#!/bin/sh
set -eu

port=${PORT:-8080}
case "$port" in
    ''|*[!0-9]*) exit 1 ;;
esac
sed -i "s/^Listen 80$/Listen $port/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:$port>/" /etc/apache2/sites-enabled/000-default.conf

unset CMS_DATA_DIR
if [ "${RAILWAY_VOLUME_MOUNT_PATH:-}" = /data ] && [ -d /data ]; then
    install -d -o www-data -g www-data -m 0750 /data
    install -d -o www-data -g www-data -m 0700 /data/private /data/backups
    install -d -o www-data -g www-data -m 0750 /data/uploads
    if [ ! -e /data/site.json ]; then
        install -o www-data -g www-data -m 0640 /var/www/html/content/site.json /data/site.json
    fi
    export CMS_DATA_DIR=/data
fi

export CMS_CONTAINER=1
if [ "${SITE_INDEXABLE:-0}" != 1 ]; then
    set -- -D LAURA_PREVIEW
else
    set --
fi
exec apache2-foreground "$@"
