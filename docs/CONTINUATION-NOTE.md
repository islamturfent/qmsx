# QMS continuation note

Recorded: 2026-09-18 (Europe/Istanbul).
Last updated: 2026-09-19. The state below was re-verified on that date; it is not
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

  88 checks total. All suites use temporary tables and leave real records
  untouched (verified: `risks`, `risk_history`, `office_audit` remain empty).

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
- **Still open**: `login.php` (login CSRF / session fixation - needs its own
  design, not a blind copy of the pattern) and `wopi.php` (WOPI authenticates with
  access tokens, so the CSRF model differs). Both are decisions, not mechanical
  edits.

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

- User explicitly deferred Collabora setup and will configure the connection
  personally. Do not resume installation or modify office connection settings.
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

## Open items

- Excel/PDF export extension is the next module candidate; agree the scope with
  the user before implementing.
- `login.php` and `wopi.php` still need a CSRF decision (see Security hardening).
- Phase 2 (CAPA) is half done: evidence files are implemented, notifications for
  corrective-action events are still missing.
- Dead CSS classes with no markup: `admin-action-grid`, `admin-action-button`,
  `form-section`, `editor-toolbar`, `topbar-action-link`. Safe to remove after a
  re-check.
- `language.js` may still hold unused keys; the scan attempted earlier was
  unreliable, so re-verify before deleting anything.

## Working preferences

- Give candid, constructive project advice and distinguish verified results from
  plans or delegated progress.
- Keep forms on separate management screens, not piled onto the dashboard. Use
  the established compact action buttons outside the global header.
- Preserve real records; use temporary fixtures and clean only test data created
  by the task.

## Owning task

Continue on the QMS codebase rooted at `C:\xampp\htdocs\qmsx`.
