#!/bin/bash
# Database backup runner for the "backup" container (plan §12, decision D5).
#
#   backup.sh full          full physical backup (mariadb-backup)
#   backup.sh incremental   incremental on top of the latest full/incremental
#   backup.sh binlogs       copy closed binary logs (point-in-time recovery)
#   backup.sh restore-test  restore the latest chain into a temporary server and verify it
#   backup.sh offsite-drill download the latest full backup from the off-site copy,
#                           decrypt it with the private key (AGE_IDENTITY_FILE,
#                           mounted only for the drill) and verify it the same way
#   backup.sh schedule      loop: requests from the app every minute, full daily,
#                           incremental + binlogs hourly, restore test weekly
#
# Every artifact is compressed, encrypted with age (when AGE_RECIPIENT is
# set), check-summed (SHA-256) and copied off-site with rclone (when
# RCLONE_REMOTE is set). The result of every run is written as JSON to
# $SPOOL/status/, where `php artisan backup:monitor` imports it into
# backup_runs and raises alerts. The app asks for manual runs by dropping
# files in $SPOOL/requests/.
set -euo pipefail

BACKUP_ROOT="${BACKUP_ROOT:-/backups}"
SPOOL="${SPOOL:-/spool}"
DATADIR="${DATADIR:-/var/lib/mysql}"
DB_HOST="${DB_HOST:-mariadb}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${DB_NAME:-audit_log}"
FULL_BACKUP_HOUR="${FULL_BACKUP_HOUR:-2}"
RESTORE_TEST_WEEKDAY="${RESTORE_TEST_WEEKDAY:-5}"   # 1=Mon ... 7=Sun (date +%u)
RESTORE_TEST_HOUR="${RESTORE_TEST_HOUR:-3}"
LOCAL_KEEP_DAYS="${LOCAL_KEEP_DAYS:-3}"
AGE_RECIPIENT="${AGE_RECIPIENT:-}"
AGE_IDENTITY_FILE="${AGE_IDENTITY_FILE:-}"           # only needed to restore encrypted artifacts
RCLONE_REMOTE="${RCLONE_REMOTE:-}"                   # e.g. offsite:audit-backups
MARIADBD="${MARIADBD:-mariadbd}"
MARIADBD_EXTRA_ARGS="${MARIADBD_EXTRA_ARGS:-}"
: "${DB_BACKUP_USERNAME:?}"
: "${DB_BACKUP_PASSWORD:?}"

mkdir -p "$BACKUP_ROOT/full" "$BACKUP_ROOT/incremental" "$BACKUP_ROOT/binlogs" "$BACKUP_ROOT/artifacts" \
         "$SPOOL/status" "$SPOOL/requests" "$SPOOL/reports"
# The app containers run as www-data (uid 33) and write requests and the
# "processed" markers into the spool: hand them the folders.
chown -R 33:33 "$SPOOL/status" "$SPOOL/requests" "$SPOOL/reports" 2>/dev/null || chmod 1777 "$SPOOL/status" "$SPOOL/requests" "$SPOOL/reports"

log() { echo "[backup $(date -u +%FT%TZ)] $*" >&2; }

# The off-site remote is defined by RCLONE_CONFIG_OFFSITE_* variables; an
# empty config file keeps rclone from warning that none exists.
export RCLONE_CONFIG="${RCLONE_CONFIG:-/tmp/rclone.conf}"
[ -e "$RCLONE_CONFIG" ] || : > "$RCLONE_CONFIG"
now_iso() { date -u +%FT%TZ; }
stamp() { date -u +%Y%m%dT%H%M%SZ; }

conn=(--host="$DB_HOST" --port="$DB_PORT" --user="$DB_BACKUP_USERNAME" --password="$DB_BACKUP_PASSWORD")

json_escape() { jq -Rs 'rtrimstr("\n")'; }

