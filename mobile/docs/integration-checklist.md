# Desktop 1 / Desktop 2 integration checklist

Updated 2026-10-14 (Asia/Manila). Deployed backend availability is **unknown**.
Phase 3 source inspection confirms the profile bearer API and public GIS route declarations.
The readiness audit confirms mobile eligibility in backend source. Public GIS HTTP/DTO/
viewport source is now implemented; new tests are unexecuted. Authentication provider/
session adapters, token-lifecycle policy, deployment and device integration remain unverified.
“Proposed” describes display needs, not existing API fields. No backend integration is verified.

| Required capability / endpoint | HTTP method | Authentication | Request needs | Response needs | Backend availability | Mobile implementation | Dependencies / verification |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Public GIS: `/map/data` | GET | None; client adds no credentials | Overview extent then map bbox; q/location_type; explicit viewport reload | FeatureCollection public id/title/address/description/location_type, Point/Polygon/MultiPolygon, bounds/limit/truncated/empty_reason metadata | Source inspected; deployment unknown | Mock retained; bounded live adapter, DTOs and UI implemented | HTTPS test URL/public fixtures/CORS; new fake-HTTP tests unexecuted; backend/device verification pending |
| Public options: `/map/associations` | GET (source) | None | kind=association, optional q/selected | options public location ID/title plus truncated | Source inspected; deployment unknown | Not consumed; outside current adapter | No mobile implementation or runtime verification |
| Mobile token acquisition — provider or Laravel broker TBD | TBD after architecture approval | Email/password mechanism requires approval; browser POST `/login` exists | Exact mobile request/config TBD | Provider session plus authoritative eligibility required | Provider password-grant helper exists in web source; no mobile broker contract | Demo form/repository functioning; real sign-in disabled | Token architecture and lifecycle approval; local tests pass, real integration unverified |
| Current profile: `/api/v1/me` | GET | Supabase user access JWT bearer | No body; Authorization and Accept headers | Identity/role/is_active plus `data.mobile.eligible`, reason, association id/name | Current source includes eligibility; deployment unknown | Updated typed parser prepared; no HTTP adapter | Fresh parsing tests authored, unexecuted; no live integration |
| Association mobile eligibility — included in `/api/v1/me` | GET | Verified identity; profile/role/invitation/current link checks | Actor and association derived server-side | Eligible true + association or eligible false + reason + null association; HTTP 200 may deny | Implemented in inspected service; deployment unknown | Demo guard exists; real DTO mapping not connected | Confirm current backend contract/deployment; enforce scope on future protected business APIs |
| Refresh — route/provider lifecycle TBD | TBD | Exact refresh/token rotation design TBD | Refresh schema TBD | Expiry/rotation/errors TBD | No implementation found in inspected auth source | Demo expiry timer and resume check; no real refresh | Auth lifecycle and secure storage selection; real behavior unverified |
| Logout / revocation — mobile lifecycle TBD | TBD | Authenticated; mechanism TBD | Revocation payload TBD | Local versus provider/global semantics TBD | Web POST `/logout` invalidates browser session only | Demo local logout, route cleanup, public GIS return | Local tests pass; provider revocation contract/runtime unverified |
| Association dashboard / profile — business paths TBD | TBD | Association Account, subject to confirmed server scope | Association scoping/filter rules TBD | Authorized identity/summaries; keys TBD | Unknown | Minimal protected shell only; business modules pending | Manuscript/module confirmation and contracts; no real records/operations |
| Member roster — path TBD | TBD | Association Account if approved | Pagination, visibility, filters TBD | Authorized roster fields TBD | Unknown | Provisional; not implemented | Approved access scope; unverified |
| Application list / details — paths TBD | TBD | Association Account if approved | IDs, pagination, filters TBD | Allowed details/status/errors TBD | Unknown | Provisional; not implemented | Approved module and status lifecycle; unverified |
| Application submission — path TBD | TBD; write operation if approved | Association Account if approved | Applicant/endorsement/photo schema TBD | Canonical submission/status/validation TBD | Unknown | Provisional; not implemented | Server rules, transaction/idempotency behavior, Phase 4 review; unverified |
| Photo submission / retrieval — paths TBD | TBD | Authorized association workflow if approved | MIME, size, upload format TBD | Authorized retrieval/upload response TBD | Unknown | Not implemented | Storage contract; no privileged Flutter keys; unverified |
| Projects/training/monitoring/income — paths TBD | TBD | Association Account if approved | TBD after scope review | Authorized module data TBD | Unknown | Provisional; not implemented | Approved modules and read/write authority; unverified |

## Integration acceptance checks (all pending)

Historical local demo/unit/widget results are recorded in [development status](development-status.md).
Latest parsing/configuration/public GIS changes are unverified; no tests were run in
these source-only tasks. See [public GIS verification](public-gis-integration.md).
The checks below concern actual backend/device integration and remain pending.

- [ ] Approved manuscript/Appendix N shared as explicit reference files.
- [ ] Every route, method, request/response schema, pagination rule, and error schema verified.
- [ ] Public GIS works with no authentication token and exposes approved fields only.
- [ ] Unpublished/archived records are excluded by Laravel, including cached/withdrawn records.
- [ ] Login restricts mobile workflows to Association Accounts as approved.
- [ ] Cross-association access is denied by the backend, including detail and photo endpoints.
- [ ] Expiry, refresh, revocation, 401/403, and logout are verified on the isolated test service.
- [ ] API timeout/unavailability produces an error and never synthetic fallback.
- [ ] Photo validation and authorized retrieval are verified if in scope.
- [ ] Executive conflicts and concurrent submissions are handled by server authority if in scope.
- [ ] Separate approval obtained for live test-service access; no production records used.
- [ ] Actual device/platform and stakeholder UAT results recorded.
