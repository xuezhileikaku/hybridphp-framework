# Multi-stage build for HybridPHP Framework
FROM php:8.1-cli-alpine AS base

# Install system dependencies and PHP extensions in one layer
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    oniguruma-dev \
    libxml2-dev \
    zip \
    unzip \
    autoconf \
    g++ \
    make \
    linux-headers \
    supervisor \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/cache/apk/* /tmp/* /var/tmp/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files first for better caching
COPY composer.json composer.lock ./

# Dependencies stage - separate for better caching
FROM base AS dependencies

# Install all dependencies (including dev for building)
RUN composer install --optimize-autoloader --no-scripts \
    && composer clear-cache

# Production stage
FROM dependencies AS production

# Copy only production dependencies
RUN composer install --no-dev --optimize-autoloader --no-scripts \
    && composer clear-cache

# Copy application code (excluding dev files)
COPY --chown=1000:1000 . .
COPY --chown=1000:1000 docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create required directories and set permissions
RUN mkdir -p storage/logs storage/cache storage/sessions \
    && chmod -R 755 storage \
    && chmod +x bootstrap.php

# Create non-root user
RUN addgroup -g 1000 hybridphp && \
    adduser -u 1000 -G hybridphp -s /bin/sh -D hybridphp

# Switch to non-root user
USER hybridphp

# Expose ports
EXPOSE 8080 2346

# Health check with better error handling
HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -f http://localhost:8080/api/v1/health || exit 1

# Use supervisor for process management
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# Development stage
FROM dependencies AS development

# Keep all dependencies (including dev)
# Copy application code
COPY --chown=1000:1000 . .

# Create required directories
RUN mkdir -p storage/logs storage/cache storage/sessions \
    && chmod -R 777 storage \
    && chmod +x bootstrap.php

# Create non-root user
RUN addgroup -g 1000 hybridphp && \
    adduser -u 1000 -G hybridphp -s /bin/sh -D hybridphp

# Switch to non-root user
USER hybridphp

# Expose ports
EXPOSE 8080 2346

# Development mode with hot reload
CMD ["php", "bootstrap.php", "--env=development"]