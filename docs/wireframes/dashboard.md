# Dashboard wireframe

Low-fidelity layout for the SEO Studio overview. The menu label is SEO Studio. The product name in the plugin header stays Zoop. This screen is reviewed before the React dashboard is treated as finished.

```
+------------------------------------------------------------------+
| SEO Studio                                          [Re-analyse] |
+------------------------------------------------------------------+
| Pages analysed   Average on-page    Red   Orange   Green         |
| 128              74 / 100           12    40       76            |
| Last audit: not available in this phase                          |
+------------------------------------------------------------------+
| Search [____________]  Post type [All v]  Rating [All v]         |
| Score [min] [max]   Has keyphrase [ ]   [Filter]                 |
+------------------------------------------------------------------+
| Title            Keyphrase     On-page   SEO   Read   Top issue  |
| ---------------- ------------- --------- ----- ------ ---------- |
| Welcome          rank tracker  ( 82 )    Grn   Org    title-pos  |
| Draft notes      (none)        ( -- )    —     —      not scored |
+------------------------------------------------------------------+
| Showing 20 of 128                              [Prev]  [Next]    |
+------------------------------------------------------------------+
```

Empty state, when nothing has been analysed:

```
+------------------------------------------------------------------+
| No pages have been analysed yet.                                 |
| Open a post and choose Analyse now, or run the first audit.      |
| The site audit queue is not available in this phase.             |
+------------------------------------------------------------------+
```

Running and error states:

```
+------------------------------------------------------------------+
| Re-analyse all                                                   |
| [==========----------]  40 of 100                                |
| [Cancel]                                                         |
+------------------------------------------------------------------+
| One row failed: fetch blocked.                        [Retry]    |
+------------------------------------------------------------------+
```

The score ring shows the number and the denominator. The SEO and readability columns are words (Good, Ok, Poor, Not scored), not colour alone. Re-analyse all calls the audits route. Until Phase 3 that route answers 501, and the screen says the queue is not available.
