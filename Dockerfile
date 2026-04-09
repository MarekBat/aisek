FROM php:8.2-apache

COPY . /var/www/html/

# Zajistit zapisovatelnost souboru chat.json pro www-data
RUN chown www-data:www-data /var/www/html/chat.json 2>/dev/null; \
    chown www-data:www-data /var/www/html/

EXPOSE 80