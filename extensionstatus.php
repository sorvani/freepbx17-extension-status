<?php
/**
 * Extension Status - FreePBX 17
 *
 * Admin-only page listing every PJSIP contact, with per-row SIP NOTIFY actions
 * for Yealink handsets.
 *
 * One file, three entry points:
 *   GET  (no params)      full HTML page, with the row data embedded as JSON
 *   GET  ?action=data     JSON rows only, for the auto-refresh
 *   POST action=notify    sends a SIP NOTIFY over AMI, returns JSON
 */

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

// Auto-refresh interval in seconds, and whether it starts enabled.
$es_refresh_seconds = 30;
$es_refresh_default_on = true;

// Append a dump of the raw AMI response to the page.
$es_showdebug = false;

// NOTIFY actions, keyed by the brand es_device_info() reports. A brand with no
// entry here gets no buttons, which is what softphones (Acrobits, Zoiper,
// MicroSIP, Linphone, Jitsi...) want - they have nothing to check-sync.
//
// Headers are sent inline over AMI, so the page depends on no Asterisk config
// file at all. Add an action by adding an entry below; nothing needs reloading.
// Each set notes the sip_notify_*.conf section it mirrors. Those files escape
// the semicolon as "\;" for their own parser, which is not needed here.
$es_notify_actions = [
    'Yealink' => [
        // sip_notify_custom.conf: reload-yealink / restart-yealink / default-yealink
        'reload'  => ['label' => 'Reload config', 'confirm' => false, 'danger' => false,
                      'headers' => ['Event' => 'check-sync;reboot=false']],
        'restart' => ['label' => 'Reboot',        'confirm' => true,  'danger' => true,
                      'headers' => ['Event' => 'check-sync;reboot=true']],
        'reset'   => ['label' => 'Factory reset', 'confirm' => true,  'danger' => true,
                      'headers' => ['Content-Type' => 'message/sipfrag',
                                    'Event'        => 'ACTION-URI',
                                    'Content'      => 'key=Reset']],
    ],
    'Snom' => [
        // sip_notify_additional.conf: snom-check-cfg / snom-reboot-cfg / reboot-snom
        'reload'  => ['label' => 'Reload config', 'confirm' => false, 'danger' => false,
                      'headers' => ['Event' => 'check-sync;reboot=false']],
        'restart' => ['label' => 'Reboot',        'confirm' => true,  'danger' => true,
                      'headers' => ['Event' => 'check-sync;reboot=true']],
    ],
    'Sangoma' => [
        // sip_notify_additional.conf: sync-noreboot-sangoma / sync-reboot-sangoma
        'reload'  => ['label' => 'Reload config', 'confirm' => false, 'danger' => false,
                      'headers' => ['Event' => 'check-sync;reboot=false']],
        'restart' => ['label' => 'Reboot',        'confirm' => true,  'danger' => true,
                      'headers' => ['Event' => 'check-sync;reboot=true']],
    ],
    'Polycom' => [
        // sip_notify_additional.conf: polycom-check-cfg
        'reload'  => ['label' => 'Reload config', 'confirm' => false, 'danger' => false,
                      'headers' => ['Event' => 'check-sync']],
    ],
    'Grandstream' => [
        // sip_notify_additional.conf: grandstream-check-cfg
        'reload'  => ['label' => 'Reload config', 'confirm' => false, 'danger' => false,
                      'headers' => ['Event' => 'check-sync']],
    ],
    'Fanvil' => [
        // sip_notify_additional.conf: fanvil-check-cfg
        'reload'  => ['label' => 'Reload config', 'confirm' => false, 'danger' => false,
                      'headers' => ['Event' => 'check-sync']],
    ],
    'Cisco' => [
        // sip_notify_additional.conf: cisco-check-cfg
        'reload'  => ['label' => 'Reload config', 'confirm' => false, 'danger' => false,
                      'headers' => ['Event' => 'check-sync']],
    ],
    'Algo' => [
        // sip_notify_additional.conf: algo-check-cfg
        'reload'  => ['label' => 'Reload config', 'confirm' => false, 'danger' => false,
                      'headers' => ['Event' => 'check-sync']],
    ],
    'OBIHAI' => [
        // sip_notify_additional.conf: obihai-check-cfg
        'reload'  => ['label' => 'Reload config', 'confirm' => false, 'danger' => false,
                      'headers' => ['Event' => 'sync']],
    ],
];

