# Safe verification — demo foundation and optional live public GIS

Use synthetic fixtures and explicit mock mode. No test requires Laravel or Supabase.
Terminal/device commands require user authorization. Dependency resolution was authorized
in Phases 1–2. Phase 3 separately authorized read-only backend/source inspection,
formatting, analysis, and Flutter tests; device launches/builds were not authorized.
The approved public GIS task prohibits execution; all its new tests remain unexecuted.
See development-status.md for historical execution results and
[public GIS integration](public-gis-integration.md) for exact current configuration,
contract, source inventory and the full live browser/device procedure.

## Automated commands

From `C:\dev\AssocMap\mobile`:

```powershell
flutter pub get
dart format lib test
flutter analyze
flutter test
```

The tests exercise local fixtures, synthetic AssetBundles, injected fake repositories
and mocked HTTP clients. They do not contact Supabase or a real backend. Public GIS
API tests exercise client behavior through fake transports; protected auth stays blocked.
Run pub get before checks to verify direct http dependency consistency.

## Device/UI checklist (not executed)

- [ ] Open welcome: correct app title, readable introduction, persistent mode banner.
- [ ] Open Public GIS without an account; confirm three synthetic public markers.
- [ ] Confirm draft/archive test scenarios never appear in the map or list.
- [ ] Pan/pinch/zoom and use zoom buttons; fit visible projects after exploring.
- [ ] Select each marker and each list item; read/close the correct public summary.
- [ ] Verify one polygon with a hole, two separate multipolygon parts, and a marker-only record.
- [ ] Toggle boundaries; markers and list remain usable.
- [ ] Search by association/project/area with different case and surrounding spaces.
- [ ] Filter by category; map, polygons, and list match. Clear search/filters to recover.
- [ ] Move between welcome, map, and association sign-in; real API sign-in remains blocked.
- [ ] Test empty data and invalid fixtures through an isolated fixture copy or fake repository.
- [ ] Test repository loading/failure/retry without real services.
- [ ] Start with ASSOCMAP_GIS_MODE=live and no backend URL after launch authorization:
  confirm safe configuration error, retry stays live and no mock projects appear.
- [ ] Test invalid mode values: configuration error, no silent default/fallback.
- [ ] Test small phones, landscape, tablets, enlarged text, keyboard, screen reader labels,
  touch targets, summary scrolling, back button, and orientation changes.
- [ ] Demonstrate with internet disabled; the local map/fixtures remain available.

No online basemap is expected. The graticule and synthetic geometries are not real
streets, official boundaries, location tracking, or an offline OSM tile cache.

## Current live public GIS checklist — all pending

Separate approval is required for test-service access/device launch. Use an approved
isolated reachable HTTPS backend, synthetic published records and explicit live GIS.
Keep authentication mock for this task. The complete commands/procedure are in the
[public GIS report](public-gis-integration.md).

- [ ] Run dependency resolution, formatting, analysis and all local fake-HTTP tests.
- [ ] Open without login; verify overview then bbox map GETs and exact public fields.
- [ ] Compare real test geometry, polygon holes/parts and list/marker/boundary selection.
- [ ] Submit server search/type filters; verify pan/zoom alone issues no requests.
- [ ] Search this view, recover from empty views, inspect partial/truncated results.
- [ ] Simulate offline/timeout/HTML/errors/throttle; no mock records appear on failure.
- [ ] Verify browser CORS/TLS/proxy behavior and actual Android debug networking.
- [ ] Rehearse small/landscape/tablet/keyboard/text-scale/back navigation.
- [ ] Restart explicitly in mock mode and verify fully offline demonstration.
- [ ] Record actual platform, backend revision, scenarios and results.

## Later integration checks

Deferred real-service checks: protected API routes, provider expiry/logout/refresh,
server association scoping, member submissions, typed endorsement, camera/gallery/photo
errors, secure tokens, protected HTTP/provider parsing and lifecycle behavior,
storage authorization, backend concurrency, and stakeholder UAT.
Prepare these only after module approval and the verified backend contracts are supplied.

## Phase 3 demo authentication device checklist (not executed)

- [ ] In debug mock mode, fill the valid synthetic identity and sign in; read DEMO labeling.
- [ ] Confirm only Workspace and Account shell navigation is available; modules show pending status.
- [ ] Enter an invalid email/empty password; verify local validation and password toggle.
- [ ] Wrong synthetic password produces the generic error without role/link disclosure.
- [ ] Administrator/Field Officer scenarios receive the required mobile-role denial message.
- [ ] Missing/inactive links, inactive/archived associations, and disabled profile/role are denied.
- [ ] The unavailable-service scenario offers retry and requires fresh password entry.
- [ ] Processing disables repeated submission; switching to public GIS cancels pending UI login.
- [ ] Confirm sign-out, return to public GIS, then try a protected route: login required.
- [ ] Simulate expiry or wait 15 minutes; protected content disappears, public GIS remains usable.
- [ ] Suspend/resume after expiry; verify the session-validity check clears access.
- [ ] In API mode, sign-in is disabled, no demo credentials appear, and no fallback occurs.
- [ ] Verify small-screen scrolling, landscape/tablet layout, keyboard, enlarged text,
  accessible labels, and back navigation on actual devices.

Source gates demo authentication in release builds. An actual release build and its
blocked-demo behavior require separate authorization and have not been verified.
