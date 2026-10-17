# Divi 5 Visual Builder panel spike

Decision: **no-go** for a Visual Builder panel in this release.

## What was checked

Divi 5 is not installed in this workspace or in the plugin's CI image. It is a commercial plugin, so the suite cannot download it or boot the Visual Builder. The public sources named in `docs/SPEC.md` still describe the integration points:

- Divi 5 stores native WordPress blocks in `post_content` and renders them with block callbacks, so `the_content` already returns the body text, including drafts.
- A React extension API (`@divi/*`) exists, and third parties have shipped custom modals in the Visual Builder.
- Divi 4 remains `et_pb_*` shortcodes. `the_content` runs `do_shortcode`, so body text is still extracted.

## What was not run

No Visual Builder screen was opened. No `@divi/*` package was imported. No custom modal was mounted. Treating that as a pass would be false.

## What ships instead

`DiviAdapter` reports Divi 4 shortcodes and Divi 5 block comments, and both keep the classic meta box as the panel. Content render remains the analysis path. A Visual Builder panel can be revisited when a licensed Divi 5 fixture is available. It is not on the critical path for Owner mode or the audit queue.
