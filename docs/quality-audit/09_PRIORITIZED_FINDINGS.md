# Prioritized Findings Register

Assessment date: **2026-10-13 (Asia/Manila)**. Read-only application audit; reports are the only files created. Source evidence is not runtime reproduction. No application/schema changes, test data, checks, builds, tests, SQL, provider calls, browser actions or deployment were performed.

## Severity and evidence

No **Critical** severe failure/exploit was independently confirmed. The earlier report labelled the user's two unknown npm findings Critical; this assessment treats the missing advisory evidence as **High release uncertainty (QA-008)**, not as proof of a critical package defect. No package/advisory names are inferred.

High findings include release gates and serious feature gaps as well as potential risks. Their classification identifies which. Estimated complexity is qualitative engineering judgment, not a promised duration. Prototype blockers are conditional on the approved demonstration scope, which has not been supplied. [Verification plan](10_MANUAL_VERIFICATION_PLAN.md) defines test IDs; outcomes remain blank.

## Ranked register

| Rank / ID | Severity | Problem | Evidence class | Prototype / production gate | Prior audit |
|---|---|---|---|---|---|
| 1 / QA-001 | High | Current technical and end-to-end evidence is absent. | Confirmed evidence gap | Prototype: gate for claimed working flows. Production: yes. | F-004 |
| 2 / QA-002 | High | Two migration tracks, server grants and deployed schema need reconciliation. | Source divergence plus unknown live state | Prototype: conditional on deployed schema. Production: yes. | F-009 |
| 3 / QA-003 | High | Production configuration and operational recovery are unverified. | Potential risk; effective settings unknown | Prototype: stable sandbox access needed. Production: yes. | F-008 |
| 4 / QA-004 | High | Business and account workflows outside GIS have no audit producers. | Confirmed missing coverage | Prototype: blocks complete-audit demonstration. Production: gate for audit requirements. | F-002 |
| 5 / QA-005 | High | Database append-only enforcement and deletion-event retention are not established. | Potential risk; live rights unknown | Prototype: disclose limitation. Production: yes for history guarantees. | F-003 |
| 6 / QA-006 | High | Invitation endpoint has no usable Administrator form integration. | Confirmed integration gap | Prototype: yes if inviting a new user is demonstrated. Production: release-scope dependent. | F-006 |
| 7 / QA-007 | High | Generation, aggregations, charts and exports are not implemented. | Confirmed missing backend | Prototype: blocks report demonstration. Production: release-scope dependent. | F-007 |
| 8 / QA-008 | High | Previously reported npm vulnerabilities remain unidentified. | Unverified advisory evidence gap | Prototype: exposure-dependent. Production: yes until assessed. | F-001; severity reassessed, not Critical |
| 9 / QA-009 | High | One-active-President guard does not serialize two different application approvals. | Potential concurrency risk; source guard weakness | Prototype: avoid unsupported concurrency claims. Production: gate if confirmed and workflow included. | New; distinct from F-005 |
| 10 / QA-010 | Medium | Request string limits exceed member/application SQL lengths. | Confirmed source/schema mismatch | Prototype: boundary-input risk. Production: fix before accepting those inputs. | New |
| 11 / QA-011 | Medium | A nonempty array acronym reaches trim before validation. | Confirmed source defect | Prototype: malformed-input risk. Production: correction recommended. | F-010 |
| 12 / QA-012 | Medium | Inactive-member prevalidation is incomplete. | Confirmed service/SQL mismatch | Prototype: inactive participant error risk. Production: correction recommended. | F-015 |
| 13 / QA-013 | Medium | Ordinary Auth requests lack explicit failure handling used by activation. | Confirmed handling difference; runtime impact unknown | Prototype: provider outage risk. Production: reliability gate. | F-011 |
| 14 / QA-014 | Medium | /api/v1/me has no application rate limiter. | Confirmed application limiter absence | Prototype: controlled users only. Production: assess before public API exposure. | F-012 |
| 15 / QA-015 | Medium | Several directories/options load entire sets and Monitoring searches in memory. | Confirmed unbounded query design; unmeasured impact | Prototype: dataset dependent. Production: capacity acceptance gate. | F-013 |
| 16 / QA-016 | Medium | Administrator totals are always zero. | Confirmed placeholder | Prototype: blocks genuine totals claim. Production: release-scope dependent. | F-016 |
| 17 / QA-017 | Medium | Flutter has no AssocMap workflows or organizational API integration. | Confirmed implementation gap | Prototype: blocks Android workflows. Production: mobile release-dependent. | F-017 |
| 18 / QA-018 | Medium | Logout is local; stored refresh token has no implemented refresh flow. | Confirmed limited behavior; policy decision pending | Prototype: expiry risk. Production: policy verification needed. | F-014 |
| 19 / QA-019 | Medium | Sanitized failure responses have limited correlation/timing evidence. | Confirmed diagnostic limitation | Prototype: troubleshooting risk. Production: operational acceptance gate. | F-021 |
| 20 / QA-020 | Informational | Legacy association points remain stored but excluded from normal project-only GIS. | Confirmed intentional boundary | Prototype: scope clarification. Production: acceptance decision. | F-019 |
| 21 / QA-021 | Informational | Monitoring history predicates differ from GIS eligibility. | Confirmed policy difference; no cross-association bypass established | Prototype/production: clarification, not a confirmed unauthorized-read defect. | F-022 |
| 22 / QA-022 | Informational | Approved questionnaire/edition mapping is unavailable. | Confirmed evidence limitation | Prototype: documentary gate. Production: no direct runtime blocker. | F-020 |
| 23 / QA-023 | Informational | Specific Area CRUD and regional/city creation limits need accurate documentation. | Confirmed limitations; approved broader scope unknown | Prototype/production: scope dependent. | Prior F-020 context; newly refined scope evidence |
| 24 / QA-024 | Low | Large resource/monitoring services need focused regression coverage. | Source-supported maintainability risk | Prototype: generally no. Production: regression coverage important. | New maintainability observation |

