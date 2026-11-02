# Web UI refinement — Batch F1

Date: November 2, 2026 (Asia/Manila)  
Repository: `C:\dev\AssocMap-STAGING`  
Branch: `feature/member-application-r2`  
Review/rollback baseline: `0aa936f37c5fdf7737b8203e487c7b9291be163e` (initially clean worktree)

## Scope and result

Completed a focused refinement of shared web presentation, navigation, GIS controls, and user-facing wording. Existing components, page structure, role navigation, and the established BLUE brand theme are retained. The final corrections supersede the initial green-token pass. No dependencies were added to the repository, and no new decorative animations or gradients were introduced.

No controllers, routes, requests, authorization, APIs, business services, database files, Flutter, Python, environment files, or cloud configuration were changed. No migrations, seeders, live database/Auth/storage operations, commits, pushes, or deployments were performed. Street Map/OpenStreetMap remains the only basemap. GIS classification artwork/colors and Point/Boundary logic were not changed.

## Audit findings and before/after behavior

The source audit covered all Blade templates, JavaScript UI strings, and the `resources/ui` catalogs. Neither `web/lang` nor `web/resources/lang` exists in this checkout. Input `placeholder` attributes and identifiers named `preview`/`template` were distinguished from temporary user-facing labels.

Final rendered inspection used actual Blade templates/components and production bundles with synthetic data. Ten pages were exercised at **1280, 768, 390, and 320 CSS pixels**: public landing, sign-in, public GIS, Admin GIS, Field Officer GIS, Association GIS, GIS editor, shared controls, the actual Area Management register, and the actual Registered Members register. An eleventh fixture represents the filtered Area destination. This is representative rendered coverage, not a claim that every application screen was browser-tested. The earlier eight-page/1440px run remains historical evidence in the initial verification section.

| Priority | Issue found | Before | After |
| --- | --- | --- | --- |
| High | Geoman actions clipped at 320px | The horizontal action row exceeded the map; Cancel was outside its visible bounds. | A container query stacks actions when the map is at most 360px wide. All three actions fit and are 44px tall. Wider maps keep the original horizontal controls. |
| Medium | GIS search icon overlaps text | The older served CSS let generic input padding override the original GIS rule. | GIS now uses the existing shared search-box wrapper and its 37px input padding. The decorative icon passes pointer events through. Normal, focus, populated, and disabled states clear the icon at all four requested widths. |
| Medium | Mobile actions and statuses hidden | Shared responsive rules hid page-header actions and panel status badges. | Actions wrap onto a new row; meaningful statuses remain visible. |
| Medium | Public navigation fails from map | Home/About/Public GIS/Staff Sign In used fragment-only links on every public page. | Links include the existing home route and section fragment, so they also work from `/map`. |
| Medium | Mobile close control appears on desktop with stale assets | The older local build had no sidebar-dismiss rule; the new Blade button inherited the desktop collapse styling. Its handler only closes a mobile drawer. | The control starts hidden, gains visibility only while the mobile drawer is open, and restores opener focus when closing it. Hamburger, backdrop, focus trapping, inert background, desktop collapse, and sidebar scrolling remain. Local assets were rebuilt to match the templates. |
| Medium | Shared controls are small and rigid | Buttons and inputs started at 39px; button text could not wrap. | Shared buttons/inputs start at 44px; button labels and action groups wrap. Form fields use 16px text at narrow phone widths. |
| Medium | Long dialogs/actions can crowd small screens | Dialog overflow and action wrapping were not explicit. | Dialogs scroll within 85dvh; long text wraps; close controls retain their size; action buttons wrap. |
| Medium | Primary brand consistency | The initial F1 source changed established blue tokens to green, and GIS had a hard-coded green selected view. | Restored established blue: primary `#0b4c8c`, hover `#083b70`, sidebar/KPI selection `#0b427b`, sidebar active `#35679d`. GIS view buttons use the primary tokens with hover, keyboard focus, and disabled states. Semantic status and GIS classification colors remain. |
| Medium | Area KPI styling differs from Registered Members | Area selection used a pale surface rather than the established solid blue selection. | Both groups use shared summary-card styles: the default first card is blue with white text/icons; other cards are white. Existing aria-current/filter logic moves the blue selection when a different filter is selected. Values and links are unchanged. |
| Low | Redundant bottom-right page tagline | Staff/Association footers repeated Association and project monitoring. | Removed only the second footer span; retained the system identity and public academic/program attribution. |
| Low | Dense table/form text and inconsistent surfaces | Shared table/body control text was mostly 12px. | Table body text is 13px, general inputs 14px, with neutral headers, clearer empty states, and wrapping footers. |
| Medium | Stale development wording and decorative controls | Prototype/preview labels, a disabled notification bell, and a Coming soon password action appeared in operational UI. Public copy incorrectly claimed working features were disconnected. | Production wording replaces these labels; decorative controls are removed; unavailable legacy actions retain explicit limitations. |

