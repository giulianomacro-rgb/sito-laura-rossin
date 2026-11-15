FROM php:8.4-apache

RUN a2enmod headers rewrite \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/site.conf /etc/apache2/conf-enabled/laura-site.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/laura-site.ini
COPY deploy/start.sh /usr/local/bin/laura-start
WORKDIR /var/www/html
COPY *.html *.css *.js robots.txt sitemap.xml ./
COPY assets/ assets/
COPY content/ content/
COPY servizi/ servizi/
COPY iniziative/ iniziative/
COPY lib/ lib/
COPY admin/index.php admin/styles.css admin/.htaccess admin/
COPY api/ api/
COPY deploy/render.php _render.php
RUN find . -type d -exec chmod 0755 {} + \
    && find . -type f -exec chmod 0644 {} + \
    && find . -name '*.php' -exec sh -ec 'for file do php -l "$file"; done' sh {} + \
    && apache2ctl configtest \
    && apache2ctl -t -D LAURA_PREVIEW \
    && sed -i 's/\r$//' /usr/local/bin/laura-start \
    && chmod 0755 /usr/local/bin/laura-start
CMD ["laura-start"]
