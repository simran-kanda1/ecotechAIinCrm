<?php

declare(strict_types=1);

namespace Ecotech\Chat;

use DateTimeImmutable;
use DateTimeZone;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Ecotech CRM API — https://api.ecotechcrm.ca/Api
 * @see API docs: GET for reads, POST JSON for writes, snake_case response keys.
 *
 */
final class CrmClient
{
    private Client $http;
    private const TZ = 'America/Toronto';

    public function __construct(
        private readonly string $baseUrl,
        private readonly CrmAuth $auth,
        private readonly string $clientId,
        private readonly int $timeout = 30,
    ) {
        $this->http = new Client([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'timeout' => $this->timeout,
            'headers' => [
                'Accept' => 'application/json',
                'X-Client-Id' => $this->clientId,
            ],
        ]);
    }

    public function authenticate(): array
    {
        $token = $this->auth->getToken();
        if (!$token) {
            return ['error' => true, 'message' => 'CRM authentication failed. Check CRM credentials in .env'];
        }

        return ['success' => true, 'authenticated' => true];
    }

    public function getOpportunity(
        ?string $opportunityId = null,
        ?string $phone = null,
        ?string $email = null,
        ?string $region = null,
    ): array {
        $query = $this->query([
            'opportunityId' => $opportunityId,
            'phone' => $phone ? self::formatPhone($phone) : null,
            'email' => $email,
            'region' => $region ?: 'YYZ',
        ]);

        return $this->get('GetOpportunity', $query);
    }

    public function insertLead(array $data, string $comment, ?string $region = null): array
    {
        $phone = $data['phone'] ?? $data['Phone_1'] ?? $data['phone1'] ?? '';
        $body = array_merge($data, array_filter([
            'region' => $region ?: 'YYZ',
            'Comment' => $comment,
            'Phone_1' => $phone ? self::formatPhone((string) $phone) : null,
        ]));

        return $this->postJson('InsertLead', $body);
    }

    public function addLeadComment(int|string $leadId, string $comment, ?string $region = null): array
    {
        return $this->postJson('AddLeadComment', array_filter([
            'region' => $region ?: 'YYZ',
            'LeadId' => $leadId,
            'Comment' => $comment,
        ]));
    }

    /** @return array<string, mixed> */
    public function getSalespersonServiceArea(?string $salesperson = null): array
    {
        return $this->get('GetSalespersonServiceArea', $this->query(['salesperson' => $salesperson]));
    }

    public function getSalespersonAvailability(
        string $region,
        ?string $salesperson = null,
        ?int $month = null,
        ?int $year = null,
        ?int $day = null,
    ): array {
        $now = $this->nowToronto();

        return $this->get('GetSalespersonAvailability', $this->query([
            'region' => $region,
            'salesperson' => $salesperson,
            'month' => $month ?? (int) $now->format('n'),
            'year' => $year ?? (int) $now->format('Y'),
            'day' => $day,
        ]));
    }

    public function getSalespersonPerformance(
        string $region,
        ?string $salesperson = null,
        ?int $month = null,
        ?int $year = null,
    ): array {
        $now = $this->nowToronto();
        $result = $this->get('GetSalespersonPerformance', $this->query([
            'region' => $region,
            'salesperson' => $salesperson,
            'month' => $month ?? (int) $now->format('n'),
            'year' => $year ?? (int) $now->format('Y'),
        ]));

        return $this->normalizePerformanceResult($result);
    }

    /**
     * Performance API often returns { "YYZ": [ ...reps ] }. Flatten for the model
     * and annotate how many salespeople must appear in full reports.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function normalizePerformanceResult(array $result): array
    {
        if (!empty($result['error'])) {
            return $result;
        }

        $data = $result['data'] ?? null;
        if (!is_array($data)) {
            return $result;
        }

        if (array_is_list($data)) {
            $result['meta'] = array_merge(
                is_array($result['meta'] ?? null) ? $result['meta'] : [],
                [
                    'salesperson_count' => count($data),
                    'note' => 'Include ALL ' . count($data) . ' salespeople in any report/table unless the user asked for a subset (e.g. top 5).',
                ],
            );

            return $result;
        }

        $flat = [];
        foreach ($data as $reg => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (!isset($row['region'])) {
                    $row['region'] = (string) $reg;
                }
                $flat[] = $row;
            }
        }

        $result['data'] = $flat;
        $result['meta'] = array_merge(
            is_array($result['meta'] ?? null) ? $result['meta'] : [],
            [
                'salesperson_count' => count($flat),
                'regions_in_response' => array_keys($data),
                'note' => 'Flattened performance by region. Include ALL ' . count($flat) . ' salespeople in reports/tables unless the user asked for a subset.',
            ],
        );

        return $result;
    }

    /** @param array<string, mixed> $filters */
    public function getLeads(array $filters): array
    {
        $filters = $this->ensureLeadDateRange($filters);
        $result = $this->get('GetLeads', $this->query($filters));
        if (!empty($filters['_note']) && is_array($result) && empty($result['error'])) {
            $result['meta'] = array_merge(
                is_array($result['meta'] ?? null) ? $result['meta'] : [],
                ['note' => (string) $filters['_note']],
            );
        }

        return $result;
    }

