<?php
/**
 * Extension Status - FreePBX 17
 *
 * Admin-only page listing every PJSIP contact, with per-contact SIP NOTIFY
 * actions for handsets that support them.
 *
 * Three files:
 *   extensionstatus.php       this file - configuration, access control, routing
 *   extensionstatus.lib.php   AMI access, User-Agent parsing, NOTIFY dispatch
 *   extensionstatus.view.php  markup, styling and browser code
 *
 * Three entry points, all behind the same admin session check:
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

// After a NOTIFY, the page watches this log for the handset re-fetching its
// provisioning files, which is how a check-sync is confirmed to have landed.
// The web server must be able to read it - see the README. Set to '' to turn
// the verification step off.
$es_access_log = '/var/log/apache2/other_vhosts_access.log';

// How long to keep watching before calling it a failure, in seconds.
$es_verify_window = 30;

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

$es_t = ['start' => microtime(true)];

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// session_start() blocks until it can take the per-session lock, so this
// measures contention with any other request on the same session, not just
// the read itself.
$es_t['session'] = microtime(true);
if (empty($_SESSION['AMP_user'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    die('Not logged in! Please log in to your FreePBX dashboard before opening this page...');
}
if (empty($_SESSION['es_csrf'])) {
    $_SESSION['es_csrf'] = bin2hex(random_bytes(32));
}
$es_csrf = $_SESSION['es_csrf'];
$es_amp_user = $_SESSION['AMP_user'];

// Release the session lock before the slow part of the request. PHP serialises
// requests that hold the same session open, so without this a button click
// landing while the auto-refresh is in flight waits for it to finish first.
// Nothing below writes to the session.
session_write_close();
$es_t['unlock'] = microtime(true);

// Load FreePBX bootstrap environment
include '/etc/freepbx.conf';
$fcore = FreePBX::Core();
$es_t['bootstrap'] = microtime(true);

// Load AMI
global $astman;

require __DIR__ . '/extensionstatus.lib.php';

// Name for the audit log. Must go through the helper: FreePBX stores an
// ampuser OBJECT here, and casting that to string is a fatal error.
$es_user = es_session_username($es_amp_user);

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
    // Validation only needs uri/brand/aor, so skip the display-name lookups
    // es_build_rows() would do - this path should stay as short as possible.
    $result = es_send_notify(
        $astman,
        (string) ($_POST['uri'] ?? ''),
        (string) ($_POST['action_id'] ?? ''),
        $es_notify_actions,
        es_build_targets($astman),
        $es_user
    );
    $es_t['dispatch'] = microtime(true);
    $result += es_timings($es_t);
    error_log('extensionstatus: notify timing ' . $result['timing']);
    es_json($result, $result['ok'] ? 200 : 400);
}

// GET ?action=verify: has this handset re-fetched its config since $since?
// Polled by the browser after a NOTIFY; never touched on a normal page load.
if (($_GET['action'] ?? '') === 'verify') {
    $ip    = (string) ($_GET['ip'] ?? '');
    $since = (int) ($_GET['since'] ?? 0);
    // Only an IP belonging to a currently registered contact may be queried,
    // so this cannot be used to sift the access log generally.
    $known = false;
    $model = '';
    foreach (es_build_rows($astman, $fcore) as $r) {
        if ($r['uri_ip'] === $ip) {
            $known = true;
            $model = $r['model'];
            break;
        }
    }
    if (!$known || $since <= 0) {
        es_json(['ok' => false, 'message' => 'Unknown contact.'], 400);
    }
    $res = es_verify_fetch($es_access_log, $ip, $model, $since - 5);
    es_json(['ok' => true] + $res);
}

// Verification is optional: it needs the web server to be able to read the
// access log, which is a deliberate permission change an operator may decline.
// Declining is a supported configuration, not an error - when it is off the
// page simply never offers the check.
$es_verify_enabled = ($es_access_log !== '' && is_readable($es_access_log));

$es_rows = es_build_rows($astman, $fcore);

// GET ?action=data: rows only, for the auto-refresh.
if (($_GET['action'] ?? '') === 'data') {
    es_json(['ok' => true, 'rows' => $es_rows, 'generated' => date('H:i:s')]);
}
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

require __DIR__ . '/extensionstatus.view.php';
