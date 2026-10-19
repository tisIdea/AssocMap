# Requirements-to-Implementation-to-Testing Traceability

Audit date: 2026-10-13. IDs here are **working technical reference IDs**, not invented official Capstone 1 requirement identifiers. Requirement authority is explicitly stated. Obtain the approved manuscript/requirements before treating this matrix as the final study traceability record.

All technical/functional verification is Pending Verification / Not Executed. Source presence is distinct from passing tests, deployed schema and production approval. Historical user-reported earlier GIS checks have no current-source run artifact in this package.

## Traceability matrix

| Requirement ID | Capability | Requirement authority | Exact implementation evidence | Source status | Test IDs | Findings / missing evidence | Capstone 2 section |
|---|---|---|---|---|---|---|---|
| R-AUTH-01 | Supported roles and active authenticated access | Current task role list; source access gates | StaffAuthController; EnsureSupabaseAuthenticated/ApiAuthenticated; EnsureRole; UserProfile::canAccessApplication | Implemented in source | AUTH-01; AUTH-02; API-01 | F-004/F-008/F-011/F-014 | III Use Cases / Implementation / Testing |
| R-AUTH-02 | Secure invitation provisioning | Current task/context and SQL 006; no approved Capstone 1 ID supplied | UserInvitationService::initiate; SupabaseAuthService::inviteUser; StoreUserInvitationRequest; admin.user-invitations.store | Backend implemented; Administrator form disconnected | INV-01; INV-02 | F-006/F-009 | III Program Specification / Integration Testing |
| R-AUTH-03 | Invitation verification/password/activation/recovery | Current task/context; implemented activation contracts | InvitationActivationController; InvitationActivationContext; InvitationActivationService; ProtectInvitationActivation; activation views | Implemented in source; mocked/SQLite tests only | INV-01; INV-02; AUTH-02 | F-004/F-008/F-009 | III Implementation / Testing / User-Guide |
| R-ORG-01 | Area hierarchy and association assignment | Current task module list; physical Area/Association schema | Municipality/Barangay/Area services/controllers; AssociationService; SQL 001/002 | Implemented; city creation workflow incomplete/unclear | ORG-01; DB-01 | F-009/F-013 | III ERD / Data Dictionary / Modules |
| R-ORG-02 | Authoritative association registrations | Prior explicit user registration task; local SQL association_registrations | Association::registrations; AssociationRegistration; RegistrationAgency; AssociationService::recordRegistration; admin association modal | Optional single initial entry and multi-record viewing; no approval state/general history CRUD | ORG-01; ORG-02 | F-010; live schema/permissions pending | III ERD / Program Specification / Testing |
| R-MEM-01 | Linked-account submission and assigned-officer decisions | Source member workflow and current module context; approved research rule mapping pending | MemberApplicationService::{submit,approve,reject}; account/FO controllers; reviewer/submitter SQL triggers | Implemented in source; decision race guard gap | MEM-01; MEM-02 | F-005/F-002 | III Use Cases / Implementation / Testing |
| R-PRJ-01 | Project CRUD, materials/products and monitoring history preservation | Current module context and existing ProjectService rules | ProjectService; Admin/FO ProjectController; project/resources views | Implemented in source | PRJ-01; MON-02 | F-004/F-013/F-002 | III Modules / Program Specification |
| R-PRJ-02 | Project Location with distinct site and registered-area addresses | Prior explicit user location refinement task | GisQueryService::projectLocations; projects/partials/location.blade.php; Admin/FO project row mapping | Implemented for existing Admin/FO modals; no account Project Details page | PRJ-01; GIS-01 | Provenance unrecorded; page not implemented for account | III Storyboard / Program Specification |
| R-TRN-01 | Scoped training, related projects and active participants | Existing schema trigger + service; current module context | TrainingService; Admin/FO TrainingController; validate_training_participant_association | Implemented; service validation weaker than SQL status rule | TRN-01 | F-015/F-002/F-009 | III Modules / Database / Testing |
| R-ACT-01 | Scoped/program-wide organizational activities | Source ProgramActivityService rules; current context | ProgramActivityService; Admin/FO controllers; Laravel status constraint migration | Implemented; dual schema-track status reconciliation pending | ACT-01; DB-01 | F-009/F-002 | III Modules / Database / Testing |
| R-MON-01 | Quarterly monitoring, visits, monthly production, income and materials | Existing Monitoring source/schema; current context | MonitoringWorkspaceController; MonitoringService; SQL 001/003/004/005; monitoring views | Implemented in source; account-parent lifecycle policy differs from GIS | MON-01; MON-02 | F-004/F-009/F-013/F-022/F-002 | III ERD / Implementation / Testing |
| R-GIS-01 | Project-only GIS types and scoped reads | Prior explicit user project-only update | GisLocation::TYPES; GisQueryService::scoped; GisQueryRequest; maps/editor/filter/legend source | Implemented; legacy types retained physically but excluded | GIS-02; GIS-06 | F-019; PostGIS runtime pending | III GIS / Use Cases / Database |
| R-GIS-02 | Five GIS operations plus archive and geographic validation | Prior explicit user GIS operations requirement; current task | GisLocationService; GisGeometry; Save/Delete/Lifecycle requests; editor/workspace | Implemented in source; permanent deletion intentionally fails on real blockers | GIS-01; GIS-03; GIS-05 | F-004/F-003/F-009 | III Program Specification / Integration Testing |
| R-GIS-03 | Current selected project belongs to selected association | Prior explicit user parent/site task | SaveGisLocationRequest parent_association_id; GisLocationService::lockParent; project parent options | Implemented; transient validation field, no duplicate parent column | GIS-02; GIS-04 | Older API callers need current payload contract | III Program Specification / API |
| R-GIS-04 | Registered Area reference vs editable optional Site Address vs approved public fields | Prior explicit user address separation task | gis-editor.blade.php; gis-editor.js; GIS writer/serializer; GisAddressResolver | Implemented; legacy address values preserved, verification not recorded | GIS-01; GIS-03; GIS-04 | No automatic classification/backfill of legacy values | III Data Flow / Storyboard / Testing |
| R-GIS-05 | Async dropdown, fit bounds, empty/error states and toolbar order | Prior explicit user frontend refinements | gis-editor.js; gis-workspace.js; gis-api.js; partials/gis-workspace.blade.php; overview query | Implemented in source; user-reported ~3-second lookup requires timing attribution | GIS-04; PERF-01; PERF-02 | F-011/F-013/F-021 | III Performance / Alpha Testing / User-Guide |
| R-AUD-01 | Administrator audit viewer and transactional event history | Current task + prior Audit Stage A context | AuditLogController; AuditLogService; AuditLog; actual GIS producer; SQL audit_logs | Viewer/helper implemented; only GIS source producer confirmed | AUD-01; AUD-02 | F-002/F-003/F-004 | III Audit Architecture / Testing |
| R-REP-01 | Actual scoped reports/aggregations/exports/analytics | Current task intended Reports and Analytics module | reports view/JS; UI catalogue; absent report-generation handlers | Placeholder; not implemented backend | REP-01; REP-02 | F-007/F-016 | III Modules / Testing; IV Recommendations |
| R-MOB-01 | Organizational Android application and REST integration | User-provided intended mobile architecture; approved scope pending | mobile/main.dart/pubspec.yaml/widget_test; routes/api.php /v1/me | Starter Flutter app and profile endpoint only | MOB-01; API-01; API-02 | F-017/F-012 | III Modules / API / Roadmap |
| R-PROD-01 | Secure deployment, backups and operational reliability | Current production-readiness request | Config/bootstrap/manifests; deployment checklist; no actual hosting/restore evidence | Pending Verification; not production-ready | DEP-01; OPS-01; OPS-02; UAT-01 | F-001/F-003/F-004/F-008/F-009 | III Deployment / Testing |
| R-DOC-01 | Evidence-based manuscript aligned to approved foundation | User supplied Capstone 2 outline; Capstone 1 not available | Ten documentation files; claim register; source dictionary/inventory | Technical reference prepared; research foundations/citations/results pending | DOC-01 | F-020 | I / II / III / IV / Appendices |
| R-SAFE-01 | Audit first, low-risk cleanup only, no runtime/DB/deploy execution | Current task Part 9/10 explicit restrictions | Findings register written before Phase B; sole MemberApplicationService import removal; docs folder only | Complied in this audit; no executable verification undertaken | TECH-01; DOC-01 | Consequential fixes await separate approval | III Change Control / Appendix |

