# Design research

These notes record the decisions used for the Zoop admin redesign. They are not a claim that a usability study was run. The second pass compared three page-analysis compositions in `prototypes.md` and built the summary deck.

## WordPress DataViews

`@wordpress/dataviews` can provide tables, filters, and layout switching in newer Gutenberg builds. Zoop declares WordPress 6.4 as the minimum. That release does not register DataViews as an admin script. Bundling the package would also pull private Gutenberg APIs and component CSS into every admin page that loads Zoop.

The page list already paginates on the server. The redesign keeps that query and renders a scoped table with `@wordpress/components`.

## 21st.dev

21st.dev is a useful catalogue of dashboard, table, disclosure, and empty-state patterns. Its marketplace terms restrict redistribution of component source. Zoop may be distributed as a plugin, so none of that source is copied. The interface uses WordPress components and CSS variables scoped to `.seostudio-dashboard` and `.seostudio-panel`.

Patterns taken as ideas, not as code:

- A short status row before a data table.
- One primary action per screen.
- Findings as sentences, with the technical id behind a disclosure.
- Filters that do not sit above the page title.
- Empty states that name the next action.

## Other products

Yoast and Rank Math keep the editor panel short and put the score next to the phrase. Semrush and Ahrefs use large opportunity tables that imply commercial impact. Zoop does not have ranking or traffic data, so it must not copy that framing. Linear’s compact rows and Stripe’s plain operational labels fit an admin tool better than a marketing dashboard.

## Evidence used

- Nielsen’s heuristics: visibility of status, match with the user’s language, and recognition rather than recall. Strong evidence for replacing assessment ids as the primary label.
- WCAG 2.2: status is not conveyed by colour alone, and focus remains visible. Strong evidence.
- Progressive disclosure is professional consensus, not a laboratory result for this plugin.
- The choice of a warm neutral canvas and one blue accent is design judgement.
