#!/bin/sh
# Entrypoint used by the Azure image. It hands over to the official WordPress entrypoint and,
# when DUAAIS_BOOTSTRAP=1, runs the idempotent content bootstrap in the background. Keep the
# container app at a single replica so only one container bootstraps at a time.
set -eu

if [ "${DUAAIS_BOOTSTRAP:-0}" = "1" ]; then
	(
		# Wait for the official entrypoint to finish seeding the webroot.
		waited=0
		while [ "$waited" -lt 180 ]; do
			if [ -e /var/www/html/wp-includes/version.php ] && [ -e /var/www/html/wp-config.php ]; then
				break
			fi
			waited=$((waited + 2))
			sleep 2
		done

		# bootstrap.sh is idempotent, so retrying covers a database that is not reachable yet.
		attempt=1
		while [ "$attempt" -le 30 ]; do
			if sh /scripts/bootstrap.sh; then
				echo "duaais: bootstrap completed"
				exit 0
			fi
			echo "duaais: bootstrap attempt $attempt failed, retrying" >&2
			attempt=$((attempt + 1))
			sleep 10
		done

		echo "duaais: bootstrap did not succeed, run scripts/bootstrap.sh manually" >&2
	) &
fi

exec /usr/local/bin/docker-entrypoint.sh "$@"
