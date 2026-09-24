FROM dunglas/frankenphp:php8.2

# Instalar extensiones necesarias
RUN install-php-extensions \
    bcmath \
    pdo_pgsql \
    pdo_mysql \
    mbstring \
    zip

# Instalar Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY . .

RUN composer install --no-dev --optimize-autoloader --no-interaction

# ✅ Permisos para storage
RUN chmod -R 775 storage bootstrap/cache

EXPOSE 8000

# ✅ ✅ ✅ PREDEPLOY: Limpiar caché ANTES de iniciar
CMD ["sh", "-c", "php artisan config:clear && php artisan cache:clear && php artisan view:clear && php artisan config:cache && php artisan view:cache && php artisan serve --host=0.0.0.0 --port=8000"]