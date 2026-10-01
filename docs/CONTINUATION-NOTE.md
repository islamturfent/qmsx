# QuAmi continuation note

Recorded: 2026-09-18 (Europe/Istanbul).
Last updated: 2026-09-30. The state below was re-verified on that date; it is not
carried over from earlier assumptions.

## Workspace

- Work is carried out in `C:\xampp\htdocs\qmsx`.
- The sibling copy under `htdocs` (folder `qms`) is **out of scope** and must not
  be modified. Earlier notes pointed there; that instruction is superseded.
- Both folders connect to the same MySQL database (`qms`), so any migration
  affects both front ends.

## Current checkpoint (verified 2026-09-19)

- Risk Management is **verified**, not merely present. The usage-limit stop
  recorded earlier is resolved: the implementation was re-run against the
  database and passes.
- Migrations are applied. Confirmed tables: `risks`, `risk_history`,
  `office_audit`, `office_locks`, `office_sessions`.
- Test results, all green:

  | Suite | Checks |
  | --- | --- |
  | `tests/risk-management.php` | 19 |
  | `tests/document-editor.php` | 14 |
  | `tests/document-workflow.php` | 7 |
  | `tests/office-integration.php` | 48 |
  | `tests/training-management.php` | 25 |
  | `tests/capa.php` | 51 |
  | `tests/supplier-management.php` | 47 |
  | `tests/complaint-management.php` | 47 |
  | `tests/performance-management.php` | 23 |
  | `tests/review-management.php` | 33 |
  | `tests/audit-report.php` | 26 |
  | `tests/audit-log.php` | 16 |
  | `tests/audit-program.php` | 16 |
  | `tests/equipment.php` | 12 |
  | `tests/search.php` | 11 |
  | `tests/permissions.php` | 17 |
  | `tests/complaint-nonconformity.php` | 13 |
  | `tests/checklist-templates.php` | 15 |
  | `tests/document-review.php` | 21 |
  | `tests/satisfaction.php` | 14 |
  | `tests/personnel.php` | 17 |
  | `tests/external-audit.php` | 20 |
  | `tests/quality-cost.php` | 19 |
  | `tests/document-copy.php` | 15 |
  | `tests/approval-workflow.php` | 21 |
  | `tests/quality-cost-trend.php` | 12 |
  | `tests/document-compare.php` | 14 |
  | `tests/dashboard-cockpit.php` | 10 |
  | `tests/due-workbench.php` | 7 |
  | `tests/capa-type.php` | 5 |
  | `tests/auditor-workload.php` | 7 |
  | `tests/user-overdue.php` | 4 |
  | `tests/mailer.php` | 11 |
  | `tests/notification-preferences.php` | 7 |
  | `tests/audit-program-reminders.php` | 5 |
  | `tests/closure-package.php` | 8 |
  | `tests/search-enhanced.php` | 6 |
  | `tests/my-assignments.php` | 5 |
  | `tests/document-templates.php` | 10 |
  | `tests/announcements.php` | 11 |
  | `tests/internal-survey.php` | 22 |
  | `tests/quality-plan.php` | 18 |
  | `tests/supplier-evaluations.php` | 15 |
  | `tests/improvements.php` | 14 |
  | `tests/processes.php` | 11 |
  | `tests/contracts.php` | 12 |
  | `tests/notify-overdue.php` | 8 |
  | `tests/incidents.php` | 12 |
  | `tests/instruments.php` | 14 |
  | `tests/report-export-data.php` | 23 |
  | `tests/dashboard-trend.php` | 24 |
  | `tests/notify-modules.php` | 7 |
  | `tests/contract-attachments.php` | 7 |
  | `tests/delivery-performance.php` | 15 |
  | `tests/incident-nonconformity.php` | 7 |

  931 checks total (54 suites). All suites use temporary tables and leave real records
  untouched (verified: `risks`, `risk_history`, `office_audit`, `trainings`,
  `training_participants`, `corrective_actions`, `suppliers`, `complaints`,
  `performance_targets`, `notifications` remain empty).

- Run the suites from CMD/PowerShell, or set `TMP` to a real Windows path first;
  Git Bash defaults `TMP=/tmp` and `tempnam()`-based tests then abort.

- `tests/document-workflow.php` now runs all seven cases when called without an
  argument. Before this, only the default `save` case executed, which made the
  suite look like a single check even though the cases existed. Single-case mode
  still works: `php tests/document-workflow.php csrf`.
- **Running the tests from Git Bash fails at `tempnam()`**: the shell sets
  `TMP=/tmp`, PHP resolves that to `C:\tmp`, which does not exist, and the office
  integration test aborts with "Path cannot be empty". Run tests from
  CMD/PowerShell, or point `TMP` at a real Windows temp path first. This is an
  environment artefact, not a defect in the tests.

## Interface work completed (2026-09-19)

- The UI was re-themed on the TailAdmin design system. Palette, elevation and
  radii live in the design tokens at the top of `assets/css/style.css`;
  components consume the semantic aliases, so re-theming starts there.
- Sidebar and stat tile icons come from `appIcon()` in
  `includes/app-sidebar.php` - one icon map. Do not inline SVG in pages and do
  not use emoji icons.
- Dark mode re-points tokens only (`body.dark-mode`); component rules are not
  duplicated. When adding a token, update both `:root` and `body.dark-mode`.
- **Navigation convention**: page-level navigation links (back links, "Kayıtlı
  Şirketler") belong to the right of the page title as `secondary-button`, never
  in the header. Headers keep only language/theme controls, Dashboard and logout.
  Recorded in `UI-STANDARDS.md`.
- The Outfit webfont is self-hosted under `assets/fonts/` (OFL, license text
  included), so typography survives offline PWA use with no external dependency.
- The dashboard performance card now reads `action_completion_rate` from
  `buildReportExportData()` - the same source as the reports screen, so the two
  always agree. It is labelled "Aksiyon Tamamlama". Note this loads the 12-month
  report dataset on every dashboard view; if that page ever feels slow, replace it
  with an aggregate query.

## Training management - Faz 3 module (2026-09-24)

Third product module, after documents and risks. Completes the new-module
checklist from knowhow section 10: menu entry, permissions, DB relations, list,
detail, status flow and a reporting surface.

- Schema: `trainings` + `training_participants` (migration
  `20260924-trainings.sql`, idempotent runner `scripts/migrate-trainings.php`).
- Participants are the company's **system users** (company users plus system
  admins assigned to that company) - the same model CAPA uses for its responsible
  user. There is still no personnel/employee table; non-login employees would need
  a schema addition.
- Status flow `planned -> in_progress -> completed` plus `cancelled`; participant
  status `assigned -> attended -> completed` with an optional 0-100 score.
  `completed_date` is written on the first transition to `completed` and kept on
  re-save.
- Notifications: the participant is notified when added, and the company's
  assigned system admins are notified when the training completes or is created.
- `includes/training-functions.php` is the module's single source of truth for
  status labels, field validation and scoped reads (`qmsTrainingFind`,
  `qmsTrainingList`). Pages do not re-implement scope clauses.
- Reporting surface: training count and completion rate plus a training detail
  list in `includes/report-export-data.php`, an eighth Excel sheet (`Egitimler`)
  and a PDF detail section, and a KPI tile plus company-performance column on
  `reports.php`.
- Auditor visibility follows the sibling modules rather than adding a new rule:
  auditors see only the companies of the audits assigned to them, so the training
  module is not reachable from their sidebar but is not separately blocked.
  Decide explicitly if a stricter rule is wanted.
- Verified behaviourally, not only by reading SQL: 25 temporary-table checks plus
  a throwaway HTTP harness (real login sessions for an assigned admin, an
  unassigned admin, an auditor and a company user) covering tenant isolation over
  HTTP, CSRF rejection, participant add/update/remove, the completion flow, the
  reports screen and both export files. Fixtures and uploaded files were removed
  afterwards and the table auto-increment counters were restored.
- Lesson for the next harness: this is a Windows environment, so pass PHP paths in
  `C:/...` form and `curl -F` upload paths in `C:/...` form too; MSYS `/c/...`
  paths work in the shell but not for the native binaries.

### Module helper convention (2026-09-24)

Each module with non-trivial rules now has its own helper file - do not put a
scope clause or a rule set back into a page:

- `includes/risk-functions.php` - risk scope, scoring, level thresholds
- `includes/training-functions.php` - training validation, scoped reads
- `includes/capa-functions.php` - CAPA scope, evidence paths, status timestamps,
  notification rules (`qmsCapaNotifyStatusChange`)

The CAPA extraction was done so the notification and evidence rules could be
regression tested; `corrective-action-create`, `corrective-action-detail` and
`corrective-action-evidence-download` now all read through it. One behaviour
change came with it: the assignment notification created from the create screen
now carries the action text instead of the nonconformity title, matching the
detail screen.

## Supplier management - Faz 3 module (2026-09-25)

Fourth product module. Designed by us: knowhow names the module but not its
fields, so the schema is our proposal, not an extracted requirement.

- Schema: `suppliers` + `supplier_evaluations` (migration `20260925-suppliers.sql`,
  runner `scripts/migrate-suppliers.php`).
- Approval flow `candidate -> approved -> suspended -> removed`; `approved_date` is
  written on the first transition to `approved`.
- Evaluations carry quality/delivery/service scores (0-100). The evaluation total
  is the average of the scores that were entered, and the supplier's score is the
  average of its evaluation totals. There is deliberately **no** stored score or
  `last_evaluation_date` column - an earlier draft had one and it was dropped
  before release, because it would have been a second source of truth.
- Notifications: approval and suspension go to the company's assigned system
  admins; an "unacceptable" evaluation decision also notifies them. Ordinary
  evaluations stay silent.
- Reporting surface: supplier count, average score and a supplier detail list in
  `includes/report-export-data.php`, a ninth Excel sheet (`Tedarikçiler`), a PDF
  detail section, and a KPI tile plus company-performance column on `reports.php`.
- Verified behaviourally: 47 temporary-table checks plus a throwaway HTTP harness
  (login sessions for an assigned admin, a second admin for the notification
  assertions, an unassigned admin and a company user) covering tenant isolation,
  CSRF rejection, the approval flow, evaluation add/update/remove, duplicate
  supplier-code rejection and both report exports. Fixtures were removed and the
  auto-increment counters restored.

## Notification centre pass (2026-09-25)

Closes the "worth a UI pass" item from the CAPA notes.

- `includes/notifications.php` now owns a notification **type map**: type -> icon +
  group (`capa`, `document`, `training`, `supplier`, `general`). The centre renders
  the icon through `appIcon()` and a group pill from the same map, so a new module
  only adds an entry there. Unknown types fall back to the general group, which
  keeps older rows readable.
- The old text glyphs (`!` / `✓`) used as icons are gone; the group colour lives on
  the icon (brand / purple / success / orange / neutral token pairs), the pill stays
  neutral.
- `document-detail.php` wrote its three notifications with inline `INSERT`
  statements; they now go through `qmsNotify()`. Behaviour is unchanged (same type,
  title, message and link) and the whole document flow was re-verified over HTTP.
- New icon `checkBadge` added to `appIcon()` for closure/completion events, so they
  are visually distinct from assignment events.
- Verified over HTTP with the real document flow (request -> decision -> publish)
  plus a seeded CAPA notification: three notifications rendered, each with an
  inline SVG icon from the map, the translated group pill, working "open record"
  links, unread highlighting, and mark-as-read (with CSRF rejection). Fixtures were
  removed afterwards.

## Shared vocabularies (2026-09-25)

`includes/vocabulary.php` now owns `QMS_SEVERITIES` / `qmsSeverityLabels()` /
`qmsSeverityI18nKeys()`. Severity (minor/major/critical) is used by
`nonconformity-detail`, `actions` and the complaint module; all three now read
from the one file instead of duplicating the array.

## Complaint management - Faz 3 module (2026-09-25)

Fifth product module; extends the record chain to the field.

- Schema: single table `complaints` (migration `20260926-complaints.sql`, runner
  `scripts/migrate-complaints.php`).
- Flow `new -> in_review -> action_planned -> resolved -> closed`, plus `rejected`.
  `closed_date` is written on the first transition to `closed` and kept.
- Fields: source (musteri/calisan/tedarikci/diger), channel, complainant name and
  contact, received date, severity (shared vocabulary), due date, responsible user
  (system-user model, same as CAPA/training) or free text, and a free-form
  root-cause / action / resolution trail.
- **Chain preserved**: a complaint links to an existing nonconformity of the same
  company (`complaints.nonconformity_id`, scope-validated). The link is shown on
  the detail screen with the corrective-action count.
- Deliberate limit: `nonconformities.audit_id` is NOT NULL, so a complaint cannot
  *create* a nonconformity by itself yet. That is a separate product decision (make
  audit_id nullable / add a source column) and is left as an open question rather
  than silently changing the nonconformity surfaces.
- Notifications: assignment to a responsible user, critical-severity escalation to
  the company's assigned admins, and closure to the responsible user.
- Reporting surface: complaint count, open-complaint count and a complaint detail
  list in `includes/report-export-data.php`, a tenth Excel sheet (`Sikayetler`), a
  PDF detail section, and a KPI tile plus company-performance columns on
  `reports.php`.
- Verified behaviourally: 47 temporary-table checks plus a throwaway HTTP harness
  (assigned admin, a second admin, an unassigned admin, a company user) covering
  tenant isolation, CSRF rejection, the status/severity/responsible flow,
  cross-company nonconformity-link rejection, duplicate complaint-number rejection,
  both report exports and the notification-centre rendering in the complaint group.
  Fixtures were removed and the auto-increment counters restored.

## Performance management - Faz 3 module (2026-09-25)

Sixth product module. It is deliberately a **target / scorecard layer on top of
the report engine**, not a new KPI calculator.

- Schema: single table `performance_targets` (migration
  `20260927-performance-targets.sql`, runner `scripts/migrate-performance-targets.php`).
  One row per company + KPI key + year; saving overwrites (upsert) - there is no
  history table yet.
- The actual (gerceklesen) value is read from `buildReportExportData()` for the
  selected company and year - the same source the reports screen and the exports
  use. No parallel KPI calculation is introduced.
- Eight targetable KPIs reuse their existing labels and directions
  (`includes/performance-functions.php`): audit count, nonconformity rate,
  action completion, average closure days, review-due documents, training
  completion, supplier average score, open complaints.
- `performance.php` shows each KPI with actual vs target, an on-track / off-track /
  no-target verdict, and an inline save form. Scope is via `qmsCompanyScope`;
  cross-company target writes and out-of-scope companies are rejected.
- Reporting surface: the exports carry a `Hedefler`/`Targets` sheet and a PDF
  detail section with the saved targets.
- Verified behaviourally: 23 temporary-table checks plus a throwaway HTTP harness
  covering scope, CSRF, target save/upsert, a deterministic on-track verdict and
  both report exports. Fixtures were removed and the auto-increment counters
  restored.

## Management review - Faz 3 module (2026-09-28)

Seventh and final Faz 3 product module - the loop closes: the report engine's
KPIs feed a review meeting, the review's decisions and actions are tracked, and
the whole period shows up back on the reports surface.

- Schema: two tables (migration `20260928-management-reviews.sql`, runner
  `scripts/migrate-management-reviews.php`): `management_reviews` (company,
  title, meeting date, period start/end, participants, scope notes, status
  `planned -> completed`, optional next-review date) and `management_review_items`
  (a `input` / `decision` / `action` item with a topic, description, optional
  responsible user, optional due date and an optional nonconformity link).
