#!/usr/bin/env bash
#
# Installs WordPress core, the WordPress PHPUnit test library and (optionally)
# Contact Form 7 so that `vendor/bin/phpunit` can run without wp-env.
#
# Usage: bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [cf7-version]
#
#   db-host      host, host:port or localhost (default: localhost)
#   wp-version   X.Y, X.Y.Z or "latest" (default: latest)
#   cf7-version  Contact Form 7 version to install, or empty to skip
#
# Directories can be overridden with WP_CORE_DIR and WP_TESTS_DIR; the
# defaults match tests/bootstrap.php. Existing directories are reused, so
# delete them to reinstall a different version.

set -euo pipefail

if [ $# -lt 3 ]; then
	echo "Usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [cf7-version]" >&2
	exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4:-localhost}
WP_VERSION=${5:-latest}
CF7_VERSION=${6:-}

TMPDIR_BASE=${TMPDIR:-/tmp}
TMPDIR_BASE=${TMPDIR_BASE%/}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress}
WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR%/}
WP_TESTS_DIR=${WP_TESTS_DIR%/}

download() {
	curl -fsSL --retry 3 --retry-delay 2 "$1" -o "$2"
}

# --- Resolve the WordPress version ------------------------------------------

if [ "$WP_VERSION" = "latest" ]; then
	WP_VERSION=$(curl -fsSL https://api.wordpress.org/core/version-check/1.7/ | php -r 'echo json_decode(stream_get_contents(STDIN))->offers[0]->current;')
	echo "Latest WordPress is $WP_VERSION"
fi

case "$WP_VERSION" in
	*.*.*) WP_DEVELOP_TAG=$WP_VERSION ;;
	*.*)   WP_DEVELOP_TAG="$WP_VERSION.0" ;;  # wordpress-develop tags X.Y releases as X.Y.0
	*)
		echo "Unrecognised WordPress version: $WP_VERSION" >&2
		exit 1
		;;
esac

# --- WordPress core ----------------------------------------------------------

if [ -d "$WP_CORE_DIR" ]; then
	echo "Reusing WordPress core in $WP_CORE_DIR"
else
	echo "Installing WordPress $WP_VERSION into $WP_CORE_DIR"
	mkdir -p "$WP_CORE_DIR"
	download "https://wordpress.org/wordpress-$WP_VERSION.tar.gz" "$TMPDIR_BASE/wordpress-$WP_VERSION.tar.gz"
	tar -xzf "$TMPDIR_BASE/wordpress-$WP_VERSION.tar.gz" --strip-components=1 -C "$WP_CORE_DIR"
	rm -f "$TMPDIR_BASE/wordpress-$WP_VERSION.tar.gz"
fi

# --- Test library ------------------------------------------------------------

if [ -d "$WP_TESTS_DIR" ]; then
	echo "Reusing test library in $WP_TESTS_DIR"
else
	echo "Installing the WordPress test library ($WP_DEVELOP_TAG) into $WP_TESTS_DIR"
	checkout="$TMPDIR_BASE/wordpress-develop-$WP_DEVELOP_TAG"
	rm -rf "$checkout"
	git -c advice.detachedHead=false clone --quiet --depth 1 --filter=blob:none --sparse --branch "$WP_DEVELOP_TAG" \
		https://github.com/WordPress/wordpress-develop.git "$checkout"
	git -C "$checkout" sparse-checkout set --cone tests/phpunit
	mkdir -p "$WP_TESTS_DIR"
	cp -R "$checkout/tests/phpunit/." "$WP_TESTS_DIR/"
	cp "$checkout/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config-sample.php"
	rm -rf "$checkout"
fi

# --- wp-tests-config.php -----------------------------------------------------

# Escape a value for a PHP single-quoted string, then for a sed replacement.
sed_escape() {
	printf '%s' "$1" | sed -e 's/\\/\\\\/g' -e "s/'/\\\\'/g" | sed -e 's/[\\&|]/\\&/g'
}

sed \
	-e "s|^define( 'ABSPATH'.*|define( 'ABSPATH', '$(sed_escape "$WP_CORE_DIR/")' );|" \
	-e "s|youremptytestdbnamehere|$(sed_escape "$DB_NAME")|" \
	-e "s|yourusernamehere|$(sed_escape "$DB_USER")|" \
	-e "s|yourpasswordhere|$(sed_escape "$DB_PASS")|" \
	-e "s|^define( 'DB_HOST'.*|define( 'DB_HOST', '$(sed_escape "$DB_HOST")' );|" \
	"$WP_TESTS_DIR/wp-tests-config-sample.php" > "$WP_TESTS_DIR/wp-tests-config.php"
echo "Wrote $WP_TESTS_DIR/wp-tests-config.php"

# --- Contact Form 7 ----------------------------------------------------------

if [ -n "$CF7_VERSION" ]; then
	plugins_dir="$WP_CORE_DIR/wp-content/plugins"
	if [ -d "$plugins_dir/contact-form-7" ]; then
		echo "Reusing Contact Form 7 in $plugins_dir/contact-form-7"
	else
		echo "Installing Contact Form 7 $CF7_VERSION"
		download "https://downloads.wordpress.org/plugin/contact-form-7.$CF7_VERSION.zip" "$TMPDIR_BASE/contact-form-7.zip"
		mkdir -p "$plugins_dir"
		unzip -q -o "$TMPDIR_BASE/contact-form-7.zip" -d "$plugins_dir"
		rm -f "$TMPDIR_BASE/contact-form-7.zip"
	fi
fi

# --- Database ----------------------------------------------------------------

db_host_only=${DB_HOST%%:*}
db_port=${DB_HOST#*:}
[ "$db_port" = "$DB_HOST" ] && db_port=""

mysql_args=(-h "$db_host_only" -u "$DB_USER")
if [ -n "$db_port" ] && [ "${db_port#/}" != "$db_port" ]; then
	mysql_args+=(-S "$db_port")  # host:/path/to/socket
elif [ -n "$db_port" ]; then
	mysql_args+=(-P "$db_port" --protocol=tcp)
fi
[ -n "$DB_PASS" ] && mysql_args+=("-p$DB_PASS")

if command -v mysqladmin > /dev/null 2>&1; then
	echo "Waiting for the database at $DB_HOST"
	for _ in $(seq 1 30); do
		if mysqladmin "${mysql_args[@]}" --silent ping > /dev/null 2>&1; then
			break
		fi
		sleep 1
	done
	mysqladmin "${mysql_args[@]}" --silent ping
	mysql "${mysql_args[@]}" -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"
else
	echo "mysqladmin not found; make sure the database $DB_NAME exists at $DB_HOST"
fi

echo "Done. Run: WP_TESTS_DIR=$WP_TESTS_DIR vendor/bin/phpunit"
