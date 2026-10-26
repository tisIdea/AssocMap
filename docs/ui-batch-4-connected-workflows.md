# UI Batch 4 — Connected Business Workflows

- Workspace: `C:\dev\AssocMap-MemberAPI`
- Branch: `ui/connected-workflows`
- Starting commit: `743b394df04d93982075d4ec0102d76c374908df` (clean tree)
- Verification date: 2026-10-26

## Assessment and gap matrix

| Module | Existing implementation | Confirmed gap | Batch 4 outcome |
| --- | --- | --- | --- |
| Staff Association details | Five scoped, paginated tabs: Members, Projects, Trainings, Program Activities, Registrations. Register filters already travel into details. | Opening a child loses the tab/history position. Administrator member-register link supplies a name where validation requires a UUID, plus an obsolete anchor. | Keep the five tabs; carry list/tab/page context to child records; use the association UUID for both staff member registers. |
| Staff Project details | Parent Association, products/materials, Monitoring, Training and Activity histories, finalization/audit controls and contextual reporting already exist. | Back links reset the register; histories do not retain an explicit return path. | Shared back link and breadcrumbs; child links remember independent history pages and the corresponding history anchor. |
| Monitoring | Staff Project link and all existing workspace sections already exist; Association Account has no Project link. | Dashboard filters disappear when returning. Parent Project return is unclear, especially after switching sections. | Remember the filtered dashboard or actual parent Project through section tabs and visit filtering. Add the own-Project link only where the existing Projects policy permits it. |
| Training / Program Activity | Staff parent links already enforce `canViewProject`; own-account relations are separately scoped. | Back returns to a fresh list; parent labels/context are inconsistent. | Preserve the original register or recorded parent; show role-specific breadcrumbs; retain missing-parent text and existing permission guards. |
| Members / Officers | Staff member details are dialogs, not standalone show routes. Officer lists and appointment forms already exist. | Member dialog offers no Association path. Officer return can lose its source register or Association tab. | Link the dialog's loaded Association; allow bounded returns to the selected Association or staff register; preserve context through officer selection/cancel GETs. |
| Own-account Project histories | Products, assistance/materials and Monitoring are paginated. Training/Activity directories and details exist separately. | No inverse Training/Activity history on own Project details; their directories cannot filter by Project. | Preserve existing histories and add valid detail-to-Project breadcrumbs. Do not invent a filtered history link or a new backend. |
| Contextual reports | Existing Association Distribution and Project Portfolio actions, supported filters, loading/download handling and role rules. | No new reporting defect identified in this workflow scope. | Preserve these actions and contracts. No new report generation on GET. |

Monitoring is already reachable through Association → Projects → Project Monitoring history; no duplicate Association Monitoring tab or hub was added.

## Implementation and before/after paths

| Journey | Before | After |
| --- | --- | --- |
| Association → Project → back | Fresh Project register | Same Association, related-record tab/page, original Association register filters |
| Filtered Project/Training/Activity register → details → back | Fresh register | Supported search, status, year, sorting and pagination restored |
| Project history → Training/Activity → back | Fresh module register | Same recorded Project, history page and history anchor |
| Filtered Monitoring dashboard → record → section → back | Dashboard reset to reporting year | Original search/year/quarter/validation/sort/page retained |
| Project → Monitoring → another section → back | Dashboard rather than Project | Actual parent Project and its Monitoring history page/anchor |
| Association → Members | Admin sends an unsupported name filter | Valid UUID-filtered register and return to the same Association tab |
| Officer register/Association → Officers → select/cancel | Source context lost | Context retained through these GET steps; existing POST workflows unchanged |

`App\View\WorkflowNavigation` performs presentation-only URL construction. It does not fetch records or grant access. Each view declares existing permitted destinations using already loaded records. Candidate return paths must match those exact paths. Only supported filter/page keys are retained; action/editor/lookup/finalization state is dropped. External, cross-role, unrelated-record, malformed and oversized candidates fall back to the normal role-specific register. Only known history anchors are retained. Nested context is bounded to 4,096 bytes; unusually long journeys discard older return context.

