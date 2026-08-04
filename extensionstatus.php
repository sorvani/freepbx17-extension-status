<?php

// Set to true to show the URI column
$showuri = false;

// Set to true to show a var_dump of the $results array
$showdebug = false;

// Make sure we are logged in to FreePBX.
// This session check IS the access control for this page - it exposes every
// extension's public IP, device model and firmware. Do not set
// $bootstrap_settings['freepbx_auth'] = false here the way the phonebook
// generators do; those are fetched by phones that can never hold a session.
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
if (empty($_SESSION['AMP_user'])) {
  die('Not logged in! Please log in to your FreePBX dashboard before opening this page...');
}
// Load FreePBX bootstrap environment
include '/etc/freepbx.conf';
$fcore = FreePBX::Core();

// Load AMI
global $astman;

if (!is_object($astman)) {
  die('Could not connect to the Asterisk Manager Interface.');
}

$results = $astman->PJSIPShowRegistrationInboundContactStatuses();
if (!is_array($results)) { $results = []; }

include __DIR__ . '/extensionstatus_header.php';


foreach ($results as $data) {
  echo '    <tr>' . "\n";

  // The extension
  $aor = (string) ($data['AOR'] ?? '');
  echo '      <td>' . h($aor) . '</td>' . "\n";

  // Extension Display Name
  // 90/98 prefixed AORs are FreePBX's own follow-me / queue pseudo-devices;
  // strip the prefix to find the real extension.
  if (str_starts_with($aor, '90') || str_starts_with($aor, '98')) {
    $user = $fcore->getUser(substr($aor, 2));
  } else {
    $user = $fcore->getUser($aor);
  }
  // getUser() returns false when the AOR has no matching extension.
  $username = is_array($user) ? (string) ($user['name'] ?? '') : '';
  echo '         <td>' . h($username) . '</td>' . "\n";

  // The URI is the AOR that we will send commands back to eventually
  // Eventual notify syntax to replicate
  // rasterisk -c 'pjsip send notify reload-yealink uri sip:103@64.53.207.74:12682'
  // rasterisk -x 'pjsip send notify default-yealink uri sip:5120@64.53.207.74:1073;x-ast-orig-host=10.254.103.215:5060'
  if ($showuri) { echo '      <td>' . h((string) ($data['URI'] ?? '')) . '</td>' . "\n"; }

  // The user agent contains information about the device.
  // Break it into pieces as Brand/Model/Firmware
  /********** Examples
    ["UserAgent"]=> string(31) "Yealink SIP VP-T49G 51.80.0.100"
    ["UserAgent"]=> string(26) "Yealink SIP-T54W 96.85.0.5"
    ["UserAgent"]=> string(17) "Zoiper rv2.10.8.2"
    ["UserAgent"]=> string(26) "Grandstream HT802 1.0.17.5"
    ["UserAgent"]=> string(16) "snomPA1/8.7.3.19"
    ["UserAgent"]=> string(54) "LinphoneiOS/4.3.0 (Bob's iPhone) LinphoneSDK/4.4.0"
    ["UserAgent"]=> string(24) "OBIHAI/OBi202-3.2.2.5921"
    ["UserAgent"]=> string(15) "MicroSIP/3.20.5"
    ["UserAgent"]=> string(14) "Acrobits SIPIS" // Sangoma Connect push service
    ["UserAgent"]=> string(15) "Telephone 1.5.2" // macOS app "Telephone"
    ["UserAgent"]=> string(67) "Linphone Desktop/4.2.5 (macOS 10.15, Qt 5.15.2) LinphoneCore/4.4.19"
    ["UserAgent"]=> string(21) "Sangoma Connect/1.0.1"
    ["UserAgent"]=> string(4) "Zulu"
    ["UserAgent"]=> string(47) "PolycomRealPresenceTrio-Trio_8500-UA/5.9.2.7727"
    ["UserAgent"]=> string(43) "PolycomSoundPointIP-SPIP_450-UA/4.0.15.1047"
    ["UserAgent"]=> string(24) "Jitsi2.10.5550Windows 10"
    ["UserAgent"]=> string(18) "Z 5.5.5 v2.10.15.2"  //potentially Jitsi on macOS.
    ["UserAgent"]=> string(13) "Algo-8201/5.2" // Algo door intercom
  **********/
  $ret_info = get_device_info((string) ($data['UserAgent'] ?? ''));
  echo '      <td>' . h($ret_info['brand']) . '</td>' . "\n";
  echo '      <td>' . h($ret_info['model']) . '</td>' . "\n";
  echo '      <td>' . h($ret_info['firmware']) . '</td>' . "\n";

  // show the Status
  echo '      <td>' . h((string) ($data['Status'] ?? '')) . '</td>' . "\n";

  // Show RTT times in milliseconds
  $rtt = $data['RoundtripUsec'] ?? '';
  if (is_numeric($rtt)) {
    echo '	<td>' . h((string) ($rtt / 1000)) . ' ms</td>' . "\n";
  } else {
    echo '	<td>-</td>' . "\n";
  }


  // Pull out the various IP addresses known to Asterisk.
  /********* Examples
    // Yealink phones (all)
    ["CallID"] => string(28) "0_1362581122@192.168.101.161"
    ["URI"] => string(61) "sip:417@1X.2X.1X.1X:1025;x-ast-orig-host=10.1.17.130:5060"
    ["ViaAddress"] => string(17) "10.202.40.37:5060"

    // Grandstream HT802
    ["CallID"] => string(32) "1893618396-5060-2@BJC.BGI.BAC.HA"
    ["ViaAddress"] => string(18) "10.202.40.121:5062"

    // Zoiper
    ["CallID"] => string(24) "5nw8H9tLIoXbkewN-pn_1w.."

    //MicroSIP
    ["CallID"] => string(32) "341cec212e1747b692e5663b2023b123"
    ["URI"] => string(64) "sip:4272@6X.9X.2X.1X:33980;ob;x-ast-orig-host=10.0.1.131:57017"
    ["ViaAddress"] => string(16) "10.0.1.131:57017"

    //Snom PA1
    ["CallID"] => string(25) "386d43a45de1-l88ln518lzf9"
    ["ViaAddress"] => string(17) "10.202.40.33:5060"

    //LinphoneiOS
    ["CallID"] => string(10) "Ar-U8i4THj"
    ["ViaAddress"] => string(20) "10.254.103.179:65310" // on wifi
    ["ViaAddress"] => string(45) "2607:fb90:e120:b95f:c91c:ebd4:e11f:f45e:53362" // on cellular

    //OBiHAI
    ["CallID"] => string(29) "65844919897ccd8f@10.101.5.131"
    ["URI"] => string(25) "sip:416@6X.6X.2X.2X:5060"
    ["ViaAddress"] => string(17) "10.101.5.131:5060"

    // Polycom
    ["CallID"]=> string(39) "affc1078-ed6cde77-f19623f6@192.168.7.50"
    ["URI"]=> string(57) "sip:301@6X.5X.2X.2X:5060;x-ast-orig-host=192.168.7.50:0"
    ["ViaAddress"]=> string(12) "192.168.7.50"
  *********/
  // Not every client sends all three - Zoiper's example above is CallID only.
  $callid     = last_after((string) ($data['CallID'] ?? ''), '@');
  $viaaddress = first_before((string) ($data['ViaAddress'] ?? ''), ':');
  $uri        = first_before(last_after((string) ($data['URI'] ?? ''), '@'), ':');
  echo '      <td>' . "\n";
  if (!filter_var($uri, FILTER_VALIDATE_IP)) { $uri = 'Not an IP'; }
  if (!filter_var($viaaddress, FILTER_VALIDATE_IP)) { $viaaddress = 'Not an IP'; }
  if (!filter_var($callid, FILTER_VALIDATE_IP)) { $callid = 'Not an IP'; }
  echo '        <b>URI:</b> ' . h($uri) . '<br />' . "\n";
  echo '        <b>Via:</b> ' . h($viaaddress) . '<br />' . "\n";
  echo '        <b>CallID:</b> ' . h($callid) . '<br />' . "\n";
  echo '      </td>' . "\n";

  // Make RegExpire human readable
  $regexpire = $data['RegExpire'] ?? '';
  if (is_numeric($regexpire)) {
    $regexpire = new DateTime('@' . (int) $regexpire, new DateTimeZone('UTC'));
    $regexpire->setTimezone(new DateTimeZone(date_default_timezone_get()));
    echo '      <td>' . h($regexpire->format('Y/m/d H:i:s')) . '</td>' . "\n";
  } else {
    echo '      <td>-</td>' . "\n";
  }
  echo '    </tr>' . "\n";
}

