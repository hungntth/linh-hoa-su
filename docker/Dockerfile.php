FROM php:8.2-fpm-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libcurl4-openssl-dev && docker-php-ext-install mysqli curl && rm -rf /var/lib/apt/lists/*
# config.php reads the shared-MySQL password from the container env (LHS_DB_PASSWORD); FPM clears env by default.
RUN echo 'clear_env = no' >> /usr/local/etc/php-fpm.d/www.conf
