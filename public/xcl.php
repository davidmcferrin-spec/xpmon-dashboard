<?php
/**
 * xcl.php — Export host list as StatusClientList.xcl
 * Generates a Ross Video XPression Status Client compatible XCL file
 * from the current config.json host list.
 */

require_once __DIR__ . '/includes/auth.php';
require_permission('xcl_export');

$config_path = __DIR__ . '/config.json';

if (!file_exists($config_path)) {
    http_response_code(500);
    die('config.json not found');
}

$config = json_decode(file_get_contents($config_path), true);
if (!$config || !isset($config['hosts'])) {
    http_response_code(500);
    die('Invalid config.json');
}

$null_guid = '{00000000-0000-0000-0000-000000000000}';

function xml_esc(string $val): string {
    return htmlspecialchars($val, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

$clients_xml = '';
foreach ($config['hosts'] as $host) {
    $name     = xml_esc($host['display_name'] ?? '');
    $ip       = xml_esc($host['ip'] ?? '');
    $port     = (int)($host['port'] ?? 9875);
    $hostname = xml_esc($host['ip'] ?? '');
    $reported = xml_esc(strtolower($host['display_name'] ?? $host['ip'] ?? ''));

    $clients_xml .= <<<XML
<client>
<machinename>{$name}</machinename>
<machinendesc/>
<machinengroup/>
<hostname>{$hostname}</hostname>
<ip>{$ip}</ip>
<reportedhostname>{$reported}</reportedhostname>
<port>{$port}</port>
<expanded>0</expanded>
<group>{$null_guid}</group>
</client>

XML;
}

$xcl = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<config>
<groups/>
<clients>
{$clients_xml}</clients>
</config>
XML;

$filename = 'StatusClientList_' . date('Ymd_His') . '.xcl';

header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('Content-Length: ' . strlen($xcl));

echo $xcl;
