# Performance and Reliability Review

Assessment date: **2026-10-13 (Asia/Manila)**. No execution, benchmark, query plan, request trace, browser or generated asset inspection was performed. No throughput/capacity/speedup/bundle-size measurements are invented. User-reported earlier GIS lookups of approximately **three seconds** are end-to-end observations with unknown attribution; an older reported ten-second dropdown symptom is historical context, not a current benchmark.

## Source request chain and state

Association selection -> gis-editor.js updates selected association and clears obsolete project choices/site address -> asynchronous GET role-specific /gis/parents?kind=project with association_id -> remote Supabase getUser in authentication middleware -> local profile/role/invitation checks -> GisParentRequest -> GisWorkspaceController::parents -> GisQueryService::parents/options -> scoped project join and bounded query -> JSON -> only current request/association/search result populates dropdown.

Source: web/resources/js/pages/gis-editor.js::{loadAssociations,loadProjects,setProjectState,loadParents}; web/resources/js/core/gis-api.js; web/app/Http/Middleware/EnsureSupabaseAuthenticated.php::handle; web/app/Models/UserProfile.php::canAccessApplication; web/app/Http/Requests/GisParentRequest.php; web/app/Http/Controllers/GisWorkspaceController.php::parents; web/app/Services/GisQueryService.php::{parents,options}.

The frontend immediately uses Loading projects..., disables the project selector, provides genuine empty/error/retry states, aborts superseded requests and compares controller, association and search values before applying results. A request key avoids redundant loading/ready/empty lookups. Selected edit values can be preserved separately from bounded search results. Association and project lookups run in parallel when appropriate; role/parent validation stays on the server. Registered Area metadata accompanies authorized association options; it is not automatically copied into the project-site address.

Geoman's stopGeometryModes checks active draw/edit/removal modes before disable calls. updateMode avoids repeated control add/remove when already in the correct state. Current project point placement and Polygon/MultiPolygon code paths exist; this does not prove absence of the earlier console warning in installed browsers. Source: gis-editor.js::{stopGeometryModes,updateMode,renderGeometry,trackLayer} and web/resources/js/pages/gis-map.js. No dependency internals or console suppression were modified.

## Query and capacity evidence

| Path | Source-supported control | Remaining cost/risk | Required evidence |
|---|---|---|---|
| GIS map | bbox envelope operator plus guarded intersection; 101 lookahead rows, 100 feature return, 2 MiB serialized-feature budget | Geometry predicates/joins/sorting may still be costly; the budget excludes response-wrapper overhead | Query timings/plans, payload bytes, truncated response behavior |
| GIS list | paginate 20 | Count/order may grow; arbitrary high page numbers still need measurement | Representative dataset query counts and plans |
| GIS overview | Scoped ST_Extent aggregate returns four bounds; bounded map request follows | Dataset-wide extent/validity aggregate cost is not eliminated by bounded output; repeated identical aggregate expressions do not prove repeated scans | Aggregate plan/timing under each role/filter |
| Parent options | limit51 -> 50 choices; separately includes eligible selected record; search column whitelist/bindings | Up to a selected lookup plus options query; geographic joins/DISTINCT and wildcard search | Selected/unselected/search traces; current cardinalities/index plans |
| Workspace filters | Parallel option requests, debounced/aborted state | Each protected request repeats provider identity verification and local profile/invitation queries | Request count and stage spans |
| Org directories | Eager related data in Association/Project/Member controllers | Entire collections/options and HTML/JS data loaded; no server pagination | Row/relationship counts, DB query count, PHP memory, HTML size |
| Project site summary | Batch projectLocations query grouped by project | All active scoped locations for all loaded projects; no summary bound | Location count, memory and payload per page |
| Monitoring search | Eager project/association/quarter/actor and selected year | get then in-memory filter rather than SQL pagination/search | Query/collection/HTML timings and size |
| Audit viewer | Page 25/50/100; actor/distinct choices limit100 | Count/distinct/order cost still grows | Dataset-size traces and actual plans |
| Assets/mobile | app.js defers GIS module; editor imports Geoman; Flutter counter only | Current chunks/runtime memory unmeasured; scaffold is not a mobile performance result | Personal Vite artifact review/browser profile; mobile tooling only establishes starter status |

Sources: web/app/Services/GisQueryService.php::{features,filter,overview,options,projectLocations}; web/app/Http/Requests/GisQueryRequest.php; web/app/Http/Controllers/Admin/{AssociationController,MemberController,AuditLogController}.php::index; FieldOfficer/ProjectController.php::index; MonitoringWorkspaceController.php::index; web/resources/js/{app,core/gis-api,pages/gis-workspace,pages/gis-editor}.js; mobile/lib/main.dart.

SQL 001 defines the geom GiST and parent/publication/reference indexes. Source can establish their definitions and an index-compatible bbox predicate, **not actual planner/index use**. No new index/cache design is justified without plans/cardinalities. Reporting query efficiency is not evaluable because generation/aggregation is not implemented.