Breadcrumb labels are Blade-escaped; native links remain keyboard-operable. The small CSS component wraps long labels and stacks at narrow widths. No new JavaScript, controller, service, request, route, authorization or database change was needed.

## Changes by role

| Role | Behavior and limits |
| --- | --- |
| System Administrator | Existing organization-wide authorized records, including permitted historical records; filtered registers and parent histories retained. Existing audit/finalization/export actions preserved. |
| Field Officer | Same navigation improvements within current assignments. Existing inactive/archive/read-only and cross-association denials remain authoritative. |
| Association Account | Own Projects, Trainings, Activities and Monitoring links only; Association name remains plain text because no staff Association management route is available. No editing or export privilege added. |
| Public Guest | No private workflow access added; public GIS/published routes untouched. |

Existing policy difference deliberately preserved: an active Association Account link can retain historical Monitoring access when its Association is inactive/archived, while own Project details require an active, unarchived Association. In that case Monitoring displays the Project name without an unavailable Project link and returns to Monitoring. This batch does not harmonize those policies.

## Files changed

- Presentation helper: `web/app/View/WorkflowNavigation.php`.
- Shared UI/styles: `web/resources/views/components/workflow-navigation.blade.php`, `web/resources/css/components/workflow-navigation.css`, `web/resources/css/app.css`.
- Associations: `web/resources/views/associations/{show,officers}.blade.php`, `web/resources/views/associations/partials/{actions,related}.blade.php`.
- Staff programs: `web/resources/views/{projects,trainings,program-activities}/{register,show}.blade.php`.
- Own-account programs: `web/resources/views/association/{projects,trainings,program-activities}/{index,show}.blade.php`.
- Members: `web/resources/views/admin/members/register.blade.php`, `web/resources/views/field-officer/members/index.blade.php`, `web/resources/views/members/partials/{workflow-navigation,details,filters}.blade.php`.
- Monitoring: `web/resources/views/monitoring/partials/{context,filters,quarter-card}.blade.php`.
- Tests: `web/tests/Feature/WorkflowNavigationTest.php`; additions to `AssociationManagementTest`, `AssociationProgramReadTest`, `AssociationProjectsTest`, `MemberDirectoryUiTest`, `MonitoringManagementUiTest`, `ProgramManagementUiTest`; `web/tests/Support/AssertsWorkflowNavigation.php`.
- This report: `docs/ui-batch-4-connected-workflows.md`.

## Verification and evidence boundaries

All PHP verification used `TestEnvironmentGuard`, synthetic identities, SQLite `:memory:`, uncached configuration, temporary storage/cache, array services and blocked stray Laravel HTTP. Native Edge used fresh profiles, a loopback fixture server and blocked external traffic. No configured Supabase database, Auth or Storage was accessed.

