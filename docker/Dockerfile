# =============================================================================
# Stage 1: Builder — Composer install with patches applied
# =============================================================================
FROM php:8.3.36-cli AS builder

# Install system dependencies needed for composer install
# Must include libs for gd/gmp since composer validates platform requirements
RUN apt-get update && apt-get install -y \
    git \
    patch \
    unzip \
    zip \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    libgmp-dev \
    && docker-php-ext-configure gd --with-webp --with-jpeg --with-freetype \
    && docker-php-ext-install -j$(nproc) zip gd gmp \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy composer files and patches first (maximizes Docker layer caching)
COPY composer.json composer.lock ./
COPY patches/ patches/
# composer.json's scaffold step appends this file to robots.txt, so it must
# exist before composer install runs.
COPY html/modules/custom/social_ai_indexing/scaffold/ html/modules/custom/social_ai_indexing/scaffold/

# Install dependencies (patches applied automatically by cweagans/composer-patches)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy the rest of the codebase
COPY html/ html/

# Run post-install scripts (drupal-scaffold etc.)
RUN composer run-script post-install-cmd --no-interaction || true

# =============================================================================
# Stage 2: Runtime — Lean Apache with built artifacts
# =============================================================================
FROM php:8.3.36-apache AS runtime

# Install system dependencies for PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    libgmp-dev \
    libmagickwand-dev \
    libzip-dev \
    libicu-dev \
    mariadb-client \
    netcat-openbsd \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions needed for Drupal + SIWE
RUN docker-php-ext-configure gd --with-webp --with-jpeg --with-freetype \
    && docker-php-ext-install -j$(nproc) \
    gd \
    gmp \
    intl \
    pdo_mysql \
    opcache \
    zip

# Install imagick via PECL
RUN pecl install imagick && docker-php-ext-enable imagick

# Fail the build if an extension the site calls is missing. Open Social's
# "add content" pages call transliterator_transliterate() from intl, and no
# package declares that need, so Composer cannot catch it.
RUN php -r 'foreach (["gd", "gmp", "imagick", "intl", "pdo_mysql", "zip"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "Missing PHP extension: $e\n"); exit(1); } } if (!function_exists("transliterator_transliterate")) { fwrite(STDERR, "intl has no transliterator\n"); exit(1); }'

# Configure PHP for production
RUN { \
    echo 'memory_limit = 512M'; \
    echo 'upload_max_filesize = 64M'; \
    echo 'post_max_size = 64M'; \
    echo 'max_execution_time = 300'; \
    echo 'max_input_vars = 5000'; \
    echo '; Logged exceptions must not carry call arguments: a backtrace can hold'; \
    echo '; a member question or page text passed down the call stack.'; \
    echo 'zend.exception_ignore_args = On'; \
    } > /usr/local/etc/php/conf.d/drupal.ini

# Configure opcache for production
RUN { \
    echo 'opcache.enable=1'; \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.interned_strings_buffer=16'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Enable Apache modules
RUN a2enmod rewrite headers

# Access log without query strings or Referer (they can hold a member's words)
COPY docker/apache-access-log.sh /tmp/apache-access-log.sh
RUN bash /tmp/apache-access-log.sh && rm /tmp/apache-access-log.sh

# Configure Apache DocumentRoot (Open Social uses html/ as web root)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf

# Set AllowOverride All for the docroot
RUN { \
    echo '<Directory /var/www/html/html>'; \
    echo '    AllowOverride All'; \
    echo '    Require all granted'; \
    echo '</Directory>'; \
    } > /etc/apache2/conf-available/drupal.conf \
    && a2enconf drupal

# Copy built artifacts from builder stage
COPY --from=builder /app/vendor /var/www/html/vendor
COPY --from=builder /app/html /var/www/html/html

# Copy custom modules (project code, not installed via composer)
COPY html/modules/custom/ /var/www/html/html/modules/custom/

# NOTE: config/sync is intentionally NOT copied. This is a distribution: config
# ships in module config/install + the Open Social profile, applied on fresh
# install. We do not run config:import (see docker/entrypoint.sh), so a synced
# config directory would only be stale, misleading, and image bloat.

# Create writable directories (files dir not in source, created at build time)
#
# /var/www/html/config/sync is the configuration sync directory that
# settings.php names (../config/sync). It ships EMPTY and stays empty: it is not
# a revived config export, and nothing runs config:import (CLAUDE.md, "Config
# Management Philosophy"). It exists only because Drupal's requirements check
# reports an error on every updatedb when the directory is missing, and the
# copy site:install creates lives in the container layer, so a redeploy loses
# it. Core writes an .htaccess into it, so it must be writable by www-data.
# Test: scripts/ops/tests/config-sync-dir.test.sh
RUN mkdir -p /var/www/html/html/sites/default/files \
    && mkdir -p /var/www/private \
    && mkdir -p /var/www/html/config/sync

# Set ownership and permissions for writable directories
RUN chown -R www-data:www-data /var/www/html/html/sites/default/files \
    && chown -R www-data:www-data /var/www/private \
    && chown -R www-data:www-data /var/www/html/config/sync \
    && chmod 755 /var/www/html/html/sites/default

# Copy and configure entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/worker-loop.sh /usr/local/bin/worker-loop.sh
COPY docker/localnodes-pause /usr/local/bin/localnodes-pause
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/worker-loop.sh /usr/local/bin/localnodes-pause

# The commit this image was built from, for drush localnodes:export's
# manifest (the archive names the image a community restores with). CI passes
# it (build-image.yml); a local build without it writes no file, and the
# export falls back to git or leaves the commit empty.
ARG LOCALNODES_BUILD_COMMIT=""
RUN if [ -n "$LOCALNODES_BUILD_COMMIT" ]; then echo "$LOCALNODES_BUILD_COMMIT" > /var/www/html/BUILD_COMMIT; fi

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