## Transaction/retry/failure assessment

| Workflow | Current source behavior | Reliability limit / test |
|---|---|---|
| F-005 same application | approve/reject lock fresh application in transaction; authorizes before pending; writes atomic | MEM-02 with two independent sessions/workers and overlapping requests; default transaction attempt; higher-isolation/lock errors not specially mapped |
| Cross-application membership | Different application locks; active-President trigger checks existing members | MEM-03 potential association-wide race (QA-009); no observed failure claimed |
| GIS write/delete | Actor/role/parent/version/advisory locks, geometry checks, audit atomic; DB transaction3 attempts | Real PostGIS/dependency/permission/rollback/replay tests absent |
| Registration creation | Association + optional registration in one transaction; active agency shared lock; unique violation controlled | Real trigger/duplicate/rollback tests absent; existing registration editing not implemented |
| Project resources | Resource writes in create/update transaction; history prechecks and relationship guards | Some ordinary update prechecks occur before transaction with supplied models; no universal version conflict guarantee. Test concurrent resource edits/Monitoring creation before claiming historical-scope robustness |
| Monitoring sections/visits | Locked fresh record/resources/period checks; several transaction3-attempt paths | No real READ COMMITTED monthly-trigger/concurrency test evidence |
| Invitation/provider writes | Local claims surround nonretried external invite/OTP/password changes; uncertainty retained | Cannot roll back external provider side effects with SQL; real receipt/reconciliation/template contract unknown |
| Activation finish | Locked local role/association/invitation/profile and atomic local writes; confirmed recovery context | Persistent store/locks and actual SMTP/password/DB failure recovery unverified |
| Ordinary Auth | Remote getUser per protected request; default client behavior unless specified | signIn/getUser have no own timeout/ConnectionException path matching activation; provider failure tests and timings needed |
| Data restoration | Proposed readiness checklist exists | No demonstrated backup/restore, RPO/RTO, failover or operational acceptance |

Sources: web/app/Services/{MemberApplicationService,GisLocationService,AssociationService,ProjectService,MonitoringService,UserInvitationService,InvitationActivationService,InvitationActivationContext,SupabaseAuthService}.php and supabase/migrations/005_add_monitoring_monthly_production_and_conductors.sql. This table does not claim all exceptions are handled or all organizational writes are serialized.

## Exact diagnostic procedure — user execution only

1. Use an approved sandbox, retain current-source identity, hosting/worker layout, relevant package/runtime versions and network conditions. Use existing approved nonconfidential fixtures; this audit generated no data.
2. In browser Network, preserve request ordering. Record association change time, parents URL parameters, cancelled/duplicate requests, start/end/duration, queue/connect/TLS/TTFB/download timing, status, content type and safe response sizes. Strip cookies, authorization headers, capability fragments and private names before sharing.
3. Observe A -> B -> A switches and search/retry interactions. Confirm obsolete options clear immediately, the selector stays disabled during loading, and only the selected association's latest response is applied. Verify edit selected-project preservation and genuine empty state.
4. Have the operator capture one redacted request correlation ID with separate measurements for provider getUser, local profile/invitation checks, parent SQL, serialization and total server time. Instrumentation requires separate approval; do not log tokens, SQL bindings, private rows or geometry.
5. Capture SQL statement counts and approved redacted query plans on representative existing staging data. Include selected option lookup, ordinary option query, public/internal extent, bbox geometry query, organizational list and Monitoring search.
6. Measure worker occupancy/concurrent traffic, memory, returned rows, HTML/JSON/asset bytes and client render/main-thread time. Confirm DB region/network path versus application host rather than assuming locality.
7. Compare layers: browser request queuing versus server spans versus Auth versus SQL versus serialization/rendering. A three-second request alone cannot identify the responsible layer.
8. Define accepted prototype/production thresholds with the owner; none are supplied here. Retain raw redacted observations before proposing caching, indexes or pagination changes.

## Blank measurement ledger

| Scenario | Request count | Client duration | Server total | Auth duration | Local access queries | Parent/spatial SQL | Rows / bytes | Memory / render | Actual result |
|---|---|---|---|---|---|---|---|---|---|
| First project lookup | | | | | | | | | |
| Association rapid switch | | | | | | | | | |
| Search / selected edit project | | | | | | | | | |
| Public overview -> viewport | | | | | | | | | |
| Assigned FO/org/Monitoring list | | | | | | | | | |
| Concurrent member decisions | | | | | | | | | |

All performance/reliability outcomes remain **Requires runtime verification**. Cross-references: [ISO matrix](02_ISO_IEC_25010_ASSESSMENT.md), [findings](09_PRIORITIZED_FINDINGS.md), [manual plan](10_MANUAL_VERIFICATION_PLAN.md).

