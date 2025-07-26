# Используем официальный образ PHP с расширениями
FROM php:8.2-cli

# Установка зависимостей
RUN apt-get update && apt-get install -y \
    git unzip libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev imagemagick \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install gd

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Копируем все файлы проекта
WORKDIR /app
COPY . .

# Установка PHP-зависимостей
RUN composer install --no-dev --optimize-autoloader

CMD ["php", "index.php"]
