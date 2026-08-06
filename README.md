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

```bash
git clone https://github.com/sorvani/freepbx17-extension-status.git
cd freepbx17-extension-status
sudo ./install.sh
```

Then open `https://YOUR-PBX/custom/extensionstatus.php` while logged in to the
FreePBX admin GUI.

> [!IMPORTANT]
> **The default install makes one permission change outside the install
> directory.** To let the page confirm that a handset acted on a check-sync, it
> gives the web server read access to the Apache access log:
>
> ```
> /var/log/apache2/other_vhosts_access.log  ->  group asterisk, mode 640
> ```
>
> That single file only — `error.log` and everything else under `/var/log` are
> untouched — plus a `postrotate` hook in `/etc/logrotate.d/apache2` so rotation
> does not undo it. The logrotate config is backed up first and validated with
> `logrotate -d`.
>
> To install without it:
>
> ```bash
> ENABLE_VERIFY_LOG=0 sudo -E ./install.sh
> ```
>
> Everything except the verification line works identically either way. See
> [Verification](#verification).

### Install details

Installs to `/var/www/html/custom/` (override with `DEST=... sudo -E ./install.sh`),
owned `asterisk:asterisk` mode 644, backing up any file it would change and
running `php -l` over the result. It refuses to run if `mod_php` is not loaded,
since Apache would otherwise serve the PHP source instead of executing it.

Three files, all in the same directory:

| File | Contents |
| --- | --- |
| `extensionstatus.php` | configuration, access control, request routing |
| `extensionstatus.lib.php` | AMI access, User-Agent parsing, NOTIFY dispatch |
| `extensionstatus.view.php` | markup, styling, browser code |

By hand instead:

```bash
for f in extensionstatus.php extensionstatus.lib.php extensionstatus.view.php; do
  sudo install -o asterisk -g asterisk -m 644 "$f" "/var/www/html/custom/$f"
done
```

`/var/www/html/custom/` is the right home for these: it is served by the admin
vhost and FreePBX never writes there, so upgrades leave it alone.

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

Buttons appear on rows whose brand has an action set defined, and by default
target the **contact URI**, so an extension registered on both a desk phone and
a softphone only gets the NOTIFY on the handset whose row you clicked. A chip in
the toolbar states which way the page is set — `NOTIFY: this handset only` or,
with `$es_notify_target = 'endpoint'`, `NOTIFY: whole extension`.

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

After a button click the row shows `Verifying…` and polls until it can confirm
what happened, or gives up after `$es_verify_window` seconds. Nothing is polled
on page load or during the auto-refresh — only after a click.

What counts as confirmation depends on the action:

| Action | What must be observed | Reported |
| --- | --- | --- |
| Reload config | an HTTP **200** on its config in the access log | `✓ Config fetched 17:10:08` |
| Reboot, Factory reset | it goes away and registers again, **and** an HTTP **200** on its config | `✓ Rebooted, config fetched 20:04:31` |

Both facts are watched independently, from the moment the button is clicked.
**No order is assumed between them** — a handset fetches its config during
boot, which is *before* it can register again, and a Yealink also fetches
before it reboots at all. So each is simply looked for, and the action is
confirmed once all of them have been seen.

Progress updates as pieces land: `Verifying…` → `Rebooting…` → `Back online,
waiting on config…` → `✓ Rebooted, config fetched`. A timeout names what was
missing rather than just failing — e.g. `✕ no config fetch in 150s`, which
usually means the handset is not provisioned from this server.

> [!IMPORTANT]
> **Verification assumes the handsets are provisioned from a server.** The
> Reload config check watches for the phone fetching its config files over
> HTTP(S); a phone that was programmed by hand through its web UI never fetches
> anything, so that check can never pass for it.
>
> Because of that, **`Reload config` buttons are hidden entirely when
> verification is off** — no provisioning server is assumed, so a config reload
> would have nothing to reload from. `Reboot` and `Factory reset` are still
> offered: they are confirmed by re-registration, which does not depend on
> provisioning at all.

#### What it needs, and why

The access log is `root:adm 0640` and the page runs as `asterisk`, so the web
server cannot read it out of the box. `install.sh` grants exactly that, and
nothing more:

| Change | Scope |
| --- | --- |
| `chgrp asterisk` + `chmod 640` on `other_vhosts_access.log` | that one file |
| `postrotate` hook in `/etc/logrotate.d/apache2` | keeps the above across rotation |

`error.log` stays `root:adm`. Nothing else under `/var/log` is touched.

Adding `asterisk` to the `adm` group would also work and is the more usual
reflex — **don't**. On a stock FreePBX 17 box that grants a web-facing process
read on ~92 files, including `/var/log/auth.log`.

By hand, if you would rather not run the installer:

```bash
sudo chgrp asterisk /var/log/apache2/other_vhosts_access.log
sudo chmod 640 /var/log/apache2/other_vhosts_access.log
```

and in the `postrotate` block of `/etc/logrotate.d/apache2`:

```bash
chgrp asterisk /var/log/apache2/other_vhosts_access.log 2>/dev/null || true
chmod 640 /var/log/apache2/other_vhosts_access.log 2>/dev/null || true
```

#### Turning it off

Verification is the only part of the page that needs anything outside the
install directory, and it is entirely optional:

```bash
ENABLE_VERIFY_LOG=0 sudo -E ./install.sh   # at install time
```

or afterwards, set `$es_access_log = ''` at the top of `extensionstatus.php`.

Either way the page notices the log is unreadable and simply omits the check —
no warning, no error line, no failed state. The NOTIFY buttons themselves do
not depend on it.

### A note on timing

By default the NOTIFY targets the **contact URI**, so only the clicked handset
is addressed. URI mode routes via `default_outbound_endpoint`, which means the
request carries that identity rather than the extension's:

| | From header |
| --- | --- |
| URI mode (the default) | `<sip:dpma_endpoint@…>` |
| endpoint mode, `pjsip send notify … endpoint 103` | `<sip:103@…>` |

Everything else about the two packets is identical and the phone returns
200 OK immediately either way. Endpoint mode is faster to take effect but
reaches every device registered to the extension. Measured here, a Yealink
T44U began re-fetching its config 2s after a URI-mode check-sync.

A box with no usable `default_outbound_endpoint` answers `Success: NOTIFY sent`
and puts nothing on the wire, so the button looks like it worked while the phone
never moves. Set `$es_notify_target = 'endpoint'` there.

## Configuration

At the top of `extensionstatus.php`:

| Setting | Default | Meaning |
| --- | --- | --- |
| `$es_refresh_seconds` | `30` | Auto-refresh interval |
| `$es_refresh_default_on` | `true` | Whether auto-refresh starts enabled |
| `$es_showdebug` | `false` | Append a dump of the raw AMI response |
| `$es_notify_target` | `'uri'` | `'uri'` reaches the clicked handset; `'endpoint'` reaches every device on the extension |
| `$es_access_log` | `/var/log/apache2/other_vhosts_access.log` | Log watched to confirm a check-sync landed; `''` disables verification |
| `$es_verify_window` | `150` | Seconds to watch before reporting failure |
| `$es_state_file` | `/var/lib/asterisk/extensionstatus-devices.json` | Remembers seen devices so one that drops off shows as Unregistered; `''` disables |
| `$es_retain_days` | `7` | Forget a remembered device unseen for this long |
| `$es_no_action_models` | see file | Models carrying a hardware brand's name that are really softphones |
| `$es_notify_actions` | see file | Per-brand NOTIFY actions |

## License

GPL-3.0, same as
[sorvani/freepbx-helper-scripts](https://github.com/sorvani/freepbx-helper-scripts).
