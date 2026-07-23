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
 * Each endpoint is a separate method so access can later be gated per user
 * (super-admin ACL). Today every endpoint is exposed to the chat tools.
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

        return $this->get('GetSalespersonPerformance', $this->query([
            'region' => $region,
            'salesperson' => $salesperson,
            'month' => $month ?? (int) $now->format('n'),
            'year' => $year ?? (int) $now->format('Y'),
        ]));
    }

    /** @param array<string, mixed> $filters */
    public function getLeads(array $filters): array
    {
        return $this->get('GetLeads', $this->query($filters));
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
     *
     * @param array<string, mixed> $params
     * @return array<string, scalar>
     */
    private function query(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
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
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            if (!empty($decoded['error'])) {
                return [
                    'error' => true,
                    'message' => is_string($decoded['error']) ? $decoded['error'] : 'CRM error',
                    'data' => $decoded,
                ];
            }

            return $decoded;
        }

        return ['raw' => $raw];
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
