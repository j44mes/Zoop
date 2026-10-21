# Search intelligence

This note describes the evidence and search-ownership design. Phase 1, the reliability correction, is the only part implemented. The engine below is not built.

## What Phase 1 does

Zoop records a shared target phrase as an observation. Observation confidence can be high for that match. Conclusion confidence stays insufficient for cannibalisation. A topic copied from the same phrase is marked as the same observation, not as corroboration. When one topic group mixes saved and suggested topics, each page keeps the source that supplied its topic. Saving an unchanged suggestion records editorial confirmation and leaves the original source in place. Only a topic that does not match the target phrase, heading, or title is an editorial observation. Unknown intent is not agreement. A matching intent, including one an editor saved, supports investigation and does not prove the pages compete. Explanations refer to these pages, including when a group has more than two.

Priorities lists unresolved addresses and weak or orphan pages before those investigations. Imported impressions can change that order. The copy says the file has no dates or segmentation, so the count is not verified commercial value and not lost traffic. A file without page addresses is not attached to URLs. The import summary and the priority notes count rows imported, rows with a usable page address, and rows without one.

The 100-point on-page scorer is unchanged. Zoop does not merge, redirect, or edit pages.

## Evidence record

Later reports should use one shape for every finding:

| Field | Purpose |
| --- | --- |
| Observation | What was detected |
| Source | Editor, stored HTML, link graph, or an imported file |
| Source version | Which analysis produced it |
| Timestamp | When it was observed |
| Coverage | Which pages and search dimensions were examined |
| Dependencies | Earlier observations this finding was derived from |
| Limitations | What Zoop could not establish |
| Confidence | Strength of that specific claim |

A derived value must point at the observation it came from. It must not be counted again as independent evidence. Phase 1 stores a smaller version of this on each overlap finding: signal, source, observation confidence, what the row establishes, whether it is independent, and a sentence.

## Search ownership

The later product should answer six questions for a pair of pages.

1. What is each page supposed to achieve? The unit is a search job: topic, audience, intent, page role, and the URL that owns that job.
2. What does the main content say, once navigation and template text are set aside?
3. Do imported query and page rows, with dates and a segment, show both URLs for the same query?
4. Which situation is it: healthy differentiation, technical duplication, unclear ownership, a possible performance conflict, or insufficient evidence?
5. What is the safest next action: keep both, differentiate, strengthen links, consider consolidation, investigate, or do nothing?
6. After a person acts, did the intended change land, and did a later file show a performance change? Those two results stay separate. A ranking change is not automatically credited to the last edit.

Human decisions are stored. An unchanged case that an editor marked as different audiences is not raised again until the evidence changes.

## What the current file cannot support

The Search Console import stores query, optional page, clicks, impressions, and position. It does not store a date range, country, or device. A query-only export cannot say which URL competed with which URL. Comparable periods, and the difference between a missing row and zero impressions, belong to a later import. An authenticated Search Console connection can wait until that model exists.

## How to judge the next step

Phase 2 should be checked against reviewed page pairs, including Schools UK cases where related pages should stay separate. The measures to define first are false urgent warnings, time to understand one overlap, and whether every substantive sentence names its evidence. Those are targets, not results this note claims.
