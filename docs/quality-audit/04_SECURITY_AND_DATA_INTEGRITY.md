# Security and Data Integrity Assessment

Assessment date: **2026-10-13 (Asia/Manila)**. Source evidence only. No live identity, database, grants, cookies, secrets or personal records inspected. Application files and migrations were not modified. Findings are linked by QA IDs in [register](09_PRIORITIZED_FINDINGS.md); runtime checks are in [verification plan](10_MANUAL_VERIFICATION_PLAN.md).

## Authentication and authorization boundaries

Browser login reaches StaffAuthController::store and SupabaseAuthService::signIn. A successful provider response is followed by UserProfile::canAccessApplication, active role/profile and invitation gating. The controller regenerates the Laravel session and stores provider access/refresh tokens server-side. Each protected request calls remote getUser, retrieves the local profile, rechecks access and supplies trusted current_user to handlers. API /api/v1/me instead reads a bearer token and returns only the verified user's minimal profile. Source: web/app/Http/Controllers/Auth/StaffAuthController.php; web/app/Services/SupabaseAuthService.php; web/app/Models/UserProfile.php; web/app/Http/Middleware/{EnsureSupabaseAuthenticated,EnsureSupabaseApiAuthenticated,EnsureRole}.php; web/app/Http/Controllers/Api/V1/MeController.php.

EnsureRole rechecks application eligibility and the required role. Some FormRequests return authorize=true because their routes/services enforce scope; this alone is not evidence of an authorization bypass. No application Policy classes were found in the inventoried app tree; middleware/service predicates are the actual architecture. Service checks and controller scoping must be tested through direct IDs, not merely hidden buttons.

| Actor | Current source boundary | Required adversarial check |
|---|---|---|
| System Administrator | Admin routes; GIS manager and actor revalidation | Inactive role/profile must lose access; no fabricated admin invitation workflow |
| Field Officer | Current primary_field_officer_id; eligible active parents for relevant write choices | Reassignment and cross-association IDs; distinguish historical reads from write eligibility |
| Association Account | Active association_accounts link; read-only GIS/Monitoring; own member submission/history | Link deactivation/relink; other association/record IDs; deny manager methods |
| Public | Only eligible published project GIS via public allowlist; no mutation routes | Private filters, unpublished/archived/invalid parent and geometry exclusions |

Source: web/routes/web.php; web/app/Services/{GisQueryService,ProjectService,TrainingService,ProgramActivityService,MemberApplicationService,MonitoringService}.php; web/app/Http/Controllers/{MonitoringWorkspaceController,AssociationAccount/MemberApplicationController}.php. Monitoring own-link history reads and assigned FO project history reads are not identical to GIS's active/unarchived parent rule (QA-021). No cross-association bypass was established by that difference.

## Invitation and activation strengths with limits

ProtectInvitationActivation is prepended and applies restricted nonce-based CSP, no-store, no-referrer, frame denial and nosniff to activation responses. The activation layout extracts the fragment token hash and clears browser history before explicit verification; query capabilities are discarded. Context is encrypted, session-bound, fixed-expiry and stored through approved persistent stores. Recipient UUID/email and invitation/profile/role/association binding are independently checked. Provider OTP/password writes are not automatically retried; confirmed receipt permits local recovery. Local profile activation, account link and accepted invitation commit together.

Source: web/app/Http/Middleware/ProtectInvitationActivation.php; web/resources/views/auth/invitation-layout.blade.php; web/app/Http/Controllers/Auth/InvitationActivationController.php; web/app/Services/{InvitationActivationContext,InvitationActivationService,SupabaseAuthService,UserInvitationService}.php; web/routes/web.php::invitation routes.

These controls do not prove real SMTP delivery, template/fragment URL compatibility, provider identity-pagination metadata, legacy service-role key compatibility, persistent store permissions or session blocking across instances. The Admin invitation UI remains disconnected (QA-006). Ordinary signIn/getUser do not have the same explicit timeout/connection-failure handling (QA-013). Local logout and unused refresh-token lifecycle remain policy limitations (QA-018). Provider calls and SQL transactions are separate systems; no distributed atomicity is claimed.

