#!/bin/bash
# Runs once, the first time the mariadb data volume is initialised.
#
# The official image already created MARIADB_DATABASE and MARIADB_USER.
# Here we add:
#   * the test database (used by `php artisan test`)
#   * audit_archiver - the ONLY account the triggers allow to DELETE audit_logs
#   * audit_backup   - least-privilege account for mariadb-backup
set -euo pipefail

: "${MARIADB_DATABASE:?}"
: "${MARIADB_USER:?}"
: "${DB_TEST_DATABASE:=audit_testing}"
: "${DB_ARCHIVER_USERNAME:=audit_archiver}"
: "${DB_ARCHIVER_PASSWORD:?DB_ARCHIVER_PASSWORD must be set}"
: "${DB_BACKUP_USERNAME:=audit_backup}"
: "${DB_BACKUP_PASSWORD:?DB_BACKUP_PASSWORD must be set}"

mariadb --protocol=socket -uroot -p"${MARIADB_ROOT_PASSWORD}" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS \`${DB_TEST_DATABASE}\`
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    GRANT ALL PRIVILEGES ON \`${DB_TEST_DATABASE}\`.* TO '${MARIADB_USER}'@'%';

    CREATE USER IF NOT EXISTS '${DB_ARCHIVER_USERNAME}'@'%' IDENTIFIED BY '${DB_ARCHIVER_PASSWORD}';
    GRANT SELECT, DELETE ON \`${MARIADB_DATABASE}\`.* TO '${DB_ARCHIVER_USERNAME}'@'%';
    GRANT SELECT, DELETE ON \`${DB_TEST_DATABASE}\`.* TO '${DB_ARCHIVER_USERNAME}'@'%';

    CREATE USER IF NOT EXISTS '${DB_BACKUP_USERNAME}'@'%' IDENTIFIED BY '${DB_BACKUP_PASSWORD}';
    GRANT RELOAD, PROCESS, LOCK TABLES, BINLOG MONITOR ON *.* TO '${DB_BACKUP_USERNAME}'@'%';
    GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON \`${MARIADB_DATABASE}\`.* TO '${DB_BACKUP_USERNAME}'@'%';

    FLUSH PRIVILEGES;
EOSQL

echo "[init] test database, archiver and backup accounts created"
