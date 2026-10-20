# Zoop

Zoop is a WordPress plugin that scores a page against a 100-point on-page rubric. The plugin header says Zoop. The PHP namespace is `SEOStudio` and the text domain is `seo-studio`.

Phase 1 is the scoring engine, storage, REST routes, and WP-CLI. The specification is in [docs/SPEC.md](docs/SPEC.md). How the points are awarded is in [docs/scoring.md](docs/scoring.md).

## Requirements

- PHP 8.1 or newer, with `mbstring`, `intl`, and `dom`
- Composer 2
- WordPress 6.4 or newer to run the plugin

The unit tests do not boot WordPress.

## Install

```bash
composer install
```

Copy this directory to `wp-content/plugins/` and activate Zoop. A source build uses:

```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
```

A zip exists only after a future tag runs the release workflow. `0.6.0-beta.1` is a prerelease candidate. Do not install `seo-studio-0.5.0.zip` as the current build, and do not expect this branch to publish a zip.

## Commands

```bash
composer test             # PHPUnit unit suite
composer test:coverage    # Line coverage for src/Scoring and src/Content
composer lint             # PHPCS (WordPress-Extra and WordPress-Docs)
composer analyse          # PHPStan level 6
npm run build             # Dashboard, editor, and the content-analysis worker
npm test                  # Jest
npm run test:e2e          # Playwright against wp-env on http://localhost:8888
```

`npm run build` writes `assets/build/`, which the admin screens enqueue. That directory is not committed.

Integration tests boot WordPress through wp-env:

```bash
npm install -g @wordpress/env
wp-env start
curl -fsSL -o phpunit-9.phar https://phar.phpunit.de/phpunit-9.6.22.phar
wp-env run tests-cli --env-cwd="wp-content/plugins/$(basename "$PWD")" php phpunit-9.phar -c phpunit.integration.xml.dist
wp-env run tests-cli --env-cwd="wp-content/plugins/$(basename "$PWD")" php phpunit-9.phar -c phpunit.queue.xml.dist
```

On a site with WP-CLI:

```bash
wp seostudio analyse <post_id> <keyphrase>
wp seostudio detect
wp seostudio openapi
```

`analyse` prints the category breakdown and stores the page and score. `detect` only reports whether another SEO plugin's version constant is defined.

## Layout

| Path | Purpose |
| --- | --- |
| `seo-studio.php` | Plugin bootstrap |
| `src/Scoring/OnPage` | 22 assessments and the scorer |
| `src/Content` | Extraction, rendering, and the fetch guard |
| `src/Storage` | Tables and repositories |
| `src/Rest` | Logged-in REST routes |
| `tests/fixtures/onpage` | The ten scoring fixtures |
| `docs/` | Specification, scoring rules, and decisions |

## Licence

GPL-3.0-or-later. See `seo-studio.php`. The readme says GPLv3 or later.