## Dependencies and unresolved scope

- Any authenticated browser/API use depends on Supabase user validation, user_profiles/roles and the invitation access-gate table; missing schema can fail existing-account access closed.
- Activation depends on real provider email/template/callback contracts, persistent sessions/cache/locks and approved association eligibility. A provider write is not rolled back by SQL; reconciliation/confirmed local recovery are intentional safeguards.
- GIS depends on physical PostGIS functions/types/constraints and runtime privileges; DELETE also checks actual incoming FKs/triggers and audit insertion/retention. Legacy association points are not part of current active GIS.
- Registration has no approval status or mandatory-registration requirement in local SQL. Do not map a nonexistent approval workflow to a passing test.
- Reports/mobile/full user administration must be marked incomplete or explicitly excluded by the approved release scope. Backend invitation/activation existence does not connect a disabled admin form.
- Association Account Project Details and FO/Association Account association detail pages are absent; do not infer capabilities from generic role descriptions.

## Claim-status vocabulary

| Status | Meaning | Evidence needed to advance |
|---|---|---|
| Implemented in source | Relevant route/handler/view/schema declaration exists | Current lint/build and targeted runtime checks |
| Static finding | Directly observed code/data-flow/constraint discrepancy | Reproduction or measured effect before exploit/performance claims |
| Technically checked | Actual syntax/platform/route/build execution completed with artifact | Record version, environment, commands and outputs |
| Functionally verified | Actual authorized/unauthorized/edge workflow executed | Redacted request/state/UI/test artifacts and expected-vs-actual results |
| Production-ready | Operational/security/schema/recovery/UAT gates approved | Responsible-owner sign-off and release evidence; never inferred from source |

