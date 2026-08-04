# FreePBX 17 Extension Status

FreePBX 17 (Debian) port of
[`Extension_Status`](https://github.com/sorvani/freepbx-helper-scripts/tree/master/Extension_Status)
from [sorvani/freepbx-helper-scripts](https://github.com/sorvani/freepbx-helper-scripts).

An admin-only page listing every PJSIP contact: extension, name, device
brand/model/firmware parsed out of the User-Agent, status, round-trip time,
known device IPs, and registration expiry.

> [!WARNING]
> **Port not started.** The two PHP files here are verbatim copies of the
> FreePBX 15/16 originals, checked in as the starting point. They have not been
> adapted or tested on 17 yet. Everything below is analysis, not a changelog.

## This one is not like the phonebook generators

[sorvani/freepbx17-phonebooks](https://github.com/sorvani/freepbx17-phonebooks)
fixed its scripts by setting `$bootstrap_settings['freepbx_auth'] = false`
before loading `/etc/freepbx.conf`. **Do not copy that blindly here.**

Those scripts are fetched by phones, which can never hold an admin session, so
opting out of the GUI auth layer costs nothing. This page is the opposite: it is
meant for a logged-in admin in a browser, and it does its own check first:

```php
session_start();
if (!$_SESSION['AMP_user']) {
    die('Not logged in! Please log in to your FreePBX dashboard before opening this page...');
}
```

That session check *is* the access control, and it guards real information —
every extension's public IP, device model, and firmware. If the flag goes in, it
must go in **after** that check, and the check has to stay. Setting the flag and
dropping the check would publish the lot to anyone who can reach the admin vhost.

Worth noting: the copy running on the Bundy 17 box already carries
`// $bootstrap_settings['freepbx_auth'] = false;` commented out just above the
session check, so this has been tried there.

## What to look at first

### The copy on the Bundy box is older than this one

`extensionstatus_header.php` is byte-identical, but the `extensionstatus.php`
deployed at `/var/www/html/custom/` on that box **predates** the version in
freepbx-helper-scripts. It is missing:

- Algo device support (the `Algo-NNNN/firmware` User-Agent branch)
- the `90`/`98` AOR prefix handling that strips the prefix before `getUser()`

and it carries local edits that are not upstream: a `case "Acrobitsxx":` that
disables Sangoma Connect push-service detection, and a commented-out
`if ($data['AOR']==103)` filter from debugging a single extension.

So the answer to "does mine need fixing, or is it fine?" is that it is running,
but it is a stale fork with debug edits, not a 17-adapted version. Port from the
files in this repo, then re-apply anything from the box that was deliberate.

### PHP 8.2 hazards, unverified

Read off the source, not yet reproduced. FreePBX 17 runs PHP 8.2 and its error
handler turns warnings into fatals, so undefined-key reads that were silent
notices on PHP 5.6/7 become 500s. Candidates:

| Line | Concern |
| --- | --- |
| `if (!$_SESSION['AMP_user'])` | Undefined array key when *not* logged in — the friendly "please log in" message may become a 500 instead. Wants `empty()`. |
| `end(explode('@', $data['CallID']))` | `end()` takes a reference; passing a function result is at minimum a notice. Same pattern on the `URI` line. |
| `$data['URI']`, `$data['ViaAddress']` | Not present for every client — Zoiper's own example in the comments shows `CallID` only. |
| `$ua_arr[1]` in `get_device_info()` | Undefined for a single-token User-Agent. `"Zulu"` is in the example list and has no space or slash. |
| `$data['RegExpire']` | Fed straight to `new DateTime("@$regexpire")`; empty or missing would throw. |

Each needs confirming against live AMI output rather than assuming.

### Also worth checking

- `PJSIPShowRegistrationInboundContactStatuses()` still exists on 17's `$astman`.
- Output is not HTML-escaped. `$user['name']` and the User-Agent are echoed raw,
  and the User-Agent is attacker-controlled by whatever registers.

## Install

No installer yet. See
[freepbx17-phonebooks](https://github.com/sorvani/freepbx17-phonebooks) for the
`install.sh` pattern to follow — `/var/www/html/custom/`, owned
`asterisk:asterisk` mode 644, backing up anything it would replace and running
`php -l` over the result.

## License

GPL-3.0, same as
[sorvani/freepbx-helper-scripts](https://github.com/sorvani/freepbx-helper-scripts).
