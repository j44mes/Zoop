# Release readiness

Zoop `0.6.0-dev.1` is a development build. Passing this branch's checks does not make it a release. Do not tag, publish a zip, or install it on a production site from this work.

`Zoop 0.6` in the scoring-rules notice names the product line. It does not mean that `0.6.0` has been released.

## Still open

- No production tag and no release zip for this development line.
- L2 is an unresolved, independent scoring investigation. It is not part of this change and has no fix here.
- Published Evidence Recovery is specified in `docs/published-evidence-recovery.md` only. There is no batch live fetch.
- A TidyQuo staging install has not been run.
- An explicit production decision has not been made.

## After review

1. Investigate and correct L2.
2. Run integrated release-candidate QA on the result.
3. Install that candidate on TidyQuo staging.
4. Make an explicit production decision.
5. Build Published Evidence Recovery, then Fix & Verify.

## What this branch does check

The upgrade notice appears only after a ruleset retirement that included previously definitive scores, and only for pages that still need that check and that the current user can edit. Content checks keep their own engines and do not display a definitive on-page score. The architecture screen uses the server's published-check count.

## Observed 0.5.0 zip upgrade

The published asset `seo-studio-0.5.0.zip` matches sha256 `035e677eb693c4d0006d7cd63bd5be248c55559a5fb65a1d1bdc85e4737416e2`. Its header is version 0.5.0 and a fresh activation stores schema 3.

Booting the current development code over that database:

- Moves the schema to 7 and records on-page ruleset 1.
- Leaves the stored Yoast title, Yoast description, and Zoop title meta unchanged.
- Keeps the original score row (`plugin_version` 0.5.0). A later content check adds a new score row and does not delete the old one.
- Clears the cached score. The page is "Out of date" and offers "Check published page".
- Does not show the scoring-rules notice. Schema 3 had no evidence-ready flag, so there was no previously definitive score for the ruleset retirement to count. A later ruleset retirement of evidence-ready scores does show the notice.
- A content check then says "Content checked. Needs a published-page check to score." and leaves `evidence_ready` at 0.
- With `WPSEO_VERSION` defined, Zoop does not publish metadata and reports the detected plugin as Yoast SEO.