## Exact evidence and proposed corrections

### QA-001 — Acceptance evidence

- **Severity / characteristic:** High / Reliability; Functional Suitability.
- **Evidence class:** Confirmed evidence gap.
- **Problem:** Current technical and end-to-end evidence is absent.
- **Affected files / functions:** `web/tests/Feature/GisScopedReadsTest.php; InvitationActivationServiceTest.php; SupabaseInvitationAuthTest.php; web/phpunit.xml; mobile/test/widget_test.dart` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Existing tests are SQLite/mocked or starter tests. No current run artifacts, real PostGIS mutation/concurrency tests, browser/UAT, performance or restoration evidence supplied.
- **Business impact:** Core demonstration/release failures remain possible despite source safeguards.
- **Reproduction prerequisites:** Approved nonproduction environment and current-source identity.
- **Smallest recommended correction:** Run the verification plan and collect genuine redacted artifacts.
- **Potential side effects:** Isolation mistakes could hit live services; confirm the test environment first.
- **Testing required:** TECH-01; MEM-02; GIS-05; UAT-01; OPS-01; no result available.
- **Estimated complexity:** Medium verification effort.
- **Readiness effect:** Prototype: gate for claimed working flows. Production: yes.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-002 — Database

- **Severity / characteristic:** High / Compatibility; Security; Reliability.
- **Evidence class:** Source divergence plus unknown live state.
- **Problem:** Two migration tracks, server grants and deployed schema need reconciliation.
- **Affected files / functions:** `supabase/migrations/001_assocmap_schema.sql::program_activity_records/RLS; 005_add_monitoring_monthly_production_and_conductors.sql::isolation guards; 006_create_user_invitations.sql; 007_secure_laravel_infrastructure.sql; web/database/migrations/2026_10_05_102947_update_program_activity_status_constraint.php` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Baseline program activity status omits ongoing; Laravel migration adds it. Access gates require user_invitations. RLS/client-helper revokes exist; runtime owner/grants/history are unknown. Monthly guards require READ COMMITTED.
- **Business impact:** Write errors, denied logins, client exposure or missing integrity rules if deployment differs.
- **Reproduction prerequisites:** Schema-only catalog and migration history; no live inspection performed.
- **Smallest recommended correction:** Have the operator compare definitions/constraints/extension schema/owners/grants and approve an ordered migration plan.
- **Potential side effects:** Replaying baseline/backfill or altering grants blindly can destroy history or block application access.
- **Testing required:** DB-01; ACT-01; AUTH-01; GIS-01; no result available.
- **Estimated complexity:** Medium investigation; any schema change separately approved.
- **Readiness effect:** Prototype: conditional on deployed schema. Production: yes.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-003 — Operations

