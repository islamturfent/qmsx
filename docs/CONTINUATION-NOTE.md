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