    /**
     * Default to last 30 days unless the user asked for all-time / supplied dates.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function ensureLeadDateRange(array $filters): array
    {
        $allTime = filter_var($filters['all_time'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($allTime) {
            unset($filters['added_start_date'], $filters['added_end_date'], $filters['booking_date']);
            $filters['_note'] = 'All-time request: no date filter applied. Large result sets are aggregated for the assistant.';

            return $filters;
        }

        $hasRange = !empty($filters['added_start_date'])
            || !empty($filters['added_end_date'])
            || !empty($filters['booking_date']);

        if ($hasRange) {
            return $filters;
        }

        $now = $this->nowToronto();
        $filters['added_end_date'] = $now->format('Y-m-d 23:59:59');
        $filters['added_start_date'] = $now->modify('-30 days')->format('Y-m-d 00:00:00');
        $filters['_note'] = 'No time range was provided, so this used the last 30 days. Ask the user for a specific range, or all-time if they need the full history.';

        return $filters;
    }

    /** @param array<string, mixed> $filters */
    public function getAppointments(array $filters): array
    {
        return $this->get('GetAppointments', $this->query($filters));
    }

    /** @param array<string, mixed> $filters */
    public function getFollowUpsDue(array $filters): array
    {
        return $this->get('GetFollowUpsDue', $this->query($filters));
    }

    /** @param array<string, mixed> $filters */
    public function getCanvassers(array $filters = []): array
    {
        return $this->get('GetCanvassers', $this->query($filters));
    }

    /** @param array<string, mixed> $filters */
    public function getSalesRabbitOrganizations(array $filters = []): array
    {
        return $this->get('GetSalesRabbitOrganizations', $this->query($filters));
    }

    /** @param array<string, mixed> $filters */
    public function getCanvassingDoorKnocks(array $filters): array
    {
        return $this->get('GetCanvassingDoorKnocks', $this->query($filters));
    }

    /** @param array<string, mixed> $filters */
    public function getCanvassingOpportunities(array $filters): array
    {
        return $this->get('GetCanvassingOpportunities', $this->query($filters));
    }

    /** @param array<string, mixed> $filters */
    public function getCanvassingLeads(array $filters): array
    {
        return $this->get('GetCanvassingLeads', $this->query($filters));
    }

    /** @param array<string, mixed> $filters */
    public function getCanvassingQuotedLeads(array $filters): array
    {
        return $this->get('GetCanvassingQuotedLeads', $this->query($filters));
    }

    /** @param array<string, mixed> $filters */
    public function getCanvassingClients(array $filters): array
    {
        return $this->get('GetCanvassingClients', $this->query($filters));
    }

    /** @param array<string, mixed> $filters */
    public function getCanvassingBonus(array $filters): array
    {
        return $this->get('GetCanvassingBonus', $this->query($filters));
    }

    /** @param array<string, mixed> $filters */
    public function getCanvassingPerformance(array $filters): array
    {
        return $this->get('GetCanvassingPerformance', $this->query($filters));
    }

    public function getEntityUrl(string $entity, string $entityId, ?string $region = null): array
    {
        return $this->get('GetEntityUrl', $this->query([
            'entity' => strtolower($entity),
            'entity_id' => $entityId,
            'region' => $region ?: 'YYZ',
        ]));
    }

    /**
     * Resolve several CRM deep-links in one tool call.
     *
     * @param list<array{entity?: string, entity_id?: string, region?: string}> $items
     * @return array<string, mixed>
     */
    public function resolveEntityLinks(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $entity = (string) ($item['entity'] ?? '');
            $id = (string) ($item['entity_id'] ?? '');
            if ($entity === '' || $id === '') {
                continue;
            }
            $region = isset($item['region']) && $item['region'] !== '' ? (string) $item['region'] : 'YYZ';
            $result = $this->getEntityUrl($entity, $id, $region);
            $url = $result['data']['url'] ?? $result['url'] ?? null;
            $out[] = [
                'entity' => strtolower($entity),
                'entity_id' => $id,
                'region' => $region,
                'url' => is_string($url) ? $url : null,
                'error' => ($result['error'] ?? false) ? ($result['message'] ?? 'failed') : null,
            ];
        }