- Input values (the period's KPIs) are **not stored**: `review-detail.php` reads
  them from `buildReportExportData()` for the review's company and period, the
  same source as the reports screen and exports. No parallel KPI calculation is
  introduced (matches the performance module's design).
- Items link to an **existing nonconformity of the same company** only; the
  responsible-user and nonconformity option lists come from shared access helpers
  (`qmsCompanyResponsibleOptions`, new `qmsCompanyNonconformityOptions`).
- Completion notification: when a review moves to `completed`, the company's
  assigned system admins are notified (`review_completed`, group `review`); the
  acting admin and super admins are deliberately excluded. Re-saving a completed
  review or returning to planned sends nothing.
- Reporting surface: a review count metric plus a review detail list in
  `includes/report-export-data.php`, a twelfth Excel sheet (`Gözden Geçirmeler`), a
  PDF detail section, and a KPI tile plus company-performance columns on
  `reports.php` (Excel is now 12 sheets, the PDF nine detail tables).
- Verified behaviourally: 33 temporary-table checks (scoped reads, item linking,
  the completion notification rule, i18n key coverage) plus an HTTP harness
  covering scope, creation, the KPI input panel, item add/update/remove, the
  cross-tenant nonconformity-link rejection, CSRF, completion notification, other
  tenants being redirected away, company-user visibility and both report exports.
  Fixtures were removed and the auto-increment counters restored.

### Bug caught by the harness (fixed before release)

The cross-tenant nonconformity-link check originally set its error message but
still inserted the item: the add/update branch's validation used
`if ($formError === "" ...) / elseif ... / else { insert }`, and the final `else`
did not re-check `$formError`, so a pre-set error was silently ignored and a
foreign company's nonconformity could be linked. The insertion branch is now a
`elseif ($formError === "")`, so a rejected link leaves the row uncreated. This
was a real data-integrity leak that only the HTTP harness caught (the unit suite
exercised helpers, not this page branch).

### Shared option extraction

`includes/access.php` grew `qmsCompanyNonconformityOptions()` (a company's
nonconformities as link options). The complaint module's
`qmsComplaintNonconformityOptions()` now delegates to it, so both modules read
one source of truth instead of each owning a copy of the query.

### Faz 3 complete

Education, supplier, complaint, performance and management review modules are
done; the knowhow Faz 3 module list is now full. Dashboard KPI depth/trends and
a local auto summary were picked up next (see the dashboard panel section).
Still open: the complaint-to-nonconformity creation decision and Collabora live
verification.

## Dashboard panel - trend + ozet (2026-09-28)

Two view-level additions after Faz 3 closed, covering the dashboard KPI depth
and an auto summary.

- `includes/dashboard-functions.php` owns a scoped, **aggregate** trend builder
  (`qmsDashboardTrend`) and a local, rule-based summary (`qmsDashboardSummary`).
  Both run directly on GROUP BY queries - the dashboard no longer has to load the
  full 12-month report dataset just to show its cards, and the values agree with
  the reports because they read the same tables.
- Trend series (last 12 months, oldest to newest): audits, nonconformities,
  completed actions, completed trainings, complaints. Each bucketed by its own
  timestamp (actions by `completed_at`, trainings by `completed_date`).
- Summary is **generated locally** - no external AI service - and composes a
  headline plus a list of insight lines with a neutral/positive/warning tone
  (overdue actions trigger the warning). It is a narrative over the same scoped
  counts, so it stays consistent with the reports.
- Rendering: a "Dönem Özeti" panel and a "Son 12 Ay Trendleri" panel on
  `dashboard.php`, token-based TailAdmin styling with no inline SVG (icons come
  from `appIcon()`). i18n: 9 new TR/EN keys (now 702 per language).
- Verified: 17 temporary-table checks (bucket shape, per-series counts, scope
  isolation for super admin / company user / system admin, summary tones) plus an
  HTTP harness (login, dashboard render, both panels present). Fixtures were
  removed and auto-increment counters restored.

## Denetim raporu taslagi (2026-09-30)

A per-audit reporting surface (knowhow "denetim notlarindan rapor taslagi").

- Schema `audit_reports` (one row per audit, migration `20260929-audit-reports.sql`):
  status `draft -> final`, editable narrative fields, a stored source snapshot and
  approval (by/when) metadata.
- `includes/audit-report-functions.php` derives a snapshot from the audit + checklist
  + nonconformities (`qmsAuditReportSnapshot`) and composes a rule-based draft
  (`qmsGenerateAuditReportText`: scope, method, findings, NC summary, conclusion,
  recommendations) - no external AI service.
- `audit-report.php?id=<audit>` edits/saves/finalizes; `audit-report-export-pdf.php`
  streams a single-report PDF. Reached from `audit-detail.php` (Denetim Raporu).
- Scoped via `qmsAuditRecordScope`; audit reports for other tenants are not disclosed.
- 26 temp-table checks + an HTTP harness (generation, persistence, PDF, scope).

## Denetim izi (audit trail) - Task 2 (2026-09-30)

Security/compliance surface (knowhow requirement "kim, neyi, ne zaman degistirdi").

- `audit_log` is an **append-only** table; the app never updates or deletes it, and the
  view is read-only. `qmsAuditLog()` records company, actor, entity type/id, action,
  a human-readable summary, field-level details (JSON) and the client IP.
- `audit-trail.php` is a filtered, read-only viewer for management roles (super admin
  + system admin; auditors and company users are redirected). Filters: company, record
  type, action, date range. Super admin sees everything; others only their companies.
- Instrumented hooks (via `qmsAuditLog`): audit report (create/update/finalize),
  complaint (create/status), document (publish/archive), nonconformity (close/status),
  corrective action (status), management review (complete), supplier (status),
  training (complete/status). These are a representative set; the helper is the single
  entry point for future modules.
- 16 temp-table checks + an HTTP harness (a real generate wrote a log, viewer, filters,
  cross-tenant isolation, role gate). Fixtures cleaned and counters restored - including
  a real bug caught: `lastInsertId()` was read after `qmsAuditLog` inserted, returning the
  log's id instead of the report's id; the report id is now captured first.

## Ic denetim programi - Task 3 (2026-09-30)

Yearly audit-planning module: groups a company's audits under a program.

- Schema `audit_programs` + `audit_program_audits` (migration
  `20260930-audit-programs.sql`); flow `draft -> active -> completed` with an approval
  date written on entering `active`.
- `includes/audit-program-functions.php` owns scoped reads, status labels, and
  link/unlink that only accept same-company audits (foreign-company audit links are
  rejected).
- Pages: `audit-programs.php`, `audit-program-create.php`, `audit-program-detail.php`
  (edit + status + link/unlink). Sidebar: Denetim Programlari (management roles).
- Reporting: a program count/active KPI, company columns, an Excel sheet
  "Denetim Programlari" (Excel is now 13 sheets) and a PDF detail section (10 detail
  tables) on the reports surface.
- 16 temp-table checks + an HTTP harness (create, link/unlink, status flow, CSRF,
  exports, cross-tenant isolation).

## Kalibrasyon / Ekipman modulu (2026-09-30)

Eighth product module, from the "başka bir modül/yüzey" follow-up.

- Schema: `equipment` + `calibrations` (migration `20260930-equipment.sql`).
- Calibration **status is derived**, never stored: `next_calibration_date` drives
  `not_scheduled / calibrated / due_soon / overdue`. There is no stored status copy,
  so the two cannot drift.
- `includes/equipment-functions.php` owns scoped reads, the derived status, and an
  overdue/upcoming helper used by the notification feed.
- Pages: `equipment.php` (list), `equipment-create.php`, `equipment-detail.php`
  (calibration history + add/edit + delete). Sidebar entry for management roles.
- Notification group `calibration`; a `calibration_failed` reminder joins the
  existing group map in `includes/notifications.php`.
- Reporting: an equipment/overdue KPI plus a calibration detail list in
  `includes/report-export-data.php`, a fourteenth Excel sheet (`Ekipman`) and an
  eleventh PDF detail table.
- 12 temp-table checks + an HTTP harness (scoped reads, derived status, history
  add/edit/delete, CSRF, exports).

## Semantik arama + benzer vaka (2026-09-30)

A search surface across the record chain, from the same follow-up.

- `includes/search-functions.php` owns a **local** keyword matcher: keyword tokens
  + `LIKE` + a relevance score; no external AI service.
- `corrective_actions` has no `company_id`, so the type definition uses a join to
  `nonconformities` plus a `company_col` so every searchable type resolves its
  tenant.
- `search.php` scopes results to the caller's company scope; the type filter also
  drives the "Benzer Vakalar" panel (cross-type by design).
- 11 temp-table checks + an HTTP harness (tenant isolation, relevance ranking,
  type filter, empty-query handling). Fixtures cleaned.

## RBAC - izin servisi ve matris (2026-09-30)

A read-only permission-matrix surface plus a single source of truth for role
checks. Proposed as a *layer*, delivered as a concrete surface (a super-admin
page) because the layer alone had nothing reviewable.

- `includes/permissions.php` is the single source: `qmsCan`, `qmsCanSession`,
  `qmsRequirePermission`, `qmsPermissionMatrix` - 15 actions across 4 roles
  (super_admin / system_admin / auditor / company_user).
- `permissions.php` is a read-only matrix view (`permissions.view`, super admin
  only). Sidebar "İzinler" under Sistem Yönetimi; i18n TR/EN; icon from
  `appIcon()`.
- **Representative gate moved**: only `audit-trail.php` now checks
  `qmsCanSession('audit_trail.view')`. The rest of the pages still work as-is;
  the service is the single source going forward. Full propagation to the other
  role gates (reports, super-admin-*, my-audits, exports) is an explicit, not-yet
  approved open item - do not expand it silently.
- 17 temp-table checks + an HTTP harness (every action resolves to a known role
  set, unknown action/role denied, matrix covers every action, role-specific
  scenarios). Fixtures cleaned.

## RBAC propagation across the role gates (2026-09-30)

Closes the "representative gate only" caveat from the RBAC section: the rest of
 the page role gates now read through the same service.

- `my-audits.php` (my_audits.view), the three `super-admin-*` pages
  (admin.admins / admin.assignments / admin.companies), `reports.php`
  (reports.view) and both report exports (report.export, 403 on denial)
  were moved onto `qmsCanSession` / `qmsRequirePermission`.
- `reports.php` had no role gate at all before; an auditor reaching it directly
  saw an empty scoped report. It now redirects non-permitted roles away.
- Page gates redirect to `dashboard.php`; download endpoints return 403. All 17
  role-gate cases verified over HTTP with per-role logins and the fixtures were
  cleaned. The permission service is now the single source for every role gate.

## Sikayetten uygunsuzluk olusturma (2026-09-30)

Resolves the open product question from the complaint module: a complaint can
 now *create* its own nonconformity, not only link an existing audit-sourced one.

- Schema change `nonconformities`: `audit_id` is now nullable and a `source`
  column (`audit` | `complaint`) was added. Migration
  `20260930-nonconformity-source.sql` + idempotent runner
  `scripts/migrate-nonconformity-source.php`: the FK is dropped, the column
  becomes nullable, `source` is added, and the FK is re-applied as CASCADE (so
  deleting an audit still removes its own NCs; complaint-sourced ones have NULL
  audit_id and survive).
- `includes/complaint-functions.php` gained `qmsComplaintCreateNonconformity()`:
  inserts a complaint-sourced NC (audit_id NULL, source complaint, severity and
  subject mirrored from the complaint) and links it back in one transaction.
  Already-linked complaints create nothing (single-link invariant).
- `complaint-detail.php` shows a "Uygunsuzluk Oluştur" panel when the complaint
  has no linked NC; on success it links the new NC. It never duplicates.
- `nonconformity-detail.php` joins audits with LEFT JOIN so complaint-sourced
  NCs render; for `source = complaint` the sub-header, back button and the
  "Kaynak" record point at the originating complaint instead of an audit.
  Auditors stay isolated: their scope is by audit id, and a complaint NC has
  NULL audit_id, so it is invisible to them.
- 13 temp-table checks + an HTTP harness (create + link, NULL audit id, source
  marker, no second record for a linked complaint, complaint-source detail
  render). Fixtures and the audit_log row were cleaned.

## Denetim kontrol listesi sablonlari (2026-09-30)

A company-scoped template library so auditors reuse standard checklist items
 instead of typing them per audit.

- Schema `audit_checklist_templates` + `audit_checklist_template_items`
  (migration `20260930-checklist-templates.sql`, idempotent runner
  `scripts/migrate-checklist-templates.php`). Both are company-scoped.
- `includes/checklist-template-functions.php` owns scoped list/find/items and
  `qmsChecklistTemplateApply()`: copies a template's active items into an audit
  checklist, skipping item texts already present and rejecting templates whose
  company differs from the audit's (cross-tenant apply is a no-op).
- Pages: `checklist-templates.php` (list + summary), `checklist-template-create.php`
  (company + title + description), `checklist-template-detail.php` (edit + add/
  remove items). Sidebar entry under "Denetim Programları" (management roles).
- Integration: `audit-detail.php` gains a "Şablon Uygula" panel (management
  roles) that lists only templates of the audit's company; `qmsChecklistTemplateApply`
  copies them in.
- i18n + appIcon `approvals`; service-worker cache bumped.
- 15 temp-table checks + an HTTP harness (create, add item, apply to an audit,
  duplicate-skip, cross-tenant rejection, flash render). Fixtures cleaned.

## Doküman gözden geçirme merkezi (2026-09-30)

A dedicated review work queue on top of the existing `documents.review_date`
 (already surfaced as the "review-due documents" KPI). ISO-style periodic
 document reviews now have a repeatable queue and a recorded history.

- Schema `document_reviews` (migration `20260930-document-reviews.sql`, idempotent
  runner `scripts/migrate-document-reviews.php`): one row per review action with
  outcome (`ok` | `needs_revision`), notes, reviewed_at, next_review_date.
- `includes/document-review-functions.php` owns the derived status
  (`not_scheduled / overdue / due_soon / on_schedule`, 30-day due-soon window),
  the scoped queue, the history, and `qmsDocumentReviewRecord()` which inserts
  the review, advances the document's `review_date`, and writes an audit-log row.
- `document-reviews.php`: summary cards, filter pills, the review queue with an
  inline review form (management roles act; others see a read-only queue), and a
  recent-history list. Sidebar entry under "Doküman Gözden Geçirme".
- Scoped by company; cross-tenant documents cannot be reviewed (no-op).
- 21 temp-table checks + an HTTP harness (queue + ordering, status derivation,
  review record + date advance + history + audit log, invalid/cross-tenant
  rejection, flash render). Fixtures and the audit_log rows were cleaned.

## Müşteri memnuniyeti ankette (2026-09-30)

A company-scoped satisfaction survey module: survey templates (question sets),
 customer responses rated 1-5, and a derived average satisfaction score.

- Schema `satisfaction_surveys`, `satisfaction_questions`, `satisfaction_responses`,
  `satisfaction_response_answers` (migration `20260930-satisfaction.sql`, idempotent
  runner `scripts/migrate-satisfaction.php`). All company-scoped.
- `includes/satisfaction-functions.php` owns scoped list/find/questions/responses
  and `qmsSatisfactionRecordResponse()`: it accepts only the survey's own question
  ratings (cross-tenant and unknown question ids are ignored), stores the answers,
  and derives `overall_score` as the 1-5 average of the valid ratings.
- Pages: `satisfaction-surveys.php` (list + summary), `satisfaction-survey-create.php`,
  `satisfaction-survey-detail.php` (edit + question add/remove + customer response
  entry + response list). Sidebar entry under "Şikayet Yönetimi".
- Reporting: `satisfaction_count` + `satisfaction_avg` metrics, a satisfaction KPI
  tile on `reports.php`, a fifteenth Excel sheet (`Memnuniyet`) and a twelfth PDF
  detail table.
- 14 temp-table checks + an HTTP harness (create survey, questions, record a
  response, derived average, response list, cross-tenant rejection, KPI + both
  exports). Fixtures cleaned.

## Personel & Yetkinlik (2026-09-30)

Adds the personnel concept the codebase previously deliberately omitted. A staff
 record is company-scoped and carries a competency matrix.

- Schema `staff_members` + `staff_competencies` (migration
  `20260930-personnel.sql`, idempotent runner `scripts/migrate-personnel.php`).
  Unique employee code per company.
- `includes/personnel-functions.php` owns scoped list/find/competencies and
  competency add/remove. Competency level is 1-5; the competency status
  (`not_scheduled / ok / due_soon / expired`, 30-day window) is derived from
  `next_assessment_date` - no stored status copy.
- Pages: `personnel.php` (list + summary), `personnel-create.php`,
  `personnel-detail.php` (edit + competency add/remove + matrix). Sidebar entry
  under "Eğitim Yönetimi".
- Reporting: `personnel_count` + `personnel_expired` metrics, a personnel KPI
  tile on `reports.php`, a sixteenth Excel sheet (`Personel`) and a thirteenth
  PDF detail table.
- 17 temp-table checks + an HTTP harness (status derivation, scoping, add/remove
  competency, cross-tenant rejection, KPI + both exports). Fixtures cleaned.

## Dış denetim & kapama takibi (2026-09-30)

Tracks audits performed by external bodies (customer, certification, regulatory)
 and the closure of their findings.

- Schema `external_audits` + `external_audit_findings` (migration
  `20260930-external-audits.sql`, idempotent runner
  `scripts/migrate-external-audits.php`). Both company-scoped.
- `includes/external-audit-functions.php` owns scoped list/find/findings, the
  derived overdue flag (`status <> closed` and `due_date < today`), finding add
  and status update (closing sets `closed_date`, reopening clears it).
- Pages: `external-audits.php` (list + summary), `external-audit-create.php`,
  `external-audit-detail.php` (edit + status + finding add/closure). Sidebar
  entry under "Denetim Programları".
- Reporting: `external_audit_count` + `external_audit_open` metrics, an external
  audit KPI tile on `reports.php`, a seventeenth Excel sheet (`Dış Denetimler`)
  and a fourteenth PDF detail table.
- 20 temp-table checks + an HTTP harness (overdue derivation, scoping, finding
  add/closure, cross-tenant rejection, KPI + both exports). Fixtures cleaned.

## Kalite maliyeti (COQ) (2026-09-30)

Tracks quality costs by the classic COQ categories (prevention, appraisal,
 internal/external failure) and reports period totals.

- Schema `quality_costs` (migration `20260930-quality-costs.sql`, idempotent
  runner `scripts/migrate-quality-costs.php`): one ledger row per cost. Scoped by
  company; `created_by` recorded.
- `includes/quality-cost-functions.php` owns the scoped ledger, the per-category
  summary (with a derived `failure_total` = internal + external), add and
  soft-delete. Amounts are non-negative decimals.
- Page `quality-costs.php`: summary cards (total / prevention / appraisal /
  failure), type + date-range filters, an inline add form and a deletable
  ledger list (no separate create/detail pages needed for a ledger surface).
  Sidebar entry under "Performans Yönetimi".
- Reporting: `quality_cost_total` + `quality_cost_failure` metrics, a COQ KPI
  tile on `reports.php`, an eighteenth Excel sheet (`Kalite Maliyeti`) and a
  fifteenth PDF detail table.
- 19 temp-table checks + an HTTP harness (scoping, per-category totals, filters,
  add, validation, soft-delete, KPI + both exports). Fixtures cleaned.

## Doküman dağıtım kontrolü (2026-09-30)

Tracks controlled copies of documents and their distribution/return status.

- Schema `document_copies` (migration `20260930-document-copies.sql`, idempotent
  runner `scripts/migrate-document-copies.php`): one row per controlled copy with
  copy number, recipient, location, status (`distributed`/`returned`/`obsolete`).
  Copy number is unique per document. Company-scoped through the document.
- `includes/document-copy-functions.php` owns scoped list/find/add and status
  update (returning sets `returned_on`, re-distributing clears it). Documents
  must be within scope; duplicate copy numbers are rejected.
- Page `document-distribution.php`: summary cards, status filter pills, an add
  form and a list with inline status control. Sidebar entry under "Doküman
  Yönetimi" ("Dağıtım Kontrolü").
- Reporting: `copy_count` + `copy_returned` metrics, a "Dağıtılan Kopya" KPI
  tile on `reports.php`, a nineteenth Excel sheet (`Dağıtım`) and a sixteenth
  PDF detail table.
- 15 temp-table checks + an HTTP harness (scoping, filter, add, duplicate/cross-tenant
  rejection, return/redo status, KPI + both exports). Fixtures cleaned.

## Onay & imza workflow (2026-09-30)

A multi-step approval/signature workflow attached to records (documents,
 contracts, etc.). Distinct from the single-step document approval inbox.

- Schema `approval_runs` + `approval_run_steps` (migration
  `20260930-approval-workflow.sql`, idempotent runner
  `scripts/migrate-approval-workflow.php`): a run has an ordered set of steps,
  each assigned to a signer (active system/super admin). Company-scoped.
- `includes/approval-workflow-functions.php` owns scoped list/find/steps, the
  current (first pending) step, run creation (up to 5 steps, validated signers
  and scope), and signing. The run status is derived from the steps: all
  approved -> approved, any rejected -> rejected, else in_progress. Only the
  current step's assigned signer can sign; a signer without the company scope
  cannot access or sign.
- Pages: `approval-runs.php` (list + summary), `approval-runs-create.php`
  (subject + optional linked record + up to 5 step rows), `approval-runs-detail.php`
  (step timeline + sign form for the current signer). Sidebar entry under the
  management block.
- Reporting: `approval_run_count` / `approval_run_approved` / `approval_run_pending`
  metrics, an "Onay Akışı" KPI tile on `reports.php`, a twentieth Excel sheet
  (`Onay Akışları`) and a seventeenth PDF detail table.
- 21 temp-table checks + an HTTP harness (two signers complete a run end to end:
  create -> admin1 signs step1 -> admin2 signs step2 -> approved; non-current/non-
  assigned signing rejected; rejection path; KPI + both exports). Fixtures cleaned.

## COQ trendi / Kalite Maliyeti analizi (2026-09-30)

New read-only analysis surface built on top of the existing `quality_costs` table
(no schema change) - `quality-cost-trend.php`.

- `qmsQualityCostMonthlyTrend()` in `includes/quality-cost-functions.php` returns
  the 12 months of a selected year with `prevention` / `appraisal` /
  `internal_failure` / `external_failure` / `total` sums, company-scoped, with an
  optional per-company filter. Params are ordered scope-then-year (an initial
  ordering bug put the scope id into the `YEAR()` placeholder and was caught by the
  temp-table test).
- Page `quality-cost-trend.php`: year + company filter, four summary cards
  (annual total, prevention, appraisal, failure), a stacked monthly bar chart,
  an annual category breakdown bar and a monthly detail table.
- Sidebar entry "COQ Trendi" under the COQ item; TR/EN i18n keys for the surface.
- Dark mode CSS fix from the same task (header user-menu text and topbar-button /
  dropdown borders now read on dark surfaces; CSS-only, functions untouched).
- 12 temp-table checks (`tests/quality-cost-trend.php`): 12-month shape, per-category
  and total sums per month, company scoping, company filter, no year leak, annual total.

## Doküman versiyon karşılaştırma (2026-09-30)

New read-only comparison surface - `document-compare.php` - that picks a document
(a scoped list) and two of its `document_versions`, then shows them side by side
plus a line-level text diff.

- `includes/document-compare-functions.php`:
  - `qmsCompareVersionBody()` reads the stored revision HTML from `storage/documents/`,
    extracts the `<body>`, and also produces flat plain text (block closing tags ->
    newline, then `strip_tags`, then whitespace collapse) for diffing.
  - `qmsDiffLines()` is a bounded line-based LCS diff returning `same`/`add`/`del`
    ops; inputs above 3000 lines return an empty diff rather than a memory blowup.
  - `qmsCompareTextToLines()` splits flat text into comparable lines.
- Page `document-compare.php`: document + two-version selects (defaults to the two
  latest), summary cards, a side-by-side scrollable HTML view and a colored diff
  view. Read-only (no POST, no CSRF needed).
- Sidebar entry "Versiyon Karşılaştırma" under Doküman Yönetimi; TR/EN i18n keys.
- 11 checks (`tests/document-compare.php`): LCS add/del/same behaviour, empty-edge
  cases, text splitting, body extraction (temp stored file, cleaned), missing-file
  handling and the oversized-input guard.

## Doküman versiyon karşılaştırma export'u (CSV/PDF) + COQ trend rapor entegrasyonu

Two follow-ups on top of the surfaces built just before.

### Doküman karşılaştırma CSV/PDF özeti (`document-compare-export.php`)
- `document-compare.php` now shows CSV/PDF download buttons that call
  `document-compare-export.php?document=..&a=..&b=..&format=csv|pdf`. Read-only and
  scoped (re-fetches the document/versions through `qmsVisibleCompanyIds`).
- CSV: UTF-8 BOM + a header block (document, company, revisions, diff counts) then
  `Durum,İçerik` rows for every diff op.
- PDF (Dompdf): summary lines + a colored `Durum/İçerik` diff table.
- **Dompdf gotcha:** a small `<table>` placed right after the intro `<h1>`/`<div>`
  triggered the "Parent table not found for table cell" exception. Isolated table
  snippets rendered fine, but the combination did not. The summary was rewritten
  as simple `<p>` rows (cleaner and avoids the parser quirk); the diff data table
  is unaffected.
- `qmsDiffSummary()` added to `includes/document-compare-functions.php` (same/add/
  del/changed counts). `tests/document-compare.php` grew 11 -> 14 checks.

### COQ trend in the report exports
- `includes/report-export-data.php` now builds `quality_cost_trend`: one row per
  month of the report period (same labels as the audit trend) with prevention /
  appraisal / internal_failure / external_failure / total sums, aggregated from the
  already-fetched scoped `quality_costs`.
- `report-export-xlsx.php` adds a `COQ Trendi` worksheet; `report-export-pdf.php`
  adds a matching `COQ Trendi` detail table.
- `tests/report-export-data.php` (9 checks): seeds all ~27 tables the builder reads
  as empty temporary tables plus companies/users/quality_costs, then asserts the
  12-month trend shape, per-month category totals and total, an empty month, and
  that the `quality_cost_total` KPI stays correct.

## Beş yeni modül (2026-09-26): Şablon Kütüphanesi + Duyuru Merkezi

Kullanıcı onayıyla beş yeni şirket kapsamlı modül sırayla ekleniyor:
Doküman Şablon Kütüphanesi, Duyuru Merkezi, İç Memnuniyet Anketi, Yıllık Kalite
Planı ve Tedarikçi Değerlendirme Takvimi. Her modül migration + include + yönetim
sayfası + sidebar + i18n + temp-tablo testi deseniyle yapılır. Collabora/office'e
dokunulmaz.

### Doküman Şablon Kütüphanesi (Task 1) - tamamlandı

- Schema: `document_templates` (migration `20260926-document-templates.sql`,
  idempotent runner `scripts/migrate-document-templates.php`).
- Sayfa: `document-templates.php`; yardımcılar
  `includes/document-template-functions.php` (`qmsDocumentTemplateList/Find/Add/
  Update/Delete`). CRUD + şirket kapsamlı; auditor yönlendirilir (`my-audits.php`).
- Menü: Operasyonlar → Doküman Şablonları. i18n TR+EN eklendi.
- Test: `tests/document-templates.php` (10 kontrol).
- Kapsam sınırlı tutuldu: yalnız şablon kütüphanesi (ekle/düzenle/sil); "yeni
  doküman oluştururken şablon seçme" entegrasyonu bu turda yapılmadı.

### Duyuru Merkezi (Task 2) - tamamlandı

- Schema: `announcements` (migration `20260926-announcements.sql`, idempotent
  runner `scripts/migrate-announcements.php`).
- Sayfa: `announcements.php`; yardımcılar
  `includes/announcement-functions.php` (`qmsAnnouncementList/Find/Add/Update/
  Delete`). CRUD + yayın/taslak toggle; şirket kapsamlı; auditor yönlendirilir.
- Menü: Operasyonlar → Duyuru Merkezi. i18n TR+EN eklendi.
- Test: `tests/announcements.php` (11 kontrol).
- Doğrulama notu: `published` alanı boolean olarak geçilir; include fonksiyonları
  `isset()` yerine `!empty()` ile yorumlar, aksi halde "yayından kaldır"
  (checked kaldırılmış onay kutusu) `published=1` olarak kaydedilirdi. Test bunu
  yakaladı ve düzeltti.

### İç Memnuniyet Anketi (Task 3) - tamamlandı

- Schema: `internal_surveys` + `internal_survey_questions` +
  `internal_survey_responses` (migration `20261001-internal-survey.sql`, idempotent
  runner `scripts/migrate-internal-survey.php`). Yanıtlar kullanıcı bazlıdır ve
  anket+soru+kullanıcı üçlüsünde tektir (UNIQUE).
- Sayfalar:
  - `internal-surveys.php` - yönetim: anket CRUD + soru ekle/sil + sonuç
    ortalamaları (1-5 ölçek, `rating-bar` ile görsel).
  - `internal-survey-fill.php` - doldurma: yayındaki ve henüz yanıtlanmamış
    anketleri listeler, 1-5/ metin sorularını kaydeder, tekrar yanıtı reddeder.
- Yardımcılar: `includes/internal-survey-functions.php`
  (`qmsInternalSurveyList/Find/Add/Update/Delete`, `AddQuestion/DeleteQuestion`,
  `Results`, `HasResponded`, `FillableSurveys`, `Submit`).
- Menü: Operasyonlar → İç Memnuniyet Anketi + Anketi Doldur. i18n TR+EN eklendi.
- Test: `tests/internal-survey.php` (22 kontrol).
- CSS: `.muted-block`, `.rating-bar`, `.rating-bar-fill` eklendi (TailAdmin tokenları).
- Not: müşteri memnuniyet modülünden ayrıdır; bu modül çalışan/İK memnuniyetini
  ölçer ve yanıt sahibi kullanıcıdır (müşteri değil).

### Yıllık Kalite Planı (Task 4) - tamamlandı

- Schema: `quality_plans` (şirket+yıl tekil) + `quality_plan_items`
  (migration `20261001-quality-plan.sql`, idempotent runner
  `scripts/migrate-quality-plan.php`).
- Sayfa: `quality-plan.php` - plan CRUD + yıl filtresi + kalem ekle/düzenle/sil
  (kategori, hedef, hedef değer, sorumlu, bitiş tarihi, durum, ilerleme %).
- Yardımcılar: `includes/quality-plan-functions.php`
  (`qmsQualityPlanList/Find/Add/Update/Delete`, `Items/AddItem/UpdateItem/DeleteItem`).
- Durum: `not_started / in_progress / completed / on_hold`; `qmsPlanStatusLabel()`.
- Menü: Operasyonlar → Yıllık Kalite Planı. i18n TR+EN eklendi; `filter-inline` CSS.
- Test: `tests/quality-plan.php` (18 kontrol).

### Tedarikçi Değerlendirme Takvimi (Task 5) - tamamlandı

- Schema: `supplier_evaluation_schedule` (migration
  `20261001-supplier-eval-schedule.sql`, idempotent runner
  `scripts/migrate-supplier-eval-schedule.php`). Tek seferlik puanların
  (`supplier_evaluations`) yanında periyodik randevuları izler.
- Sayfa: `supplier-evaluations.php` - randevu CRUD + durum filtresi
  (planlı/yapıldı/atlandı/vadesi geçti) + KPI kartları + hızlı "Yapıldı" işareti.
- Yardımcılar: `includes/supplier-eval-schedule-functions.php`
  (`qmsSupplierEvalScheduleList/Find/Add/Update/Delete`). `eff_status` goreli
  durumu yansıtır (geçmiş planlı -> overdue); company_id tedarikçiden türetilir.
- Menü: Operasyonlar → Değerlendirme Takvimi. i18n TR+EN eklendi; cache v77.
- Test: `tests/supplier-evaluations.php` (14 kontrol).

Birinci beşlik (Şablon, Duyuru, İç Anket, Yıllık Plan, Tedarikçi Takvimi) tamamlandı.

## Beşliyi derinleştirme / mevcut altyapıya bağlama

### 1. Dashboard duyuru yüzeyi (2026-09-26)
- Dashboard'a yayındaki duyurular için KPI kartı + "Yayındaki Duyurular" liste
  bölümü (`dashboard.php`) eklendi; kapsam `qmsAnnouncementList(..., publishedOnly=true)`.
- `announcement-feed`/`announcement-item` CSS ve i18n eklendi; cache v78.

### 2. İç memnuniyet anketi sonuçları rapor export'una (2026-09-26)
- `report-export-data.php`: `internal_survey_list` bölümü + KPI'ler
  (`internal_survey_count/respondents/avg`). Sorgu, `$fetchRows` yardımcısının
  sona eklediği `AND companies.id = ?` ile çakıştığı için GROUP BY yerine
  ilişkili alt sorgularla (correlated subqueries) yazıldı; tablo alias'sız
  `companies` olarak kullanıldı (scope `companies.id`'i bu yüzden gerektirir).
- `report-export-pdf.php`: "İç Memnuniyet Anketi" detay tablosu + 2 KPI metriği.
- `report-export-xlsx.php`: "İç Anket" çalışma sayfası + 3 KPI etiketi.
- `tests/report-export-data.php` 9 → 12 kontrol (iç anket bölümü doğrulanır).

### 3. Yıllık kalite planı ilerlemesi dashboard'a (2026-09-26)
- Dashboard'a "Yıllık Plan" KPI kartı + "Kalite Planı İlerlemesi" widget'ı (cari yıl
  planları, tamamlanan kalem ve ortalama ilerleme çubuğu) eklendi.
- `plan-progress-*`/`progress-track`/`progress-fill` CSS + i18n; cache v79.

### 4. Tedarikçi takvimi gecikme sayacı (2026-09-26)
- `qmsSupplierEvalScheduleOverdueCount()` eklendi; sidebar'daki
  "Değerlendirme Takvimi" linkine vadesi geçen randevu rozeti (CAPA'daki gibi).
- `tests/supplier-evaluations.php` 14 → 15 kontrol.

### 5. Doküman şablon → yeni doküman entegrasyonu (2026-09-26)
- `document-create.php`'ye "Doküman Şablonu" seçimi eklendi: sirkete göre
  filtrelenen şablon listesinden seçilince başlık/kategori/açıklama
  şablondan ön-doldurulur (inline JS, veri şablon/company eşleşmesiyle).
- `docTemplateSelect*` i18n; cache v80. (Önceki turda ertelenen madde tamamlandı.)

Beşliyi derinleştirme setinin tamamı (1-5) tamamlandı ve commit'lendi.

### Otomatik e-posta bildirimleri: duyuru / anket / tedarikçi gecikme (2026-09-26)
- Yeni bildirim türleri + grupları: `announcement_published` (grup `announcement`),
  `internal_survey_published` (grup `survey`), `overdue_supplier_eval` (grup `supplier`).
  `notifications.php`'te tür/ikon/grup + grup etiketleri ve i18n eklendi.
- `qmsAnnouncementNotifyCompany()`: duyuru yayında olunca şirket kullanıcılarına
  ve super adminlere `announcement_published` (bildirim + tercihe bağlı e-posta).
  `announcements.php`'te yayınla (publish) ve yayında ekleme akışlarında çağrılır.
- `qmsInternalSurveyNotifyCompany()`: anket yayında olunca "doldur" daveti
  (`internal_survey_published`, link `internal-survey-fill.php?fill=ID`).
  `internal-surveys.php`'te yayında ekleme/yayına geçişte çağrılır.
- `scripts/notify-overdue.php`'e vadesi geçen tedarikçi değerlendirme bölümü
  eklendi (`overdue_supplier_eval`, şirket adminlerine; `--all` ile tüm kullanıcılar).
- E-posta hepsi `qmsNotify` → tercihe bağlı (`notification_preferences`) akışını
  kullanır; SMTP etkin değilse sessizce atlanır.
- Testler: `tests/notify-modules.php` (4 kontrol) yeni; `tests/notify-overdue.php`
  4 → 5 kontrol. Cache v81.

### Kalite planı + tedarikçi takvimi Dönem Özeti'ne (2026-09-26)
- `qmsDashboardSummary()`'e yeni özet maddeleri: cari yıl kalite planı hedefleri
  ortalama ilerleme (%) ve gecikmiş tedarikçi değerlendirme sayısı (kapsamlı
  toplu sorgularla; plan ilerlemesi kalem ağırlıklı ortalamadan türetilir).
- `tests/dashboard-trend.php` 17 → 19 kontrol (yeni maddeler doğrulanır).

Dashboard Dönem Özeti artık kalite planı ve tedarikçi takvimini de içeriyor.

### Yeni modül: İyileştirme Fırsatları / OFI (2026-09-26)
- Schema: `improvements` (migration `20261002-improvements.sql`, idempotent runner
  `scripts/migrate-improvements.php`). Sürekli iyileştirme önerileri; CAPA'dan
  farklı olarak uygunsuzluğa bağlanmaz.
- Sayfa: `improvements.php` - CRUD + KPI kartları (açık/uygulanan/toplam) + durum
  filtresi. Alanlar: fayda türü, etki, öncelik, sorumlu, hedef tarih, durum,
  değerlendirme puanı (1-5), sonuç.
- Durum akışı: `submitted / under_review / approved / rejected / implemented / closed`.
- Yardımcılar: `includes/improvement-functions.php` (`qmsImprovementList/Find/Add/
  Update/Delete` + etiket yardımcıları).
- Menü: Operasyonlar → İyileştirme Fırsatları. i18n TR/EN; cache v82.
- Test: `tests/improvements.php` (14 kontrol). Not: 'open' filtresi
  rejected/closed/implemented dışı → açık fırsat; implement sonrası filtreden çıkar.

Bu modül, bildirimle bağlanabilir (yeni yüksek etkili öneri / uygulanma bildirimi)
ve rapor export'una eklenebilir - gelecek adımlar için hazır.

### İyileştirme Fırsatları→ rapor + Dönem Özeti bağlantısı (2026-09-26)
- `report-export-data.php`: `improvement_list` bölümü + KPI'ler
  (`improvement_count/open/implemented`). PDF'ye "İyileştirme Fırsatları" detay
  tablosu + metrik; XLSX'e "İyileştirme" çalışma sayfası + 3 KPI etiketi.
- `qmsDashboardSummary()`'e açık iyileştirme sayısı ve uygulanan sayısı maddeleri
  (kapsamlı toplu sorgularla).
- Testler: `tests/report-export-data.php` 12 → 15, `tests/dashboard-trend.php`
  19 → 21 kontrol.

### OFI bildirimleri + dashboard widget (2026-09-26)
- Yeni bildirim türleri: `improvement_submitted`, `improvement_implemented`
  (grup `improvement` = İyileştirme). `qmsImprovementNotify()` sirket adminlerine
  (qmsNotifyCompanyAdmins üzerinden) tercihe bağlı e-posta ile bildirir.
- `improvements.php`: eklemede `improvement_submitted`; durum 'implemented'
  olunca (öncesi farklıysa) `improvement_implemented` bildirimi.
- Dashboard: "İyileştirme" KPI kartı + açık iyileştirme önerileri için özel OFI
  widget'ı (öncelik rozeti: yüksek/normal/düşük). `ofi-*` CSS, i18n; cache v83.
- `tests/notify-modules.php` 4 → 6 kontrol.

### Yeni modül: Süreç Envanteri / Proses Yönetimi (2026-09-26)
- Schema: `processes` (migration `20261003-processes.sql`, idempotent runner
  `scripts/migrate-processes.php`). Şirket süreçleri: kod, ad, departman, sahip,
  amaç, girdi/çıktı, KPI, gözden geçirme tarihi, durum (active/paused).
- Sayfa: `processes.php` - CRUD + KPI kartları (aktif/toplam) + durum filtresi.
- Yardımcılar: `includes/process-functions.php` (`qmsProcessList/Find/Add/Update/
  Delete` + `qmsProcessStatusLabel`).
- Menü: Operasyonlar → Süreç Envanteri. i18n TR/EN; cache v84.
- Test: `tests/processes.php` (11 kontrol).

### Yeni yüzey: Sözleşme Yönetimi (2026-09-26)
- Schema: `contracts` (migration `20261004-contracts.sql`, idempotent runner
  `scripts/migrate-contracts.php`). Sözleşme kod, ad, taraf, tür (müşteri/
  tedarikçi/diğer), başlangıç/bitiş/yenileme tarihi, tutar, para birimi, durum.
- Sayfa: `contracts.php` - CRUD + KPI kartları (aktif/süresi doluyor/toplam) +
  durum & "süresi doluyor" filtresi; aktif & bitişi ≤60 gün sözleşmelerde
  `expiring` rozeti (görünüm, manuel duruma dokunmaz).
- Yardımcılar: `includes/contract-functions.php` (`qmsContractList/Find/Add/Update/
  Delete` + `qmsContractStatusLabel`). Kapsam: sözleşmenin şirketi.
- Menü: Operasyonlar → Sözleşme Yönetimi. i18n TR/EN; cache v85.
- Test: `tests/contracts.php` (12 kontrol).

### Sözleşme/Proses bildirimleri + sözleşme rapor & Dönem Özeti (2026-09-26)
- Yeni bildirim türleri: `contract_expiring`, `contract_renewal_due` (grup
  `contract`), `process_review_overdue` (grup `process`). `notify-overdue.php`'e
  üç bölüm: süresi yaklaşan (≤60 gün) aktif sözleşmeler, yenileme tarihi yaklaşan
  (≤30 gün) sözleşmeler, gözden geçirme tarihi geçen süreçler.
- `report-export-data.php`: `contract_list` + KPI'ler (`contract_count/active/expiring`).
  PDF'ye "Sözleşmeler" detay tablosu + metrik; XLSX'e "Sözleşmeler" sayfası + 3 KPI.
- `qmsDashboardSummary()`'e süresi yaklaşan sözleşme sayısı maddesi.
- Testler: `notify-overdue.php` 5→7, `report-export-data.php` 15→18,
  `dashboard-trend.php` 21→22 kontrol. i18n grup etiketleri + cache v86.

### Yeni yüzey: Olay Raporlama (Incident Management) (2026-09-26)
- Schema: `incidents` (migration `20261005-incidents.sql`, idempotent runner
  `scripts/migrate-incidents.php`). Kaza/ramak kala/kalite/güvenlik olayları;
  tür, şiddet, durum akışı (açık/inceleniyor/soruşturuluyor/kapandı), konum,
  sorumlu, olay tarihi.
- Sayfa: `incidents.php` - CRUD + KPI kartları (açık/kritik/toplam) + durum
  filtresi; kritik olaylar `overdue-badge` ile vurgulanır.
- Yardımcılar: `includes/incident-functions.php` (`qmsIncidentList/Find/Add/
  Update/Delete` + tür/şiddet/durum etiketleri). Kapsam: olayın şirketi.
- Menü: Operasyonlar → Olay Raporlama. i18n TR/EN; cache v87.
- Test: `tests/incidents.php` (12 kontrol). Bundan sonra bildirim + rapor
  export'una bağlanabilmeye hazır.

### Yeni yüzey: Kalibrasyon & Metroloji takvimi (2026-09-26)
- Schema: `instruments` (migration `20261006-instruments.sql`, idempotent runner
  `scripts/migrate-instruments.php`). Ölçü aletleri: kod, ad, tip, konum,
  kalibrasyon aralığı (ay), son/sonraki kalibrasyon tarihi, sorumlu, durum.
- Sayfa: `instruments.php` - CRUD + KPI kartları (aktif/kalibrasyonu geçen/toplam)
  + durum & "kalibrasyonu geçen" filtresi + hızlı "Kalibre Et" (son=bugün,
  sonraki=bugün+aralık). Geç kalibrasyonlar `overdue-badge` ile vurgulanır.
- Yardımcılar: `includes/instrument-functions.php` (`qmsInstrumentList/Find/Add/
  Update/Delete/Calibrate` + `qmsInstrumentStatusLabel`). Kapsam: aletin şirketi.
- Menü: Operasyonlar → Kalibrasyon & Metroloji. i18n TR/EN; cache v88.
- Test: `tests/instruments.php` (14 kontrol).

### Metroloji/Olay bildirim + rapor + Dönem Özeti bağlantısı (2026-09-26)
- Yeni bildirim türleri: `instrument_calibration_overdue` (grup `instrument`),
  `incident_reported` (grup `incident`). `notify-overdue.php`'e kalibrasyonu geçen
  aletler bölümü; `qmsIncidentNotify()` + `incidents.php`'te yeni olayda admin bildirimi.
- `report-export-data.php`: `instrument_list` + `incident_list` ve KPI'ler. PDF/XLSX'e
  "Ölçü Aletleri" ve "Olaylar" detay tabloları/sayfaları + metrikler.
- `qmsDashboardSummary()`'e geç kalibrasyon ve açık olay maddeleri.
- Testler: `notify-overdue` 7→8, `report-export-data` 18→23, `dashboard-trend` 22→24,
  `notify-modules` 6→7. i18n grup etiketleri + cache v89.

### Yeni yüzey: Sözleşme dosya eki (2026-09-26)
- Schema: `contract_attachments` (migration `20261006-contract-attachments.sql`,
  idempotent runner `scripts/migrate-contract-attachments.php`). Dosyalar
  `storage/contracts/` altinda rastgele adlarla saklanir.
- `contracts.php`'e `?manage=ID` detay görünümü: dosya yükleme + liste + silme;
  liste öğesinde "Dosyalar" butonu. `contract-attachment-download.php` kapsam
  kontrollü indirme sunar.
- Yardımcılar: `includes/contract-functions.php` (`qmsContractAttachments/Find/Add/
  DeleteAttachment`); tümü sözleşmenin şirketine göre kapsamlı.
- i18n TR/EN; cache v90. Test: `tests/contract-attachments.php` (7 kontrol).

### Dashboard metroloji/olay/sözleşme widget'ları (2026-09-27)
- Dashboard'a üç KPI kartı (Kalib. Geçen / Açık Olay / Yaklaşan Sözleşme) ve üç
  özel widget bölümü: Kalibrasyon Takvimi, Açık Olaylar (kritik vurgulu),
  Yaklaşan Sözleşmeler. i18n + cache v91.

### Yeni yüzey: Müşteri Teslimat Performans Kartı (2026-09-27)
- Schema: `delivery_performance` (migration `20261007-delivery-performance.sql`,
  idempotent runner `scripts/migrate-delivery-performance.php`). Müşteri+dönem
  (YYYY-MM) başına toplam/zamanında sipariş, teslim edilen/reddedilen miktar.
- Sayfa: `delivery-performance.php` - CRUD + KPI (zamanında teslim %, sipariş,
  red %) + dönem filtresi + ilerleme çubuğu. `qmsDeliveryOnTimeRate()` türetilir.
- Yardımcılar: `includes/delivery-performance-functions.php`.
- Menü: Operasyonlar → Teslimat Performansı. i18n TR/EN; cache v92.
- Test: `tests/delivery-performance.php` (15 kontrol).

### Olay → uygunsuzluk/CAPA bağlantısı (2026-09-27)
- Migration: `nonconformities.incident_id` sütunu (`20261007-incident-nonconformity.sql`,
  runner `scripts/migrate-incident-nonconformity.php`).
- `qmsIncidentCreateNonconformity()`: olaydan uygunsuzluk oluşturur (source='incident',
  şirket, başlık/açıklama, şiddet) ve `incident_id` ile bağlar; tekrar çağrıda
  mevcut uygunsuzluğu döner (idempotent). `qmsIncidentLinkedNonconformity()`.
- Yeni sayfa `incident-detail.php`: olay bilgileri + "Uygunsuzluk Oluştur" (CAPA
  için uygunsuzluk detayına gider); olay listesinde "Detay" butonu.
- i18n TR/EN; cache v93. Test: `tests/incident-nonconformity.php` (7 kontrol).

### Teslimat Performansı → rapor/özete bağlantı (2026-10-08)
- `includes/report-export-data.php`: `delivery_list` + metrikler `delivery_count`,
  `delivery_ontime_rate`, `delivery_rejected` (teslimat redleri toplamı). Zamanında
  oran ağırlıklı (sipariş üzerinden). Excel'e "Teslimat" sayfası, PDF'e
  "Teslimat Performansı" detay tablosu + metrik hücreleri.
- `reports.php`: yerel teslimat sorgusu; KPI kartı (Zamanında Teslim % + red sayısı)
  ve Şirket Performansı tablosuna Teslimat / Red / Zamanında % sütunları.
- `includes/dashboard-functions.php` Dönem Özeti'ne teslimat maddesi (ağırlıklı
  zamanında % + red toplamı).
- `appIcon()`'a `truck` ikonu. i18n TR/EN; cache v94.
- Test: `tests/delivery-report.php` (6 kontrol).

### Denetim izi raporlama şablonu (2026-10-08)
- Yeni yüzey `audit-trail-report.php`: mevcut `audit_log` salt-okunur verisi üzerinde
  filtreli (şirket/kayıt türü/işlem/tarih) özet raporu; kayıt türü ve işlem
  dağılımı; CSV ve PDF (dompdf) export. RBAC `audit_trail.view` (mevcut sayfa ile aynı).
- `includes/audit-log-functions.php` → `qmsAuditLogAggregate()` (toplulaştırma tek
  kaynağı). Menüye "Denetim İzi Raporu" eklendi; ikon `reports`.
- i18n TR/EN; cache v94. Test: `tests/audit-trail-report.php` (8 kontrol).

### Teslimat reddinden uygunsuzluk / CAPA oluşturma (2026-10-08)
- Migration: `nonconformities.delivery_id` sütunu (`20261008-delivery-nonconformity.sql`,
  runner `scripts/migrate-delivery-nonconformity.php`).
- `qmsDeliveryCreateNonconformity()`: red miktarı > 0 olan teslimat kaydından
  uygunsuzluk (source='delivery', severity='major') ve ardından CAPA; idempotent.
  `qmsDeliveryLinkedNonconformity()`.
- `delivery-performance.php`: listede red varsa "Uygunsuzluk Oluştur" butonu;
  bağlıysa "Uygunsuzluğu Aç".
- `nonconformity-detail.php`: incident/delivery kaynaklarını düzgün işler (NULL
  audit_id kırık denetim bağlantısı yerine olay/teslimat bağlantısı gösterir).
- i18n TR/EN; cache v94. Test: `tests/delivery-nonconformity.php` (10 kontrol).

### Profil bildirim tercihleri yerlesim duzeltmesi + TailAdmin checkbox (2026-10-08)
- `profile.php`'de "Bildirim Tercihleri" bolumu `<main>` disina dusup sol menunun
altında kalıyordu; bolum sayfa akisinda `<main class="page-container">` icinde,
profil-layout'tan hemen sonra tasindi.
- Checkbox'lar TailAdmin tarzina birebir cevrildi: yerel input gorsel olarak
gizlenir, gorunen 20x20 yuvarlatilmis `.box` kutu marka rengine donup beyaz tik
cikarir; focus/hover durumlari var. `assets/css/style.css` `.pref-check`.

### Teslimat red esigi → otomatik bildirim (2026-10-08)
- `scripts/notify-overdue.php`: `delivery_rejection` bolumu. Varsayilan esik %5
  (QMS_DELIVERY_REJECT_THRESHOLD ortam degiskeni veya `--threshold=0-1` argumani).
  Teslim edilen miktara gore red orani esigi asan kayitlar sirket adminlerine
  (ve `--all` ile tum aktif kullanicilara) bildirilir; idempotent.
- `includes/notifications.php`: `delivery_rejection` türü `delivery` grubuna,
  yeni `delivery` grup etiketi (Teslimat/Delivery) + i18n anahtari.
- Test: `tests/notify-overdue.php` 8 ek kontrol ile 14 'e cikti.

### Yeni yuzey: Kalibrasyon & Metroloji sertifika/gecmis (2026-10-08)
- Migration `20261008-instrument-calibrations.sql` + idempotent runner:
  `instrument_calibrations` (instrument, company, tarih, sonraki, sonuc pass/fail,
  sertifika no, laboratuvar, sertifika dosyasi, uygulayan).
- `includes/instrument-calibration-functions.php` (`qmsInstCalib*` on-ekli;
  `qmsCalibration*` equipment modulunde zaten vardi — cakisma onlendi). Kayit
eklenirken aletin son/sonraki kalibrasyon tarihleri guncellenir; sertifika
`storage/calibrations/` altinda saklanir ve `?download=` ile inilir.
- `instrument-calibrations.php` (liste + ekleme + filtre + sertifika indirme) ve
aletler sayfasinda "Gecmis" butonu + menuye "Kalibrasyon Gecmisi".
- Rapor entegrasyonu: `calibration_list` + `calibration_count`/`calibration_fail`
  metrikleri; Excel "Kalibrasyonlar" sayfasi, PDF detay tablosu; Dönem Özeti'ne
  basarisiz kalibrasyon noktasi.
- i18n TR/EN; cache v95. Test: `tests/instrument-calibration.php` (17 kontrol).

### Denetim izi raporunun ana rapor export'una baglanmasi (2026-10-08)
- `includes/report-export-data.php`: donemdeki denetim izi kayitlarini kapsamli
  (secili sirket filtreli) `audit_trail_list` (son 300) + `audit_trail_count`
  metrik + `audit_trail_entity`/`audit_trail_action` ozetleriyle tasir. NOT:
  `$fetchRows` kullanilmadi cunku ORDER BY/LIMIT ile bozulur; kapsam acikca kuruldu.
- Excel "Denetim İzi" sayfasi + PDF "Denetim İzi" detay tablosu + metrik cell.
- Dönem Özeti'ne denetim izi kayit sayisi noktasi.
- Test: `tests/audit-trail-export.php` (6 kontrol).

### Sidebar + header TailAdmin uyumluluk paketi (2026-10-08)
- **Sol menü kaydirma korunur**: `assets/js/sidebar.js` `.sidebar-nav.scrollTop`
  degerini `sessionStorage`'da tutar ve sayfa yuklenince geri yukler; bir menü
  maddesine tiklayinca menü baslangica kaymaz, tiklama konumunda kalir.
- **Sol menuden cikis dugmesi kaldirildi** (`includes/app-sidebar.php` `.sidebar-footer`).
  Cikis artik yalniz header kullanici menusu dropdown'inda.
- **Header birebir TailAdmin**:
  - Zil -> acilir bildirim paneli (son 8 bildirim + "Tumunu Gor") — sidebar.js
    sunucudan gelen `#qmsNotificationRecent` JSON'dan beslenir.
  - Kullanici menusu (avatar + ad + ok, dropdown) TailAdmin tarzi (mevcut).
  - Tema dugmesi gunes/ay SVG ikonuna, dil dugmesi globe + TR/EN etiketine cevrildi
    (theme.js / language.js / sidebar.js + yeni `sun`,`moon`,`globe` ikonlari).
  - `.topbar-button` ve `.notification-bell` TailAdmin ikon-butona (kare, cercevesiz,
    hover bg) yeniden boyutlandirildi; `assets/css/style.css`.
- i18n TR/EN (notificationPanelTitle/Empty/SeeAll); cache v96.

### Header kullanici menusu -> TailAdmin birebir (2026-10-08)
- `sidebar.js` kullanici menusu yeniden kuruldu: tetik avatar + ad/rol (iki satir)
  + chevron SVG; dropdown basligi avatar + ad + rol; maddeler ikon + etiket
  (Profil/user, Hesap Ayarlari/cog, Sifre/key, ayrac, Cikis/logout).
- Header siralama (CSS order, fonksiyon degismez): sagdan sola kullanici, dil,
  tema, uyari zili. cache v99.

### Bildirim Tercihleri profil'den ayrildi -> Hesap Ayarlari (2026-10-08)
- Yeni `account-settings.php`: e-posta bildirim tercihleri formu buraya tasindi
  (ayni POST save_prefs mantigi, ayni qmsMailPrefs/qmsMailPrefsSave - fonksiyon
degismez). Tek kolonlu TailAdmin kart (`settings-layout`).
- `profile.php`'den Bildirim Tercihleri bolumu, save_prefs POST kolu ve ilgili
degiskenler kaldirildi. Profil sayfasi TailAdmin tarzi kart duzeni korur.
- Kullanici menusu "Hesap Ayarlari" artik `account-settings.php`'e gider; profil
  basligina da Hesap Ayarlari kisa yolu eklendi. i18n accountSettingsTitle/Text.
- cache v100.

### Marka: QMS -> QuAmi (2026-10-08)
- Kullanici gorur marka/yazi "QMS" -> "QuAmi" uygulama genelinde degistirildi
  (sidebar brand, login, tum sayfa <title>...>, manifest, PDF/Excel basliklari).

### Bana Atanmislar bos durum kutusu duzeltmesi (2026-10-08)
- `.page-container > .empty-state` ust bosluk (margin-top 20px) eklendi;
  "Size atanmış açık kayıt bulunmuyor." kutusu ustteki kartlara degmiyor.
  Fonksiyonlara dokunulmadi. cache v105.

### TailAdmin birebir checkbox (2026-10-08)
- Tüm dogal `input[type=checkbox]` icin global TailAdmin stili (appearance:none,
  20px yuvarlatilmis kutu, marka rengi + beyaz tik, hover/focus/disabled).
  Duyuru Merkezi "Yayinda", ic anket, e-posta ayarlari ve ofis ayarlari
  checkbox'lari artik birebir ayni. `.pref-check` .box yapisi korunur. cache v106.

### Iç anket sonuclari hata duzeltmesi (2026-10-08)
- internal-surveys.php'de `$manageSurvey['respond_count']`/`avg_rating` anahtarlari
  yoktu (Warning). Sayfada bu degerler artik yanit tablosundan hesaplaniyor
  (`COUNT(DISTINCT user_id)` ve rating sorulari ortalamasi); baslikta ve Sonuclar
  basliginda kullaniliyor. cache v107.

### dashboard musteri performans SQL hatasi duzeltmesi (2026-10-08)
- dashboard.php Donem Ozeti musteri sorgusu `$pdo->query()` ile calistiriliyordu
  ama `$scopeClause` parametreli (`IN (?)`) idi; system_admin gibi scope-u dolu
  kullanicida placeholder doldurulamadigi icin SQL hatasi veriyordu. Sorgu
  `prepare + execute($scopeParams)` yapildi. system_admin render testi gecti.
  cache v147.

### notify-overdue e-posta bildirimi (2026-10-08)
- `scripts/notify-overdue.php` `$notify` closure'i artik bildirim uretirken ilgili
  kullanicinin e-postasina da gonderir (mail yapilandirmasi etkinse: config/mail.php
  enabled true + host tanimli). mailer require eklendi. E-posta hatasi bildirim
  uretimini bozmaz. notify-overdue testi 15/15. cache v146.

### Dashboard Donem Ozeti blogu + Denetim izi baglantisi (2026-10-08)
- Donem Ozeti konsol karti: Sirket Panosu KPI'lari (acik NC/sikayet/risk, aktif
  dokuman, dolan sozlesme, gecik kalibrasyon) + Musteri & Yetkinlik (dusuk
  performans musteri, ort. red % , yetkinlik vadesi gecen) - sirket scope.
- Musteri/Yetkinlik metrikleri ilgili yüzeylere linkli.
- Denetim izi Dönem Ozeti baglantisi zaten mevcuttu (audit_trail_count metric +
  Excel/PDF Denetim Izi bolumu) - dogrulandi, ek is gerekmedi.
- i18n TR/EN, CSS period-overview-grid. Render test gecti. cache v145.

### Dashboard Yetkinlik vadesi widget (2026-10-08)
- `dashboard.php` altina "Yetkinlik Vadesi Gecenler" console-card eklendi
  (staff_members uzerinden sirket scope; kisi + yetkinlik + sirket + vade listesi,
  Yetkinlik Matrisi linki). Bosken "Vadesi gecen yetkinlik yok." gosterir.
- i18n TR/EN. Render test gecti. cache v144.

### Dashboard rapor entegrasyonu: Yetkinlik vadesi (2026-10-08)
- Sirket panosu KPI'lari (NC/sikayet/risk/dokuman) ve musteri performansi
  (delivery) rapor export'ta zaten mevcuttu; eksik olan Yetkinlik vadesi eklendi.
- `report-export-data.php`: `competency_overdue_list` (vadesi gecen yetkinlikler,
  sirket scope `s.company_id`).
- `report-export-xlsx.php`: "Yetkinlik Vade" worksheet.
- `report-export-pdf.php`: "Yetkinlik Vadesi" tablo bolumu.
- report-export-data testi 23/23 gecti. cache v143.

### Denetim izi sozuyle + Bildirim merkezi (2026-10-08)
- Denetim izi raporu sebeni: `qmsAuditLogList`'e `actor_user_id` filtresi;
  audit-trail-report.php'ye Kullanici (aktor) filtre dropdown'i + export
  buildQuery + i18n. Rapor kullanicilara gore filtrelenip CSV/PDF disa verilebilir.
- Bildirim merkezi: `qmsNotificationTypes`'e `overdue_competency` (ikon users,
  grup competency); yeni `competency` grubu etiketi 'Yetkinlik' + i18n keys +
  language.js TR/EN. Bildirim merkezinde yetkinlik bildirimleri artik gorunur
  ikon/grup etiketiyle. cache v142.

### Musteri Teslimat / Performans Karti (2026-10-08)
- Yeni yuzey `customer-delivery-performance.php`: musteri bazli teslimat ozeti
  (zamaninda teslimat %, red %, toplam siparis, donem sayisi). KPI kartlari:
  musteri sayisi, ort. zam. teslimat %, ort. red %, dusuk performansli musteri
  (red esigi varsayilan %5; QMS_DELIVERY_REJECT_THRESHOLD). Dusuk performansli
  musteri karti kirmizi vurgulanir.
- operations.view RBAC + sidebar linki (Teslimat Performansi altina) + i18n TR/EN.
  Render test gecti. cache v141.

### Sozlesme coklu dosya yukleme (2026-10-08)
- `qmsContractAddAttachments()` eklendi: `$_FILES["attachment_files"]` (name[],
  tmp_name[]) icerisindeki her dosyayi tek tek isler, kac tanesinin eklendigini
  doner. Tekli `qmsContractAddAttachment` korundu.
- `contracts.php`: dosya input `multiple` yapildi (`attachment_files[]`); POST
  coklu fonksiyonu cagirir. Mevcut tek dosya akisi/limitleri (10 MB) korunur.
  contract-attachments testi 7/7 gecti. cache v140.

### Yetkinlik vade bildirimi (notify-overdue entegrasyonu) (2026-10-08)
- `scripts/notify-overdue.php`'ye `overdue_competency` bolumu eklendi: vadesi gecen
  yetkinlikler (staff_competencies.next_assessment_date < bugun) icin bildirim
  uretilir. Personel ile eslesen kullanici varsa ona, yoksa sirket adminlerine;
  --all modda tüm sirkete. Idempotent (okunmamis ayni tur+link atlanir).
- `tests/notify-overdue.php`: staff_members/staff_competencies temp, vadesi gecen
  yetkinlik; ilk run 6 bildirim, idempotent, overdue_competency testi. 15/15 gecti.
  cache v139.

### Arama yapilabilir dropdown (Secenek A) (2026-10-08)
- Yeni `assets/js/searchable-select.js`: 4+ secenekli `<select>` elemanlarini
  acilinca en ustte "Ara..." kutusu olan ozel listeye donusturur. Native select
  gizli kalir, secimi onun value/change'ine yazar (form gonderimi/is mantigi
  bozulmaz). Kucuk (<4 secenek) listeler native kalir.