// close out table and div. likely change to include eventually
echo '  </tbody>' . "\n";
echo '</table>' . "\n";
echo '</div>' . "\n";


// Escape for HTML output. The User-Agent is supplied by whatever registers,
// so it is attacker-controlled and must never be echoed raw.
function h($s) {
  return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Everything after the last $sep (the whole string if $sep is absent).
function last_after($s, $sep) {
  $parts = explode($sep, $s);
  return end($parts);
}

// Everything before the first $sep (the whole string if $sep is absent).
function first_before($s, $sep) {
  $parts = explode($sep, $s);
  return $parts[0];
}

function get_device_info($ua) {
  $ua_arr = preg_split("/[\s\/]/", $ua, 2);
  // A single-token User-Agent ("Zulu") has no second element, and a two-token
  // one ("Acrobits SIPIS") has no firmware. Both are fatal on PHP 8.2 if read
  // blind, so every index below is defaulted.
  $head = $ua_arr[0] ?? '';
  $rest = $ua_arr[1] ?? '';
  switch ($head) {
    case "Yealink":
    case "Zulu":
    case "Z":
      $mod_firm_arr = preg_split("/[\s]/", preg_replace("/^SIP[\s-]/", "", $rest));
      $device_info = ["brand" => $head, "model" => $mod_firm_arr[0] ?? '', "firmware" => $mod_firm_arr[1] ?? ''];
      break;
    case "Grandstream":
    case "OBIHAI":
    case "Fanvil":
    case "Acrobits":
    case "Cisco":
      $mod_firm_arr = preg_split("/[\s-]/", $rest);
      $device_info = ["brand" => $head, "model" => $mod_firm_arr[0] ?? '', "firmware" => $mod_firm_arr[1] ?? ''];
      break;
    case "Sangoma":
      $mod_firm_arr = preg_split("/[\/]/", $rest);
      $device_info = ["brand" => $head, "model" => $mod_firm_arr[0] ?? '', "firmware" => $mod_firm_arr[1] ?? ''];
      break;
    case "Zoiper":
    case "MicroSIP":
    case "Telephone":
      $device_info = ["brand" => $head, "model" => "", "firmware" => $rest];
      break;
    case "snomPA1":
      $device_info = ["brand" => "Snom", "model" => "PA1", "firmware" => $rest];
      break;
    case "LinphoneiOS":
      $mod_firm_arr = preg_split("/[\s]/", $rest);
      $device_info = ["brand" => $head, "model" => "", "firmware" => $mod_firm_arr[0] ?? ''];
      break;
    case "Linphone": //Linphone Desktop
      $mod_firm_arr = preg_split("/[\s\/]/", preg_replace('/\(|\)/', '', $rest));
      $device_info = ["brand" => $head . " " . ($mod_firm_arr[0] ?? ''), "model" => $mod_firm_arr[2] ?? '', "firmware" => $mod_firm_arr[1] ?? ''];
      break;
    default:
      // Messy, will look into it after more Poly devices are tested
      if (substr($head, 0, 7) == "Polycom") {
        $mod_firm_arr = preg_split("/[-]/", $head);
        $device_info = ["brand" => "Polycom", "model" => preg_replace('/_/', ' ', $mod_firm_arr[1] ?? ''), "firmware" => $rest];
      // Algo is Algo-NNNN/firmware
      } elseif (substr($head, 0, 4) == "Algo") {
        $mod_arr = preg_split("/[-]/", $head);
        $device_info = ["brand" => $mod_arr[0] ?? '', "model" => $mod_arr[1] ?? '', "firmware" => $rest];
      // Jitsi on Windows does not have a split character.
      } elseif (substr($head, 0, 5) == "Jitsi") {
        $regexp = '/(\D+)([\d\.]+)(\D+.*)/';
        // preg_match leaves $ua_jitsi empty when the User-Agent does not match.
        if (preg_match($regexp, $ua, $ua_jitsi)) {
          $device_info = ["brand" => $ua_jitsi[1] ?? '', "model" => $ua_jitsi[3] ?? '', "firmware" => $ua_jitsi[2] ?? ''];
        } else {
          $device_info = ["brand" => "Jitsi", "model" => "", "firmware" => ""];
        }
      } else {
        $device_info = ["brand" => "Unknown", "model" => "", "firmware" => ""];
      }
  }
  return $device_info;
}

if ($showdebug) {
  echo "<br />BEGIN RESULTS DUMP<br />\r\n<pre>\r\n";
  var_dump($results);
  echo "\r\n</pre><br />\r\nEND RESULTS DUMP\r\n";
}

?>