        return ['data' => $out];
    }

    /** @param array<string, mixed> $filters */
    public function getRoles(array $filters = []): array
    {
        return $this->get('GetRoles', $this->query($filters));
    }

    /** @param array<string, mixed> $filters */
    public function getUsers(array $filters = []): array
    {
        return $this->get('GetUsers', $this->query($filters));
    }

    public function callEndpoint(string $endpoint, array $params): array
    {
        return match ($endpoint) {
            'authenticate' => $this->authenticate(),
            'getOpportunity' => $this->getOpportunity(
                isset($params['opportunityId']) && $params['opportunityId'] !== '' ? (string) $params['opportunityId'] : null,
                $params['phone'] ?? null,
                $params['email'] ?? null,
                $params['region'] ?? null,
            ),
            'insertLead' => $this->insertLead(
                is_array($params['data'] ?? null) ? $params['data'] : $params,
                (string) ($params['comment'] ?? ''),
                $params['region'] ?? null,
            ),
            'addLeadComment' => $this->addLeadComment(
                $params['leadId'] ?? '',
                (string) ($params['comment'] ?? ''),
                $params['region'] ?? null,
            ),
            'getSalespersonServiceArea' => $this->getSalespersonServiceArea(
                isset($params['salesperson']) && $params['salesperson'] !== '' ? (string) $params['salesperson'] : null,
            ),
            'getSalespersonAvailability' => $this->getSalespersonAvailability(
                (string) ($params['region'] ?? 'YYZ'),
                isset($params['salesperson']) && $params['salesperson'] !== '' ? (string) $params['salesperson'] : null,
                isset($params['month']) ? (int) $params['month'] : null,
                isset($params['year']) ? (int) $params['year'] : null,
                isset($params['day']) ? (int) $params['day'] : null,
            ),
            'getSalespersonPerformance' => $this->getSalespersonPerformance(
                (string) ($params['region'] ?? 'A'),
                isset($params['salesperson']) && $params['salesperson'] !== '' ? (string) $params['salesperson'] : null,
                isset($params['month']) ? (int) $params['month'] : null,
                isset($params['year']) ? (int) $params['year'] : null,
            ),
            'getLeads' => $this->getLeads($this->flattenFilters($params)),
            'getAppointments' => $this->getAppointments($this->flattenFilters($params)),
            'getFollowUpsDue' => $this->getFollowUpsDue($this->flattenFilters($params)),
            'getCanvassers' => $this->getCanvassers($this->flattenFilters($params)),
            'getSalesRabbitOrganizations' => $this->getSalesRabbitOrganizations($this->flattenFilters($params)),
            'getCanvassingDoorKnocks' => $this->getCanvassingDoorKnocks($this->flattenFilters($params)),
            'getCanvassingOpportunities' => $this->getCanvassingOpportunities($this->flattenFilters($params)),
            'getCanvassingLeads' => $this->getCanvassingLeads($this->flattenFilters($params)),
            'getCanvassingQuotedLeads' => $this->getCanvassingQuotedLeads($this->flattenFilters($params)),
            'getCanvassingClients' => $this->getCanvassingClients($this->flattenFilters($params)),
            'getCanvassingBonus' => $this->getCanvassingBonus($this->flattenFilters($params)),
            'getCanvassingPerformance' => $this->getCanvassingPerformance($this->flattenFilters($params)),
            'getEntityUrl' => $this->getEntityUrl(
                (string) ($params['entity'] ?? ''),
                (string) ($params['entity_id'] ?? ''),
                $params['region'] ?? null,
            ),
            'resolveEntityLinks' => $this->resolveEntityLinks(
                is_array($params['items'] ?? null) ? $params['items'] : [],
            ),
            'getRoles' => $this->getRoles($this->flattenFilters($params)),
            'getUsers' => $this->getUsers($this->flattenFilters($params)),
            default => ['error' => true, 'message' => "Unknown endpoint: {$endpoint}"],
        };
    }

    public static function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            return substr($digits, 0, 3) . '-' . substr($digits, 3, 3) . '-' . substr($digits, 6);
        }

        return $phone;
    }

    private function nowToronto(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::TZ));
    }

    /**
     * Accept either top-level params or a nested `filters` object from the model.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function flattenFilters(array $params): array
    {
        if (isset($params['filters']) && is_array($params['filters'])) {
            return array_merge($params['filters'], array_diff_key($params, ['filters' => true]));
        }

        return $params;
    }

    /**
     * Drop null/empty values; coerce booleans to API-friendly strings.
     * Internal keys starting with _ are not sent to the CRM API.
     *
     * @param array<string, mixed> $params
     * @return array<string, scalar>
     */
    private function query(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }
            // Client-only flags — never send to CRM.
            if (in_array((string) $key, ['all_time', 'filters'], true)) {
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
                continue;
            }
            if (is_scalar($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @param array<string, scalar|null> $query */
    private function get(string $action, array $query = []): array
    {
        return $this->send('GET', $action, ['query' => $query]);
    }

    /** @param array<string, mixed> $body */
    private function postJson(string $action, array $body): array
    {
        return $this->send('POST', $action, [
            'json' => $body,
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    /** @param array<string, mixed> $options */
    private function send(string $method, string $action, array $options = []): array
    {
        $token = $this->auth->getToken();
        if (!$token) {
            return ['error' => true, 'message' => 'CRM authentication failed'];
        }

        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $token,
        ]);

        try {
            $response = $this->http->request($method, $action, $options);

            return $this->decodeBody((string) $response->getBody());
        } catch (GuzzleException $e) {
            if ($e->getCode() === 401) {
                $this->auth->clearToken();
                $token = $this->auth->getToken();
                if ($token) {
                    $options['headers']['Authorization'] = 'Bearer ' . $token;
                    try {
                        $response = $this->http->request($method, $action, $options);

                        return $this->decodeBody((string) $response->getBody());
                    } catch (\GuzzleHttp\Exception\GuzzleException $retry) {
                        return $this->errorResponse($retry, $action);
                    }
                }
            }

            return $this->errorResponse($e, $action);
        }
    }

    /** @return array<string, mixed> */
    private function decodeBody(string $raw): array
    {
        $bytes = strlen($raw);
        // Large CRM dumps (all-time leads, etc.) need more headroom, then we aggregate.
        if ($bytes > 1_500_000) {
            $previous = ini_get('memory_limit');
            @ini_set('memory_limit', '512M');
        }

        $decoded = json_decode($raw, true);
        if (isset($previous)) {
            @ini_set('memory_limit', (string) $previous);
        }

        if (!is_array($decoded)) {
            if ($bytes > 2_000_000) {
                return [
                    'error' => true,
                    'message' => 'CRM returned a very large payload that could not be parsed. Try a shorter time range, or ask again for all-time charts (aggregates only).',
                    'bytes' => $bytes,
                ];
            }

            return ['raw' => $raw];
        }

        if (!empty($decoded['error'])) {
            return [
                'error' => true,
                'message' => is_string($decoded['error']) ? $decoded['error'] : 'CRM error',
                'data' => $decoded,
            ];
        }

        return $this->shrinkForAi($decoded, $bytes);
    }

    /**
     * Keep tool payloads workable for the model. Full row lists are sampled;
     * status_counts (and similar) cover the entire result for charts / all-time.
     *
     * @param array<string, mixed> $decoded
     * @return array<string, mixed>
     */
    private function shrinkForAi(array $decoded, int $bytes = 0): array
    {
        if (!isset($decoded['data']) || !is_array($decoded['data']) || !array_is_list($decoded['data'])) {
            return $decoded;
        }

        $rows = $decoded['data'];
        $total = count($rows);
        $statusCounts = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = $row['status'] ?? null;
            if ($status === null || $status === '') {
                continue;
            }
            $key = (string) $status;
            $statusCounts[$key] = ($statusCounts[$key] ?? 0) + 1;
        }

        // For huge all-time pulls, only return aggregates + a tiny sample.
        $aggregateOnly = $total > 250 || $bytes > 1_500_000;
        $max = $aggregateOnly ? 15 : 80;

        $meta = is_array($decoded['meta'] ?? null) ? $decoded['meta'] : [];
        $meta['total_count'] = $total;
        if ($statusCounts !== []) {
            $meta['status_counts'] = $statusCounts;
        }

        if ($total > $max) {
            $decoded['data'] = array_slice($rows, 0, $max);
            $meta['truncated'] = true;
            $meta['returned'] = $max;
            $meta['aggregate_only'] = $aggregateOnly;
            $meta['note'] = ($meta['note'] ?? '')
                . ($aggregateOnly
                    ? ' Large result: showing a small sample of rows. Use meta.status_counts / total_count for charts and totals (covers the full set).'
                    : ' Rows truncated for the assistant. Use meta.status_counts for charts/summaries when present.');
            $meta['note'] = trim((string) $meta['note']);
        }

        $decoded['meta'] = $meta;
        // Free the full list ASAP when we sliced.
        unset($rows);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function errorResponse(GuzzleException $e, string $action): array
    {
        return [
            'error' => true,
            'message' => 'CRM API request failed: ' . $e->getMessage(),
            'endpoint' => $action,
        ];
    }
}
