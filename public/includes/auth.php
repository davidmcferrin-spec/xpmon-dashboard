<?php
/**
 * auth.php — Session auth, roles, LDAP, user prefs.
 * PHP/UI-only security — bridge WebSocket is not authenticated.
 */

declare(strict_types=1);

const AUTH_DATA_FILE = __DIR__ . '/../../data/auth.json';

const PERMISSIONS = [
    'dashboard',
    'xcl_export',
    'bridge_view',
    'bridge_control',
    'manage_hosts',
    'view_host_commands',
    'execute_host_commands',
    'manage_users',
];

const DEFAULT_ROLES = [
    'admin' => [
        'label' => 'Administrator',
        'permissions' => [
            'dashboard' => true,
            'xcl_export' => true,
            'bridge_view' => true,
            'bridge_control' => true,
            'manage_hosts' => true,
            'view_host_commands' => true,
            'execute_host_commands' => true,
            'manage_users' => true,
        ],
    ],
    'operator' => [
        'label' => 'Operator',
        'permissions' => [
            'dashboard' => true,
            'xcl_export' => false,
            'bridge_view' => false,
            'bridge_control' => false,
            'manage_hosts' => false,
            'view_host_commands' => true,
            'execute_host_commands' => true,
            'manage_users' => false,
        ],
    ],
    'viewer' => [
        'label' => 'Viewer',
        'permissions' => [
            'dashboard' => true,
            'xcl_export' => false,
            'bridge_view' => false,
            'bridge_control' => false,
            'manage_hosts' => false,
            'view_host_commands' => false,
            'execute_host_commands' => false,
            'manage_users' => false,
        ],
    ],
    'control_viewer' => [
        'label' => 'Control Viewer',
        'permissions' => [
            'dashboard' => true,
            'xcl_export' => false,
            'bridge_view' => false,
            'bridge_control' => false,
            'manage_hosts' => false,
            'view_host_commands' => true,
            'execute_host_commands' => false,
            'manage_users' => false,
        ],
    ],
    'bridge_monitor' => [
        'label' => 'Bridge Monitor',
        'permissions' => [
            'dashboard' => true,
            'xcl_export' => false,
            'bridge_view' => true,
            'bridge_control' => false,
            'manage_hosts' => false,
            'view_host_commands' => false,
            'execute_host_commands' => false,
            'manage_users' => false,
        ],
    ],
];

const DEFAULT_PREFS = [
    'theme' => 'dark',
    'alert_mode' => 'both',
    'show_ignored_services' => true,
    'hide_door' => false,
    'hide_win_updates' => false,
];

const DEFAULT_GLOBAL = [
    'force_alert_mode' => null,
    'force_show_ignored_services' => null,
    'force_hide_door' => null,
    'force_hide_win_updates' => null,
    'default_alert_mode' => 'both',
    'default_show_ignored_services' => true,
    'default_hide_door' => false,
    'default_hide_win_updates' => false,
];

const DEFAULT_LDAP = [
    'enabled' => false,
    'host' => '',
    'port' => 636,
    'bind_template' => '{username}@example.com',
    'ignore_cert' => true,
    'allowed_groups' => [],
];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------------
// Data file
// ---------------------------------------------------------------------------

function auth_data_dir(): string
{
    return dirname(AUTH_DATA_FILE);
}

function load_auth_data(): array
{
    ensure_auth_data();
    $raw = file_get_contents(AUTH_DATA_FILE);
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) {
        $data = [];
    }
    $data['ldap'] = array_merge(DEFAULT_LDAP, $data['ldap'] ?? []);
    $data['global'] = array_merge(DEFAULT_GLOBAL, $data['global'] ?? []);
    $data['users'] = $data['users'] ?? [];
    return $data;
}

function save_auth_data(array $data): bool
{
    ensure_auth_data();
    $data['ldap'] = array_merge(DEFAULT_LDAP, $data['ldap'] ?? []);
    $data['global'] = array_merge(DEFAULT_GLOBAL, $data['global'] ?? []);
    $data['users'] = $data['users'] ?? [];
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = AUTH_DATA_FILE . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    return rename($tmp, AUTH_DATA_FILE);
}

function ensure_auth_data(): void
{
    $dir = auth_data_dir();
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    if (!file_exists(AUTH_DATA_FILE)) {
        seed_auth_data();
    }
}

function seed_auth_data(): void
{
    $data = [
        'ldap' => DEFAULT_LDAP,
        'global' => DEFAULT_GLOBAL,
        'users' => [
            [
                'id' => uuid4(),
                'username' => 'admin',
                'type' => 'local',
                'password_hash' => password_hash('admin', PASSWORD_DEFAULT),
                'roles' => ['admin'],
                'permission_overrides' => new stdClass(),
                'prefs' => DEFAULT_PREFS,
                'enabled' => true,
                'must_change_password' => true,
            ],
        ],
    ];
    // JSON encode stdClass as {}
    $data['users'][0]['permission_overrides'] = [];
    save_auth_data($data);
}

