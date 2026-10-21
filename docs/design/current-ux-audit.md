# Current interface audit

Inspected on `main` before this redesign.

## Screens

- Dashboard at `wp-admin/admin.php?page=seostudio`, mounted on `#seostudio-dashboard`.
- Settings, swapped in by a button on that same screen.
- Block editor sidebar, plugin `seostudio`, and the classic meta box. Both render `Panel`.

## What is difficult

- The menu and heading say “SEO Studio” while the plugin name is Zoop.
- “Pages analysed” is the count of stored rows, including rows with no on-page score. The average is a percentage of points, shown next to point scores.
- The main finding column prints the assessment id, such as `title.entire_keyphrase`.
- The editor sidebar is one long form: phrase fields, three score presentations, every assessment, both previews, and every metadata field. Analyse now is at the bottom.
- The metadata ownership note is at the bottom of the sidebar.
- A website audit runs the PHP on-page scorer only. The dashboard still shows SEO and readability columns as if the audit filled them.
- Batch size and pause are stored settings with no fields. Robots defaults, the function-word locale, the post content selector, and the default social image id are also stored and not shown.

## What already works and must stay

- Search, rating, score range, keyphrase, sort, and server pagination on `GET /seostudio/v1/pages`.
- Audit start, read, and cancel.
- Unsaved preview, saved analysis, and published-page check.
- Related phrases, synonyms, SEO title, description, canonical, robots, social text, video URL, and news article.
- Analysis-only versus Owner mode, including the refusal while another SEO plugin is active.
- Import preview and import start.
