# Release readiness

Zoop `0.6.0-beta.1` is a prerelease candidate on the stacked draft branches. Passing checks does not publish a release. Do not tag, publish a zip, or install it on a production site from this work.

`Zoop 0.6` in the scoring-rules notice names the product line. It does not mean that `0.6.0` has been released.

## What the stack contains

- Pull request #22 explains content that has been checked but not scored, and shows a ruleset-retirement notice only for pages the current user can still edit.
- Pull request #23 matches regular English singular and plural keyphrases. On-page ruleset 2 retires current ruleset 1 scores and keeps their history. Overlap still describes a shared phrase as something to investigate, not established cannibalisation.
- This branch accepts `0.6.0-beta.1`, builds the zip from an allowlist, and will mark a future hyphenated tag as a prerelease. It does not create that tag.

## Ruleset notice and a 0.5.0 upgrade

A direct upgrade from the released 0.5.0 package does **not** show the one-time scoring-rules notice. Those historical scores had no evidence-ready flag, so the retirement did not have a previously definitive score to count. The old score rows are kept. The cached score is cleared and the page is labelled out of date, with Check published page as the recovery.

The notice does appear after a later ruleset retirement that included scores already marked definitive, and only for pages that still need that check and that the current user can edit.

This disagrees with a later request that every 0.5.0 upgrade show the notice. The implemented rule is the one covered by the tests: no notice unless a definitive score was retired. The Out of date list remains the recovery for a 0.5.0 upgrade.

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
