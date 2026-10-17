#!/usr/bin/env bash
# Build dist/zoop-<version>.zip. The WordPress server does not need Composer or npm.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

if ! command -v composer >/dev/null 2>&1; then
	echo "composer is required to build the plugin zip" >&2
	exit 1
fi
if ! command -v npm >/dev/null 2>&1; then
	echo "npm is required to build the plugin zip" >&2
	exit 1
fi
if ! command -v zip >/dev/null 2>&1; then
	echo "zip is required to build the plugin zip" >&2
	exit 1
fi

version="$(php -r 'preg_match("/^ \* Version:\s*(\S+)/m", file_get_contents("seo-studio.php"), $m) || exit(1); echo $m[1];')"
if [[ -z "$version" ]]; then
	echo "Could not read the plugin version from seo-studio.php" >&2
	exit 1
fi

if [[ ! -d node_modules ]]; then
	npm ci
fi
npm run build

stage="$(mktemp -d)"
cleanup() {
	rm -rf "$stage"
}
trap cleanup EXIT

dest="$stage/zoop"
mkdir -p "$dest/assets/build" "$dest/vendor-js/yoastseo"

cp seo-studio.php uninstall.php readme.txt "$dest/"
cp -a src "$dest/src"
cp -a languages "$dest/languages"
cp -a assets/build/. "$dest/assets/build/"
cp vendor-js/yoastseo/LICENSE "$dest/vendor-js/yoastseo/LICENSE"
cp composer.json composer.lock "$dest/"

composer install \
	--no-dev \
	--no-interaction \
	--prefer-dist \
	--optimize-autoloader \
	--no-scripts \
	--working-dir="$dest"

rm -f "$dest/composer.json" "$dest/composer.lock"

required=(
	zoop/seo-studio.php
	zoop/uninstall.php
	zoop/readme.txt
	zoop/vendor/autoload.php
	zoop/vendor/woocommerce/action-scheduler/action-scheduler.php
	zoop/assets/build/dashboard.js
	zoop/assets/build/dashboard.asset.php
	zoop/assets/build/editor.js
	zoop/assets/build/editor.asset.php
	zoop/assets/build/metabox.js
	zoop/assets/build/metabox.asset.php
	zoop/assets/build/worker.js
	zoop/assets/build/style-dashboard.css
	zoop/src/Core/Plugin.php
	zoop/languages/seo-studio.pot
	zoop/vendor-js/yoastseo/LICENSE
)

for path in "${required[@]}"; do
	if [[ ! -f "$stage/$path" ]]; then
		echo "Package is missing $path" >&2
		exit 1
	fi
done

if find "$dest" -type d \( -name node_modules -o -name tests -o -name .git \) | grep -q .; then
	echo "Package contains a development directory" >&2
	exit 1
fi

mkdir -p "$root/dist"
archive="$root/dist/zoop-${version}.zip"
rm -f "$archive"
(
	cd "$stage"
	zip -r "$archive" zoop
)

listing="$(zipinfo -1 "$archive")"
for path in "${required[@]}"; do
	if ! grep -qx "$path" <<<"$listing"; then
		echo "Zip is missing $path" >&2
		exit 1
	fi
done
if grep -E '(^|/)(node_modules|tests|\.git)(/|$)' <<<"$listing" >/dev/null; then
	echo "Zip contains development files" >&2
	exit 1
fi

echo "Built $archive"
