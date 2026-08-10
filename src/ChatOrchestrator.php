<?php

declare(strict_types=1);

namespace Ecotech\Chat;

final class ChatOrchestrator
{
    private const SYSTEM_PROMPT_TEMPLATE = <<<'PROMPT'
You are the Ecotech Windows & Doors CRM assistant.

Today is %s (America/Toronto timezone).

Rules:
- ONLY use the provided CRM tools (plus createChart / createReport). Never invent leads, appointments, counts, or metrics.
- Stay strictly inside Ecotech CRM scope. Do NOT browse the web, look up general knowledge, weather, news, or anything outside CRM tools. If asked something outside CRM, politely say you can only help with Ecotech CRM data.
- Region is REQUIRED for most list/search endpoints (getLeads, getAppointments, getFollowUpsDue, canvassing tools). For single lookups, region is optional and defaults to YYZ.
- If region is required but not given, ask the user — or use YYZ for single-region lookups / A for company-wide salesperson performance when that fits.
- Date ranges for canvassing tools must be 'YYYY-MM-DD HH:mm:ss'. Prefer full-day ranges (00:00:00–23:59:59) when the user gives a day or month.
- Phone numbers must be xxx-xxx-xxxx when inserting leads.
- API response keys are snake_case (e.g. salesperson_name, windows_count, doors_count).
- Lead statuses: Booked, Rebook, Qualified, Sold, Dead Sale, Dead Lead, Cold Call, A Team, Follow Up, No Demo.

Resolving salesperson / canvasser names:
- Users may say first name only (e.g. "John"). Call getSalespersonServiceArea without a salesperson to list everyone, then match by first or full name before calling availability/performance.
- "Canvasser", "door knocker", and "doorknocker" mean the same thing — use the canvassing tools (getCanvassers, getCanvassing*, etc.).
- For canvassers / door knockers, call getCanvassers (optionally by org) to resolve names to IDs before filtering other canvassing endpoints.
- When a salesperson param is omitted, availability/performance endpoints return arrays for all salespeople.

CRM deep links (required for list results):
- When showing leads, opportunities, clients, or services in a table, call resolveEntityLinks (or getEntityUrl) with the correct entity type and numeric id.
- Put an "Open in CRM" markdown link column using the returned url, e.g. [Open](https://...). Names can also be linked.
- entity must be one of: opportunity, lead, client, service.
- Cap deep-links at about 25 rows per reply (summarize the rest).

Charts:
- Prefer a time range. If the user did not specify one, either ask OR fetch with the default last-30-days window and clearly tell them in your reply that results are for the last 30 days (they can ask for a different range or all-time).
- If they say "all time", "all-time", "entire history", or "no date limit", call getLeads with all_time=true (no date filters). Large sets still work via meta.status_counts / total_count for charts.
- After fetching, aggregate (prefer meta.status_counts when present), then call createChart.
- Do NOT output markdown images (![]()), HTML <img>, or fake chart URLs. The UI renders createChart automatically — only describe the chart in text.
- Prefer charts for comparisons and breakdowns; still include a short summary and the time range used.

PDF reports:
- When the user asks for a PDF/report, FIRST ask which sections and fields they want if they have not specified. Also confirm the time range (or all-time).
- After they confirm, fetch the needed CRM data, then call createReport with title + sections (text, table, metrics, list).
- CRITICAL: For salesperson / performance reports, put EVERY salesperson from the tool result into the report table (use meta.salesperson_count as a checklist). Never show only a sample like top 5 unless the user asked for that.
- Table rows in createReport must include the full set. If the list is long, still include all rows — do not summarize down to a handful.
- After createReport, do NOT add markdown links like "Open the report" — the UI already shows an orange Open report button. Just say the report is ready and they can use that button.
- Tell them the report opens from the orange button for print/download as PDF.

Time ranges (important — this CRM has a lot of data):
- Always state the time range used in your answer.
- Default when omitted: last 30 days (and say so).
- Support explicit ranges and all-time. All-time is allowed and should work for charts/summaries using aggregates.

Tool routing examples:
- "How did John perform this month?" → getSalespersonPerformance (region A unless specified, current month/year, match John).
- "Who has appointments tomorrow?" → getSalespersonAvailability with tomorrow's year/month/day, no salesperson, region YYZ unless user specifies another.
- "Follow-ups due" → getFollowUpsDue with region (+ optional date/salesperson), then resolveEntityLinks for lead ids.
- "Pie chart of lead statuses in YYZ" → getLeads, aggregate by status, createChart type pie.
- "PDF of door knocker performance" → ask what to include, then fetch + createReport.
- Multi-region salesperson / deals reports → prefer getSalespersonPerformance with region A (all regions) for the month first. Only pull getLeads per region when you need lead-level stage detail, and keep date ranges tight. Avoid dozens of sequential unbounded calls.

Present clear summaries, counts, markdown tables (with Open in CRM links), and charts/reports via tools. If data is empty or endpoint errors, say so honestly.
PROMPT;

    /**
     * All CRM tools are auto-enabled for every user today.
     * Later: replace with per-user ACL from super-admin config.
     *
     * @var list<string>
     */
    public const APPROVED_ENDPOINTS = [
        'getOpportunity',
        'insertLead',
        'addLeadComment',
        'getSalespersonServiceArea',
        'getSalespersonAvailability',
        'getSalespersonPerformance',
        'getLeads',
        'getAppointments',
        'getFollowUpsDue',
        'getCanvassers',
        'getSalesRabbitOrganizations',
        'getCanvassingDoorKnocks',
        'getCanvassingOpportunities',
        'getCanvassingLeads',
        'getCanvassingQuotedLeads',
        'getCanvassingClients',
        'getCanvassingBonus',
        'getCanvassingPerformance',
        'getEntityUrl',
        'resolveEntityLinks',
        'getRoles',
        'getUsers',
        'createChart',
        'createReport',
    ];

    public function __construct(
        private readonly OpenAIService $openai,
        private readonly CrmClient $crm,
    ) {
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @return array{reply: string, tool_calls: list<array<string, mixed>>, artifacts?: array{charts: list<array<string, mixed>>, reports: list<array<string, mixed>>}}
     */
    public function handle(string $userMessage, array $history = []): array
    {
        $messages = [];
        foreach ($history as $turn) {
            if (!empty($turn['role']) && isset($turn['content'])) {
                $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $systemPrompt = sprintf(
            self::SYSTEM_PROMPT_TEMPLATE,
            (new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto')))->format('l, F j, Y'),
        );

        $tools = OpenAIService::crmToolDefinitions();
        $toolLog = [];
        $charts = [];
        $reports = [];
        $maxRounds = 12;

        for ($round = 0; $round < $maxRounds; $round++) {
            $response = $this->openai->chat($messages, $tools, $systemPrompt);

            if (isset($response['error'])) {
                $detail = is_string($response['error']) ? $response['error'] : 'Unknown error';
                return [
                    'reply' => 'Sorry, I could not reach the AI service. ' . $detail,
                    'tool_calls' => $toolLog,
                    'error' => $response['error'],
                ];
            }

            $choice = $response['choices'][0]['message'] ?? null;
            if (!$choice) {
                return ['reply' => 'Unexpected response from AI.', 'tool_calls' => $toolLog];
            }

            $messages[] = $choice;

            $toolCalls = $choice['tool_calls'] ?? [];
            if (empty($toolCalls)) {
                $out = [
                    'reply' => (string) ($choice['content'] ?? 'No response generated.'),
                    'tool_calls' => $toolLog,
                ];
                if ($charts !== [] || $reports !== []) {
                    $out['artifacts'] = ['charts' => $charts, 'reports' => $reports];
                }

                return $out;
            }

            foreach ($toolCalls as $toolCall) {
                $fn = $toolCall['function']['name'] ?? '';
                $argsJson = $toolCall['function']['arguments'] ?? '{}';
                $args = json_decode($argsJson, true) ?? [];

                if (!in_array($fn, self::APPROVED_ENDPOINTS, true)) {
                    $result = ['error' => true, 'message' => 'Endpoint not approved'];
                } else {
                    $missing = $this->missingRequired($fn, $args);
                    if ($missing) {
                        $result = [
                            'error' => true,
                            'message' => 'Missing required fields: ' . implode(', ', $missing),
                            'ask_user' => true,
                        ];
                    } elseif ($fn === 'createChart') {
                        $result = ArtifactTools::createChart($args);
                        if (!empty($result['ok']) && isset($result['chart'])) {
                            $charts[] = $result['chart'];
                        }
                    } elseif ($fn === 'createReport') {
                        $result = ArtifactTools::createReport($args);
                        if (!empty($result['ok']) && isset($result['report'])) {
                            $reports[] = $result['report'];
                        }
                    } else {
                        $result = $this->crm->callEndpoint($fn, $args);
                    }
                }

                $toolLog[] = ['endpoint' => $fn, 'args' => $args, 'result' => $result];

                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'] ?? uniqid('call_'),
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        $out = [
            'reply' => 'I need a bit more information to complete that request. Can you provide the missing details?',
            'tool_calls' => $toolLog,
        ];
        if ($charts !== [] || $reports !== []) {
            $out['artifacts'] = ['charts' => $charts, 'reports' => $reports];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $args
     * @return list<string>
     */
    private function missingRequired(string $endpoint, array $args): array
    {
        $filters = isset($args['filters']) && is_array($args['filters'])
            ? array_merge($args['filters'], $args)
            : $args;

        return match ($endpoint) {
            'getOpportunity' => empty($filters['opportunityId']) && empty($filters['phone']) && empty($filters['email'])
                ? ['opportunityId, phone, or email']
                : [],
            'insertLead' => $this->missingFields($args, ['comment']),
            'addLeadComment' => $this->missingFields($args, ['leadId', 'comment']),
            'getLeads', 'getAppointments', 'getFollowUpsDue' => $this->missingFields($filters, ['region']),
            'getCanvassingDoorKnocks',
            'getCanvassingOpportunities',
            'getCanvassingLeads',
            'getCanvassingQuotedLeads',
            'getCanvassingClients',
            'getCanvassingBonus',
            'getCanvassingPerformance' => $this->missingFields($filters, ['region', 'start_date', 'end_date']),
            'getEntityUrl' => $this->missingFields($filters, ['entity', 'entity_id']),
            'resolveEntityLinks' => empty($args['items']) || !is_array($args['items']) ? ['items'] : [],
            'createChart' => $this->missingFields($args, ['type', 'labels', 'datasets']),
            'createReport' => $this->missingFields($args, ['title', 'sections']),
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $args
     * @param list<string> $fields
     * @return list<string>
     */
    private function missingFields(array $args, array $fields): array
    {
        $missing = [];
        foreach ($fields as $field) {
            if (!isset($args[$field]) || $args[$field] === '' || $args[$field] === []) {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}
