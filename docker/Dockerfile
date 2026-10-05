# Local testing only — production runs on the host's Apache/PHP-FPM or Caddy (see deploy.md).
FROM php:8.4-apache

RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
	&& rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

# Magrathea writes its own logs/cache; the bind-mounted repo isn't writable by www-data,
# so the [dev] section of magrathea.conf points at these container-local folders instead.
RUN mkdir -p /var/lib/logger/logs /var/lib/logger/cache && chown -R www-data:www-data /var/lib/logger

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/logger
EXPOSE 80
