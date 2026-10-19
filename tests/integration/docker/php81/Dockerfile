FROM joomla:5.4-php8.3-apache AS joomla54

FROM php:8.1-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libicu-dev libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath exif gd intl mysqli pdo_mysql zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=joomla54 /usr/src/joomla /usr/src/joomla
COPY --from=joomla54 /entrypoint.sh /entrypoint.sh
COPY --from=joomla54 /makedb.php /makedb.php

ENV JOOMLA_VERSION=5.4.7
ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]
