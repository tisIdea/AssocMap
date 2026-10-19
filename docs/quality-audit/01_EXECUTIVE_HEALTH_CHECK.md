# Executive Source-Level Health Check

Assessment date: **2026-10-13 (Asia/Manila)**. Original project: **C:/dev/AssocMap**. This is a **read-only application audit with documentation creation only**. No application/migration/configuration/dependency files changed. No credentials/environment/private records were accessed; no tests, syntax checks, builds, SQL, provider calls, Git, Python, Flutter commands, application/browser sessions, test data or deployment occurred.

## Overall health

AssocMap has a substantive Laravel web implementation, especially organizational workflows, scoped project GIS, publication lifecycle and activation state handling. It remains an **unverified prototype with incomplete modules**, not a demonstrated complete system or a production-approved release. Clear source defects and coverage gaps remain. No independently confirmed severe Critical failure/exploit was established.

F-005 is **addressed in source, not functionally verified**. Member decision callbacks lock and reload before pending checks; stale JSON409 and existing browser validation feedback are defined. The previous unused import cleanup also remains source-addressed. Neither was implemented again in this audit. A separate potential President invariant race across different application IDs was identified; see QA-009.

The existing project/module/route inventories were rechecked across all major areas and key source workflows/constraints were inspected. This is a complete scoped inventory with risk-based source review, not a formal line-by-line proof, PHP/JS parser run, dependency scan, penetration test or exhaustive assurance of every possible interaction. Evidence that requires execution remains explicitly unknown. No source revision was collected because Git commands are prohibited.

## ISO/IEC 25010 lens

