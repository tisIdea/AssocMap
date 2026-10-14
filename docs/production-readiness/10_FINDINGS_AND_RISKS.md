# Findings and Risks Register

Audit date: **2026-10-13 (Asia/Manila)**. Project: original AssocMap. Phase A was read-only. No PHP/JavaScript execution, builds, tests, SQL, migrations, Git, Supabase calls, browser or deployment activity occurred.

## Evidence and severity rules

"Confirmed" means directly visible in source, not reproduced at runtime. "Potential risk" requires live verification before claiming exploitability. Prior user-reported successful Vite/PHP/route checks apply to an earlier GIS iteration; no current run artifact or revision identity was supplied. Runtime results remain **Pending Verification**. No critical exploitable vulnerability was independently confirmed. F-001 retains the user's reported severity without inventing package names/advisories.

## Prioritized register

| ID | Severity | Finding | Evidence class | Disposition |
|---|---|---|---|---|
| F-001 | Critical (user-reported; unverified) | Unresolved dependency-security report | Potential risk; advisory evidence unavailable | Pending verified advisory report |
| F-002 | High | Audit producers missing outside GIS | Confirmed missing source coverage | Proposed; approval required |
| F-003 | High | Database append-only audit protection is not established | Potential risk; live permissions unverified | Pending live evidence; database change requires approval |
| F-004 | High | Production acceptance evidence is missing | Confirmed evidence gap | Not Executed |
| F-005 | High | Member decisions lack an atomic pending-state transition | Confirmed source guard gap; race outcome not reproduced | Proposed; approval required |
| F-006 | High | Invitation backend is not connected to its Administrator form | Confirmed integration gap | Proposed; approval required |
| F-007 | High | Reports and Analytics generation/export is absent | Confirmed missing feature | Proposed; approval required |
| F-008 | High | Effective production configuration and hosting are unverified | Potential deployment risk | Pending Verification |
| F-009 | High | Live schema and two migration tracks need reconciliation | Potential risk; source divergence confirmed | Pending live schema evidence; changes require approval |
| F-010 | Medium | Malformed association acronym can fail before validation | Confirmed source defect; runtime not executed | Proposed; approval required |
| F-011 | Medium | Ordinary Auth HTTP failures lack the activation path's handling | Confirmed source difference; impact Pending Verification | Proposed; approval required |
| F-012 | Medium | Versioned profile API has no application throttle | Confirmed source absence; infrastructure protection unknown | Proposed; approval required |
| F-013 | Medium | Organizational lists and project location summaries are unbounded | Confirmed query design; latency/memory impact unmeasured | Pending measurement; implementation requires approval |
| F-014 | Medium | Logout/refresh lifecycle is local and limited | Confirmed source behavior; policy risk | Policy approval and runtime verification required |
| F-015 | Medium | Training prevalidation is weaker than its SQL participant rule | Confirmed source/schema rule mismatch | Proposed; approval required |
| F-016 | Medium | Dashboard totals are hardcoded | Confirmed placeholder | Proposed; approval required |
| F-017 | Medium | Mobile workflows and organizational APIs are not implemented | Confirmed scope gap | Not implemented beyond starter/profile endpoint |
| F-018 | Low | Unused MemberClassification import | Confirmed unused symbol | Implemented; source-only verification |
| F-019 | Informational | Legacy association points are intentionally hidden | Confirmed intentional compatibility boundary | Intentional; separate approval for cleanup |
| F-020 | Informational | Approved research foundations and literature evidence unavailable | Evidence limitation | Pending authoritative material |
| F-021 | Medium | Sanitized operational errors lack diagnostic detail | Confirmed observability limitation | Proposed; approval required |
| F-022 | Informational | Read eligibility differs between GIS and Monitoring | Confirmed policy difference; no cross-association bypass established | Policy clarification required |

## Detailed findings

### F-001 — Unresolved dependency-security report

