# Component plan

- `assessment-labels.ts` maps on-page ids to sentences. Unknown ids are shown with dots replaced by spaces.
- The dashboard overview reads the existing page list and settings. It does not score on open.
- `PageWorkspace` reads `view=page` and `post` on the same admin screen, then `GET /seostudio/v1/pages?object_id=` and `GET /seostudio/v1/pages/{id}`.
- The editor `Panel` keeps its state and requests. The default view is ownership, the target phrase, analyse, a short summary, and up to three findings. Everything else is in a disclosure.
- Settings groups the fields `Settings::sanitize()` already accepts. Saving with “delete data on uninstall” requires a second checkbox.