# write_status <type> <status> <started_at> <size> <location> <checksum> <error> <meta-json>
write_status() {
    local id; id="$(stamp)-$1-$RANDOM"
    local tmp="$SPOOL/status/.$id.tmp"
    local err_json="null"
    [ -n "$7" ] && err_json="$(printf '%s' "$7" | json_escape)"
    cat > "$tmp" <<JSON
{"id":"$id","type":"$1","status":"$2","started_at":"$3","finished_at":"$(now_iso)","size":${4:-null},"location":$( [ -n "$5" ] && printf '"%s"' "$5" || echo null ),"checksum":$( [ -n "$6" ] && printf '"%s"' "$6" || echo null ),"error":$err_json,"requested_by":${REQUESTED_BY:-null},"meta":${8:-{\}}}
JSON
    mv "$tmp" "$SPOOL/status/$id.json"
}

# ship <source-dir-or-file> <remote-subdir> <name> -> echoes "size location checksum"
#
# Every step is checked explicitly: ship() runs inside `if ! x="$(ship ...)"`,
# where bash ignores `set -e`, so a silent failure would otherwise be recorded
# as a successful backup. On an upload failure the encrypted artifact is kept
# locally (and the run reported as failed) so nothing is lost.
ship() {
    local src="$1" sub="$2" name="$3" out
    if [ -d "$src" ]; then
        out="$BACKUP_ROOT/artifacts/$name.tar.gz"
        tar -C "$src" -czf "$out" . || { log "ERROR: tar failed for $src"; rm -f "$out"; return 1; }
    else
        out="$BACKUP_ROOT/artifacts/$name.gz"
        gzip -c "$src" > "$out" || { log "ERROR: gzip failed for $src"; rm -f "$out"; return 1; }
    fi
    if [ -z "$AGE_RECIPIENT" ] && [ "${ALLOW_UNENCRYPTED_BACKUPS:-false}" != "true" ]; then
        rm -f "$out"
        log "ERROR: AGE_RECIPIENT is not set - refusing to keep an unencrypted backup (set ALLOW_UNENCRYPTED_BACKUPS=true only for throwaway tests)"
        return 1
    fi
    if [ -n "$AGE_RECIPIENT" ]; then
        age -r "$AGE_RECIPIENT" -o "$out.age" "$out" || { log "ERROR: age encryption failed"; rm -f "$out" "$out.age"; return 1; }
        rm -f "$out"
        out="$out.age"
    fi
    local sum size location remote
    sum="$(sha256sum "$out" | awk '{print $1}')" || return 1
    size="$(stat -c %s "$out")" || return 1
    location="local:$out"
    if [ -n "$RCLONE_REMOTE" ]; then
        remote="$RCLONE_REMOTE/db/$sub/$(basename "$out")"
        if ! rclone copyto --s3-no-check-bucket "$out" "$remote"; then
            log "ERROR: upload to $remote failed - the encrypted artifact is kept at $out"
            return 1
        fi
        # Byte-for-byte verification of the off-site copy: download it back
        # and compare its SHA-256 with the local artifact's. (`rclone check`
        # compares directories and fails on single files.)
        local remote_sum
        remote_sum="$(rclone cat "$remote" 2>/dev/null | sha256sum | awk '{print $1}')"
        if [ "$remote_sum" != "$sum" ]; then
            log "ERROR: off-site copy $remote does not match the local artifact (sha256 ${remote_sum:-unreadable}) - kept at $out"
            return 1
        fi
        location="$remote"
        rm -f "$out"   # verified off-site; the local backup directory is kept for incrementals
    fi
    echo "$size $location $sum"
}

max_audit_id() {
    mariadb "${conn[@]}" -N -e "SELECT COALESCE(MAX(id),0) FROM \`$DB_NAME\`.audit_logs" 2>/dev/null || echo 0
}

