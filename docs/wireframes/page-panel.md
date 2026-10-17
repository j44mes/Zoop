# Page panel wireframe

The same panel is the block editor sidebar and the classic meta box. Unsaved editor content is marked Live and is not written until Analyse now.

```
+------------------------------------------+
| SEO                                      |
| Keyphrase [ rank tracker____________ ]   |
| Related   [ + Add ]                      |
| Synonyms  [ tracker, ranking ]           |
+------------------------------------------+
| On-page              Content analysis    |
| ( 82 / 100 )         SEO: Good           |
|                      Readability: Ok     |
| [rank tracker] [related phrase]          |
+------------------------------------------+
| Title                                                6 / 10 pts |
|   Keyphrase at the start                             Good       |
|   Why: A keyphrase at the start is what a result shows first.   |
| Body                                                 22 / 30    |
|   v Keyphrase in the body                            Poor       |
|     Move the keyphrase into the first paragraph.                |
+------------------------------------------+
| Google                                                   Mobile |
| example.test/welcome                                         |
| Welcome rank tracker                                         |
| A short description of the page.                             |
+------------------------------------------+
| Social                                                   |
| [ image ]  Welcome rank tracker                          |
|            A short description.                          |
+------------------------------------------+
| SEO title  [ Welcome %%sep%% %%sitename%% ]  42 px     |
|            chips: title  sitename  sep  excerpt  category |
| Description [ A short description.          ]  24 chars |
| Canonical   [ https://example.test/welcome  ]          |
| Robots      [x] index   [x] follow                     |
| Social title [ ]  Social description [ ]  Image [ ]    |
+------------------------------------------+
| [Analyse now]  [Verify live]                         |
| Render: content. Selector: main.                     |
| Scores use the metadata a crawler would receive.     |
+------------------------------------------+
```

States:

```
Not yet analysed
  Both cards say Not scored. Analyse now is the primary button.

Live, unsaved
  Badge: Live
  Scores follow the editor text. Nothing is stored until Analyse now.

Fetch blocked
  Verify live shows the reason: off-host, private address, or oversized.
  The on-page card keeps the last content-mode score.

Worker unavailable
  Content analysis says the worker did not load.
  The on-page score stays visible. Saving the post still works.
```