- CSS: .sb-searchable/.sb-trigger/.sb-list/.sb-search/.sb-option (token tabanli).
- `app-sidebar.php` uzerinden tum sayfalara dahil edildi. cache v138.

### Egitim Sablonu sirket dropdown bos + Kaydet bosluk (2026-10-08)
- `qmsVisibleCompanyIds()` super admin icin null doner (kisit yok = tumu);
  kod null'i "sirket yok" gibi isliyordu -> sirket dropdown bos cikiyordu.
  `training-templates.php` ve `competency-matrix.php` null'i "tum sirketler" olarak
  ele alacak sekilde duzeltildi (isAllCompanies).
- `.form-grid + .form-actions { margin-top: 16px }` eklendi; Egitim Sablonlari
  sayfasinda Kaydet dugmesi ustteki kutuya degmiyor. Fonksiyonlara dokunulmadi.
  cache v137.

### Eğitim Şablonu - Eğitim / Yetkinlik entegrasyonu (2026-10-08)
- Migration: `trainings.template_id` (nullable) + `trainings.target_competency`.
- `training-create.php`: "Sablondan Doldur" secimi (gorsel JS orn doldurma) +
  hedef yetkinlik alani. Tenant guvenligi: secilen sablonun sirkete ait oldugu
  dogrulanir; template_id + target_competency INSERT'e yazilir.
