# =============================================================================
# Stage 1: Builder — Composer install with patches applied
# =============================================================================
FROM php:8.3-cli AS builder

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

# Install dependencies (patches applied automatically by cweagans/composer-patches)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy the rest of the codebase
COPY html/ html/

# Run post-install scripts (drupal-scaffold etc.)
RUN composer run-script post-install-cmd --no-interaction 2>/dev/null || true

# =============================================================================
# Stage 2: Runtime — Lean Apache with built artifacts
# =============================================================================
FROM php:8.3-apache AS runtime

# Install system dependencies for PHP extensions
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    libgmp-dev \
    libmagickwand-dev \
    libzip-dev \
    mariadb-client \
    netcat-openbsd \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions needed for Drupal + SIWE
RUN docker-php-ext-configure gd --with-webp --with-jpeg --with-freetype \
    && docker-php-ext-install -j$(nproc) \
    gd \
    gmp \
    pdo_mysql \
    opcache \
    zip

# Install imagick via PECL
RUN pecl install imagick && docker-php-ext-enable imagick

# Configure PHP for production
RUN { \
    echo 'memory_limit = 512M'; \
    echo 'upload_max_filesize = 64M'; \
    echo 'post_max_size = 64M'; \
    echo 'max_execution_time = 300'; \
    echo 'max_input_vars = 5000'; \
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

# Create writable directories (files dir not in source, created at build time)
RUN mkdir -p /var/www/html/html/sites/default/files \
    && mkdir -p /var/www/private

# Set ownership and permissions for writable directories
RUN chown -R www-data:www-data /var/www/html/html/sites/default/files \
    && chown -R www-data:www-data /var/www/private \
    && chmod 755 /var/www/html/html/sites/default

# Copy and configure entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
