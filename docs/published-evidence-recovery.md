# Published Evidence Recovery

This is a future feature. It is not implemented, and this document is not a claim that Zoop can re-check a whole site.

Zoop can already check one published page when an editor asks. A later release may add a resumable job that walks the pages still missing published-page evidence. That job is separate from the scoring rules and from the upgrade notice.

## Required behaviour

- Resume after a timeout, a failed request, or a closed browser. Store the cursor with the job, not in the editor.
- Use the existing fetch policy. Do not add a second client, and do not follow addresses the policy would reject. Private and link-local targets stay blocked.
- Bound how many published pages are fetched at once. One slow page must not occupy every worker.
- Bound each request with the existing timeout. A page that does not answer is a failure for that page, not a reason to stop the queue forever.
- Show progress as pages finished, pages remaining, and pages that failed. The count comes from the stored job.
- Let an editor cancel the job. Cancellation leaves finished pages as they are and does not mark unfinished pages as verified.
- Record a failure against the page that failed. Other pages continue.
- Run the check only for pages the current user can edit. An author does not fetch or see another author's pages.
- Do not invent a "Re-check all" button, a batch of live requests, or a new score while this remains a specification.

## Out of scope

Scoring weights, metadata ownership, and the fetch policy stay as they are. Fix & Verify, which would apply a change and then check the published page, is a later step after this recovery path exists.