- `training-detail.php`: hedef yetkinlik KPI karti (target_competency varsa).
- i18n TR/EN. Lint gecti. cache v136.

### Metin tarihleri Turkce gun ay yil formatina cevrildi (2026-10-08)
- Yeni `assets/js/dates.js`: gorunen metin (text node) duzeyinde ISO `YYYY-MM-DD`
  tarihlerini Turkce "15 Mayis 2026" bicimine cevirir. `<input type="date">`
  degerine ve form gizli alanlarina dokunmaz (backend ISO okumaya devam eder).
- `app-sidebar.php` sonuna dates.js dahil edildi (sidebar her sayfada oldugundan
  tum sayfalarda geçerli). Fonksiyonlara/is mantigina dokunulmadi. cache v135.

### Egitim detay katilimci dugmeleri bosluk duzeltmesi (2026-10-08)
- `.checklist-item-form .form-actions { margin-top: 14px }` eklendi; Egitim
  detay sayfasinda "Katilimciyi Kaydet" ve "Kaldir" dugmeleri artik ustteki edit
  kutusuna degmiyor. Fonksiyonlara dokunulmadi. cache v134.

### Eğitim Şablonu / Yetkinlik Matrisi modulu (2026-10-08)
- Yeni tablo `training_templates` (migration: scripts/migrate-training-templates.php).
- `training-templates.php`: yeniden kullanilabilir eğitim tanimlari kütüphanesi
  (CRUD, sirket kapsami, CSRF).
