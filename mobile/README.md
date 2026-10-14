# AssocMap mobile

Phase 1–3B Flutter development prototype: public welcome, offline GIS demonstration,
synthetic project summaries, demo Association Account sign-in, protected navigation,
and centrally selected repositories. No login is needed for the public map. Real
authentication and association management modules remain pending.

The fixtures contain fictional associations, locations, and boundaries. They are not
BFAR/SAAD records. The coordinate grid is not a street basemap or authoritative geography.

## Environment and dependencies

Development SDK inspected: Flutter 3.44.6 / Dart 3.12.2. The project requires Dart
`^3.12.2`. Mapping uses pinned `flutter_map` 8.3.2 and `latlong2` 0.10.1.
Keep `pubspec.lock` when transferring the mobile workspace to another computer.

## Data modes

`ASSOCMAP_DATA_MODE` is selected at compile time and defaults explicitly to `mock`.
For a future authorized device launch:

```powershell
flutter run --dart-define=ASSOCMAP_DATA_MODE=mock
flutter run --dart-define=ASSOCMAP_DATA_MODE=api
```

These launch commands are instructions, not a claim that a device was launched.
API mode currently shows a missing-contract error and makes no HTTP requests. Unknown
mode values fail closed. A failed API operation never switches to synthetic data.
Changing the compile-time mode requires restarting with the chosen define.

In development mock mode, open Association access, choose a synthetic scenario,
use **Fill demo credentials**, and sign in. Do not enter genuine credentials. Demo
sessions last 15 minutes and are not persisted. Demo authentication is disabled in
release builds. Full scenario details are in the Phase 3 reference.

## Technical references

- [Architecture, data flow, security, and defense notes](docs/mobile-architecture.md)
- [Desktop 1 backend requirements](docs/backend-contract-requirements.md)
- [Phase 3 authentication findings, demo guide, and Desktop 1 handoff](docs/phase-3-authentication.md)
- [Integration checklist](docs/integration-checklist.md)
- [Safe manual verification](docs/manual-verification.md)
- [Phase status, verification record, and exact authored file inventory](docs/development-status.md)

The supplied mobile requests are the current scope references. No approved manuscript
or Appendix N was found. Phase 3 read-only backend inspection confirmed the source
contract of `GET /api/v1/me`; it does not provide association eligibility, and its
deployment has not been contacted or verified.
Do not treat provisional modules or this prototype as approved production integration.
