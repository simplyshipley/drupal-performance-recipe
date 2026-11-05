# Local decision for redirect #3589682 (NOT POSTED)

Target: [redirect #3589682](https://www.drupal.org/project/redirect/issues/3589682).
Decision date: 2026-11-05. This records the delegated local lab disposition and a draft
issue comment. Posting a comment, patch or merge request still waits for Bo.

## Issue-queue comparison

Read the comments before comparing the patch. The captured comments contain only
antonio.cangemi's issue-creation note, comment
[#16583808](https://www.drupal.org/project/redirect/issues/3589682#comment-16583808), dated
2026-05-12 07:49:10 UTC. There is no maintainer reply in that capture. A fresh
[issue API response](https://www.drupal.org/api-d7/node/3589682.json), read on
2026-11-05 at 06:51 UTC, still lists that one comment and no attached patch files.
There is therefore no maintainer position to attribute or upstream patch to apply from
this issue's attachments. This does not establish that no separate merge request exists.

The issue author's proposed resolution is an early return for `isXmlHttpRequest()` before
canonical redirects. The local patch implements that guard in
`RouteNormalizerRequestSubscriber::onKernelRequestRedirect()` and adds the unit test
`testSkipIfAjaxRequest`. The patch is a local implementation of the proposal, not an
upstream-reviewed patch. The module's admin-path exclusion does not cover the core
`views.ajax` route; see [installation notes](install.md#optional-redirect-module-no-canonical-redirect-on-ajax-requests).

The issue's remaining tasks include a kernel test for an unsorted query string and checks
of `ajax_form=1` and BigPipe behavior. The local patch's unit test and synthetic lab probe
do not fulfill those tasks. In particular, the historical synthetic lab run reproduced
canonical-path normalization, while ordinary Views AJAX calls already returned 200 with
and without the patch. It did not reproduce the issue's query-reordering trigger.

## Local disposition

Keep the optional patch and the synthetic `perf_ajax_test` view for regression probes.
The synthetic view at `/perf-ajax-test` is lab configuration, not production evidence.
Its machine ID and synthetic path remain stable so the existing probe can address it.

Keep the patched upstream clone, probe script, captured AJAX query strings and issue
comments as test inputs/evidence. Keep the lab's pre-patch Composer backups so the active
patch can be reversed. Retire the two redundant session patch exports and the empty
failed issue-body capture directory with `__ARCHIVED_` names in their existing directory.
The canonical patch in this repository's `patches/` directory remains active.

This is a decision to preserve a reproducible local test, not approval for production
installation or an assertion that the contribution is ready for upstream review. The
cache-variation caveat and cache-clear instructions remain in the installation notes.

## Draft issue comment (unposted)

We implemented the proposed `isXmlHttpRequest()` guard locally in the route normalizer
and added a unit test. We are retaining the patch and a clearly labeled synthetic Views
fixture for local regression probes. The synthetic lab demonstrated the canonical-path
case; it did not reproduce the reported query-reordering trigger. Ordinary Views AJAX
requests returned 200 both before and after the patch in that lab.

The requested kernel test, `ajax_form=1` coverage and BigPipe checks remain unverified.
No patch or merge request has been submitted from this work.

## Evidence

- Repository patch: [`redirect-3589682-skip-ajax-requests.patch`](../patches/redirect-3589682-skip-ajax-requests.patch).
- Historical lab results and cache caveat: [installation notes](install.md#optional-redirect-module-no-canonical-redirect-on-ajax-requests), dated 2026-11-04.
- Saved session evidence: `../redirect-3589682/fetch-comments/source.txt` and its
  `acquisition.json` (captured 2026-11-04 at 18:54 UTC).
- Fresh API body: source SHA-256
  `51381205b0246fd4693c60a33d872e63b779cef491447378125043d31fa3e96c`,
  captured by moocher's full-content pipeline on 2026-11-05 at 06:51:42 UTC.
  Raw fresh source stays in temporary review storage; the reviewed findings are recorded here.
- Current lane audit: `../20261104-codex-handoff/fleet/AS-Q28-performance-recipe-lab/issue-docs-review.md`.
