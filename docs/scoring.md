# On-page score

Zoop scores one keyphrase at a time. The score is a sum of 22 assessments in seven categories. The maximum is 100 when every check runs. Advisories never change the number.

Normalisation, applied before matching:

1. NFKC
2. Lower case
3. Punctuation becomes spaces
4. Whitespace collapses

Content words are the tokens that are not in the English function-word list (`src/Content/FunctionWords/en.php`). The list is filterable with `seostudio/function_words`. An entire match is those content words, in order, adjacent. A partial match is at least one content word. Coverage is distinct content words found divided by distinct content words in the keyphrase. Position is the 1-based index of the entire match after leading function words are removed. Body word count is the normalised token count of `body_text`. Title and H1 length use the raw character count of the trimmed string.

| Category | Check | Max | Rule |
| --- | --- | --- | --- |
| Title (27) | Title exists | 7 | Non-empty title |
|  | Entire keyphrase | 6 | Entire match |
|  | Partial keyphrase | 2 | Partial match, including when the entire match also scores |
|  | Length | 6 | 30–60 characters: 6; 20–29 or 61–70: 4; any other non-empty title: 2; missing: 0 |
|  | Position | 6 | Position 1: 6; within the first half of the words: 4; later: 2; no entire match: 0 |
| Headings (20) | Entire keyphrase in H1 | 3 | Entire match in the first H1 |
|  | Partial keyphrase in H1 | 2 | Partial match in the first H1 |
|  | H1 length | 2 | 1–40 characters: 2; 41–60: 1; otherwise 0 |
|  | Position in H1 | 5 | Position 1: 5; 2: 4; 3: 2; 4 or later, or no entire match: 0 |
|  | Number of H1s | 4 | Exactly one |
|  | Title and H1 differ | 2 | Normalised strings differ. Two empty strings do not differ |
|  | Subheadings | 2 | Entire match in any H2–H6: 2; partial only: 1; none: 0 |
| Body (28) | Word count | 14 | 500 or more: 14; 300–499: 10; 150–299: 6; 1–149: 2; 0: 0 |
|  | Mentions | 7 | Entire matches 3 or more: 7; 2: 5; 1: 3; 0: 0 |
|  | Partial coverage | 5 | Above 70%: 5; 50–70%: 3; below: 0 |
|  | Every word | 2 | Coverage is exactly 100% and the keyphrase has content words |
| Meta (5) | Meta description | 5 | Entire match: 5; coverage at least 50%: 3; otherwise 0. An empty description is 0 |
| Media (4) | Image alt | 4 | Entire match in any HTML or template alt: 4; partial: 2; no images or no matching alt: 0. CSS backgrounds are not scored. The recommendation asks for an accurate description and does not tell the user to insert the target phrase. |
| Code (6) | Separate CSS | 3 | A stylesheet is linked. See the code-check rule below |
|  | Visible text | 3 | Visible-text bytes / HTML bytes at least 2%: 3; at least 1%: 1; otherwise 0 |
| URL (10) | Keyphrase in the path | 5 | Root path `/`: 5. All content words in the path: 5. Coverage above half: 3. Otherwise 0 |
|  | HTTPS | 5 | The page is marked HTTPS |

Code checks run only when `has_external_css`, `html_bytes`, and `visible_text_bytes` are all present and `html_bytes` is greater than 0. Otherwise their status is `not_checked`, they earn 0, and they leave the denominator. A content render with no fetch therefore scores out of 94. A fetched page with measurements scores out of 100, including when the checks fail.

The visible-text threshold is 2%, which is the line the published reference score uses.

## Reference

Keyphrase `rank tracker`, title of 65 characters starting with the keyphrase, H1 `Discover the finest online rank tracker` (39 characters, keyphrase at position 5), one partial subheading, 1,309 body words and 11 entire mentions, keyphrase in the meta description and an image alt, external CSS, 2.88% visible text, HTTPS URL whose path contains both words.

That page scores 92/100: Title 25, Headings 14, Body 28, Meta 5, Media 4, Code 6, URL 10. The fixture is `tests/fixtures/onpage/01-wincher-reference.json`.

## Advisories

These sit beside the score and do not change it:

- Keyphrase density above 3% of body words (`entire count × content-word count / words`)
- Approximate title width above 600 pixels
- A non-empty meta description outside 120–157 characters
- More than 75% of subheadings containing any keyphrase word
- An article whose body is under 300 words

The word-count recommendation tells the writer to cover the topic. It does not tell them to add filler.
