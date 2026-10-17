#!/usr/bin/env bash
# Install a Zoop release zip twice and prove the dashboard, version, and stored data survive.
# Usage: verify-release-zip.sh <zip> <wordpress-path>
# WP_CLI overrides the wp binary, for example: WP_CLI="php /tmp/wp-cli.phar"
set -euo pipefail

if [[ $# -ne 2 ]]; then
	echo "usage: verify-release-zip.sh <zip> <wordpress-path>" >&2
	exit 1
fi

zip_path="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
wp_path="$2"
script_dir="$(cd "$(dirname "$0")" && pwd)"
check_file="${script_dir}/release-install-check.php"

if [[ ! -f "$zip_path" ]]; then
	echo "Zip not found: $zip_path" >&2
	exit 1
fi

version="$(php -r '
$zip = new ZipArchive();
if ($zip->open($argv[1]) !== true) { fwrite(STDERR, "Could not open the zip.\n"); exit(1); }
$names = [];
for ($i = 0; $i < $zip->numFiles; $i++) { $names[] = $zip->getNameIndex($i); }
$required = ["zoop/seo-studio.php", "zoop/vendor/autoload.php", "zoop/assets/build/dashboard.js", "zoop/assets/build/dashboard.asset.php", "zoop/assets/build/style-dashboard.css"];
foreach ($required as $name) {
	if (!in_array($name, $names, true)) { fwrite(STDERR, "Missing {$name}\n"); exit(1); }
}
foreach ($names as $name) {
	if (preg_match("#(^|/)(node_modules|tests|\\.git)(/|$)#", $name)) { fwrite(STDERR, "Unexpected {$name}\n"); exit(1); }
}
$php = $zip->getFromName("zoop/seo-studio.php");
if (!is_string($php) || !preg_match("/^ \\* Version:\\s*(\\S+)/m", $php, $match)) { fwrite(STDERR, "Could not read the plugin version.\n"); exit(1); }
echo $match[1];
' "$zip_path")"

wp_cli() {
	if [[ -n "${WP_CLI:-}" ]]; then
		# Word splitting is intentional: WP_CLI is a command prefix such as "php /tmp/wp-cli.phar".
		# shellcheck disable=SC2086
		${WP_CLI} --path="$wp_path" --allow-root "$@"
	else
		wp --path="$wp_path" --allow-root "$@"
	fi
}

run_check() {
	ZOOP_CHECK="$1" ZOOP_EXPECT_VERSION="$version" wp_cli eval-file "$check_file"
}

cleanup() {
	ZOOP_CHECK=cleanup ZOOP_EXPECT_VERSION="$version" wp_cli eval-file "$check_file" || true
}
trap cleanup EXIT

if wp_cli plugin is-active zoop >/dev/null 2>&1; then
	run_check snapshot
fi

wp_cli plugin install "$zip_path" --force --activate
run_check seed
wp_cli plugin install "$zip_path" --force --activate
run_check assert

echo "Verified zoop-${version}.zip at ${zip_path}"
