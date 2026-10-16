# SEO Studio — Build Specification v1

Oct 16, 2026 · @James

## Decisions taken

SEO Studio is a universal, standalone WordPress SEO plugin that combines Yoast-style content analysis with Wincher-style numerical on-page scoring, built to eventually replace Yoast SEO Premium. TidyQuo is the first test site, not the product.

| # | Decision | Outcome | Consequence |
| --- | --- | --- | --- |
| 1 | Metadata ownership | Independent plugin. Owns titles, descriptions, canonicals, robots, social tags and schema when the owner enables Owner mode. Ships with an Analysis-only mode that never writes to the page head. | Must detect Yoast, Rank Math, AIOSEO, SEOPress and The SEO Framework and refuse to double-output. Migration is a feature, not an afterthought. |
| 2 | V1 scope | Approved: two scoring engines, rendered-page analysis, metadata editing, up to five keyphrases, site-wide dashboard, batch audits. Deferred: internal-link suggestions, redirects, Search Console, AI, Visual Builder panel. | Architecture must carry thousands of pages, several page builders and future modules without a rewrite. |
| 3 | Repository | New private repo `wordpress-seo-studio`, fully independent of any site codebase, producing an installable ZIP. | No hardcoded domains, industries or page counts anywhere in the code. |

Two earlier assumptions are withdrawn. Background jobs are now a V1 requirement, because a universal plugin cannot assume a small site. The plugin no longer plans around any particular site's existing metadata output; it detects what is there at install time.

Three roles run the build. Claude (this document) is lead architect. Claude Opus runs an adversarial review against this document before any code is written. Cursor, running Grok 4.7, implements the approved specification. Section 17 holds the implementation prompt and section 18 the review prompt.

The plan deliberately stops short of total Yoast Premium parity in V1. Redirects, sitemaps, Local, News and Video SEO, the Google Docs add-on and 24/7 support are roadmap or out of scope. WordPress core already serves a sitemap at `/wp-sitemap.xml`, so V1 does not duplicate it.

## Product definition

SEO Studio installs on any WordPress 6.x site, works with any theme or page builder through rendered-HTML analysis, and gives every page two scores: a Yoast-style traffic-light analysis and a Wincher-style 0 to 100 on-page score. Both scores run against the HTML a search engine receives, not the editor's raw content.

**Target user.** A site owner or marketer who is not an SEO specialist and wants clear, specific instructions on what to change on a page. Every assessment explains what it checks, what it found and what to do, in plain language.

**Operating modes.** The owner picks one at setup. Modes can be switched later from Settings.

| Mode | What the plugin does | What it never does | When to pick it |
| --- | --- | --- | --- |
| Analysis-only | Scores pages, stores keyphrases, shows recommendations. Reads existing metadata from the detected SEO plugin for scoring. | Writes nothing to the public page head. | Another SEO plugin already outputs metadata. |
| Owner | Everything above, plus outputs title, meta description, canonical, robots, Open Graph, Twitter card and basic schema. | Outputs anything while another SEO plugin is active, unless its output is confirmed disabled. | No SEO plugin, or after a completed migration. |

**V1 feature list.**

1. Rendered-page content pipeline (section 5).
2. Wincher-style 100-point scorer, deterministic, server-side (section 6).
3. Yoast-style SEO and readability analysis in the admin browser, using the `yoastseo` package (section 7).
4. Primary focus keyphrase plus up to four related keyphrases per post, each with synonyms.
5. Metadata editor: SEO title, meta description, canonical, robots, social title, description and image, with Google and social previews.
6. Site dashboard: every indexable URL with keyphrase, both scores, top issue, last analysed date, filters and bulk re-analyse.
7. Batch audit engine for sites of any size (section 8).
8. SEO plugin detection, Analysis-only and Owner modes, one-way migration import from Yoast, Rank Math and SEOPress (section 4).
9. Gutenberg sidebar panel and a classic meta box that works on every edit screen, including Divi and Elementor.
10. Settings, uninstall options, export of all plugin data as JSON.

**Roadmap (designed for, not built in V1).**

| Feature | Phase | Depends on |
| --- | --- | --- |
| Internal linking suggestions and orphan report | V1.1 | Content index table from section 9 |
| Divi 5 Visual Builder panel | V1.1 | Spike in Phase 3 |
| Redirect manager | V1.2 | Owner mode |
| XML sitemap control, breadcrumbs | V1.2 | Owner mode |
| Search Console import | V1.3 | OAuth, external API |
| AI title and description drafts | V1.3 | Settings for API key, approval queue |
| Local, Video, News schema | V2 | Owner mode schema graph |

**Out of scope permanently.** Rank tracking, backlink data, copying either vendor's visual design or wording.

## Architecture

One content pipeline produces a normalised page model; two independent engines score it; a job queue runs the pipeline in batches; a React admin reads everything over the REST API. The Wincher-style engine runs in PHP so scores can be stored and compared. The Yoast-style engine runs in the browser because `yoastseo` is JavaScript and shared hosts have no Node.js.

&#91;embedded content: SEO Studio architecture: 9 components, two scoring engines\]

Posts and scheduled jobs both feed the content pipeline; Engine A scores the PageModel in PHP and stores the result, while Engine B scores the same PageModel in the browser and posts its ratings back through the REST API. Owner-mode head output reads only post meta and never depends on either engine.

**Technology choices.**

| Layer | Choice | Reason | Rejected alternative |
| --- | --- | --- | --- |
| Language and minimums | PHP 8.1+, WordPress 6.4+, MySQL 5.7+ / MariaDB 10.4+ | PHP 8.1 gives enums and readonly properties; WP 6.4 gives a stable Interactivity-free block editor API | PHP 7.4 support doubles the test matrix for a shrinking audience |
| HTML parsing | `WP_HTML_Processor` (WP 6.4+) for tag walking; `DOMDocument` via `masterminds/html5` fallback | Core processor is safe on malformed HTML and already loaded; the fallback handles full-tree queries | Regex parsing of HTML; `simple_html_dom` (unmaintained) |
| Rendering a post | In-process: `apply_filters('the_content', $post->post_content)` inside a controlled `WP_Query` loop; loopback HTTP fetch only for the Full-page mode | In-process rendering reaches drafts and private posts with no auth problems and no network call; loopback fetch sees the complete head and theme output | Headless browser rendering (not available on shared hosting) |
| Background jobs | Action Scheduler library, bundled | Proven at WooCommerce scale, survives WP-Cron being disabled when triggered by real cron, has an admin screen for failed jobs | Bare WP-Cron (unreliable on low-traffic sites); custom queue table (reinventing Action Scheduler) |
| Score storage | Custom tables `seostudio_scores` and `seostudio_pages`; keyphrases and metadata in post meta | Tables make the dashboard a single indexed query over 10,000 rows; post meta keeps per-post data portable and exportable | Everything in post meta (dashboard becomes N+1 meta queries) |
| Admin UI | React 18 via `@wordpress/scripts`, `@wordpress/components`, `@wordpress/data` stores | Native look in both the block editor sidebar and standalone admin pages; ships with WordPress so the bundle stays small | Vue or Svelte (second framework in the admin), jQuery pages |
| Yoast engine | `yoastseo` npm package 3.x from the `Yoast/wordpress-seo` monorepo, bundled into a web worker | GPL-licensed, maintained, the same code Yoast runs | Archived standalone `YoastSEO.js` repo; re-implementing the assessments by hand |
| Front-end output | A single `Head_Output` service hooked to `wp_head` at priority 1, with `pre_get_document_title` and `wp_robots` filters | One place to audit; priority 1 matches what SEO plugins do so the title filter wins | Theme template edits |
| Settings | One `seostudio_settings` option, versioned, with a schema | Simple export and import; migrations run on version mismatch | Dozens of individual options |
| Builds | Composer for PHP autoloading and Action Scheduler; npm for the React bundle; GitHub Actions produce the release ZIP | Standard WordPress plugin toolchain | Committing built assets by hand |