The requested primary lens is [ISO/IEC 25010:2023, official ISO publication](https://www.iso.org/standard/78176.html), a nine-characteristic product model. [Assessment02](02_ISO_IEC_25010_ASSESSMENT.md) supplies 40 subcharacteristic rows and applicability/edition limits. No artificial scores, percentages or certification claims are made.

| Characteristic | Source assessment | Current implication |
|---|---|---|
| Functional Suitability | Partially supported | Core web workflows present; report/invitation UI/dashboard/account/mobile gaps and input mismatches |
| Performance Efficiency | Requires runtime verification | GIS/audit bounds and eager/deferred work help; unbounded org lists and per-request Auth remain unmeasured |
| Compatibility | Partially supported | Locked stack/JSON/GeoJSON contracts; SQL string/status divergence, provider assumptions and no Flutter client |
| Interaction Capability | Partially supported | Labels/focus/dialogs/responsive/loading/retry indicators; no user/device evaluation and disabled workflows |
| Reliability | Partially supported | Transactions/locks/recovery guards; F-005 source-addressed; real concurrency/rollback/restoration proof absent |
| Security | Partially supported | Role/assignment/link/public allowlists and activation safeguards; incomplete audits, live grants/session/advisories unknown |
| Maintainability | Partially supported | Conventional separation and shared services; large synchronization services, sparse org tests and historical report corrections |
| Flexibility | Partially supported | Configuration boundaries exist; regional/proxy assumptions, missing organizational APIs and unproven deployment/capacity |
| Safety | Context-dependent / partially supported | Ordinary data/location/publication/history risks relevant; physical fail-safe/hazard-warning rows not applicable to inspected informational scope |

## Current module snapshot

| Module | Implementation status | Execution status |
|---|---|---|
| M01 Staff authentication | Implemented in source | Runtime verification required |
| M02 Invitation provisioning | Partially implemented | Runtime verification required |
| M03 Invitation activation | Implemented in source | Runtime verification required |
| M04 Account administration | Placeholder/UI-only | Runtime verification required |
| M05 Administrator dashboard | Placeholder/UI-only for totals; source navigation implemented | Runtime verification required |
| M06 Area Management | Partially implemented | Runtime verification required |
| M07 Association Management | Implemented in source; Source-level defect identified | Runtime verification required |
| M08 Association Registration | Partially implemented (limited intended initial-entry workflow in source) | Runtime verification required |
| M09 Member applications | Implemented in source; Source-level defect identified; F-005 addressed in source | Runtime verification required |
| M10 Official members | Partially implemented (routed directory/lifecycle) | Runtime verification required |
| M11 Projects/materials/products | Implemented in source | Runtime verification required |
| M12 Training/participants | Implemented in source; Source-level defect identified | Runtime verification required |
| M13 Program Activities | Implemented in source; Runtime verification required | Runtime verification required |
| M14 Monitoring | Implemented in source; Runtime verification required | Runtime verification required |
| M15 Internal GIS Management | Implemented in source; Runtime verification required | Runtime verification required |
| M16 Public GIS | Implemented in source; Runtime verification required | Runtime verification required |
| M17 Project Location/address display | Implemented in source; Runtime verification required | Runtime verification required |
| M18 Audit Logging Stage A | Partially implemented (viewer and GIS producers) | Runtime verification required |
| M19 Reports & Analytics | Placeholder/UI-only | Runtime verification required |
| M20 API foundation | Partially implemented | Runtime verification required |
| M21 Flutter Android application | Placeholder/UI-only | Runtime verification required |
| M22 Public landing/navigation | Implemented in source; Runtime verification required | Runtime verification required |
| M23 Framework infrastructure | Partially implemented (framework support) | Runtime verification required |

## Top ten priorities

The rank includes feature gaps, potential risks and missing release evidence; the Evidence class prevents treating them all as reproduced defects.

| Rank / ID | Severity | Finding | Evidence class |
|---|---|---|---|
| 1 / QA-001 | High | Current technical and end-to-end evidence is absent. | Confirmed evidence gap |
| 2 / QA-002 | High | Two migration tracks, server grants and deployed schema need reconciliation. | Source divergence plus unknown live state |
| 3 / QA-003 | High | Production configuration and operational recovery are unverified. | Potential risk; effective settings unknown |
| 4 / QA-004 | High | Business and account workflows outside GIS have no audit producers. | Confirmed missing coverage |
| 5 / QA-005 | High | Database append-only enforcement and deletion-event retention are not established. | Potential risk; live rights unknown |
| 6 / QA-006 | High | Invitation endpoint has no usable Administrator form integration. | Confirmed integration gap |
| 7 / QA-007 | High | Generation, aggregations, charts and exports are not implemented. | Confirmed missing backend |
| 8 / QA-008 | High | Previously reported npm vulnerabilities remain unidentified. | Unverified advisory evidence gap |
| 9 / QA-009 | High | One-active-President guard does not serialize two different application approvals. | Potential concurrency risk; source guard weakness |
| 10 / QA-010 | Medium | Request string limits exceed member/application SQL lengths. | Confirmed source/schema mismatch |

## Confirmed defects versus unknown conditions

Confirmed source defects/mismatches: acronym preprocessing (QA-011), member/application request lengths against SQL (QA-010), training active-status prevalidation against SQL (QA-012). Confirmed missing features/coverage: invitation form connection, report backend/export, real dashboard counts, full account lifecycle/mobile workflows and non-GIS audit producers. F-005's former same-ID decision guard gap is now source-addressed.

Potential risks/evidence gaps: actual schema/grants/RLS, production settings/cookies/TLS, append-only history/tombstone retention, provider contract/outages, dependency advisories, capacity, real rollback/concurrency and President cross-ID race. None are converted into proven exploits or measurements. The previous two Critical npm findings remain user-reported **unidentified**; this audit assigns High release uncertainty without guessing packages.

## Prototype versus production

A source-backed web demonstration path exists for eligible roles, organizations, applications, projects, GIS and Monitoring, but must pass personal verification before presentation. Reports/invitation UI/dashboard/mobile claims cannot be demonstrated as complete. Scope exclusions require adviser/owner acceptance. See [prototype06](06_PROTOTYPE_READINESS.md).

Production remains unverified until schema/access/configuration, named advisory review, current-source runtime/role/PostGIS/invitation/concurrency/rollback/UAT/capacity evidence and backup restoration/runbook acceptance exist. A temporary development tunnel is not production hosting. See [production07](07_PRODUCTION_READINESS.md).

## Previous findings re-evaluated

The prior register is a historical snapshot. Current source was used for the dispositions below; no earlier pass result was assumed. All outcomes remain unexecuted unless supplied later by the user.

| Prior finding | Current disposition | Current evidence/limit | Current reference |
|---|---|---|---|
| F-001 | Requires runtime confirmation | Unknown advisories remain; no Critical defect inferred | QA-008 |
| F-002 | Still present | GIS-only actual producers; no non-GIS shared writer calls found | QA-004 |
| F-003 | Requires runtime confirmation | Model callbacks remain; actual DB rights/protection/retention unknown | QA-005 |
| F-004 | Still present | No current execution/acceptance artifacts supplied | QA-001 |
| F-005 | Addressed in source | Both decisions transact/reload/lock/re-authorize/recheck pending; real concurrency/rollback pending | MEM-02; QA-009 is a different invariant |
| F-006 | Still present | Authorized provisioning endpoint; disabled/disconnected Admin form | QA-006 |
| F-007 | Still present | No generation/aggregation/export backend | QA-007 |
| F-008 | Requires runtime confirmation | Effective settings/hosting/recovery unknown; source defaults only | QA-003 |
| F-009 | Requires runtime confirmation | Two source migration tracks still differ; deployed constraints/history unknown | QA-002 |
| F-010 | Still present | Nonempty array acronym reaches trim before string validation | QA-011 |
| F-011 | Still present | Ordinary signIn/getUser handling differs from explicit bounded invitation requests | QA-013 |
| F-012 | Still present | No application limiter for versioned own-profile endpoint; edge unknown | QA-014 |
| F-013 | Still present | Unbounded directories/Monitoring in-memory search; impact unmeasured | QA-015 |
| F-014 | Still present | Local logout/no refresh implementation; policy/runtime effect pending | QA-018 |
| F-015 | Still present | Training service lacks active-status predicate required by SQL trigger | QA-012 |
| F-016 | Still present | Dashboard closure passes constant zeros | QA-016 |
| F-017 | Still present | Counter mobile and profile-only API foundation | QA-017 |
| F-018 | Addressed in source | Unused MemberClassification import absent; lint pending | TECH-01 |
| F-019 | Still present | Intentional project-only GIS; legacy association points hidden/preserved | QA-020 |
| F-020 | Still present | User confirmed manuscript unavailable; questionnaire/edition not found | QA-022 |
| F-021 | Still present | Limited sanitized diagnosis/correlation/timing evidence | QA-019 |
| F-022 | Still present | Monitoring/project history eligibility differs from GIS; no bypass established | QA-021 |

## Recommended correction/verification order

1. Establish approved scope/current source, technical compatibility, actual advisory IDs and deployed schema/grants/isolation prerequisites.
2. Personally verify F-005 and investigate the distinct-application President guard; approve corrections for acronym/input-length/training prevalidation defects.
3. Connect the invitation form to its existing authorized backend and prove actual activation/recovery.
4. Define audit catalogue/retention/actor policy; approve missing producers and database protection design.
5. Implement one approved report end to end and real dashboard count definitions; only then advertise analytics.
6. Measure Auth/SQL/network/collections/assets and improve proven bottlenecks. Add abuse/failure handling only through reviewed changes preserving authorization.
7. Execute device/role/PostGIS/history/UAT and recovery checks; document a tested staging installation/release runbook. Develop mobile workflows after approved API/contracts.
8. Reconcile research scope/ISO instrument with the adviser before final manuscript or acceptance-score claims.

These are proposals, not implemented patches. Any source, database, dependency, authentication or deployment change requires separate authorization.

## Verification and documentation evidence

The current source has39 declared PHP test methods in seven classes, mostly isolated SQLite/provider fixtures, and one Flutter counter widget smoke test. They do not establish real PostGIS, SMTP, RLS, F-005 or UAT behavior. [Manual plan10](10_MANUAL_VERIFICATION_PLAN.md) provides 45 unique cases with blank Actual result cells and Not Executed status, including meaningful same-ID/cross-ID membership tests.

Source-only file-presence inspection found no missing targets among 17 matched relative JavaScript imports and 245 matched App use declarations in the inspected PHP tree. This does not prove syntax, autoload/package resolution, dynamic includes, generated assets or runtime behavior.

The approved Capstone 1 manuscript is unavailable per user confirmation; no approved questionnaire/edition record was found. The user-provided outline is available. Technical working IDs/diagrams/table definitions can support Chapter III, but methodology/organizational research/literature/physical topology/hardware and actual results must come from genuine approved material. See [mapping08](08_CAPSTONE2_EVIDENCE_MAPPING.md).

## Package navigation

1. [Executive health check](01_EXECUTIVE_HEALTH_CHECK.md)
2. [ISO/IEC 25010 assessment](02_ISO_IEC_25010_ASSESSMENT.md)
3. [Module status inventory](03_MODULE_STATUS_INVENTORY.md)
4. [Security/data integrity](04_SECURITY_AND_DATA_INTEGRITY.md)
5. [Performance/reliability](05_PERFORMANCE_AND_RELIABILITY.md)
6. [Prototype readiness](06_PROTOTYPE_READINESS.md)
7. [Production readiness](07_PRODUCTION_READINESS.md)
8. [Capstone 2 evidence mapping](08_CAPSTONE2_EVIDENCE_MAPPING.md)
9. [Prioritized findings](09_PRIORITIZED_FINDINGS.md)
10. [Manual verification plan](10_MANUAL_VERIFICATION_PLAN.md)

## Inspected scope and canonical source inventory

The refreshed inventory contains 125 PHP app/route/test/migration paths and 128 frontend/resource paths. Config/bootstrap/entry points/manifests/locks, seven Supabase migrations, Flutter source/manifest/widget test and all ten prior report files are additional inspected evidence. Module-specific current methods and SQL objects are cited in reports03–09. An inventory item does not imply every line received equal scrutiny. Generated assets, runtime caches/logs, dependencies' complete internals, private data and environment files are excluded.

Application / routing / tests / Laravel migrations:

- `web/app/Exceptions/InvitationActivationException.php`
- `web/app/Http/Controllers/Controller.php`
- `web/app/Http/Controllers/GisWorkspaceController.php`
- `web/app/Http/Controllers/MonitoringWorkspaceController.php`
- `web/app/Http/Controllers/PublicGisController.php`
- `web/app/Http/Controllers/Admin/AreaController.php`
- `web/app/Http/Controllers/Admin/AssociationController.php`
- `web/app/Http/Controllers/Admin/AuditLogController.php`
- `web/app/Http/Controllers/Admin/BarangayController.php`
- `web/app/Http/Controllers/Admin/MemberController.php`
- `web/app/Http/Controllers/Admin/MonitoringController.php`
- `web/app/Http/Controllers/Admin/MunicipalityController.php`
- `web/app/Http/Controllers/Admin/ProgramActivityController.php`
- `web/app/Http/Controllers/Admin/ProjectController.php`
- `web/app/Http/Controllers/Admin/TrainingController.php`
- `web/app/Http/Controllers/Admin/UserInvitationController.php`
- `web/app/Http/Controllers/Api/V1/MeController.php`
- `web/app/Http/Controllers/AssociationAccount/MemberApplicationController.php`
- `web/app/Http/Controllers/Auth/InvitationActivationController.php`
- `web/app/Http/Controllers/Auth/StaffAuthController.php`
- `web/app/Http/Controllers/FieldOfficer/MemberApplicationController.php`
- `web/app/Http/Controllers/FieldOfficer/MonitoringController.php`
- `web/app/Http/Controllers/FieldOfficer/ProgramActivityController.php`
- `web/app/Http/Controllers/FieldOfficer/ProjectController.php`
- `web/app/Http/Controllers/FieldOfficer/TrainingController.php`
- `web/app/Http/Middleware/AuditRequestGuard.php`
- `web/app/Http/Middleware/EnsureRole.php`
- `web/app/Http/Middleware/EnsureSupabaseApiAuthenticated.php`
- `web/app/Http/Middleware/EnsureSupabaseAuthenticated.php`
- `web/app/Http/Middleware/GisRequestGuard.php`
- `web/app/Http/Middleware/ProtectInvitationActivation.php`
- `web/app/Http/Requests/AuditLogIndexRequest.php`
- `web/app/Http/Requests/DeleteGisLocationRequest.php`
- `web/app/Http/Requests/GisLifecycleRequest.php`
- `web/app/Http/Requests/GisParentRequest.php`
- `web/app/Http/Requests/GisQueryRequest.php`
- `web/app/Http/Requests/SaveGisLocationRequest.php`
- `web/app/Http/Requests/SetInvitationPasswordRequest.php`
- `web/app/Http/Requests/StaffLoginRequest.php`
- `web/app/Http/Requests/StoreAreaRequest.php`
- `web/app/Http/Requests/StoreAssociationRequest.php`
- `web/app/Http/Requests/StoreBarangayRequest.php`
- `web/app/Http/Requests/StoreMemberApplicationRequest.php`
- `web/app/Http/Requests/StoreMemberRequest.php`
- `web/app/Http/Requests/StoreMonitoringRequest.php`
- `web/app/Http/Requests/StoreMonitoringVisitReportRequest.php`
- `web/app/Http/Requests/StoreMunicipalityRequest.php`
- `web/app/Http/Requests/StoreProgramActivityRequest.php`
- `web/app/Http/Requests/StoreProjectRequest.php`
- `web/app/Http/Requests/StoreTrainingRequest.php`
- `web/app/Http/Requests/StoreUserInvitationRequest.php`
- `web/app/Http/Requests/UpdateMonitoringSectionRequest.php`
- `web/app/Http/Requests/VerifyInvitationRequest.php`
- `web/app/Models/Area.php`
- `web/app/Models/Association.php`
- `web/app/Models/AssociationAccount.php`
- `web/app/Models/AssociationPosition.php`
- `web/app/Models/AssociationRegistration.php`
- `web/app/Models/AuditLog.php`
- `web/app/Models/Barangay.php`
- `web/app/Models/Commodity.php`
- `web/app/Models/FisheriesProjectCategory.php`
- `web/app/Models/GisLocation.php`
- `web/app/Models/Member.php`
- `web/app/Models/MemberApplication.php`
- `web/app/Models/MemberClassification.php`
- `web/app/Models/MonitoringIncome.php`
- `web/app/Models/MonitoringMaterial.php`
- `web/app/Models/MonitoringProduction.php`
- `web/app/Models/MonitoringProductionMonthly.php`
- `web/app/Models/MonitoringRecord.php`
- `web/app/Models/MonitoringVisitReport.php`
- `web/app/Models/Municipality.php`
- `web/app/Models/ProgramActivityRecord.php`
- `web/app/Models/Project.php`
- `web/app/Models/ProjectMaterial.php`
- `web/app/Models/ProjectProduct.php`
- `web/app/Models/Quarter.php`
- `web/app/Models/RegistrationAgency.php`
- `web/app/Models/Role.php`
- `web/app/Models/SaadActivity.php`
- `web/app/Models/SexCategory.php`
- `web/app/Models/Training.php`
- `web/app/Models/TrainingParticipant.php`
- `web/app/Models/User.php`
- `web/app/Models/UserInvitation.php`
- `web/app/Models/UserProfile.php`
- `web/app/Providers/AppServiceProvider.php`
- `web/app/Providers/GisServiceProvider.php`
- `web/app/Services/AreaService.php`
- `web/app/Services/AssociationService.php`
- `web/app/Services/AuditLogService.php`
- `web/app/Services/BarangayService.php`
- `web/app/Services/GisAddressResolver.php`
- `web/app/Services/GisFeatureSerializer.php`
- `web/app/Services/GisGeometry.php`
- `web/app/Services/GisLocationService.php`
- `web/app/Services/GisQueryService.php`
- `web/app/Services/InvitationActivationContext.php`
- `web/app/Services/InvitationActivationService.php`
- `web/app/Services/MemberApplicationService.php`
- `web/app/Services/MemberService.php`
- `web/app/Services/MonitoringService.php`
- `web/app/Services/MunicipalityService.php`
- `web/app/Services/ProgramActivityService.php`
- `web/app/Services/ProjectService.php`
- `web/app/Services/SupabaseAuthService.php`
- `web/app/Services/TrainingService.php`
- `web/app/Services/UserInvitationService.php`
- `web/routes/api.php`
- `web/routes/console.php`
- `web/routes/web.php`
- `web/tests/TestCase.php`
- `web/tests/Feature/ExampleTest.php`
- `web/tests/Feature/GisScopedReadsTest.php`
- `web/tests/Feature/InvitationActivationServiceTest.php`
- `web/tests/Feature/SupabaseInvitationAuthTest.php`
- `web/tests/Unit/ExampleTest.php`
- `web/tests/Unit/GisFeatureSerializerTest.php`
- `web/tests/Unit/GisGeometryTest.php`
- `web/database/migrations/0001_01_01_000000_create_users_table.php`
- `web/database/migrations/0001_01_01_000001_create_cache_table.php`
- `web/database/migrations/0001_01_01_000002_create_jobs_table.php`
- `web/database/migrations/2026_09_28_091014_create_personal_access_tokens_table.php`
- `web/database/migrations/2026_10_05_102947_update_program_activity_status_constraint.php`

Frontend/resource inventory:

- `web/resources/css/base/foundation.css`
- `web/resources/css/components/controls.css`
- `web/resources/css/components/forms.css`
- `web/resources/css/components/tables.css`
- `web/resources/css/layouts/admin-footer.css`
- `web/resources/css/layouts/admin-responsive.css`
- `web/resources/css/layouts/admin-shell.css`
- `web/resources/css/pages/admin-audit-logs.css`
- `web/resources/css/pages/admin-dashboard.css`
- `web/resources/css/pages/admin-gis.css`
- `web/resources/css/pages/admin-reports.css`
- `web/resources/css/pages/gis-workspace.css`
- `web/resources/css/pages/public-gis.css`
- `web/resources/css/pages/public-landing.css`
- `web/resources/css/pages/staff-login.css`
- `web/resources/css/app.css`
- `web/resources/js/core/dialogs.js`
- `web/resources/js/core/gis-api.js`
- `web/resources/js/core/helpers.js`
- `web/resources/js/layouts/admin-navigation.js`
- `web/resources/js/layouts/public-navigation.js`
- `web/resources/js/pages/admin/forms.js`
- `web/resources/js/pages/admin/reports.js`
- `web/resources/js/pages/gis-editor.js`
- `web/resources/js/pages/gis-map.js`
- `web/resources/js/pages/gis-workspace.js`
- `web/resources/js/pages/staff-login.js`
- `web/resources/js/app.js`
- `web/resources/ui/admin.php`
- `web/resources/views/admin/areas/index.blade.php`
- `web/resources/views/admin/areas/modals.blade.php`
- `web/resources/views/admin/associations/create.blade.php`
- `web/resources/views/admin/associations/edit.blade.php`
- `web/resources/views/admin/associations/index.blade.php`
- `web/resources/views/admin/associations/show.blade.php`
- `web/resources/views/admin/audit-logs/error.blade.php`
- `web/resources/views/admin/audit-logs/index.blade.php`
- `web/resources/views/admin/audit-logs/show.blade.php`
- `web/resources/views/admin/gis/index.blade.php`
- `web/resources/views/admin/members/index.blade.php`
- `web/resources/views/admin/members/show.blade.php`
- `web/resources/views/admin/monitoring/create.blade.php`
- `web/resources/views/admin/monitoring/edit.blade.php`
- `web/resources/views/admin/monitoring/index.blade.php`
- `web/resources/views/admin/monitoring/show.blade.php`
- `web/resources/views/admin/program-activities/create.blade.php`
- `web/resources/views/admin/program-activities/edit.blade.php`
- `web/resources/views/admin/program-activities/index.blade.php`
- `web/resources/views/admin/program-activities/show.blade.php`
- `web/resources/views/admin/projects/create.blade.php`
- `web/resources/views/admin/projects/edit.blade.php`
- `web/resources/views/admin/projects/index.blade.php`
- `web/resources/views/admin/projects/show.blade.php`
- `web/resources/views/admin/reports/index.blade.php`
- `web/resources/views/admin/shared/form.blade.php`
- `web/resources/views/admin/shared/index.blade.php`
- `web/resources/views/admin/shared/repeater-row.blade.php`
- `web/resources/views/admin/shared/show.blade.php`
- `web/resources/views/admin/trainings/create.blade.php`
- `web/resources/views/admin/trainings/edit.blade.php`
- `web/resources/views/admin/trainings/index.blade.php`
- `web/resources/views/admin/trainings/participants.blade.php`
- `web/resources/views/admin/trainings/show.blade.php`
- `web/resources/views/admin/users/create.blade.php`
- `web/resources/views/admin/users/index.blade.php`
- `web/resources/views/admin/users/show.blade.php`
- `web/resources/views/admin/dashboard.blade.php`
- `web/resources/views/association/member-applications/index.blade.php`
- `web/resources/views/auth/invitation-layout.blade.php`
- `web/resources/views/auth/invitation-password.blade.php`
- `web/resources/views/auth/invitation-recovery.blade.php`
- `web/resources/views/auth/invitation-result.blade.php`
- `web/resources/views/auth/invitation-verify.blade.php`
- `web/resources/views/auth/login.blade.php`
- `web/resources/views/components/confirmation-modal.blade.php`
- `web/resources/views/components/data-table.blade.php`
- `web/resources/views/components/empty-state.blade.php`
- `web/resources/views/components/field.blade.php`
- `web/resources/views/components/filter-panel.blade.php`
- `web/resources/views/components/form-section.blade.php`
- `web/resources/views/components/icon.blade.php`
- `web/resources/views/components/modal.blade.php`
- `web/resources/views/components/pagination.blade.php`
- `web/resources/views/components/public-feature-card.blade.php`
- `web/resources/views/components/search-box.blade.php`
- `web/resources/views/components/sort-dropdown.blade.php`
- `web/resources/views/components/stat-card.blade.php`
- `web/resources/views/components/status-badge.blade.php`
- `web/resources/views/dashboard/index.blade.php`
- `web/resources/views/field-officer/member-applications/index.blade.php`
- `web/resources/views/field-officer/monitoring/index.blade.php`
- `web/resources/views/field-officer/program-activities/index.blade.php`
- `web/resources/views/field-officer/projects/index.blade.php`
- `web/resources/views/field-officer/trainings/index.blade.php`
- `web/resources/views/layouts/admin.blade.php`
- `web/resources/views/layouts/association.blade.php`
- `web/resources/views/layouts/field-officer.blade.php`
- `web/resources/views/layouts/public.blade.php`
- `web/resources/views/monitoring/partials/context.blade.php`
- `web/resources/views/monitoring/partials/details.blade.php`
- `web/resources/views/monitoring/partials/editor-script.blade.php`
- `web/resources/views/monitoring/partials/editor-templates.blade.php`
- `web/resources/views/monitoring/partials/project-shortcuts.blade.php`
- `web/resources/views/monitoring/partials/removal-modal.blade.php`
- `web/resources/views/monitoring/partials/styles.blade.php`
- `web/resources/views/monitoring/dashboard.blade.php`
- `web/resources/views/monitoring/edit-overview.blade.php`
- `web/resources/views/monitoring/editor.blade.php`
- `web/resources/views/monitoring/visit.blade.php`
- `web/resources/views/monitoring/visits.blade.php`
- `web/resources/views/monitoring/workspace.blade.php`
- `web/resources/views/partials/admin-sidebar.blade.php`
- `web/resources/views/partials/admin-topbar.blade.php`
- `web/resources/views/partials/gis-workspace.blade.php`
- `web/resources/views/partials/page-header.blade.php`
- `web/resources/views/partials/public-footer.blade.php`
- `web/resources/views/partials/public-navbar.blade.php`
- `web/resources/views/projects/partials/location.blade.php`
- `web/resources/views/public/sections/about.blade.php`
- `web/resources/views/public/sections/access.blade.php`
- `web/resources/views/public/sections/features.blade.php`
- `web/resources/views/public/sections/gis-preview.blade.php`
- `web/resources/views/public/sections/hero.blade.php`
- `web/resources/views/public/gis.blade.php`
- `web/resources/views/public/landing.blade.php`
- `web/resources/views/gis-editor.blade.php`
- `web/resources/views/gis-unavailable.blade.php`
- `web/resources/views/gis-workspace.blade.php`

Additional entry/configuration/source definitions:

- web/public/index.php; web/artisan; web/bootstrap/app.php; web/bootstrap/providers.php.
- web/config PHP files are enumerated in the authoritative configuration inventory below.
- web/composer.json; web/composer.lock; web/package.json; web/package-lock.json; web/vite.config.js; web/phpunit.xml; web/README.md.
- supabase/migrations/001_assocmap_schema.sql; 002_add_locality_type_to_municipalities.sql; 003_add_monitoring_visit_reports.sql; 004_backfill_monitoring_visit_reports.sql; 005_add_monitoring_monthly_production_and_conductors.sql; 006_create_user_invitations.sql; 007_secure_laravel_infrastructure.sql.
- mobile/lib/main.dart; mobile/pubspec.yaml; mobile/test/widget_test.dart; mobile/README.md. Flutter location was discovered within the project, not assumed.
- docs/production-readiness/01–10 Markdown reports, including local dictionary and technical/evidence/test mappings. No approved manuscript/questionnaire was present in inspected documentation.

Actual PHP configuration inventory:

- `web/config/app.php`
- `web/config/auth.php`
- `web/config/cache.php`
- `web/config/database.php`
- `web/config/filesystems.php`
- `web/config/logging.php`
- `web/config/mail.php`
- `web/config/queue.php`
- `web/config/sanctum.php`
- `web/config/services.php`
- `web/config/session.php`

