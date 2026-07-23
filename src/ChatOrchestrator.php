<?php

declare(strict_types=1);

namespace Ecotech\Chat;

final class ChatOrchestrator
{
    private const SYSTEM_PROMPT_TEMPLATE = <<<'PROMPT'
You are the Ecotech Windows & Doors CRM assistant.

Today is %s (America/Toronto timezone).

Rules:
- ONLY use the provided CRM tools. Never invent leads, appointments, counts, or metrics.
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

Tool routing examples:
- "How did John perform this month?" → getSalespersonPerformance (region A unless specified, current month/year, match John).
- "Who has appointments tomorrow?" → getSalespersonAvailability with tomorrow's year/month/day, no salesperson, region YYZ unless user specifies another.
- "Follow-ups due" → getFollowUpsDue with region (+ optional date/salesperson).
- "Leads booked last week in YYZ" → getLeads with region + added_start_date / added_end_date or status Booked.
- "Door knocks this month" / "canvassing performance" / "door knocker bonus" → getCanvassingDoorKnocks / getCanvassingPerformance / getCanvassingBonus with region + date range.
- "Find opportunity for phone/email/id" → getOpportunity.

Present clear summaries, counts, and markdown tables. If data is empty or endpoint errors, say so honestly.
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
    ];

    public function __construct(
        private readonly OpenAIService $openai,
        private readonly CrmClient $crm,
    ) {
    }

    /**
     * @param array<int, array{role: string, content: string}> $history
     * @return array{reply: string, tool_calls: list<array<string, mixed>>}
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
        $maxRounds = 8;

        for ($round = 0; $round < $maxRounds; $round++) {
            $response = $this->openai->chat($messages, $tools, $systemPrompt);

            if (isset($response['error'])) {
                return [
                    'reply' => 'Sorry, I could not reach the AI service. Please try again.',
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
                return [
                    'reply' => (string) ($choice['content'] ?? 'No response generated.'),
                    'tool_calls' => $toolLog,
                ];
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

        return [
            'reply' => 'I need a bit more information to complete that request. Can you provide the missing details?',
            'tool_calls' => $toolLog,
        ];
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
