<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Ecotech\Chat\ChatOrchestrator;
use Ecotech\Chat\Config;
use Ecotech\Chat\CrmAuth;
use Ecotech\Chat\CrmClient;
use Ecotech\Chat\OpenAIService;

Config::load();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);

if (!is_array($body) || empty($body['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing message']);
    exit;
}

$message = trim((string) $body['message']);
$history = is_array($body['history'] ?? null) ? $body['history'] : [];
$sessionId = isset($body['sessionId']) ? (string) $body['sessionId'] : null;
$isFirstMessage = !empty($body['isFirstMessage']);

$openaiKey = Config::get('OPENAI_API_KEY');
$crmBase = Config::get('CRM_API_BASE_URL');

if (!$openaiKey || preg_match('/^your[-_]/i', $openaiKey)) {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI API key not configured. Set OPENAI_API_KEY in .env']);
    exit;
}

if (!$crmBase) {
    http_response_code(500);
    echo json_encode(['error' => 'CRM API base URL not configured']);
    exit;
}

$clientId = Config::get('CRM_CLIENT_ID');
$crmUser = Config::get('CRM_USERNAME');
$crmPass = Config::get('CRM_PASSWORD');

if (
    !$clientId || !$crmUser || !$crmPass
    || preg_match('/^your[-_]/i', $clientId)
    || preg_match('/^your[-_]/i', $crmUser)
    || preg_match('/^your[-_]/i', $crmPass)
) {
    http_response_code(500);
    echo json_encode(['error' => 'CRM credentials not configured. Set CRM_CLIENT_ID, CRM_USERNAME, and CRM_PASSWORD in .env']);
    exit;
}

$timeout = (int) (Config::get('CRM_API_TIMEOUT', '30') ?? 30);
$auth = new CrmAuth($crmBase, $clientId, $crmUser, $crmPass, $timeout);
$openai = new OpenAIService($openaiKey, Config::get('OPENAI_MODEL', 'gpt-4o-mini') ?? 'gpt-4o-mini');
$crm = new CrmClient($crmBase, $auth, $clientId, $timeout);

$sessionTitle = null;
if ($isFirstMessage) {
    $sessionTitle = $openai->generateSessionTitle($message);
}

$orchestrator = new ChatOrchestrator($openai, $crm);
$result = $orchestrator->handle($message, $history);

$response = [
    'reply' => $result['reply'],
    'sessionId' => $sessionId,
    'timestamp' => gmdate('c'),
];

if ($sessionTitle !== null && $sessionTitle !== '') {
    $response['sessionTitle'] = $sessionTitle;
}

if (Config::bool('APP_DEBUG') && !empty($result['tool_calls'])) {
    $response['debug'] = ['tool_calls' => $result['tool_calls']];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
