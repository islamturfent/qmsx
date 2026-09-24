# QMS continuation note

Recorded: 2026-09-18 (Europe/Istanbul).
Last updated: 2026-09-25. The state below was re-verified on that date; it is not
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

  211 checks total. All suites use temporary tables and leave real records
  untouched (verified: `risks`, `risk_history`, `office_audit`, `trainings`,
  `training_participants`, `corrective_actions`, `suppliers`, `notifications`
  remain empty).

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

- Excel/PDF export extension was completed on 2026-09-24: the exports carry
  detail sheets/sections for audits, nonconformities, corrective actions, the
  risk register, trainings and suppliers, on top of the existing KPI and summary
  content. Excel has 9 sheets, the PDF adds six detail tables and prints "no
  records this period" when a section is empty.
- Faz 3 remaining modules: sikayet (complaint), performans (performance),
  yonetimin gozden gecirmesi (management review). Pick the next one with the user
  before starting; the training and supplier modules are good templates.
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

## Working preferences

- Give candid, constructive project advice and distinguish verified results from
  plans or delegated progress.
- Keep forms on separate management screens, not piled onto the dashboard. Use
  the established compact action buttons outside the global header.
- Preserve real records; use temporary fixtures and clean only test data created
  by the task.

## Owning task

Continue on the QMS codebase rooted at `C:\xampp\htdocs\qmsx`.