- **Severity / characteristic:** High / Security; Flexibility; Reliability.
- **Evidence class:** Potential risk; effective settings unknown.
- **Problem:** Production configuration and operational recovery are unverified.
- **Affected files / functions:** `web/config/app.php; session.php; database.php; logging.php; web/bootstrap/app.php::trustProxies; web/public/index.php; web/composer.json::scripts.setup` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Secure cookie is environment-dependent, session encryption defaults false, pgsql TLS defaults prefer, proxy trust is loopback-only; no accepted hosting/restore runbook was found.
- **Business impact:** Misconfiguration can expose sessions/errors or cause outages and irreversible data loss.
- **Reproduction prerequisites:** Actual hosting/domain/proxy/session/database/logging and backup setup.
- **Smallest recommended correction:** Validate the release checklist; serve only web/public, HTTPS and approved TLS/session policy; prove restoration.
- **Potential side effects:** Proxy/cookie/key changes affect URLs, login and encrypted context; never regenerate production keys blindly.
- **Testing required:** OPS-01; AUTH-01; INV-01; DEPLOY-01; no result available.
- **Estimated complexity:** Medium to high operational effort.
- **Readiness effect:** Prototype: stable sandbox access needed. Production: yes.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-004 — Audit Logging

- **Severity / characteristic:** High / Security.
- **Evidence class:** Confirmed missing coverage.
- **Problem:** Business and account workflows outside GIS have no audit producers.
- **Affected files / functions:** `web/app/Services/AuditLogService.php::record; UserInvitationService.php::initiate; InvitationActivationService.php::finish; AssociationService.php; MemberApplicationService.php; ProjectService.php; TrainingService.php; ProgramActivityService.php; MonitoringService.php` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Producer search identifies GIS inserts; the shared record method has no workflow caller. Operational Log messages are not audit events.
- **Business impact:** Cannot reconstruct comprehensive organizational/account changes or defend complete-audit claims.
- **Reproduction prerequisites:** Perform a non-GIS mutation and inspect its intended event in an approved sandbox.
- **Smallest recommended correction:** Approve an event catalogue and add trusted-actor transactional producers incrementally.
- **Potential side effects:** Required event failures may roll back mutations; provider stages need explicit outcomes rather than pretending to be one SQL transaction.
- **Testing required:** AUD-01; AUD-02; ORG-01; INV-01; no result available.
- **Estimated complexity:** Medium; cross-module scope requires approval.
- **Readiness effect:** Prototype: blocks complete-audit demonstration. Production: gate for audit requirements.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-005 — Audit history/GIS

- **Severity / characteristic:** High / Security; Reliability.
- **Evidence class:** Potential risk; live rights unknown.
- **Problem:** Database append-only enforcement and deletion-event retention are not established.
- **Affected files / functions:** `web/app/Models/AuditLog.php::booted; web/app/Services/GisLocationService.php::{store,destroy}; supabase/migrations/001_assocmap_schema.sql::audit_logs` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Instance callbacks reject edits/deletes; bulk/raw writes bypass them. No append-only DB enforcement is defined in reviewed migrations. GIS create replay checks retained DELETE GIS events; actor FK uses ON DELETE SET NULL.
- **Business impact:** Privileged mutation/pruning could weaken historical integrity and deleted-ID replay protection.
- **Reproduction prerequisites:** Actual application-role ownership/grants and retention policy.
- **Smallest recommended correction:** Verify least privilege and retained deletion events; design approved database protection and actor-retention policy.
- **Potential side effects:** A blanket update blocker could conflict with the actor FK; restrictive grants must permit required audit INSERTs.
- **Testing required:** AUD-02; DB-01; GIS-05; no result available.
- **Estimated complexity:** Medium design; DB changes need approval.
- **Readiness effect:** Prototype: disclose limitation. Production: yes for history guarantees.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-006 — User Management

- **Severity / characteristic:** High / Functional Suitability; Interaction Capability.
- **Evidence class:** Confirmed integration gap.
- **Problem:** Invitation endpoint has no usable Administrator form integration.
- **Affected files / functions:** `web/routes/web.php::admin.user-invitations.store; web/resources/views/admin/shared/form.blade.php; web/resources/js/pages/admin/forms.js; web/app/Services/UserInvitationService.php::initiate` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Generic user form has onsubmit=false, no submitting action and a disabled Invite User button; backend provisioning exists.
- **Business impact:** New-user demonstration cannot complete through the intended UI.
- **Reproduction prerequisites:** Administrator opens user creation from dashboard shell.
- **Smallest recommended correction:** Approve a dedicated adapter to the existing endpoint and uncertain/reconciliation states.
- **Potential side effects:** Touches User Management; requires provider and browser tests without weakening activation.
- **Testing required:** INV-01; INV-02; UI-01; no result available.
- **Estimated complexity:** Medium.
- **Readiness effect:** Prototype: yes if inviting a new user is demonstrated. Production: release-scope dependent.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-007 — Reports & Analytics