latest_dir() {
    # Newest local backup directory of the current chain.
    ls -1d "$BACKUP_ROOT"/full/* "$BACKUP_ROOT"/incremental/* 2>/dev/null | awk -F/ '{print $NF" "$0}' | sort | tail -n1 | cut -d' ' -f2- || true
}

latest_full() { ls -1d "$BACKUP_ROOT"/full/* 2>/dev/null | sort | tail -n1 || true; }

run_backup() {
    local kind="$1" started target base="" ts
    started="$(now_iso)"; ts="$(stamp)"
    local max_id; max_id="$(max_audit_id)"

    if [ "$kind" = "incremental" ]; then
        base="$(latest_dir)"
        if [ -z "$base" ]; then
            log "no full backup yet - taking a full backup instead"
            kind=full
        fi
    fi

    target="$BACKUP_ROOT/$kind/$ts"
    log "$kind backup -> $target"
    local args=(--backup "${conn[@]}" --datadir="$DATADIR" --target-dir="$target")
    [ "$kind" = "incremental" ] && args+=(--incremental-basedir="$base")

    if ! mariadb-backup "${args[@]}" > "$BACKUP_ROOT/last-$kind.log" 2>&1; then
        local err; err="$(tail -n 5 "$BACKUP_ROOT/last-$kind.log")"
        rm -rf "$target"
        write_status "$kind" failed "$started" "" "" "" "$err" "{}"
        log "ERROR: $kind backup failed"
        return 1
    fi

    printf '{"max_audit_id":%s,"base":"%s","taken_at":"%s"}\n' "$max_id" "$(basename "${base:-}")" "$started" > "$target/audit-meta.json"

    local shipped size location sum
    if ! shipped="$(ship "$target" "$kind" "$kind-$ts")"; then
        write_status "$kind" failed "$started" "" "" "" "upload/encryption failed" "{}"
        return 1
    fi
    read -r size location sum <<< "$shipped"
    write_status "$kind" succeeded "$started" "$size" "$location" "$sum" "" \
        "{\"max_audit_id\":$max_id,\"encrypted\":$( [ -n "$AGE_RECIPIENT" ] && echo true || echo false ),\"base\":\"$(basename "${base:-}")\"}"
    log "$kind backup done ($size bytes, sha256 $sum)"
    cleanup_local
}

run_binlogs() {
    local started; started="$(now_iso)"
    mariadb "${conn[@]}" -e "FLUSH BINARY LOGS" >/dev/null 2>&1 || log "warning: FLUSH BINARY LOGS failed"
    local current; current="$(mariadb "${conn[@]}" -N -e "SHOW MASTER STATUS" 2>/dev/null | awk '{print $1}')"
    local copied=0 total=0 failed=0
    for f in "$DATADIR"/mariadb-bin.[0-9][0-9][0-9][0-9][0-9][0-9]; do  # not the .idx GTID index files
        [ -e "$f" ] || continue
        local name; name="$(basename "$f")"
        [ "$name" = "$current" ] && continue
        # The shipped list outlives the local copies (cleanup removes those
        # after LOCAL_KEEP_DAYS while the server may keep the file longer).
        grep -qxF "$name" "$BACKUP_ROOT/binlogs/.shipped" 2>/dev/null && continue
        [ -e "$BACKUP_ROOT/binlogs/$name" ] && continue
        cp -p "$f" "$BACKUP_ROOT/binlogs/$name" || { failed=$((failed + 1)); continue; }
        local shipped size
        if ! shipped="$(ship "$BACKUP_ROOT/binlogs/$name" binlog "binlog-$(stamp)-$name")"; then
            # Forget the local copy so the next run retries this file.
            rm -f "$BACKUP_ROOT/binlogs/$name"
            failed=$((failed + 1))
            continue
        fi
        echo "$name" >> "$BACKUP_ROOT/binlogs/.shipped"
        size="$(awk '{print $1}' <<< "$shipped")"
        total=$((total + size)); copied=$((copied + 1))
    done
    local meta="{\"files\":$copied,\"failed\":$failed,\"current\":\"${current:-}\"}"
    if [ "$failed" -gt 0 ]; then
        write_status binlog failed "$started" "$total" "" "" "$failed binary log file(s) could not be copied off-site" "$meta"
        log "ERROR: binlogs copied: $copied, failed: $failed"
        return 1
    fi
    write_status binlog succeeded "$started" "$total" "${RCLONE_REMOTE:+$RCLONE_REMOTE/db/binlog/}" "" "" "$meta"
    log "binlogs copied: $copied (current: ${current:-unknown})"
}

cleanup_local() {
    # Keep local directories for LOCAL_KEEP_DAYS, but never the newest full
    # and everything after it (the current incremental chain).
    local keep_from; keep_from="$(basename "$(latest_full)")"
    find "$BACKUP_ROOT/full" "$BACKUP_ROOT/incremental" -mindepth 1 -maxdepth 1 -type d -mtime +"$LOCAL_KEEP_DAYS" | while read -r d; do
        [[ "$(basename "$d")" < "$keep_from" ]] && rm -rf "$d"
    done
    find "$BACKUP_ROOT/binlogs" "$BACKUP_ROOT/artifacts" -type f -mtime +"$LOCAL_KEEP_DAYS" -delete 2>/dev/null || true
}

# run_restore_test [<prepared-source-dir> <label>]
# Without arguments: the latest local chain (full + incrementals).
run_restore_test() {
    local started; started="$(now_iso)"
    local t0; t0="$(date +%s)"
    local source_label="${2:-local chain}"
    local full; full="${1:-$(latest_full)}"
    if [ -z "$full" ]; then
        write_status restore_test failed "$started" "" "" "" "no full backup to restore" "{}"
        return 1
    fi

    local work; work="$(mktemp -d /tmp/restore-test.XXXXXX)"
    local sock="$work/mysqld.sock" report="$SPOOL/reports/restore-test-$(stamp).md"
    trap 'mariadb-admin --socket="'"$work"'/mysqld.sock" -uroot shutdown >/dev/null 2>&1 || true; rm -rf "'"$work"'"; trap - RETURN' RETURN

    local chain=("$full")
    if [ -z "${1:-}" ]; then
        while read -r inc; do chain+=("$inc"); done < <(ls -1d "$BACKUP_ROOT"/incremental/* 2>/dev/null | sort | awk -v f="$(basename "$full")" -F/ '$NF > f')
    fi

    log "restore test: ${#chain[@]} backup(s) from $(basename "$full")"
    # Work on copies only: "--prepare --incremental-dir" consumes (moves) the
    # .delta files of the incremental directory, which would destroy the
    # local backup chain.
    cp -a "$full" "$work/data"
    if ! mariadb-backup --prepare --target-dir="$work/data" > "$work/prepare.log" 2>&1; then
        write_status restore_test failed "$started" "" "" "" "prepare failed: $(tail -n 3 "$work/prepare.log")" "{}"
        return 1
    fi
    local n=0
    for inc in "${chain[@]:1}"; do
        n=$((n + 1))
        cp -a "$inc" "$work/inc-$n"
        if ! mariadb-backup --prepare --target-dir="$work/data" --incremental-dir="$work/inc-$n" >> "$work/prepare.log" 2>&1; then
            write_status restore_test failed "$started" "" "" "" "incremental prepare failed for $(basename "$inc")" "{}"
            return 1
        fi
        rm -rf "$work/inc-$n"
    done

    # shellcheck disable=SC2086
    "$MARIADBD" --no-defaults --datadir="$work/data" --socket="$sock" --skip-networking --skip-grant-tables \
        --user=root --innodb-buffer-pool-size=128M --log-error="$work/server.log" $MARIADBD_EXTRA_ARGS &
    for _ in $(seq 1 60); do mariadb --socket="$sock" -uroot -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done

    local q=(mariadb --socket="$sock" -uroot -N "$DB_NAME")
    if ! "${q[@]}" -e 'SELECT 1' >/dev/null 2>&1; then
        write_status restore_test failed "$started" "" "" "" "restored server did not start: $(tail -n 3 "$work/server.log")" "{}"
        return 1
    fi

    local names tables rows max_id expected partitions
    names="$("${q[@]}" -e "SELECT GROUP_CONCAT(TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NAME'" 2>/dev/null || true)"
    tables="$(tr ',' ' ' <<< "$names")"
    local failed_checks=""
    for t in $tables; do
        local res; res="$("${q[@]}" -e "CHECK TABLE \`$t\`" 2>/dev/null | awk '{print $NF}' | tail -n1 || true)"
        [ -n "$res" ] || res="unreadable"
        [ "$res" = "OK" ] || failed_checks="$failed_checks $t($res)"
    done
    # A broken restore must produce a "failed" report, not stop the script.
    rows="$("${q[@]}" -e 'SELECT COUNT(*) FROM audit_logs' 2>"$work/query.err" || echo -1)"
    max_id="$("${q[@]}" -e 'SELECT COALESCE(MAX(id),0) FROM audit_logs' 2>>"$work/query.err" || echo -1)"
    partitions="$("${q[@]}" -e "SELECT COUNT(*) FROM information_schema.PARTITIONS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='audit_logs'" 2>>"$work/query.err" || echo -1)"
    expected="$(jq -r '.max_audit_id' "${chain[-1]}/audit-meta.json")"
    # Row count (D13): every row up to the recorded max id that still exists
    # in the live database must be in the restore. Retention may have deleted
    # rows since, which only lowers the live count.
    restored_upto="$("${q[@]}" -e "SELECT COUNT(*) FROM audit_logs WHERE id <= $expected" 2>>"$work/query.err" || echo -1)"
    live_upto="$(mariadb "${conn[@]}" -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.audit_logs WHERE id <= $expected" 2>/dev/null || echo -1)"
    local seconds=$(( $(date +%s) - t0 ))

    local status=succeeded error=""
    [ -n "$failed_checks" ] && { status=failed; error="CHECK TABLE failed:$failed_checks"; }
    [ "$rows" -lt 0 ] && { status=failed; error="${error:+$error; }audit_logs unreadable: $(head -c 300 "$work/query.err")"; }
    [ "$max_id" -lt "$expected" ] && { status=failed; error="${error:+$error; }restored max(id) $max_id < $expected recorded at backup time"; }
    if [ "$live_upto" -ge 0 ] && [ "$restored_upto" -lt "$live_upto" ]; then
        status=failed; error="${error:+$error; }restored $restored_upto rows with id <= $expected, live database has $live_upto"
    fi

    cat > "$report" <<MD
# Restore test report

| | |
|---|---|
| Date (UTC) | $started |
| Source | $source_label |
| Result | **$status** |
| Restore chain | $(for c in "${chain[@]}"; do printf '%s ' "$(basename "$c")"; done) |
| Restore time (RTO evidence) | ${seconds}s |
| Tables checked (CHECK TABLE) | $(wc -w <<< "$tables") — failures: ${failed_checks:-none} |
| audit_logs rows | $rows |
| audit_logs max(id) | $max_id (expected ≥ $expected) |
| Rows with id ≤ $expected | restored $restored_upto, live $live_upto (restored must be ≥ live) |
| audit_logs partitions | $partitions |

${error:+**Error:** $error}
MD

    write_status restore_test "$status" "$started" "" "local:$report" "" "$error" \
        "{\"source\":\"$source_label\",\"rows\":$rows,\"rows_upto\":$restored_upto,\"live_rows_upto\":$live_upto,\"max_id\":$max_id,\"expected_min_id\":$expected,\"tables\":$(wc -w <<< "$tables"),\"chain\":${#chain[@]},\"seconds\":$seconds}"
    log "restore test $status in ${seconds}s (rows=$rows)"
    [ "$status" = succeeded ]
}

# Quarterly drill (D13): proves the off-site copy and the private key
# actually restore, not only the local chain the weekly test uses.
run_offsite_drill() {
    local started; started="$(now_iso)"
    if [ -z "$RCLONE_REMOTE" ]; then
        write_status restore_test failed "$started" "" "" "" "off-site drill: BACKUP_RCLONE_REMOTE is not set" "{}"
        log "ERROR: BACKUP_RCLONE_REMOTE is not set"; return 1
    fi
    if [ -z "$AGE_IDENTITY_FILE" ] || [ ! -r "$AGE_IDENTITY_FILE" ]; then
        write_status restore_test failed "$started" "" "" "" "off-site drill: the private key (AGE_IDENTITY_FILE) is not mounted" "{}"
        log "ERROR: mount the private age key and set AGE_IDENTITY_FILE"; return 1
    fi

    local name; name="$(rclone lsf "$RCLONE_REMOTE/db/full/" 2>/dev/null | sort | tail -n1 || true)"
    if [ -z "$name" ]; then
        write_status restore_test failed "$started" "" "" "" "off-site drill: no full backup in $RCLONE_REMOTE/db/full/" "{}"
        log "ERROR: no full backup off-site yet"; return 1
    fi

    local drill; drill="$(mktemp -d /tmp/offsite-drill.XXXXXX)"
    log "off-site drill: downloading $RCLONE_REMOTE/db/full/$name"
    local ok=true
    rclone copyto "$RCLONE_REMOTE/db/full/$name" "$drill/$name" || ok=false
    if $ok && [[ "$name" == *.age ]]; then
        age -d -i "$AGE_IDENTITY_FILE" -o "$drill/full.tar.gz" "$drill/$name" || ok=false
    elif $ok; then
        mv "$drill/$name" "$drill/full.tar.gz"
    fi
    mkdir -p "$drill/full"
    $ok && { tar -xzf "$drill/full.tar.gz" -C "$drill/full" || ok=false; }
    if ! $ok; then
        write_status restore_test failed "$started" "" "" "" "off-site drill: download, decryption or extraction of $name failed" "{}"
        rm -rf "$drill"; log "ERROR: off-site drill failed before the restore"; return 1
    fi

    local rc=0
    run_restore_test "$drill/full" "off-site copy $name (downloaded, decrypted with the private key)" || rc=$?
    rm -rf "$drill"
    return $rc
}

process_requests() {
    for req in $(ls -1 "$SPOOL"/requests/*.req 2>/dev/null | sort); do
        local type; type="$(basename "$req" | cut -d- -f1)"
        [ "$type" = "restore" ] && type="restore-test"
        REQUESTED_BY="$(sed -nE 's/.*"requested_by":([0-9]+).*/\1/p' "$req")"
        export REQUESTED_BY
        rm -f "$req"
        log "request: $type"
        case "$type" in
            full) run_backup full || true ;;
            incremental) run_backup incremental || true ;;
            restore-test) run_restore_test || true ;;
        esac
        unset REQUESTED_BY
    done
}

