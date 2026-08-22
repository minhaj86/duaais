FROM wordpress:6.8.2-php8.3-apache

# The entrypoint of the official image seeds /var/www/html from /usr/src/wordpress on
# every start, so the theme and plugin are placed there rather than in /var/www/html.
COPY --chown=www-data:www-data wp-content/themes/duaais /usr/src/wordpress/wp-content/themes/duaais
COPY --chown=www-data:www-data wp-content/plugins/duaais-members /usr/src/wordpress/wp-content/plugins/duaais-members
COPY --chown=www-data:www-data wp-content/plugins/duaais-setup /usr/src/wordpress/wp-content/plugins/duaais-setup

# Members attach a DU certificate copy to their application, which the stock 2M upload limit blocks.
COPY scripts/php-uploads.ini /usr/local/etc/php/conf.d/duaais-uploads.ini

# WP-CLI so the content bootstrap can be run inside the deployed container.
ARG WP_CLI_VERSION=2.12.0
RUN set -eux; \
	curl -fsSL -o /usr/local/bin/wp-cli.phar "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"; \
	chmod 0644 /usr/local/bin/wp-cli.phar; \
	printf '%s\n' \
	'#!/bin/sh' \
	'if [ "$(id -u)" = "0" ]; then' \
	'	exec php /usr/local/bin/wp-cli.phar --allow-root "$@"' \
	'fi' \
	'exec php /usr/local/bin/wp-cli.phar "$@"' \
	> /usr/local/bin/wp; \
	chmod 0755 /usr/local/bin/wp; \
	wp --info

COPY scripts /scripts

RUN chmod 0755 /scripts/duaais-entrypoint.sh

ENTRYPOINT ["/scripts/duaais-entrypoint.sh"]
CMD ["apache2-foreground"]
