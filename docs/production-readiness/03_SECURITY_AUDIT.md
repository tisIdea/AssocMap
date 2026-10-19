# Security Audit

Audit date: 2026-10-13. **Static review, not penetration testing or security certification.** No environment values, credentials, live grants/RLS, external provider settings or records were inspected. Current runtime outcomes are Pending Verification. The complete severity/evidence register is [10_FINDINGS_AND_RISKS.md](10_FINDINGS_AND_RISKS.md).

## Confirmed gaps versus risks

Confirmed source gaps include missing non-GIS audit producers (F-002), non-atomic member decision checks (F-005), disconnected invitation UI (F-006), absent reporting backend (F-007), malformed acronym normalization (F-010), no app limiter on /api/v1/me (F-012), and training/SQL validation inconsistency (F-015). These findings are not claims that an exploit was reproduced.

Live cookies/TLS, effective APP_DEBUG, database grants, deployed schema/RLS, provider contract and audit immutability are unresolved risks (F-003/F-008/F-009). Two critical npm findings were reported by the user; affected packages/advisories and present applicability remain unknown (F-001). No package is blamed based on its version alone. No critical exploitable defect was independently confirmed in this pass.

## Authentication data flow

1. POST /login passes staff-login throttling and StaffLoginRequest. StaffAuthController calls SupabaseAuthService::signIn with validated credentials.
2. Provider returns an identity/token response; the controller resolves user_profiles and its role. UserProfile::canAccessApplication checks active profile/role and invitation eligibility. Legacy active profiles without invitation records are supported, but a missing invitation table fails access closed.
3. On acceptance the Laravel session ID rotates; provider access/refresh tokens and profile/role identifiers are stored server-side in the session. Passwords are not stored on UserProfile.
4. Every protected browser request calls provider /auth/v1/user, resolves the profile and checks eligibility before role middleware. Invitation eligibility is cached on that model instance, not a cross-request authorization cache.
5. Logout invalidates local session and rotates CSRF; provider revocation/refresh are not implemented (F-014).
6. /api/v1/me independently validates a bearer token through the provider and returns only the current profile ID, display name, role and active flag. It does not use the Laravel web-session token or treat a Supabase token as a Sanctum token.

Source: web/app/Http/Controllers/Auth/StaffAuthController.php::{store,destroy}; web/app/Services/SupabaseAuthService.php::{signIn,getUser}; web/app/Http/Middleware/EnsureSupabaseAuthenticated.php::handle; EnsureSupabaseApiAuthenticated.php::handle; web/app/Models/UserProfile.php::canAccessApplication; web/routes/api.php. Status: source-supported; actual provider/session checks not executed. Manuscript: Chapter III Implementation / Program Specification / User-Guide.

## Invitation and activation flow

Administrator provisioning reserves a local invitation, marks an external-write claim, checks provider identity absence conservatively, sends a single provider invite and creates an inactive profile. Ambiguous provider outcomes are held for reconciliation; a SQL transaction cannot roll back a successful remote Auth write. Service-role access is server-only and the current invitation configuration requires a legacy service-role JWT format; compatibility with actual provider settings is unverified.

The activation page reads token_hash from the URL fragment, immediately removes the fragment from history, and sends it only after explicit form submission with CSRF. Query-string capabilities are discarded. Provider verification and an independent recipient identity check bind invitation ID, profile UUID, email, role and association. Context is encrypted using Laravel Crypt in a persistent store, bound to the session fingerprint and has fixed bounded expiry (at most 600 seconds). Session blocking plus an invitation write lock/claim prevents unconfirmed password writes from being replayed. After confirmed provider password update, local profile activation, association-account link and invitation acceptance occur in one database transaction. Recovery retries local completion only with a confirmed receipt; activation does not log the recipient in automatically.

Source: UserInvitationService::initiate; SupabaseAuthService::{inviteUser,verifyInvitationToken,updateInvitationPassword,getInvitationIdentity}; InvitationActivationController::{verify,setPassword,recover}; InvitationActivationContext::{storage,create,read,save}; InvitationActivationService::{claimPassword,finish}; ProtectInvitationActivation; auth/invitation-layout.blade.php. Status: source-supported; mocked tests exist; real SMTP/link/provider/storage/concurrency tests pending. Manuscript: Chapter III Implementation / Testing / Installation Guide.

## Security-control evidence matrix