## GIS confidentiality and integrity

GisQueryService::scoped restricts current project_point/project_boundary rows to active/unarchived associations, unarchived projects and permitted lifecycle. FO scope uses current assignment; Account scope requires an active own link. Public scope adds is_published, nonblank public_title, publication actor/time and readable geometry. Public query selection and GisFeatureSerializer::make omit internal names, private address, parent IDs and actor/lifecycle metadata. Public association options use approved location titles/IDs, not private association names. Public private-parent filters are rejected by GisQueryRequest.

Geometry is represented as extensions.geometry(Geometry,4326). The Laravel service uses parameterized extensions.ST_GeomFromGeoJSON and extensions.ST_SetSRID, spatial validity/nonempty checks, bounded two-dimensional world coordinates and supported types/vertex/payload limits. The viewport predicate uses extensions.&& plus guarded extensions.ST_Intersects. ST_SetSRID assigns the declared reference system; it does not transform arbitrarily supplied coordinates. The client must supply longitude/latitude WGS84 coordinates. Mathematical validity does not establish an accurate physical project site.

Source: web/app/Services/{GisQueryService,GisFeatureSerializer,GisGeometry,GisLocationService}.php; web/app/Http/Requests/{SaveGisLocationRequest,GisQueryRequest,GisLifecycleRequest,DeleteGisLocationRequest}.php; supabase/migrations/001_assocmap_schema.sql::gis_locations/validate_gis_location. The SQL trigger has search_path public,extensions; the earlier suspected missing extension search path is not a confirmed defect.

Manager writes lock/revalidate actor, role, project and association; use updated_at version conflicts; creation/deletion serialize request UUIDs with a transaction advisory lock. Permanent deletion targets only gis_locations, checks actual privileges, incoming foreign keys and enabled noninternal DELETE triggers, and writes a minimal audit in the same transaction. A blocker stops the operation rather than bypassing dependents. The parent FK cascade direction is parent-to-location; deleting a location does not itself delete its association/project.

Unpublish preserves geometry/internal record/history. Archive separately hides the location and unpublishes it. Delete removes the row, with retained deletion event blocking delayed create retries using the same ID. The DELETE GIS audit records actor/action/entity/time without a geometry snapshot or request context. Earlier create/edit audit snapshots may still retain historical geometry; hard deletion is not a claim of erasure of every prior audit artifact.

All these are source-supported, not executed. Verify real PostGIS functions/extension placement, writer grants, dependency gates, public projections and rollback/replay behavior (GIS-01/02/05; DB-01). Data owners must approve public content/location accuracy; no separate formal geographic-verification status exists.

## F-005 current member decision behavior

MemberApplicationService::approve (transaction 101–178) and reject (186–225) re-read the application by key with lockForUpdate before authorization and pending status checks. Under ordinary PostgreSQL READ COMMITTED, a competing request on the same ID waits and then sees the committed terminal state. Approval's member, classification pivot and application writes share the transaction. A thrown failure rolls back that transaction. Stale JSON uses ValidationException status 409; browser forms retain the existing error bag/redirect and layout notice. Missing rows resolve through firstOrFail rather than recreating them.

Source: web/app/Services/MemberApplicationService.php::{approve,reject,ensureFieldOfficerCanReview}; web/app/Models/{MemberApplication,Member}.php; web/resources/views/layouts/field-officer.blade.php; web/app/Http/Controllers/FieldOfficer/MemberApplicationController.php. Local schema permits only pending/approved/rejected, requires matching review metadata and has UNIQUE members.source_application_id (SQL 001). No F-005 tests were executed or added in this audit. Lock timeout/higher-isolation errors retain existing handling.

A distinct association-wide invariant needs separate investigation: validate_one_active_president checks IF EXISTS without an association serialization lock or President-specific uniqueness backstop. Two different application IDs do not share the F-005 lock. This is QA-009, a potential race, not a reproduced failure or evidence that F-005 fails its approved same-application scope.

## Input and schema mismatches