| Check | Executed result |
| --- | --- |
| Pre-change focused baseline | 516 tests / 7,433 assertions; two failures: sandbox Edge crash before interaction and the already stale Association Account sidebar-dropdown assertion. |
| Focused regression after implementation | 549 tests / 7,736 assertions passed, excluding exactly the pre-existing sidebar test named below. Includes the unchanged native browser submission test run outside the sandbox. |
| Final officer navigation follow-up | 73 tests / 526 assertions passed after tightening officer register/selection/cancel returns. |
| Missing-parent follow-up | Connected staff-record test passed with 84 assertions, including deleted related Projects for both staff roles and safe register fallback. |
| Navigation coverage | Nested Association → Project → Training/Activity returns; all role registers; pagination; member UUID filter; exact loaded parent; invalid/external/action/cross-role returns; escaping; query-free helper. |
| Existing security/business regressions | Assignment and account scope; guest/wrong-role/revoked access; inactive/archived parents; archived/finalized Projects; missing/foreign relations; empty histories; existing forms/dialogs, concurrency/finalization and contextual report authorization contracts. |
| Frontend tests | 89 selected tests passed: workspace navigation, Association UI, area navigation, contextual reports, program activities/lookups, training forms, Monitoring periods/visits, feedback and Reports workspace. |
| Vite | Production build passed using an empty environment directory and ignored output directory; no dependency changes. |
| Native Edge workflow verification | Final run: 48 role/module/viewport cases and 210 actual Blade page loads passed at 320, 768 and 1440 px; filtered returns, tabs, member dialogs, officer selection/cancel, keyboard activation and Tab focus, no page overflow or JavaScript exceptions. Screenshots visually inspected. |
| Route registry | All 247 registrations unchanged, including actions, parameters and middleware; 244 named routes; no duplicate names. Direct pre/post byte comparison passed. |
| Report route registry | All 12 web / 2 API report routes unchanged; direct pre/post byte comparison passed. |
| Syntax/format | PHP syntax checks passed for changed PHP/Blade files; Pint passed for new PHP helper/test/support files; `git diff --check` passed. |

The full selected suite remains red solely for the pre-existing `AssociationProgramReadTest::test_sidebar_renders_one_expanded_group_with_four_links_and_active_child`, which expects the dropdown removed by UI Batch 1. It was reproduced before this work and left unchanged. Four lifecycle/action tests were updated only to expect the intended `return_to` query parameter; their row visibility, exact destinations, permissions, forms, CSRF, lifecycle and confirmation assertions remain intact.

The historical route-capture utility also reports failure against its older approved `f782e…` baseline both before and after this task. Current pre/post route bytes are identical: SHA-256 `bcd49c31e4b11c204b9dad3f77c16b322eea6080a234195741e57bd5173b7808`. Report-route SHA-256 is unchanged at `7af8bd843ef3fe70d2f1f49de717e7207861979af073a0f2d08959fb6729f706`. The historical mismatch is not a Batch 4 regression.

Evidence is retained locally in `%TEMP%\assocmap-ui-batch4-20261026\` (test logs, isolated runtime and pre/post route snapshots) and ignored `web/node_modules/.cache/assocmap-ui-batch4/` (synthetic rendered pages, locked Vite build, Edge results/screenshots). Browser checks exercise actual rendered Blade and assets; HTTP authorization/database behavior is covered separately by PHP tests. This is not hosted Supabase/PostgreSQL, provider Auth, physical-device or deployment acceptance.

## Performance

No reporting generation, additional dataset, eager-load expansion, database query or network request was added to normal GETs. Navigation constructs URLs from existing models/paginators and validates bounded query metadata. Existing query budgets passed: Association registers Admin 9 / Field Officer 7; officer directories 5; Project registers 8 → 8; Training registers 9 → 9; Activity registers 9 → 9; Member registers Admin 10 → 10 / Field Officer 11 → 11 as fixture rows increase from 1 to 30. Monitoring list/section query counts also remain bounded at their baseline values.

## Remaining gaps and recommendation

- Own Project → Training/Activity inverse histories require an explicit scoped read-model/controller extension in a future batch; no unsupported filter link was added.
- Existing form submissions keep their existing controller redirects. This batch preserves read-navigation context and officer selection/cancel GET context; it does not change mutation redirect contracts.
- Broken or inaccessible parent relations remain non-links or are denied by existing controllers. Return metadata cannot restore access after an assignment/account/lifecycle change.
- Very long nested journeys fall back to a nearer record/register once the context size bound is reached.
- Repair the stale sidebar test and refresh the historical route baseline separately if an entirely green legacy verification command is required.

Recommendation: **GO for committing the scoped Batch 4 changes**, subject to the explicitly documented pre-existing verification debt. No commits, staging, pushes, merges or deployments performed. Stop after verification.
