# Backup container (plan §12): mariadb-backup from the same MariaDB release as
# the database server, plus age (encryption), rclone (off-site copy to
# S3 / MinIO / SFTP) and jq (status files read by `php artisan backup:monitor`).
FROM mariadb:11.8

RUN apt-get update \
    && apt-get install -y --no-install-recommends age rclone jq ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY docker/backup/backup.sh /usr/local/bin/backup.sh
RUN chmod +x /usr/local/bin/backup.sh

ENTRYPOINT ["/usr/local/bin/backup.sh"]
CMD ["schedule"]

HEALTHCHECK --interval=60s --timeout=5s --retries=3 \
    CMD grep -q backup.sh /proc/1/cmdline || exit 1
