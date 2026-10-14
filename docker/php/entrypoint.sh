#!/bin/sh
set -e

cd /var/www

# storage и bootstrap/cache должны принадлежать www-data (uid 1000),
# иначе получишь 'could not be opened in append mode' на первом же ответе
if [ "$(id -u)" = "0" ]; then
    mkdir -p storage/app/private/documents
    chown -R www-data:www-data storage bootstrap/cache
fi

exec "$@"