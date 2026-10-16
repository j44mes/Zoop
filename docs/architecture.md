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
- `audit_runs` — reserved for the Phase 3 worker

Post inputs live in `_seostudio_*` meta. Settings live in the `seostudio_settings` option. Uninstall drops tables and meta only when `uninstall_delete_data` is true.

## HTTP and CLI

REST routes are under `seostudio/v1`, require a logged-in user, and are not public.

- `GET /pages/{page_id}`
- `POST /pages/{page_id}/analyse` with a `keyphrase` argument
- `GET` and `PUT /posts/{post_id}/seo`
- `GET /keyphrases/usage`

`wp seostudio analyse <post_id> <keyphrase>` renders the post, prints the category breakdown, and stores the page and score. `wp seostudio detect` reports whether Yoast, Rank Math, SEOPress, AIOSEO, or The SEO Framework constants are defined. `wp seostudio openapi` prints the Phase 1 route list.

`POST /pages/{page_id}/analyse` re-scores a page row that already exists. The CLI command creates that row.

## Not in this phase

The dashboard, Gutenberg sidebar, classic meta box, and `yoastseo` worker are Phase 2. Action Scheduler is installed so Phase 3 can queue audits; no worker class is registered yet. Owner mode, head output, and importers are Phase 4. `detect` only reads constants.
