# FreePBX 17 Extension Status

FreePBX 17 (Debian) port of
[`Extension_Status`](https://github.com/sorvani/freepbx-helper-scripts/tree/master/Extension_Status)
from [sorvani/freepbx-helper-scripts](https://github.com/sorvani/freepbx-helper-scripts).

An admin-only page listing every PJSIP contact: extension, name, device
brand/model/firmware parsed out of the User-Agent, status, round-trip time,
known device IPs, and registration expiry.

## Status

Port not started. `extensionstatus.php` and `extensionstatus_header.php` are
verbatim copies of the FreePBX 15/16 originals, checked in as the starting
point. Not yet adapted or tested on 17.

## Install

No installer yet. Drop both files in `/var/www/html/custom/`, owned
`asterisk:asterisk`, mode 644.

## License

GPL-3.0, same as
[sorvani/freepbx-helper-scripts](https://github.com/sorvani/freepbx-helper-scripts).
