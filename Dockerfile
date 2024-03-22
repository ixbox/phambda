FROM php:8-cli-alpine

WORKDIR /var/runtime

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.* /var/runtime/
RUN composer install --no-interaction --no-progress --no-suggest --no-scripts --no-cache --optimize-autoloader

COPY . /var/runtime/

ENTRYPOINT [ "/var/runtime/bootstrap" ]

CMD [ "index" ]
