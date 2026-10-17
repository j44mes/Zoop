# Release checklist

Do not tag a release from a coding session. Gate B (the second review) and Gate C (owner sign-off on a staging site) come first. This list is what that later tag has to satisfy.

1. `composer lint`, `composer analyse`, and `composer test` are clean on PHP 8.1, 8.2, and 8.3.
2. The WordPress integration suite is clean, including the audit cursor, Owner mode, and the three importers.
3. `npm run lint`, `npm test`, and `npm run build` are clean. Playwright flows 1 to 6 are green in CI.
4. `readme.txt` stable tag matches `SEOSTUDIO_VERSION`. The changelog names what changed.
5. `languages/seo-studio.pot` is updated for new user-facing strings.
6. `docs/openapi.json` matches the routes `wp seostudio openapi` prints.
7. The plugin is in Analysis-only mode in the zip. Owner mode is a setting, not the default.
8. No `.env`, credentials, or production content is in the tree.
9. After the Release workflow is on `main`, run **Actions → Release** from `main` and type `publish` in the confirm field. The workflow refuses unless Gate B is recorded in `docs/reviews/gate-b.md`, the commit was merged into `main` with an approving review from someone other than the pull request author, and the required CI checks are green. It then builds `zoop-<version>.zip`, installs that zip twice on a clean WordPress site, and attaches that same verified file to a draft release. Review the draft, then publish it. The WordPress upload is that release asset (`zoop-<version>.zip`), not Code → Download ZIP and not the Package workflow's Actions artifact. Do not install that zip on a live site until the owner asks.
