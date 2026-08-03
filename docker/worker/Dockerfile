# Dev worker image: runs the app's background processes (Horizon, scheduler,
# Reverb) beside the backing services. Application CODE is bind-mounted from
# the host — this image only supplies a PHP runtime with the right extensions.
#
# The production/parity image (FrankenPHP + Octane, assets baked in) is a
# separate concern; see docs/03-local-development.md §6.
FROM php:8.4-cli-alpine

# pcntl + posix are hard requirements for Horizon's process supervision.
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        pdo_pgsql \
        pgsql \
        redis \
        intl \
        bcmath \
        gd \
        zip \
        pcntl \
        sockets

WORKDIR /app

# Horizon terminates gracefully on SIGTERM: it finishes in-flight jobs rather
# than dropping them. Run it as PID 1 so the signal actually reaches it.
STOPSIGNAL SIGTERM
