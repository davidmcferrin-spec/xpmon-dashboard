<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

require_login();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    if (!has_permission('manage_users')) {
        json_response(['ok' => false, 'error' => 'Access denied'], 403);
    }
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $result = audit_read($limit, $offset);
    json_response([
        'ok' => true,
        'entries' => $result['entries'],
        'total' => $result['total'],
        'limit' => $limit,
        'offset' => $offset,
    ]);
}

if ($method !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) {
    json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$event = $body['event'] ?? '';

if ($event === 'host_command') {
    $command = $body['command'] ?? '';
    if (!in_array($command, ['start', 'stop', 'reboot'], true)) {
        json_response(['ok' => false, 'error' => 'Invalid command'], 400);
    }
    if ($command === 'reboot') {
        if (!has_permission('execute_reboot')) {
            json_response(['ok' => false, 'error' => 'Access denied'], 403);
        }
    } elseif (!has_permission('execute_service_commands')) {
        json_response(['ok' => false, 'error' => 'Access denied'], 403);
    }

    $logged = audit_log('host_command', [
        'command' => $command,
        'host_id' => (string)($body['host_id'] ?? ''),
        'host_name' => (string)($body['host_name'] ?? ''),
        'host_ip' => (string)($body['host_ip'] ?? ''),
    ], null);

    if (!$logged) {
        json_response(['ok' => false, 'error' => 'Failed to write audit log'], 500);
    }
    json_response(['ok' => true]);
}

if ($event === 'host_command_result') {
    $command = $body['command'] ?? '';
    if (!in_array($command, ['start', 'stop', 'reboot'], true)) {
        json_response(['ok' => false, 'error' => 'Invalid command'], 400);
    }
    if ($command === 'reboot') {
        if (!has_permission('execute_reboot')) {
            json_response(['ok' => false, 'error' => 'Access denied'], 403);
        }
    } elseif (!has_permission('execute_service_commands')) {
        json_response(['ok' => false, 'error' => 'Access denied'], 403);
    }

    $ok = !empty($body['ok']);
    $logged = audit_log('host_command_result', [
        'command' => $command,
        'host_id' => (string)($body['host_id'] ?? ''),
        'host_name' => (string)($body['host_name'] ?? ''),
        'host_ip' => (string)($body['host_ip'] ?? ''),
        'error' => $ok ? null : (string)($body['error'] ?? 'unknown error'),
    ], $ok);

    if (!$logged) {
        json_response(['ok' => false, 'error' => 'Failed to write audit log'], 500);
    }
    json_response(['ok' => true]);
}

json_response(['ok' => false, 'error' => 'Unknown event'], 400);
