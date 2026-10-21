# Release checklist

Do not tag a release from a coding session. Gate B (the second review) and Gate C (owner sign-off on a staging site) come first. This list is what that later tag has to satisfy.

1. `composer lint`, `composer analyse`, and `composer test` are clean on PHP 8.1, 8.2, and 8.3.
2. The WordPress integration suite is clean, including the audit cursor, Owner mode, and the three importers.
3. `npm run lint`, `npm test`, and `npm run build` are clean. Playwright flows 1 to 6 are green in CI.
4. `readme.txt` stable tag matches `SEOSTUDIO_VERSION` and the plugin header. Development versions use `X.Y.Z-dev.N` and are not production zips. A zip is attached only when a tag runs the release workflow.
5. `languages/seo-studio.pot` is updated for new user-facing strings.
6. `docs/openapi.json` matches the routes `wp seostudio openapi` prints.
7. The plugin is in Analysis-only mode in the zip. Owner mode is a setting, not the default.
8. No `.env`, credentials, or production content is in the tree.
9. Tag `vX.Y.Z`. The release workflow builds `seo-studio-X.Y.Z.zip` and attaches it. Do not install that zip on a live site until the owner asks.