- **Severity / characteristic:** High / Functional Suitability.
- **Evidence class:** Confirmed missing backend.
- **Problem:** Generation, aggregations, charts and exports are not implemented.
- **Affected files / functions:** `web/resources/views/admin/reports/index.blade.php; web/resources/js/pages/admin/reports.js::initReports; web/routes/web.php; web/package.json` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Filter visibility only; generation disabled and preview explicitly empty. No report controller/export endpoint or Chart.js declaration found.
- **Business impact:** Cannot demonstrate genuine program summaries or report downloads.
- **Reproduction prerequisites:** Open Reports from Administrator shell.
- **Smallest recommended correction:** Approve formulas, periods, role/association scope and export allowlists, then implement one report end to end.
- **Potential side effects:** Incorrect formulas or scope can disclose data/mislead decisions; pagination/size limits needed.
- **Testing required:** REP-01; REP-02; no result available.
- **Estimated complexity:** High feature work.
- **Readiness effect:** Prototype: blocks report demonstration. Production: release-scope dependent.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-008 — Dependencies

- **Severity / characteristic:** High / Security; Compatibility.
- **Evidence class:** Unverified advisory evidence gap.
- **Problem:** Previously reported npm vulnerabilities remain unidentified.
- **Affected files / functions:** `web/package.json; web/package-lock.json; web/composer.json; web/composer.lock` (paths relative to C:/dev/AssocMap).
- **Source evidence:** User reported two critical findings but names/advisories were not captured; no package audit was executed. Locks establish versions only.
- **Business impact:** Cannot assess applicability or sign off dependency security.
- **Reproduction prerequisites:** User-authorized network dependency audit.
- **Smallest recommended correction:** Obtain named advisory JSON and dependency paths, then propose a targeted compatible update.
- **Potential side effects:** Updates may change Node requirements and GIS/asset behavior; avoid forced blind upgrades.
- **Testing required:** DEP-01; TECH-01; GIS-04; no result available.
- **Estimated complexity:** Low identification effort; remediation unknown.
- **Readiness effect:** Prototype: exposure-dependent. Production: yes until assessed.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-009 — Membership invariants

- **Severity / characteristic:** High / Reliability; Security; Safety.
- **Evidence class:** Potential concurrency risk; source guard weakness.
- **Problem:** One-active-President guard does not serialize two different application approvals.
- **Affected files / functions:** `supabase/migrations/001_assocmap_schema.sql::validate_one_active_president/trg_validate_one_active_president; web/app/Services/MemberApplicationService.php::approve; web/app/Services/MemberService.php::unarchive` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Trigger performs IF EXISTS without association-row serialization. F-005 locks each application, so different IDs use different locks. Reviewed migrations contain no President-specific uniqueness backstop.
- **Business impact:** Concurrent distinct applications or restores might create multiple active Presidents, violating an existing organizational rule.
- **Reproduction prerequisites:** Two valid distinct President applications under one association with no active President, overlapping PostgreSQL transactions, and no additional deployed guard. Not reproduced.
- **Smallest recommended correction:** First reproduce in disposable PostgreSQL; review an association-wide serialization/database design covering all membership writes.
- **Potential side effects:** Lock ordering/throughput and role-position representation matter; this is a separate scope from F-005 and needs approval.
- **Testing required:** MEM-03; DB-01; no result available.
- **Estimated complexity:** Medium investigation/design.
- **Readiness effect:** Prototype: avoid unsupported concurrency claims. Production: gate if confirmed and workflow included.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-010 — Member input

