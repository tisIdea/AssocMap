# Performance and Reliability Review

Audit date: 2026-10-13. **No benchmarks, browser requests, builds, database timing or EXPLAIN were executed.** The user reported approximately three-second GIS parent requests in browser Network. That is user-observed response duration; Auth, SQL, rendering and network contributions are unknown. This report makes no invented timing, payload-size or speedup claims.

## Source-supported request chain

Association change -> editor JavaScript clears project choices/site address and updates Registered Area reference -> GET role-specific /gis/parents?kind=project with selected association -> Supabase session middleware calls remote /auth/v1/user -> profile/role/invitation eligibility -> GisParentRequest -> GisWorkspaceController::parents -> GisQueryService::parents/options -> association/project/geographic joins with current assignment/link scope -> bounded options JSON -> current-controller/association/search guard -> dropdown enabled or empty/error/retry state.

Registered Area metadata travels with the authorized association options lookup, not a separate address request. Site Address is never overwritten by a lookup. Association and project lookups run concurrently when appropriate. Project requests use a key to avoid repeating already loading/ready/empty requests; AbortController and current selection/search guards reject superseded responses. Valid edit project selection is included as selected and may be preserved outside the bounded search page.

Source: web/resources/js/pages/gis-editor.js::{loadAssociations,loadProjects,updateRegisteredArea}; core/gis-api.js; EnsureSupabaseAuthenticated::handle; GisQueryService::{parents,options}. Status: source-reviewed; browser/provider/database timing Pending Verification. Manuscript: Chapter III Implementation and Performance Testing.

## Bounded GIS and audit paths

- Map requests require a valid bbox, retrieve at most 101 rows to determine truncation, return at most 100 features and enforce a 2 MiB feature budget. List mode paginates 20 records.
- Initial/fit map requests first call overview: a role/public-filtered aggregate extent returns four numeric bounds, not an unbounded geometry payload. The subsequent map request uses that viewport. Ordinary pan requests remain bbox-scoped.
- Readable geometry predicates cover 2D, 4326 SRID, validity/nonempty, type, vertex limit and world coverage. This performs database work and must be profiled; the geom GiST and parent/publication indexes exist, but actual planner use is unknown.
- Empty-state explanation may execute extra scoped existence checks after an empty response. It does not reveal private public-map counts, but its cost must be measured on larger datasets.
- Workspace load aborts previous data requests, compares filter/view/request URL, and suppresses programmatic-fit moveend reloads. Search/viewport timers are cleared to reduce duplicate loads.
- Filter option requests may run in parallel for geographic/association/project selectors. Each protected HTTP request still incurs its own Auth check; parallel HTTP does not mean zero remote overhead.
- Audit list pages permit 25/50/100 events, with bounded actor/distinct choices. Count/distinct/order work can grow even with a bounded response.

Source: GisQueryService::{features,overview,emptyReason,filter,options}; GisQueryRequest; resources/js/pages/gis-workspace.js::{load,loadOptions,applyBounds}; AuditLogController::index; SQL indexes. No changes to response bounds/pagination were made in this audit.

## Potential bottlenecks and reliability tradeoffs

| Source evidence | Finding / risk | Smallest next action | Approval boundary |
|---|---|---|---|
| Middleware performs remote getUser per protected request | Provider RTT/worker occupancy might dominate small lookups; not measured | Capture separate Auth and DB spans | Instrumentation/auth caching needs approval; never cache past revocation requirements speculatively |
| options selected lookup plus bounded search, geographical joins and DISTINCT | Up to a selected-ID query plus options query; cost unknown | Measure selected/unselected searches and plans | No index or query rewrite without measurements |
| Unbounded get() on organizational screens; monitoring in-memory search | Payload/memory/rendering growth (F-013) | Record eligible row count, compressed transfer and PHP peak memory | Server pagination changes page/modal contracts |
| projectLocations batches all loaded project IDs and returns every eligible address | Avoids per-project address queries but remains unbounded with the existing project list | Measure project/site counts and render size | Consider approved on-demand/paginated detail loading |
| GIS extent validates all matching geometries | Useful first viewport but potentially expensive aggregate | Measure extent independently from bbox request | No approximate/unscoped cached bounds |
| GIS read limiter by IP; write limiter by session profile/IP | Shared networks can affect aggregate limits | Check realistic shared-network behavior and 429s | Limit policy changes need approval |
| Ordinary getUser/signIn lacks explicit bounded request/error handling | Provider faults may become unhandled server errors (F-011) | Controlled outage/timeout tests | Do not alter authentication flows automatically |
| Supabase provisioning/password writes are deliberately not retried | Avoids duplicate identities/password writes; uncertain outcomes require reconciliation | Test provider success-with-lost-response and local completion recovery | Never add blind retries to non-idempotent external writes |
| GIS/activation have transactions/locks; membership decisions do not serialize status | Transaction presence alone does not cover every concurrent state transition (F-005) | Concurrent approval/rejection test | Lock/conflict patch requires approval |
| Dynamic GIS and editor-only Geoman imports | Source supports deferred loading; actual chunk sizes unknown | Inspect a fresh Vite build manifest/chunk report manually | Do not invent KB/savings or replace map libraries |
| Error logs are privacy-preserving but very generic | Difficult root-cause attribution (F-021) | Propose sanitized request correlation and failure-category metrics | Do not log credentials, tokens, bodies or sensitive SQL bindings |

## Exact runtime diagnostic protocol (user execution)

1. Use an approved staging dataset and record role, selected association/project UUIDs privately, eligible counts, filter/search state and source snapshot. Do not publish personal addresses or tokens in screenshots/HARs.
2. In browser Network, preserve the log and record association/project/overview/map/list request count, initiator, cancellation, queue/stalled time, connection/TLS, waiting/TTFB, download duration, transferred bytes and HTTP status. Test first load, rapid association changes, search and retries independently.
3. Run repeated samples and record sample count, median and p95; distinguish warm/cold state and concurrency. Do not label a single Network duration a database benchmark.
4. With a separately approved temporary instrumentation patch, measure spans for middleware getUser HTTP, user-profile/invitation queries, parent options queries, extent/map query, serialization and total application duration. Log duration/category/request ID only, not Authorization headers, provider bodies, credentials, passwords or personal-data bindings.
5. Inspect the database query plan manually using a reviewed read-only diagnostic procedure. Record planner index use, row estimates/actual rows, join/geometry predicate cost and server RTT. **No EXPLAIN or SQL was run by this agent; any plan capture is the user's separate manual action.**
6. Compare network TTFB with server total/span timings. Only then attribute latency or propose the smallest change. Keep scope/public predicates identical in any plan comparison.
7. Verify rapid changes display only the current association/project, failure differs from empty response, retained edit selections are valid, and the site address is not copied from Registered Area.

## Measurement sheet

| Test / request | Role / dataset | Samples | Queue | Auth HTTP | Profile/invitation DB | Business SQL | Serialization | Total / p95 | Bytes / rows | Evidence |
|---|---|---|---|---|---|---|---|---|---|---|
| Parent association options | Pending | | | | | | | | | |
| Project options | Pending | | | | | | | | | |
| Overview extent | Pending | | | | | | | | | |
| Viewport map | Pending | | | | | | | | | |
| Paginated list | Pending | | | | | | | | | |
| Project details/sites | Pending | | | | | | | | | |
| Audit list/filter | Pending | | | | | | | | | |

All values deliberately remain blank. Acceptance thresholds must be approved based on deployment and realistic workload, not invented after measurement. No caching, index, database, API contract or frontend behavior change was performed in this audit.

