# Rollback

Deactivating Zoop removes its front-end tags on the next request and cancels pending jobs in the `seostudio` Action Scheduler group. Post content, slugs, and other plugins' meta are not modified.

## To the previous zip

1. Deactivate Zoop.
2. Delete the current plugin directory, or upload the previous zip over it.
3. Activate the previous copy.

Migrations are additive. Reinstalling an older zip keeps the custom tables. A newer `schema_version` is left as stored; the older code does not drop columns.

## What each development version added

| Version | Data |
| --- | --- |
| 0.1.0 | Tables `seostudio_pages`, `seostudio_scores`, `seostudio_keyphrases`, `seostudio_audit_runs`. Post meta `_seostudio_*` for the on-page fields. Option `seostudio_settings`. |
| 0.2.0 | No new tables. Content-analysis rows use the existing scores table (`engine` `seo` and `readability`). |
| 0.5.0 | No new tables. Settings gain `local_business`. Post meta gains `_seostudio_video_url` and `_seostudio_is_news`. Action Scheduler rows in group `seostudio`. |
| 0.6.0-beta.1 | No new tables or columns. Schema marker 7. On-page ruleset 2. Current ruleset 1 scores are marked out of date. Score history remains. |
| 0.6.0-dev.1 | No new tables or columns. Schema marker 7. Option `seostudio_applied_onpage_ruleset`. Current on-page caches without the current ruleset are marked out of date. Score history, published snapshots, and link evidence remain. |

## Uninstall

`uninstall_delete_data` false removes the options and the `seostudio` scheduled actions, and leaves tables and meta. True also drops the four tables and the `_seostudio_*` meta keys. The import never writes Yoast, Rank Math, or SEOPress meta, so that data is still the source plugin's rollback.