function uuid4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ---------------------------------------------------------------------------
// Session
// ---------------------------------------------------------------------------

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user_record(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    $data = load_auth_data();
    foreach ($data['users'] as $user) {
        if (($user['id'] ?? '') === $_SESSION['user_id']) {
            return $user;
        }
    }
    return null;
}

function effective_permissions(array $user): array
{
    $merged = [];
    foreach (PERMISSIONS as $perm) {
        $merged[$perm] = false;
    }
    $roles = $user['roles'] ?? [];
    foreach ($roles as $roleId) {
        $role = DEFAULT_ROLES[$roleId] ?? null;
        if (!$role) {
            continue;
        }
        foreach ($role['permissions'] as $perm => $val) {
            if ($val) {
                $merged[$perm] = true;
            }
        }
    }
    $overrides = $user['permission_overrides'] ?? [];
    foreach ($overrides as $perm => $val) {
        if (in_array($perm, PERMISSIONS, true)) {
            $merged[$perm] = (bool)$val;
        }
    }
    return $merged;
}

function effective_prefs(array $user, array $global): array
{
    $prefs = array_merge(DEFAULT_PREFS, $user['prefs'] ?? []);
    $map = [
        'alert_mode' => 'force_alert_mode',
        'show_ignored_services' => 'force_show_ignored_services',
        'hide_door' => 'force_hide_door',
        'hide_win_updates' => 'force_hide_win_updates',
    ];
    foreach ($map as $prefKey => $forceKey) {
        if (array_key_exists($forceKey, $global) && $global[$forceKey] !== null) {
            $prefs[$prefKey] = $global[$forceKey];
        } elseif (!isset($user['prefs'][$prefKey])) {
            $defaultKey = 'default_' . $prefKey;
            if (array_key_exists($defaultKey, $global)) {
                $prefs[$prefKey] = $global[$defaultKey];
            }
        }
    }
    return $prefs;
}

function has_permission(string $perm): bool
{
    $payload = session_user_payload_full();
    if (!$payload) {
        return false;
    }
    return !empty($payload['permissions'][$perm]);
}

function require_login(string $redirect = 'login.php'): void
{
    if (!is_logged_in()) {
        $next = $_SERVER['REQUEST_URI'] ?? 'index.php';
        header('Location: ' . $redirect . '?next=' . urlencode($next));
        exit;
    }
    $user = current_user_record_with_ldap();
    if (!$user || empty($user['enabled'])) {
        logout_user();
        header('Location: ' . $redirect);
        exit;
    }
}

