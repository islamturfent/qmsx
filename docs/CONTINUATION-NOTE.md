# QMS continuation note

Recorded: 2026-09-18 (Europe/Istanbul).

## Current checkpoint

- The latest requested module is Risk Management. The task stopped with a usage-limit error; do not assume it is still running or fully verified.
- Existing files confirmed: risks.php, risk-create.php, risk-detail.php, tests/risk-management.php, docs/RISK-MANAGEMENT.md. Their presence does not prove successful migration or end-to-end testing.
- Resume by inspecting the existing implementation, database migration state and test results. Complete missing work and verify before starting another module. Do not rebuild from scratch or overwrite user changes.
- Required scope: company-scoped risk list and filters, separate creation/detail screens, 5x5 likelihood-impact matrix, controls, owner, due date, status and residual risk. Preserve tenant authorization, CSRF protection, TR/EN, light/dark themes and mobile layout.
- The 1-5 scale and severity thresholds are project assumptions documented in RISK-MANAGEMENT.md, not claims of standards compliance.

### Verified Risk Management checkpoint

- The Risk Management implementation remains in place; TinyMCE work did not replace or remove it.
- `php tests/risk-management.php` previously passed 19 cases covering tenant scope, scores and thresholds, validation, CSRF, access revocation, residual tracking/history and optimistic version checks. Re-run after future risk changes.
- The risk migration had been run and real risk tables were empty at that checkpoint; tests use temporary tables and preserve real records.

## Office integration

- User explicitly deferred Collabora setup and will configure the connection personally. Do not resume installation or modify office connection settings as part of Risk Management.
- Web document editor was previously reported completed: new revisions preserve older versions and changed approved/published documents require approval again.
- Collabora integration code and docs/OFFICE-INTEGRATION.md exist. Live Word opening, editing and saving through a running Collabora server have NOT been confirmed in this conversation.
- Start live verification with a disposable DOCX when the connection is ready. Excel/PDF come later; expose only capabilities actually supported by the server.
- User could not find the document editor button. Follow up on discoverability later: Documents > document detail > Office Editor / Web Document Editor.
- Architecture decision: free Collabora CODE for development/evaluation; assess supported production deployment and licensing before commercial rollout. Keep provider-specific code separated; another provider may require adapter changes, not merely configuration.
- Collabora remains deferred. The web editor now uses locally hosted TinyMCE 8.9.1 GPL core; see `docs/TINYMCE-EDITOR.md`. No Tiny Cloud/CDN/API key is used.

## Working preferences

- Give candid, constructive project advice and distinguish verified results from plans or delegated progress.
- Keep forms on separate management screens, not piled onto the dashboard. Use the established compact action buttons outside the global header.
- Preserve real records; use temporary fixtures and clean only test data created by the task.

## Owning task

Continue in the existing task titled "QMS web editörüne devam et", rooted at C:\xampp\htdocs\qms. Inspect its latest history before concurrent edits.
