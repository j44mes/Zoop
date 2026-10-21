# Remaining limits

This note records what the interface correction did not finish. The research and prototype work from the original design brief was not restarted.

## Research

`docs/design/research.md` is a short decision note. It is not a comparison of built components, and there are no three reviewable prototypes. No moderated usability study was run.

## Live check on this host

Check published page calls the existing live analysis route. On the isolated site used for these checks, the permalink is a loopback address. The fetch allow-list blocks that address, so the screen shows “Fetch target resolves to a blocked address.” The allow-list was left unchanged. A caption of “Observed on the last check of the published page.” was not produced here.

## Classic editor

The classic metabox was checked by turning the block editor off with a temporary must-use plugin on that site. The plugin is not part of this repository and was removed after the check.

## Playwright

The committed Playwright project expects wp-env at `http://localhost:8888` and includes WebKit. Docker, wp-env, and WebKit were not available. The five Chromium flows were run against the isolated site with system Chrome. The owner-mode test waits for the settings response. That response uses an encoded `rest_route` on this host, so the matcher now decodes the URL. It still matches a pretty `/wp-json/` URL. WebKit was not run.

## Not exercised here

- Yoast is not installed. The sentence that names another SEO plugin is covered by the Jest copy test, not by a browser session with Yoast active.
- A missing post the current user cannot read returns WordPress’s “You are not allowed to do that.” The permission check runs before a Zoop not-found message. The workspace shows that error and does not call the page unanalysed.
- Summary counts use the same filtered query as the table. Filtering by post type changes the Stored count. That is the existing aggregate.
- Gate B was not graded. The release workflow was not changed. Nothing was deployed, tagged, or installed on a live site.
- Title, body, and other content are still edited in the WordPress editor. The workspace only links to those controls.