// ---------------------------------------------------------------------------
// Access control
//
// This session check IS the access control for this page - it exposes every
// extension's public IP, device model and firmware, and it can reboot and
// factory-reset handsets. It must run before the FreePBX bootstrap, and the
// freepbx_auth opt-out the phonebook generators use must NOT be applied here.
// ---------------------------------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['AMP_user'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die('Not logged in! Please log in to your FreePBX dashboard before opening this page...');
}
if (empty($_SESSION['es_csrf'])) {
    $_SESSION['es_csrf'] = bin2hex(random_bytes(32));
}
$es_csrf = $_SESSION['es_csrf'];
$es_user = (string) $_SESSION['AMP_user'];

// Load FreePBX bootstrap environment
include '/etc/freepbx.conf';
$fcore = FreePBX::Core();

// Load AMI
global $astman;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Escape for HTML. The User-Agent is supplied by whatever registers. */
function es_h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Everything after the last $sep, or the whole string if $sep is absent. */
function es_last_after($s, $sep) {
    $parts = explode($sep, (string) $s);
    return end($parts);
}

/** Everything before the first $sep, or the whole string if $sep is absent. */
function es_first_before($s, $sep) {
    $parts = explode($sep, (string) $s);
    return $parts[0];
}

/** Send a JSON response and stop. */
function es_json($payload, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Transport actually in use for a contact, read off its URI.
 * SIP defaults to UDP when no transport parameter is present.
 */
function es_transport($uri) {
    $uri = (string) $uri;
    if (preg_match('/;transport=([A-Za-z]+)/i', $uri, $m)) {
        $t = strtolower($m[1]);
    } elseif (stripos($uri, 'sips:') === 0) {
        $t = 'tls';
    } else {
        $t = 'udp';
    }
    $known = ['udp' => 'UDP', 'tcp' => 'TCP', 'tls' => 'TLS', 'ws' => 'WS', 'wss' => 'WSS'];
    return $known[$t] ?? 'Other';
}

/** Validate an IP, or return a placeholder. */
function es_ip_or_placeholder($v) {
    return filter_var($v, FILTER_VALIDATE_IP) ? $v : 'Not an IP';
}

/**
 * Split a User-Agent into brand / model / firmware.
 *
 * Every index is defaulted. A single-token User-Agent ("Zulu") has no second
 * element and a two-token one ("Acrobits SIPIS") has no firmware; on PHP 8.2
 * under FreePBX's Whoops handler, reading either blind is a fatal error page.
 *
 ********** Examples
   "Yealink SIP VP-T49G 51.80.0.100"
   "Yealink SIP-T54W 96.85.0.5"
   "Zoiper rv2.10.8.2"
   "Grandstream HT802 1.0.17.5"
   "snomPA1/8.7.3.19"
   "LinphoneiOS/4.3.0 (Bob's iPhone) LinphoneSDK/4.4.0"
   "OBIHAI/OBi202-3.2.2.5921"
   "MicroSIP/3.20.5"
   "Acrobits SIPIS"                                   // Sangoma Connect push service
   "Telephone 1.5.2"                                  // macOS app "Telephone"
   "Linphone Desktop/4.2.5 (macOS 10.15, Qt 5.15.2) LinphoneCore/4.4.19"
   "Sangoma Connect/1.0.1"
   "Zulu"
   "PolycomRealPresenceTrio-Trio_8500-UA/5.9.2.7727"
   "PolycomSoundPointIP-SPIP_450-UA/4.0.15.1047"
   "Jitsi2.10.5550Windows 10"
   "Z 5.5.5 v2.10.15.2"                               // potentially Jitsi on macOS
   "Algo-8201/5.2"                                    // Algo door intercom
 **********/
function es_device_info($ua) {
    $ua = (string) $ua;
    $ua_arr = preg_split("/[\s\/]/", $ua, 2);
    $head = $ua_arr[0] ?? '';
    $rest = $ua_arr[1] ?? '';

    switch ($head) {
        case 'Yealink':
        case 'Zulu':
        case 'Z':
            $mf = preg_split("/[\s]/", preg_replace("/^SIP[\s-]/", '', $rest));
            return ['brand' => $head, 'model' => $mf[0] ?? '', 'firmware' => $mf[1] ?? ''];

        case 'Grandstream':
        case 'OBIHAI':
        case 'Fanvil':
        case 'Acrobits':
        case 'Cisco':
            $mf = preg_split("/[\s-]/", $rest);
            return ['brand' => $head, 'model' => $mf[0] ?? '', 'firmware' => $mf[1] ?? ''];

        case 'Sangoma':
            $mf = preg_split("/[\/]/", $rest);
            return ['brand' => $head, 'model' => $mf[0] ?? '', 'firmware' => $mf[1] ?? ''];

        case 'Zoiper':
        case 'MicroSIP':
        case 'Telephone':
            return ['brand' => $head, 'model' => '', 'firmware' => $rest];

        case 'snomPA1':
            return ['brand' => 'Snom', 'model' => 'PA1', 'firmware' => $rest];

        case 'LinphoneiOS':
            $mf = preg_split("/[\s]/", $rest);
            return ['brand' => $head, 'model' => '', 'firmware' => $mf[0] ?? ''];

        case 'Linphone': // Linphone Desktop
            $mf = preg_split("/[\s\/]/", preg_replace('/\(|\)/', '', $rest));
            return [
                'brand'    => trim($head . ' ' . ($mf[0] ?? '')),
                'model'    => $mf[2] ?? '',
                'firmware' => $mf[1] ?? '',
            ];
    }

    // Messy, will look into it after more Poly devices are tested
    if (substr($head, 0, 7) === 'Polycom') {
        $mf = preg_split("/[-]/", $head);
        return [
            'brand'    => 'Polycom',
            'model'    => preg_replace('/_/', ' ', $mf[1] ?? ''),
            'firmware' => $rest,
        ];
    }
    // Algo is Algo-NNNN/firmware
    if (substr($head, 0, 4) === 'Algo') {
        $mf = preg_split("/[-]/", $head);
        return ['brand' => $mf[0] ?? '', 'model' => $mf[1] ?? '', 'firmware' => $rest];
    }
    // Jitsi on Windows does not have a split character.
    if (substr($head, 0, 5) === 'Jitsi') {
        if (preg_match('/(\D+)([\d\.]+)(\D+.*)/', $ua, $m)) {
            return ['brand' => $m[1] ?? '', 'model' => $m[3] ?? '', 'firmware' => $m[2] ?? ''];
        }
        return ['brand' => 'Jitsi', 'model' => '', 'firmware' => ''];
    }

    return ['brand' => 'Unknown', 'model' => '', 'firmware' => ''];
}

/** Extension display name, cached so duplicate AORs cost one lookup. */
function es_display_name($fcore, $aor) {
    static $cache = [];
    if (array_key_exists($aor, $cache)) {
        return $cache[$aor];
    }
    // 90/98 prefixed AORs are FreePBX pseudo-devices; strip to the real extension.
    $ext = (str_starts_with($aor, '90') || str_starts_with($aor, '98'))
        ? substr($aor, 2)
        : $aor;
    $user = $fcore->getUser($ext);
    // getUser() returns false when the AOR has no matching extension.
    return $cache[$aor] = is_array($user) ? (string) ($user['name'] ?? '') : '';
}

/**
 * Fetch the contact statuses, at most once per request.
 *
 * FreePBX's PJSIPShowRegistrationInboundContactStatuses() appends to an
 * internal array without clearing it first, so calling it twice in one request
 * returns every contact twice (6, 12, 18, 24... on repeat calls). Everything
 * here goes through this wrapper so that cannot happen.
 */
function es_fetch_contacts($astman) {
    static $cache = null;
    if ($cache === null) {
        $r = $astman->PJSIPShowRegistrationInboundContactStatuses();
        $cache = is_array($r) ? $r : [];
    }
    return $cache;
}

/** Build the normalized row set the page and the JSON endpoint both use. */
function es_build_rows($astman, $fcore) {
    $results = es_fetch_contacts($astman);

    $rows = [];
    foreach ($results as $data) {
        $aor = (string) ($data['AOR'] ?? '');
        $uri = (string) ($data['URI'] ?? '');
        $ua  = (string) ($data['UserAgent'] ?? '');
        $dev = es_device_info($ua);

        $rtt = $data['RoundtripUsec'] ?? '';
        $rtt_ms = is_numeric($rtt) ? round($rtt / 1000, 1) : null;

        $expire = $data['RegExpire'] ?? '';
        $expire_unix = is_numeric($expire) ? (int) $expire : null;
        $expire_str = '-';
        if ($expire_unix !== null) {
            $dt = new DateTime('@' . $expire_unix, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
            $expire_str = $dt->format('Y/m/d H:i:s');
        }

        $rows[] = [
            'aor'        => $aor,
            'endpoint'   => (string) ($data['EndpointName'] ?? $aor),
            // Full contact URI - the NOTIFY target, so one handset is addressed
            // rather than every contact registered to the extension.
            'uri'        => $uri,
            'name'       => es_display_name($fcore, $aor),
            'brand'      => $dev['brand'],
            'model'      => $dev['model'],
            'firmware'   => $dev['firmware'],
            'useragent'  => $ua,
            'transport'  => es_transport($uri),
            'status'     => (string) ($data['Status'] ?? ''),
            'rtt_ms'     => $rtt_ms,
            'uri_ip'     => es_ip_or_placeholder(es_first_before(es_last_after($uri, '@'), ':')),
            'via_ip'     => es_ip_or_placeholder(es_first_before((string) ($data['ViaAddress'] ?? ''), ':')),
            'callid_ip'  => es_ip_or_placeholder(es_last_after((string) ($data['CallID'] ?? ''), '@')),
            'expire'     => $expire_unix,
            'expire_str' => $expire_str,
        ];
    }
    return $rows;
}

/**
 * Send a SIP NOTIFY to a single contact via the AMI PJSIPNotify action.
 *
 * Targets the contact URI rather than the endpoint, so an extension with
 * several registered contacts only gets the NOTIFY on the handset that was
 * clicked. URI mode routes through pjsip.conf's default_outbound_endpoint.
 *
 * Asterisk answers "Success: NOTIFY sent" as soon as it dispatches - including
 * for a URI nothing is listening on. It confirms dispatch, never delivery.
 */
function es_send_notify($astman, $uri, $action, array $actionmap, array $rows, $who) {
    // Only a URI that is currently a registered contact is targetable. Asterisk
    // will happily accept anything here, so this check is the real constraint.
    $match = null;
    foreach ($rows as $r) {
        if ($r['uri'] === $uri) {
            $match = $r;
            break;
        }
    }
    if ($match === null) {
        return ['ok' => false, 'message' => 'That contact is no longer registered - refresh the page.'];
    }
    // The action must be one defined for THAT row's brand, so a Yealink-only
    // command cannot be aimed at a Polycom by editing the request.
    $allowed = $actionmap[$match['brand']] ?? [];
    if (!isset($allowed[$action])) {
        return ['ok' => false, 'message' => 'That action is not available for a ' . $match['brand'] . ' device.'];
    }
    // Defence in depth: a CR or LF in a header value would let the rest of the
    // string be read as further AMI headers.
    if (preg_match('/[\r\n]/', $uri)) {
        return ['ok' => false, 'message' => 'Invalid contact URI.'];
    }

    $resp = $astman->send_request('PJSIPNotify', [
        'URI'      => $uri,
        'Variable' => $allowed[$action]['headers'],
    ]);
    $ok = isset($resp['Response']) && strcasecmp((string) $resp['Response'], 'Success') === 0;

    error_log(sprintf(
        'extensionstatus: %s sent %s to %s (ext %s) - %s',
        $who, $action, $uri, $match['aor'], $ok ? 'dispatched' : 'FAILED'
    ));

    return [
        'ok'      => $ok,
        'message' => $ok
            ? 'NOTIFY dispatched to extension ' . $match['aor'] . '.'
            : (string) ($resp['Message'] ?? 'Asterisk rejected the request.'),
    ];
}

// ---------------------------------------------------------------------------
// Request routing
// ---------------------------------------------------------------------------

if (!is_object($astman)) {
    if (($_GET['action'] ?? '') === 'data' || ($_POST['action'] ?? '') === 'notify') {
        es_json(['ok' => false, 'message' => 'No connection to the Asterisk Manager Interface.'], 503);
    }
    http_response_code(503);
    die('Could not connect to the Asterisk Manager Interface.');
}

// POST: send a NOTIFY.
if (($_POST['action'] ?? '') === 'notify') {
    if (!hash_equals($es_csrf, (string) ($_POST['csrf'] ?? ''))) {
        es_json(['ok' => false, 'message' => 'Session expired - reload the page.'], 403);
    }
    $rows = es_build_rows($astman, $fcore);
    $result = es_send_notify(
        $astman,
        (string) ($_POST['uri'] ?? ''),
        (string) ($_POST['action_id'] ?? ''),
        $es_notify_actions,
        $rows,
        $es_user
    );
    es_json($result, $result['ok'] ? 200 : 400);
}

$es_rows = es_build_rows($astman, $fcore);

// GET ?action=data: rows only, for the auto-refresh.
if (($_GET['action'] ?? '') === 'data') {
    es_json(['ok' => true, 'rows' => $es_rows, 'generated' => date('H:i:s')]);
}

// ---------------------------------------------------------------------------
// Page
// ---------------------------------------------------------------------------

$es_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

// Strip the NOTIFY headers before handing the action map to the browser: the
// client names an action, the server decides what that means.
$es_actions_public = [];
foreach ($es_notify_actions as $brand => $actions) {
    foreach ($actions as $id => $spec) {
        $es_actions_public[$brand][$id] = [
            'label'   => $spec['label'],
            'confirm' => $spec['confirm'],
            'danger'  => $spec['danger'],
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Extension Status</title>
<style>
  :root {
    --accent: #4CAF50;
    --border: #ddd;
    --hover: #f5f5f5;
    --muted: #777;
    --danger: #c62828;
  }
  body {
    margin: 0;
    padding: 16px;
    font: 14px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: #222;
  }
  .toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
  }
  .toolbar input[type="search"] {
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: 4px;
    min-width: 240px;
    font-size: 14px;
  }
  .toolbar label { display: flex; align-items: center; gap: 6px; }
  .spacer { flex: 1; }
  .meta { color: var(--muted); font-size: 13px; }

  .wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; }
  th {
    height: 44px;
    text-align: left;
    background-color: var(--accent);
    color: #000;
    padding: 8px 12px;
    white-space: nowrap;
  }
  th.sortable { cursor: pointer; user-select: none; }
  th.sortable:hover { filter: brightness(1.07); }
  th .arrow { opacity: .35; font-size: 11px; }
  th.sorted .arrow { opacity: 1; }
  td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: top;
  }
  tbody tr:hover { background-color: var(--hover); }

  .pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 12px;
    background: #eee;
  }
  .pill.udp { background: #e3f2fd; }
  .pill.tcp { background: #e8f5e9; }
  .pill.tls { background: #ede7f6; }
  .pill.ws, .pill.wss { background: #fff8e1; }
  .status-reachable { color: #2e7d32; }
  .status-unreachable, .status-unknown { color: var(--danger); }

  .ips { white-space: nowrap; font-size: 13px; }
  .ips b { font-weight: 600; }
  .ips .none { color: var(--muted); }

  .actions { white-space: nowrap; }
  .actions button {
    font-size: 12px;
    padding: 5px 9px;
    margin-right: 4px;
    border: 1px solid #bbb;
    border-radius: 4px;
    background: #fafafa;
    cursor: pointer;
  }
  .actions button:hover:not(:disabled) { background: #f0f0f0; }
  .actions button:disabled { opacity: .5; cursor: default; }
  .actions button.danger { border-color: #e0b4b4; color: var(--danger); }
  .actions .dash { color: var(--muted); }

  #toast {
    position: fixed;
    right: 16px;
    bottom: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    z-index: 10;
  }
  #toast div {
    padding: 10px 14px;
    border-radius: 4px;
    color: #fff;
    background: #333;
    box-shadow: 0 2px 8px rgba(0,0,0,.25);
    max-width: 380px;
  }
  #toast div.ok { background: #2e7d32; }
  #toast div.err { background: var(--danger); }

  .empty { padding: 24px; color: var(--muted); }
  pre.debug { background: #f7f7f7; padding: 12px; overflow-x: auto; }
</style>
</head>
<body>

<div class="toolbar">
  <input type="search" id="filter" placeholder="Filter (extension, name, brand, IP...)" autocomplete="off">
  <label><input type="checkbox" id="autorefresh"<?php echo $es_refresh_default_on ? ' checked' : ''; ?>> Auto-refresh every <?php echo (int) $es_refresh_seconds; ?>s</label>
  <button type="button" id="refreshnow">Refresh now</button>
  <div class="spacer"></div>
  <span class="meta" id="meta"></span>
</div>

<div class="wrap">
  <table>
    <thead><tr id="head"></tr></thead>
    <tbody id="body"></tbody>
  </table>
  <div class="empty" id="empty" hidden>No registered contacts.</div>
</div>

<div id="toast"></div>

<?php if ($es_showdebug): ?>
<h3>Raw AMI response</h3>
<pre class="debug"><?php echo es_h(print_r(es_fetch_contacts($astman), true)); ?></pre>
<?php endif; ?>

<script>
(function () {
  "use strict";

  var CSRF     = <?php echo json_encode($es_csrf, $es_json_flags); ?>;
  // Brand -> action id -> {label, confirm, danger}. The NOTIFY headers stay
  // server-side; the browser only ever names an action.
  var ACTIONS  = <?php echo json_encode($es_actions_public, $es_json_flags); ?>;
  var INTERVAL = <?php echo (int) $es_refresh_seconds; ?> * 1000;
  var rows     = <?php echo json_encode($es_rows, $es_json_flags); ?>;

  var COLUMNS = [
    { key: 'aor',        label: 'Extension',            type: 'num'  },
    { key: 'name',       label: 'Name',                 type: 'text' },
    { key: 'brand',      label: 'Brand',                type: 'text' },
    { key: 'model',      label: 'Model',                type: 'text' },
    { key: 'firmware',   label: 'Firmware',             type: 'text' },
    { key: 'transport',  label: 'Transport',            type: 'text' },
    { key: 'status',     label: 'Status',               type: 'text' },
    { key: 'rtt_ms',     label: 'Response Time',        type: 'num'  },
    { key: 'ips',        label: 'Known Device IPs',     sort: false  },
    { key: 'expire',     label: 'Registration Expires', type: 'num'  },
    { key: 'actions',    label: 'Actions',              sort: false  }
  ];

  var sortKey = 'aor', sortAsc = true, filter = '';
  var $head = document.getElementById('head');
  var $body = document.getElementById('body');
  var $empty = document.getElementById('empty');
  var $meta = document.getElementById('meta');
  var timer = null;

  function el(tag, text, cls) {
    var n = document.createElement(tag);
    // textContent throughout: the User-Agent is attacker-controlled.
    if (text !== undefined && text !== null) { n.textContent = String(text); }
    if (cls) { n.className = cls; }
    return n;
  }

  function toast(msg, ok) {
    var box = document.getElementById('toast');
    var t = el('div', msg, ok ? 'ok' : 'err');
    box.appendChild(t);
    setTimeout(function () { t.remove(); }, 6000);
  }

  function renderHead() {
    $head.textContent = '';
    COLUMNS.forEach(function (c) {
      var th = el('th', c.label);
      if (c.sort !== false) {
        th.className = 'sortable' + (sortKey === c.key ? ' sorted' : '');
        var a = el('span', sortKey === c.key ? (sortAsc ? ' ▲' : ' ▼') : ' ▵', 'arrow');
        th.appendChild(a);
        th.addEventListener('click', function () {
          if (sortKey === c.key) { sortAsc = !sortAsc; } else { sortKey = c.key; sortAsc = true; }
          render();
        });
      }
      $head.appendChild(th);
    });
  }

  function matches(r) {
    if (!filter) { return true; }
    var hay = [r.aor, r.name, r.brand, r.model, r.firmware, r.transport,
               r.status, r.uri_ip, r.via_ip, r.callid_ip, r.useragent]
              .join(' ').toLowerCase();
    return hay.indexOf(filter) !== -1;
  }

  function compare(a, b) {
    var col = COLUMNS.filter(function (c) { return c.key === sortKey; })[0];
    var av = a[sortKey], bv = b[sortKey];
    var r;
    if (col && col.type === 'num') {
      var an = av === null || av === '' ? NaN : Number(av);
      var bn = bv === null || bv === '' ? NaN : Number(bv);
      // Fall back to a string compare when the value is not numeric (e.g. a
      // non-numeric extension), and sort blanks last either way.
      if (isNaN(an) && isNaN(bn)) { r = String(av || '').localeCompare(String(bv || '')); }
      else if (isNaN(an)) { return 1; }
      else if (isNaN(bn)) { return -1; }
      else { r = an - bn; }
    } else {
      r = String(av === null ? '' : av).localeCompare(String(bv === null ? '' : bv));
    }
    return sortAsc ? r : -r;
  }

  function ipCell(r) {
    var td = el('td', null, 'ips');
    [['URI', r.uri_ip], ['Via', r.via_ip], ['CallID', r.callid_ip]].forEach(function (pair) {
      td.appendChild(el('b', pair[0] + ':'));
      td.appendChild(document.createTextNode(' '));
      if (pair[1] === 'Not an IP') {
        td.appendChild(el('span', pair[1], 'none'));
      } else {
        td.appendChild(document.createTextNode(pair[1]));
      }
      td.appendChild(document.createElement('br'));
    });
    return td;
  }

  function actionCell(r) {
    var td = el('td', null, 'actions');
    var available = ACTIONS[r.brand];
    if (!available) {
      // Softphone or unrecognised brand - nothing sensible to send it.
      td.appendChild(el('span', '—', 'dash'));
      return td;
    }
    Object.keys(available).forEach(function (id) {
      var spec = available[id];
      var b = el('button', spec.label);
      if (spec.danger) { b.className = 'danger'; }
      b.addEventListener('click', function () {
        if (spec.confirm) {
          // Name the specific handset: an extension can have several contacts
          // and this only hits the one in this row.
          var msg = spec.label + '\n\nExtension ' + r.aor +
                    (r.name ? ' (' + r.name + ')' : '') +
                    '\n' + r.brand + ' ' + r.model + ' at ' + r.uri_ip +
                    '\n\nThis affects only this handset. Continue?';
          if (!window.confirm(msg)) { return; }
        }
        sendNotify(r, id, b);
      });
      td.appendChild(b);
    });
    return td;
  }

  function sendNotify(r, actionId, button) {
    var siblings = button.parentNode.querySelectorAll('button');
    siblings.forEach(function (b) { b.disabled = true; });

    var body = new URLSearchParams();
    body.set('action', 'notify');
    body.set('csrf', CSRF);
    body.set('uri', r.uri);
    body.set('action_id', actionId);

    fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin'
    })
    .then(function (res) { return res.json().catch(function () {
      throw new Error('HTTP ' + res.status);
    }); })
    .then(function (j) {
      // Asterisk confirms dispatch, not delivery - say only what is true.
      toast(j.message, j.ok);
    })
    .catch(function (e) {
      toast('Request failed: ' + e.message, false);
    })
    .finally(function () {
      siblings.forEach(function (b) { b.disabled = false; });
    });
  }

  function render() {
    renderHead();
    var view = rows.filter(matches).slice().sort(compare);

    $body.textContent = '';
    view.forEach(function (r) {
      var tr = document.createElement('tr');
      tr.appendChild(el('td', r.aor));
      tr.appendChild(el('td', r.name));
      tr.appendChild(el('td', r.brand));
      tr.appendChild(el('td', r.model));
      tr.appendChild(el('td', r.firmware));

      var tt = el('td');
      tt.appendChild(el('span', r.transport, 'pill ' + r.transport.toLowerCase()));
      tr.appendChild(tt);

      tr.appendChild(el('td', r.status, 'status-' + String(r.status).toLowerCase()));
      tr.appendChild(el('td', r.rtt_ms === null ? '-' : r.rtt_ms + ' ms'));
      tr.appendChild(ipCell(r));
      tr.appendChild(el('td', r.expire_str));
      tr.appendChild(actionCell(r));
      $body.appendChild(tr);
    });

    $empty.hidden = view.length !== 0;
    $meta.textContent = view.length + ' of ' + rows.length + ' contact' +
                        (rows.length === 1 ? '' : 's');
  }

  function refresh() {
    fetch(window.location.pathname + '?action=data', { credentials: 'same-origin' })
      .then(function (res) {
        if (res.status === 403) { throw new Error('session expired - reload the page'); }
        return res.json();
      })
      .then(function (j) {
        if (!j.ok) { throw new Error(j.message || 'refresh failed'); }
        rows = j.rows;
        render();
        $meta.textContent += ' · updated ' + j.generated;
      })
      .catch(function (e) {
        toast('Refresh failed: ' + e.message, false);
        stopTimer();
        document.getElementById('autorefresh').checked = false;
      });
  }

  function startTimer() { stopTimer(); timer = setInterval(refresh, INTERVAL); }
  function stopTimer() { if (timer) { clearInterval(timer); timer = null; } }

  document.getElementById('filter').addEventListener('input', function (e) {
    filter = e.target.value.trim().toLowerCase();
    render();
  });
  document.getElementById('refreshnow').addEventListener('click', refresh);
  document.getElementById('autorefresh').addEventListener('change', function (e) {
    if (e.target.checked) { startTimer(); } else { stopTimer(); }
  });

  render();
  if (document.getElementById('autorefresh').checked) { startTimer(); }
})();
</script>
</body>
</html>