**Module boundaries.** Each namespace below is a folder under `src/` and depends only on the layers above it in this list: `Core` (plugin bootstrap, settings, capabilities), `Content` (rendering and extraction), `Scoring` (both engines' PHP side), `Jobs` (Action Scheduler wrappers), `Storage` (tables, meta repositories), `Rest` (controllers), `Output` (head output, Owner mode), `Integrations` (SEO plugin detectors, builder adapters), `Admin` (menu pages, enqueues). Nothing in `Scoring` may call WordPress functions other than `__()` and `apply_filters()`, so the scorer is unit-testable without a WordPress install.

**Extension points.** Filters `seostudio/assessments` (add or remove Wincher-style assessments), `seostudio/content_selectors` (per-site main-content selectors), `seostudio/builder_adapters` (register a page builder adapter), `seostudio/head_output` (adjust Owner mode tags), and action `seostudio/page_analysed` (fires after a page is scored, with the result).

## Metadata ownership and migration

Exactly one component may write each SEO tag on a page. The plugin enforces this with a detector that runs on every admin load, a hard interlock in `Head_Output`, and a migration wizard that imports another plugin's data before Owner mode can be turned on.

**Detection.** The detector checks for defined constants and active plugin paths, never for option values, because constants are set before any output.

| Plugin | Detection constant | Title/description meta keys | Focus keyphrase key | Robots key |
| --- | --- | --- | --- | --- |
| Yoast SEO | `WPSEO_VERSION` | `_yoast_wpseo_title`, `_yoast_wpseo_metadesc` | `_yoast_wpseo_focuskw`; Premium related: `_yoast_wpseo_focuskeywords` (JSON) | `_yoast_wpseo_meta-robots-noindex`, `-nofollow` |
| Rank Math | `RANK_MATH_VERSION` | `rank_math_title`, `rank_math_description` | `rank_math_focus_keyword` (comma list) | `rank_math_robots` (array) |
| SEOPress | `SEOPRESS_VERSION` | `_seopress_titles_title`, `_seopress_titles_desc` | `_seopress_analysis_target_kw` | `_seopress_robots_index` |
| All in One SEO | `AIOSEO_VERSION` | Table `{prefix}aioseo_posts` columns `title`, `description` | `keyphrases` JSON column | `robots_noindex` column |
| The SEO Framework | `THE_SEO_FRAMEWORK_VERSION` | `_genesis_title`, `_genesis_description` | none | `_genesis_noindex` |

Meta keys are taken from each plugin's current public code and must be re-verified by the implementer against the installed version before the importer ships. Yoast title and description values contain replacement variables such as `%%title%% %%sep%% %%sitename%%`; the importer expands the common ones and leaves unknown ones as literal text flagged for review.

**Interlock rules.**

1. Owner mode cannot be enabled while a detected SEO plugin is active. The toggle is disabled with the reason shown.
2. If a detected plugin is activated after Owner mode was enabled, `Head_Output` suspends itself on the next request and the dashboard shows a warning banner. Nothing is deleted.
3. In Analysis-only mode the scorer reads the detected plugin's title and description for the page so scores reflect what is actually output.
4. In Owner mode, if the theme already prints `<title>` without `title-tag` support, the plugin shows a one-line notice and does not add a second title.

**Migration wizard (one-way import).**

1. Preview: counts of posts with title, description, keyphrase, robots and canonical values in the source plugin, and how many would overwrite existing SEO Studio values.
2. Import runs as a background job in batches of 200 posts, idempotent, with a completion report.
3. Owner can then deactivate the source plugin themselves. The plugin never deactivates another plugin.
4. Owner mode becomes available only after the detector no longer sees the source plugin.
5. A rollback is the source plugin's own data, which the import never touches.

**Owner mode output set.** Title (filter `pre_get_document_title`), `meta name=description`, `link rel=canonical`, `meta name=robots` (filter `wp_robots`), Open Graph `og:title`, `og:description`, `og:type`, `og:url`, `og:image` with width and height, Twitter `twitter:card`, `twitter:title`, `twitter:description`, `twitter:image`, and JSON-LD `WebSite` and `Organization` on the home page plus `WebPage` or `Article` per post with `BreadcrumbList`. Separate schema types beyond these are roadmap.

**Preservation guarantee.** Installation, activation and mode changes never alter post content, slugs, post IDs, menus, builder layouts or any other plugin's stored data. The regression suite in section 14 asserts this on a fixture site before and after each operation.

## Content pipeline

Every analysis starts from rendered HTML, so the plugin works on any theme or builder without a per-builder integration. Two render modes exist; the pipeline then strips boilerplate and produces one `PageModel` that both scoring engines consume.

**Render modes.**

| Mode | How | Reaches | Used for |
| --- | --- | --- | --- |
| Content render (default) | Set up the post with `setup_postdata()`, run `apply_filters('the_content', $post->post_content)`, capture output, combine with the plugin's own computed title, description and canonical | Published, draft, pending, private and scheduled posts; unsaved builder changes are not reachable | Editor-side analysis, batch audits, drafts |
| Full-page fetch | `wp_remote_get()` the post's permalink (or preview link with a logged-in nonce cookie for drafts) with a 20-second timeout and a 2 MB body cap | Everything a crawler sees: theme head, menus, footer, scripts | Code checks (external CSS, visible-text ratio), verifying Owner mode output, a Verify-live button |

Full-page fetch may only target `home_url()`'s scheme and host. Any other host, any IP literal, any redirect off-host, and any non-200 status is rejected before the body is read. Credentials for a password-protected staging site are read from the constants `SEOSTUDIO_FETCH_USER` and `SEOSTUDIO_FETCH_PASS` in `wp-config.php`, never from the database.

**Boilerplate stripping (main-content selection).** The extractor picks the first rule that matches and records which one it used, so the UI can show it.

1. A per-site CSS selector from Settings, if set (filter `seostudio/content_selectors`).
2. `main`, then `article`, then `[role=main]`.
3. The element with the highest text density among `div` and `section` elements that is not inside `header`, `footer`, `nav`, `aside` and does not match a known cookie-banner, menu or widget class list.
4. Elements with `hidden`, `aria-hidden=true`, `display:none` inline, `noscript`, `script`, `style`, `template`, `svg` and `iframe` are removed before any counting.

In Content render mode step 3 is skipped because the captured string is already the body.

**PageModel fields.** `url`, `slug`, `title_tag`, `meta_description`, `canonical`, `robots`, `h1[]`, `headings[] {level, text, order}`, `body_text`, `word_count`, `paragraphs[]`, `sentences[]`, `links[] {href, anchor, internal, nofollow}`, `images[] {src, alt, in_body}`, `has_external_css`, `html_bytes`, `visible_text_bytes`, `is_https`, `lang`, `render_mode`, `content_selector_used`, `rendered_at`.

**Page builders.** Verified position as of 16 October 2026:

| Builder | Storage | What works with no adapter | Adapter in V1 |
| --- | --- | --- | --- |
| Gutenberg | Blocks in `post_content` | Content render gives full body text | Sidebar panel via `@wordpress/edit-post` PluginSidebar; live analysis of unsaved content from the block editor store |
| Divi 5 | Native WordPress blocks in `post_content` with server render callbacks; no shortcodes | Content render gives full body text, including drafts, because `the_content` runs the block render callbacks | None in V1. Classic meta box on the WordPress edit screen. Divi 5 exposes a React extension API (`@divi/*` packages) and third parties have shipped custom modals in the Visual Builder, so a panel is feasible; it is a Phase 3 spike |
| Divi 4 | `et_pb_*` shortcodes in `post_content` | Content render runs `do_shortcode`, so body text is extracted | None; meta box only |
| Elementor | JSON in `_elementor_data` meta; `post_content` is a flattened copy | Content render reaches the flattened copy; Full-page fetch reaches everything | None; meta box only. Elementor's `elementor/editor/after_enqueue_scripts` hook is the V1.1 route |
| Any other | Varies | Full-page fetch always works for published pages | Universal fallback |

Sources: [Divi 5 extension example modules](https://github.com/elegantthemes/d5-extension-example-modules), [Divi 5 dev tool with a custom Visual Builder modal](https://github.com/elegantthemes/d5-dev-tool), [Divi 5 block render callbacks](https://wordpress.org/plugins/content-visibility-for-divi-builder/), [Yoast page builder compatibility note](https://yoast.com/help/is-the-plugin-compatible-with-xyz-visual-page-builder/).

**Why this beats editor-box analysis.** Yoast's own help page states that it analyses only the main content box and relies on each builder to push content into its analysis; Divi 5 content is reported as invisible to Yoast today. Rendering first means SEO Studio has no such gap.

**Caching.** Each `PageModel` is stored with a hash of the inputs (post modified time, settings version, plugin version, selector). A re-analysis is skipped when the hash is unchanged unless the user forces it.

## Scoring engine A: on-page score (Wincher-style, 0 to 100)

The on-page score is a deterministic sum of 22 assessments in 7 categories totalling 100 points, computed in PHP from the `PageModel` for one keyphrase. The category weights and most rules are taken directly from Wincher's public results page; where Wincher does not publish a rule the table marks our approximation with an asterisk.

**Keyphrase normalisation (applies to every assessment).**

1. Lowercase, Unicode NFKC, replace punctuation with spaces, collapse whitespace. Do the same to every text field before matching.
2. Content words = the keyphrase tokens minus a per-language function-word list (English list bundled; filter `seostudio/function_words`).
3. Entire match = the content words appear in order and adjacent in the field. Partial match = at least one content word appears. Coverage = share of distinct content words that appear anywhere in the field.
4. Position = 1-based index of the first word of the entire match after leading function words are removed from the field.
5. Word count = tokens in `body_text` after normalisation; numbers count as words.

**Assessments.**

| Category | Assessment | Max | Rule | Source |
| --- | --- | --- | --- | --- |
| Title (27) | Title exists | 7 | Non-empty `title_tag` | Wincher |
|  | Entire keyphrase in title | 6 | Entire match | Wincher |
|  | Part of keyphrase in title | 2 | Partial match | Wincher |
|  | Title length | 6 | 30 to 60 characters: 6; 20 to 29 or 61 to 70: 4; otherwise 2; missing: 0 | Wincher range; steps \* |
|  | Keyphrase position in title | 6 | Position 1: 6; within first half of the words: 4; later: 2; no entire match: 0 | Wincher; steps \* |
| Headings (20) | Entire keyphrase in H1 | 3 | Entire match in the first H1 | Wincher |
|  | Part of keyphrase in H1 | 2 | Partial match in the first H1 | Wincher |
|  | H1 length | 2 | 1 to 40 characters: 2; 41 to 60: 1; otherwise 0 | Wincher; step \* |
|  | Keyphrase position in H1 | 5 | Position 1: 5; 2: 4; 3: 2; 4 or later: 0; no entire match: 0 | Wincher publishes 0 at position 5; steps \* |
|  | Number of H1 tags | 4 | Exactly one: 4; otherwise 0 | Wincher |
|  | Title and H1 differ | 2 | Normalised strings not identical | Wincher |
|  | Keyphrase in subheadings | 2 | Entire match in any H2 to H6: 2; partial only: 1; none: 0 | Wincher publishes 1 for partial |
| Body (28) | Word count | 14 | 500 or more: 14; 300 to 499: 10; 150 to 299: 6; 1 to 149: 2; 0: 0 | Wincher threshold; steps \* |
|  | Keyphrase in body | 7 | Entire matches 3 or more: 7; 2: 5; 1: 3; 0: 0 | Wincher threshold; steps \* |
|  | Keyphrase partially in body | 5 | Coverage above 70%: 5; 50 to 70%: 3; below: 0 | Wincher 70% |
|  | Every keyphrase word in body | 2 | Coverage 100% | Wincher |
| Meta (5) | Keyphrase in meta description | 5 | Entire match: 5; coverage at least 50%: 3; otherwise 0; no description: 0 | Wincher; partial step \* |
| Media (4) | Keyphrase in an image alt | 4 | Entire match in any `alt`: 4; partial: 2; no images or no alt: 0 | Wincher; partial step \* |
| Code (6) | Separate CSS file | 3 | At least one `link rel=stylesheet` in the fetched page | Wincher |
|  | Visible text ratio | 3 | `visible_text_bytes / html_bytes` at least 2%: 3; 1 to 2%: 1; below: 0 | Wincher shows 2.88% as perfect against a 2% line |
| URL (10) | Keyphrase in URL | 5 | All content words in the path: 5; more than half: 3; root path `/`: 5; otherwise 0 | Wincher; partial step \* |
|  | HTTPS | 5 | Scheme is https | Wincher |

Source: [Wincher On-Page SEO Checker](https://www.wincher.com/free-tools/on-page-seo-checker), results and explanatory text as published; fetched 16 October 2026.

**Code category in Content render mode.** The two Code assessments need the full page. In Content render mode they are computed from the last Full-page fetch of the same URL if one exists; otherwise they are marked not yet checked and the score is shown out of 94 with a Verify-live button. The stored score always records its denominator.

**Result object per assessment.** `id` (stable slug such as `title.entire_keyphrase`), `category`, `max`, `earned`, `status` (pass, partial, fail, not\_checked), `observed` (the measured value, e.g. `{"length": 65}`), `explanation`, `recommendation`, `evidence` (the matched text or element). Definitions live in `src/Scoring/OnPage/assessments/*.php`, one class each, registered through the `seostudio/assessments` filter.

**Advisories (not scored).** The rubric rewards repetition and length, which is a 2015 view of SEO. Alongside the score the engine emits advisories that never change the number: keyphrase density above 3% of words (over-optimisation), title over 600 px wide, meta description outside 120 to 157 characters, more than 75% of subheadings containing the keyphrase, and body under 300 words on a page typed as an article. Recommendation text for the Body category never tells the user to add words for their own sake.

**Multiple keyphrases.** The score is computed once per keyphrase. The dashboard shows the primary keyphrase score; the page panel shows a tab per keyphrase. Related keyphrases do not share synonyms with the primary.

**Worked example (public reference).** Wincher's own results for keyphrase `rank tracker` on `https://www.wincher.com/rank-tracker` on 18 September 2025, as published on their tool page: Title 25/27 (length 65 characters scored 4/6), Headings 14/20 (keyphrase at position 5 in the H1 scored 0/5, partial subheading 1/2), Body 28/28 (1,309 words, 11 mentions), Meta 5/5, Media 4/4, Code 6/6 (2.88% visible), URL 10/10. Total 92. The engine must reproduce 92 from a fixture built from that page's observed values.

**Test cases (fixtures in `tests/fixtures/onpage/`).**

| # | Fixture | Expected total | Checks |
| --- | --- | --- | --- |
| 1 | Wincher reference page values above | 92 | Every category matches the published breakdown |
| 2 | Empty page at \`/empty/\`, keyphrase set | 5 | Only HTTPS scores; every other assessment reports fail with a recommendation |
| 3 | Perfect page: title and H1 start with keyphrase, 1 H1, 520 words, 4 mentions, keyphrase in an H2, alt, meta, slug, external CSS, 3% visible | 100 | Deterministic across three runs |
| 4 | Two H1 tags, otherwise perfect | 96 | Number of H1 tags scores 0 |
| 5 | Keyphrase with function words `how to price a cleaning contract` | content words = `price cleaning contract` | Normalisation drops `how`, `to`, `a` |
| 6 | Title 61 characters | title length 4 | Boundary |
| 7 | Visible text ratio 1.5% | code ratio 1 | Partial step |
| 8 | Content render mode, no prior fetch | denominator 94 | Code assessments `not_checked` |
| 9 | Non-ASCII keyphrase `café près de moi` | matches `Café près de moi` in title | NFKC and case folding |
| 10 | Keyphrase appears only inside a `nav` element | body mentions 0 | Boilerplate excluded |

## Scoring engine B: content analysis (Yoast-style traffic lights)

The content analysis uses the `yoastseo` npm package (GPL, version 3.x, published from the `Yoast/wordpress-seo` monorepo) running in a web worker in the admin browser. SEO Studio adds no assessments of its own to this engine in V1; it feeds the package a `Paper` built from the `PageModel` and renders the results. This keeps parity with Yoast's published scoring and avoids re-implementing 25 assessments.

**Integration.**

1. Build step bundles `yoastseo` and its English researcher (`yoastseo/build/languageProcessing/languages/en/Researcher`) into `worker.js`; additional language researchers are loaded on demand by site locale.
2. The admin UI fetches the `PageModel` over REST, constructs `new Paper(body_html, { keyword, synonyms, description, title, titleWidth, slug, permalink, locale })` and posts it to the worker.
3. The worker runs the SEO assessor, the readability assessor and (English only) the inclusive-language assessor, returning per-assessment `score`, `text` and `marks`.
4. For related keyphrases the related-keyphrase assessor set runs once per keyphrase.
5. Results are displayed live; the overall SEO and readability ratings are posted back to REST and stored in `seostudio_scores` so the dashboard can show them without opening the editor.

**Assessments included (from the package's published scoring spec).** SEO: keyphrase in introduction, keyphrase length, keyphrase density, keyphrase in meta description, keyphrase in subheadings, competing links, keyphrase in image alt attributes, keyphrase in SEO title, keyphrase in slug, previously used keyphrase, keyphrase distribution, text length, outbound links, internal links, SEO title width, meta description length, single title, function words in keyphrase, images, title presence. Readability: subheading distribution, paragraph length, sentence length, consecutive sentences, passive voice, transition words, text presence, word complexity, text alignment. Keyphrase distribution, word complexity, text alignment and title presence are marked Premium-only in Yoast's spec; they ship in the open-source package and are enabled here.

**Scoring formulas (as published, implemented by the package).**

```latex
\text{SEO overall} = \operatorname{round}\left(\frac{\sum_i s_i}{9\,n} \times 100\right)
```

Individual scores below 0 or at most 4 are red, 5 to 7 orange, 8 to 9 green. Readability uses penalty points instead: red 3 (4 when the language has partial support), orange 2, green 0; a total of 6 or more gives 30, 4 gives 60, under 4 gives 90, then divided by 10 for the 0 to 9 rating. The overall traffic light for a page is red below 41, orange 41 to 70, green above 70, matching Yoast's displayed thresholds.

Sources: [SEO scoring spec, yoastseo 3.6.0](https://cdn.jsdelivr.net/npm/yoastseo@3.6.0/build/scoring/assessments/SCORING%20SEO.md), [Readability scoring spec](https://cdn.jsdelivr.net/npm/yoastseo@3.6.0/build/scoring/assessments/SCORING%20READABILITY.md), [yoastseo on npm](https://npmjs.com/package/yoastseo).

**Previously-used keyphrase.** This assessment needs a list of keyphrases used on other posts. REST endpoint `GET /keyphrases/usage?keyphrase=` returns post IDs from the `seostudio_keyphrases` index so the check works site-wide, as Yoast's does.

**Licence obligations.** `yoastseo` is GPL. SEO Studio is distributed under GPL-2.0-or-later, keeps the package's `LICENSE` file in `vendor-js/`, lists it in `readme.txt` under Third-party code, and does not use the Yoast name or logo anywhere in the UI. The assessment feedback strings are the package's own; the plugin may override them through the package's `translations` hook but must not present them as Yoast's.

**Parity statement.** Because the engine is the package itself, results match Yoast SEO for the same input text. Differences arise only from input: SEO Studio feeds rendered HTML, so on a builder page it will usually score higher than Yoast, which sees an empty content box. The UI states this once in the panel footer.

**Fallback.** If the worker fails to load (CSP, old browser), the panel shows the on-page score alone with a one-line message. Engine B never blocks saving a post.

## Background processing and scale

All site-wide work runs through Action Scheduler in small, resumable batches so a 10,000-page site audits without touching PHP time limits or slowing the front end. Single-page analysis on save stays synchronous because it takes well under a second in Content render mode.

**Job types.**

| Job | Trigger | Batch | Per-item cost target | Concurrency |
| --- | --- | --- | --- | --- |
| `seostudio_analyse_post` | Post save or update (debounced 5 s), manual refresh | 1 post | under 400 ms, Content render | Unlimited, idempotent |
| `seostudio_audit_batch` | Dashboard Re-analyse all, settings change that affects scoring, plugin upgrade | 25 posts, ordered by post ID, cursor stored in the job args | under 10 s per batch | 1 running audit at a time |
| `seostudio_fetch_live` | Verify-live button, nightly for posts flagged for Code checks | 5 URLs | 20 s timeout per fetch | 1 at a time, 2 s pause between fetches |
| `seostudio_import_batch` | Migration wizard | 200 posts | under 10 s | 1 at a time |
| `seostudio_reindex_keyphrases` | Plugin upgrade | 500 posts | under 5 s | 1 at a time |

**Rules.**

1. A batch re-queues itself with the next cursor; it never loops inside one request. Progress is written to `seostudio_audit_runs` after every batch.
2. Batch size and pause are settings with the defaults above, so a slow host can be tuned without a release.
3. Action Scheduler is bundled through Composer and shares its tables with WooCommerce if present; the plugin registers its own group `seostudio` and cleans only that group on uninstall.
4. If WP-Cron is disabled, the dashboard shows the exact `wp action-scheduler run` cron line to add, and Verify-live falls back to a synchronous single fetch.
5. A failed item records the error on its `seostudio_pages` row and the run continues. The run report lists failures with a retry button.
6. Front-end requests never trigger analysis. Hooks fire only in admin, REST and cron contexts.

**Capacity targets (acceptance in section 14).** 1,000 posts fully audited in Content render mode in under 15 minutes on a shared host running one batch per minute; dashboard list query under 150 ms for 10,000 rows using the indexes in section 9; memory per batch under 64 MB.

**Caching.** Rendered `PageModel` JSON is stored on the `seostudio_pages` row with an input hash; identical hash means no re-score. Transients hold the dashboard summary counts for 5 minutes. Owner mode head output uses no cache; it is computed per request from post meta, which is already in the object cache.

## Data model

Per-post inputs live in post meta so they travel with WordPress exports; computed results live in three custom tables so the dashboard is one indexed query. All keys are prefixed `_seostudio_` (meta) or `{$wpdb->prefix}seostudio_` (tables).

**Post meta (inputs, user-owned).**

| Key | Type | Notes |
| --- | --- | --- |
| `_seostudio_focus_keyphrase` | string | Primary keyphrase |
| `_seostudio_related_keyphrases` | JSON array of `{keyphrase, synonyms[]}` | Up to 4 |
| `_seostudio_synonyms` | JSON array | Synonyms for the primary |
| `_seostudio_title` | string | SEO title template or literal; supports `%%title%%`, `%%sitename%%`, `%%sep%%`, `%%excerpt%%`, `%%category%%` |
| `_seostudio_description` | string | Meta description, same variables |
| `_seostudio_canonical` | URL | Empty means permalink |
| `_seostudio_robots` | JSON `{index, follow, max_snippet, max_image_preview}` | Booleans null means inherit site default |
| `_seostudio_og_title`, `_seostudio_og_description`, `_seostudio_og_image` | string, string, attachment ID | Social overrides |
| `_seostudio_cornerstone` | bool | Switches Yoast-style cornerstone thresholds |
| `_seostudio_content_selector` | string | Per-post override of the main-content selector |

All meta is registered with `register_post_meta()` with `show_in_rest`, a sanitize callback and `auth_callback` requiring `edit_post`.

**Tables.**

`seostudio_pages` (one row per analysable object): `id` bigint PK, `object_type` enum(post, term, home), `object_id` bigint, `url` varchar(2048), `url_hash` char(32) unique, `input_hash` char(32), `page_model` longtext (JSON), `render_mode` enum, `content_selector` varchar(255), `last_rendered_at` datetime, `last_fetched_at` datetime null, `last_error` text null, `is_indexable` tinyint. Indexes: `object (object_type, object_id)`, `url_hash`, `last_rendered_at`.

`seostudio_scores` (one row per page per keyphrase per engine run): `id` bigint PK, `page_id` bigint FK, `keyphrase` varchar(255), `keyphrase_hash` char(32), `engine` enum(onpage, seo, readability), `score` smallint, `denominator` smallint, `rating` enum(red, orange, green, grey), `details` longtext (JSON array of assessment results), `plugin_version` varchar(16), `created_at` datetime. Indexes: `page_keyphrase (page_id, keyphrase_hash, engine, created_at)`, `score`. The dashboard reads the latest row per `(page_id, keyphrase_hash, engine)`; history older than 90 days is pruned weekly, keeping the first and last row per month.

`seostudio_keyphrases` (index for previously-used and cannibalisation checks): `keyphrase_hash` char(32), `object_type`, `object_id`, `is_primary` tinyint, PK `(keyphrase_hash, object_type, object_id)`.

`seostudio_audit_runs`: `id`, `type` enum(audit, import, fetch), `status` enum(queued, running, done, failed, cancelled), `total`, `processed`, `failed`, `cursor`, `started_at`, `finished_at`, `report` longtext JSON.

**Options.** One autoloaded option `seostudio_settings` holding `{schema_version, mode, site_title_separator, home_title, home_description, default_robots, og_default_image, content_selectors[], batch_size, batch_pause_ms, function_word_locale, uninstall_delete_data}`. A non-autoloaded `seostudio_detected_plugins` snapshot refreshed on admin load.

**Lifecycle.** Activation runs `dbDelta` for the tables and schedules nothing. Upgrade compares `schema_version` and runs ordered migrations in `src/Storage/Migrations/`. Deactivation cancels pending Action Scheduler actions in group `seostudio` and leaves all data. Uninstall deletes tables, meta and options only when `uninstall_delete_data` is true; otherwise it removes only the options and scheduled actions. Export (Settings, Export) writes all meta, settings and latest scores to a JSON file; import restores meta and settings, never scores.

## REST API contract

All endpoints live under `seostudio/v1`, require a logged-in user with the stated capability, check the REST nonce, and return the same JSON shapes the admin React app renders. No endpoint is public.

| Method and route | Capability | Request | Response | Notes |
| --- | --- | --- | --- | --- |
| `GET /pages` | `edit_posts` | `page`, `per_page` (max 100), `search`, `post_type`, `rating`, `score_min`, `score_max`, `orderby`, `order`, `has_keyphrase` | `{items[], total, pages}`; item = `{page_id, object_type, object_id, title, url, edit_url, keyphrase, onpage {score, denominator}, seo_rating, readability_rating, top_issue, last_rendered_at, last_error}` | Dashboard list; single SQL query joining latest scores |
| `GET /pages/{page_id}` | `edit_post` on the object |  | Full page record with `page_model` and latest `details` for every keyphrase and engine | Page panel load |
| `POST /pages/{page_id}/analyse` | `edit_post` | `{mode: content or live, keyphrases?: string[]}` | Updated page record | Synchronous for `content`; `live` queues `seostudio_fetch_live` and returns `202` with `run_id` |
| `POST /posts/{post_id}/model` | `edit_post` | `{content_html, title, excerpt}` | `PageModel` without saving | Live analysis of unsaved block-editor content; output not stored |
| `GET /posts/{post_id}/seo` | `edit_post` |  | All `_seostudio_*` meta for the post, resolved title and description previews | Panel load |
| `PUT /posts/{post_id}/seo` | `edit_post` | Any subset of the meta fields | Saved meta plus resolved previews | Validation: title 1 to 300 chars, description 1 to 500, canonical same-host or empty, robots booleans |
| `GET /keyphrases/usage` | `edit_posts` | `keyphrase` | `{posts: [{post_id, title, is_primary}]}` | Previously-used check, cannibalisation |
| `POST /audits` | `manage_options` | `{type: audit, post_types?: string[]}` | `{run_id}` and `202` | Starts a site-wide run; `409` if one is running |
| `GET /audits/{run_id}` | `manage_options` |  | Run row with progress and report | Polled every 3 s by the dashboard |
| `DELETE /audits/{run_id}` | `manage_options` |  | `204` | Cancels pending batches |
| `GET /settings`, `PUT /settings` | `manage_options` | Settings object | Settings object | `PUT` validates against the schema; mode change to `owner` returns `409` with `detected_plugins` if the interlock blocks it |
| `GET /detect` | `manage_options` |  | `{plugins: [{slug, name, version, active}], theme_title_tag: bool}` | Drives mode UI |
| `POST /migrations/preview`, `POST /migrations` | `manage_options` | `{source: yoast or rankmath or seopress}` | Preview counts; `{run_id}` | Section 4 |
| `GET /export` | `manage_options` |  | JSON file download | All meta, settings, latest scores |

**Conventions.** Errors use `WP_Error` with codes `seostudio_forbidden`, `seostudio_not_found`, `seostudio_validation`, `seostudio_conflict`, `seostudio_fetch_blocked`, `seostudio_fetch_failed`, each with a human message and a `data.field` where relevant. All string inputs pass `sanitize_text_field` or `wp_kses_post` as appropriate before validation. Rate limit: `POST .../analyse` with `mode: live` is limited to 10 per user per minute by a transient counter; `POST /audits` to 1 per 10 minutes. Responses include `X-SEOStudio-Version`.

**Schema.** Every route registers `args` with types, enums and `validate_callback`, and `schema` for the response, so the generated OpenAPI file in `docs/openapi.json` is produced by a WP-CLI command and checked into the repo.

## Repository, file structure and tooling

Repository `wordpress-seo-studio` (private GitHub) holds one plugin, builds with Composer and npm, and releases a ZIP from GitHub Actions on every tag. No site-specific code, URLs or credentials are committed.

```markdown
seo-studio/
  seo-studio.php                 # Header, constants, autoload, bootstrap
  uninstall.php                  # Honours uninstall_delete_data
  readme.txt                     # WordPress.org format, third-party code section
  composer.json                  # PSR-4 autoload, woocommerce/action-scheduler, dev tools
  package.json                   # @wordpress/scripts, yoastseo, jest, playwright
  src/
    Core/        Plugin.php, Settings.php, Capabilities.php, Upgrader.php
    Content/     Renderer.php, LoopbackFetcher.php, Extractor.php, PageModel.php, FunctionWords/
    Scoring/     OnPage/Scorer.php, OnPage/Assessment.php, OnPage/assessments/*.php, OnPage/Normaliser.php
    Jobs/        AnalysePost.php, AuditBatch.php, FetchLive.php, ImportBatch.php, Scheduler.php
    Storage/     Tables.php, PagesRepository.php, ScoresRepository.php, KeyphrasesIndex.php, Migrations/
    Rest/        PagesController.php, PostSeoController.php, AuditsController.php, SettingsController.php, MigrationsController.php
    Output/      HeadOutput.php, TitleFilter.php, RobotsFilter.php, SchemaGraph.php, SocialTags.php
    Integrations/ Detector.php, Importers/{Yoast,RankMath,SeoPress}.php, Builders/{Gutenberg,Divi,Elementor}Adapter.php
    Admin/       Menu.php, Assets.php, MetaBox.php, Notices.php
  assets/src/
    dashboard/   index.tsx, components/, store/
    editor/      sidebar.tsx (Gutenberg PluginSidebar), metabox.tsx (classic screens)
    analysis/    worker.ts (yoastseo), paper.ts, results.tsx
    shared/      api.ts, ui/ (ScoreRing, TrafficLight, AssessmentList, Preview)
  assets/build/                  # Generated, gitignored, included in the release ZIP
  languages/seo-studio.pot
  tests/
    php/Unit/                    # PHPUnit, no WordPress bootstrap: Scoring, Normaliser, Extractor
    php/Integration/             # wp-env + WP test suite: REST, meta, output, migrations
    js/                          # Jest: paper building, worker results, components
    e2e/                         # Playwright against wp-env: editor panel, dashboard, modes
    fixtures/onpage/             # Section 6 test cases as JSON
  docs/
    architecture.md, scoring.md, openapi.json, release-checklist.md, rollback.md
  .github/workflows/ci.yml, release.yml
```

**Standards.** PHP: WordPress Coding Standards via PHPCS with `WordPress-Extra` and `WordPress-Docs`, PHPStan level 6 with the WordPress stubs, strict types in every file, PSR-4 namespace `SEOStudio\`. JavaScript: TypeScript, `@wordpress/eslint-plugin`, Prettier. Commits: Conventional Commits. Every public function has a docblock stating capability requirements where relevant.

**Builds and releases.** `npm run build` produces `assets/build/` with `@wordpress/dependency-extraction-webpack-plugin` so React and `@wordpress/*` load from WordPress core. `composer install --no-dev --optimize-autoloader` for release. GitHub Actions on push runs PHPCS, PHPStan, PHPUnit on PHP 8.1, 8.2 and 8.3 with WordPress latest and latest-1, Jest and Playwright. On a `v*` tag it builds `seo-studio-{version}.zip` and attaches it to the release. The ZIP installs through Plugins, Add New, Upload.

**Local development.** `@wordpress/env` (`wp-env`) with two configs: plain WordPress, and WordPress with Yoast Free, Rank Math and Divi installed for coexistence and detector tests. `wp-env run cli wp seostudio audit` starts an audit from the command line. WP-CLI commands: `wp seostudio analyse <post_id>`, `wp seostudio audit`, `wp seostudio detect`, `wp seostudio openapi`.

**Rollback.** Deactivating the plugin removes all front-end output immediately and cancels scheduled actions. Reinstalling the previous ZIP over the current one is supported because migrations are forward-only and additive; `docs/rollback.md` lists the exact steps and the data that each version added.

## UI specification

The interface has three surfaces: a dashboard under its own top-level menu, a page panel inside every edit screen, and a settings area. It is built from `@wordpress/components` so it looks native, with one accent colour, a score ring and a traffic-light chip as the only custom visual elements. No part of it copies Yoast's or Wincher's layout, iconography or wording.

**Design principles.** Score first, reasons second, fix third: every view leads with a number or light, expands to the assessment that caused it, and ends with one concrete instruction. Every assessment row is expandable; collapsed rows show name, status chip and points. Text at 13 px minimum; colour never carries meaning alone (status chips carry a word). All controls keyboard-reachable with visible focus; live regions announce score changes. Layouts work at 360 px wide.

**Screens.**

| Screen | Location | Contents | Key states |
| --- | --- | --- | --- |
| Dashboard | Admin menu SEO Studio, Overview | Summary strip (pages analysed, average on-page score, red/orange/green counts, last audit); table of pages with title, keyphrase, on-page score ring, SEO light, readability light, top issue, last analysed; filters by post type, rating, score range, has keyphrase; search; sort; Re-analyse all button with progress bar | Empty (no analysis yet, with a Run first audit button); Running audit (progress, cancel); Error rows with retry; WP-Cron disabled banner with the cron line |
| Page panel | Block editor sidebar (PluginSidebar) and classic meta box below the editor on all other screens, including Divi and Elementor | Keyphrase field with related keyphrases and synonyms; two score cards side by side (on-page 0 to 100 with denominator, content analysis light); tabs per keyphrase; assessment lists grouped by category with expand; Google preview; social preview; metadata form (title with variable chips and pixel-width bar, description with character count, canonical, robots, social overrides); Analyse now and Verify live buttons; footer line stating the render mode and selector used | Unsaved changes (live analysis from editor content, badge Live); Not yet analysed; Fetch blocked (reason shown); Worker unavailable (on-page score only) |
| Audit report | Dashboard, run row | Per-run counts, duration, failures with messages, retry failed | Running, done, cancelled |
| Settings: General | SEO Studio, Settings | Mode selector with detector results and interlock explanation; site title separator; home title and description; default robots; default social image | Owner blocked by detected plugin |
| Settings: Analysis |  | Main-content selectors per post type; function-word locale; batch size and pause; cornerstone defaults |  |
| Settings: Migration |  | Source plugin picker, preview counts, Start import, report | Preview, importing, done |
| Settings: Tools |  | Export JSON, Import JSON, Re-index keyphrases, Delete data on uninstall toggle, OpenAPI link |  |

**Components (in `assets/src/shared/ui/`).** `ScoreRing` (value, denominator, size), `TrafficLight` (rating, label), `AssessmentList` (grouped, expandable, shows observed value and recommendation), `CategoryBar` (earned over max per category), `SnippetPreview` (desktop and mobile), `SocialPreview`, `VariableInput` (title and description with `%%` chips and live resolution), `RunProgress`.

**Copy rules.** Recommendations are imperative and specific: Move the keyphrase to the start of the title, not Consider optimising your title. Each assessment has a one-sentence Why that explains the SEO reason in plain words. No exclamation marks, no emoji in the product UI, British English in the default locale with full i18n through `__()` and `@wordpress/i18n`.

**Wireframe deliverable.** Before Phase 2 the implementer produces low-fidelity wireframes for the dashboard and page panel as Markdown ASCII or Figma-free HTML mockups in `docs/wireframes/`, reviewed before React work starts.

## Security requirements

The plugin is treated as production software that runs on sites it does not control. Every rule below is enforced in code and covered by a test in section 14.

| Area | Requirement | Implementation |
| --- | --- | --- |
| Authorisation | Every REST route has a `permission_callback` that checks a capability against the specific object | `current_user_can('edit_post', $post_id)` for post routes; `manage_options` for site-wide routes; never `__return_true` |
| Request forgery | Every state-changing admin request carries a nonce | REST uses `X-WP-Nonce`; classic meta box save uses `wp_nonce_field` and `check_admin_referer`; no GET changes state |
| Input | All input sanitised then validated before use | `sanitize_text_field`, `esc_url_raw`, `absint`, `rest_sanitize_boolean`; schema validation in REST `args`; keyphrases capped at 255 chars, 5 per post |
| Output | All output escaped at the point of output | `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` for assessment HTML; JSON-LD through `wp_json_encode` with `JSON_HEX_TAG`; React renders strings, never `dangerouslySetInnerHTML` except the sanitised Google preview |
| Server-side request forgery | Full-page fetch can only reach the site's own host | Allow-list check on scheme and host against `home_url()` before `wp_remote_get`; `reject_unsafe_urls => true`; no redirects followed off-host (`redirection => 0` then manual same-host follow, max 2); IP literals, `localhost`, link-local and private ranges rejected even if the host resolves there; response capped at 2 MB with `limit_response_size` |
| Draft privacy | Draft and private content never reaches a public URL or an unauthorised user | Content render mode reads the post in-process under the current user's capability; preview fetch uses a short-lived preview nonce; `GET /pages` filters rows by `read_post`; stored `page_model` for non-public posts is served only to users who can edit them |
| Database | No raw SQL with interpolated values | `$wpdb->prepare` everywhere; table names from a constant map; `dbDelta` for schema |
| Secrets | No credentials in the database | Staging basic-auth credentials from `wp-config.php` constants only; future API keys stored with `wp_salt`-derived encryption and never returned by REST |
| Rate limiting | Expensive actions cannot be spammed | Transient counters per user for live fetch and audit start; Action Scheduler concurrency of one for audits |
| Failure isolation | A scoring or fetch failure cannot affect the public site | Front-end hooks only in Owner mode and only read post meta; all analysis wrapped in try/catch with errors logged to the page row; fatal-error-safe autoload with a version check and graceful bail on PHP under 8.1 |
| Dependencies | Supply chain is pinned and audited | `composer.lock` and `package-lock.json` committed; `npm audit` and `composer audit` in CI fail the build on high severity; Action Scheduler pinned to a release |
| Logging | Nothing sensitive in logs | Errors log route and post ID only, never request bodies; opt-in debug log behind `SEOSTUDIO_DEBUG` constant |
| Uninstall | Explicit data deletion only | `uninstall.php` deletes tables and meta only when the owner enabled Delete data on uninstall |

**Threats considered and out of scope for V1.** Multisite network activation (plugin refuses network activation with a notice; per-site activation works). Shared hosting cross-account attacks (hosting responsibility). Protecting the admin from a malicious administrator.

**Review gate.** Opus's adversarial review (section 18) and a manual pass against the WordPress Plugin Handbook security checklist are both required before the first staging deployment.

## Testing strategy and acceptance criteria

The build is accepted when every criterion below passes in CI and on a staging site, with the test commands recorded in the pull request. A claim that tests pass without CI output is not acceptance.

**Test layers.**

| Layer | Tool | Scope | Minimum |
| --- | --- | --- | --- |
| Unit (PHP, no WordPress) | PHPUnit 10 | `Normaliser`, every on-page assessment class, `Scorer` totals, `Extractor` on fixture HTML, `PageModel` serialisation | The 10 fixtures in section 6 plus one test per assessment branch; 95% line coverage on `src/Scoring` and `src/Content` |
| Integration (PHP) | PHPUnit with the WordPress test suite in `wp-env` | REST permission callbacks, meta registration and sanitisation, `Head_Output` under each mode, detector with Yoast and Rank Math installed, importers, migrations, Action Scheduler jobs, uninstall | Every REST route tested for 401, 403 and happy path; every job tested for re-queue and failure recording |
| Unit (JS) | Jest with `@wordpress/jest-preset-default` | `paper.ts` mapping, worker message handling, `ScoreRing`, `AssessmentList`, `VariableInput` resolution | All components render in each state listed in section 12 |
| End to end | Playwright against `wp-env` | Install, choose mode, set keyphrase in the block editor, see both scores, change title and watch the score move, run a site audit on 300 generated posts, migrate from Yoast, switch to Owner mode, view page source | The six flows above green on Chromium and WebKit |
| Security | PHPUnit and Playwright | SSRF cases (external host, IP literal, redirect off-host, oversized body), unauthenticated REST, contributor editing another author's post, XSS payloads in keyphrase and title, CSRF on meta box save | All rejected with the documented error codes |
| Regression | Snapshot script in `tests/regression/` | Before and after activation, mode switch, migration and upgrade: all permalinks, post IDs, `post_content` hashes, page head of 20 sample URLs, sitemap, existing plugin meta | Zero diffs except the plugin's own tags in Owner mode |
| Performance | PHPUnit timing plus `wp-env` with 10,000 generated posts | Dashboard query time, batch duration, memory | Dashboard under 150 ms; batch of 25 under 10 s; under 64 MB per batch |
| Accessibility | axe-core in Playwright | Dashboard and panel | No serious or critical violations |

**User acceptance on staging (manual, owner-run).**

- [ ] Install the ZIP on a staging site with Yoast active; plugin starts in Analysis-only and Owner mode is disabled with the reason shown.
- [ ] Open a page built with Divi 5 and a page built with Gutenberg; both show a body word count that matches the visible page.
- [ ] Set a keyphrase; on-page score and content lights appear within 3 seconds; every red assessment has a specific instruction.
- [ ] Edit the SEO title to start with the keyphrase; the title assessments turn green without reloading.
- [ ] Run a site audit; progress advances; the dashboard sorts by score and filters by rating.
- [ ] Run the Yoast import preview, then import; values match on five spot-checked posts.
- [ ] Deactivate Yoast; enable Owner mode; view source on three pages shows exactly one title, one description, one canonical, one robots tag and valid JSON-LD (Google Rich Results Test passes).
- [ ] Deactivate SEO Studio; view source shows the plugin's tags are gone and nothing else changed.
- [ ] Reactivate; all keyphrases, metadata and scores are still present.
- [ ] Uninstall with Delete data off; tables and meta remain. Reinstall; everything returns.

**Definition of done for a pull request.** CI green on all matrix entries; new code covered; `docs/scoring.md` updated if an assessment changed; `readme.txt` changelog entry; no new PHPCS or PHPStan findings; a reviewer other than the author (Opus review log attached for Phase 1 and Phase 4).

## Implementation phases

Eight phases, three hard gates. Phases 1 and 2 can be built in parallel once the REST contract is frozen at the end of Phase 1's first week; everything else is sequential. A phase is done only when its listed criteria pass in CI.

&#91;embedded content: Build roadmap: 8 phases, 3 gates\]

Phases 1 and 2 run side by side once Phase 1 freezes the REST contract (the horizontal arrow); Phase 3 needs both, Phase 4 needs only Phase 1, and nothing passes a gate on the implementer's say-so.

| Phase | Scope | Depends on | Done when |
| --- | --- | --- | --- |
| 0 Discovery and architecture | This document; Opus adversarial review; blockers and highs resolved |  | Gate A: review log shows no open Blocker or High |
| 1 Foundation and on-page engine | Plugin bootstrap, settings schema, tables, migrations, `Normaliser`, `Extractor`, `Renderer`, `LoopbackFetcher` with SSRF guard, all 22 assessments, `Scorer`, REST routes for pages and post SEO, WP-CLI `analyse` | Gate A | 10 fixtures pass; `wp seostudio analyse` prints a breakdown; PHPStan level 6 clean |
| 2 Dashboard and editor | React dashboard, Gutenberg sidebar, classic meta box, `yoastseo` worker, previews, metadata form, live analysis of unsaved content | Phase 1 REST contract | E2E flows 1 to 4 green; Jest states covered |
| 3 Builders and jobs | Action Scheduler jobs, site audit, Divi 4 and Elementor verification on fixture sites, Divi 5 Visual Builder panel spike with a written go or no-go, nightly live fetch | Phases 1 and 2 | 300-post audit E2E green; spike report in `docs/divi5-spike.md` |
| 4 Owner mode and migration | Detector, interlock, `Head_Output`, schema graph, social tags, importers for Yoast, Rank Math and SEOPress, migration wizard | Phase 1 | Integration tests for each mode and importer; Rich Results Test passes on fixture pages |
| 5 Hardening | Security test suite, performance run on 10,000 posts, accessibility pass, i18n extraction, uninstall and export, docs | Phases 3 and 4 | Gate B: second Opus review on the code with no open Blocker or High; all section 14 layers green |
| 6 Staging and acceptance | ZIP installed on a staging site with Yoast active; the ten acceptance checks | Gate B | Gate C: owner signs off every acceptance item |
| 7 Release | Tag `v1.0.0`, GitHub release with ZIP, `docs/release-checklist.md` completed, install on the first production site in Analysis-only mode | Gate C | Live site head unchanged in Analysis-only; Owner mode switched on only after a separate explicit approval |

**One-shot strategy.** The implementer receives this document and the prompt in section 17 and is asked to complete Phases 1 to 5 in one coordinated pass with a pull request per phase, stopping only at the three gates and at any point where a decision in section 19 is still open. Section 17 lists the order of files to create so that each PR is runnable on its own.

**Effort estimate (honest).** For a strong coding agent with a reviewer: Phase 1 one to two days; Phase 2 two to three days; Phase 3 two days including the spike; Phase 4 two days; Phase 5 two days; Phase 6 one weekend of owner time. Roughly two working weeks of agent time to a releasable V1. A full Yoast Premium replacement including redirects, sitemaps and the extra schema types is the roadmap in section 2 and at least as much again.

## Risk register

The two risks most likely to bite are a `yoastseo` bundle that does not build cleanly outside Yoast's own toolchain, and boilerplate stripping that miscounts body text on unusual themes. Both have a cheap early test in Phase 1.

| # | Risk | Likelihood | Impact | Mitigation | Early signal |
| --- | --- | --- | --- | --- | --- |
| 1 | `yoastseo` 3.x does not bundle standalone (missing peer dependencies, worker build quirks) | Medium | High: engine B blocked | Day-one spike: build `worker.js` and score a fixture `Paper`; fall back to pinning the last version that builds; last resort is porting the SEO assessor alone | Phase 1 spike result |
| 2 | Boilerplate stripping over- or under-counts body text on some themes | High | Medium: wrong scores, lost trust | Selector precedence with per-site override; show the selector used in the panel; fixture set of 10 popular themes in tests | Word count mismatch in acceptance check 2 |
| 3 | Divi 5 Visual Builder panel not feasible or unstable | Medium | Low: meta box still works | Treated as a spike with a written go or no-go; never on the V1 critical path | Phase 3 spike |
| 4 | Duplicate metadata output during migration | Low | High: indexing damage | Interlock on detected constants; Owner mode blocked while a plugin is active; regression snapshot of page heads | Integration tests for each mode |
| 5 | Action Scheduler conflicts with an older bundled copy from another plugin | Medium | Medium: jobs fail silently | Use the library's version negotiation (highest version wins); test with WooCommerce installed; dashboard shows last run time | Audit never progresses |
| 6 | Shared hosting kills long requests | Medium | Medium | Batches of 25 with a configurable pause; no loops inside a request; `set_time_limit` not relied on | Batch duration in the run report |
| 7 | Wincher-style rubric judged inaccurate against Wincher's live tool | Medium | Low: it is labelled an approximation | Reference test case 1 reproduces their published 92; asterisked rules documented; Advisories separate from score | Fixture 1 |
| 8 | Licence or trademark complaint | Low | High | GPL distribution, licence files, no vendor names or marks in UI, original design, own wording | Legal review of `readme.txt` before release |
| 9 | Importer meta keys change in a vendor release | Medium | Low | Keys live in one map per importer with the vendor version they were verified against; preview step shows counts before writing | Preview counts of zero |
| 10 | Grok 4.7 implementer drifts from the spec or claims untested success | Medium | High | Section 17 prompt demands file-by-file order, CI output in every PR, and a stop at each gate; Opus review at Gates A and B | PR without CI link |
| 11 | Performance on 10,000 posts | Low | Medium | Custom tables with indexes; dashboard is one query; performance test in CI | Dashboard over 150 ms |
| 12 | Draft content exposed through the preview fetch | Low | High | Content render mode is the default for drafts; preview fetch only with a nonce and only to the owner's own host | Security tests |

## Implementation prompt for Cursor (Grok 4.7)

Paste the block below as the first message in a fresh Cursor agent session opened in an empty `wordpress-seo-studio` repository, with this document exported to Markdown and saved as `docs/SPEC.md` first. The prompt is written for a strong but literal coding model: it fixes the order of work, forbids unverified claims, and makes the model stop at the three gates.

```markdown
You are the implementation engineer for SEO Studio, a universal WordPress SEO plugin.
The complete specification is in docs/SPEC.md in this repository. Read it fully before
writing any code. It is the source of truth. Where this prompt and SPEC.md disagree,
SPEC.md wins. Where SPEC.md is silent, choose the simplest option consistent with
WordPress core conventions and write your choice in docs/DECISIONS.md.

ROLE AND STANCE
- You implement. You do not redesign the architecture, rename modules, change the
  scoring rubric, add features, or swap libraries named in SPEC.md section 3.
- You never claim a test passed, a build succeeded or a page rendered unless you ran
  the command in this session and paste its output. If you cannot run something,
  say so and mark the task incomplete.
- You never invent WordPress, Divi, Elementor or yoastseo APIs. When unsure, open the
  installed package source in node_modules or vendor and quote the function you use.
- You stop and ask only at the three gates below or when SPEC.md section 19 lists an
  open question that blocks the current file. Everything else you decide and record.

WORKING RULES
1. Work phase by phase in the order of SPEC.md section 15. One git branch and one
   pull request per phase: phase-1-foundation, phase-2-ui, phase-3-jobs-builders,
   phase-4-owner-migration, phase-5-hardening.
2. Inside a phase, create files in the order listed under FILE ORDER below. Each
   file must be complete and syntactically valid before you move on. No TODO stubs.
3. Write the test for a class before or with the class. A phase PR must include its
   tests from SPEC.md section 14 and the CI output showing them green.
4. Run these after every few files and paste the output: composer run lint,
   composer run analyse (PHPStan level 6), composer run test, npm run lint,
   npm run test, npm run build. Fix failures before continuing.
5. Keep docs/scoring.md, docs/architecture.md and readme.txt current in the same PR
   as the code they describe.
6. Commit messages follow Conventional Commits. Commit after every green test run.
7. Nothing in the repo may reference a specific website, domain, company, industry
   or page count. Fixture data uses example.test domains and lorem-style content.
8. PHP strict_types=1 in every file, namespace SEOStudio\, PSR-4, WordPress Coding
   Standards. TypeScript for all JS. British English in UI strings, all through __().

GATES (stop, summarise what was done, list CI links, wait for a reply)
- Gate A is already passed when you receive this prompt.
- Gate B: after the phase-5 PR is green. Do not start Phase 6.
- Gate C: owner acceptance on staging. You do not deploy to any site.

FILE ORDER
Phase 1
  composer.json, package.json, .editorconfig, phpcs.xml, phpstan.neon,
  .wp-env.json, .github/workflows/ci.yml
  seo-studio.php, uninstall.php, readme.txt
  src/Core/Plugin.php, Settings.php, Capabilities.php, Upgrader.php
  src/Storage/Tables.php, Migrations/Migration_001_Initial.php,
    PagesRepository.php, ScoresRepository.php, KeyphrasesIndex.php, AuditRunsRepository.php
  src/Content/FunctionWords/en.php, Normaliser.php (in Scoring/OnPage), PageModel.php,
    Extractor.php, Renderer.php, LoopbackFetcher.php
  src/Scoring/OnPage/Assessment.php (interface), AssessmentResult.php, then one class
    per assessment in src/Scoring/OnPage/assessments/ in the order of SPEC.md section 6,
    then Scorer.php, Advisories.php
  tests/php/Unit/ for Normaliser, Extractor, each assessment, Scorer with the 10 fixtures
    in tests/fixtures/onpage/*.json
  src/Rest/PagesController.php, PostSeoController.php, KeyphrasesController.php,
    tests/php/Integration/Rest/*
  src/Cli/Commands.php (analyse, detect, openapi)
Phase 2
  assets/src/shared/api.ts, shared/ui/* components with Jest tests
  assets/src/analysis/worker.ts, paper.ts, results.tsx (yoastseo bundle; build it FIRST
    and score a fixture Paper; if the package does not build, stop and report exactly
    which import fails before trying alternatives)
  assets/src/editor/sidebar.tsx, metabox.tsx; src/Admin/Menu.php, Assets.php, MetaBox.php
  assets/src/dashboard/*; src/Rest/AuditsController.php stub returning 501 until Phase 3
  tests/e2e/ flows 1 to 4
Phase 3
  src/Jobs/Scheduler.php, AnalysePost.php, AuditBatch.php, FetchLive.php;
    AuditsController.php real implementation
  src/Integrations/Builders/GutenbergAdapter.php, DiviAdapter.php, ElementorAdapter.php
  docs/divi5-spike.md with a reproducible test and a go or no-go recommendation
  tests/e2e/ flow 5 with 300 generated posts
Phase 4
  src/Integrations/Detector.php, Importers/Yoast.php, RankMath.php, SeoPress.php
  src/Output/HeadOutput.php, TitleFilter.php, RobotsFilter.php, SocialTags.php, SchemaGraph.php
  src/Rest/SettingsController.php, MigrationsController.php; assets/src/dashboard/settings/*
  tests/php/Integration/Output/*, Importers/*, Detector/*; tests/e2e/ flow 6
Phase 5
  tests/php/Security/*, tests/regression/snapshot.php, tests/perf/*, axe in Playwright
  languages/seo-studio.pot, docs/openapi.json, docs/release-checklist.md, docs/rollback.md
  .github/workflows/release.yml

DEFINITION OF DONE FOR EACH PHASE PR
- All tests in SPEC.md section 14 for that phase exist and pass in CI (link the run).
- PHPCS, PHPStan level 6, ESLint and TypeScript are clean.
- docs updated; readme.txt changelog entry added.
- A short PR description: what was built, what was decided (link docs/DECISIONS.md),
  what was NOT done and why, and the exact commands a reviewer runs to verify.

START NOW with Phase 1. Your first message back is a short plan listing the files you
will create for Phase 1 in order, then begin creating them.
```

## Adversarial review prompt for Claude Opus

Run this twice: at Gate A against this document alone, and at Gate B against the phase-5 code with this document attached. Paste the block as the first message of a fresh Claude Code session using Opus, with `docs/SPEC.md` (and at Gate B, the repository) available to it. The reviewer must not have seen the architect's reasoning, only the artefacts.

```markdown
You are an independent, adversarial technical reviewer. You did not write any of the
material you are reviewing and you have no stake in it being approved. Your job is to
find what will break, what is wrong, what is missing and what is unsafe. Agreement is
not a finding. If you find nothing in an area, say so in one line and move on.

MATERIAL
- docs/SPEC.md: the architecture and build specification for SEO Studio, a universal
  WordPress SEO plugin.
- At Gate B only: the repository on branch phase-5-hardening, including CI results.

METHOD
1. Read SPEC.md end to end before writing anything.
2. For every factual claim about an external system, verify it against the primary
   source available to you (installed package source, official documentation you can
   open, WordPress core code). Quote the line that confirms or contradicts the claim.
   Where you cannot verify, say UNVERIFIED and what evidence would settle it.
3. At Gate B, run the test suite yourself and read the CI logs. Do not trust the PR
   description. Open at least ten source files chosen by you, including every file
   under src/Output, src/Content/LoopbackFetcher.php and src/Rest.

AREAS TO ATTACK (cover every one)
- yoastseo package: does version 3.x build standalone, which assessors does it export,
  is the licence as stated, are Premium-only assessments really in the open package?
- Wincher-style rubric: do the 22 assessments sum to 100, does fixture 1 reproduce 92,
  are the asterisked approximations labelled in the UI, can the score be gamed by
  stuffing in a way the Advisories do not catch?
- Content pipeline: does in-process the_content rendering work for Divi 5 drafts, for
  Elementor, for posts using blocks with server-side render callbacks that depend on
  the global query? What breaks when a theme hooks the_content expecting a front-end
  request?
- SSRF and fetch: enumerate bypasses of the host allow-list (DNS rebinding, IPv6
  literals, redirects, userinfo in URL, trailing dots, mixed case, port tricks).
- Draft privacy: can any role read another user's draft content or scores via REST?
- Owner mode: find any path where two components output the same tag; check the
  pre_get_document_title and wp_robots filter priorities against Yoast, Rank Math,
  SEOPress and common themes.
- Importers: are the meta keys current for each vendor's latest release? Name the
  version you checked.
- Action Scheduler: version conflicts with other bundled copies, behaviour when WP-Cron
  is disabled, what happens when a batch throws.
- Data model: index coverage for the dashboard query at 10,000 rows; migration safety
  on upgrade and downgrade; uninstall behaviour.
- REST: every route's permission_callback, nonce handling, input validation, error
  codes, rate limiting; any route reachable by subscribers.
- UI: accessibility of custom components, behaviour at 360 px, every empty and error
  state in SPEC.md section 12.
- Licensing and trademark: any vendor name, logo, wording or layout that could be
  mistaken for Yoast or Wincher.
- Universality: any hardcoded domain, path, industry, page count or environment
  assumption anywhere in spec or code.
- Scope creep or omission: features in SPEC.md section 2 that the code does not deliver,
  or code that delivers things the spec did not ask for.
- Process: does the Cursor prompt in section 17 leave room for the implementer to
  claim success without evidence?

OUTPUT FORMAT (exactly this, nothing else)
# Review log: <Gate A or Gate B>, <date>
## Summary
Two sentences: overall verdict and the single most important finding.
## Findings
A table with columns: ID, Severity (BLOCKER, HIGH, MEDIUM, LOW), Area, Finding,
Evidence (quote or file:line), Recommended fix, Verification (how to confirm the fix).
One row per finding. BLOCKER = must not proceed; HIGH = must fix before the gate;
MEDIUM = fix before release; LOW = note.
## Unverified claims
Bullet list of claims you could not check and what would settle each.
## Areas with no findings
One line per area from the list above where you found nothing.
## Recommendation
PASS, PASS WITH CONDITIONS (list them), or FAIL.

RULES
- No praise, no restating the spec, no suggestions outside the areas above unless they
  are BLOCKER or HIGH.
- Every HIGH and BLOCKER carries evidence; a finding without evidence is downgraded to
  LOW and marked SPECULATIVE.
- Do not propose a different architecture. Review the one in front of you.
- Do not edit any file. Review only.
```

**Handling the log.** The architect resolves every BLOCKER and HIGH by editing this document (Gate A) or opening fix PRs (Gate B), then appends a Resolution column to the log and files it as `docs/reviews/gate-a.md` or `gate-b.md`. The gate passes when the reviewer, given the resolved log, returns PASS or PASS WITH CONDITIONS and the conditions are tracked as issues.

## Assumptions log and open questions

Nothing below blocks Phase 1. Items marked Owner need a reply before the phase named; items marked Verify are for the implementer to confirm in code and record in `docs/DECISIONS.md`.

| # | Item | Type | Current assumption | Needed by |
| --- | --- | --- | --- | --- |
| 1 | `yoastseo` 3.x builds standalone with webpack and exports the SEO, readability and inclusive-language assessors | Verify | Yes, based on the package README and published scoring docs; not yet built in this project | Phase 2, day 1 |
| 2 | Divi 5 stores page content as native blocks rendered through `the_content` | Verify | Yes, based on third-party developer notes; confirm on an installed Divi 5 site and record the version | Phase 3 |
| 3 | Divi 5 Visual Builder accepts a third-party React panel | Verify | Feasible (custom modals exist in Elegant Themes' own dev tool); scope and stability unknown | Phase 3 spike |
| 4 | Importer meta keys for Yoast, Rank Math, SEOPress | Verify | Keys in section 4 are from current public code; re-check against installed versions | Phase 4 |
| 5 | Wincher's unpublished step values (title length steps, position decay, partial credits) | Assumption | Our asterisked steps in section 6; labelled approximations in the UI | None; revisit if users report divergence |
| 6 | Plugin display name | Owner | SEO Studio (working name); check availability before any public listing | Phase 7 |
| 7 | Public distribution on WordPress.org | Owner | Private ZIP first; the code already meets the directory's GPL and security rules so listing stays open | Phase 7 |
| 8 | Supported languages at launch | Owner | English analysis with the English function-word list; other `yoastseo` researchers loaded by locale but untested | Phase 5 |
| 9 | Minimum PHP and WordPress versions | Owner | PHP 8.1, WordPress 6.4 | Phase 1 (change requires a different HTML parser) |
| 10 | Taxonomy archive pages and the home page as analysable objects | Assumption | Home page yes in V1; term archives in V1.1 | Phase 2 |
| 11 | WooCommerce product pages | Assumption | Treated as posts; the `yoastseo` product-page assessor is not enabled in V1 | Phase 2 |
| 12 | First production install | Owner | Analysis-only on the first site; Owner mode only after explicit approval and the regression snapshot | Phase 7 |

**Changes since the previous architecture note.** Background jobs moved from deferred to V1. Owner mode no longer assumes any particular existing metadata source; detection decides. Scope widened from one site to a universal plugin, which added the detector, importers, builder adapters and the scale targets in section 8.