Measured contrast: white on primary blue **8.65:1**, white on selected KPI blue **10.10:1**, secondary text on white **4.76:1**, and sidebar text `#d1e0f2` on `#0b427b` **7.53:1**. These are checks of selected color pairs, not a complete WCAG conformance certification.

## Content cleanup inventory

Paths in this table are relative to `web/resources/views/`.

| File(s) | Removed/replaced text | Result |
| --- | --- | --- |
| `auth/login.blade.php`, `partials/public-navbar.blade.php` | Capstone Project Prototype | Association and project monitoring |
| `layouts/admin.blade.php`, `layouts/field-officer.blade.php`, `layouts/association.blade.php` | Capstone Project Prototype, then the redundant replacement tagline | Removed the second footer label; retained the system identity. |
| `auth/login.blade.php` | authorized AssocMap workspace.. | Corrected the duplicate period. |
| `layouts/public.blade.php` | independent Capstone prototype in page metadata | Accurate association-management, project-monitoring, and GIS description. |
| `partials/public-footer.blade.php` | Independent academic Capstone prototype | Independent academic Capstone project; attribution retained. |
| `partials/public-footer.blade.php` | Public landing page template · Staff access and public GIS are not connected. | Staff access requires an authorized account. The public map displays eligible published locations. |
| `public/sections/about.blade.php` | Landing-page introduction note | Adds explicit independent academic Capstone attribution while retaining the limitation that this page does not display live operational records. |
| `public/sections/features.blade.php` | These areas describe the intended system capabilities. Live services are not connected to this template. | Authorized workspaces bring association records, program activities, maps, and reports together. |
| `partials/page-header.blade.php` | UI preview badge/dot | Removed; functional header actions retained. |
| `components/workspace-header.blade.php`, `partials/admin-topbar.blade.php` | Notifications preview disabled bell and its unused component prop | Removed. No notification capability is implied. |
| `admin/users/register.blade.php` | Disabled Reset Password / Coming soon action | Removed. Existing Forgot password? sign-in link/recovery workflow remains. No administrator reset feature was implemented. |
| `components/empty-state.blade.php` | Data will appear here once connected. | No records are available in this view. |
| `components/stat-card.blade.php` | Data will appear once connected default note | Omitted unless a meaningful note is explicitly supplied; missing counter value still uses the existing dash. |
| `components/confirmation-modal.blade.php` | This action is not connected yet. | This action is unavailable in this view. No changes can be submitted. Unsupported submit remains disabled/guarded. |
| `components/filter-panel.blade.php` | Filtering is not connected yet | Filtering is unavailable in this view; disabled behavior preserved. |
| `layouts/admin.blade.php` | Email changes are not connected yet. | States that this legacy dialog is unavailable and directs users to Change Email on account details. Input constraints and disabled submission remain. |
| `admin/associations/edit.blade.php` | Association editing is not available yet. | Explicitly says editing is unavailable in this view and directs users to the association register. |
| `admin/shared/form.blade.php` | Account invitations are not connected yet. | Says invitations are unavailable in this form; directs users to Invite User in User Management. |
| `admin/shared/form.blade.php` | Options will appear once connected. | Says SAAD classification options are unavailable in this form. |
| `admin/shared/form.blade.php` | Saving is not connected yet. | Says saving is unavailable in this form; directs users to the module register. Existing no-submit/disabled behavior remains. |
| `admin/shared/show.blade.php` | Record details will appear here once connected. | Review the available record information. Existing available fields and missing-value fallbacks remain. |
| `admin/trainings/participants.blade.php` | Member selection will be available once connected. | Says selection is unavailable in this legacy form; directs users to the training record. Disabled controls remain disabled. |