| Confirmed source issue | Exact evidence | Consequence / limitation |
|---|---|---|
| Acronym type preprocessing | web/app/Http/Requests/StoreAssociationRequest.php::prepareForValidation: filled then trim without string guard | Nonempty array can throw before validation; QA-011 |
| Member/application lengths | web/app/Http/Requests/{StoreMemberApplicationRequest,StoreMemberRequest}.php::rules versus SQL 001 member_applications/members | Names150/120, suffix50/20, contact50/30, Other-position150/100; controlled validation should match approved schema; QA-010 |
| Training active status | web/app/Services/TrainingService.php::validateParticipants versus SQL 001 validate_training_participant_association | Service lacks member_status=active; deployed trigger should reject, but friendly prevalidation is incomplete; QA-012 |
| Program Activity ongoing | SQL 001 status set versus web/database/migrations/2026_10_05_102947_update_program_activity_status_constraint.php | Current app may be correct only after the appropriate Laravel migration; live constraint unknown; QA-002 |

This audit does not widen fields, create statuses or alter constraints. Direct member create/update handlers are not in current routes; the application length mismatch is on an active submission path.

## Audit integrity and accountability

Stage A viewer reads real audit_logs with Admin-only route/controller checks, bounded pagination/filter options and permitted snapshot formatting. GIS writes are transactional producers. The shared AuditLogService::record has trusted-actor/sensitive-key/structure/length safeguards but no non-GIS workflow callers. Missing producers are confirmed; provider operational warnings are not equivalent audit events (QA-004).

AuditLog::booted rejects instance edits/deletes, not bulk/raw database operations. Runtime grants and any additional live immutability protections are unknown. The reviewed SQL does not enforce a full append-only application-role contract. Deletion replay protection depends on retained DELETE GIS events. actor_user_id ON DELETE SET NULL and current actor display_name mean immutable historical attribution is limited; ordinary rows are not cryptographic non-repudiation. Any stronger protection must reconcile actor deletion and retention rather than adding a blanket blocker blindly (QA-005).

Source: web/app/Models/AuditLog.php; web/app/Http/Controllers/Admin/AuditLogController.php; web/app/Services/{AuditLogService,GisLocationService}.php; supabase/migrations/001_assocmap_schema.sql::audit_logs.

## Remaining security indicators

- Parameterized Eloquent/raw spatial statements and whitelist-selected SQL columns resist common injection patterns in reviewed paths. No universal injection/XSS absence claim is made.
- Blade escaping, Js::from/hex-safe GIS seed JSON and textContent in the inspected DOM renderers provide XSS defenses. Browser payload/CSP tests remain pending. Source: web/resources/views/{gis-editor,projects/partials/location}.blade.php; web/resources/js/{core/gis-api,pages/gis-map,pages/admin/forms}.js.
- Routed browser write forms use CSRF protection; GIS fetches send same-origin credentials and CSRF headers. Source: web/routes/web.php; web/resources/views/field-officer/member-applications/index.blade.php; web/resources/js/core/gis-api.js.
- Login, invitation, GIS and audit throttles exist. /api/v1/me lacks an application limiter; upstream rate controls are unknown (QA-014). Source: web/app/Providers/{AppServiceProvider,GisServiceProvider}.php; web/routes/{web,api}.php; web/bootstrap/app.php.
- No implemented business upload handler was found; storage configuration does not prove file-upload validation. Source: inventoried controllers/requests and web/config/filesystems.php.
- SQL 001 RLS definitions/helper revokes and SQL 003/005/006/007 client-access restrictions support a server-mediated design. No client policies/grants should be assumed effective until operator verification. SECURITY DEFINER alone is not proof of public exploitability: helper execution revokes exist.
- Effective secrets, TLS, debug flags, keys and session protection were deliberately not read. Config defaults are not evidence of unsafe live settings. No credential values are included here.
- No independently confirmed Critical security exploit. Unknown npm advisories remain QA-008; obtain actual advisory evidence before attributing blame to a package.

See [production readiness](07_PRODUCTION_READINESS.md) for the required environment/grant/backup gates and [manual plan](10_MANUAL_VERIFICATION_PLAN.md) for proof obligations.