| Area | Source mechanism and exact evidence | Limitation / required manual check | Manuscript section |
|---|---|---|---|
| Login abuse | AppServiceProvider::staff-login limiter: 5/min identity+IP and 30/min IP; routes/web.php login POST applies it | Verify effective shared cache, spoofing/proxy trust and 429 behavior | III Implementation / Testing |
| Invitation abuse | invitation-activation: 30/min IP, 10/min session; provisioning 5/min and 30/hour actor | Verify session locking/store and provider-side limits; no auto-resend on ambiguous writes | III Implementation / Testing |
| Role control | EnsureRole::handle checks active application access and exact role code | FormRequest authorize=true alone is not a bypass: routes/services must be tested together | III Program Specification |
| FO association scope | GIS applyRoleScope/lockParent; Project/Training/ProgramActivity services; member reviewer guard | Scope/lifecycle checks differ across modules; race handling requires actual tests | III Use Cases / Testing |
| Association Account | association_accounts active-link predicates in GIS/Monitoring/member submission | Monitoring historical-parent eligibility differs from GIS (F-022); no cross-association bypass shown | III Use Cases / Testing |
| Public GIS | GisQueryService::scoped(null) requires eligible unarchived parents, published flag/actor/time/title and readable geometry; serializer uses public allowlist | Live RLS/API interfaces are separate from Laravel public endpoint | III GIS Specification |
| Spatial input | GisGeometry normalization: 2D bounded Point/Polygon/MultiPolygon, size/vertex/ring limits; PostGIS validity/SRID checks; SQL validate_gis_location | SQL trigger validates type/parent/SRID; application adds stronger validity/world/size limits | III Database / Testing |
| GIS parent identity | SaveGisLocationRequest requires parent_association_id/project_id; lockParent locks project/association and checks scope before mismatch detail | parent_association_id is transient validation context, not a new DB column | III Program Specification |
| Permanent GIS delete | DeleteGisLocationRequest accepted confirmation; version checks; role/parent revalidation; DB privilege/FK/trigger checks; targeted DELETE and minimal audit in transaction | Foreign-key/trigger constraints intentionally block deletion rather than bypass dependencies; actual permissions pending | III GIS / Testing |
| Replay/concurrency | GIS UUID advisory lock, retained deletion audit check, updated_at version checks and row locks | Audit retention/immutability is part of replay safety (F-003); PostgreSQL concurrency not tested | III Implementation / Testing |
| SQL injection | Reviewed query values bound through Eloquent/Query Builder; raw GIS GeoJSON/bbox/identity strings use parameters; lookup identifiers are fixed validated-kind mappings | Review newly added raw SQL for future changes; no exploit scan was run | III Security Testing |
| XSS | Escaped Blade output; project JS uses Illuminate Support Js::from; GIS seed JSON_HEX flags; GIS/registration DOM rendering uses textContent | Browser payload tests pending; this is not a proof of all-page XSS absence | III Security Testing |
| CSRF | Laravel web middleware stack, Blade csrf fields, same-origin GIS credentials and X-CSRF-TOKEN; activation negative test source | Verify missing/mismatched tokens cause safe failures on real routes | III Testing |
| Activation headers | ProtectInvitationActivation::secure sets no-store, no-referrer, nosniff, DENY framing and nonce CSP | Actual HTTPS/proxy/browser response evidence pending | III Deployment / Testing |
| GIS/audit headers | GIS and AuditRequestGuard set no-store and nosniff; audit no-referrer; sanitized handlers avoid raw exceptions | Global HSTS/framing/CSP/host policy not established by these module guards; inline modules need a compatible policy | III Deployment |
| Session tokens | Server session; ID rotation; HttpOnly true/SameSite lax defaults; encryption false/secure env-dependent | Effective session cookie/encryption and infrastructure privileges must be checked without copying secrets | III Deployment |
| Audit read access | Administrator-only GET routes; AuditLogIndexRequest bounds filters/pages; controller pagination and snapshot allowlist/redaction | Model immutability is not DB append-only enforcement; actual writers mostly GIS | III Audit Architecture |
| Database boundary | SQL 001 enables business-table RLS with no direct client policies and dynamically revokes helper execution; 003/005/006/007 restrict sensitive client privileges | Live policies/grants/default privileges/owners/BYPASSRLS are unverified; do not add broad client grants | III Database / Network |
| Files/uploads | No implemented upload handler found in inspected app routes/services; framework disks configured | File-upload security cannot be claimed as implemented/tested capability; approve requirements before adding uploads | III Module Inventory |
| Dependencies | Exact versions are recorded in architecture inventory | No advisory verification was performed; npm/Composer audit pending | III Deployment / Testing |

## Audit architecture and historical integrity

Actual GIS writes insert actor UUID, action, module, entity identifier, timestamp and optional snapshots inside business transactions. DELETE GIS inserts only minimal metadata and retains older history. Stage A provides a reusable recorder and read-only viewer, but non-GIS services do not invoke the recorder. Its actor comes from trusted request attributes, not incoming JSON; metadata/snapshot fields are bounded and sensitive keys removed.

AuditLog model callbacks prohibit instance updates/deletes. They do not govern bulk or raw database writes. The SQL actor FK uses ON DELETE SET NULL, so an event can outlive its actor; the viewer resolves the actor's current display name, not an immutable name-at-event snapshot. Entity identifiers are polymorphic text, deliberately not a GIS FK, allowing events/tombstones to survive deletion. Approve retention, actor-deletion treatment and database privilege policy before claiming immutable history.

Source: AuditLogService::{record,formatSnapshot}; AuditLogController::{index,show}; AuditLog::booted; GisLocationService::{audit,destroy,store}; SQL audit_logs. Status: source-supported, DB invariants/transactions untested. Manuscript: Chapter III ERD/Data Dictionary/Implementation/Testing.

## Approval-required hardening proposals

Use findings F-005/F-010/F-011/F-012/F-015 for concrete patch boundaries. Audit producer integration requires a separately approved event catalogue (F-002); append-only/retention and production configuration changes require database/operations approval (F-003/F-008/F-009). Do not change roles, publication eligibility, activation states, geometry, password policy or session contracts simply to pass a test.

No security-sensitive source patch was implemented in Phase B. Only the unused MemberClassification import was removed. No User Management, GIS, Audit Stage A, credentials, grants, constraints or deployment settings changed.