Preserved: Pending/Approved/Archived/Unpublished and other meaningful statuses, required attribution, account/security warnings, permissions messaging, missing-data notices, publication/privacy/geography limitations, tile-unavailable fallback, OpenStreetMap attribution, legitimate photo/GeoJSON previews, and the landing map illustration's explicit “Illustration only” limitation. No statistics, records, accomplishments, or capabilities were invented. No JavaScript wording required replacement; remaining matching identifiers/attributes represent functional code or real previews.

## Exact affected files

Paths below are relative to the repository root. These are the complete F1 file changes.

**Shared styles (13):**

- `web/resources/css/app.css`
- `web/resources/css/components/summary-cards.css` (new)
- `web/resources/css/pages/area-management.css`
- `web/resources/css/pages/member-management.css`

- `web/resources/css/base/foundation.css`
- `web/resources/css/components/controls.css`
- `web/resources/css/components/forms.css`
- `web/resources/css/components/tables.css`
- `web/resources/css/layouts/admin-responsive.css`
- `web/resources/css/layouts/admin-shell.css`
- `web/resources/css/pages/admin-dashboard.css`
- `web/resources/css/pages/gis-workspace.css`
- `web/resources/css/pages/public-landing.css`

**Navigation behavior (1):**

- `web/resources/js/layouts/workspace-sidebar.js` — shared Close navigation handler and explicit visibility tied to the existing mobile drawer state; existing controller behavior reused.

**Blade templates (25):**

- `web/resources/views/admin/areas/register.blade.php`
- `web/resources/views/members/partials/summary.blade.php`
- `web/resources/views/partials/gis-workspace.blade.php`

- `web/resources/views/admin/associations/edit.blade.php`
- `web/resources/views/admin/shared/form.blade.php`
- `web/resources/views/admin/shared/show.blade.php`
- `web/resources/views/admin/trainings/participants.blade.php`
- `web/resources/views/admin/users/register.blade.php`
- `web/resources/views/auth/login.blade.php`
- `web/resources/views/components/confirmation-modal.blade.php`
- `web/resources/views/components/empty-state.blade.php`
- `web/resources/views/components/filter-panel.blade.php`
- `web/resources/views/components/stat-card.blade.php`
- `web/resources/views/components/workspace-header.blade.php`
- `web/resources/views/components/workspace-sidebar.blade.php`
- `web/resources/views/layouts/admin.blade.php`
- `web/resources/views/layouts/association.blade.php`
- `web/resources/views/layouts/field-officer.blade.php`
- `web/resources/views/layouts/public.blade.php`
- `web/resources/views/partials/admin-topbar.blade.php`
- `web/resources/views/partials/page-header.blade.php`
- `web/resources/views/partials/public-footer.blade.php`
- `web/resources/views/partials/public-navbar.blade.php`
- `web/resources/views/public/sections/about.blade.php`
- `web/resources/views/public/sections/features.blade.php`

**Verification (6):**

- `web/tests/Feature/InterfaceIconTest.php` — professional wording with retained academic/program attribution.
- `web/tests/Feature/UserManagementTest.php` — removed unavailable reset menu item; existing access destinations/guards retained.
- `web/tests/Feature/WorkspaceHeaderTest.php` — removed decorative bell and redundant footer label, updated wording, and asserted the initially hidden mobile close control; existing route, CSRF, input, dialog, and sidebar assertions retained.
- `web/tests/Frontend/render-web-ui.php` (new) — synthetic real-Blade fixtures using `TestEnvironmentGuard`; no database connections; separate temporary cache/storage/views and no application credential file.
- `web/tests/Frontend/build-web-ui.mjs` (new) — production Vite builds into the task's temporary directory with an empty environment directory.
- `web/tests/Frontend/web-ui-refinement.browser.mjs` (new) — responsive/interaction regression checks using headless Edge and temporary Playwright; loopback fixture server and blocked external requests.

**Report (1):** `docs/web-ui-refinement-batch-f1.md` (new).

## Final UI corrections and verification

The final corrections continue the existing uncommitted F1 work. No earlier content cleanup or Geoman fix was discarded.

