<?php
require_once __DIR__ . '/../includes/auth.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'POST required'], 405);
}

if (!empty($_SESSION['ldap_ephemeral'])) {
    json_response(['ok' => false, 'error' => 'Profile cannot be saved for group-only LDAP sessions. Ask an admin to add your account.'], 403);
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($body)) {
    json_response(['ok' => false, 'error' => 'Invalid JSON'], 400);
}

$action = $body['action'] ?? 'save_prefs';

$data = load_auth_data();
$userIdx = null;
foreach ($data['users'] as $i => $u) {
    if (($u['id'] ?? '') === $_SESSION['user_id']) {
        $userIdx = $i;
        break;
    }
}
if ($userIdx === null) {
    json_response(['ok' => false, 'error' => 'User not found'], 404);
}

$user = $data['users'][$userIdx];

if ($action === 'change_password') {
    if (($user['type'] ?? 'local') !== 'local') {
        json_response(['ok' => false, 'error' => 'LDAP users cannot change password here'], 400);
    }
    $current = $body['current_password'] ?? '';
    $newPass = $body['new_password'] ?? '';
    if (!password_verify($current, $user['password_hash'] ?? '')) {
        json_response(['ok' => false, 'error' => 'Current password incorrect'], 400);
    }
    if (strlen($newPass) < 6) {
        json_response(['ok' => false, 'error' => 'New password must be at least 6 characters'], 400);
    }
    $data['users'][$userIdx]['password_hash'] = password_hash($newPass, PASSWORD_DEFAULT);
    $data['users'][$userIdx]['must_change_password'] = false;
    save_auth_data($data);
    json_response(['ok' => true]);
}

if ($action === 'save_prefs') {
    $allowedModes = ['none', 'flash', 'horn', 'both'];
    $prefs = $user['prefs'] ?? [];
    $global = $data['global'];

    if (isset($body['theme']) && in_array($body['theme'], ['dark', 'light'], true)) {
        $prefs['theme'] = $body['theme'];
    }
    if (isset($body['alert_mode']) && in_array($body['alert_mode'], $allowedModes, true)) {
        if ($global['force_alert_mode'] === null) {
            $prefs['alert_mode'] = $body['alert_mode'];
        }
    }
    if (isset($body['show_ignored_services']) && $global['force_show_ignored_services'] === null) {
        $prefs['show_ignored_services'] = (bool)$body['show_ignored_services'];
    }
    if (isset($body['hide_door']) && $global['force_hide_door'] === null) {
        $prefs['hide_door'] = (bool)$body['hide_door'];
    }
    if (isset($body['hide_win_updates']) && $global['force_hide_win_updates'] === null) {
        $prefs['hide_win_updates'] = (bool)$body['hide_win_updates'];
    }

    $data['users'][$userIdx]['prefs'] = array_merge(DEFAULT_PREFS, $prefs);
    save_auth_data($data);
    json_response(['ok' => true, 'prefs' => effective_prefs($data['users'][$userIdx], $data['global'])]);
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
