#!/usr/bin/env bash
#
# Runs the integration tests against a WordPress installation with the
# plugin configured for a real S3 server (e.g. MinIO).
#
#   WP="wp --path=/var/www/site" tests/integration/run.sh [test-file...]
#
# Environment:
#   WP              WP-CLI command (default: wp)
#   BMS3O_TEST_URL  Base URL for HTTP requests (default: home_url())
set -u

DIR="$(cd "$(dirname "$0")" && pwd)"
WP="${WP:-wp}"
export BMS3O_TEST_DIR="$DIR"

if [ "$#" -gt 0 ]; then
	tests=("$@")
else
	tests=("$DIR"/[0-9]*.php)
fi

failed=0
for test in "${tests[@]}"; do
	echo "=== $(basename "$test")"
	# shellcheck disable=SC2086 # WP may contain arguments.
	if ! $WP eval-file "$test"; then
		failed=1
		echo "=== FAILED: $(basename "$test")"
	fi
done

exit "$failed"
