# Release readiness

Zoop `0.6.0-beta.1` is a prerelease candidate on the stacked draft branches. Passing checks does not publish a release. Do not tag, publish a zip, or install it on a production site from this work.

`Zoop 0.6` in the scoring-rules notice names the product line. It does not mean that `0.6.0` has been released.

## What the stack contains

- Pull request #22 explains content that has been checked but not scored, and shows a ruleset-retirement notice only for pages the current user can still edit.
- Pull request #23 matches regular English singular and plural keyphrases. On-page ruleset 2 retires current ruleset 1 scores and keeps their history. Overlap still describes a shared phrase as something to investigate, not established cannibalisation.
- This branch accepts `0.6.0-beta.1` and builds the zip from an allowlist. The downloadable file is the asset on the GitHub Release created by tag `v0.6.0-beta.1`. This branch does not create that tag. A pull-request artifact is not the release.

## Ruleset notice and a 0.5.0 upgrade

A direct upgrade from the released 0.5.0 package does **not** show the scoring-rules notice. Those historical scores had no evidence-ready flag, so they are not described as previously verified. The old score rows are kept. The cached score is cleared and the page is labelled out of date.

When that upgrade finds saved on-page scores, Zoop shows a separate one-time explanation: "Zoop's page checks have been updated. Your previous scores have been kept, but N pages need a new published-page check." N counts only pages that user can still edit and that still need the check. The link opens that same review list. Dismissing it hides it for that user. A fresh installation, and an upgrade with no saved scores, do not show it. It does not start a batch check.

The scoring-rules notice still appears only after a later ruleset retirement that included scores already marked definitive, and only for pages that still need that check and that the current user can edit.

## Still open before a beta install

- No production tag and no published zip.
- Published Evidence Recovery is specified in `docs/published-evidence-recovery.md` only. There is no batch live fetch.
- A TidyQuo staging install has not been run, and the saved mode on that site has not been read.
- An explicit production decision has not been made.

## After review

1. Review the stacked draft pull requests.
2. Run the resulting main commit's CI.
3. Install that candidate on TidyQuo staging.
4. Make an explicit production decision.
5. Build Published Evidence Recovery, then Fix & Verify.