- **Severity / characteristic:** Medium / Compatibility; Functional Suitability; Reliability.
- **Evidence class:** Confirmed source/schema mismatch.
- **Problem:** Request string limits exceed member/application SQL lengths.
- **Affected files / functions:** `web/app/Http/Requests/StoreMemberApplicationRequest.php::rules; StoreMemberRequest.php::rules; supabase/migrations/001_assocmap_schema.sql::member_applications/members` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Names allow 150 vs VARCHAR(120); suffix 50 vs 20; contact 50 vs 30; Other position 150 vs 100. Submission route uses the application request; direct member create/update methods are not routed.
- **Business impact:** Valid-looking inputs can reach a database length error instead of controlled form validation.
- **Reproduction prerequisites:** Values between the request and deployed SQL maxima; actual schema must match local definitions.
- **Smallest recommended correction:** Align request/UI limits to approved existing schema after authorization; no schema widening implied.
- **Potential side effects:** Previously accepted long values would be rejected earlier; confirm existing data and requirements.
- **Testing required:** MEM-04; DB-01; no result available.
- **Estimated complexity:** Low.
- **Readiness effect:** Prototype: boundary-input risk. Production: fix before accepting those inputs.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-011 — Association validation

- **Severity / characteristic:** Medium / Reliability; Security; Functional Suitability.
- **Evidence class:** Confirmed source defect.
- **Problem:** A nonempty array acronym reaches trim before validation.
- **Affected files / functions:** `web/app/Http/Requests/StoreAssociationRequest.php::prepareForValidation` (paths relative to C:/dev/AssocMap).
- **Source evidence:** filled(acronym) then trim(acronym), unlike the guarded name normalization, runs before string rules.
- **Business impact:** Malformed requests can fail outside controlled validation.
- **Reproduction prerequisites:** Authorized POST/PATCH with acronym represented as a nonempty array. Not executed.
- **Smallest recommended correction:** Guard trimming with is_string and preserve invalid input for normal validation.
- **Potential side effects:** Changes invalid-input handling only; retain all existing valid rules.
- **Testing required:** ORG-03; no result available.
- **Estimated complexity:** Low.
- **Readiness effect:** Prototype: malformed-input risk. Production: correction recommended.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-012 — Training

- **Severity / characteristic:** Medium / Functional Suitability; Reliability.
- **Evidence class:** Confirmed service/SQL mismatch.
- **Problem:** Inactive-member prevalidation is incomplete.
- **Affected files / functions:** `web/app/Services/TrainingService.php::validateParticipants; supabase/migrations/001_assocmap_schema.sql::validate_training_participant_association` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Service filters association and archived_at but not member_status=active; SQL trigger explicitly requires active and unarchived members.
- **Business impact:** Deployed trigger should reject invalid participants, but service may surface a database exception rather than a helpful validation error.
- **Reproduction prerequisites:** Inactive, nonarchived member submitted for a training; live trigger unknown.
- **Smallest recommended correction:** Add the active-status predicate and controlled regression verification after approval.
- **Potential side effects:** Historical participation rules must remain unchanged; do not weaken SQL guard.
- **Testing required:** TRN-01; DB-01; no result available.
- **Estimated complexity:** Low.
- **Readiness effect:** Prototype: inactive participant error risk. Production: correction recommended.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-013 — Authentication

- **Severity / characteristic:** Medium / Reliability; Performance Efficiency.
- **Evidence class:** Confirmed handling difference; runtime impact unknown.
- **Problem:** Ordinary Auth requests lack explicit failure handling used by activation.
- **Affected files / functions:** `web/app/Services/SupabaseAuthService.php::{signIn,getUser,adminRequest,activationRequest}; web/app/Http/Middleware/EnsureSupabaseAuthenticated.php::handle` (paths relative to C:/dev/AssocMap).
- **Source evidence:** signIn/getUser have no own connect/total timeout, redirect suppression or ConnectionException handling; invitation helpers use 5s/15s and no redirects. Protected requests call getUser remotely.
- **Business impact:** Provider/network faults may produce generic errors or occupy workers; latency origin unmeasured.
- **Reproduction prerequisites:** Provider timeout/unavailable/invalid-response scenarios in a sandbox.
- **Smallest recommended correction:** Approve bounded Auth handling and sanitized outcomes; measure before any caching design.
- **Potential side effects:** Auth changes can alter expiry/revocation behavior; never cache identity past authorization rules.
- **Testing required:** AUTH-02; PERF-01; no result available.
- **Estimated complexity:** Medium.
- **Readiness effect:** Prototype: provider outage risk. Production: reliability gate.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-014 — Profile API

