FROM php:8.5-fpm-alpine

ARG UID=1000
ARG GID=1000
ARG LAZYGIT_VERSION=0.48.0

ENV UID=${UID}
ENV GID=${GID}
ENV HOME=/home/developer

RUN apk add --no-cache \
    git \
    vim \
    curl \
    jq \
    mc \
    tree \
    ripgrep \
    fd \
    bat \
    bind-tools \
    iproute2 \
    postgresql-client \
    redis \
    nodejs \
    npm \
    unzip \
    bash \
    $PHPIZE_DEPS \
    postgresql-dev

RUN docker-php-ext-install \
    pdo_pgsql \
    pgsql

RUN apk add --no-cache --virtual .xdebug-deps \
    $PHPIZE_DEPS \
    linux-headers \
 && pecl install xdebug \
 && docker-php-ext-enable xdebug \
 && apk del .xdebug-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN npm install -g @anthropic-ai/claude-code

RUN curl -L https://phar.phpunit.de/phpunit-11.phar -o /usr/local/bin/phpunit \
    && chmod +x /usr/local/bin/phpunit \
    && curl -L https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar -o /usr/local/bin/phpstan \
    && chmod +x /usr/local/bin/phpstan \
    && curl -L https://github.com/PHP-CS-Fixer/PHP-CS-Fixer/releases/latest/download/php-cs-fixer.phar -o /usr/local/bin/php-cs-fixer \
    && chmod +x /usr/local/bin/php-cs-fixer

RUN curl -L "https://github.com/jesseduffield/lazygit/releases/download/v${LAZYGIT_VERSION}/lazygit_${LAZYGIT_VERSION}_Linux_x86_64.tar.gz" \
    -o /tmp/lazygit.tar.gz \
    && tar -xzf /tmp/lazygit.tar.gz -C /tmp \
    && install /tmp/lazygit /usr/local/bin/lazygit \
    && rm -f /tmp/lazygit.tar.gz /tmp/lazygit

RUN addgroup -g "${GID}" developer \
    && adduser -D -s /bin/sh -u "${UID}" -G developer developer \
    && mkdir -p /home/developer /workspace \
    && chown -R developer:developer /home/developer /workspace

RUN sed -i 's/^user = .*/user = developer/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -i 's/^group = .*/group = developer/' /usr/local/etc/php-fpm.d/www.conf

COPY php.dev.ini /usr/local/etc/php/conf.d/zz-php.dev.ini
COPY opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY xdebug.ini /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

WORKDIR /workspace
USER developer

EXPOSE 9000