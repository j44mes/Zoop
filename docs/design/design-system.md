# Design system

Tokens live on `.seostudio-dashboard` and `.seostudio-panel` in `assets/src/shared/ui/style.css`.

- Canvas `#F7F8FA`, white cards, charcoal text, slate secondary text, a blue accent, and muted success, warning, and error colours. Secondary text is darker than the first sketch so help text meets contrast on the canvas.
- Success, warning, and error, each with a background tint.
- Cards, status chips, the page table, disclosures, and the audit bar.

Shared components that already existed stay: `ScoreRing`, `TrafficLight`, `AssessmentList`, `RunProgress`, `SnippetPreview`, `SocialPreview`, `CategoryBar`, `VariableInput`. `AssessmentList` now leads with a sentence from `assessment-labels.ts`.