- **Severity / characteristic:** Medium / Security; Performance Efficiency.
- **Evidence class:** Confirmed application limiter absence.
- **Problem:** /api/v1/me has no application rate limiter.
- **Affected files / functions:** `web/routes/api.php; web/bootstrap/app.php; web/app/Http/Middleware/EnsureSupabaseApiAuthenticated.php` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Bearer verification calls remote getUser; route has no throttle and bootstrap does not enable throttleApi. Infrastructure limiter unknown.
- **Business impact:** Request abuse can consume provider/worker resources; exploitability and capacity unmeasured.
- **Reproduction prerequisites:** Many requests to the versioned profile endpoint; edge settings unknown.
- **Smallest recommended correction:** Approve a suitable API rate-limit policy and controlled error responses.
- **Potential side effects:** Legitimate mobile retries and shared IPs must be considered.
- **Testing required:** API-01; PERF-02; no result available.
- **Estimated complexity:** Low to medium.
- **Readiness effect:** Prototype: controlled users only. Production: assess before public API exposure.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-015 — Organizational lists

- **Severity / characteristic:** Medium / Performance Efficiency; Flexibility.
- **Evidence class:** Confirmed unbounded query design; unmeasured impact.
- **Problem:** Several directories/options load entire sets and Monitoring searches in memory.
- **Affected files / functions:** `web/app/Http/Controllers/Admin/{AssociationController,MemberController,MunicipalityController}.php::index; FieldOfficer/ProjectController.php::index; MonitoringWorkspaceController.php::index; web/app/Services/GisQueryService.php::projectLocations` (paths relative to C:/dev/AssocMap).
- **Source evidence:** get() loads complete collections; Monitoring filters after retrieval; per-project GIS summaries are batched but unbounded for loaded project IDs. Eager relationships reduce obvious per-row queries.
- **Business impact:** Growth can increase server memory, HTML size, query time and browser work.
- **Reproduction prerequisites:** Representative larger sandbox data; no synthetic data was generated by this audit.
- **Smallest recommended correction:** Measure counts/query plans/memory first; incrementally add server-side pagination/search.
- **Potential side effects:** Preserve filters, selection, archived visibility and role scope; no speculative index/migration.
- **Testing required:** PERF-01; PERF-02; ORG-01; no result available.
- **Estimated complexity:** Medium.
- **Readiness effect:** Prototype: dataset dependent. Production: capacity acceptance gate.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-016 — Dashboard

- **Severity / characteristic:** Medium / Functional Suitability; Interaction Capability.
- **Evidence class:** Confirmed placeholder.
- **Problem:** Administrator totals are always zero.
- **Affected files / functions:** `web/routes/web.php::admin.dashboard; web/resources/views/admin/dashboard.blade.php` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Route passes zero for all four total counts.
- **Business impact:** Misrepresents real records and weakens a coherent demonstration.
- **Reproduction prerequisites:** Any nonempty eligible dataset.
- **Smallest recommended correction:** Approve count definitions and role/archival rules, then use actual queries.
- **Potential side effects:** Incorrect scope/aggregation can produce misleading totals; verify empty and populated data.
- **Testing required:** DASH-01; no result available.
- **Estimated complexity:** Low to medium.
- **Readiness effect:** Prototype: blocks genuine totals claim. Production: release-scope dependent.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-017 — Mobile/API

- **Severity / characteristic:** Medium / Functional Suitability; Compatibility; Flexibility.
- **Evidence class:** Confirmed implementation gap.
- **Problem:** Flutter has no AssocMap workflows or organizational API integration.
- **Affected files / functions:** `mobile/lib/main.dart; mobile/pubspec.yaml; mobile/test/widget_test.dart; web/routes/api.php` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Located mobile/pubspec.yaml under the project; lib contains the counter starter only. Backend offers profile API plus separate Sanctum scaffold.
- **Business impact:** Android GIS/auth/monitoring cannot be demonstrated as implemented.
- **Reproduction prerequisites:** Mobile organizational feature expectation.
- **Smallest recommended correction:** Approve one mobile workflow/API contract and secure token lifecycle before development.
- **Potential side effects:** New API and storage/sync/offline scope are consequential; do not count counter tests as system tests.
- **Testing required:** MOB-01; API-01; no result available.
- **Estimated complexity:** High feature effort.
- **Readiness effect:** Prototype: blocks Android workflows. Production: mobile release-dependent.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-018 — Session lifecycle