| Correction | Files and implementation |
| --- | --- |
| Restore BLUE branding | `base/foundation.css`, `layouts/admin-shell.css`, `components/controls.css`, and `components/tables.css`: restore established blue/neutral tokens and remove the initial F1 green shell/tint overrides. Public branding and shared controls inherit the restored tokens. |
| Desktop/mobile close control | `components/workspace-sidebar.blade.php` and `layouts/workspace-sidebar.js`: the mobile close button starts with hidden and aria-controls; its hidden property follows drawerOpen. The close handler reuses setDrawer(false), restores hamburger focus, and has no desktop action. Existing CSS also hides it outside the mobile breakpoint. |
| Footer cleanup | Admin, Field Officer, and Association layouts remove only the second footer span. The system name remains; public academic attribution and program context remain. |
| GIS view states | `pages/gis-workspace.css`: replace the hard-coded green selected background with primary blue, use primary-hover blue, retain an explicit focus ring/inner contrast, and keep disabled selected styling distinct. The shared GIS partial applies it across all viewers. No GIS selection or switching JavaScript changed. |
| GIS search | `partials/gis-workspace.blade.php` uses the existing search-box wrapper. `pages/gis-workspace.css` removes redundant input/icon positioning; `components/tables.css` makes the decorative icon ignore pointer events. The existing shared input padding takes precedence over generic input padding. |
| Area/Member KPIs | New `components/summary-cards.css`, imported by `app.css`, holds one shared grid, white-card, blue selected-card, typography/icon, hover/focus, and phone-spacing pattern. Area and Member summary wrappers opt in; duplicated page rules are removed from `pages/area-management.css` and `pages/member-management.css`. The existing Member two-column phone layout is reused for both groups. Existing counts, href expressions, and aria-current logic are unchanged. |
| Regression coverage | Updated `WorkspaceHeaderTest.php` for the explicit hidden mobile control and single footer span. Extended the existing F1 renderer/browser harness with real Area/Member views, filtered Area navigation, synthetic Point/Boundary data, search/filter behavior, and computed visual-state/contrast checks. |

The correction file paths above are relative to `web/resources/css/`, `web/resources/views/`, `web/resources/js/`, or `web/tests/Feature/` as appropriate; the complete repository-relative inventory is listed above.

The old local asset was `web/public/build/assets/app-BKtNsnUe.css`: it contained blue primary tokens, the old low-specificity GIS search rule, a hard-coded green Map state, and no sidebar-dismiss rule. The earlier source-only F1 templates were being combined with this older build. A fresh pre-correction source build already hid the desktop close button correctly; the older served build explains why manual review still showed it. Final hidden-state handling also prevents that control from appearing before JavaScript or matching CSS loads.

| Final check | Executed result |
| --- | --- |
| Relevant Laravel feature tests | **178 tests / 4,450 assertions passed**: AreaManagementTest, MemberDirectoryUiTest, WorkspaceHeaderTest, GisManagementUiTest, InterfaceIconTest, and GlobalFeedbackTest. Area/Member values, destination filters, authorization, query budgets, and shared shell contracts remain covered. |
| Frontend tests | **278 passed, 0 failed, 3 fixture-dependent skips** across 281 existing Node tests. The skips belong to the separate native Association fixture harness, not these four corrections. |
| Browser checks | **542 passed** at **1280, 768, 390, and 320px**, using real Blade/production bundles, synthetic data, and blocked external requests. Ten page/viewport combinations per width produced **40 initial page captures**; an additional filtered Area fixture validates KPI navigation. No document overflow or JavaScript errors. |
| Sidebar | Admin, Field Officer, Association, Area, and Member pages: desktop close explicitly hidden; hamburger opens; visible mobile close and Escape close/restore focus; reverse Tab stays inside drawer; background inert; desktop collapse and current navigation remain. |
| GIS | All shared viewers: normal/focus/populated/disabled search padding and blue active/hover/focus/disabled view states. Association GIS: keyboard-select original location, switch to list, reselect the same record, type a search, combine it with geometry filters, Apply Filters, Reset, and return to map with original records. Classification color and Point/Boundary behavior remain. The original view-switch selection reset is unchanged; View location reselects that same record. |
| KPI visual reference | Area and Member default first card blue; others white. Computed selected-card backgrounds, borders, shadows, padding, number/note typography, and icon treatment match. Selected number/note contrast exceeds 4.5:1; actual keyboard Tab/Shift+Tab focus is visible; selected card stays blue on hover. Filtered Area navigation moves selection correctly. |
| Vite | Isolated production build and standard **npm.cmd run build** both passed, 42 modules. Local assets were refreshed for manual review; manifest equality and SHA-256 comparison confirm all **18 generated files** match the browser-tested build. No repository dependency or environment configuration change. |
| Source checks | PHP/JavaScript syntax and git diff --check passed. GIS marker artwork, map/view/filter JavaScript, routes, APIs, app services, database files, Flutter, and Python have no changes. |

