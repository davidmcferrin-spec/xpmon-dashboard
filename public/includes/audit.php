<?php
/**
 * audit.php — Append-only audit log (data/audit.jsonl).
 */
declare(strict_types=1);

const AUDIT_FILE = __DIR__ . '/../../data/audit.jsonl';
const AUDIT_MAX_FILE_BYTES = 10_485_760; // 10 MB — rotate when exceeded
const AUDIT_MAX_READ_BYTES = 2_097_152;  // 2 MB cap when reading

function audit_client_ip(): string
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $first = $parts[0] ?? '';
        if ($first !== '') {
            return $first;
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function audit_log(string $action, array $details = [], ?bool $ok = null): bool
{
    $user = session_user_payload_full();
    if (!$user) {
        return false;
    }

    $entry = array_merge([
        'ts' => gmdate('c'),
        'username' => $user['username'],
        'user_id' => $user['id'],
        'ip' => audit_client_ip(),
        'action' => $action,
        'ok' => $ok,
    ], $details);

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return false;
    }

    $dir = dirname(AUDIT_FILE);
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        return false;
    }

    audit_maybe_rotate();

    $written = file_put_contents(AUDIT_FILE, $line . "\n", FILE_APPEND | LOCK_EX);
    return $written !== false;
}

function audit_maybe_rotate(): void
{
    if (!file_exists(AUDIT_FILE)) {
        return;
    }
    $size = filesize(AUDIT_FILE);
    if ($size === false || $size < AUDIT_MAX_FILE_BYTES) {
        return;
    }
    $rotated = AUDIT_FILE . '.' . gmdate('Ymd-His') . '.bak';
    @rename(AUDIT_FILE, $rotated);
}

/**
 * @return array{entries: list<array>, total: int}
 */
function audit_read(int $limit = 200, int $offset = 0): array
{
    if (!file_exists(AUDIT_FILE)) {
        return ['entries' => [], 'total' => 0];
    }

    $size = filesize(AUDIT_FILE);
    if ($size === false || $size === 0) {
        return ['entries' => [], 'total' => 0];
    }

    $readFrom = 0;
    if ($size > AUDIT_MAX_READ_BYTES) {
        $readFrom = $size - AUDIT_MAX_READ_BYTES;
    }

    $fh = fopen(AUDIT_FILE, 'rb');
    if ($fh === false) {
        return ['entries' => [], 'total' => 0];
    }
    if ($readFrom > 0) {
        fseek($fh, $readFrom);
        fgets($fh); // discard partial line
    }

    $entries = [];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $row = json_decode($line, true);
        if (is_array($row)) {
            $entries[] = $row;
        }
    }
    fclose($fh);

    $total = count($entries);
    $entries = array_reverse($entries);
    $entries = array_slice($entries, $offset, max(1, min($limit, 500)));

    return ['entries' => $entries, 'total' => $total];
}
