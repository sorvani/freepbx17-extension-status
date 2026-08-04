# FreePBX 17 Extension Status

FreePBX 17 (Debian) port of
[`Extension_Status`](https://github.com/sorvani/freepbx-helper-scripts/tree/master/Extension_Status)
from [sorvani/freepbx-helper-scripts](https://github.com/sorvani/freepbx-helper-scripts).

An admin-only page listing every PJSIP contact: extension, name, device
brand/model/firmware parsed out of the User-Agent, transport, status, round-trip
time, known device IPs, and registration expiry. Handsets that support it get
per-contact SIP NOTIFY buttons to reload config, reboot, or factory reset.

Tested on Debian 12 (bookworm), FreePBX 17, Asterisk 22.10.1, PHP 8.2 under
`mod_php`.

## Install

Three files, all into the same directory:

```bash
for f in extensionstatus.php extensionstatus.lib.php extensionstatus.view.php; do
  sudo install -o asterisk -g asterisk -m 644 "$f" "/var/www/html/custom/$f"
done
```

| File | Contents |
| --- | --- |
| `extensionstatus.php` | configuration, access control, request routing |
| `extensionstatus.lib.php` | AMI access, User-Agent parsing, NOTIFY dispatch |
| `extensionstatus.view.php` | markup, styling, browser code |

Then open `https://YOUR-PBX/custom/extensionstatus.php` while logged in to the
FreePBX admin GUI.

## Access control

The page checks for a logged-in FreePBX admin session and refuses everything
else with a 403 — the HTML page, the JSON refresh, and the NOTIFY endpoint
alike. That check is the access control: the page exposes every extension's
public IP, device model and firmware, and it can reboot and factory-reset
handsets.

Do **not** add `$bootstrap_settings['freepbx_auth'] = false` here. The phonebook
generators in
[freepbx17-phonebooks](https://github.com/sorvani/freepbx17-phonebooks) use it
because phones fetch them and can never hold a session. This page is the
opposite case.

## NOTIFY actions

Buttons appear on rows whose brand has an action set defined, and target the
**contact URI**, so an extension registered on both a desk phone and a softphone
only gets the NOTIFY on the handset whose row you clicked.

| Brand | Actions |
| --- | --- |
| Yealink | Reload config, Reboot, Factory reset |
| Snom, Sangoma | Reload config, Reboot |
| Polycom, Grandstream, Fanvil, Cisco, Algo, OBIHAI | Reload config |

Softphones get no buttons. Reboot and Factory reset prompt for confirmation.

The NOTIFY headers are sent inline over AMI, so **no Asterisk config file is
involved** — `sip_notify_custom.conf` does not need an entry for any of this.
Add or change actions in the `$es_notify_actions` block at the top of the file;
each entry notes the `sip_notify_*.conf` section it mirrors.

Asterisk reports success as soon as it dispatches the NOTIFY, including to a URI
nothing is listening on. A success message means dispatched, not delivered —
which is what the verification below is for.

### Verification

A check-sync makes the handset re-read its provisioning files, which lands in
the web server access log. After a button click the row shows `Verifying…` and
polls for that fetch, then reports `✓ Config fetched 17:10:08` or, after 30s,
`✕ No config fetch in 30s`. The log is only read after a click — never on page
load or auto-refresh.

This needs the web server to be able to read the access log. It is `root:adm
0640` by default and the page runs as `asterisk`:

```bash
sudo chgrp asterisk /var/log/apache2/other_vhosts_access.log
sudo chmod 640 /var/log/apache2/other_vhosts_access.log
```

and, to survive rotation, in the `postrotate` block of
`/etc/logrotate.d/apache2`:

```bash
chgrp asterisk /var/log/apache2/other_vhosts_access.log 2>/dev/null || true
chmod 640 /var/log/apache2/other_vhosts_access.log 2>/dev/null || true
```

Only that one file. Adding `asterisk` to the `adm` group would work too, but on
a stock box that grants a web-facing process read on ~92 files including
`/var/log/auth.log` — don't. Set `$es_access_log = ''` to turn verification off
instead.

### A note on timing

The NOTIFY targets the **contact URI**, so only the clicked handset is
addressed. URI mode routes via `default_outbound_endpoint`, which means the
request carries that identity rather than the extension's:

| | From header |
| --- | --- |
| URI mode (this page) | `<sip:dpma_endpoint@…>` |
| `pjsip send notify … endpoint 103` | `<sip:103@…>` |

Everything else about the two packets is identical and the phone returns
200 OK immediately either way. Endpoint mode is faster to take effect but
reaches every device registered to the extension. Measured here, a Yealink
T44U began re-fetching its config 2s after a URI-mode check-sync.

## Configuration

At the top of `extensionstatus.php`:

| Setting | Default | Meaning |
| --- | --- | --- |
| `$es_refresh_seconds` | `30` | Auto-refresh interval |
| `$es_refresh_default_on` | `true` | Whether auto-refresh starts enabled |
| `$es_showdebug` | `false` | Append a dump of the raw AMI response |
| `$es_access_log` | `/var/log/apache2/other_vhosts_access.log` | Log watched to confirm a check-sync landed; `''` disables verification |
| `$es_verify_window` | `30` | Seconds to watch before reporting failure |
| `$es_notify_actions` | see file | Per-brand NOTIFY actions |

## License

GPL-3.0, same as
[sorvani/freepbx-helper-scripts](https://github.com/sorvani/freepbx-helper-scripts).
