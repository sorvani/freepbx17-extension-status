<?php
/**
 * Extension Status - AMI access, User-Agent parsing and NOTIFY dispatch.
 *
 * Required by extensionstatus.php, which owns configuration and access
 * control. Nothing here starts a session or checks one - by the time this is
 * loaded the caller has already established that an admin is logged in.
 *
 * Every function is prefixed es_ deliberately: this runs inside the FreePBX
 * bootstrap, which defines plenty of unprefixed globals of its own (out(),
 * for one), and a collision is a fatal redeclare.
 */

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Username to name in the audit log, from $_SESSION['AMP_user'].
 *
 * FreePBX stores an ampuser OBJECT there, not a string - see
 * admin/libraries/gui_auth.php ($_SESSION['AMP_user'] = new ampuser(...)) and
 * admin/config.php, which guards every use with is_object(). Casting it
 * straight to string throws "Object of class ampuser could not be converted to
 * string", which is a 500 on the authenticated path only - the exact shape of
 * bug a test that fakes the session with a string will never catch.
 */
function es_session_username($u) {
    // The access-control check runs session_start() BEFORE the FreePBX
    // bootstrap, deliberately - so at deserialization time the ampuser class
    // does not exist yet and PHP substitutes a __PHP_Incomplete_Class
    // placeholder. Reading a property off that placeholder is itself a fatal
    // error; round-tripping it now that the class is loaded rebuilds the real
    // object without disturbing the session.
    if ($u instanceof __PHP_Incomplete_Class) {
        $restored = @unserialize(@serialize($u));
        if (is_object($restored) && !($restored instanceof __PHP_Incomplete_Class)) {
            $u = $restored;
        } else {
            return 'unknown';
        }
    }
    if (is_object($u)) {
        return isset($u->username) ? (string) $u->username : get_class($u);
    }
    if (is_array($u)) {
        return isset($u['username']) ? (string) $u['username'] : 'unknown';
    }
    return is_scalar($u) ? (string) $u : 'unknown';
}

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

/**
 * Look for a handset fetching its provisioning files since a given time.
 *
 * A check-sync makes the phone re-read its config, which lands in the web
 * server access log as a request for .cfg/.boot on the provisioning vhost.
 * That is the only end-to-end confirmation available: Asterisk reports the
 * NOTIFY dispatched, never whether the phone acted on it.
 *
 * Only the tail of the log is read - these files grow without bound.
 *
 * @return array{seen: bool, at?: string, what?: string, readable: bool}
 */
function es_verify_fetch($logfile, $ip, $model, $since) {
    if ($logfile === '' || !is_readable($logfile)) {
        return ['seen' => false, 'readable' => false];
    }
    $fh = @fopen($logfile, 'rb');
    if (!$fh) {
        return ['seen' => false, 'readable' => false];
    }
    // Last 256KB is far more than a 30s window can produce.
    fseek($fh, 0, SEEK_END);
    $size = ftell($fh);
    fseek($fh, max(0, $size - 262144));
    $tail = stream_get_contents($fh);
    fclose($fh);

    $best = null;
    foreach (explode("\n", $tail) as $line) {
        if (strpos($line, $ip) === false) {
            continue;
        }
        // ... [10/Oct/2026:17:02:33 -0500] "GET /y-common.cfg HTTP/1.1" 200 8541 ...
        if (!preg_match('~\[([^\]]+)\]\s+"(?:GET|POST)\s+(\S+)[^"]*"\s+(\d{3})~', $line, $m)) {
            continue;
        }
        if ($m[3] !== '200') {
            continue;                       // 401 is the pre-auth probe, not a fetch
        }
        if (!preg_match('~\.(cfg|boot)$~i', $m[2])) {
            continue;
        }
        $ts = strtotime(str_replace('/', ' ', preg_replace('~^(\d+)/(\w+)/(\d+):~', '$1 $2 $3 ', $m[1])));
        if ($ts === false || $ts < $since) {
            continue;
        }
        // Prefer a line whose User-Agent names this model: several handsets can
        // share one public IP.
        $exact = ($model !== '' && stripos($line, $model) !== false);
        if ($best === null || $exact) {
            $best = ['at' => date('H:i:s', $ts), 'what' => $m[2], 'exact' => $exact];
            if ($exact) {
                break;
            }
        }
    }

    if ($best === null) {
        return ['seen' => false, 'readable' => true];
    }
    return ['seen' => true, 'readable' => true, 'at' => $best['at'], 'what' => $best['what']];
}

/**
 * Turn the request checkpoints into a per-phase breakdown.
 *
 * Reported to the browser and the error log so a slow click can be attributed
 * to a phase instead of guessed at. 'session' is time spent waiting for the
 * PHP session lock - PHP serialises concurrent requests on one session, so a
 * click that lands while another request holds it queues behind that request.
 */
function es_timings(array $t) {
    $ms = function ($a, $b) use ($t) {
        return (int) round((($t[$b] ?? 0) - ($t[$a] ?? 0)) * 1000);
    };
    $parts = [
        'session'   => $ms('start', 'session'),
        'bootstrap' => $ms('unlock', 'bootstrap'),
        'dispatch'  => $ms('bootstrap', 'dispatch'),
    ];
    $total = (int) round((($t['dispatch'] ?? microtime(true)) - $t['start']) * 1000);
    $out = [];
    foreach ($parts as $k => $v) { $out[] = "$k={$v}ms"; }
    return [
        'ms'     => $total,
        'timing' => implode(' ', $out) . " total={$total}ms",
    ];
}

/**
 * Minimal contact list for validating a NOTIFY target.
 *
 * Same shape es_send_notify() needs, but without the per-extension display-name
 * lookups es_build_rows() performs - a button click has no use for them.
 */