The first targeted Laravel run encountered one outdated assertion forbidding every hidden sidebar element. It was narrowed to permit exactly the intentionally hidden mobile close button while retaining the ban on hidden navigation disclosures. The final 178-test rerun passed. Browser harness refinements used actual keyboard input for focus-visible checks and cleared pointer hover before comparing resting card styles; no application behavior was changed to satisfy those checks.

Current evidence: `%TEMP%\assocmap-ui-f1-30f18e9804eb4eee994914c0bbddaf0a` contains before/after screenshots, after-results.json, Node/build logs, local-build-match.json, a pre-correction tracked diff, and an archival copy of the older served build. That old build is evidence of the mismatch; do not pair it with current F1 templates. Final Laravel log: `%TEMP%\assocmap-media2-dde4c815a41f4115bb7b6617491eafd3\tests.txt`.

To roll back only the final corrections, reverse their reviewed hunks and retain the earlier F1 content cleanup/Geoman changes; the pre-correction tracked patch is available for comparison and excludes untracked report/helper files. Rebuild assets from whichever source state is selected. For complete F1 rollback, follow the baseline instructions below.

## Initial F1 verification (before final corrections)

| Check | Executed result |
| --- | --- |
| Focused pre-change Laravel baseline | **108 tests / 3,511 assertions passed.** |
| Before browser rendering | Captured all 32 page/viewport combinations and measured Geoman action bounds. At 320px Cancel did not fit; wider measured rows fit. |
| Initial F1 Laravel UI/workflow regression | **594 tests / 11,448 assertions passed**, including association native-form submission checks. Covers shared headers/icons, GIS, feedback, dashboards, program management, monitoring, users, associations, member directories, record/workflow navigation, and staff authentication characterization. |
| Node regression | **278 passed, 0 failed, 3 skipped** across 281 tests. The three skips require an exported native Association fixture; the Laravel native-form case subsequently supplied that fixture and passed all three native checks. |
| Initial F1 headless Edge UI checks | **180 passed** across 320/390/768/1440px. All 32 combinations have no document-level horizontal overflow. Checks include drawer focus containment/Escape/visible close, desktop collapse, current navigation, public menu/navigation from map, password visibility, header actions/statuses, required-field validation, long dialog scroll/focus restoration, GIS loading/error/recovery, search padding, and Point/Boundary operations. No browser JavaScript errors. |
| Geoman drawing | All Finish/Remove Last Vertex/Cancel actions fit. Narrow-map actions are 44px tall; 768/1440px actions remain the original 40px row. Removing a vertex, completing a Polygon, cancelling another draft, and placing a Point passed at all four widths. |
| Vite production build | **Passed**, 42 modules transformed. Actual production configuration was used with output and environment loading isolated. `web/public/build` and any running application's assets were not replaced. |
| Final source checks | PHP fixture syntax, JavaScript helper syntax, and `git diff --check` passed. Targeted development-label scan found no remaining prototype/demo/coming-soon/disconnected-service wording in web source. |

An initial broader run had two failures: one stale expected email-dialog sentence and a sandboxed native Edge launch exit (`2147483651`). The text expectation was updated to the requested content contract, and the native test passed outside the sandbox using the same database/provider isolation. A focused rerun passed **37 tests / 1,124 assertions**; the subsequent complete selected regression run passed all **594** tests. No application logic was changed to address the launcher failure.

Machine-local evidence:

- UI builds, screenshots, final `after-results.json`, and build log: `%TEMP%\assocmap-ui-f1-e76b420f84bd4d5a875df6ab5624bdef`.
- Initial F1 Laravel log: `%TEMP%\assocmap-media2-8bbf99fdeeb348a8aef3e22f4ca05017\tests.txt`.
- Pre-change Laravel log: `%TEMP%\assocmap-media2-755be3f8137f488ea7eb677fc2ee2e3b\tests.txt`.

Temporary artifacts are not committed and can expire with normal temporary-directory retention. Playwright 1.64.1 was installed only in the task's temporary tools directory; repository manifests/lockfiles were untouched. No interactive browser surface was available, so verification used the isolated headless browser harness.

