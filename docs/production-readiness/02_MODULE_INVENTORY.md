# Module Inventory and Permissions

Audit date: 2026-10-13. **Implemented in source does not mean functionally tested.** All current runtime status is Pending Verification. No test/build/check was executed in this audit. Earlier user-reported GIS build/lint/route checks are historical context, not evidence for the latest snapshot.

## Implementation-status register

| Module | Actual implementation | Status | Main evidence | Remaining gap / required proof |
|---|---|---|---|---|
| Staff authentication | Supabase password sign-in, profile/role/invitation eligibility, Laravel session rotation and local logout | Implemented in source | Auth/StaffAuthController; SupabaseAuthService; EnsureSupabaseAuthenticated; UserProfile::canAccessApplication | Real provider response, expiry, outage, cookie and logout tests |
| User invitations | Authorized Administrator POST endpoint, reservation/claim/reconciliation state machine, inactive profile provisioning | Backend implemented; Administrator form UI-only | UserInvitationService::initiate; StoreUserInvitationRequest; routes/web.php; admin/shared/form.blade.php | Form is disabled/disconnected; actual SMTP/provider contract and reconciliation handling unverified |
| Invitation activation | Fragment hash verification, recipient identity verification, encrypted expiring context, password claim, local atomic activation/link/acceptance and confirmed-context recovery | Implemented in source; isolated test source exists | InvitationActivationController; InvitationActivationService; InvitationActivationContext; auth/invitation-layout.blade.php | Actual email template/callback, persistent session locks and provider/database end-to-end tests |
| Account directory/edit/disable/email changes | Generic admin shell/fields; no dedicated account lifecycle controller/route found | Placeholder/partial | admin/users/*.blade.php; admin/shared/{index,form,show}.blade.php; routes/web.php | Do not claim full User Management administration merely because invitations/activation exist |
| Area Management | Municipality, barangay, specific-area read/create/update/deactivate with geographic hierarchy | Implemented in source; city creation partial | Admin/MunicipalityController, BarangayController, AreaController and corresponding services/requests; SQL 002 | locality_type=city is readable in stored data; add form has no City selector, and creation does not supply locality_type. Approved city-entry workflow needs clarification |
| Association Management | Admin index/create/update/archive/unarchive, administrative area and Field Officer assignment | Implemented in source | Admin/AssociationController; AssociationService; StoreAssociationRequest | Browser/database checks and malformed input handling |
| Association registrations | Admin optional single documented initial registration; multi-record detail display; existing records not rewritten by association editor | Implemented in source; limited scope | AssociationRegistration, RegistrationAgency; AssociationService::recordRegistration; association modal | No approval-status workflow or general existing-registration CRUD; verify deployed constraints/permissions |
| Member applications | Association Account submission from active link; assigned Field Officer approve/reject; approval creates member/classifications | Implemented in source | MemberApplicationService; AssociationAccount/MemberApplicationController; FieldOfficer/MemberApplicationController | Concurrent decisions need atomic-state hardening; real SQL trigger/uniqueness tests |
| Official members | Administrator directory, archive/unarchive; membership creation through approval | Implemented in source; route-limited CRUD | Admin/MemberController; MemberService; routes/web.php | Do not infer unrouted create/update methods are usable screens; PII access and integrity tests |
| Projects/materials/products | Admin/assigned FO create/update/archive/unarchive; resource synchronization; monitoring-history protections; shared detail modal | Implemented in source | ProjectService; Admin/ProjectController; FieldOfficer/ProjectController; admin/projects/index.blade.php | Ownership/concurrency/archive tests and dataset scaling |
| Project Location display | Multiple eligible active project GIS addresses plus distinct Registered Area; lookup failure differs from absence | Implemented in source | GisQueryService::projectLocations; projects/partials/location.blade.php | Source/verification not stored; legacy addresses need human review |
| Training/participants | Admin/assigned FO CRUD/archive with activity, related-project and participant scope | Implemented in source | TrainingService; Admin/TrainingController; FieldOfficer/TrainingController; SQL participant trigger | Align service active-status validation with trigger; concurrency and inactive-member tests |
| Program Activities | Admin association-specific or program-wide events; FO assigned-association events; archive/unarchive | Implemented in source | ProgramActivityService; corresponding controllers; Laravel status migration | Baseline SQL/current status constraint reconciliation; actual valid activity selection |
| Monitoring | Quarterly records; overview visits/conductors; production/monthly detail; income/material sections; role-scoped workspace | Implemented in source | MonitoringWorkspaceController; MonitoringService; SQL 003-005; monitoring/*.blade.php | Physical PostgreSQL triggers, cross-project IDs, numerical/period integrity and UAT |
| GIS | Project Point/Boundary create/edit/hard-delete/publish/unpublish/archive, internal/public fields, bounds, parent lookups, register and role scopes | Implemented in source; isolated geometry/serializer/scope tests | GisQueryService, GisLocationService, GisGeometry; GIS requests/views/JS | PostgreSQL/PostGIS mutation/rollback/concurrency and real browser tests |
| Audit Logging Stage A | Administrator read-only filtered/paginated viewer; metadata/snapshot service; actual GIS producer writes | Viewer/helper implemented; coverage partial | AuditLogController; AuditLogService; AuditLog; GisLocationService audit inserts | Non-GIS producer coverage missing; database append-only and retention unverified |
| Reports and Analytics | Scope/type/filter visibility, placeholder preview and disabled exports | Placeholder/UI-only | admin/reports/index.blade.php; pages/admin/reports.js | No aggregation/export routes/services; Chart.js not implemented |
| Dashboard | Admin shell/cards, counters supplied as zero | Placeholder statistics | routes/web.php::admin.dashboard; admin/dashboard.blade.php | Real scoped metrics absent |
| Public website | Public landing pages and separate eligible published project map | Source implemented; landing preview illustrative | routes/web.php; PublicGisController; public/gis.blade.php; public/sections | Landing preview is not evidence of live mapped data; tiles/headers/network checks pending |
| Versioned API | Bearer-authenticated profile response only | Partial API foundation | routes/api.php; Api/V1/MeController; EnsureSupabaseApiAuthenticated | No organizational/mobile CRUD API; application limiter missing |
| Flutter mobile | Material counter starter and widget smoke test | Placeholder; organizational features not implemented | mobile/lib/main.dart; pubspec.yaml; widget_test.dart | Auth, secure storage, networking, GIS, monitoring and mobile QA absent |

## Source-supported role-permission matrix

Legend: M = manage through routed handlers; R = read; S = submit; A = current association assignment (record eligibility varies by handler); L = active association link; P = eligible published project GIS; — = no implemented route/capability found. This is implementation evidence, not an approved requirements replacement.

| Capability | System Administrator | Field Officer/CDO | Association Account | Public Visitor |
|---|---|---|---|---|
| Staff login / own profile API | Yes | Yes | Yes | No |
| Invite FO / Association Account | Backend endpoint only | — | — | — |
| Provision Administrator invitation | — in current invitation workflow | — | — | — |
| Area directory create/update/deactivate | M | Context via own scoped modules | Context via linked GIS | — |
| Association create/update/archive/assign officer | M | — | — | — |
| Initial registration entry/view | M/R | No detail page found | No detail page found | — |
| Official member directory/archive | M/R | Member context in assigned workflows | Submitted application status | — |
| Submit member application | — in active routes | — | S/L | — |
| Approve/reject member application | — in active decision routes | M/A | — | — |
| Project/material/product CRUD/archive | M | M/A | No Project Details page found | — |
| Training/participants CRUD/archive | M | M/A | — | — |
| Program Activities | M including permitted program-wide entries | M/A | — | — |
| Monitoring writes | M | M/A | — | — |
| Monitoring reads | R | R/A | R/L (parent history predicate differs from GIS) | — |
| GIS create/edit/delete/publish/unpublish/archive | M on eligible project locations | M/A on eligible project locations | — | — |
| GIS internal reads / registered area context | R | R/A | R/L, no actor/lifecycle controls | — |
| Public GIS | P | P through public endpoint | P through public endpoint | P |
| Audit viewer | R only | — | — | — |
| Generated reports/exports | Not implemented | Not implemented | Not implemented | Not implemented |
| Full Flutter workflows | Not implemented | Not implemented | Not implemented | Not implemented |

## Important workflow boundaries

Association Account is an organizational login profile, not automatically an individual official Member record. Public Visitor does not need an auth.users identity. Current invitation workflow permits only FO/Association Account roles and must not fabricate an admin bootstrap workflow.

GIS parent eligibility is stricter than some organizational history views. Do not harmonize scopes without approved rules. Association/project archival is separate from permanent GIS deletion. Old association_point rows remain in PostgreSQL but normal project-only GIS reads/mutations exclude them.

Association/Project details are reused components: admin association modal and shared admin/FO project modal. No FO/Association Account association detail routes or Association Account project detail route were found; source inventory does not authorize new private-data pages.

## Verification classification

- **Implemented in source:** code/route/schema mapping is present.
- **Partially implemented:** only some intended layers/workflows exist.
- **Placeholder/UI-only:** controls/text have no functional data/write path.
- **Technically checked:** no current artifact collected; prior user statements describe an older GIS iteration only.
- **Functionally tested:** no current runtime evidence established in this audit.
- **Not implemented:** report generation and organizational Flutter features.
- **Unverified:** effective environment, provider policies, live schema, deployment and all current runtime outcomes.

