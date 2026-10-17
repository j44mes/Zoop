# Decisions

These choices are recorded so later phases do not treat them as accidental.

## Names

The repository and the plugin header use Zoop. Internal identifiers follow the specification: namespace `SEOStudio`, text domain `seo-studio`, option `seostudio_settings`, REST namespace `seostudio/v1`. Renaming those would break the spec.

## Rubric

The 22 checks, their points, and the published 92/100 reference are fixed in `docs/scoring.md`. Partial credit is awarded even when the entire-keyphrase check also passes. The visible-text full score starts at 2% of HTML bytes. Code checks stay out of the denominator until a fetch has supplied measurements.

## Storage enums

Specification enums (`object_type`, `engine`, `rating`, `status`) are `varchar` columns. `dbDelta` does not alter MySQL enums reliably. The allowed values are enforced in PHP.

## HTML parser

`Extractor` uses `DOMDocument` to build the tree. `WP_HTML_Tag_Processor` reads `hidden`, `aria-hidden`, and `style` when WordPress is loaded, then marks those elements so the DOM step can remove them. The processor does not expose the tree operations the extractor needs, and `masterminds/html5` is not a Phase 1 dependency. Without WordPress, the same attribute checks run on the DOM attributes, including `display: none` and `visibility: hidden` with arbitrary whitespace.

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

## Names stay split

Zoop is the repository and the plugin header. SEO Studio is the product name in the specification. Internal prefixes stay `seostudio_` for tables, meta keys, the REST namespace, and the text domain. Renaming those prefixes would need a migration, so Phase 2 must not introduce a second prefix.

## Resolved metadata

Scoring uses the metadata a crawler would receive.

- Owner mode with no other SEO plugin active expands `_seostudio_title`, `_seostudio_description` and `_seostudio_canonical`, then falls back to the post title, excerpt, and permalink.
- Analysis-only mode, and Owner mode while another SEO plugin is still active, ignore saved SEO Studio fields. Those fields are not being printed. The active plugin's rendered title, description, and canonical are used instead, including its default template when the stored value is empty.
- The first detected plugin wins: Yoast, Rank Math, SEOPress, All in One SEO, The SEO Framework.

Template approximations, recorded because none of these plugins exposes a stable public function for "the title you printed":

- Yoast: stored `_yoast_wpseo_*`, otherwise `wpseo_titles` `title-post` and `metadesc-post`, otherwise `%%title%% %%sep%% %%sitename%%` and the excerpt. Separator symbols follow Yoast's `sc-*` names.
- Rank Math: stored `rank_math_*`, otherwise `rank-math-options-titles` `pt_{post_type}_title` and `pt_{post_type}_description`, otherwise `%title% %sep% %sitename%` and the excerpt.
- SEOPress: stored `_seopress_titles_*` and `_seopress_robots_canonical`, otherwise `seopress_titles_option_name` keys `seopress_titles_single_post_title` and `seopress_titles_single_post_desc`, otherwise `%%post_title%% %%sep%% %%sitetitle%%` and the excerpt.
- All in One SEO: the `aioseo_posts` row when that table exists, otherwise `#post_title #separator_sa #site_title` and the excerpt.
- The SEO Framework: `_genesis_title`, `_genesis_description`, `_genesis_canonical_uri`. An empty title is the post title. When `autodescription-site-settings` `title_rem_additions` is off, the site name is appended. This is not TSF's full title generator.

## Public meta

`show_in_rest` is false for every `_seostudio_*` key. The specification previously asked for `show_in_rest` plus `auth_callback`. In WordPress that callback only guards writes, so a published post would have leaked keyphrases through `/wp/v2/posts`. Reads and writes go through `seostudio/v1`.

## First analysis and live fetch

`POST /analyse/{object_type}/{object_id}` creates the page row. Phase 1 accepts `object_type=post` only. `term` and `home` stay in the schema and return `seostudio_validation` until those objects are modelled.

Live mode in this phase performs one synchronous same-host fetch and returns the page record. It does not queue Action Scheduler. That queue is Phase 3. A third same-host redirect is rejected. The HTTP API is asked for 2 MB + 1 byte so a body cut off at 2 MB is distinguishable from a complete 2 MB body.

## Keyphrase index

`added_post_meta`, `updated_post_meta`, and `deleted_post_meta` on `_seostudio_focus_keyphrase` update `seostudio_keyphrases`. `before_delete_post` removes every index row for that post. `wp seostudio analyse` still writes the index for a keyphrase that was passed on the command line and not saved as meta.

## Tests that need WordPress

Unit tests still do not boot WordPress. Integration tests under `tests/php/Integration` boot the WordPress test suite through `@wordpress/env` and run in CI on PHP 8.1, 8.2, and 8.3. Coverage for `src/Scoring` and `src/Content` is printed from the unit job with pcov. The 95% line target applies to those two directories.

## Licence

Phase 1 is GPL-2.0-or-later. `yoastseo` is GPL-3.0 and is not installed yet. The licence question for that package is deferred to Phase 2.

Action Scheduler 3.9.3 is GPL-3.0-or-later, which the specification requires in Composer now. Zoop's own code can be distributed under GPL-3 because it is GPL-2.0-or-later. The library is not loaded: its package has no Composer autoload, and Phase 1 does not include `action-scheduler.php`. Its plugin header requires WordPress 6.5. Zoop still declares 6.4, because this phase does not boot the library. Phase 3 has to reconcile that before jobs run.
