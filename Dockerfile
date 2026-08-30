FROM php:8.3-cli-alpine

# Зависимости для сборки mbstring (oniguruma) и полезные расширения
RUN apk add --no-cache oniguruma-dev curl \
    && docker-php-ext-install mbstring

# Проверка, что расширения активны
RUN php -m | grep -iE "mbstring|json" \
    && echo "Расширения готовы"