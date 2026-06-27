<?php
/**
 * xcl.php — Export host list as StatusClientList.xcl
 *
 * Generates a Ross Video XPression Status Client compatible XCL file
 * from the current config.json host list.
 *
 * Field mapping (verified against native client XCL export):
 *   <machinename>      = display_name  (user-set label)
 *   <hostname>         = hostname      = IP used to connect (or FQDN if known)
 *   <ip>               = ip            = connection IP
 *   <reportedhostname> = reported_hostname = lowercase hostname the server
 *                        announced in its serverinfo packet. Populated by
 *                        the bridge on first successful connection; falls
 *                        back to lowercase IP until bridge connects.
 *   <port>             = port
 *   <group>            = null GUID (ungrouped — groups not yet implemented)
 *
 * Compatible with XPression Status Client v12.x
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
    $ip       = $host['ip'] ?? '';
    $name     = xml_esc($host['display_name'] ?? $ip);
    $port     = (int)($host['port'] ?? 9875);

    // hostname: IP used to connect (or FQDN if stored from DNS lookup)
    $hostname = xml_esc($host['hostname'] ?? $ip);

    // reportedhostname: lowercase name the server announced in serverinfo.
    // Falls back to lowercase IP if the bridge hasn't connected yet.
    $reported = xml_esc($host['reported_hostname'] ?? strtolower($ip));

    $xml_ip   = xml_esc($ip);

    $clients_xml .= <<<XML
<client><machinename>{$name}</machinename><machinendesc/><machinengroup/><hostname>{$hostname}</hostname><ip>{$xml_ip}</ip><reportedhostname>{$reported}</reportedhostname><port>{$port}</port><expanded>0</expanded><group>{$null_guid}</group></client>
XML;
    $clients_xml .= "\n";
}

// Match the single-line compact format used by the native client
$xcl = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
     . '<config><groups/><clients>' . "\n"
     . $clients_xml
     . '</clients></config>' . "\n";

$filename = 'StatusClientList_' . date('Ymd_His') . '.xcl';

header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('Content-Length: ' . strlen($xcl));

echo $xcl;