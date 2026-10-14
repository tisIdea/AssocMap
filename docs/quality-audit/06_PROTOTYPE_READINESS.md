# Capstone Prototype Readiness

Assessment date: **2026-10-13 (Asia/Manila)**. **A coherent source-backed web prototype is plausible, but a working demonstration is not verified.** Required browser/PostgreSQL/provider/role evidence is absent. The full promised system cannot presently be demonstrated: reports, invitation form integration, real dashboard totals, full account administration and Android organizational workflows are incomplete.

This is a readiness assessment, not permission to omit approved requirements. Capstone 1 and the approved evaluation instrument remain unavailable; obtain adviser acceptance for the demonstrated scope. Every gate below is Pending Verification or a source-confirmed gap, with no passing result.

## Minimum coherent demonstration path — proposed

1. Sign in through existing supported roles and show denied cross-role access.
2. Administrator reviews geographic references and an association with its registered area, assignment and actual registration records.
3. Linked Association Account submits an eligible member application; currently assigned Field Officer reviews it. Explain source-addressed F-005 and present actual concurrency evidence only after testing.
4. Admin/assigned FO manages an eligible project/resources and uses its shared detail modal.
5. Create a Project Point and Project Boundary with real approved test-site geometry; demonstrate edit, publish, unpublish and permanently confirmed delete as separate operations. Show Registered Area versus Project Site Address.
6. Public /map shows only eligible published project records. Show denied unpublished/archived/private fields and read-only account scope using actual results.
7. Record quarterly Monitoring with existing valid products/materials, visits/conductor and monthly data. Demonstrate account own-link read access and current officer scope.
8. Administrator opens actual GIS audit events. State explicitly that non-GIS coverage is absent.
9. Identify dashboard/report/invitation/mobile placeholders visibly. Demonstrate them as implemented only after separate approval, correction and verification.

Sources: web/routes/web.php; web/app/Services/{AssociationService,MemberApplicationService,ProjectService,MonitoringService,GisLocationService,GisQueryService}.php; web/app/Http/Controllers/Admin/AuditLogController.php; web/resources/views/projects/partials/location.blade.php; mobile/lib/main.dart. No script was performed.

## Readiness gates and possible demonstration failures

| Capability | Current source status | Demonstration failure risk | Minimum action / proof | Test IDs |
|---|---|---|---|---|
| Platform/assets/routes | Locked source prerequisites; not executed | Vite/Blade/import/bootstrap incompatibility; missing current build | Execute technical commands against current source; verify assets loaded | TECH-01 |
| Login/roles | Source implemented | Provider outage/expiry, inactive profile/role or missing invitations table | Real supported-role positive/negative sessions and outage/expiry checks | AUTH-01/02; ROLE-01 |
| Dashboard | Navigation source; counts zero | Showing zeros while organizations exist undermines correctness | Approve real definitions/queries or explicitly identify prototype limitation | DASH-01 |
| Geographic/association records | Source workflow with Specific Area/city limits | Attempting unsupported area edits/City creation; malformed acronym error | Demonstrate supported hierarchy only; fix approved invalid-input defect; verify association writes | AREA-01; ORG-01/03 |
| Registration | Optional initial entry + multi-record display | Duplicate/primary-date/agency constraints; expecting general registration CRUD | Real eligible initial-entry, duplicate rollback and existing-record display tests | ORG-02; DB-01 |
| Member application | F-005 source-addressed; input/schema limits differ | Oversized text errors, conflicting outcomes if runtime/schema differs | Personal syntax check; PostgreSQL same-ID concurrency/rollback; boundary validation | MEM-01/02/04 |
| President invariant | Trigger guard exists; cross-ID serialization not established | Concurrent different applications may violate organizational rule | Investigate QA-009 in disposable PostgreSQL; do not claim association-wide concurrency proof from F-005 | MEM-03 |
| Project/resources/details | Source implemented; no Account Project Details page | Invalid selected activity/category/commodity/resource/history relationships | Real permitted Admin/FO create/edit/archive and history tests | PRJ-01; MON-02; ROLE-01 |
| Training/activities | Source implemented with validation/status reconciliation gaps | Inactive participant DB error; ongoing rejected by baseline constraint | Confirm live constraints and approved prevalidation fix | TRN-01; ACT-01; DB-01 |
| GIS editor/dropdowns | Async guards/current-state/mode checks source-supported | Network/Geoman failures; missing parents or incompatible geometry | Test loading/error/retry/rapid switches, Point/Polygon/MultiPolygon/hole edit and console | GIS-01/03/04 |
| GIS public/lifecycle/delete | Source scoped/published-only; actual delete gate exists | Empty map legitimately caused by no eligible published rows; live FK/trigger/permission blocker | Prepare approved existing eligible fixtures; confirm public exclusions and precise delete blockers/rollback | GIS-02/05/06; DB-01 |
| Monitoring | Source sections/history/period guards | Missing migrations, wrong quarter/month/resource IDs or stale concurrent edits | Real SQL period/quantity/conductor/resource tests; own-scope reads | MON-01/02; DB-01 |
| Invitation/account activation | Backend source; create form disconnected | Cannot start a new-user UI demonstration; provider template/store/email mismatch | Integrate form only after approval; real sandbox invite/activate/recovery. Existing accounts support other demos but do not prove invitation UI | INV-01/02 |
| Audit viewer | Real Admin DB viewer; GIS events | Promising complete organizational history; no current event fixtures | Test Admin-only filters/redaction/GIS events; disclose non-GIS coverage | AUD-01/02 |
| Reports | Configuration UI only | Disabled Generate Report, no real charts/downloads | Approved one end-to-end report before any report claim | REP-01/02 |
| Flutter | Counter only | Counter mistaken for mobile organizational system | Separate prototype scope acceptance or approved mobile implementation | MOB-01; API-01 |
| Navigation/interaction | Labels/dialog/focus/media indicators | Keyboard/touch/focus/responsive errors, stale options, misleading placeholders | Device/keyboard/state checks and actual task observations | UI-01; UAT-01 |

## Honest empty-state interpretation

Public GIS may correctly be empty because records are unpublished, archived, under inactive/archived associations, linked to archived projects, unsupported legacy types or outside the requested viewport. A source-defined empty/error distinction is not proof of a database failure. Use scoped approved fixtures and actual responses; never insert fake geometry or relax publication rules merely to make the demonstration look populated. Source: web/app/Services/GisQueryService.php::{scoped,emptyReason,overview,features}; web/resources/js/pages/gis-workspace.js.

## Proposed presentation boundaries

Use real permitted sandbox accounts and existing authorized test records. Verify every advertised workflow before presentation and retain current-source artifacts. Avoid presenting initial map center as a stored site, illustrative landing preview as live data, blank report screen as analytics, actor/time rows as cryptographic non-repudiation, the Flutter counter as a mobile module, or source checks as completed alpha/UAT.

The [module inventory](03_MODULE_STATUS_INVENTORY.md) supplies actual interfaces and missing capabilities. [Manual verification](10_MANUAL_VERIFICATION_PLAN.md) distinguishes current checks from after-implementation tests. [Evidence mapping](08_CAPSTONE2_EVIDENCE_MAPPING.md) prevents unsupported manuscript claims. No prototype sign-off was issued.