- **Severity / characteristic:** Medium / Security; Reliability.
- **Evidence class:** Confirmed limited behavior; policy decision pending.
- **Problem:** Logout is local; stored refresh token has no implemented refresh flow.
- **Affected files / functions:** `web/app/Http/Controllers/Auth/StaffAuthController.php::{store,destroy}; web/app/Services/SupabaseAuthService.php::getUser` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Local session invalidation/regenerated CSRF; no provider logout/revocation or refresh call found.
- **Business impact:** Users must sign in after token expiry; provider-token revocation scope is not established.
- **Reproduction prerequisites:** Expiry and separately retained provider token scenarios in sandbox.
- **Smallest recommended correction:** Approve intended session/refresh/revocation policy and tests before changing Auth.
- **Potential side effects:** Provider-wide logout can affect other clients; session rotation/revocation must be preserved.
- **Testing required:** AUTH-01; AUTH-02; no result available.
- **Estimated complexity:** Medium.
- **Readiness effect:** Prototype: expiry risk. Production: policy verification needed.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-019 — Observability

- **Severity / characteristic:** Medium / Maintainability; Reliability.
- **Evidence class:** Confirmed diagnostic limitation.
- **Problem:** Sanitized failure responses have limited correlation/timing evidence.
- **Affected files / functions:** `web/app/Providers/GisServiceProvider.php; web/bootstrap/app.php::exception handlers; web/app/Models/UserProfile.php::canAccessApplication` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Generic codes/SQLSTATE or no detailed log, rather than complete Auth/DB timing spans; privacy protection is intentional.
- **Business impact:** Fault location and three-second lookup causes remain hard to diagnose.
- **Reproduction prerequisites:** Redacted browser/server/provider/DB timings.
- **Smallest recommended correction:** Approve minimal correlation IDs and separately timed stages with privacy limits.
- **Potential side effects:** Never log tokens, request passwords, private profiles, geometry or raw SQL bindings.
- **Testing required:** PERF-01; AUTH-02; OPS-01; no result available.
- **Estimated complexity:** Low to medium.
- **Readiness effect:** Prototype: troubleshooting risk. Production: operational acceptance gate.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-020 — GIS compatibility

- **Severity / characteristic:** Informational / Functional Suitability; Compatibility.
- **Evidence class:** Confirmed intentional boundary.
- **Problem:** Legacy association points remain stored but excluded from normal project-only GIS.
- **Affected files / functions:** `web/app/Models/GisLocation.php::TYPES; web/app/Services/GisQueryService.php::scoped; GisLocationService.php::lockParent; supabase/migrations/001_assocmap_schema.sql::gis_locations` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Current types are project_point/project_boundary; SQL still permits legacy association_point.
- **Business impact:** Do not claim Association Point creation is currently implemented or delete historical rows automatically.
- **Reproduction prerequisites:** Existing legacy data, if any; no live rows inspected.
- **Smallest recommended correction:** Document project-only scope; obtain separate approval for any legacy review/cleanup.
- **Potential side effects:** Historical data/audits and earlier requirements can conflict; preserve both until decided.
- **Testing required:** GIS-01; GIS-06; no result available.
- **Estimated complexity:** Low documentation; cleanup unknown.
- **Readiness effect:** Prototype: scope clarification. Production: acceptance decision.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-021 — Read eligibility

- **Severity / characteristic:** Informational / Security; Functional Suitability.
- **Evidence class:** Confirmed policy difference; no cross-association bypass established.
- **Problem:** Monitoring history predicates differ from GIS eligibility.
- **Affected files / functions:** `web/app/Http/Controllers/MonitoringWorkspaceController.php::projectsFor; web/app/Services/GisQueryService.php::scoped; FieldOfficer/ProjectController.php::index` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Account Monitoring reads follow active own link without GIS parent-archive/status exclusions; FO project index follows assignment while create choices require active parents.
- **Business impact:** History visibility may be intentional; blanket permission statements are inaccurate.
- **Reproduction prerequisites:** Own linked/assigned association becomes archived/inactive.
- **Smallest recommended correction:** Obtain approved read/history policy; verify own-scope behavior without automatically tightening it.
- **Potential side effects:** Changing history visibility can hide valid evidence and alter business workflows.
- **Testing required:** ROLE-01; MON-02; GIS-02; no result available.
- **Estimated complexity:** Low policy review.
- **Readiness effect:** Prototype/production: clarification, not a confirmed unauthorized-read defect.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-022 — Research instrument

