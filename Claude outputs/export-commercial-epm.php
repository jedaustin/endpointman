#!/usr/bin/env php
<?php
/**
 * export-commercial-epm.php - export the Sangoma commercial Endpoint Manager's extension
 * mapping (FreePBX 13-16, module "endpoint") to JSON, for import into the OSS Endpoint
 * Manager on TangoPBX / FreePBX 17.
 *
 * Read-only: it only SELECTs from the endpoint_* tables. It exports no SIP secrets and no
 * passwords; the OSS module reads each extension's secret from the new PBX when it builds the
 * phone's files.
 *
 * Run on the OLD system as root (or any user that can read /etc/freepbx.conf):
 *
 *     php export-commercial-epm.php                 > epm-mapping.json
 *     php export-commercial-epm.php --check         (data-quality report only, nothing written)
 *     php export-commercial-epm.php --pretty --out=/root/epm-mapping.json
 *
 * Then copy epm-mapping.json to the new system and use Endpoint Manager > Extension Mapping >
 * Import. Fix anything the --check report flags first; the importer flags the same things.
 *
 * Options: --check (report only), --pretty (indented JSON), --out=FILE (write instead of stdout),
 *          --conf=FILE (default /etc/freepbx.conf), --include-templates (add the raw template
 *          rows, for reference only; the OSS module cannot import them).
 *
 * Part of the OSS Endpoint Manager (endpointman) for FreePBX. License: GPLv3+ like the module.
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    echo "This script runs from the command line only.\n";
    exit(1);
}

$opts = getopt('', array('check', 'pretty', 'out:', 'conf:', 'include-templates', 'help'));
if (isset($opts['help'])) {
    fwrite(STDERR, preg_replace('/^.*?\/\*\*(.*?)\*\/.*$/s', '$1', file_get_contents(__FILE__)) . "\n");
    exit(0);
}
$check   = isset($opts['check']);
$pretty  = isset($opts['pretty']) || $check;
$conf    = isset($opts['conf']) ? $opts['conf'] : '/etc/freepbx.conf';
$out     = isset($opts['out']) ? $opts['out'] : null;

// ---- database credentials from /etc/freepbx.conf (no bootstrap: the old box may be half dead)
if (!is_readable($conf)) {
    fail("cannot read $conf (run as root?)");
}
$amp_conf = array();
$src = file_get_contents($conf);
foreach (array('AMPDBHOST', 'AMPDBUSER', 'AMPDBPASS', 'AMPDBNAME', 'AMPDBENGINE', 'AMPDBPORT', 'AMPDBSOCK') as $k) {
    if (preg_match('/\$amp_conf\s*\[\s*[\'"]' . $k . '[\'"]\s*\]\s*=\s*[\'"](.*?)[\'"]\s*;/', $src, $m)) {
        $amp_conf[$k] = $m[1];
    }
}
$host = isset($amp_conf['AMPDBHOST']) ? $amp_conf['AMPDBHOST'] : 'localhost';
$name = isset($amp_conf['AMPDBNAME']) ? $amp_conf['AMPDBNAME'] : 'asterisk';
$user = isset($amp_conf['AMPDBUSER']) ? $amp_conf['AMPDBUSER'] : 'freepbxuser';
$pass = isset($amp_conf['AMPDBPASS']) ? $amp_conf['AMPDBPASS'] : '';
$dsn  = 'mysql:dbname=' . $name . ';charset=utf8';
if (!empty($amp_conf['AMPDBSOCK'])) {
    $dsn .= ';unix_socket=' . $amp_conf['AMPDBSOCK'];
} else {
    $dsn .= ';host=' . $host . (empty($amp_conf['AMPDBPORT']) ? '' : ';port=' . $amp_conf['AMPDBPORT']);
}
try {
    $db = new PDO($dsn, $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
} catch (Exception $e) {
    fail("database connection failed: " . $e->getMessage());
}

// ---- is the commercial module here at all?
$tables = $db->query("SHOW TABLES LIKE 'endpoint_%'")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('endpoint_extensions', $tables)) {
    fail("table endpoint_extensions not found: the commercial Endpoint Manager was never installed on this database");
}
$mod_ver = '';
try {
    $mod_ver = (string) $db->query("SELECT version FROM modules WHERE modulename = 'endpoint'")->fetchColumn();
} catch (Exception $e) {
}
$fpbx_ver = '';
try {
    $fpbx_ver = (string) $db->query("SELECT value FROM admin WHERE variable = 'version'")->fetchColumn();
} catch (Exception $e) {
}

// ---- what the PBX itself knows (to validate against)
$pbx_exts = array();
try {
    foreach ($db->query("SELECT id, description, tech FROM devices")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pbx_exts[(string) $r['id']] = $r;
    }
} catch (Exception $e) {
}
$ouis = array();
if (in_array('endpoint_brand', $tables)) {
    foreach ($db->query("SELECT brand, oui FROM endpoint_brand")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ouis[strtoupper($r['oui'])] = strtolower($r['brand']);
    }
}
$model_accounts = array();
if (in_array('endpoint_models', $tables)) {
    foreach ($db->query("SELECT brand, model, accounts FROM endpoint_models")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $model_accounts[strtolower($r['brand']) . '/' . strtolower($r['model'])] = (int) $r['accounts'];
    }
}
$templates = array();
if (in_array('endpoint_templates', $tables)) {
    foreach ($db->query("SELECT * FROM endpoint_templates")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $templates[strtolower($r['brand']) . '/' . $r['template_name']] = $r;
    }
}

// ---- the mapping
$rows = $db->query("SELECT * FROM endpoint_extensions ORDER BY mac, account, ext")->fetchAll(PDO::FETCH_ASSOC);
$phones   = array();
$problems = array();
$note = function ($mac, $level, $msg) use (&$problems) {
    $problems[] = array('mac' => $mac, 'level' => $level, 'message' => $msg);
};

foreach ($rows as $r) {
    $mac_raw = (string) $r['mac'];
    $mac     = strtoupper(preg_replace('/[^0-9a-f]/i', '', $mac_raw));
    $key     = $mac !== '' ? $mac : 'ROW-' . md5(json_encode($r));
    $ext     = trim((string) $r['ext']);
    $account = strtolower(trim((string) $r['account']));
    $line    = preg_match('/^account\s*(\d+)$/', $account, $m) ? (int) $m[1] : 0;
    $brand   = strtolower(trim((string) $r['brand']));
    $model   = trim((string) $r['model']);

    if (!isset($phones[$key])) {
        $phones[$key] = array(
            'mac'      => $mac,
            'mac_raw'  => $mac_raw,
            'brand'    => $brand,
            'model'    => $model,
            'template' => (string) $r['template'],
            'lines'    => array(),
            'blf'      => array(),
            'rebuild'  => (string) $r['rebuild'],
            'extra'    => array(),
        );
        foreach (array('accessory', 'exp0', 'exp1', 'exp2', 'exp3', 'exp4', 'exp5', 'country', 'vpn', 'basestation', 'configure_by') as $f) {
            if (isset($r[$f]) && $r[$f] !== '' && $r[$f] !== null && $r[$f] !== 'None' && $r[$f] !== '0') {
                $phones[$key]['extra'][$f] = $r[$f];
            }
        }
        if (strlen($mac) !== 12) {
            $note($mac_raw, 'error', "MAC '$mac_raw' is not 12 hex digits");
        } elseif (preg_match('/^(.)\1{11}$/', $mac)) {
            $note($mac_raw, 'error', "MAC '$mac_raw' is a placeholder, not a device");
        } elseif ($ouis && !isset($ouis[substr($mac, 0, 6)])) {
            $note($mac_raw, 'info', "OUI " . substr($mac, 0, 6) . " is not in the commercial module's OUI list (the list is incomplete; only worth a look if the phone never provisioned)");
        } elseif ($ouis && $brand !== '' && $ouis[substr($mac, 0, 6)] !== $brand) {
            $note($mac_raw, 'warning', "OUI " . substr($mac, 0, 6) . " belongs to '" . $ouis[substr($mac, 0, 6)] . "' but the row says brand '$brand'");
        }
        if ($templates && !isset($templates[$brand . '/' . $r['template']])) {
            $note($mac_raw, 'warning', "template '" . $r['template'] . "' does not exist for brand '$brand' in endpoint_templates");
        }
    } else {
        if ($phones[$key]['model'] !== $model || $phones[$key]['brand'] !== $brand) {
            $note($mac_raw, 'error', "rows for this MAC disagree on brand/model ('" . $phones[$key]['brand'] . "/" . $phones[$key]['model'] . "' vs '$brand/$model')");
        }
    }

    if ($line < 1) {
        $note($mac_raw, 'error', "extension $ext has account value '" . $r['account'] . "' which is not accountN; line number unknown");
    }
    if ($pbx_exts && !isset($pbx_exts[$ext])) {
        $note($mac_raw, 'error', "extension $ext is mapped but does not exist on this PBX");
    }
    $phones[$key]['lines'][] = array(
        'line'        => $line,
        'ext'         => $ext,
        'description' => isset($pbx_exts[$ext]) ? $pbx_exts[$ext]['description'] : null,
        'tech'        => isset($pbx_exts[$ext]) ? $pbx_exts[$ext]['tech'] : null,
    );
    $blf = trim((string) $r['blf']);
    if ($blf !== '' && strtolower($blf) !== 'none' && strtolower($blf) !== 'null') {
        $phones[$key]['blf'][] = array('line' => $line, 'target' => $blf, 'label' => (string) $r['blf_label']);
    }
}

foreach ($phones as $key => &$p) {
    usort($p['lines'], function ($a, $b) { return $a['line'] - $b['line']; });
    $seen = array();
    foreach ($p['lines'] as $l) { $seen[$l['line']][] = $l['ext']; }
    foreach ($seen as $ln => $exts) {
        if ($ln >= 1 && count($exts) > 1) {
            $note($p['mac_raw'], 'error', "line $ln is assigned to " . count($exts) . " extensions (" . implode(', ', $exts) . "); the commercial module stored the same account for all of them, decide which extension goes on which line");
        }
    }
    $mk = $p['brand'] . '/' . strtolower($p['model']);
    if (isset($model_accounts[$mk]) && $model_accounts[$mk] > 0) {
        foreach ($p['lines'] as $l) {
            if ($l['line'] > $model_accounts[$mk]) {
                $note($p['mac_raw'], 'error', "extension " . $l['ext'] . " is on line " . $l['line'] . " but model " . $p['model'] . " has only " . $model_accounts[$mk] . " accounts");
            }
        }
    } elseif ($model_accounts) {
        $note($p['mac_raw'], 'warning', "model '" . $p['model'] . "' is not in endpoint_models for brand '" . $p['brand'] . "'");
    }
    if (empty($p['blf'])) unset($p['blf']);
    if (empty($p['extra'])) unset($p['extra']);
    if ($p['mac'] === $p['mac_raw']) unset($p['mac_raw']);
}
unset($p);

$doc = array(
    'format'       => 'oss-epm-mapping',
    'version'      => 1,
    'created'      => date('c'),
    'source'       => array(
        'host'            => php_uname('n'),
        'freepbx_version' => $fpbx_ver,
        'endpoint_module' => $mod_ver,
        'rows'            => count($rows),
    ),
    'phones'   => array_values($phones),
    'problems' => $problems,
);
if (isset($opts['include-templates'])) {
    $doc['templates_reference'] = array_values(array_map(function ($t) {
        foreach (array('ftppass', 'wWPAPSKPass1', 'wWPAPSKKey1', 'vlanPass', 'ldapPassword', 'menupin') as $s) {
            if (isset($t[$s])) $t[$s] = $t[$s] === '' ? '' : '<redacted>';
        }
        return $t;
    }, $templates));
}

if ($check) {
    $by = array('error' => 0, 'warning' => 0, 'info' => 0);
    foreach ($problems as $pr) $by[$pr['level']]++;
    fwrite(STDOUT, sprintf("%s: FreePBX %s, endpoint module %s, %d rows -> %d phones (%s)\n",
        php_uname('n'), $fpbx_ver ?: '?', $mod_ver ?: '?', count($rows), count($phones), implode(', ', array_map(function ($b, $n) { return "$n $b"; }, array_keys(brand_counts($phones)), brand_counts($phones)))));
    foreach ($phones as $p) {
        fwrite(STDOUT, sprintf("  %-14s %-9s %-10s %-26s lines: %s\n", $p['mac'] ?: $p['mac_raw'], $p['brand'], $p['model'], $p['template'],
            implode(' ', array_map(function ($l) { return $l['line'] . '=' . $l['ext']; }, $p['lines']))));
    }
    fwrite(STDOUT, sprintf("\n%d error(s), %d warning(s), %d note(s)\n", $by['error'], $by['warning'], $by['info']));
    foreach ($problems as $pr) {
        fwrite(STDOUT, sprintf("  %-7s %-14s %s\n", strtoupper($pr['level']), $pr['mac'], $pr['message']));
    }
    exit($by['error'] ? 2 : 0);
}

$json = json_encode($doc, $pretty ? (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : JSON_UNESCAPED_SLASHES);
if ($out !== null) {
    if (file_put_contents($out, $json . "\n") === false) fail("cannot write $out");
    fwrite(STDERR, sprintf("wrote %s: %d phones, %d problem(s) flagged (run with --check to read them)\n", $out, count($phones), count($problems)));
} else {
    echo $json, "\n";
}
exit(0);

function fail($msg) {
    fwrite(STDERR, "export-commercial-epm: $msg\n");
    exit(1);
}
function brand_counts($phones) {
    $c = array();
    foreach ($phones as $p) { $b = $p['brand'] ?: '?'; $c[$b] = isset($c[$b]) ? $c[$b] + 1 : 1; }
    return $c;
}
