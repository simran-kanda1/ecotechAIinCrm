<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Ecotech\Chat\Config;
use Ecotech\Chat\CrmAuth;
use Ecotech\Chat\CrmClient;

Config::load();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$crmBase = Config::get('CRM_API_BASE_URL');
$clientId = Config::get('CRM_CLIENT_ID');
$crmUser = Config::get('CRM_USERNAME');
$crmPass = Config::get('CRM_PASSWORD');

if (!$crmBase || !$clientId || !$crmUser || !$crmPass) {
    http_response_code(500);
    echo json_encode(['error' => 'CRM credentials not configured']);
    exit;
}

$timeout = (int) (Config::get('CRM_API_TIMEOUT', '30') ?? 30);
$auth = new CrmAuth($crmBase, $clientId, $crmUser, $crmPass, $timeout);
$crm = new CrmClient($crmBase, $auth, $clientId, $timeout);

$activeParam = $_GET['active'] ?? 'true';
$usersResult = $crm->getUsers([
    'active' => filter_var($activeParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
]);
$rolesResult = $crm->getRoles();

if (!empty($usersResult['error'])) {
    http_response_code(502);
    echo json_encode([
        'error' => $usersResult['message'] ?? 'Failed to load users',
        'users' => [],
        'roles' => [],
    ]);
    exit;
}

$rawUsers = $usersResult['data'] ?? $usersResult;
if (isset($rawUsers['id']) && !array_is_list($rawUsers)) {
    $rawUsers = [$rawUsers];
}
if (!is_array($rawUsers)) {
    $rawUsers = [];
}

$rawRoles = $rolesResult['data'] ?? [];
if (isset($rawRoles['id']) && !array_is_list($rawRoles)) {
    $rawRoles = [$rawRoles];
}
if (!is_array($rawRoles) || !empty($rolesResult['error'])) {
    $rawRoles = [];
}

$roleNames = [];
foreach ($rawRoles as $role) {
    if (!is_array($role)) {
        continue;
    }
    $id = (string) ($role['id'] ?? '');
    if ($id !== '') {
        $roleNames[$id] = (string) ($role['name'] ?? $id);
    }
}

$users = [];
foreach ($rawUsers as $user) {
    if (!is_array($user)) {
        continue;
    }
    $id = (string) ($user['id'] ?? '');
    if ($id === '') {
        continue;
    }
    $roleIds = $user['roles'] ?? [];
    if (!is_array($roleIds)) {
        $roleIds = [];
    }
    $roleIds = array_map('strval', $roleIds);
    $isAdmin = false;
    foreach ($roleIds as $rid) {
        $name = strtolower($roleNames[$rid] ?? '');
        if (str_contains($name, 'admin') || str_contains($name, 'super')) {
            $isAdmin = true;
            break;
        }
    }

    $users[] = [
        'id' => $id,
        'first_name' => (string) ($user['first_name'] ?? ''),
        'last_name' => (string) ($user['last_name'] ?? ''),
        'active' => (bool) ($user['active'] ?? true),
        'roles' => $roleIds,
        'role_names' => array_values(array_filter(array_map(
            static fn ($rid) => $roleNames[$rid] ?? null,
            $roleIds,
        ))),
        'role' => $isAdmin ? 'super_admin' : 'user',
        'email' => '',
    ];
}

echo json_encode([
    'users' => $users,
    'roles' => array_map(static fn ($r) => [
        'id' => (string) ($r['id'] ?? ''),
        'name' => (string) ($r['name'] ?? ''),
    ], $rawRoles),
], JSON_UNESCAPED_UNICODE);