function es_build_targets($astman) {
    $contacts = es_fetch_contacts($astman);
    $counts   = es_registration_counts($contacts);
    $targets  = [];
    foreach ($contacts as $data) {
        $endpoint = (string) ($data['EndpointName'] ?? ($data['AOR'] ?? ''));
        $targets[] = [
            'uri'      => (string) ($data['URI'] ?? ''),
            'aor'      => (string) ($data['AOR'] ?? ''),
            'endpoint' => $endpoint,
            'siblings' => $counts[$endpoint] ?? 1,
            'brand'    => es_device_info((string) ($data['UserAgent'] ?? ''))['brand'],
        ];
    }
    return $targets;
}

/**
 * How many contacts each endpoint currently has registered.
 *
 * NOTIFY is sent per endpoint, so this is how many devices a single click
 * actually reaches - stated in the UI rather than left as a surprise.
 */
function es_registration_counts(array $contacts) {
    $counts = [];
    foreach ($contacts as $data) {
        $endpoint = (string) ($data['EndpointName'] ?? ($data['AOR'] ?? ''));
        $counts[$endpoint] = ($counts[$endpoint] ?? 0) + 1;
    }
    return $counts;
}

/**
 * Every PJSIP device FreePBX knows about, as extension => display name.
 *
 * This is what makes the Status column mean something: without it the page can
 * only list contacts that are currently registered, so a phone that drops off
 * silently vanishes and every row always reads "Reachable".
 *
 * getAllDevicesByType('pjsip') is used rather than PJSIPShowEndpoints because
 * Asterisk's endpoint list also contains trunks (ACI, FSL, GMIC...), and rather
 * than getAllUsers() because that includes non-device extensions such as the
 * UCP template.
 */
function es_pjsip_devices($fcore) {
    $out = [];
    $devices = $fcore->getAllDevicesByType('pjsip');
    if (!is_array($devices)) {
        return $out;
    }
    foreach ($devices as $d) {
        if (!is_array($d)) {
            continue;
        }
        $ext = (string) ($d['user'] ?? $d['id'] ?? '');
        if ($ext === '') {
            continue;
        }
        $out[$ext] = (string) ($d['description'] ?? '');
    }
    return $out;
}

/**
 * Build the normalized row set the page and the JSON endpoint both use.
 *
 * $devices exists so the unregistered-device merge can be tested without
 * unregistering a real phone; production callers leave it null.
 */
function es_build_rows($astman, $fcore, $devices = null) {
    $results = es_fetch_contacts($astman);
    $counts  = es_registration_counts($results);

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
            // Devices a NOTIFY to this extension will reach.
            'siblings'   => $counts[(string) ($data['EndpointName'] ?? $aor)] ?? 1,
            'registered' => true,
        ];
    }

    // Anything configured but not currently registered gets a row of its own,
    // so a phone that drops off is visible as down rather than just absent.
    $seen = [];
    foreach ($rows as $r) {
        $seen[$r['aor']] = true;
    }
    foreach (($devices === null ? es_pjsip_devices($fcore) : $devices) as $ext => $name) {
        // PHP turns a numeric-string array key into an int, so cast back or the
        // JSON carries 101 for these rows and "101" for every other one.
        $ext = (string) $ext;
        if (isset($seen[$ext])) {
            continue;
        }
        $rows[] = [
            'aor'        => $ext,
            'endpoint'   => $ext,
            'uri'        => '',
            'name'       => $name !== '' ? $name : es_display_name($fcore, $ext),
            'brand'      => '',
            'model'      => '',
            'firmware'   => '',
            'useragent'  => '',
            'transport'  => '',
            // Distinct from Asterisk's own "Unreachable", which means a contact
            // IS registered but is not answering qualify. This one is gone.
            'status'     => 'Unregistered',
            'rtt_ms'     => null,
            'uri_ip'     => '',
            'via_ip'     => '',
            'callid_ip'  => '',
            'expire'     => null,
            'expire_str' => '-',
            'siblings'   => 0,
            'registered' => false,
        ];
    }
    return $rows;
}

/**
 * Send a SIP NOTIFY to a single contact via the AMI PJSIPNotify action.
 *
 * Targets the contact URI, so an extension registered on several devices only
 * gets the NOTIFY on the handset whose row was clicked.
 *
 * KNOWN AND ACCEPTED: URI mode routes through pjsip.conf's
 * default_outbound_endpoint, so the request carries that identity rather than
 * the extension's. Captured from a live Yealink T44U, the two modes differ in
 * exactly one field - request-URI, To, Event and Subscription-State are
 * identical, and the phone returns 200 OK in the same second either way:
 *
 *   URI mode       From: <sip:dpma_endpoint@104.207.139.250>
 *   endpoint mode  From: <sip:103@104.207.139.250>
 *
 * AMI accepts exactly one of Endpoint/URI/Channel, so URI mode cannot be given
 * a corrected From. Endpoint mode reaches every device registered to the
 * extension; per-contact precision is worth more here.
 *
 * Measured on this box: NOTIFY dispatched at 17:02:31, the handset began
 * re-fetching its provisioning files at 17:02:33 - about 2-3s. A check-sync
 * always leaves that trail, so whether one landed is verifiable from the web
 * server access log rather than by watching the phone.
 *
 * Asterisk answers "Success: NOTIFY sent" as soon as it dispatches - including
 * for a target nothing is listening on. It confirms dispatch, never delivery.
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
            ? 'NOTIFY dispatched to extension ' . $match['aor']
              . '. The handset typically acts on it within ~10s.'
            : (string) ($resp['Message'] ?? 'Asterisk rejected the request.'),
    ];
}
