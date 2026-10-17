# Feature preservation

| Capability | Before | After |
| --- | --- | --- |
| Page list, search, filters, sort, pagination | Dashboard | Dashboard table, same REST query |
| Stored-row count | Labelled “Pages analysed” | Labelled “Stored”. `summary.analysed` is unchanged |
| Scored, unscored, and failed counts | Not shown | Added to the same aggregate query |
| On-page, SEO, and readability columns | Dashboard | Kept. SEO and readability are labelled as editor results |
| Site audit start | “Re-analyse all” | “Run website audit”, same `POST /audits` |
| Audit progress and cancel | Progress after queue | Polled from `GET /audits/{id}`, cancel when queued or running |
| Target phrase, related phrases, synonyms | Sidebar, always visible | Phrase visible. Related phrases and synonyms in “More phrases” |
| Analyse and live check | Bottom of the sidebar | “Analyse page” and “Check published page” |
| Unsaved preview | Badge | Same badge and test id |
| Assessments | Id as the summary | Sentence first. `data-assessment` kept. Id and points inside |
| Google and social previews | Always visible | Inside “Search appearance” and “Social appearance” |
| SEO title, description, canonical, robots, social text, video, news | Always visible | Inside those sections |
| Metadata ownership note | Bottom | Top of the sidebar. Same test id and Analysis-only sentence |
| Settings: mode, home title, home description, separator, local business, uninstall | Settings | Grouped. Uninstall requires a second confirmation |
| Batch size, pause, locale, default robots, post selector, social image id | Stored, mostly hidden | Shown in Settings |
| Import preview and start | Settings | “Import” section |
| Full page record | No screen | Admin view `page=seostudio&view=page&post={id}` using existing page and SEO routes |
| Scoring weights and assessment ids | Engine | Unchanged |
| `seostudio` storage, routes, and meta keys | Unchanged | Unchanged |
