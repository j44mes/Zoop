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

Content-mode scoring uses estimated metadata. Only a live fetch observes the public HTML; plugin filters, dynamic variables and theme output can differ from stored templates.

- Owner mode with no other SEO plugin active expands `_seostudio_title`, `_seostudio_description` and `_seostudio_canonical`, then falls back to the post title, excerpt, and permalink.
- Analysis-only mode, and Owner mode while another SEO plugin is still active, ignore saved SEO Studio fields. Those fields are not being printed. An estimate from the active plugin's stored title, description, and canonical is used instead, including its default template when the stored value is empty.
- The first detected plugin wins: Yoast, Rank Math, SEOPress, All in One SEO, The SEO Framework.

Template approximations, recorded because none of these plugins exposes a stable public function for "the title you printed":

- Yoast: stored `_yoast_wpseo_*`, otherwise `wpseo_titles` `title-{post_type}` and `metadesc-{post_type}`, otherwise `%%title%% %%sep%% %%sitename%%` and the excerpt. Separator symbols follow Yoast's `sc-*` names.
- Rank Math: stored `rank_math_*`, otherwise `rank-math-options-titles` `pt_{post_type}_title` and `pt_{post_type}_description`, otherwise `%title% %sep% %sitename%` and the excerpt.
- SEOPress: stored `_seopress_titles_*` and `_seopress_robots_canonical`, otherwise `seopress_titles_option_name` nested `seopress_titles_single_titles[post_type][title|description]`, otherwise `%%post_title%% %%sep%% %%sitetitle%%` and the excerpt.
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

Unit tests still do not boot WordPress. Integration tests under `tests/php/Integration` boot the WordPress test suite through `@wordpress/env` and run in CI on PHP 8.1, 8.2, and 8.3. The tests-cli image installs PHPUnit 10, and the WordPress 6.6 test library still calls `PHPUnit\Util\Test::parseTestMethodAnnotations()`, which that release removed. CI and the README therefore run `phpunit-9.6.22.phar` inside the container. Coverage for `src/Scoring` and `src/Content` is printed from the unit job with pcov. The 95% line target applies to those two directories.

## Licence

Phase 1 is GPL-2.0-or-later. Phase 2 bundles `yoastseo` 3.6.0, which is GPL-3.0. Shipping that package means the combined plugin is distributed under GPL-3.0. Its licence file is kept at `vendor-js/yoastseo/LICENSE`, it is listed under third-party code in `readme.txt`, and the admin UI does not use the Yoast name, logo, or yoast.com links. Assessment text from the package is shown with HTML stripped.

Action Scheduler 3.9.3 is GPL-3.0-or-later, which the specification requires in Composer now. Zoop's own code can be distributed under GPL-3 because it is GPL-2.0-or-later. The library is not loaded: its package has no Composer autoload, and Phase 1 does not include `action-scheduler.php`. Its plugin header requires WordPress 6.5. Zoop still declares 6.4, because this phase does not boot the library. Phase 3 has to reconcile that before jobs run.

## Phase 1 review corrections

- Content models carry `metadata_status: estimated`; live HTML extraction carries `observed_html`. Resolving stored templates over a live model is still estimated. This is provenance, not a guarantee of search-engine indexing or exact compatibility with every SEO plugin. Option-shape regression tests cover Yoast and SEOPress; installed-plugin end-to-end parity remains unverified.
- The SEOPress option shape follows its own [wpml-config.xml](https://github.com/wp-seopress/wp-seopress-public/blob/master/wpml-config.xml). Yoast templates use the actual post type. Unsupported dynamic variables and filters remain a reason to use Verify live.
- A single selector resolver applies post overrides, then the filtered site post-type map in both render modes.
- `is_indexable` is nullable. False means a detected blocker (non-public post, password, site visibility or robots noindex/none). Null means unverified; true means the checks passed against fetched HTML and HTTP robots headers, not that Google has indexed the page. Robots.txt, canonical consolidation and search-engine decisions are outside this flag. Schema version 2 clears the previous unconditional true values; re-analysis repopulates them. Upgrades run on init as well as activation.
- Dashboard scores match the saved focus phrase. A changed, cleared or unanalysed focus phrase never inherits a score from a different phrase. Explicit ad-hoc analyses remain in the full record, without silently becoming the saved focus phrase.
- Related phrases and the primary phrase are rebuilt from current meta on either key's save/delete hooks. Case-insensitive duplicates share one index row, with primary taking precedence. Hook registration is idempotent even when WordPress restores hooks between tests.
- Lists scan only page/post IDs in batches of 200 to honour custom `edit_post` capability filters and produce exact authorised totals. Only the requested page loads score details; no collection query loads PageModel JSON. Ties sort by page ID. Counting still requires O(n) permission checks; this is not a constant-time pagination claim or a 10,000-page benchmark. Background audit/large-site optimisation remains a later phase.
- SEO validation is scoped to `/seostudio/v1/posts/{id}/seo`, including its trailing-slash form. Other plugins' routes are untouched.
