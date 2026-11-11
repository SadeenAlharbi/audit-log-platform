#!/bin/sh
# Entrypoint shared by app, queue-worker and scheduler.
#
# In development the source code is bind-mounted, so vendor/ may not exist on
# the very first `docker compose up -d`. Only the "app" container installs it;
# the other containers wait until it is there. In the prod image vendor/ is
# already baked in, so both branches are no-ops.
set -e

if [ "${CONTAINER_ROLE:-app}" = "app" ] && [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ missing - running composer install"
    composer install --no-interaction --prefer-dist
fi

until [ -f vendor/autoload.php ]; do
    echo "[entrypoint] waiting for vendor/autoload.php ..."
    sleep 3
done

exec "$@"