- **Severity:** Critical (user-reported; unverified).
- **Classification:** Potential risk; advisory evidence unavailable.
- **Affected files/functions:** web/package.json; web/package-lock.json.
- **Evidence:** The user reported two critical npm findings; names, advisory IDs and a current audit artifact are unavailable. Versions identify dependencies, not severity.
- **Business impact:** Unknown dependency exploitability prevents security release sign-off.
- **Smallest recommended correction:** Run npm audit --json manually; record advisory IDs, dependency paths, affected versions and applicability; propose a targeted update before changing the lockfile.
- **Side effects:** Updates can change bundles, Node requirements and GIS behavior.
- **Testing required:** DEP-01; GIS-01; GIS-04 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Pending verified advisory report. No runtime proof claimed.

### F-002 — Audit producers missing outside GIS

- **Severity:** High.
- **Classification:** Confirmed missing source coverage.
- **Affected files/functions:** web/app/Services/{UserInvitationService,InvitationActivationService,AssociationService,ProjectService,TrainingService,ProgramActivityService,MemberApplicationService,MonitoringService}.php; AuditLogService::record.
- **Evidence:** Producer search finds GIS inserts but no organizational/invitation/activation calls to AuditLogService::record; normal Log calls are not audit events.
- **Business impact:** Account and organizational changes cannot be comprehensively reconstructed in the audit viewer.
- **Smallest recommended correction:** Approve an event/field catalogue; add minimal trusted-actor events inside each local business transaction. External provider stages need outcome-specific events rather than a fictional distributed transaction.
- **Side effects:** Required audit inserts may roll back workflows; snapshot retention and viewer allowlists need review.
- **Testing required:** AUD-01; AUD-02; INV-01; ORG-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Proposed; approval required. No runtime proof claimed.

### F-003 — Database append-only audit protection is not established

- **Severity:** High.
- **Classification:** Potential risk; live permissions unverified.
- **Affected files/functions:** web/app/Models/AuditLog.php::booted; supabase/migrations/001_assocmap_schema.sql::audit_logs; web/app/Services/GisLocationService.php::store.
- **Evidence:** Model callbacks deny instance edits/deletes; query-builder operations bypass model events. No audit immutability trigger or application-role grant contract is defined in the reviewed migrations. GIS replay protection uses retained DELETE GIS events.
- **Business impact:** Privileged database changes or audit pruning could compromise history and deletion replay protection.
- **Smallest recommended correction:** Review actual table ownership/grants; retain deletion events; propose least-privilege SELECT/INSERT access and an approved retention/identity-deletion policy.
- **Side effects:** Blanket update prevention can conflict with actor FK ON DELETE SET NULL; strict grants must allow required audit inserts.
- **Testing required:** DB-01; AUD-02; GIS-05 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Pending live evidence; database change requires approval. No runtime proof claimed.

### F-004 — Production acceptance evidence is missing

