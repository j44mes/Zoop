# Changelog

Development versions advance at approved milestones. Production releases use a normal `X.Y.Z` version. Pull requests #15 through #20 were unreleased development on 0.5.0. They are not retrospectively version-bumped, and this file does not treat them as shipped releases.

The plugin header, `SEOSTUDIO_VERSION`, and the readme stable tag move together. The on-page ruleset and the evidence version stay independent of that string. A release zip is created only when a future tag runs the release workflow.

## 0.6.0-beta.1

Prerelease. The downloadable zip is attached when the tag `v0.6.0-beta.1` is pushed. This branch does not create that tag.

- Accept `X.Y.Z-beta.N` in the version contract. A beta build shows its checked-out commit. A stable `X.Y.Z` version does not. The tag, plugin header, version constant, and readme stable tag must agree before a zip is named.
- Package the release zip from an allowlist. Development Composer packages, tests, `dist`, `.git`, and `.env` files are refused. The yoastseo GPL licence is included. The zip directory is `seo-studio`, so an upgrade replaces the existing plugin.
- On-page ruleset 2 treats a regular English plural as the same keyphrase token for URL checks and overlap grouping. Previously definitive ruleset 1 scores are marked out of date and their history is kept.
- A direct upgrade from 0.5.0 keeps the old score rows. Those scores were not evidence-ready, so they are not described as previously verified. Zoop shows one explanation of how many pages the current user can still send for a published-page check. Dismissing it hides it for that user. A fresh install does not show it.

## 0.6.0-dev.1

Release identity and score currency.

- Show `Zoop 0.6.0-dev.1` in Dashboard and Settings. A development build stamped from the checked-out commit appends that commit. A release version shows the version alone.
- Introduce on-page ruleset 1. Current scores with no ruleset, including evidence-version 2 scores, are marked out of date. Score history, published snapshots, and link evidence are kept.
- Later ruleset changes compare the applied-ruleset option with the code and do not require another schema bump. The option advances only after the retirement query succeeds.
- A content-only analysis can store a historical score and cannot restore a definitive score. A matching live published-page check can.
- Architecture withholds orphan totals and reachability totals until published-link coverage is complete, and names the evidence that is actually missing.
- Hide the unverified-link heading when that list is empty.
- Treat Twenty Twenty-Five previous/next navigation as template-only. An ordinary editorial link stays contextual.

## Unreleased development included from 0.5.0

### #20 Correct site intelligence navigation, destinations, and boundaries

- Count assigned classic menus and referenced block navigation, and keep unassigned menus out unless the published HTML shows them.
- Classify local WordPress destinations without fetching them. Unknown addresses are unverified, not confirmed broken links.
- Treat the public site boundary as `home_url()`, including a subdirectory. An untrusted www or non-www twin is not incoming evidence until an administrator trusts it.

### #19 Explain on-page scores from fresh published evidence

- A definitive score requires a current result, fresh published evidence, and a denominator above zero.
- All-clear is limited to the viewer's authorised pages, and only when every one of those pages is current and definitive.

### #18 Score on-page checks from explicit evidence and verify rendered HTML

- On-page checks record the evidence they used. Rendered HTML is verified separately from the stored content analysis.

### #17 Save SEO titles as proposals and verify observed HTML

- SEO titles are saved as proposals. Observed HTML confirms what was published.

### #16 Add a search-appearance proposal before anything is published

- Search appearance can be proposed before Zoop publishes metadata.

### #15 Keep phrase overlap as an observation, not an urgent problem

- Phrase overlap stays an observation. It is not promoted to an urgent problem on its own.
