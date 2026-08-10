<?php

declare(strict_types=1);

namespace Ecotech\Chat;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

final class OpenAIService
{
    private Client $http;

    private const LEAD_STATUSES = 'Booked, Rebook, Qualified, Sold, Dead Sale, Dead Lead, Cold Call, A Team, Follow Up, No Demo';
    private const OPP_STATUSES = 'Follow Up, Dead Opportunity, No Answer, Left Voicemail, lead, Supply Only, Ottawa, Called on Mobile';
    private const CLIENT_STATUSES = 'Sold, To Be Measured, Measuring, On Hold, Spring Install, Product Ready for Install, Confirmed, Paid, To Be Scheduled, Scheduled, Cancelled, Service, Measured, Ordered, To Be Ordered, Installed, To Be Assigned, Questions, Supply Only, Reviewed, Hold - For Review, Collections, Measurement Reviewed, Hold - For Finance, Tentative Schedule';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'gpt-4o-mini',
        int $timeout = 120,
    ) {
        $this->http = new Client([
            'base_uri' => 'https://api.openai.com/v1/',
            'timeout' => max(30, $timeout),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    public function chat(array $messages, array $tools, ?string $systemPrompt = null): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'tools' => $tools,
            'tool_choice' => 'auto',
            'temperature' => 0.2,
        ];

        if ($systemPrompt) {
            array_unshift($payload['messages'], [
                'role' => 'system',
                'content' => $systemPrompt,
            ]);
        }

        try {
            $response = $this->http->post('chat/completions', ['json' => $payload]);
            $decoded = json_decode((string) $response->getBody(), true);

            return is_array($decoded) ? $decoded : ['error' => 'Invalid OpenAI response'];
        } catch (GuzzleException $e) {
            return ['error' => $this->formatHttpError($e)];
        }
    }

    private function formatHttpError(GuzzleException $e): string
    {
        if ($e instanceof \GuzzleHttp\Exception\RequestException && $e->hasResponse()) {
            $body = (string) $e->getResponse()->getBody();
            $decoded = json_decode($body, true);
            $msg = $decoded['error']['message'] ?? null;
            if (is_string($msg) && $msg !== '') {
                return $msg;
            }
            $status = $e->getResponse()->getStatusCode();
            if ($status === 401) {
                return 'Invalid OpenAI API key. Check OPENAI_API_KEY in .env';
            }
        }

        return $e->getMessage();
    }

    public function generateSessionTitle(string $firstMessage): string
    {
        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Generate a short sidebar title (max 6 words) for a CRM chat that started with the user question below. Return only the title text, no quotes or punctuation at the end.',
                ],
                ['role' => 'user', 'content' => $firstMessage],
            ],
            'temperature' => 0.3,
            'max_tokens' => 24,
        ];

        try {
            $response = $this->http->post('chat/completions', ['json' => $payload]);
            $decoded = json_decode((string) $response->getBody(), true);
            $title = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));

            if ($title === '') {
                return $this->fallbackTitle($firstMessage);
            }

            $title = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $title) ?? $title;
            $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);

            return mb_strlen($title) > 60 ? mb_substr($title, 0, 57) . '…' : $title;
        } catch (GuzzleException) {
            return $this->fallbackTitle($firstMessage);
        }
    }

    private function fallbackTitle(string $firstMessage): string
    {
        $t = trim($firstMessage);

        return mb_strlen($t) > 60 ? mb_substr($t, 0, 57) . '…' : $t;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function crmToolDefinitions(): array
    {
        $dateRange = [
            'region' => ['type' => 'string', 'description' => 'Required region code, e.g. YYZ, HAM, OTT'],
            'start_date' => ['type' => 'string', 'description' => "Required, format 'YYYY-MM-DD HH:mm:ss'"],
            'end_date' => ['type' => 'string', 'description' => "Required, format 'YYYY-MM-DD HH:mm:ss'; must be >= start_date"],
            'canvasser' => ['type' => 'string', 'description' => 'Optional numeric canvasser ID'],
            'org' => ['type' => 'string', 'description' => 'Optional numeric SalesRabbit org ID'],
            'active' => ['type' => 'boolean', 'description' => 'Optional; defaults to true (active only)'],
        ];

        return [
            self::tool(
                'getOpportunity',
                'GET a single opportunity by opportunityId, phone, or email (at least one required). Region optional (defaults YYZ).',
                [
                    'opportunityId' => ['type' => 'string', 'description' => 'Opportunity ID'],
                    'phone' => ['type' => 'string', 'description' => 'xxx-xxx-xxxx'],
                    'email' => ['type' => 'string'],
                    'region' => ['type' => 'string', 'description' => 'Optional, e.g. YYZ'],
                ],
                [],
            ),
            self::tool(
                'insertLead',
                'POST new lead. Phone must be xxx-xxx-xxxx. Region optional (defaults YYZ).',
                [
                    'data' => ['type' => 'object', 'description' => 'Lead table columns + phone/email/name/address'],
                    'comment' => ['type' => 'string'],
                    'region' => ['type' => 'string'],
                ],
                ['comment'],
            ),
            self::tool(
                'addLeadComment',
                'POST comment on existing lead. Region optional.',
                [
                    'leadId' => ['type' => 'string'],
                    'comment' => ['type' => 'string'],
                    'region' => ['type' => 'string'],
                ],
                ['leadId', 'comment'],
            ),
            self::tool(
                'getSalespersonServiceArea',
                'GET all salespeople and service areas. Call WITHOUT salesperson to list everyone (use to resolve first names). Optional salesperson filters to one person.',
                [
                    'salesperson' => ['type' => 'string', 'description' => 'Optional — omit to list all'],
                ],
                [],
            ),
            self::tool(
                'getSalespersonAvailability',
                'GET appointment availability. Region optional (defaults YYZ). Omit salesperson for all reps. Use day for a specific date (e.g. tomorrow).',
                [
                    'region' => ['type' => 'string', 'description' => 'Optional, defaults YYZ'],
                    'salesperson' => ['type' => 'string', 'description' => 'Optional'],
                    'year' => ['type' => 'integer'],
                    'month' => ['type' => 'integer', 'description' => '1-12'],
                    'day' => ['type' => 'integer', 'description' => 'Day of month for specific date queries'],
                ],
                [],
            ),
            self::tool(
                'getSalespersonPerformance',
                'GET monthly performance. Region optional (defaults A for all regions). Salesperson optional.',
                [
                    'region' => ['type' => 'string', 'description' => 'Optional — A for all regions'],
                    'salesperson' => ['type' => 'string', 'description' => 'Optional first or full name'],
                    'year' => ['type' => 'integer'],
                    'month' => ['type' => 'integer'],
                ],
                [],
            ),
            self::tool(
                'getLeads',
                'GET leads by region. Pass a date range when possible (added_start_date / added_end_date). If omitted, last 30 days is used and noted in meta.note. For full history set all_time=true (no date filters). Large results return meta.status_counts + total_count for charts. Statuses: ' . self::LEAD_STATUSES . '.',
                [
                    'region' => ['type' => 'string', 'description' => 'Required region code'],
                    'added_start_date' => ['type' => 'string', 'description' => "YYYY-MM-DD HH:mm:ss; cannot be future"],
                    'added_end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:mm:ss; >= added_start_date'],
                    'booking_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD or YYYY-MM-DD HH:mm:ss'],
                    'all_time' => ['type' => 'boolean', 'description' => 'If true, do not apply date filters (full history). Prefer for all-time charts using meta.status_counts.'],
                    'status' => ['type' => 'string', 'description' => self::LEAD_STATUSES],
                    'salesperson' => ['type' => 'string', 'description' => 'Numeric salesperson ID'],
                    'city' => ['type' => 'string'],
                    'source' => ['type' => 'string'],
                    'quote_amount' => ['type' => 'number'],
                    'comments' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                ],
                ['region'],
            ),
            self::tool(
                'getAppointments',
                'GET booked appointments in a region by date range, status, or salesperson.',
                [
                    'region' => ['type' => 'string', 'description' => 'Required region code'],
                    'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:mm:ss'],
                    'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD HH:mm:ss; >= start_date'],
                    'salesperson' => ['type' => 'string', 'description' => 'Numeric salesperson ID'],
                    'status' => ['type' => 'string', 'description' => self::LEAD_STATUSES],
                ],
                ['region'],
            ),
            self::tool(
                'getFollowUpsDue',
                'GET leads with an outstanding Follow Up due in a region, optionally by date or salesperson.',
                [
                    'region' => ['type' => 'string', 'description' => 'Required region code'],
                    'date' => ['type' => 'string', 'description' => "YYYY-MM-DD — follow-ups due on or before this date"],
                    'salesperson' => ['type' => 'string', 'description' => 'Numeric salesperson ID'],
                ],
                ['region'],
            ),
            self::tool(
                'getCanvassers',
                'GET door-knocking canvassers (also called door knockers / doorknockers). Optionally filter by canvasser ID, org, or active status (defaults active only).',
                [
                    'canvasser' => ['type' => 'string', 'description' => 'Numeric canvasser ID'],
                    'org' => ['type' => 'string', 'description' => 'Numeric org ID'],
                    'active' => ['type' => 'boolean', 'description' => 'Defaults true; false includes inactive'],
                ],
                [],
            ),
            self::tool(
                'getSalesRabbitOrganizations',
                'GET SalesRabbit organizations. Optionally filter by org ID or status (active/inactive; defaults active).',
                [
                    'org' => ['type' => 'string', 'description' => 'Numeric org ID'],
                    'status' => ['type' => 'string', 'description' => "active or inactive; defaults active"],
                ],
                [],
            ),
            self::tool(
                'getCanvassingDoorKnocks',
                'GET paginated SalesRabbit door-knock records in a region/date range. Optional canvasser, org, status, deleted, active, limit, page.',
                array_merge($dateRange, [
                    'deleted' => ['type' => 'boolean'],
                    'status' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'description' => '1–2000, default 500'],
                    'page' => ['type' => 'integer', 'description' => '>= 1, default 1'],
                ]),
                ['region', 'start_date', 'end_date'],
            ),
            self::tool(
                'getCanvassingOpportunities',
                'GET CRM opportunities sourced from door knocking. Statuses: ' . self::OPP_STATUSES . '.',
                array_merge($dateRange, [
                    'status' => ['type' => 'string', 'description' => self::OPP_STATUSES],
                    'deleted' => ['type' => 'boolean'],
                ]),
                ['region', 'start_date', 'end_date'],
            ),
            self::tool(
                'getCanvassingLeads',
                'GET CRM leads created by canvassers in a region/date range. Statuses: ' . self::LEAD_STATUSES . '.',
                array_merge($dateRange, [
                    'status' => ['type' => 'string', 'description' => self::LEAD_STATUSES],
                    'deleted' => ['type' => 'boolean'],
                ]),
                ['region', 'start_date', 'end_date'],
            ),
            self::tool(
                'getCanvassingQuotedLeads',
                'GET canvasser-created leads that received a quote in a date range. Excludes Dead Sale / Dead Lead by default. Statuses: ' . self::LEAD_STATUSES . '.',
                array_merge($dateRange, [
                    'status' => ['type' => 'string', 'description' => self::LEAD_STATUSES],
                    'deleted' => ['type' => 'boolean'],
                ]),
                ['region', 'start_date', 'end_date'],
            ),
            self::tool(
                'getCanvassingClients',
                'GET sold clients attributed to canvassers. Statuses: ' . self::CLIENT_STATUSES . '. Excludes Cancelled by default.',
                array_merge($dateRange, [
                    'status' => ['type' => 'string', 'description' => self::CLIENT_STATUSES],
                ]),
                ['region', 'start_date', 'end_date'],
            ),
            self::tool(
                'getCanvassingBonus',
                'GET accrued door-knocking bonus totals per canvasser for converted clients and quoted leads in a date range.',
                $dateRange,
                ['region', 'start_date', 'end_date'],
            ),
            self::tool(
                'getCanvassingPerformance',
                'GET aggregate + per-canvasser door-knocking metrics (doors knocked, CRM conversion, sales) for a region/date range.',
                $dateRange,
                ['region', 'start_date', 'end_date'],
            ),
            self::tool(
                'getEntityUrl',
                'GET a CRM deep-link URL for one opportunity, lead, client, or service so the user can open it in the CRM.',
                [
                    'entity' => ['type' => 'string', 'description' => "opportunity | lead | client | service"],
                    'entity_id' => ['type' => 'string', 'description' => 'Numeric entity ID'],
                    'region' => ['type' => 'string', 'description' => 'Optional, defaults YYZ'],
                ],
                ['entity', 'entity_id'],
            ),
            self::tool(
                'resolveEntityLinks',
                'Batch-resolve CRM deep-link URLs for many entities. Prefer this when building tables so each row can link into the CRM.',
                [
                    'items' => [
                        'type' => 'array',
                        'description' => 'Up to ~25 items',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'entity' => ['type' => 'string'],
                                'entity_id' => ['type' => 'string'],
                                'region' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
                ['items'],
            ),
            self::tool(
                'getRoles',
                'GET CRM roles. Optional role id filters to one role.',
                [
                    'role' => ['type' => 'string', 'description' => 'Optional numeric role ID'],
                ],
                [],
            ),
            self::tool(
                'getUsers',
                'GET CRM users (active by default). Optional user id or role id filters.',
                [
                    'user' => ['type' => 'string', 'description' => 'Optional numeric user ID'],
                    'role' => ['type' => 'string', 'description' => 'Optional numeric role ID'],
                    'active' => ['type' => 'boolean', 'description' => 'Defaults true'],
                ],
                [],
            ),
            self::tool(
                'createChart',
                'Create a pie, bar, or line chart from CRM numbers you already fetched. Use after aggregating tool results. The UI renders the chart in chat.',
                [
                    'type' => ['type' => 'string', 'description' => 'bar | pie | line'],
                    'title' => ['type' => 'string'],
                    'labels' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'datasets' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'label' => ['type' => 'string'],
                                'data' => ['type' => 'array', 'items' => ['type' => 'number']],
                            ],
                        ],
                    ],
                ],
                ['type', 'labels', 'datasets'],
            ),
            self::tool(
                'createReport',
                'Create a printable PDF-style report. Ask for sections/fields and time range if missing, fetch CRM data, then call this. For salesperson performance include EVERY salesperson from the tool data in table rows (not a sample of 5). The UI opens the report in a new tab.',
                [
                    'title' => ['type' => 'string'],
                    'subtitle' => ['type' => 'string'],
                    'sections' => [
                        'type' => 'array',
                        'description' => 'Sections: text|table|metrics|list. Tables must include the full row set from source data.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'heading' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'content' => ['type' => 'string', 'description' => 'For text sections'],
                                'columns' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'rows' => ['type' => 'array', 'items' => ['type' => 'array'], 'description' => 'Full rows — do not truncate salesperson lists'],
                                'items' => ['type' => 'array', 'description' => 'For list or metrics [{label, value}]'],
                            ],
                        ],
                    ],
                ],
                ['title', 'sections'],
            ),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     */
    private static function tool(string $name, string $description, array $properties, array $required): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $schema,
            ],
        ];
    }
}
