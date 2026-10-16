# Decisions

These choices are recorded so later phases do not treat them as accidental.

## Names

The repository and the plugin header use Zoop. Internal identifiers follow the specification: namespace `SEOStudio`, text domain `seo-studio`, option `seostudio_settings`, REST namespace `seostudio/v1`. Renaming those would break the spec.

## Rubric

The 22 checks, their points, and the published 92/100 reference are fixed in `docs/scoring.md`. Partial credit is awarded even when the entire-keyphrase check also passes. The visible-text full score starts at 2% of HTML bytes. Code checks stay out of the denominator until a fetch has supplied measurements.

## Storage enums

Specification enums (`object_type`, `engine`, `rating`, `status`) are `varchar` columns. `dbDelta` does not alter MySQL enums reliably. The allowed values are enforced in PHP.

## HTML parser

`Extractor` uses `DOMDocument`. `WP_HTML_Processor` does not yet expose the tree operations this extractor needs, and `masterminds/html5` is not a Phase 1 dependency.

## Fetch policy

DNS that returns no address is blocked. A host that resolves to a private or reserved address is blocked, including when the site itself is on localhost. Content rendering still works there. Verify-live does not. That is the specification's SSRF rule, not an oversight. Basic authentication is read only from the constants `SEOSTUDIO_FETCH_USER` and `SEOSTUDIO_FETCH_PASS`.

## Coding standards

PHPCS runs `WordPress-Extra` and `WordPress-Docs` with three exclusions:

- `WordPress.Files.FileName` and `WordPress.NamingConventions.PrefixAllGlobals`, because the code is PSR-4 under `SEOStudio`.
- `WordPress.NamingConventions.ValidFunctionName` and `ValidVariableName`, because methods and promoted properties are camelCase. Options, meta keys, and table columns stay snake_case.
- `Squiz.Commenting.FunctionComment` parameter tags, because parameter types are declared in PHP. Array shapes still use `@param` and `@return`.

Filters keep the published slash names `seostudio/assessments` and `seostudio/function_words`.

## Detect-only plugins

`wp seostudio detect` reports AIOSEO and The SEO Framework. Phase 1 has no importer for them, and it does not switch the plugin into Owner mode.

## Tests that need WordPress

The unit suite does not boot WordPress. PHPUnit, PHPCS, and PHPStan level 6 are the checks that run in CI. `wp seostudio analyse` and the REST routes are not executed in CI in this phase. `@wordpress/env` is not configured because the unit suite does not need it.

## Licence

Phase 1 is GPL-2.0-or-later. `yoastseo` is GPL-3.0 and is not installed yet. The licence question for that package is deferred to Phase 2.

Action Scheduler 3.9.3 is GPL-3.0-or-later, which the specification requires in Composer now. Zoop's own code can be distributed under GPL-3 because it is GPL-2.0-or-later. The library is not loaded: its package has no Composer autoload, and Phase 1 does not include `action-scheduler.php`. Its plugin header requires WordPress 6.5. Zoop still declares 6.4, because this phase does not boot the library. Phase 3 has to reconcile that before jobs run.