To reproduce the UI checks from `web/` using an installed Edge browser:

```powershell
$uiEvidence = Join-Path $env:TEMP ('assocmap-ui-f1-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $uiEvidence
php tests/Frontend/render-web-ui.php $uiEvidence
npm.cmd install --prefix (Join-Path $uiEvidence 'tools') playwright@1.64.1 --no-save --package-lock=false --ignore-scripts --cache (Join-Path $uiEvidence 'npm-cache')
node tests/Frontend/build-web-ui.mjs $uiEvidence after
node tests/Frontend/web-ui-refinement.browser.mjs $uiEvidence after
node --test tests/Frontend/*.test.mjs
```

The original “before” evidence was captured before production-source edits. Building `before` now would use current source; it does not automatically check out the baseline.

From the repository root, the final Laravel selection was:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File scripts/storage/verify.ps1 -Filter 'InterfaceIconTest|WorkspaceHeaderTest|WorkspaceNavigationTest|GisManagementUiTest|GlobalFeedbackTest|DashboardServiceTest|ProgramManagementUiTest|MonitoringManagementUiTest|UserManagementTest|AssociationManagementTest|MemberDirectoryUiTest|MonitoringUiTest|RecordNavigationTest|WorkflowNavigationTest|StaffAuthenticationCharacterizationTest'
```

This is the selected regression suite, not the entire unrelated Laravel suite. Tests enforce SQLite `:memory:`, synthetic configuration, temporary storage/caches, and blocked Laravel HTTP. SQLite and synthetic browser responses do not establish native PostgreSQL/PostGIS, provider, or deployed acceptance.

## Remaining UI issues and unfinished features

- Legacy shared forms, participant selection, noninteractive filter variants, the legacy email dialog, and unsupported confirmation actions still contain disabled/unavailable operations. Their limitations now remain explicit. Dedicated current workflows exist for account invitations/email changes, association maintenance, and training participation; this batch does not activate or delete the legacy forms. Their retirement/reachability deserves a separate scoped review.
- There is no working notification panel represented by the removed bell, and this batch does not implement an administrator-initiated password-reset action. Existing self-service password recovery remains.
- Module-specific typography/spacing and specialized controls still have older local rules. This batch starts with shared components and does not claim every module was individually redesigned or all small touch targets eliminated.
- Cross-browser Safari/Firefox checks, physical-device touch and zoom, and assistive-technology review were not executed. Container queries require a supporting browser. The four tested CSS viewport widths are not physical-device acceptance.
- Live tiles were intentionally blocked. Tile-failure notices and attribution were preserved, but real basemap availability was not tested. No satellite source was introduced.
- Authenticated deployed workflows, real data density, provider failures, live GIS writes/publication, and native PostgreSQL/PostGIS behavior remain outside this local presentation verification. No hosting or release readiness claim is made.
- Wide Area and Member tables retain their existing horizontal scroll regions. Centered empty-state text can require horizontal scrolling at phone widths. This remains a separate presentation follow-up.
- User Management retains its existing pale selected-KPI treatment. Association, Program, and Monitoring summary groups already use blue selected states; they were inspected and left unchanged in this correction pass.

## Deployment impact and rollback

Deployment, if separately authorized, requires the changed Blade templates plus the matching production manifest/hashed assets. Refresh compiled Blade views through the established deployment procedure. The final corrections rebuilt local `web/public/build` for manual review; its 18 files match the isolated browser-tested build byte for byte. No remote deployment was performed. Avoid mixing these templates with an older build: that mismatch caused the reported desktop close control and repeated search overlap. There are no dependency, environment, schema, seeder, API, authentication, or business-rule deployment steps for F1. Test helpers and this report are development artifacts.

For rollback, preserve any subsequent unrelated edits first. Restore **only the 41 modified existing files enumerated above** from baseline `0aa936f37c5fdf7737b8203e487c7b9291be163e`, or reverse just the F1 hunks if those files have later work. Remove only the new shared summary-card stylesheet, the three new UI verification helpers, and this report for a complete source rollback. Rebuild/redeploy the matching baseline Vite assets and refresh compiled Blade views through the same established process. No database or environment rollback is required. Do not reset/clean the worktree or restore whole directories.

Implementation and verification stop here; no commit, push, deployment, or external service modification was performed.
