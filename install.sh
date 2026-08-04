#!/bin/bash
# Install the Extension Status page on a FreePBX 17 (Debian) system.
# Idempotent: safe to re-run after a git pull.

set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# /var/www/html/custom is the conventional home for hand-added scripts on
# FreePBX: it is served by the admin vhost and FreePBX itself never writes
# there, so an upgrade will not clobber it. Override with DEST=... ./install.sh
DEST="${DEST:-/var/www/html/custom}"

# NOTIFY verification needs the web server to be able to read the Apache access
# log, so this grants that: chgrp asterisk on that one file, plus a logrotate
# hook to keep it. On by default; to skip it:
#   ENABLE_VERIFY_LOG=0 sudo -E ./install.sh
ENABLE_VERIFY_LOG="${ENABLE_VERIFY_LOG:-1}"
ACCESS_LOG="${ACCESS_LOG:-/var/log/apache2/other_vhosts_access.log}"
LOGROTATE_CONF="${LOGROTATE_CONF:-/etc/logrotate.d/apache2}"

FILES=(
    extensionstatus.php
    extensionstatus.lib.php
    extensionstatus.view.php
)

die() { echo "ERROR: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "run me as root (sudo ./install.sh)"
[ -f /etc/freepbx.conf ] || die "/etc/freepbx.conf not found -- is this a FreePBX box?"
command -v apache2ctl >/dev/null \
    || die "apache2ctl not found -- this script is for the Debian-based FreePBX 17, not Sangoma OS"

# The page is served by mod_php as the Apache user, which FreePBX sets to
# asterisk. Without mod_php Apache would hand out the PHP source instead.
MODULES="$(apache2ctl -M 2>/dev/null || true)"
grep -q 'php_module' <<<"$MODULES" \
    || die "mod_php (php_module) is not loaded in Apache -- install libapache2-mod-php and a2enmod it"

id -u asterisk >/dev/null 2>&1 || die "no asterisk user found"

[ -d "$DEST" ] || { echo "==> creating $DEST"; install -d -o asterisk -g asterisk -m 755 "$DEST"; }

for f in "${FILES[@]}"; do
    [ -f "$SRC/$f" ] || die "missing $SRC/$f"

    if [ -f "$DEST/$f" ] && ! cmp -s "$SRC/$f" "$DEST/$f"; then
        BACKUP="$DEST/$f.bak.$(date +%Y%m%d%H%M%S)"
        echo "==> backing up existing $f to $(basename "$BACKUP")"
        cp -a "$DEST/$f" "$BACKUP"
    fi

    echo "==> installing $DEST/$f"
    install -o asterisk -g asterisk -m 644 "$SRC/$f" "$DEST/$f"
done

# Earlier releases shipped the table header as a separate include. The rewrite
# folded it in, so a leftover copy is dead weight.
if [ -f "$DEST/extensionstatus_header.php" ]; then
    echo "==> retiring obsolete extensionstatus_header.php"
    mv "$DEST/extensionstatus_header.php" \
       "$DEST/extensionstatus_header.php.obsolete.$(date +%Y%m%d%H%M%S)"
fi

# A syntax error would otherwise only show up as a blank page in the browser.
echo "==> php syntax check"
for f in "${FILES[@]}"; do
    php -l "$DEST/$f" >/dev/null || die "$f failed php -l"
done
echo "    all ${#FILES[@]} files parse cleanly"

if [ "$ENABLE_VERIFY_LOG" = "1" ]; then
    echo "==> enabling NOTIFY verification (access log read for the web server)"
    if [ ! -f "$ACCESS_LOG" ]; then
        echo "    WARNING: $ACCESS_LOG does not exist -- skipping"
    else
        chgrp asterisk "$ACCESS_LOG"
        chmod 640 "$ACCESS_LOG"
        echo "    $ACCESS_LOG is now $(stat -c '%U:%G %a' "$ACCESS_LOG")"

        # logrotate recreates the file as root:adm, which would silently undo
        # the above at the next rotation.
        if [ -f "$LOGROTATE_CONF" ]; then
            if grep -q 'extensionstatus.php reads this log' "$LOGROTATE_CONF"; then
                echo "    logrotate hook already present"
            else
                # Backups must NOT land in /etc/logrotate.d: logrotate reads
                # every file in that directory, so a copy of the apache2 stanza
                # sitting beside it produces "duplicate log entry" errors on
                # every run.
                BDIR=/var/backups
                [ -d "$BDIR" ] || BDIR=/root
                LRBAK="$BDIR/logrotate-$(basename "$LOGROTATE_CONF").$(date +%Y%m%d%H%M%S)"
                cp -a "$LOGROTATE_CONF" "$LRBAK"
                echo "    backed up $LOGROTATE_CONF -> $LRBAK"

                # 'log' is a gawk builtin - the variable must not be called that.
                TMP="$(mktemp)"
                awk -v logfile="$ACCESS_LOG" '
                    { lines[NR] = $0 }
                    END {
                        last = 0
                        for (i = 1; i <= NR; i++) if (lines[i] ~ /^[ \t]*endscript/) last = i
                        for (i = 1; i <= NR; i++) {
                            if (i == last) {
                                print "\t\t# extensionstatus.php reads this log to confirm a check-sync landed"
                                print "\t\tchgrp asterisk " logfile " 2>/dev/null || true"
                                print "\t\tchmod 640 " logfile " 2>/dev/null || true"
                            }
                            print lines[i]
                        }
                    }' "$LOGROTATE_CONF" > "$TMP" \
                    || { rm -f "$TMP"; die "failed to rewrite $LOGROTATE_CONF (original untouched)"; }
                [ -s "$TMP" ] || { rm -f "$TMP"; die "rewrite of $LOGROTATE_CONF produced an empty file (original untouched)"; }

                cat "$TMP" > "$LOGROTATE_CONF"
                rm -f "$TMP"
                chown root:root "$LOGROTATE_CONF"
                chmod 644 "$LOGROTATE_CONF"

                # Validate the WHOLE config, not just this file - that is what
                # cron actually runs, and it is where duplicate-entry errors show.
                if ! logrotate -d /etc/logrotate.conf 2>&1 | grep -qiE '^error:'; then
                    echo "    logrotate postrotate hook added"
                else
                    cp -a "$LRBAK" "$LOGROTATE_CONF"
                    die "logrotate reported errors -- reverted $LOGROTATE_CONF from $LRBAK"
                fi
            fi
        fi
    fi
else
    echo "==> NOTIFY verification skipped (ENABLE_VERIFY_LOG=0, no permission changes)"
    echo "    the page works fully without it; it just will not confirm that a"
    echo "    handset acted on a check-sync."
fi

cat <<EOF

Done. Open it while logged in to the FreePBX admin GUI:

  https://YOUR-PBX/custom/extensionstatus.php

The page refuses anything without an admin session. It exposes every
extension's public IP, device model and firmware, and it can reboot and
factory-reset handsets -- do not weaken that check.
EOF

if [ "$ENABLE_VERIFY_LOG" = "1" ]; then
cat <<EOF

This install made ONE permission change outside $DEST:

  $ACCESS_LOG  ->  group asterisk, mode 640

so the page can confirm a handset acted on a check-sync. Nothing else in
/var/log is affected. To install without it:

  ENABLE_VERIFY_LOG=0 sudo -E ./install.sh
EOF
fi
