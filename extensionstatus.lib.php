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