function require_permission(string $perm): void
{
    require_login();
    if (!has_permission($perm)) {
        http_response_code(403);
        die('Access denied.');
    }
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// ---------------------------------------------------------------------------
// LDAP
// ---------------------------------------------------------------------------

function ldap_connect_config(array $ldap): mixed
{
    if (!extension_loaded('ldap')) {
        return null;
    }
    $host = trim($ldap['host'] ?? '');
    if ($host === '') {
        return null;
    }
    $port = (int)($ldap['port'] ?? 636);
    $uri = $host;
    if (!str_contains($uri, '://')) {
        $uri = 'ldaps://' . ltrim($uri, '/');
    }
    if (!str_contains($uri, ':')) {
        $uri .= ':' . $port;
    }
    $conn = @ldap_connect($uri);
    if (!$conn) {
        return null;
    }
    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    if (!empty($ldap['ignore_cert'])) {
        ldap_set_option($conn, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
    }
    return $conn;
}

function ldap_bind_user(array $ldap, string $username, string $password): array
{
    if ($password === '') {
        return ['ok' => false, 'error' => 'Password required'];
    }
    $conn = ldap_connect_config($ldap);
    if (!$conn) {
        return ['ok' => false, 'error' => 'LDAP unavailable'];
    }
    $template = $ldap['bind_template'] ?? '{username}';
    $bindDn = str_replace('{username}', $username, $template);
    if (!@ldap_bind($conn, $bindDn, $password)) {
        return ['ok' => false, 'error' => 'Invalid credentials'];
    }
    $groups = ldap_read_member_of($conn, $bindDn);
    ldap_unbind($conn);
    return ['ok' => true, 'bind_dn' => $bindDn, 'member_of' => $groups];
}

function ldap_read_member_of(mixed $conn, string $bindDn): array
{
    $result = @ldap_read($conn, $bindDn, '(objectClass=*)', ['memberOf']);
    if (!$result) {
        return [];
    }
    $entries = ldap_get_entries($conn, $result);
    if ($entries['count'] < 1) {
        return [];
    }
    $memberOf = $entries[0]['memberof'] ?? [];
    unset($memberOf['count']);
    return array_values($memberOf);
}

function ldap_group_matches(string $memberOfDn, string $groupName): bool
{
    $groupName = strtolower(trim($groupName));
    if ($groupName === '') {
        return false;
    }
    $dn = strtolower($memberOfDn);
    if ($dn === $groupName) {
        return true;
    }
    if (preg_match('/^cn=([^,]+)/', $dn, $m)) {
        return strtolower($m[1]) === $groupName;
    }
    return str_contains($dn, $groupName);
}

function roles_from_ldap_groups(array $memberOf, array $allowedGroups): array
{
    $roles = [];
    foreach ($allowedGroups as $entry) {
        if (is_string($entry)) {
            $entry = ['name' => $entry, 'roles' => ['viewer']];
        }
        $name = $entry['name'] ?? '';
        $groupRoles = $entry['roles'] ?? ['viewer'];
        foreach ($memberOf as $dn) {
            if (ldap_group_matches($dn, $name)) {
                foreach ($groupRoles as $r) {
                    $roles[$r] = true;
                }
            }
        }
    }
    return array_keys($roles);
}

function find_user_by_username(string $username): ?array
{
    $data = load_auth_data();
    $username = strtolower(trim($username));
    foreach ($data['users'] as $user) {
        if (strtolower($user['username'] ?? '') === $username) {
            return $user;
        }
    }
    return null;
}

function authenticate(string $username, string $password): array
{
    $data = load_auth_data();
    $user = find_user_by_username($username);

    if ($user && empty($user['enabled'])) {
        return ['ok' => false, 'error' => 'Account disabled'];
    }

    if ($user && ($user['type'] ?? 'local') === 'local') {
        if (!password_verify($password, $user['password_hash'] ?? '')) {
            return ['ok' => false, 'error' => 'Invalid credentials'];
        }
        login_user($user);
        return ['ok' => true, 'user' => $user];
    }

    $ldap = $data['ldap'];
    if (empty($ldap['enabled'])) {
        if ($user && ($user['type'] ?? '') === 'ldap') {
            return ['ok' => false, 'error' => 'LDAP is not enabled'];
        }
        return ['ok' => false, 'error' => 'Invalid credentials'];
    }

    $ldapResult = ldap_bind_user($ldap, $username, $password);
    if (!$ldapResult['ok']) {
        return ['ok' => false, 'error' => $ldapResult['error']];
    }

    if ($user) {
        if (($user['type'] ?? '') !== 'ldap') {
            return ['ok' => false, 'error' => 'Invalid credentials'];
        }
        login_user($user);
        return ['ok' => true, 'user' => $user];
    }

    $groupRoles = roles_from_ldap_groups($ldapResult['member_of'], $ldap['allowed_groups'] ?? []);
    if (empty($groupRoles)) {
        return ['ok' => false, 'error' => 'Not authorized — no matching LDAP group'];
    }

    $ephemeral = [
        'id' => 'ldap:' . strtolower($username),
        'username' => $username,
        'type' => 'ldap',
        'roles' => $groupRoles,
        'permission_overrides' => [],
        'prefs' => DEFAULT_PREFS,
        'enabled' => true,
        'must_change_password' => false,
    ];
    login_user($ephemeral);
    $_SESSION['ldap_ephemeral'] = true;
    $_SESSION['ldap_roles'] = $groupRoles;
    return ['ok' => true, 'user' => $ephemeral, 'ephemeral' => true];
}

function current_user_record_with_ldap(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    if (!empty($_SESSION['ldap_ephemeral'])) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'] ?? '',
            'type' => 'ldap',
            'roles' => $_SESSION['ldap_roles'] ?? ['viewer'],
            'permission_overrides' => [],
            'prefs' => DEFAULT_PREFS,
            'enabled' => true,
            'must_change_password' => false,
        ];
    }
    return current_user_record();
}

function session_user_payload_full(): ?array
{
    $user = current_user_record_with_ldap();
    if (!$user) {
        return null;
    }
    $data = load_auth_data();
    if (!empty($_SESSION['ldap_ephemeral'])) {
        $stored = find_user_by_username($user['username']);
        if ($stored && ($stored['type'] ?? '') === 'ldap') {
            $user = $stored;
            unset($_SESSION['ldap_ephemeral'], $_SESSION['ldap_roles']);
        }
    }
    $perms = effective_permissions($user);
    $prefs = effective_prefs($user, $data['global']);
    return [
        'id' => $user['id'],
        'username' => $user['username'],
        'type' => $user['type'] ?? 'local',
        'roles' => $user['roles'] ?? [],
        'permissions' => $perms,
        'prefs' => $prefs,
        'must_change_password' => !empty($user['must_change_password']),
    ];
}

function json_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}
