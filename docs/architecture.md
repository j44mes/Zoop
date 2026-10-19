# Architecture

Zoop is a WordPress plugin. The user-facing name is Zoop. The PHP namespace, text domain, options, and REST namespace stay `SEOStudio` / `seo-studio`, which is the name in the build specification.

Phase 1 is the foundation and the on-page engine. It does not include the editor UI, the Yoast-style JavaScript engine, background workers, or front-end meta output.

## Pipeline

`Renderer` turns a post into HTML through `the_content` and marks the page `render_mode = content`. Code measurements stay null, so the code checks are `not_checked`.

`LoopbackFetcher` downloads the public URL of the same site and marks the page `render_mode = live`. `FetchPolicy` allows only that site's scheme, host, and port. It rejects userinfo, non-HTTP URLs, IP literals, `localhost`, `.local`, `.internal`, and any DNS answer that is private, reserved, or empty. Redirects are followed manually, at most two hops, and each hop must pass the same check. The body is capped at 2 MB.

`Extractor` reads the HTML with `DOMDocument`. In content mode the root is `body`. In live mode it uses an optional selector, then `main`, `article`, `[role=main]`, then the densest `div` or `section` that is not inside navigation, header, footer, or aside. Script, style, and hidden nodes are removed before the text is read. Images are collected from the whole document.

`Scorer` runs the 22 assessments in `docs/scoring.md`. `seostudio/assessments` can add or remove assessments. A result with status `not_checked` is omitted from the earned total and the denominator.

## Storage

Activation runs the initial migration. Tables, all prefixed with `seostudio_`:

- `pages` — one row per post, including the extracted model
- `scores` — one row per scoring run
- `keyphrases` — which posts use a keyphrase
- `audit_runs` — one row per audit, import, or fetch run, with `cursor_id`

Post inputs live in `_seostudio_*` meta. Settings live in the `seostudio_settings` option. Uninstall drops tables and meta only when `uninstall_delete_data` is true.

## HTTP and CLI

REST routes are under `seostudio/v1`, require a logged-in user, and are not public.

- `GET /pages/{page_id}`
- `POST /pages/{page_id}/analyse` with a `keyphrase` argument
- `GET` and `PUT /posts/{post_id}/seo`
- `GET /keyphrases/usage`

`wp seostudio analyse <post_id> <keyphrase>` renders the post, prints the category breakdown, and stores the page and score. `wp seostudio detect` reports whether Yoast, Rank Math, SEOPress, AIOSEO, or The SEO Framework constants are defined. `wp seostudio openapi` prints the Phase 1 route list.

`POST /pages/{page_id}/analyse` re-scores a page row that already exists. The CLI command creates that row.

## Admin

The dashboard is the SEO Studio menu. The block editor gets a sidebar, and other edit screens get a classic meta box. Both mount the same panel. `POST /posts/{id}/model` previews unsaved content and an on-page score without writing a row. Analyse now stores the on-page score, then the browser worker stores the SEO and readability ratings.

`yoastseo` 3.6.0 runs in `assets/build/worker.js`. If that file fails to load, the panel keeps the on-page score and says the worker is unavailable.

## Jobs

Action Scheduler 3.9.3 is loaded from `vendor/woocommerce/action-scheduler/action-scheduler.php` before `plugins_loaded`, so its own bootstrap still runs. Jobs use the group `seostudio`. `seostudio_audit_batch` scores 25 posts (or `batch_size`) ordered by post ID and stores the cursor on the run. It re-queues itself instead of looping the whole site in one request. Integration tests define `SEOSTUDIO_JOBS_SYNC` and run the same handlers inline.

`seostudio_analyse_post` is scheduled from `save_post` only in admin, REST, cron, or WP-CLI, for every public post type except attachments. A front-end view does not analyse. `seostudio_fetch_live` walks published public content in batches of five, including pages fetched on an earlier night, and stores a fresh on-page score when the post has a focus keyphrase. A full batch queues the next cursor. When `DISABLE_WP_CRON` is set, the dashboard shows `wp action-scheduler run --group=seostudio` and the nightly path falls back to one synchronous fetch. The editor's Verify live button stays the synchronous same-host fetch from Phase 1. A later content analysis keeps the fetched code measurements.

`wp seostudio audit` starts a run. `POST /audits` returns 202, 409 when a run is already queued or running, and 429 when the same user starts another within ten minutes. The synchronous test mode skips that rate limit so the suite can start a run and then assert the conflict separately.

## Owner mode

`HeadOutput` prints tags only when the mode is `owner` and `Detector::first()` is null. Activating another SEO plugin suspends output and shows an admin warning. Nothing is deleted. The graph is `WebSite` and `Organization` on the home page, `WebPage` or `Article` plus `BreadcrumbList` on a post, and the V2 nodes `LocalBusiness` (when the local setting is enabled), `VideoObject` (when `_seostudio_video_url` is set), and `NewsArticle` (when `_seostudio_is_news` is set, instead of `Article`).

