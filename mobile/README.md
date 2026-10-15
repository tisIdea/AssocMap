# AssocMap mobile

Flutter development prototype with public welcome/GIS, synthetic demo Association
Account sign-in and protected navigation. Public GIS now has an optional Laravel
adapter implemented in source. No login is needed for the public map. **Current
formatting, analysis, tests, live backend integration and device behavior are unverified.**
Real authentication and association management modules remain pending.

Mock fixtures contain fictional associations, locations and boundaries, not BFAR/SAAD
records. The offline coordinate grid is not a street basemap or authoritative geography.
Live mode renders only supplied public fields and actual Point/Polygon/MultiPolygon
geometry; polygon parts and holes are preserved without invented markers.

## Environment and dependencies

Previously inspected development SDK: Flutter 3.44.6 / Dart 3.12.2. Project SDK
requirement: ^3.12.2. Mapping packages: flutter_map 8.3.2 and latlong2 0.10.1.
This task declares the already resolved http 1.6.0 directly. No dependency resolution
was executed by Codex. The current lockfile already marks it direct; run flutter pub
get when authorized to verify dependency consistency.
Keep pubspec.lock when transferring the workspace.

## GIS and authentication modes

- ASSOCMAP_GIS_MODE=mock: existing local fixtures; works without Desktop 1/network.
- ASSOCMAP_GIS_MODE=live: HTTPS GET /map/data overview and viewport map queries.
- ASSOCMAP_DATA_MODE=mock|api: existing authentication selection, unchanged.
- Without a GIS define, mock data mode selects mock GIS and api selects live GIS.
- Unknown modes fail closed. A failed live request never substitutes synthetic data.
- Changing defines requires restarting the app.

ASSOCMAP_BACKEND_BASE_URL is the approved HTTPS Laravel application base URL, optionally
with its application prefix. Do not append /map/data or /api/v1; do not include
credentials, queries or fragments. No hostname is hardcoded. Missing/invalid live
configuration produces a safe error without a request.

When device launch and any test-service access are separately authorized:

    flutter run --dart-define=ASSOCMAP_DATA_MODE=mock --dart-define=ASSOCMAP_GIS_MODE=mock
    flutter run --dart-define=ASSOCMAP_DATA_MODE=mock --dart-define=ASSOCMAP_GIS_MODE=live "--dart-define=ASSOCMAP_BACKEND_BASE_URL=<approved-https-base-url>"

Replace the URL placeholder locally with the approved isolated backend. These commands
were not executed. Live GIS with mock authentication does not enable real login.

In debug/profile mock authentication, open Association access, choose a synthetic
scenario and use Fill demo credentials. Do not enter genuine credentials. Demo
sessions last 15 minutes, are not persisted, and are disabled in release builds.

## Documentation

- [Current public GIS implementation, exact files/contracts and verification procedure](docs/public-gis-integration.md)
- [Architecture, flow, security and defense notes](docs/mobile-architecture.md)
- [Desktop 1 backend handoff](docs/backend-contract-requirements.md)
- [Historical Phase 3 authentication findings and demo guide](docs/phase-3-authentication.md)
- [Original Friday readiness audit and approved plan](docs/friday-prototype-readiness.md)
- [Current integration checklist](docs/integration-checklist.md)
- [Safe manual verification](docs/manual-verification.md)
- [Development status and historical execution records](docs/development-status.md)

Current Laravel reference source includes eligibility and association context in
GET /api/v1/me. Its parser is prepared, but real authentication remains blocked.
No approved manuscript/Appendix N was available in the previous audit.
The historical 63-test pass applies to earlier source. Newly authored fake-HTTP,
geometry and widget tests have not been executed. No backend/Supabase modifications,
live requests, builds, migrations or device launches were performed in this task.