## Evidence index / execution ledger

| Artifact | Current state | Required owner/action |
|---|---|---|
| Approved Capstone 1/requirement mapping | Unavailable in inspected locations | User/adviser supplies authorized source/version |
| Current source revision identifier | Not collected (Git prohibited) | User records identity using personally approved tooling |
| Current PHP/JS/routes/Blade/Vite results | Not Executed | User runs manual checklist and records artifacts |
| Named dependency advisory JSON | Unavailable; two critical findings user-reported | User runs verified audits; assess impact and approved remediation |
| Live schema-only/privilege reconciliation | Not collected; no SQL/API executed | User supplies nonconfidential schema/grant evidence |
| Real invitation delivery/activation flow | Pending | User uses approved sandbox identity/provider configuration |
| PostGIS mutation/public/rollback/concurrency results | Pending | User executes scoped staging plan |
| Broad audit producers/immutability | Source gaps / live rights unknown | Separate approved design/patch and tests |
| Network waterfall/Auth/DB timings | User reports ~3 seconds only; no current traces | User follows diagnostic protocol and supplies redacted measurements |
| Browser/device/accessibility/UAT | Pending | User/participants collect genuine consented evidence |
| Backup restore/production domain/runbook | Pending | Responsible operator validates approved deployment plan |

## Change-control ledger

Phase A: read-only inventory/audit; prioritized findings written first. Phase B: removed one unused MemberClassification import from web/app/Services/MemberApplicationService.php; no behavior/interface/role/auth/schema/GIS/audit changes. Documentation files created under docs/production-readiness only. No tests/builds/SQL/migrations/Git/Supabase/browser/deployment commands were executed. F-018 is source-only corrected; all meaningful fixes remain proposals needing approval.

When approved requirements conflict with source, record both and obtain a decision. Never silently rewrite research objectives, widen permissions, choose a hosting provider or fabricate evidence to make this matrix appear complete.