Importers for Yoast, Rank Math, and SEOPress copy into `_seostudio_*` keys. They expand the common title variables and leave unknown tokens in place. They never write the source keys and never deactivate the other plugin.

## Builders

`GutenbergAdapter`, `DiviAdapter`, and `ElementorAdapter` report how content is reached. The Divi 5 Visual Builder panel is a no-go in `docs/divi5-spike.md` because Divi is not installed here. The classic meta box remains the panel for Divi and Elementor.

## Site architecture

`GET /seostudio/v1/architecture` builds a read-only link graph for administrators. The inventory is every published public post except attachments. Contextual links come from the stored page model. Links inside the content root are contextual. Links outside it are template links. Content mode prefers `main` or `article` and otherwise uses the body, and `the_content` does not include the theme header or footer. Navigation menus are template links even when a page has only been analysed from its content. A repeated link on the same page counts once as a referring page and again in the occurrence total.

Orphan pages are listed only when an administrator can see every published page and every one of those pages has a stored analysis. Otherwise the report keeps the orphan count empty and says why. A weakly linked page has exactly one unique contextual referring page. A page linked only from the template or a menu is reported separately. Crawl depth is a breadth-first walk from the front page, and from navigation targets when the front page is the blog index. An unresolved internal address does not match a published page. Zoop does not request it. System addresses such as `wp-admin` and feeds are ignored. The report does not edit content, insert links, or claim that a search engine has indexed anything.

## Search appearance

`GET` and `PUT /seostudio/v1/posts/{post_id}/appearance` keep a proposed SEO title and meta description apart from the values Zoop prints. The response separates a title resolved from saved settings from HTML observed on the published page. Saving a proposal does not change the published page. `POST .../appearance/apply` copies a confirmed proposal into the fields that control the page: the post title and description, or the homepage title and description when the page is the static front page. The request must repeat the stored values the person confirmed. If those values changed, the write is rejected. Apply then checks the published HTML. A failed fetch, or HTML that does not match, is not described as a verified publication. An empty expected description is still compared with the description in the HTML. `POST .../appearance/restore` puts the previous values back under the same rules. When another plugin prints the tags, or the homepage settings are outside the current user's control, the response offers no apply action.

## Topics

`GET` and `PUT /seostudio/v1/posts/{post_id}/topic` suggest a primary topic, page role, search intent, parent topic, and up to five supporting phrases. The primary topic uses the saved target phrase, then the first heading, then the title. Intent is a word list, not Search Console data. Role uses the link graph: a page close to the front that links to three or more pages from its content is a hub, a page three or more clicks away is supporting, and contact-style wording is utility. The parent topic is the closest contextual referring page's saved topic, or that page's own suggestion. A saved field replaces the suggestion. Clearing it uses the suggestion again. Nothing is rewritten in the post content.

## Overlap

`GET /seostudio/v1/overlap` compares published pages and keeps two judgements apart. Observation confidence says how sure Zoop is that a phrase, topic, or title wording matches. Conclusion confidence says whether that match is a competition problem. A shared target phrase is a confirmed observation. A topic copied from that phrase is the same observation, not a second one. When pages in one topic group come from different sources, each source is recorded with the pages that supplied it. Saving a suggested topic confirms the editor's choice and keeps the original source, so an accepted heading is not a new observation. A topic that matches neither the target phrase, the heading, nor the title is an editorial observation. It still does not prove the pages compete. Unknown intent is not agreement. A matching intent, including one an editor confirmed, supports a closer look and does not prove the pages compete. The conclusion for these findings is that cannibalisation is not established. Shared title words stay a weak observation. The report does not edit pages.

## Search Console

`POST /seostudio/v1/search-console` stores a performance export. The file needs query, clicks, and impressions columns. Page and position are kept when they are present. At most 5,000 query rows are stored, and a new import replaces the previous one. Zoop does not use OAuth and does not connect to Search Console. The summary says the rows came from a file, and it counts how many name a usable page address and how many do not.

## Priorities

`GET /seostudio/v1/recommendations` orders stored evidence for review. Unresolved addresses, and orphan or weakly linked pages, stay ahead of phrase overlap. A shared phrase or shared topic is a routine investigation, not priority 1. Imported impressions can move a weakly linked page up the list, and the wording says the file has no dates or segmentation, so the count is not verified commercial value and not lost traffic. A query-only file is not attached to URLs. The notes count imported rows, rows with a usable page address, and rows without one. Orphans stay off the list until the architecture crawl is complete. Shared title words stay on the Overlap screen. The list does not edit pages.
