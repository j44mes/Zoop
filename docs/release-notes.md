# Zoop 0.6.0-beta.1

Prerelease for installation testing. This is not a production release.

## Install

1. Download `seo-studio-0.6.0-beta.1.zip` from this release. Check it against `SHA256SUMS`.
2. In WordPress, open Plugins → Add New Plugin → Upload Plugin, and choose that zip.
3. Activate Zoop. It starts in Analysis-only mode and does not print meta tags.

The plugin directory is `seo-studio`. Uploading this zip over an existing Zoop install replaces that directory. It does not create a second copy.

Do not install a CI artifact or a checkout in place of this zip.

## What this beta changes

- A definitive on-page score is shown only after a fresh published-page check. A content check is labelled as content checked, not as a verified SEO score.
- Regular English singular and plural keyphrases match. Older current scores are marked out of date and their history is kept.
- An upgrade from Zoop 0.5.0 keeps the old scores. They were not previously verified, so Zoop says how many pages still need a published-page check instead of calling them verified. The explanation is limited to pages the current user can edit, and that user can dismiss it.
- Search titles and descriptions are saved as proposals until Zoop is allowed to publish them. Analysis-only mode does not print tags while Yoast SEO, Rank Math, SEOPress, All in One SEO, or The SEO Framework is active.
- Architecture, overlap, topics, and priorities describe the evidence Zoop actually has. Shared phrases are something to investigate, not established cannibalisation.
- The zip is built from an allowlist, stamped with the tagged commit, and includes the bundled yoastseo licence.

## Not in this beta

- No batch published-page fetch.
- No Search Console OAuth. A CSV import is included.
- No redirects, AI features, or agency dashboard.

## Rollback

Deactivate Zoop, delete `wp-content/plugins/seo-studio`, and upload the previous zip if you want to go back. Deactivation stops Zoop's front-end tags and cancels its queued jobs. It does not delete post content or another plugin's meta. Tables remain unless uninstall is set to delete Zoop's data.
