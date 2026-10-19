# Desktop 1 handoff — backend requirements

Phase 3 inspected Laravel authentication, route/controller/model source and relevant
repository SQL as read-only references. No live service, real record, or credential
was accessed. [Current readiness findings](friday-prototype-readiness.md) records the
updated source contracts and remaining integration work; deployed availability remains unknown.
This document requests missing contracts and does not invent endpoints. Desktop 1 must validate scope
against the approved manuscript and Appendix N before protected modules are implemented.

## Public GIS — source adapter implemented, runtime confirmation pending

Read-only route inspection confirms declared public `GET /map/data` and
`GET /map/associations` routes in `web/routes/web.php`. The readiness audit also inspected
`/map/data` request rules, FeatureCollection/public serializer, and map/list limits;
see [the implemented Flutter adapter](public-gis-integration.md). It uses only /map/data
in overview/map modes and preserves actual Point/Polygon/MultiPolygon geometry and holes.
/map/associations is not consumed. Source is wired, but all new tests and live integration
are unverified. Confirm deployment/base URL and supply sanitized isolated test examples.

Confirm:

- Public calls require no token, cookies, or CSRF session.
- The server returns only the authorized public projection, excluding unpublished and
  archived records according to existing server rules.
- Which public labels and descriptions stakeholders approve; exclude personal data,
  internal actor IDs, management metadata, and private association addresses.
- Stable public identifiers and whether records represent projects, associations, or
  multiple locations per project. The current DTO is a replaceable presentation model.
- Deployment matches inspected WGS84 two-dimensional GeoJSON: project_point → Point;
  project_boundary → Polygon/MultiPolygon with holes; positions use longitude/latitude.
- Pagination, total counts, limits, viewport queries, search/category filters, maximum
  geometry payload, refresh behavior, caching headers, and withdrawn-publication handling.
- Empty response, malformed geometry policy, partial failures, status codes, validation
  error format, retry limits, and rate limits.
- CORS requirements for Flutter web previews, TLS, allowed test environments, and a
  separately approved basemap provider. Public business data and basemap tiles are
  separate services.

Current live fields are Feature.id, geometry and properties.location_type/title/address/
description. Association names and livelihood categories are absent and omitted from the
live UI. Null descriptions show an honest missing-description message. A published
boundary renders its real geometry without a fabricated centroid/point.

## Authentication contract — before Phase 3 integration

Association Accounts are association-linked users, not a login per association member.
Source confirms `GET /api/v1/me` with Supabase bearer middleware, returning profile
identity, role, `is_active`, and now `mobile.eligible`, `mobile.reason`, and the
authorized `mobile.association` ID/name. Active links and association lifecycle are
resolved server-side. HTTP 200 with eligibility false is a valid mobile denial.
Browser login obtains Supabase tokens and stores them in a Laravel session; browser
logout invalidates that session without provider revocation. No mobile refresh/logout
contract was found. Current detailed response/errors and evidence are in the readiness report.

Desktop 1 must confirm the reference contract/deployment, approve mobile token acquisition, and specify
expiry/refresh/revocation/401/403/outage behavior. A new Laravel login endpoint is not
automatically necessary if an approved Supabase public-client flow is selected.
Do not assume web cookies/CSRF work on mobile. Enforce association scope in every
protected business endpoint; a Flutter guard is additional presentation protection.

The public-only HTTP client does not enable protected workflows. Choose provider and
secure token storage only after authentication/session architecture approval. Phase 3 implements a demo form/session/guard and a parser for
the existing profile response, with real sign-in disabled. No real token exchange,
refresh implementation, token persistence, or local user database exists. Supply synthetic test identities and
an isolated approved test environment for later integration; never send privileged keys.

## Protected module contracts — provisional pending approved scope

For each approved module, supply route/method, authorization, association scope,
pagination/filter rules, request and response examples, error cases, and concurrency
behavior. Candidates are dashboard/profile, member roster, member applications,
projects, training, monitoring, and production/income. Do not treat this list as a
request to create all endpoints or allow new write operations.

If member applications are confirmed, specify applicant fields and validation,
status lifecycle, permitted actions, typed endorsement requirements, officer authorship
and stakeholder approval, applicant-photo JPEG/PNG and size constraints, multipart
or other upload format, progress/failure handling, idempotency, and signed/authorized
photo retrieval. No file-size value or database field is invented by mobile.

Server authority includes at most one active nonarchived President and Vice President,
permitted vacancies, multiple holders for other positions, and pending applications
not reserving an executive seat, as described by the supplied request. Desktop 1 must
confirm the deployed enforcement. Mobile will display server validation; it must not
simulate a database uniqueness guarantee or reserve positions independently.

## Proposed integration review process

1. Desktop 1 supplies sanitized contract examples and approved scope explicitly.
2. Desktop 2 maps the contract to public DTOs and repository interfaces.
3. Implement adapters only for verified routes; keep explicit source selection.
4. Add parser/HTTP tests using sanitized fixtures and a fake transport; no production calls.
5. Obtain separate authorization for integration against an isolated test environment.
6. Verify publication, association scoping, auth expiry, errors, and applicable concurrency.
7. Record actual results in the integration checklist; do not infer success from source.

Member submission's future flow is user/form → local usability checks → authenticated
Laravel request → server validation/authorization → transactional database/storage
operation → canonical response/errors → mobile feedback. That flow is not implemented.