- **Severity / characteristic:** Informational / Maintainability; Functional Suitability.
- **Evidence class:** Confirmed evidence limitation.
- **Problem:** Approved questionnaire/edition mapping is unavailable.
- **Affected files / functions:** `docs/production-readiness/*.md; inspected project documentation inventory; user confirmed Capstone 1 unavailable` (paths relative to C:/dev/AssocMap).
- **Source evidence:** No approved questionnaire or ISO edition selection found. User supplied the 2023 assessment framework and Capstone outline, not the approved instrument.
- **Business impact:** Cannot certify alignment, rewrite evaluation items, claim scores or UAT results.
- **Reproduction prerequisites:** Adviser-approved instrument and revision/edition record.
- **Smallest recommended correction:** Supply the instrument and get adviser approval for any 2011/2023 crosswalk or amendments.
- **Potential side effects:** Changing items silently would invalidate research comparability.
- **Testing required:** DOC-01; UAT-01; no result available.
- **Estimated complexity:** Low evidence collection; adviser decision.
- **Readiness effect:** Prototype: documentary gate. Production: no direct runtime blocker.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-023 — Area scope

- **Severity / characteristic:** Informational / Functional Suitability; Flexibility.
- **Evidence class:** Confirmed limitations; approved broader scope unknown.
- **Problem:** Specific Area CRUD and regional/city creation limits need accurate documentation.
- **Affected files / functions:** `web/routes/web.php::admin.areas.store; web/app/Services/AreaService.php::create; MunicipalityService.php::create; StoreMunicipalityRequest.php; Admin/MunicipalityController.php::getAreaRows` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Specific Areas are created and selected as references; no specific-area update/deactivate routes found. Directory rows cover municipalities/barangays. Creation fixes Cebu/Region VII and has no locality_type input; stored cities can render as City.
- **Business impact:** Earlier inventory overstated full Specific Area CRUD; broader geography/city entry cannot be assumed.
- **Reproduction prerequisites:** Need to edit/deactivate a Specific Area or create a City/outside-region record.
- **Smallest recommended correction:** Correct claims in current assessment; ask for approved scope before adding workflows.
- **Potential side effects:** Geographic changes affect associations/GIS/reference integrity; do not invent new supported regions.
- **Testing required:** AREA-01; DOC-01; no result available.
- **Estimated complexity:** Low clarification; implementation scope unknown.
- **Readiness effect:** Prototype/production: scope dependent.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

### QA-024 — Services

- **Severity / characteristic:** Low / Maintainability.
- **Evidence class:** Source-supported maintainability risk.
- **Problem:** Large resource/monitoring services need focused regression coverage.
- **Affected files / functions:** `web/app/Services/MonitoringService.php; ProjectService.php; web/tests` (paths relative to C:/dev/AssocMap).
- **Source evidence:** Current source is approximately 1,304 and 895 lines respectively, with nested resource/period/history checks and little dedicated regression coverage. Size alone is not a defect.
- **Business impact:** Changes are difficult to reason about without tests and may regress history rules.
- **Reproduction prerequisites:** Future changes to synchronization/period logic.
- **Smallest recommended correction:** Add meaningful focused tests and document invariants before any justified extraction.
- **Potential side effects:** Broad refactors could change transaction ordering, scope and history semantics; avoid cosmetic rewrites.
- **Testing required:** MON-01; MON-02; PRJ-01; no result available.
- **Estimated complexity:** Medium test effort; refactor not approved.
- **Readiness effect:** Prototype: generally no. Production: regression coverage important.
- **Disposition:** Documentation only. Any implementation/database/configuration work needs separate approval.

## Resolved/source-addressed observations

**F-005: Addressed in source; Requires runtime confirmation.** Current MemberApplicationService::approve starts a transaction at line 101, reloads/locks at 105–108, authorizes at 110 and checks pending at 115. Member/classification/application writes remain inside the callback ending at 178. reject starts at 186, reloads/locks at 191–194, authorizes at 196 and checks pending at 201; save is inside the callback ending at 225. Stale JSON validation returns 409; HTML uses existing redirect/error display. No PostgreSQL concurrency or rollback check was executed. This does not serialize different applications under one association (QA-009).

**F-018: Addressed in source.** The unused MemberClassification import remains absent from web/app/Services/MemberApplicationService.php. PHP lint is pending. Neither fix was made during this audit.

The earlier production-readiness reports remain unchanged as historical snapshots. Their open F-005 entries and broader Specific Area wording must not override this current assessment. No finding is marked functionally verified.