do_schedule() {
    log "scheduler started (full daily at ${FULL_BACKUP_HOUR}:00 UTC, incremental + binlogs hourly, restore test weekly)"
    # Slots run on the first loop after the hour changes, so a long full
    # backup delays the next slot instead of skipping it. The weekly restore
    # test catches up later that day if its hour was taken by a long backup.
    local last_hour; last_hour="$(date -u +%Y%m%d%H)"
    local last_restore_week=""
    while true; do
        process_requests
        local hour; hour="$(date -u +%Y%m%d%H)"
        if [ "$hour" != "$last_hour" ]; then
            last_hour="$hour"
            if [ "$(date -u +%-H)" -eq "$FULL_BACKUP_HOUR" ]; then run_backup full || true; else run_backup incremental || true; fi
            run_binlogs || true
            local week; week="$(date -u +%G%V)"
            if [ "$(date -u +%u)" -eq "$RESTORE_TEST_WEEKDAY" ] && [ "$(date -u +%-H)" -ge "$RESTORE_TEST_HOUR" ] && [ "$week" != "$last_restore_week" ]; then
                last_restore_week="$week"
                run_restore_test || true
            fi
        fi
        sleep $(( 60 - $(date -u +%-S) ))
    done
}

case "${1:-schedule}" in
    full) run_backup full ;;
    incremental) run_backup incremental ;;
    binlogs) run_binlogs ;;
    restore-test) run_restore_test ;;
    offsite-drill) run_offsite_drill ;;
    schedule) do_schedule ;;
    *) echo "usage: $0 {full|incremental|binlogs|restore-test|offsite-drill|schedule}" >&2; exit 2 ;;
esac
