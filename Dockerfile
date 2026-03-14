# ---- Build stage: install Composer dependencies ----
FROM composer:2 AS composer-build

WORKDIR /app

COPY composer.json composer.lock ./

# Install dependencies without dev packages, optimise autoloader
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist \
    && mkdir -p web/app/plugins web/app/themes web/app/mu-plugins

# ---- Runtime stage: PHP-FPM + Nginx ----
FROM php:8.3-fpm-alpine AS production

# Install system dependencies & PHP extensions required by WordPress
RUN apk add --no-cache \
        nginx \
        supervisor \
        curl \
        icu-libs \
        libpng \
        libjpeg-turbo \
        libwebp \
        freetype \
        ghostscript \
        imagemagick \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        icu-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        imagemagick-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j$(nproc) \
        bcmath \
        exif \
        gd \
        intl \
        mysqli \
        opcache \
        zip \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && apk del .build-deps \
    && rm -rf /tmp/*

# PHP production configuration
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# OPcache recommended settings for WordPress
RUN { \
    echo 'opcache.memory_consumption=128'; \
    echo 'opcache.interned_strings_buffer=8'; \
    echo 'opcache.max_accelerated_files=4000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.revalidate_freq=0'; \
    echo 'opcache.enable_cli=1'; \
} > "$PHP_INI_DIR/conf.d/opcache-recommended.ini"

# PHP upload & memory limits
RUN { \
    echo 'upload_max_filesize=64M'; \
    echo 'post_max_size=64M'; \
    echo 'memory_limit=256M'; \
    echo 'max_execution_time=300'; \
} > "$PHP_INI_DIR/conf.d/wordpress.ini"

# Set working directory
WORKDIR /var/www/html

# Copy Composer-installed vendors from build stage
COPY --from=composer-build /app/vendor ./vendor

# Copy Composer-installed WordPress core and app directories
COPY --from=composer-build /app/web/wp ./web/wp
COPY --from=composer-build /app/web/app/mu-plugins ./web/app/mu-plugins
COPY --from=composer-build /app/web/app/plugins ./web/app/plugins
COPY --from=composer-build /app/web/app/themes ./web/app/themes

# Copy the rest of the application
COPY . .

# Ensure the uploads directory exists and is writable
RUN mkdir -p web/app/uploads web/app/cache \
    && chown -R www-data:www-data web/app/uploads web/app/cache \
    && chmod -R 775 web/app/uploads web/app/cache

# Nginx configuration
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

# Supervisord configuration (runs both php-fpm and nginx)
COPY docker/supervisord.conf /etc/supervisord.conf

# PHP-FPM: listen on a unix socket for performance
# Overwrite zz-docker.conf entirely (loaded last, overrides docker.conf's `listen = 9000`)
RUN { \
    echo '[global]'; \
    echo 'daemonize = no'; \
    echo ''; \
    echo '[www]'; \
    echo 'listen = /var/run/php-fpm.sock'; \
    echo 'listen.owner = nginx'; \
    echo 'listen.group = nginx'; \
    echo 'listen.mode = 0660'; \
} > /usr/local/etc/php-fpm.d/zz-docker.conf

# Create nginx user/group alignment (Alpine nginx runs as nginx user)
RUN addgroup www-data nginx 2>/dev/null || true

EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsL -o /dev/null http://localhost/ || exit 1

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