- **Severity:** High.
- **Classification:** Confirmed evidence gap.
- **Affected files/functions:** web/tests; web/phpunit.xml; mobile/test/widget_test.dart.
- **Evidence:** Test source exists, mostly isolated SQLite/mocked provider logic. No current run artifacts, PostgreSQL/PostGIS workflow tests, browser/UAT evidence or restore drill were supplied.
- **Business impact:** Static safeguards do not prove correct deployment or functional behavior.
- **Smallest recommended correction:** Execute the staged test plan against approved nonproduction fixtures; retain redacted evidence tied to the tested source snapshot.
- **Side effects:** Testing must avoid production data and real provisioning except an explicitly approved sandbox exercise.
- **Testing required:** All test IDs (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Not Executed. No runtime proof claimed.

### F-005 — Member decisions lack an atomic pending-state transition

- **Severity:** High.
- **Classification:** Confirmed source guard gap; race outcome not reproduced.
- **Affected files/functions:** web/app/Services/MemberApplicationService.php::{approve,reject}.
- **Evidence:** approve checks the supplied model's pending status before its transaction; reject checks then saves without a row lock/atomic status predicate. Existing reviewer triggers validate reviewer identity, not terminal-state transitions.
- **Business impact:** Concurrent approve/reject requests could leave a rejected application with an approved member; duplicate approval can reach a DB uniqueness failure.
- **Smallest recommended correction:** Proposed patch: reload/lock the application inside a transaction for both decisions, re-check pending/current reviewer, and preserve member insertion and classification writes in that transaction.
- **Side effects:** Lock ordering, conflict response and repeat-request semantics must be agreed; do not bypass DB constraints.
- **Testing required:** MEM-01; MEM-02 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Proposed; approval required. No runtime proof claimed.

### F-006 — Invitation backend is not connected to its Administrator form

- **Severity:** High.
- **Classification:** Confirmed integration gap.
- **Affected files/functions:** web/routes/web.php::admin.user-invitations.store; web/resources/views/admin/users/{index,create}.blade.php; web/resources/views/admin/shared/form.blade.php; web/resources/js/pages/admin/forms.js.
- **Evidence:** The authorized POST endpoint exists; the rendered generic user form has no submitting action, onsubmit=false and a disabled Invite User button. General form JS prevents submission.
- **Business impact:** Administrators lack an integrated browser provisioning workflow; backend implementation is not equivalent to usable Stage 1/2 UI.
- **Smallest recommended correction:** Approve a dedicated frontend adapter to the existing invitation API and its uncertain/reconciliation states; preserve the activation backend.
- **Side effects:** Changes touch User Management contracts and require provider/end-to-end tests.
- **Testing required:** INV-01; INV-02; AUTH-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Proposed; approval required. No runtime proof claimed.

### F-007 — Reports and Analytics generation/export is absent

- **Severity:** High.
- **Classification:** Confirmed missing feature.
- **Affected files/functions:** web/resources/views/admin/reports/index.blade.php; web/resources/js/pages/admin/reports.js; web/routes/web.php; web/resources/ui/admin.php.
- **Evidence:** Report controls only toggle visibility; Generate Report is disabled; no report aggregation/export controller or route is defined. No Chart.js dependency is declared.
- **Business impact:** Required summaries and PDF/XLSX exports cannot be produced by this module.
- **Smallest recommended correction:** Approve report definitions, formulas, role/association scope and export field allowlists before incremental implementation.
- **Side effects:** New endpoints and aggregation semantics are consequential; exports require disclosure/size controls.
- **Testing required:** REP-01; REP-02 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Proposed; approval required. No runtime proof claimed.

### F-008 — Effective production configuration and hosting are unverified

- **Severity:** High.
- **Classification:** Potential deployment risk.
- **Affected files/functions:** web/config/{app,session,database,logging}.php; web/bootstrap/app.php; web/composer.json.
- **Evidence:** Config defaults are not effective environment evidence. Secure cookie is env-dependent, session encryption defaults false, database TLS defaults prefer, proxy trust is loopback-only. The user describes Quick Tunnel access as temporary.
- **Business impact:** Incorrect TLS/proxy/session/debug/document-root settings can expose data or disrupt access.
- **Smallest recommended correction:** Complete the deployment checklist; validate HTTPS, debug off, session protection, certificate validation, least privilege, persistent locks, controlled logs, secrets and public-only document root.
- **Side effects:** Environment/proxy changes affect cookies, URL generation, throttling and activation; require separate approval.
- **Testing required:** OPS-01; OPS-02; AUTH-02; DB-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Pending Verification. No runtime proof claimed.

### F-009 — Live schema and two migration tracks need reconciliation

- **Severity:** High.
- **Classification:** Potential risk; source divergence confirmed.
- **Affected files/functions:** supabase/migrations/001-007; web/database/migrations/2026_10_05_102947_update_program_activity_status_constraint.php; UserProfile::canAccessApplication.
- **Evidence:** Business schema is maintained by SQL scripts while Laravel infrastructure/status changes use Laravel migrations. SQL baseline program-activity status omits ongoing; a Laravel migration adds it. Access checks also depend on user_invitations even for legacy profiles.
- **Business impact:** Missing migration application can reject valid operations or deny all protected logins; replays can fail or damage assumptions.
- **Smallest recommended correction:** Compare deployed definitions and applied histories manually; document ordered prerequisites and reviewed one-time migration/backup plan. Do not run composer setup as production provisioning.
- **Side effects:** Migration 003/005 are guarded against replay; 004 is data backfill; 007 requires infrastructure tables. No automatic replay or grant changes.
- **Testing required:** DB-01; ORG-01; AUTH-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Pending live schema evidence; changes require approval. No runtime proof claimed.

### F-010 — Malformed association acronym can fail before validation

- **Severity:** Medium.
- **Classification:** Confirmed source defect; runtime not executed.
- **Affected files/functions:** web/app/Http/Requests/StoreAssociationRequest.php::prepareForValidation.
- **Evidence:** filled(acronym) then trim(acronym) runs before the string rule; a nonempty array is not a string and can cause a PHP TypeError.
- **Business impact:** A crafted or malformed request can return a server error instead of controlled validation.
- **Smallest recommended correction:** Proposed patch: trim only strings and leave other shapes intact for validation to reject; add malformed-shape tests.
- **Side effects:** This changes the error path/validation behavior and is outside the permitted cosmetic Phase B cleanup.
- **Testing required:** ORG-02 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Proposed; approval required. No runtime proof claimed.

### F-011 — Ordinary Auth HTTP failures lack the activation path's handling

- **Severity:** Medium.
- **Classification:** Confirmed source difference; impact Pending Verification.
- **Affected files/functions:** web/app/Services/SupabaseAuthService.php::{signIn,getUser,adminRequest,activationRequest}; EnsureSupabaseAuthenticated::handle.
- **Evidence:** signIn/getUser do not set their own connect/total timeouts, disable redirects, or catch ConnectionException; admin/activation requests do. getUser is used on every protected request.
- **Business impact:** Provider/network failures may escape as 500/503 responses; transient outages and expiry are not clearly distinguished.
- **Smallest recommended correction:** Approve bounded provider requests and controlled failure handling without treating outages as successful authentication or silently extending sessions.
- **Side effects:** Authentication/session behavior must remain fail-closed; changes require targeted tests.
- **Testing required:** AUTH-03; PERF-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Proposed; approval required. No runtime proof claimed.

### F-012 — Versioned profile API has no application throttle

- **Severity:** Medium.
- **Classification:** Confirmed source absence; infrastructure protection unknown.
- **Affected files/functions:** web/routes/api.php::v1/me; web/bootstrap/app.php; vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php::apiLimiter.
- **Evidence:** /v1/me has only supabase.api.auth; bootstrap does not configure throttleApi; inspected framework apiLimiter is unset by default.
- **Business impact:** Repeated bearer requests can amplify remote Auth checks and worker utilization.
- **Smallest recommended correction:** Approve a named API limiter and deployment edge limits; choose identity/IP rules from expected client usage.
- **Side effects:** Limits can affect mobile users behind shared networks; test 429 responses and Retry-After.
- **Testing required:** API-01; API-02; PERF-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Proposed; approval required. No runtime proof claimed.

### F-013 — Organizational lists and project location summaries are unbounded

- **Severity:** Medium.
- **Classification:** Confirmed query design; latency/memory impact unmeasured.
- **Affected files/functions:** web/app/Http/Controllers/Admin/{AssociationController,ProjectController,MemberController,MunicipalityController}.php::index; FieldOfficer/ProjectController::index; MonitoringWorkspaceController::index; GisQueryService::projectLocations.
- **Evidence:** These paths use get(); project summaries fetch all eligible addresses for the loaded projects; monitoring text search filters records in memory. GIS map/list and audit viewer are bounded separately.
- **Business impact:** Dataset growth can increase database work, payload size, memory and UI rendering cost.
- **Smallest recommended correction:** Measure row counts/payloads/SQL plans before approving server pagination or endpoint changes; preserve complete multiple-site presentation.
- **Side effects:** Pagination/filter contracts and modal loading behavior require deliberate changes; do not add speculative indexes.
- **Testing required:** PERF-01; PERF-02 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Pending measurement; implementation requires approval. No runtime proof claimed.

### F-014 — Logout/refresh lifecycle is local and limited

- **Severity:** Medium.
- **Classification:** Confirmed source behavior; policy risk.
- **Affected files/functions:** web/app/Http/Controllers/Auth/StaffAuthController.php::{store,destroy}; SupabaseAuthService::getUser.
- **Evidence:** Refresh token is stored but no refresh flow is implemented; logout invalidates Laravel session only and does not call provider revocation.
- **Business impact:** Users may be forced to sign in at provider expiry; a separately retained bearer token may remain valid after web logout.
- **Smallest recommended correction:** Define local-versus-provider/global logout and refresh policy; implement only after approval.
- **Side effects:** Provider revocation can sign out other clients; refresh introduces token rotation/concurrency obligations.
- **Testing required:** AUTH-01; AUTH-04; API-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Policy approval and runtime verification required. No runtime proof claimed.

### F-015 — Training prevalidation is weaker than its SQL participant rule

- **Severity:** Medium.
- **Classification:** Confirmed source/schema rule mismatch.
- **Affected files/functions:** web/app/Services/TrainingService.php::validateParticipants; supabase/migrations/001_assocmap_schema.sql::validate_training_participant_association.
- **Evidence:** Service checks association and archived_at, but not member_status=active. The SQL trigger requires both active status and unarchived membership.
- **Business impact:** Inactive participants can reach a database exception instead of a useful validation response; missing live trigger would weaken eligibility.
- **Smallest recommended correction:** Proposed patch: align the service predicate with the existing SQL rule and validate the real trigger is deployed.
- **Side effects:** Changes rejection behavior; preserve same-association checks and transaction rollback.
- **Testing required:** TRN-01; DB-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Proposed; approval required. No runtime proof claimed.

### F-016 — Dashboard totals are hardcoded

- **Severity:** Medium.
- **Classification:** Confirmed placeholder.
- **Affected files/functions:** web/routes/web.php::admin.dashboard; web/resources/views/admin/dashboard.blade.php.
- **Evidence:** Route supplies zero counts rather than database totals; other organizational screens query real records.
- **Business impact:** Users may mistake placeholders for actual zero program activity.
- **Smallest recommended correction:** Label unavailable statistics or approve scoped real aggregations; do not describe these as functioning analytics.
- **Side effects:** Aggregation rules and statuses need approval; no invented counts.
- **Testing required:** REP-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Proposed; approval required. No runtime proof claimed.

### F-017 — Mobile workflows and organizational APIs are not implemented

- **Severity:** Medium.
- **Classification:** Confirmed scope gap.
- **Affected files/functions:** mobile/lib/main.dart; mobile/pubspec.yaml; mobile/test/widget_test.dart; web/routes/api.php.
- **Evidence:** Flutter contains the counter starter application; only profile API and scaffold Sanctum endpoint are present.
- **Business impact:** Capstone/mobile requirements remain unmet; web readiness cannot establish Android readiness.
- **Smallest recommended correction:** Confirm release scope; plan authenticated APIs, role scope, secure token storage and actual Flutter features incrementally.
- **Side effects:** Requires new contracts and mobile-specific security/testing; not a cleanup task.
- **Testing required:** MOB-01; API-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Not implemented beyond starter/profile endpoint. No runtime proof claimed.

### F-018 — Unused MemberClassification import

- **Severity:** Low.
- **Classification:** Confirmed unused symbol.
- **Affected files/functions:** web/app/Services/MemberApplicationService.php::imports.
- **Evidence:** Before cleanup, whole-file occurrence review found MemberClassification only in its import; classification writes use model relationships. Read-back confirmed the import was removed.
- **Business impact:** Minor maintainability noise; no runtime business effect.
- **Smallest recommended correction:** Remove only that import during Phase B.
- **Side effects:** No intended behavior or interface change; PHP lint still pending.
- **Testing required:** TECH-01; MEM-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Implemented: removed the single unused import. Source-only verification; no runtime proof claimed.

### F-019 — Legacy association points are intentionally hidden

- **Severity:** Informational.
- **Classification:** Confirmed intentional compatibility boundary.
- **Affected files/functions:** web/app/Models/GisLocation.php::TYPES; GisQueryService::scoped; GisLocationService::lockParent; SQL gis_locations type check.
- **Evidence:** Active application types are project_point/project_boundary; physical SQL retains association_point and historical rows. Normal lifecycle paths reject legacy type.
- **Business impact:** Existing records cannot be managed in ordinary GIS UI; public map hides them even if their stored flag remains published.
- **Smallest recommended correction:** Plan a separately approved historical review/cleanup interface or administrative procedure; preserve history and actual foreign-key checks.
- **Side effects:** Cleanup is consequential/destructive and must not invent project parents or geometry.
- **Testing required:** GIS-06 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Intentional; separate approval for cleanup. No runtime proof claimed.

### F-020 — Approved research foundations and literature evidence unavailable

- **Severity:** Informational.
- **Classification:** Evidence limitation.
- **Affected files/functions:** Project root and reviewed source/documentation locations; user-provided Capstone 2 outline.
- **Evidence:** No approved Capstone 1 manuscript, study instruments, research schedule, hardware measurements or academic reference list was supplied/found in inspected locations.
- **Business impact:** Code cannot establish research rationale, results, methodology history or academic citations.
- **Smallest recommended correction:** Supply approved manuscript/reference material; use technical documentation as implementation evidence only.
- **Side effects:** Do not replace approved objectives or invent Agile/BMC/Gantt/UAT conclusions.
- **Testing required:** DOC-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Pending authoritative material. No runtime proof claimed.

### F-021 — Sanitized operational errors lack diagnostic detail

- **Severity:** Medium.
- **Classification:** Confirmed observability limitation.
- **Affected files/functions:** web/app/Providers/GisServiceProvider.php::reportable; web/bootstrap/app.php::invitation exception handling; UserProfile::canAccessApplication.
- **Evidence:** GIS logs a generic code and stops default reporting; invitation logs are similarly sanitized; invitation-table lookup failure denies access without a specific operational trace.
- **Business impact:** Safe user-facing errors can be difficult to diagnose without per-request timing/correlation and alerting.
- **Smallest recommended correction:** Approve sanitized request IDs, SQLSTATE/failure-category telemetry and Auth/DB spans; never log tokens, password bodies or SQL bindings containing personal data.
- **Side effects:** Logging changes need retention/privacy review; preserve existing redaction and generic public errors.
- **Testing required:** OPS-02; PERF-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Proposed; approval required. No runtime proof claimed.

### F-022 — Read eligibility differs between GIS and Monitoring

- **Severity:** Informational.
- **Classification:** Confirmed policy difference; no cross-association bypass established.
- **Affected files/functions:** web/app/Services/GisQueryService.php::scoped; web/app/Http/Controllers/MonitoringWorkspaceController.php::projectsFor.
- **Evidence:** GIS requires active/unarchived association and project parents. Association Account Monitoring reads are constrained to the active link but do not apply the same parent lifecycle predicate.
- **Business impact:** Archived/inactive-parent history can appear differently across modules; this may be intentional organizational history access.
- **Smallest recommended correction:** Compare approved business rules before harmonizing; do not widen or narrow access solely for UI consistency.
- **Side effects:** Any change alters permitted historical visibility and needs approval.
- **Testing required:** MON-01; GIS-02; DOC-01 (see [testing plan](07_TESTING_PLAN.md)).
- **Status:** Policy clarification required. No runtime proof claimed.

## Release gates and approvals

Resolve verified dependency advisories, validate effective deployment/RLS/schema settings, execute core PostgreSQL/PostGIS/Auth and UAT tests, establish audit coverage/retention, and agree delivered module scope before production sign-off. Reports/mobile/user-admin UI gaps must be explicitly accepted as excluded scope or completed, not described as operational.

F-005, F-006, F-007, F-010, F-011, F-012, F-014, F-015 and F-016 describe concrete proposed corrections. No consequential patch was implemented. Database privileges, schema/migration changes, security headers/proxy/cookie changes and production configuration require separate approval. F-018 permits only removal of the demonstrably unused import. No workflows, authentication, User Management, GIS or Audit Logging Stage A contracts are changed by that cleanup.

## Coverage limits

This is a static, targeted source audit with a complete enumerated application inventory and reviewed critical handlers. It is not a penetration test, SAST scan, dependency advisory scan, live permission audit, production assessment or proof of absence of vulnerabilities. Generated builds, credentials, environment files, logs, private records and teammate projects were not inspected. Findings may change after manual evidence arrives.

