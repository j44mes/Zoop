# Zoop

Zoop is a WordPress plugin that scores a page against a 100-point on-page rubric. The plugin header says Zoop. The PHP namespace is `SEOStudio` and the text domain is `seo-studio`.

Phase 1 is the scoring engine, storage, REST routes, and WP-CLI. The specification is in [docs/SPEC.md](docs/SPEC.md). How the points are awarded is in [docs/scoring.md](docs/scoring.md).

## Requirements

- PHP 8.1 or newer, with `mbstring`, `intl`, and `dom`
- Composer 2
- WordPress 6.4 or newer to run the plugin

The unit tests do not boot WordPress.

## Install

The file to upload in WordPress is `zoop-<version>.zip` attached to a GitHub Release. For 0.5.0, once that release is published, the address is:

`https://github.com/j44mes/Zoop/releases/download/v0.5.0/zoop-0.5.0.zip`

That address is the plugin zip. It contains one folder, `zoop/`, with `seo-studio.php`, `vendor/`, and `assets/build/`. Upload it in **Plugins → Add New → Upload Plugin**, then activate Zoop. The left-hand menu is **Zoop**. The address stays `wp-admin/admin.php?page=seostudio`. No Composer or npm step is required on the WordPress server. Installing a newer zip over the same `zoop` folder keeps Zoop's tables and post meta.

No release has been published. Pushing a version tag does not create one. After this workflow is on `main`, **Actions → Release** creates a draft only when every one of these is true:

- The workflow is run from `main`, and the confirm field is exactly `publish`.
- `docs/reviews/gate-b.md` exists and records `Reviewer:` plus `Verdict: PASS` or `Verdict: PASS WITH CONDITIONS`. The implementing agent does not write that verdict.
- The commit was merged into `main` through a pull request with an approving review from someone other than the pull request author.
- The latest PHP 8.1–8.3, integration, JavaScript, browser, and repository-baseline checks for that commit succeeded.

The workflow then builds `zoop-<version>.zip`, installs that zip on a clean WordPress site, checks the version, Zoop menu, and dashboard script, installs the same zip again, and checks that the stored score and settings are still there. Only that verified zip is attached to a **draft** release, which also creates the `v<version>` tag. Publishing the draft is a separate step.

Two other downloads are not the file to upload:

- **Code → Download ZIP** is the source tree. It does not contain `vendor/` or `assets/build/`. WordPress will list Zoop, then show an admin error, and the Zoop menu will not work.
- **The Package workflow artifact** is a zip that contains `zoop-<version>.zip`. WordPress looks inside the outer zip, finds no plugin file, and reports that no valid plugins were found. GitHub always adds that wrapper to an Actions artifact. The release asset above is the inner file, without the wrapper.

To build the same zip locally:

```bash
bash scripts/package-plugin.sh
```

That command installs production PHP dependencies, builds the dashboard, and writes `dist/zoop-0.5.0.zip`.

To work from this directory instead, install the dependencies and build the admin screens:

```bash
composer install
npm ci
npm run build
```

Copy this directory to `wp-content/plugins/` and activate Zoop. For a production tree, install without dev dependencies:

```bash
composer install --no-dev --optimize-autoloader
```

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

`npm run build` writes `assets/build/`, which the admin screens enqueue. That directory is not committed. `scripts/package-plugin.sh` is what puts it, and `vendor/`, into `dist/zoop-<version>.zip`. The Package workflow runs that script for CI and stores the zip as an Actions artifact. That artifact is wrapped again by GitHub, so it is not the install link. The Release workflow is manual, requires the confirm field `publish`, and stops at a draft.

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

GPL-2.0-or-later. See `seo-studio.php`.
