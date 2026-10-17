# Gate B review packet

This packet prepares the second review. It is not a passed review. The implementing agent does not grade Gate B.

The Release workflow does not read this file. It reads `docs/reviews/gate-b.md`, which the independent reviewer files after the review. That file has to contain a `Reviewer:` line and either `Verdict: PASS` or `Verdict: PASS WITH CONDITIONS`. Until it does, Actions → Release refuses to create a draft.

Phases 1 to 5 are on this branch. Phase 6 (staging acceptance with Yoast) and Phase 7 (tag `v1.0.0` and a live install) have not been started. No release tag was created.

## What a reviewer is asked to do

Run the adversarial review in `docs/SPEC.md` against this repository. Gate B is passed only when that review has no open Blocker or High. The reviewer must be someone other than the implementer.

## Proven in this workspace

- `test_an_audit_finishes_three_hundred_posts` created 300 posts and finished the audit in the synchronous test seam: 1 test, 7 assertions, under 2 seconds. Batch size 25. Failed count 0.
- Divi 5 remains a written no-go in `docs/divi5-spike.md`. The commercial plugin is not installed here.
- Owner mode, head output, schema, social tags, and the three importers have integration coverage in `tests/php/Integration/PhaseFourTest.php`.
- Security, regression, and the 10,000-row dashboard timing are in the integration job.

## In the suite, not run in this workspace

Docker is not available here, so Playwright was not executed locally. CI runs:

- Chromium creates 300 posts from the dashboard and waits until that queued audit is done. WebKit skips that flow because both browsers share one site and a second audit start is refused for ten minutes.
- axe-core checks the dashboard and the editor panel for serious and critical violations.

## Not claimed

- The 15-minute shared-host audit target.
- A public Google Rich Results test. That needs a public fixture URL.
- Installed Divi 4, Divi 5, or Elementor.
- Gate B, Gate C, or a production install.
