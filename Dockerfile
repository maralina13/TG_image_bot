# Используем официальный образ PHP с расширениями
FROM php:8.2-cli

# Установка зависимостей
RUN apt-get update && apt-get install -y \
    libjpeg-dev libpng-dev libfreetype6-dev libwebp-dev \
    libtiff-dev libmagickwand-dev imagemagick unzip git curl && \
    docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp && \
    docker-php-ext-install gd

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Копируем все файлы проекта
WORKDIR /app
COPY . .

# Установка PHP-зависимостей
RUN composer install 

CMD ["php", "-S", "0.0.0.0:8000", "index.php"]