- `competency-matrix.php`: personel x yetkinlik gorunumu (staff_competencies), vadesi
  gecen yetkinlikler vurgulaniyor (next_assessment_date < bugun), 3 KPI kartı.
- RBAC eylemleri: `training_templates.manage`, `competency_matrix.view` (super+system).
- Sidebar linkleri + i18n TR/EN. RBAC testi 24/24; lint + render gecti. cache v133.

### Izinler matrisine filtre/arama (2026-10-08)
- `permissions.php`'ye eylem arama kutusu + rol kolon filtre pilleri eklendi.
  Filtre client-side (JS) ile gorunumu gizler; tum toggle'lar DOM'da kalir,
  boylece kaydetme akisi bozulmaz (gizli eylemlerin override'i kaybolmaz).
  Tabloya `data-role`, `#permTable` eklendi; CSS (perm-filter-bar/perm-search).
  cache v132.

### Denetci Panosu (2026-10-08)
- `my-audits.php` 4 KPI kartina cikti: Atanan, Devam Eden, Tamamlanan,
  Geciken (tarih bugun oncesi + tamamlanmamis kontrol listesi). Listedeki
  geciken denetim kartlarina "Gecikti" rozeti eklendi (gorsel; fonksiyonlara
  dokunulmadi). i18n TR/EN. cache v131.

### Sirket Kullanicisi Sirket Panosu (Secenek A) (2026-10-08)
- Yeni sayfa `company-overview.php`: sirket kullanicisinin kendi sirketine ozel
  baslangic panosu (acik uygunsuzluk/sikayet/risk, aktif dokuman, kalibrasyon
  gecen alet, surem dolan sozlesme, denetimler, tedarikciler). KPI'lar kendi
  `company_id` kapsaminda SQL COUNT ile.
- `qmsLandingPage()`: company_user -> company-overview.php (match); sidebar
  Dashboard linki company_user icin company-overview.php'ye isaret eder.
- i18n TR/EN anahtarlari; qmsCanSession('dashboard.view') + role company_user
  guard. Render test: id=8 company_user ile HTML uretildi, sidebar link dogru.
  cache v130.

### Izinler sayfasi Varsayilanlara Don dugmesi (2026-10-08)
- `permissions.php`'ye "Varsayilanlara Don" sekonder dugmesi eklendi; tüm
  override'lari siler ve izinler kod varsayilanlarina doner. Onay (confirm) ve
  CSRF korunur (ayni scope). `qmsPermissionResetOverrides()` backend'e eklendi.
  Test: override yazildi, reset sonrasi count=0 ve qmsCan varsayilana dondu.
  cache v129.

### RBAC kalici test eklenmesi (2026-10-08)
- `tests/permissions.php` 17 -> 24 kontrol olarak genisletildi: yeni sol menü
  yüzey eylemlerinin varsayilan rolleri, `search.view` kaldirildigi,
  override kaydetme/uygulama akisi (operations.view kapatilinca system_admin
  erisemez, approvals.manage acilinca company_user erisebilir) ve süper admin'in
  override ile kisitlanmadigi. Test, mevcut override'lari koruyup kendi test
  degerlerini temizler.
- `qmsPermissionOverrides()` cagrisina test amaci `$forceReload` parametresi
  eklendi (cache'i yeniden yukler; varsayilan davranis degismez). 24/24 gecti.
  cache v128.

### permissions.php Kaydet dugmesi bosluk duzeltmesi (2026-10-08)
- `.report-table-wrap + .form-actions { margin-top: 16px }` eklendi; Izinler
  sayfasinda "Izinleri Kaydet" dugmesi artik ustteki matris kutusuna degmiyor.
  Fonksiyonlara dokunulmadi. cache v127.

### Sol menu RBAC'e gore gizleniyor (2026-10-08)
- `app-sidebar.php`'de helper `qmsSidebarVisible($action)` eklendi (qmsCanSession'a
  dayali) ve `access.php` require edildi.
- Sol menudeki 50 sidebar-link, ilgili RBAC eylemine gore `<?php if
  (qmsSidebarVisible('...')): ?>` ile sarildi; pasif yapilan yuzeyin menü dugmesi
  artik gorunmuyor, aktif yapilinca gorünüyor. Marka linki haric tutuldu.
- Render testi: system_admin icin operations.view kapaliyken risk/dokuman/CAPA
  baglantilari gizleniyor, notifications/reports gorunuyor (PASS). cache v126.

### Sol menu yuzeyleri RBAC'e baglandi (Secenek A) (2026-10-08)
- Matristeki tum eylemler ilgili sayfalara baglandi; sol menudeki her yuzey
  artik bir izinle kontrol ediliyor (Secenek A: operasyon modulleri operations.view
  grubunda).
- `includes/access.php` sonuna `require permissions.php` eklendi (guard her
  sayfada hazir; circular guvenli).
- Yeni matris eylemleri: `my_assignments.view`, `overdue.view`,
  `checklist_templates.view`, `external_audits.view`, `approvals.manage`,
  `document_reviews.manage`, `document_approvals.manage`, `admin.office`,
  `admin.mail`; `search.view` kaldirildi (search.php operations.view'a baglandi).
- 48 sayfaya `qmsRequirePermission(<eylem>)` eklendi (script ile; 9 sayfaya
  ayrica access.php require). Varsayilan roller sidebar mantigiyla eslesir;
  super admin kendini kilitleyemez. Tum degerislen dosyalar lint gecti, RBAC
  testi 17/17, login'siz sayfalar login.php'ye yonleniyor. cache v125.

### RBAC toggle tiklanma duzeltmesi (2026-10-08)
- RBAC toggle'lari `<span>` idi; span label olmadigindan tiklaninca input
  tetiklenmiyordu (input gizli oldugundan). `<label>` yapildi -> implicit label
  ile tiklamalar input'u ac/kapa yapabiliyor. cache v124.

### RBAC izinleri toggle ile düzenlenebilir (2026-10-08)
- Izinler (RBAC) sayfasi salt-okunurdu; super admin artik her eylem x rol
  hucreisini TailAdmin toggle switch ile ac/kapa yapabiliyor.
- Yeni tablo `permission_overrides` (action, role, allowed).
- `includes/permissions.php`: `qmsPermissionOverrides()` (DB override yukler),
  `qmsCan()` override'i uygular (statik cache; tablo yoksa varsayilanlar),
  `qmsPermissionSaveOverrides()` toplu yazar.
- Super admin rolu override edilmez (hep varsayilan) -> kendini kilitleyemez;
  sayfada super admin sutunu kilitli/checked gorunur. CSRF + yetki (super admin)
  korunur. cache v123.

### Sirket kullanicisi rapor export audit count hatasi (2026-10-08)
- `report-export-data.php` satir 737'deki `SELECT COUNT(*) FROM audit_log`
  sorgusu `companies.id IN (...)` scope'u kullaniyordu ama `companies` tablosunu
  JOIN etmiyordu; sirket kullanicisinda scope doluyken `Unknown column
  'companies.id'` fatal hatasi veriyordu. Sorguya `LEFT JOIN companies ON
  companies.id = audit_log.company_id` eklendi. Diger tum sorgular zaten
  companies JOIN iceriyordu (sadece bu count eksikti). Test: sirket scope'u ile
  count sorgusu dogrulandi, 23/23 regresyon gecti. cache v122.

### Hesap Ayarlari kategori secimleri toggle switch (2026-10-08)
- "E-posta alinacak kategoriler" (`email_categories[]`) checkbox'lari da
  TailAdmin toggle switch'e cevirildi (toggle-field + toggle-slider). input
  name/value/checked korundu -> backend fonksiyonu aynai. Kullanici onayladigi
  icin coklu secimler de toggle olarak gorunuyor. cache v121.

### Hesap Ayarlari ana anahtar toggle switch (2026-10-08)
- "E-posta bildirimleri al" (`email_notifications`) checkbox'i TailAdmin tarzi
  toggle switch'e cevrilirldi (.toggle-field + .toggle-slider). input name/checked
  korundu -> backend fonksiyonu aynai calisir. Kategori secimleri (`email_categories[]`)
  coklu secim oldugu icin checkbox olarak kaldi. cache v120.

### Checkbox satir hizasi duzeltmesi (2026-10-08)
- `.form-field` `flex-direction: column` kullandigindan checkbox ve span alt alta
  diziliyordu; checkbox span'in solunda degil. `inline-flex` kulalrina
  `flex-direction: row` eklendi -> checkbox kutu ayni label icindeki metnin
  solunda, ayi hizada duruyor. Fonksiyonlara dokunulmadi. cache v119.

### Checkbox boyutu/hizasi duzeltmesi (2026-10-08)
- `.form-field input` kurali checkbox'i da kapsadigindan onlara `width:100%`,
  `min-height:44px`, padding ve border uyguluyordu; bu yuzden onay kutulari
  devasa ve metinle dugunsuz gorunuyordu.
- `.form-field input[type="checkbox"]` reset kurali eklendi -> 20x20 TailAdmin
  kutucuguna doner; metnin solunda (inline-flex) durur. Fonksiyonlara
  dokunulmadi. cache v118.

### Tum checkbox'lar Bildirim Tercihleri ile ayni stil (2026-10-08)
- Global `input[type=checkbox]` zaten TailAdmin (pref-check .box ile ayni)
  idi; eksik olan `focus-visible` border-color ve etiket hizalamasi eklendi.
- `.checkbox-field`, `.switch-label` ve checkbox iceren `.form-field > span`
  icin pref-check gibi `inline-flex + gap:10px` verildi -> tum onay kutulari
  Bildirim Tercihleri'ndekilerle birebir ayni gorunuyor. Fonksiyonlara
  dokunulmadi. cache v117.

### secondary-button text underline kaldirildi (2026-10-08)
- `.secondary-button` ve `.secondary-button:hover` icin `text-decoration: none`
  eklendi; link olan secondary-button'larda (genel `a:hover` kuralindan gelen)
  alt cizgi artik gorunmuyor. Fonksiyonlara dokunulmadi. cache v116.

### secondary-button-sm sinifi kaldirildi (2026-10-08)
- `secondary-button secondary-button-sm` kalibindaki `secondary-button-sm` sinifi
  15 dosyada (41 yerde) kaldirildi; dugmeler normal secondary-button boyutuna
  dondu. `.secondary-button-sm` CSS kurali da artik kullanilmiyor. Fonksiyonlara
  dokunulmadi. cache v115.

### Personel Detayi form.message bosluk duzeltmesi (2026-10-08)
- `main > .form-message { margin-top: 16px; }` eklendi; Personel Detayi
  sayfasinda form-message (basari/hata bildirimi) ustteki KPI kutularina
  degmiyor. `<main>`'in dogrudan cocugu olan form-message'lari hedefler;
  form icindekileri etkilemez. Fonksiyonlara dokunulmadi. cache v114.

### Vadesi Gelen Isler export dugmeleri bosluk duzeltmesi (2026-10-08)
- `.form-actions` icin `gap: 10px` eklendi; Vadesi Gelen Isler sayfasinda
  "Excel Indir" ve "PDF Indir" dugmeleri artik birbirine degmiyor. Tüm dugme
  gruplarinda tutarli bosluk saglar. Fonksiyonlara dokunulmadi. cache v113.

### Denetim izi/reporu sol menu aktif duzeltmesi (2026-10-08)
- `audit-trail.php` `$activeNav = "audit-trail"` (tire) idi; sidebar anahtari
  `audit_trail` (alt cizgi) oldugundan eslesmiyor, aktif gorunmuyordu.
  `audit-trail.php` -> `audit_trail`, `audit-trail-report.php` ->
  `audit_trail_report` yapildi. Ikisi de artik tiklaninca sol menude aktif.
  Fonksiyonlara dokunulmadi. cache v112.

### Dokuman Gozden Gecirme Gecmisi bosluk duzeltmesi (2026-10-08)
- `.console-card + .console-card { margin-top: 16px; }` eklendi; Dokuman Gozden
  Gecirme Merkezi'nde "Gozden Gecirme Gecmisi" kutusu artik ustteki kutuya
  degmiyor. Ardısık konsol kartlarini hedefler; fonksiyonlara dokunulmadi.
  cache v111.

### Yillik Kalite Plani filtre dropdown TailAdmin uyumlu (2026-10-08)
- `.filter-inline select` TailAdmin stilinde yapildi: `appearance:none` + ozel
  chevron (SVG data-uri) ile dogal ok kaldirildi; yuvarlak kose, hover border ve
  focus halkasi (`--shadow-focus-ring`) eklendi. Sadece Planlar kutusundaki Yil
  filtresini hedefler; diger select'leri etkilemez. Fonksiyonlara dokunulmadi.
  cache v110.

### COQ trendi bosluk duzeltmesi (2026-10-08)
- `main > .report-panel { margin-top: 22px; margin-bottom: 22px; }` eklendi;
  "Aylik COQ Trendi" kutusu artik ustteki KPI kartlarina ve alttaki kutulara
  degmiyor. `<main>`'in dogrudan cocugu olan tek basina rapor kutularini hedefler;
  grid icindeki `.report-layout` / `.two-col` panellerini etkilemez.
  Fonksiyonlara dokunulmadi. cache v109.

### Ic anket "Soru Ekle" bosluk duzeltmesi (2026-10-08)
- `form + .admin-list` ust bosluk (margin-top 16px) eklendi; Soru Ekle dugmesi
  artik alttaki soru kutusuna degmiyor. Fonksiyonlara dokunulmadi. cache v108.
- Kod kimlikleri KORUNDU: `qms*` on-ekleri ve `QMS_*` PHP sabitleri + lower-case
  `qms` dosya/cache adlari ve storage/documents icerigi degismedi. cache v104.

### Dokuman gozden gecirme dark mod duzeltmesi (2026-10-08)
- `.filter-pill` arka plani tanimsiz `var(--surface, #fff)` idi -> hep beyaz
  kaliyordu; `--surface-color` yapildi. `.filter-pill.active` `brand-50`/`brand-700`
  yerine dark moda uyan `--brand-soft`/`--brand-soft-text`.
- `.review-submit-form` arka plani sabit `--gray-50` idi -> `--surface-strong-color`.
- cache v101.

### Profil page dark mod foto/cover + Sistem Ozeti kompakt (2026-10-08)
- `.profile-avatar` arka plani `--brand-50`/rengi `--brand-500` -> dark moda uyan
  `--brand-soft`/`--brand-soft-text`; `.profile-cover` dark override
  (`--brand-700` -> `--gray-900` gradyan) eklendi. Fonksiyonlara dokunulmadi.

### Dokuman dagitim kontrolu filtre cakismasi duzeltmesi (2026-10-08)
- `.filter-bar`'a ust bosluk (margin-top 18px) eklendi; filtre dügmeleri
  (Tumu/Dagitilmis/Iade/Gecersiz) artik ustteki istatistik kartlarina degmiyor.
  Fonksiyonlara dokunulmadi. cache v103.
- `.profile-card .record-card-grid` Sistem Ozeti kartlari kompakt (min 140px,
  min-height 118px, kucuk ikon) hale getirildi. cache v102.
- `appIcon()`'a `user`,`cog`,`key`,`chevronDown` ikonlari; app-sidebar gizli
  ikon span'lari + i18n `accountSettingsMenuLabel`.
- `.user-menu-*` TailAdmin olcultur (iki satir id, ikonlu madde, ayrac). cache v98.

## Yönetim kokpiti / Genel Bakış (dashboard eklentisi)

Executive overview added to `dashboard.php` (the dashboard already had audit /
nonconformity / complaint / action / training trend strips). Two new panels make
it a management cockpit:

- **COQ mini trend:** the `qmsDashboardTrend()` buckets now also carry monthly
  `prevention` / `appraisal` / `internal_failure` / `external_failure` / `cost_total`
  sums (one grouped `quality_costs` query; all other series untouched). A stacked
  monthly COQ bar chart + category legend renders from those buckets, linking to
  `quality-cost-trend.php`.
- **Hedef vs Gerçekleşen KPI matrisi:** new `qmsCockpitKpiMatrix()` in
  `includes/dashboard-functions.php` returns, for every scoped company that has at
  least one `performance_targets` row for the current year, a per-KPI
  target/actual/on-track comparison. Actuals come from the report engine
  (`buildReportExportData` per company - the single source of truth per
  `performance-functions.php`), targets from `qmsPerformanceTargets`, and the
  higher-better logic from `qmsPerformanceOnTrack`. Companies without targets are
  omitted. Rendered as one card per company with on-track/off-track badges and a
  compact table.
- i18n TR/EN for both panels; new `.cockpit-*` / `.compact-table` /
  `.secondary-button-sm` CSS (TailAdmin-flavored); cache -> v60.
- No schema change. The dashboard already computed `$reportMetrics` once; the
  cockpit KPI matrix runs a per-company report build only for companies that have
  targets.
- 10 checks (`tests/dashboard-cockpit.php`): trend buckets now include COQ
  category sums and the other series are not disturbed, cockpit lists only
  target-set companies, per-KPI target/on-track, and the all-on-track bookkeeping.

## Operasyonel iyilestirme paketi (2026-09-26, dort is)

Four follow-ups requested in sequence: an overdue workbench, CAPA visibility incl. a
preventive type, an auditor-workload panel, and a stronger notification center.

### 1. Vadesi Gelen / Geciken Isler workbench (`overdue.php`)
- `includes/due-workbench-functions.php` -> `qmsOverdueWorkbench()` aggregates
  overdue records across 7 modules (all scoped): corrective actions, nonconformities,
  trainings, equipment calibration, external-audit findings, documents (review due)
  and complaints. Each row is overdue = due/planned/review/next-calibration date
  before today AND still open (closed/cancelled/archived/out_of_service excluded).
- `overdue.php`: summary cards per module + grouped lists that link to each record's
  detail page. Sidebar entry "Vadesi Gelen İşler" (non-auditor nav).
- Also hosts the auditor workload panel (task 3).

### 2. CAPA gorunurlugu + preventive type
- Sidebar CAPA entry re-labelled to "Düzeltici & Önleyici Faaliyet (CAPA)" and now
  shows an overdue badge (`qmsOverdueActionCount`, one query, non-auditor nav).
- Added `action_type` (corrective/preventive) to `corrective_actions` via an
  idempotent migration (`20260926-capa-action-type.sql` + `migrate-capa-action-type.php`;
  existing rows stay 'corrective'). CAPA types/labels/i18n in `capa-functions.php`;
  create + detail forms set/edit it (validated against `QMS_CAPA_TYPES`);
  `actions.php` shows a type badge. Uses `--violet-soft` token.

### 3. Denetci is yuku
- `qmsAuditorWorkload()` (scoped per auditor company): assigned active audits, open
  nonconformities and open actions from those audits. Rendered as a panel on the
  overdue workbench page (one card per auditor).

### 4. Bildirim merkezi guclendirmesi
- `qmsUserOverdueAssignments()` returns the logged-in user's own assigned overdue
  corrective actions, complaints and equipment calibrations (live-computed, no cron
  or schema change). `notifications.php` shows a "Geciken İşlerim" block above the
  notification feed, linking each item to its record and to `overdue.php`.
- No cron/scheduler exists; overdue awareness is computed on page view rather than
  pushed as stored notifications.
- New tests: `tests/due-workbench.php` (7), `tests/capa-type.php` (5),
  `tests/auditor-workload.php` (7), `tests/user-overdue.php` (4).

## Gecikme bildirimi cron job + Excel/PDF exportu

### Otomatik gecikme bildirimleri (`scripts/notify-overdue.php`)
CLI / scheduled-task script that scans all companies' overdue records and inserts
real `notifications` rows. Called from Windows Task Scheduler / cron:
`php -f scripts/notify-overdue.php`. Idempotent: a (user, type, link) pair is skipped
if an unread notification already exists for it, so repeated runs don't flood.
Recipients: corrective actions / complaints / equipment calibrations go to the
responsible user when set, otherwise to the company's system admins via
`qmsNotifyCompanyAdmins`; nonconformities, trainings, findings and document reviews go
to company admins. New `overdue_*` notification types were added to
`qmsNotificationTypes()` (icon + group) so the center renders them out of the box.
Cache bumped v64 -> v66 for the JS/CSS changes in this package.

### Overdue/auditor-workload export (`overdue-export.php`)
`format=xlsx|pdf` (buttons on `overdue.php`). XLSX: an `Özet` sheet plus one sheet per
overdue module and a `Denetçi İş Yükü` sheet, built with the shared `xlsx-writer`.
PDF: Dompdf summary tables (module counts, each module's overdue rows, auditor
workload). Reuses the scoped `qmsOverdueWorkbench()` + `qmsAuditorWorkload()`.
Denied for the auditor role. Verified by a CLI smoke run (valid worksheets / %PDF),
on top of the function-level temp-table suites.
- Test: `tests/notify-overdue.php` (4): first run creates one notification, second
  run is idempotent (no duplicates).

## Uretkenlik / denetim paketi (2026-09-26, bes is)

### 1. Kapanis kaniti / denetim raporu paketi
- `closure-package-export.php?nonconformity_id=N` -> PDF bundling the NC root cause,
  corrective/preventive actions (status, timestamps, verifier, verification note) and
  evidence files. `includes/closure-package-functions.php` (`qmsClosurePackageData`,
  scoped). Button on `nonconformity-detail.php`. Each export logs an
  `audit_trail` entry (`closure_package_exported`).

### 2. Global arama + benzer vaka gelistirmesi
- `qmsSearchTypes()` gained `personnel`, `review` and `finding` (external-audit,
  joined) record types, so global search and similar-cases now cover them.

### 3. Bana atanmislar merkezi
- `qmsMyAssignments()` lists the user's open corrective actions, complaints and
  equipment calibrations (by `responsible_user_id`) with overdue flags.
  `my-assignments.php` page + sidebar; overdue rows highlighted.

### 4. Dashboard kisisel ozet widget'i
- `dashboard.php` shows "Kişisel Özet": assigned-open count, my overdue count and
  pending document approvals (reuses `qmsUserOverdueAssignments`/`qmsMyAssignments`).

### 5. Performans indexlerı
- `scripts/migrate-performance-indexes.php` (idempotent) added 8 composite indexes
  for the new overdue/COQ/CAPA queries: corrective_actions(status,due_date) and
  (nonconformity_id,status), nonconformities(status,due_date),
  complaints(status,due_date), documents(review_date,status),
  trainings(planned_date,status), external_audit_findings(status,due_date),
  quality_costs(company_id,incurred_on). Verified idempotent (second run adds 0).

Tests: `tests/closure-package.php` (8), `tests/search-enhanced.php` (6),
`tests/my-assignments.php` (5).

## SMTP e-posta bildirimleri - E-posta Ayarları

E-posta gonderimi uzeri eklendi: her `qmsNotify()` bildirimi, SMTP etkinse aliciya
e-posta da gonderir (gonderme asla cokmez; bildirim kaydi her zaman yazilir).

- `config/mail.php`: `qmsMailDefaults()` / `qmsMailConfig()` - ayni desenle
  (`storage/mail/settings.json` + `QMS_MAIL_*` env override).
- `includes/mailer.php`: bagimliliksiz minimal SMTP istemcisi
  `qmsMailSend()` (EHLO / STARTTLS / AUTH LOGIN / MAIL FROM / RCPT / DATA) +
  `qmsMailNotificationContent()` HTML/duz metin sablonu. XAMPP/Windows uyumlu;
  gercek baglantida basarisizlik false doner, aga gidilmeyen bos/kapali durumlar
  da aninda false doner.
- `includes/notifications.php`: `qmsNotify()` sonrasi `qmsMailNotifyUser()` alici
  e-postasini bulup gonderir; `qmsNotifyCompanyAdmins` otomatik kapsanir.
- `mail-settings.php`: super/system admin icin SMTP formu (storage'dan okur/yazar)
  + "Test E-postası Gönder" (giris yapan hesabin kendi adresine). Sidebar > Sistem
  Yonetimi > E-posta Ayarlari. CSRF korumali. Gmail ipucu formda.
- `scripts/test-mail.php alici@ornek.com`: CLI test gonderimi (SMTP hazir degilse
  aciklar).
- Env override ornek: `QMS_MAIL_ENABLED=1 QMS_MAIL_HOST=smtp.example.com ...`.
- `tests/mailer.php` (9): defaults, disabled/empty-host gonderim aga girmeden false
  doner, env override, icerik uretici (html/plain/link), ve qmsNotify mail kapaliyken
  bildirim satirini yine yazar.

## E-posta paketi devam: rapor teslimi, tercihler, denetim programi, sablon (2026-09-26)

### A. Periyodik otomatik rapor e-postasi
- `qmsMailSend()` now accepts `$attachments` (multipart/mixed) so files can ride along.
- `config/mail.php` gained `base_url` (default `http://localhost/qmsx/`) used to make
  email links absolute.
- `scripts/send-daily-report.php`: builds a management PDF (overdue + auditor workload
  + KPI metrics via `buildReportExportData`) with Dompdf and emails it as attachment to
  every active system/super admin. If mail is off it just reports the generated PDF.

### B. Kullanici eposta tercihleri
- New table `notification_preferences` (user_id unique, `email_enabled`,
  `email_categories` JSON or NULL=all) via `20260926-notification-preferences.sql` +
  runner. `qmsMailPrefs()` reads, `qmsMailPrefsSave()` upserts.
- `qmsNotify()` routes through `qmsMailNotifyUserPrefsAware()`: mail off -> early
  return; prefs off -> skip; category filter applied (
  `qmsNotificationTypes()[type]['group']`). Per-user UI on `profile.php` (Bildirim
  Tercihleri) - toggle + category checkboxes. CSRF-scope `profile`.

### C. Denetim programi otomasyonu
- `scripts/audit-program-reminders.php [--all]`: for active current-year programs with
  no audits added -> `audit_program_reminder` to company admins; for past-year
  draft/active programs -> `audit_program_due`. Idempotent (unread dedupe). New
  notification types added to `qmsNotificationTypes()`.

### D. E-posta sablonu + gercek URL
- `qmsMailNotificationContent()` upgraded to a branded template: header, optional
  category pill, CTA "Kaydı Aç", footer with "Uygulamayı Aç" (base_url).
- Relative notification links are made absolute in `qmsMailNotifyUser()` via
  `base_url`; `mail-settings.php` has a `base_url` field.
- Tests: `tests/mailer.php` grew 9 -> 11 (branded template + footer link);
  `tests/notification-preferences.php` (7); `tests/audit-program-reminders.php` (5).
- `--all` mode: `php scripts/notify-overdue.php --all` also pushes each overdue item
  to every active user of the owning company (plus super admins) besides the
  responsible/admin recipients. Default mode stays role-aware.
- Localhost scheduling: a Windows Task Scheduler task `QuAmiOverdueNotify` (daily
  08:30) runs `C:\xampp\php\php.exe -f C:\xampp\htdocs\qmsx\scripts\notify-overdue.php`.
  `schtasks` needs `MSYS_NO_PATHCONV=1` when called from Git Bash (forward-slash
  args get mangled into paths otherwise). Task runs in interactive logon mode.

## Security hardening (2026-09-19)

### CSRF

- A shared helper now exists: `includes/csrf.php` with `qmsCsrfToken($scope)`,
  `qmsCsrfVerify($scope, $provided)` and `qmsCsrfField($scope)`. New forms must use
  it; do not re-implement the pattern page by page.
- Fixed in this pass (these pages had no verification at all): `notifications`,
  `super-admin-assignments`, `super-admin-admins`, `super-admin-companies`,
  `company-detail`, `auditor-create`, `nonconformity-detail`, `audit-detail`,
  `document-create`, plus `corrective-action-create` and `corrective-action-detail`
  which were closed earlier the same day.
- Already protected before this pass: `document-detail`, `document-edit`,
  `document-office`, `office-settings`, `risk-create`, `risk-detail`. They use
  module-specific helpers that still work; unify them onto `includes/csrf.php`
  when those files are next touched.
- Verification habit worth repeating: for each page confirm that
  `count(<form)` equals `count(qmsCsrfField(`. A form that is missing its hidden
  field breaks silently, and a page-level check would not catch it.
- **Resolved (2026-09-24)**:
  - `login.php` carries its own CSRF token in the login form and rotates the
    session id on successful login (`session_regenerate_id(true)`). Login CSRF is
    a real attack - a victim is forced into the attacker's account and then the
    data they enter is readable by the attacker - and the id rotation closes
    session fixation. Verified over real HTTP: a POST without a token and with a
    wrong token both return 403, a correct token with a wrong password reaches
    the auth logic, and a successful login rotates the session id while keeping
    the user signed in.
  - `wopi.php` deliberately has **no** CSRF token. It is a server-to-server
    endpoint that never authenticates with browser cookies: it validates a 64-hex
    access token, an IP allow-list and optionally WOPI proof keys, and sends
    `Referrer-Policy: no-referrer`. There is no ambient cookie authority to ride
    on, so CSRF does not apply - adding a token would break Collabora's requests.

### Tenant scoping

- Agreed mechanism: `company_admin_assignments` EXISTS check. Super admin sees
  everything; everyone else sees only assigned companies.
- Fixed in this pass (records were previously fetched by id alone, so changing the
  id exposed another company's data):
  - `audit-detail` - audit and checklist
  - `nonconformity-detail` - nonconformity
  - `company-detail` - company record (tax number and contact e-mail included)
  - `auditors` - the list showed every company's auditors
  - `auditor-create` - the company dropdown listed all companies **and** the posted
    `company_id` was not validated; both are now bound to the scope
  - `dashboard` - the four counters were system-wide totals
  - `corrective-action-detail` and `corrective-action-create` - fixed earlier
- Scoping that already existed and should be reused rather than rewritten:
  `qmsEditorDocument()` for documents, `qmsRiskFind()`/`qmsRiskScope()` for risks,
  `document-create`/`documents`, `actions` and `reports`.
- `document-approvals` is scoped to the signed-in approver rather than to the
  company. That is defensible; a company-scope check would be stricter.
- `super-admin-*` pages are role-gated, so unscoped queries there are correct.
- Behaviour was verified against real data, not just by reading the SQL: with the
  new audit query an assigned admin receives the row and an unassigned one
  receives 0 rows, while the old query returned it to everyone.

## Version control

- The repository was created on 2026-09-19 (`main` branch).
- `.gitignore` excludes `config/database.php` (credentials), runtime uploads under
  `storage/`, and `.rnd`. `config/database.example.php` is the template.
- `.gitattributes` pins `eol=lf`; without it, the Windows `core.autocrlf=true`
  setting would rewrite every file to CRLF on the first checkout.
- The service worker caches the app shell under a versioned cache name
  (`qms-cache-vNN`). Bump it whenever files in the app shell change, and confirm
  every shell path still returns 200 - one missing file aborts `cache.addAll` and
  breaks offline mode entirely.
- PWA paths are relative, so the app is no longer tied to a folder name.

## Roles and tenant access model

Four roles are now real end to end. Until this work only `super_admin` and
`system_admin` could sign in; `auditor` and `company_user` existed as names only.

| Role | Sees | Notes |
| --- | --- | --- |
| `super_admin` | Everything, unrestricted | Manages companies, accounts and assignments |
| `system_admin` | Only assigned companies (`company_admin_assignments`) | Operational modules |
| `company_user` | Only their own company (`users.company_id`) | Limited write access, no admin screens |
| `auditor` | Only the audits assigned to them (`audit_auditors`) | Lands on `my-audits.php`; operations and reports hidden |

### Schema added for this

- `users.company_id` - the company a company user belongs to
- `auditors.user_id` - links an auditor directory row to a login account
- `audit_auditors` - many-to-many, an audit can have **several** auditors. An
  earlier single column (`audits.auditor_id`) was dropped again because nothing
  used it and two ways to link auditors to audits would drift apart.
- Migrations `scripts/migrate-roles.php` and `scripts/migrate-audit-auditors.php`
  are idempotent. Existing rows were never rewritten (the only backfill was
  splitting `full_name` into first/last on the profile migration, and
  `full_name` was preserved).

### Central access layer - `includes/access.php`

**This is the single source of truth for visibility.** Do not write
`company_admin_assignments` EXISTS clauses inside pages any more.

- `qmsVisibleCompanyIds()` - `null` means unrestricted (super admin), `[]` means
  nothing, otherwise the allowed company ids
- `qmsCompanyScope($column, $ids)` - ready-made SQL fragment plus params
- `qmsAuditRecordScope()` - auditors are scoped by **audit**, not by company, so
  one auditor cannot open a different audit in the same company by changing the id
- `qmsVisibleAuditIds()`, `qmsCanAccessCompany()`, `qmsScopedRole()`,
  `qmsLandingPage()`

Pages and helpers migrated: `dashboard`, `reports`, `report-export-data`,
`risk-functions` (`qmsRiskScope`, `qmsRiskFind`), `document-editor`
(`qmsEditorDocument`, which also covers `document-edit` and `document-office`),
the documents chain, the actions/CAPA chain, `auditors`, `auditor-create`,
`company-detail`, `profile` and `nonconformity-detail`.

The remaining `company_admin_assignments` queries are deliberate:
- `document-detail.php` - the approver list. That is *approval eligibility*, not
  access: only system admins assigned to the company (and super admins) may
  approve. Kept as its own query, with a comment in the code.
- `super-admin-assignments.php` - the super admin screen that manages assignments.
- `includes/access.php` - the layer itself.

### Account creation

`super-admin-admins.php` creates all three non-super roles. Creating an auditor
account also creates the linked `auditors` row in the same transaction, so the
account is immediately assignable to audits. The password minimum was raised from
6 to 8 characters to match the profile screen.

### Audit assignment

Multiple auditors per audit. The assignment is edited on the audit detail page;
the auditor role cannot change its own assignment (the form renders only for
management roles, others see a read-only list). Auditors can also be chosen while
creating an audit from the company screen. Only auditors of that company can be
assigned - posted ids are intersected with the allowed list.

### Menu and landing

- The sidebar is role aware: auditors see only "Denetimlerim" and notifications;
  company users lose the auditor directory, the approval inbox and all admin
  sections.
- `qmsLandingPage()` sends auditors to `my-audits.php` after login, and
  `dashboard.php` redirects them there too.

### Verification habit that paid off

Every step was proved behaviourally, not just by reading the SQL: a company user
sees their own company's records and cannot open another company's; an auditor
can open their assigned audit and cannot open an unassigned one in the same
company; super admin stays unrestricted. Temporary fixtures are created inside a
transaction and rolled back, so no rows are left behind.

Two real defects were caught this way:
- `actions.php` - a `?? []` wrapper added while migrating would have turned
  "unrestricted" into "sees nothing" for super admin.
- `auditors.php` - the query had no `WHERE`, so appending a scope clause produced
  invalid SQL; fixed with `WHERE 1 = 1`.

## Office integration

- **Collabora/office is off-limits until the project is done** (confirmed
  2026-09-28). Do not touch `office-settings.php`, `config/office.php`,
  `deploy/office/`, `includes/office/*`, `wopi.php`, the web editor entry point
  on the document detail page, or any office connection settings. The user will
  configure the connection personally, at the end of the project.
- Web document editor was previously reported completed: new revisions preserve
  older versions and changed approved/published documents require approval again.
- Collabora integration code and `docs/OFFICE-INTEGRATION.md` exist. Live Word
  opening, editing and saving through a running Collabora server have **not** been
  confirmed.
- Start live verification with a disposable DOCX when the connection is ready.
  Excel/PDF come later; expose only capabilities actually supported by the server.
- Editor discoverability follow-up is **resolved**: the entry point is now a
  labelled panel ("Düzenleme" / "Dokümanı Düzenle") near the top of the document
  detail page.
- Architecture decision: free Collabora CODE for development/evaluation; assess
  supported production deployment and licensing before commercial rollout. Keep
  provider-specific code separated; another provider may require adapter changes,
  not merely configuration.
- The web editor uses locally hosted TinyMCE 8.9.1 GPL core; see
  `docs/TINYMCE-EDITOR.md`. No Tiny Cloud/CDN/API key is used.

### TailAdmin value reference (verified against their source, 2026-09-24)

Component styling mirrors TailAdmin's real class values rather than approximating
them. Source of truth for the numbers: their `sidebar.html`, `header.html` and the
`Button`/`InputField`/`Badge` components.

| Piece | TailAdmin | Here |
| --- | --- | --- |
| Sidebar | `w-72.5 px-5` | 290px wide, `padding: 0 20px` |
| Sidebar brand | `pt-8 pb-7` | `padding: 32px 4px 28px` |
| Menu item | `rounded-lg px-3 py-2 text-theme-sm font-medium` | 40px tall, `8px 12px`, 14px/500 |
| Menu icon | `text-gray-500`, active `text-brand-500` | `--gray-500` / `--brand-500` |
| Header | `lg:py-4 xl:px-6` | `padding: 16px 24px`, height from content |
| Header controls | `h-11 w-11 rounded-full border-gray-200` | 44px circle, `--border-subtle` |
| User area | borderless: `h-11 w-11` avatar + name + caret | no border, no background |
| User dropdown | `w-65 rounded-2xl p-3 mt-4.25` | 260px, 16px radius, 12px pad, 17px offset |
| Input | `h-11 rounded-lg px-4 py-2.5 shadow-theme-xs` | 44px, `10px 16px`, theme-xs |
| Button | `sm: px-4 py-3` / `md: px-5 py-3.5` | 44px (their `sm` size) |
| Table th | `px-5 py-3 text-theme-xs font-medium text-gray-500` | `12px 20px`, 12px/500 |
| Table td | `px-5 py-4 text-theme-sm` | `16px 20px` |

Two deliberate deviations: buttons use TailAdmin's `sm` size rather than `md`
because this app's UI standards call for compact buttons, and table cells use
`--text-body` (gray-700) instead of their gray-500 because our tables have no
per-column emphasis, so uniform gray-500 reads washed out.

## Open items

- **Zamanlayıcı (Task Scheduler) kurulumu — ERTELENDİ (sistem bitince yapılacak):**
  `scripts/notify-overdue.php` için `QuAmiOverdueNotify` görevi zaten kuruldu (günlük
  08:30). Sistem tamamlanınca şunlar da görev olarak eklenmeli (günlük):
  `scripts/send-daily-report.php` (rapor e-postası), `scripts/audit-program-reminders.php`
  ve `notify-overdue.php --all` varyantının zamanlayıcıda aç/kapa kararı.
  Kullanıcı bu kurulumu sistemi bitirdikten sonra yapmayı planlıyor.

- Excel/PDF export extension was completed on 2026-09-24: the exports carry
  detail sheets/sections for audits, nonconformities, corrective actions, the
  risk register, trainings, suppliers, complaints and performance targets, on top
  of the existing KPI and summary content. Excel has 20 sheets, the PDF has
  seventeen detail tables (management review, audit programs, equipment,
  satisfaction, personnel, external audits, quality costs, document
  distribution and approval flows added the last sheets/tables) and prints
  "no records this period" when a section is empty.
- Faz 3 is complete: all seven product modules (documents, risks, training,
  supplier, complaint, performance, management review) are built, tested and
  committed. See the Faz 3 complete note under the management review section.
- Complaint-to-nonconformity creation is **resolved** (2026-09-30): a complaint
  can now create its own nonconformity (`audit_id` NULL, `source = complaint`).
  See the "Sikayetten uygunsuzluk olusturma" section.
- RBAC propagation is **resolved** (2026-09-30): every page role gate now reads
  through the permission service. See the "RBAC propagation across the role
  gates" section. A new page should call `qmsCanSession` / `qmsRequirePermission`
  rather than comparing `$_SESSION['qms_role']` directly.
- Migration runner gotcha (hit on 2026-09-25): `explode(';')` splits on semicolons
  inside SQL comments too. `scripts/migrate-suppliers.php` strips `^--` lines first;
  do the same in any new runner.
- Phase 2 (CAPA) is complete: evidence files and corrective-action notifications
  are both implemented. `corrective_actions.responsible_user_id` supplies the
  recipient, so a corrective action can now be assigned to a real account.
  Notifications go to the responsible user (assignment, closure) and to the
  company's assigned system admins (verification requested); a user never gets a
  notification for their own action. Worth a UI pass to confirm it reads well.
- Dead CSS was removed on 2026-09-24: `admin-action-grid`, `admin-action-button`,
  `form-section`, `super-admin-section`, `editor-toolbar` and `topbar-action-link`.
  Reminder for next time: deleting the main block is not enough - the media queries
  referenced them too, and a first pass missed those leftovers.
- Unused `language.js` keys were removed on 2026-09-24 (30 keys, TR and EN).
  **Scan gotcha:** a naive "is this key used" scan gives false positives. Keys built
  dynamically (`data-i18n="actionStatus<?= ... ?>Label"`) and keys picked with
  single quotes in PHP (`'loginLinkLabel'`) both look unused. Check both patterns
  before deleting.
- `qmsVisibleCompanyIds()` returning `null` means "no restriction". Wrapping the
  result in `?? []` silently turns a super admin into "sees nothing" - this trap
  produced two real bugs. See the warning in `includes/access.php`.
- Auditor RBAC effect is **resolved** (2026-10): the auditor role no longer runs on
  hard-coded `$isAuditorNav` / `$isManagementNav` sidebar guards. `includes/app-sidebar.php`
  now renders every menu item purely by `qmsSidebarVisible()` (i.e. `qmsCanSession`),
  so enabling `dashboard.view`, `reports.view`, `operations.view`, `audit_trail.view`,
  `document_reviews.manage`, `document_approvals.manage`, `training_templates.manage`
  etc. for the auditor actually surfaces those pages in the menu. `dashboard.php`
  only redirects an auditor to `my-audits.php` when `dashboard.view` is not granted.
  `tests/permissions.php` was hardened to snapshot live `permission_overrides`, run
  default-behaviour assertions against a clean set, then restore the real rows; it is
  26/26 and never touches the user's saved permissions. Cache `v148`.
- Auditor execution workspace is **added** (2026-10): `audit-detail.php` now carries a
  real denetim yasam dongusu - an assigned auditor / management / company admin can
  **Baslat** (status -> `in_progress`, sets `started_at`), **Tamamla** (status -> `done`,
  sets `completed_at`, blocked while any checklist item is still `pending`), and
  **Yeniden Ac** (back to `in_progress`, clears `completed_at`). An execution progress
  bar + status pill + dates sit at the top of the audit detail page, and the auditor
  role gate reads `my_audits.view` through RBAC. Migration
  `20261010-audit-execution.sql` adds `audits.started_at` / `audits.completed_at`;
  `tests/audit-execution.php` (7/7) covers the lifecycle; `my-audits.php` cards now
  show a friendly status label. Cache `v149`.
- COQ Trend yil sorgusu hatasi **cozuldu** (2026-10): `quality-cost-trend.php`'nin
  yil (YEAR) sorgusu `FROM quality_costs c` uzerinde calisirken `companies.id`
  kapsamini (`qmsCompanyScope('companies.id', ...)`) yeniden kullaniyordu; bu tablo o
  sorguda olmadigi icin "Unknown column 'companies.id'" (1054) hatasi veriyordu.
  Kapsam artik `c.company_id` uzerinden (`$yearScope`) kuruluyor. Ayni sinif bir hata
  daha once `dashboard.php`'de duzeltilmisti; COQ Trend degisikligi bunun dogrulugunu
  DB'de yeniden ureterek test edildi. Cache `v150`.
- "Programa Denetim Ekle" bos durum UX'i **iyilestirildi** (2026-10): `audit-program-detail.php`
  artik `$available` bosken yalnizca devre disi bir dropdown secenegi gostermek yerine
  aciklayici bir bos-durum mesaji ve "Şirket Denetimleri" butonu (company-detail) sunuyor.
  Sirkette hic denetim yoksa ("once denetim olusturun") ile tum denetimler zaten
  baglanmissa ("tum denetimler programa bagli") ayri mesajlar gosterir. Cache `v151`.
- Audit denetci paneli + otomasyon (2026-10) - dort is birlikte yapildi:
  1. **Otomatik rapor taslagi**: `audit-detail.php`'de denetim tamamlaninca henuz bir rapor
     yoksa `qmsAuditReportGenerateDraft()` (yeni) taslak rapor uretir; kullanici rapora
     baglantidan gider. Mevcut rapora dokunulmaz. `audit-report-functions.php`'ye eklendi.
  2. **Denetci panosu guclendirildi** (`my-audits.php`): durum filtre sekmeleri
     (Tum/Devam Eden/Tamamlanan/Geciken), kart basina yurume rozeti (Basla/Devam/Rapor)
     ve Excel/PDF disa aktarma (`my-audits-export.php`).
  3. **Dashboard & bildirim**: Dashboard Donem Ozeti'ne devam eden + vadeyi gecen denetim
     gostergesi eklendi (`dashboard-functions.php`); `notify-overdue.php`'ye
     `assigned_audit_due` bolumu eklendi (vadeyi gecen ananmis denetim icin denetciye +
     `--all`'da sirket adminlerine bildirim/eposta). `notifications.php`'ye `audit` grubu
     ve `assigned_audit_due` turu eklendi.
  4. **Uygunsuzluk -> CAPA**: `audit-detail.php` uygunsuzluk listesinde her kayit icin
     tek tik "Faaliyet Olustur" butonu (corrective-action-create) eklendi.
  Cache `v152`.
- Sol menude ayni anda aktif gozukme hatasi **giderildi** (2026-10): "İç Memnuniyet Anketi"
  ile "Anketi Doldur" ve "Kalite Maliyeti (COQ)" ile "COQ Trendi" ciftleri ayni `$activeNav`
  anahtarini paylasiyordu (`internal_surveys` / `quality_costs`), bu yuzden ikisi birden
  vurgulamıyordu. Iki kisiye ozgu anahtar verildi: `internal_survey_fill` ve
  `quality_cost_trend` (app-sidebar ogeleri + sayfa `$activeNav` eslestirildi).
  Benzer bir cakisma `document-compare.php` (documents anahtari) icin de aday; kullanici
  istekte bulunursa ayni sekilde ayrilabilir. Cache `v153`.
- `document-compare.php` aktiflik cakismasi **giderildi** (2026-10): "Versiyon Karşılaştırma"
  menü ogesi ve sayfa `document_compare` anahtarini kullanir; artik Dokuman Yonetimi ile
  birlikte aktif gozukmez. Cache `v154`.
- Dashboard **Denetim & Rapor Durumu** paneli eklendi (2026-10): devam eden denetim,
  vadeyi gecen denetim, taslak / kesinlesmis rapor sayisi ve denetim kaynakli acik NC.
  Kapsamli sorgular `$dashOpenAudits`, `$dashAuditOverdue`, `$dashAuditReportDraft`,
  `$dashAuditReportFinal`, `$dashAuditOpenNc` (rapor sayilari `a.company_id` uzerinden
  kapsamlidir). Dashboard icin dogrulandi. Cache `v154`.
- Bu turde uc is birlikte (2026-10):
  1. **notify-overdue cron**: `scripts/cron-notify-overdue.bat` + Windows zamanlanmis
     gorev `QuAmiNotifyOverdue` (gunluk 08:00) olusturuldu; calistirilip dogrulandi
     (overdue bildirimleri + log `storage/logs/notify-overdue.log`).
  2. **Denetim Bulgulari yuzeyi** (`audit-findings.php`): uygun bulunmayan kontrol
     maddelerini (bulgu) sirkelet, audit, bagli NC durumu ve CAPA sayisi ile listeler;
     acik/kapali filtre, sirket ve arama; sol menuye `audit_findings` anahtari ile eklendi.
  3. **COQ Dashboard**: Donem Ozeti'ne "Kalite Maliyeti" grubu (yillik COQ, hata maliyeti,
     hata orani) kapsamli olarak eklendi (`$dashCoqTotal/Failure/FailurePct`).
  Cache `v155`.
- **Kok Neden Analizi modulu** eklendi (2026-10): `nc_root_cause` tablosu + `root-cause.php`
  (5 Neden + kok neden + duzeltici/onleyici onlem). `includes/root-cause-functions.php`
  (qmsRootCauseFind/Save upsert; kok neden doluysa otomatik `done`). Sol menude
  `root_cause`; `nonconformity-detail.php`'de "Kok Neden Analizi" butonu. Kapsam sirke
  uzerinden. DB'de dogrulandi. Cache `v156`.
- **Denetim Yillik Takvimi** eklendi (2026-10): `audit-calendar.php` - 12 ay grid,
  ay bazinda planlanan/devam eden/tamamlanan denetimler, yil + sirket filtresi, ozet
  kartlari (toplam/plan/int_progress/done/vadeyi gecen). Sol menude `audit_calendar`.
  Cache `v156` sonrasi ek yeni `v157`.
- **Kalibrasyon sertifika ekle/guncelle** eklendi (2026-10): `instrument-calibrations.php`
  arti `qmsInstCalibAttachCert()` ile mevcut bir kayda sertifika dosyasi eklenebilir /
  degistirilebilir (eski dosya silinir, 10MB, storage/calibrations). Satir ici "Sertifika
  Yükle" + `attached=1` mesaji. `certificate_file` kolonu zaten vardi; migration yok.
  Cache `v157`.
- `audit-detail.php` kutu degme sorunu **giderildi** (2026-10, stil only):
  `.audit-execution-bar` (Denetim Calisma Alani alti) icin `margin-top:22px` ve
  `.super-admin-console + .console-card` (Denetciler karti) icin `margin-top:18px`
  eklendi; kutular birbirine degmiyor. Cache `v158`.
- **Denetim Raporlari Merkezi** (`audit-reports.php`) eklendi (2026-10): tum raporlari
  (taslak/kesinlesmis) sirke/denetim/durum filtreleriyle listeler; satir basi Dusenle +
  PDF; ozet kartlari; sol menude `audit_reports`.
- **Denetim Bulgulari + Kok Neden Analizi export**: `audit-findings-export.php` ve
  `root-cause-export.php` (Excel/PDF) eklendi; iki sayfaya da export butonlari.
  Cache `v159`.
- **Dagitim & Imza Takibi** eklendi (2026-10): `document-distribution-tracking.php` -
  dagitilan kontrollu kopyalar icin teslim/onay durumu; `qmsDocumentCopyConfirm()` ile
  kopya `received_confirmed`/`received_on`/`signed_by` isaretlenir. Migration
  `20261012-doc-copy-confirmation.sql` (3 kolon). Sol menude `document_tracking`.
  DB'de dogrulandi. Cache `v160`.
- **Denetim Izi -> ana rapor export**: zaten bagliydi (Excel "Denetim Izi" sheet +
  PDF bolumu + `audit_trail_count` metriği `includes/report-export-data.php` + xlsx/pdf).
  Degisiklik gerekmedi; dogrulandi.
- **Dashboard Donem Ozeti genisletildi** (2026-10): acik denetim bulgulari, kok neden
  bekleyen NC ve dokuman teslim onayi bekleyen gostergeleri. Cache `v161`.
- **Bildirim Merkezi akisi**: zaten vardi (`mark_read`, `mark_all_read`, unread/read
  filtre); degisiklik gerekmedi. Dogrulandi.
- **Denetim Izi -> ana rapor export**: zaten bagliydi; degisiklik gerekmedi.
- **Dogrulama & Kapanis Merkezi** (`verification-center.php`) eklendi (2026-10):
  dogrulama bekleyen CAPA + acik uygunsuzluk kapanis hatti (kok neden, acik CAPA,
  kapanis paketi). Sol menude `verification_center`; i18n TR/EN. Cache `v162`.
- **Ana rapor export zenginlesti** (2026-10): Excel'e Denetim Bulgulari, Kok Neden,
  Dagitim Onayi ve Dogrulama Bekleyen sheet'ler + PDF'e ayni bolumler eklendi
  (`report-export-data.php` -> findings/root_cause/doc_confirm/verification listeleri;
  xlsx/pdf render). Cache `v163`.
- **Olcu Aleti Detay sayfasi** (`instrument-detail.php`) eklendi (2026-10): alet bilgileri,
  son/sonraki kalibrasyon tarihleri (gecikme uyarisi), kalibrasyon gecmisi + sertifika
  indirme. `instruments.php` listesinde alet adi detaya baglandi. Cache `v164`.

## GIT DURUMU (COZULDU)

Git for Windows (2.56.0) kullanici tarafindan kuruldu; adres:
`C:\Program Files\Git\cmd\git.exe`. Tum birikmis degisiklikler tek kapsamli commit
`b49e1f2` (19 dosya, +1487) ile kaydedildi. Bundan sonra commit icin git PATH'te
olmazsa oturum basina `$env:Path = 'C:\Program Files\Git\cmd;' + $env:Path`
(veya tam yol) kullan. Her `shell_command` taze bir PowerShell ile baslayip
kurulum oncesi onbelleklenmis PATH'i alabilir; bu yuzden PATH on-ekini tekrarlamak
en guvenlisi. `service-worker.js` cache su an `v159`.

## Working preferences

- Give candid, constructive project advice and distinguish verified results from
  plans or delegated progress.
- Keep forms on separate management screens, not piled onto the dashboard. Use
  the established compact action buttons outside the global header.
- Preserve real records; use temporary fixtures and clean only test data created
  by the task.

## Owning task

Continue on the QuAmi codebase rooted at `C:\xampp\htdocs\qmsx`.

## Log (2026-10)

- **Kalibrasyon Takvimi** (`calibration-calendar.php`) eklendi (commit `3374120`, cache v167):
  olcu aletlerinin yillik gorunumu (sonraki kalibrasyon tarihleri). Sol menude
  `calibration_calendar`; i18n TR/EN.
- **NC durum degisimi bildirimi** (cache v168): `nonconformity-detail.php`
  `update_nonconformity` handler'inda durum degisiminde sirket adminlerine bildirim
  + (tercihe bagli) e-posta. Yeni bildirim tipleri `nc_status_changed` ve `nc_closed`
  (`includes/notifications.php` -> `nc` grubu); grup etiketi + i18n anahtari
  `notificationGroupNcLabel` eklendi. Kapanis ayri tip olarak `nc_closed`; diger
  durum gecisleri `nc_status_changed`. Islem yapan kullanici dislanir.
- **Kalibrasyon Takvimi dark mode** (cache v169): `assets/css/style.css` icinde
  `finding-filter-form select` TailAdmin chevron stili + `.calendar-*` kutulari
  icin `body.dark-mode` overrides eklendi (dropdown ve kutular dark modda okunur).
- **Olay -> NC -> CAPA baglantisi derinlestirildi** (cache v169):
  `qmsIncidentCreateNonconformity()` yeni bir olay kaynakli ucunsuzluk acarken
  sirket adminlerine `nc_status_changed` bildirimi + (tercihe bagli) eposta
  gonderir; CAPA kapanis bildirimi zaten durum degisiminde gider.
- **Olaydan tek akista CAPA acma** (cache v170): `qmsIncidentCreateCorrectiveAction()`
  gerekirse uygunsuzluk olusturur, ona bagli duzeltici faaliyet yazar, sirket
  adminlerine `capa_opened_from_incident` bildirimi + denetim izi (`incident`/
  `capa_created`) gonderir. `incident-detail.php` CAPA bolumune "Duzeltici Faaliyet
  Ac" butonu (her durumda) eklendi; i18n `incidentCreateCapaButton`.
- **Olay -> Dashboard Donem Ozeti** (cache v170): `dashboard.php` Donem Ozeti
  izgarasinin Sirket Panosu grubuna "Acik Olay" ve "Kritik Olay" KPI'lari eklendi
  (incidents.php linkli; kritik kirmizi vurgulu). Rapor export'u zaten Olaylar
  sheet/metriklerini iceriyordu (dogrulandi).
- **Son 12 Ay Trendleri dark mode** (cache v171): `style.css` icinde
  `body.dark-mode` overrides eklendi — `.trend-series-head` metni koyu yerine
  acik, `.trend-bar-track` zemini koyu, deger/etiket renkleri dark token'a.
- **Denetim Bulgulari ara kutusu dark mode** (cache v172): `style.css` icinde
  `.finding-filter-form input[type=text]` TailAdmin tarzi dark-adaptif stil eklendi
  (control-bg/border/text token'lari + odak halkasi); select'lerle tutarli.
- **Dokuman Dagitim Kontrolu dropdown dark mode** (cache v173): `copy_status`
  selectine `doc-roll-select` sinifi eklendi (presentational) + `style.css`'e
  TailAdmin tarzi dark-adaptif inline durum select stili; fonksiyona dokunulmadi.
- **Dagitim & Imza Takibi onaylayan kutusu dark mode** (cache v174):
  `.inline-cert-upload input[type=text]` TailAdmin tarzi dark-adaptif stil eklendi
  (control-bg/border/text + odak halkasi); fonksiyona dokunulmadi.

## Log A/B/C (cache v175)

- **A - Egitim -> Yetkinlik otomatik senkron**: `staff_members.user_id` eklendi
  (migration `migrate-staff-user-link.php`). `qmsTrainingSyncCompetency()` bir
  katilimci "tamamlandi" olunca egitimin `target_competency`'sini ilgili
  personelin `staff_competencies` kaydina isler (+1yil vade, tamamlanma tarihi).
  Kullanici->personel eslesmesi: user_id baglantisi, email, ad+soyad. Esl estirme
  `competency-matrix.php`'e "Esl" dropdown ile eklendi (`comp-user-link`).
  `training-detail.php` katilimci guncelleme akisinda cagrilir. Vade gecikince
  mevcut overdue_competency bildirimi devreye girer.
- **B - Kalibrasyon & Metroloji**: Donem Ozeti izgarasina "Kalibrasyon & Metroloji"
  grubu eklendi (Toplam Alet / Yakinlasan 60g / Gecikmis, linkli). Sertifika-
  geçmiş (`calibration_list`) rapor export'unun XLSX/PDF'inde zaten vardi;
  `instrument_calibration_overdue` e-posta bildirimi pref-aware idi (dogrulandi).
- **C - Musteri Memnuniyeti Anketi**: modul zaten mevcuttu ve rapor export'una
  bagliydi (satisfaction_count/avg + list). Donem Ozeti "Musteri & Yetkinlik"
  grubuna Memnuniyet Puanı (x/5) ve Yanıt adedi linkli metrikleri eklendi.

## Log A/B/C (cache v176)

- **A - OFI -> CAPA/NC baglantisi**: `improvements.linked_nc_id` eklendi
  (migration `migrate-improvement-nc-link.php`). Edit formuna "Bagli Uygunsuzluk"
  dropdown (sirketin acik NC'leri), listede NC linki, durum degisiminde denetim
  izi (entity `improvement`) eklendi. `qmsImprovementOpenNcOptions`/`LinkedNc`.
- **B - Yeni yüzey: Kok Neden Analizi (RCA)**: `rca_analyses` tablosu
  (migration `migrate-rca.php`), `includes/rca-functions.php`, `rca.php` sayfasi.
  5-Neden (JSON), sorun/kok neden/onerilen CAPA, kaynak (NC/olay/iyilestirme)
  baglantisi; kaynak uygunsuzluk ise "CAPA Ac" butonu `qmsRcaOpenCapa()` ile
  faaliyet + bildirim + denetim izi. Sidebar `rca` + i18n TR/EN.
- **C - Denetim Izi raporlama sablonu**: zaten mevcuttu (`audit-trail-report.php`
  filtre + CSV + PDF + ozet; sidebar `audit_trail_report`, RBAC
  `audit_trail.view`). Ek kod gerekmedi; dogrulandi.
- **Denetim Izi Raporu header arama** (cache v177): header `page-title-block`
  yerine `topbar-search` (ara kutusu) kondu; `audit-log-functions` `q` ozet-metni
  filtresi ekledi (LIKE). "Kayit turu" (entity_type) secim kutusu kaldirildi ve
  her zaman "tumu" yapildi (filtre bos; ozet dagilim gorseli korundu). CSS
  `.topbar-search` dark-adaptif.
- **Global header arama (tüm sayfalar)** (cache v178): `sidebar.js` sonuna IIFE
  eklendi - her sayfada `.topbar .page-title-block` yerine `search.php`'ye giden
  `topbar-search` (Ara...) kutusu koyar. "Kayit turu" (record/entity/type)
  filtreleri her zaman "tumu" yapildi ve kutucugu kaldirildi: `search.php`
  (type), `actions.php` (record_type), `audit-trail.php` (entity_type),
  `audit-trail-report.php` (daha once).
- **Arama sayfasinda Kayit Turu geri geldi** (cache v179): `search.php` 'de
  `type` filtresi ve secim kutusu yeniden etkinles tirildi (sadece arama
  sayfasinda gorunur). Header'larda kayit turu gorunmez (kuresel arama box'i
  `q` ile `search.php` 'ye gider). `actions.php` / `audit-trail.php` de kayit
  turu hâlâ gizli + "tumu".
- **Sidebar Arama dugmesi kaldirildi** (cache v180): `app-sidebar.php`'den
  `search.php` menü ogesi cikti (yalnizca görünüm). `search.php` sayfasi ve
  global header aramasi etkin kaldi (fonksiyona dokunulmadi).
- **Sidebar TailAdmin grup/altmenu (tam liste)** (cache v181): `app-sidebar.php`
  yeniden yazildi - 13 grup ve alt oge (accordion). "Genel" grubunda Panel / Bana
  Atanmislar / Bildirim Merkezi / Raporlama / Vadesi Gelen / Yillik Kalite Plani /
  Yönetimin Gözden Geçirmesi; "Sistem Yönetimi" grubu admin sayfalari (Ofis,
  Mail, Sirketler, Kullanicilar, Admin Atamalari, Izinler). `.sidebar-group`/
  `.sidebar-submenu` CSS + `sidebar.js` toggle + grup i18n anahtarlari. Her oge
  RBAC izniyle guard'li. (Grup/altmenu daha once acilmis, geri cekilmis, genisletilmis
  liste ile yeniden uygulandi.)
- **Sidebar TailAdmin görünümü duzeltildi** (cache v182): grup basliklari artik
  kücük/buyuk-harfli etiket degil, normal menü ögesi gibi (ikon + etiket +
  cevron; 14px, 500). Her gruba ikon eklendi. Altmenu ögeleri girintili + sol
  kilavuz cizgisi ile (`sidebar-submenu`).
- **Sidebar submenu ikon boyutu duzeltildi** (cache v183): submenu ogeleri
  `appIcon($icon,'')` yerine `appIcon($icon,'sidebar-link-icon')` ile render
  ediliyor (20px). Grup ikonuna `.sidebar-group-icon svg { width/height:20px }`.

## AI Asistani (cache v184) - kuruldu
- **Kurulum asamasinda**: `config/ai.php` (varsayilanlar + okuyucu), `.gitignore`'a
  `storage/ai/*` (anahtar asla repo'ya girmez), `storage/ai/.htaccess`.
- **Ayar sayfasi**: `ai-settings.php` (super/system admin) - OpenAI API anahtarini,
  model (gpt-4o-mini), whisper-1, base_url, timeout'i `storage/ai/settings.json`'a
  kaydeder; "Baglanti Test Et" butonu.
- **Saglayici**: `includes/ai-functions.php` - `qmsAiChat()` (OpenAI chat),
  `qmsAiWhisperTranscribe()` (ses tanima), `qmsAiFallbackDocument()` (offline
  sablon motoru - AI kapaliyken calisir).
- **Studio**: `ai-document-studio.php` - dokumani tarif et (yazi), tur sec,
  "Taslagi Uret"; **voice** tarayicida Web Speech API (`webkitSpeechRecognition`,
  tr-TR) ile metne cevirir. Cikti kopyalanabilir.
- **Sidebar**: Genel -> "AI Dokuman Studusu", Sistem Yonetimi -> "Yapay Zeka Ayarlari".
- i18n TR/EN; cache v184. Test: studio AI kapaliyken sablon motoru doner.
- **Saglayici secici eklendi** (cache v185): `ai-settings.php`'e OpenAI / Groq
  secimi + JS on-doldurma. `config/ai.php` `qmsAiProviderPresets()`:
  OpenAI(gpt-4o-mini/whisper-1) ve Groq(llama-3.3-70b-versatile/
  whisper-large-v3). Secici degisirse base_url/model/whisper otomatik dolar;
  sunucu tarafinda da preset uygulanir. i18n `aiProviderLabel`.
- **AI Hata Detayi** (cache v186): `includes/ai-functions.php` son hatayi
  `storage/ai/last-error.json`'a yazar (anahtarsiz, 4000 cr). `ai-settings.php`
  "Hata Detayi" panosunda HTTP + ham yanit gosterir. i18n `aiErrorDetail*`.
- **Groq model duzeltmesi** (cache v187): `llama-3.3-70b-versatile` free tier'da
  erisilemedi (model_not_found); Groq preset'i `llama-3.1-8b-instant` + 
  `whisper-large-v3` olarak degistirildi.
- **Groq model listeleme** (cache v188): `llama-3.1` da erisilemedi; Groq eski
  Llama modellerini kaldirmis. `qmsAiListModels()` (GET /models) + ayar sayfasinda
  "Modelleri Listele" butonu ve secilebilir model listesi (cevap + "Bu Modeli Kullan"
  -> kaydet). Groq preset'i `meta-llama/llama-4-scout-17b-16e-instruct` + 
  `whisper-large-v3`.
- **Model listeleme uyarisi duzeltildi** (cache v189): `qmsAiListModels` 'deki
  gereksiz `array_map('strval', ...)` satiri kaldirildi (Array to string warning).
- **AI model listesi gorunur buton olarak** (cache v190): `ai-settings.php` 'de
  model secici `<select>` yerine gorunur `ai-model-item` buton listesi (tiklayinca
  modeli yazip kaydeder). i18n `aiUseModel` + CSS.
- **Hata Detayi bosluk duzeltmesi** (cache v191): `ai-settings.php` 'de
  Hata Detayi section'ina `margin-top:20px` (ust kutuya degmesin diye).
- **Groq calisan model bulundu** (cache v192): anahtar erisimi sorgulandi;
  `llama-4-scout` yok. Erisilebilir sohbet modeli `openai/gpt-oss-20b`
  (whisper-large-v3). Groq preset + settings.json bu modele cevrildi; gercek
  test OK (model calisiyor).
- **AI Dokuman Turleri QMS-uyumlu** (cache v193): `ai-document-studio.php` tur
  listesi ISO 9001 dokumantasyon hiyerarsisine gore genisletildi: Politika,
  Prosedür, İş Talimatı, Yönerge, Form/Kayıt, Plan, Kontrol Listesi, Şartname,
  Rapor. `qmsAiDocTypeLabel` + fallback kapsam/uygulama mantigi da yeni turlere
  gore iyilestirildi.
- **Dokuman Web Editoru ile olusturma** (cache v194): `document-create.php`
  "Web Dokuman Editoru ile Olustur" butonu. Secilince dosya yuklemeden dokuman
  kaydedilir ve `document-edit.php?id=NEW` acilir (iciniz web editorde yazar).
  i18n `saveAndOpenWebEditorButton`. Web modunda dosya dogrulamasi atlanir.
- **AI Dokuman Studusu: A/B/C** (cache v195): `ai-document-studio.php`
  kullanici onayli A, B, C isleri tek yuzeyde kodlandi:
  - **A - Tek tikla dokumana aktar**: `form_type=transfer`. `qmsAiDraftToHtml`
    ile duz metin -> guvenli HTML; `documents` (draft, rev 01) + `document_versions`
    (`mime_type='text/html'`, dosya `storage/documents/<hash>.html`) olusturulur;
    sonra `document-detail.php?id=NEW`'e yonlendirir. (Not: devir sirasinda
    bulunan PostgreSQL tarzi `'document-'||?` concat MySQL icin bozuktu; `$origName`
    PHP parametresiyle duzeltildi.)
  - **C - Sablon olarak kaydet**: `form_type=save_template`. Mevcut
    `qmsDocumentTemplateAdd()` ile `document_templates`'a kaydeder (AI turu ->
    sablon turu haritasi `templateTypeMap`), sonra `document-templates.php?created=1`.
  - **B - Sesli komut ayristirici (JS)**: `parseVoice()` Web Speech sonucunu isler
    - tur anahtar kelimesi (politika/prosedur/talimat/yonerge/form/plan/kontrol
    listesi/sartname/rapor) -> tip select'ine; `baslik: ...` kalibi -> baslik
    input'ua; tam metin -> aciklama. Sunucu LLM cagrisi gerektirmez (ucretsiz).
  - Yeni i18n TR/EN: `aiTransferTitle`, `aiTransferText`, `aiTransferCodeLabel`,
    `aiTransferButton`, `aiSaveTemplateButton`, `aiTemplateTypeLabel`.
  - Lint temiz; cache v195.
- **Egitim Sablonu / Yetkinlik Matrisi derinlesmesi: 1-2-3** (cache v196):
  - **1 - Sablondan tek tikla egitim kaydi**: `training-templates.php` sablon
    listesinde her sablona "Egitim Olustur" butonu (`training-create.php?template_id=X`).
    `training-create.php` GET `template_id` ile sunucu tarafinda sablon alanlarini
    (sirket, baslik, kategori, sure, hedef yetkinlik) onceden doldurur ve sablon
    secimini isaretler. i18n `trainingCreateFromTemplateButton`.
  - **2 - Yetkinlik matrisi otomatik kaydı (puan/seviye)**: `qmsTrainingSyncCompetency`
    artik opsiyonel `?int $score` alir; egitim tamamlaninca katilimci puani (0-100)
    yetkinlik seviyesine (1-5) eslenir (score/20), sertifika/puan notu `notes`'a
    yazilir, vade +1 yil. `training-detail.php` tamamlanma aninda skoru gecirir.
  - **3 - Dashboard yetkinlik vadesi widget'ı guclendirildi**: `dashboard.php`
    sorgusu artık son 30 gun icindeki yaklasan yetkinlikleri de (gecenlerin yaninda)
    getirir; her satir icin "Vadesi gecti"/"Yaklasan vade" durum rozeti ve baslik
    "Yetkinlik Vadesi (Yaklasan/Gecen)" olarak guncellendi. i18n guncellendi.
  - Lint temiz; cache v196.
- **AI sessle komut guclendirme: otomatik uretim** (cache v197):
  `ai-document-studio.php` sesli komut ayristirici artik tetikleyici kelime
  algilayinca ("uret", "olustur", "basla", "tamam"...) formu otomatik
  gonderir (submitGenerate). Kelime siniri kontrolu Turkce harflerle yapilir;
  "uretim" gibi sozcuklerin parcasiysa tetiklenmez. Surekli dinlemede son
  sonuc (isFinal) tespit edilince `parseVoice` ile tur/baslik islenir, sonra
  generate formu otomatik submit edilir. Lint temiz; cache v197.
- **AI Studyo: transfer sonrasi Web Editor'e yonlendirme** (cache v198):
  `ai-document-studio.php` transfer akisi (dokumana aktar) artik kullaniciyi
  `document-detail.php` yerine dogrudan `document-edit.php?id=NEW` (Web Dokuman
  Editoru) acar; draft statusuyle HTML icerik textarea + TinyMCE ile duzenlenebilir
  sekilde yuklenir, kayit yeni revizyon olusturur. Lint temiz; cache v198.
- **Musteri Teslimat / Performans Karti tamamlama** (cache v199): Modul buyuk
  olcude mevcuttu (KPI kartlari `customer-delivery-performance.php`, red esigi
  bildirimi `delivery_rejection` notify-overdue.php'de, rapor export'ununda
  delivery bolumu). Eksik olan kapatildi:
  - **Dashboard Donem Ozeti** "Musteri & Yetkinlik" grubuna "Ort. Zamaninda
    Teslimat" (% on-time) metriği eklendi (`dashOnTimeAvg`, dashboard.php
    delivery toplam sorgusu `orders_total`/`on_time_orders` ile genisletildi).
  - `customer-delivery-performance.php` basligina "Teslimat Kaydi" + "Rapor"
    butonlari eklendi (kayit girisinde ve rapora hizli erisim). i18n
    `deliveryAddRecord`, `deliveryReportButton`. Lint temiz; cache v199.
- **Denetim Izi Raporlama Sablonu: XLSX export** (cache v200):
  `audit-trail-report.php` zaten CSV + PDF export ve filtreleri iceriyordu; mevcut
  `includes/xlsx-writer.php` kutuphanesiyle **Excel (XLSX) export** eklendi
  (Tarih/Kisi/Kayit Turu/Islem/Ozet/Sirket/IP sutunlari, filtreler korunur).
  Aksiyon alanina "Excel İndir" butonu eklendi. i18n `downloadXlsxButton`.
  Lint temiz; cache v200.
- **Memnuniyet + Yonetimin Gozden Gecirmesi -> Dashboard entegrasyonu** (cache v201):
  - **Item 1 (Memnuniyet):** `dashboard.php`'ye "Musteri Memnuniyeti" widget'i eklendi
    (son 5 anket baslik + sirket + yanit + ort. puan /5), `qmsSatisfactionSurveyList`
    kullanir (yeni `includes/satisfaction-functions.php` require). i18n
    `dashboardSatisfactionTitle`, `dashboardSatisfactionText`, `satisfactionEmpty`.
  - **Item 2 (Gozden Gecirme):** Donem Ozeti'ne "Yonetimin Gozden Gecirmesi" grubu
    eklendi: Gozden Gecirme, Tamamlanan, Son GGR (tarih), Siradaki GGR (tarih),
    Geciken Aksiyon (management_review_items due_date < CURDATE). i18n
    `dashboardReviewTitle`. (GGR raporu zaten reports/export'te mevcuttu.)
  - **Item 3 (Dashboard rapor entegrasyonu):** Yukaridaki iki metrik grubu Donem
    Ozeti + widget olarak eklendi; review/memnuniyet rapor export'lariyla tutarli.
  - Lint temiz; cache v201.
