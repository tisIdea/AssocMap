# Mobile proposed UI implementation

Reference: the user's 10-page `Mobile UI Proposed AssocMap.pdf`, reviewed visually
on 26 October 2026. The user's current instructions override the reference's
BFAR branding, illustrative records, sample labels and street basemap.

## Pre-implementation comparison

| Page | Proposed presentation | Existing mobile implementation | Implementation boundary |
| --- | --- | --- | --- |
| 1 Public GIS | Brand left, login right, search/filter controls, map, location details | Separate welcome screen; geometry without a basemap | Make GIS the entry; retain public repository, viewport queries and geometry; add satellite tiles |
| 2 Login | Blue welcome header, white credential form, public map action | Working Supabase/Laravel login, secure session and guard | Restyle presentation; preserve auth coordinator, validation and password clearing |
| 3 Dashboard | Greeting, association card, four totals, quick links | Working association dashboard API; long summary list | Reuse canonical counts and nullable states; never substitute zero for unavailable |
| 4 Association | Photo, name, location, totals, profile information | Session name and dashboard municipality/status/totals only | Photo placeholder; show available fields; full profile contract missing |
| 5 Members | Members/registrations tabs, searchable roster, read-only notice | Application totals only; no roster API | Show canonical application totals where available; no invented names, roster, approvals or search results |
| 6 Projects | Projects/trainings tabs, status filters, project cards | Dashboard provides up to six recent project summaries | Label scope accurately; filter those summaries only; no project detail or write API |
| 7 Trainings | Summary counts, status filters, activity calendar | Dashboard training aggregates; no dated training list | Show available aggregates; calendar contract missing; no invented dates or attendance |
| 8 Production | Period selector, quantities, chart/table, read-only notice | Working canonical reporting repository and analytics renderer | Open production report using existing report filters, units and chart/table data; retain general reports |
| 9 Registration | Step indicator, personal/association fields, consent, submit for review | No mobile member-application submission or options endpoint | Present non-persistent form with photo placeholder; submission disabled with a clear availability message |
| 10 Account | Blue account header, identity/access details, logout | Working normalized session and logout; no email in session DTO | Show verified display name, association and role; no fabricated email or shared-account claim |

## Data and authorization boundary

Confirmed source routes: `GET /map/data`, `GET /api/v1/me`,
`GET /api/v1/association/dashboard`, `GET /api/v1/association/reports`, and
`GET /api/v1/association/reports/options`. Browser session/CSRF routes are not
mobile bearer APIs. This change does not create endpoints or change contracts.

Association scope remains enforced by Laravel. All authenticated presentation
stays inside `AssociationGuard`; no member/program edit or approval action is
introduced. Reporting calculations, data models, remote API adapters and M1
transport budgets are unchanged. Availability message wording is updated;
failure codes, repository interfaces and authentication/session logic are
preserved. New design-verification fixtures belong only to tests and screenshots.

## Missing backend contracts

- Full association profile, organized date, approved commodities and authorized photo.
- Paginated roster and registration lists with association scope, safe search,
  status filters and approved detail fields.
- Full project list/details and dated training calendar/attendance projections.
- Account email or other editable account information, if approved for mobile.
- Member application field/options contract, geography, beneficiary categories,
  consent requirements, photo rules, server validation, idempotency, authorized
  submission and error/status contract. No form values are submitted or persisted.

## Verification and screenshots

Synthetic screenshot records are not release application data. They exist only
in `mobile/test/proposed_ui_test.dart`. The captures use the actual Flutter
widgets, SDK Roboto/Material fonts and a 360 x 800 logical phone viewport.
System status/navigation chrome is outside these widget captures.

## Final screen comparison

| Page | Implemented | Remaining dependency or deliberate deviation |
| --- | --- | --- |
| 1 | Public map is the initial route; circular pin mark left, login right; satellite imagery, search, geometry filters, boundary toggle, zoom/fit and location sheet | Existing public API does not supply municipality/program filter options or authorized association-detail links. Only supported location filters and public projection are exposed. |
| 2 | Blue welcome area, white credential form, visibility toggle, busy/error states and public map action | Association Account terminology replaces the reference's ambiguous member-login wording. Account provisioning and existing authentication remain unchanged. |
| 3 | Greeting, blue association card, four canonical totals, quick-access cards and five-tab navigation | Totals retain backend scope notes; null values display as unavailable, not zero. No invented next-training event. |
| 4 | Association photo placeholder, verified name/municipality/status, available totals and read-only profile card | Full profile fields/photo remain unavailable; no organized date or commodities invented. |
| 5 | Members/registrations tabs, canonical member/application totals and read-only notice | Roster/search and individual registration records are unavailable. No fictional roster or approval controls. |
| 6 | Project/training navigation, functional search/status filtering of recent summaries, photo placeholders and read-only notice | The dashboard contract contains up to six recent unarchived projects. No full-directory or detail claim. |
| 7 | Training aggregates, calendar section and read-only notice | No dated training/attendance API; no fabricated calendar or upcoming count. |
| 8 | Programs-tab production screen with period filters, server measures, expanded vertical charts and canonical tables | All existing report filters/units are retained; general Reports remains accessible from Home. Dry-weight labels and commodity totals are never assumed. Chart orientation/scale are presentation only. |
| 9 | Three-step layout, personal/association fields, date picker, consent and photo placeholder | Submission and unavailable lookup fields are disabled. Typed values are neither sent nor persisted and are cleared when leaving. Current web rules use 120-character names and optional contact, unlike the reference's illustrative 255/required-phone labels. Position/endorsement requirements are represented, but no mobile submission contract is invented. |
| 10 | Blue identity card, verified account/association access, account information and confirmed logout | No fabricated email; the normalized session does not expose one. No assertion that accounts are shared. |

The requested primary color is `#0B4C8C`. The reference's agency branding,
sample/prototype labels and fictional records are excluded from production UI.
The circular pin-in-circle mark follows the supplied reference and uses a
reusable vector widget. No separate approved master logo image was supplied.
Photos remain placeholders as requested.

## Satellite imagery

`SatelliteBasemap` explicitly configures the public Esri World Imagery tile
template and visible attribution. Both can be replaced using build defines.
The imagery client is independent of authenticated Laravel requests: no bearer
token, redirects, or automatic transport retries; 15 seconds and 1 MiB per tile.
Imagery errors are shown explicitly. Published business geometry remains usable
independently of tile availability.

Attribution follows [Esri's attribution guidance](https://support.esri.com/en-us/knowledge-base/what-is-the-correct-way-to-cite-an-arcgis-online-basema-000012040)
and [World Imagery source credits](https://doc.arcgis.com/en/data-appliance/2023/maps/world-imagery.htm).
Deployment usage terms/quotas still require owner review. Sixteen anonymous Cebu
imagery tiles were read solely for local comparison captures; no Supabase or
Laravel business records were requested. Ordinary widget tests use synthetic
tile transports and make no external imagery requests.

## Reproducing verification

```powershell
cd C:\dev\AssocMap-MemberAPI\mobile
flutter analyze --no-pub
flutter test --no-pub --concurrency=1
flutter test --no-pub --concurrency=1 --dart-define=ASSOCMAP_CAPTURE_UI=true test/proposed_ui_test.dart
```

The capture option locates fonts through `FLUTTER_ROOT` or the executing Flutter
SDK. It writes PNGs beneath `output/mobile-ui/screenshots` at repository root.
When the optional local tile cache is absent, the screenshot test uses synthetic
tiles; no imagery download occurs inside tests. Reference renders and downloaded
tiles are ignored verification artifacts, not bundled application assets.

The responsive checks use 320 x 640, 360 x 800 and 640 x 360 at 200 percent text,
with missed taps treated as failures. They navigate all destinations, including
production, and verify association mismatch rejection and registration draft
clearing. Existing tests retain authentication, revocation, logout, GIS geometry,
error/retry states and M1 timeout/size/cancellation coverage.

Physical-device behavior, hosted authentication, deployed APIs and satellite
provider deployment acceptance are not established by synthetic widget tests.
No migrations, shared database mutations, live Auth operations, commits, pushes,
merges or deployments were performed.

## Final verification results

Final verification date: 27 October 2026, Asia/Manila.

| Check | Result |
| --- | --- |
| Complete Flutter suite | 174 tests passed; zero failures |
| Static analysis | No issues found |
| Changed Dart file formatting | 26 files checked; zero formatting changes required |
| Git whitespace check | Passed |
| Large text and layout | All ten destinations checked at 200 percent text on 320 x 640, 360 x 800 and 640 x 360; missed taps fail the tests |
| Privacy and access | Existing route/session guards and logout tests pass; wrong-association response is rejected; registration fields are cleared on exit |
| Reporting M1 | Existing timeout, size, error, cancellation and logout regressions pass; transport source unchanged |
| Navigation hierarchy | Android Back returns from a workspace subsection to Home; Profile logout clears the session and removes protected navigation |
| External mutation | None; business/Auth requests in tests use synthetic transports |

Earlier verification exposed constrained-map and photo-caption overflows; both
were corrected and the final checks above pass. Old welcome/demo-control test
navigation was updated to the approved public-entry/credential-entry flow while
retaining access, denial, logout and GIS assertions.

GO for UI review. NO-GO for full mobile functional acceptance until the missing
contracts, physical-device checks and provider deployment acceptance are complete.

## Screenshot artifacts

Reference comparisons retain the original PDF page on the left and the implemented
Flutter screen on the right. Original reference branding is not application UI.
The implementation views use synthetic verification records, not live accounts.

- [Implemented screens 1-5](../output/mobile-ui/implemented-01-05.png)
- [Implemented screens 6-10](../output/mobile-ui/implemented-06-10.png)
- [Production chart/table detail](../output/mobile-ui/screenshots/08b-production-detail.png)
- [Registrations unavailable state](../output/mobile-ui/screenshots/05b-registrations.png)
- [200-percent text, 320 x 640 dashboard](../output/mobile-ui/screenshots/large-text-320-640-dashboard.png)
- [200-percent text, 320 x 640 map](../output/mobile-ui/screenshots/large-text-320-640-map.png)

| Reference page | Before/after comparison | Implementation |
| --- | --- | --- |
| 1 | [Comparison](../output/mobile-ui/comparison/page-01.png) | [Screenshot](../output/mobile-ui/screenshots/01-public-map.png) |
| 2 | [Comparison](../output/mobile-ui/comparison/page-02.png) | [Screenshot](../output/mobile-ui/screenshots/02-login.png) |
| 3 | [Comparison](../output/mobile-ui/comparison/page-03.png) | [Screenshot](../output/mobile-ui/screenshots/03-dashboard.png) |
| 4 | [Comparison](../output/mobile-ui/comparison/page-04.png) | [Screenshot](../output/mobile-ui/screenshots/04-association.png) |
| 5 | [Comparison](../output/mobile-ui/comparison/page-05.png) | [Screenshot](../output/mobile-ui/screenshots/05-members.png) |
| 6 | [Comparison](../output/mobile-ui/comparison/page-06.png) | [Screenshot](../output/mobile-ui/screenshots/06-projects.png) |
| 7 | [Comparison](../output/mobile-ui/comparison/page-07.png) | [Screenshot](../output/mobile-ui/screenshots/07-trainings.png) |
| 8 | [Comparison](../output/mobile-ui/comparison/page-08.png) | [Screenshot](../output/mobile-ui/screenshots/08-production.png) |
| 9 | [Comparison](../output/mobile-ui/comparison/page-09.png) | [Screenshot](../output/mobile-ui/screenshots/09-registration.png) |
| 10 | [Comparison](../output/mobile-ui/comparison/page-10.png) | [Screenshot](../output/mobile-ui/screenshots/10-account.png) |

Capture verification: all 7 design tests passed using real SDK fonts. Normal
screens and additional 200-percent text views were visually inspected. The map
camera starts at the public response bounds, avoiding requests for the mapping
library's unrelated default location. At large text sizes the header retains the
circular mark and allows controls/content to grow and scroll.

## Files changed

- `docs/mobile-proposed-ui-comparison.md`
- `mobile/README.md`
- `mobile/lib/app/app.dart`
- `mobile/lib/app/configuration/app_config.dart`
- `mobile/lib/app/configuration/satellite_basemap.dart`
- `mobile/lib/app/theme/app_theme.dart`
- `mobile/lib/core/authentication/auth_failure.dart`
- `mobile/lib/core/widgets/assocmap_widgets.dart`
- `mobile/lib/core/widgets/data_mode_banner.dart`
- `mobile/lib/core/widgets/public_gis_mode_banner.dart`
- `mobile/lib/data/repositories/analytics_repository.dart`
- `mobile/lib/data/repositories/dashboard_repository.dart`
- `mobile/lib/data/repositories/repository_factory.dart`
- `mobile/lib/features/account/association_access_screen.dart`
- `mobile/lib/features/account/association_dashboard_screen.dart`
- `mobile/lib/features/account/association_data_view.dart`
- `mobile/lib/features/account/association_denied_screen.dart`
- `mobile/lib/features/account/association_home_screen.dart`
- `mobile/lib/features/account/association_sections.dart`
- `mobile/lib/features/account/member_registration_screen.dart`
- `mobile/lib/features/public_map/public_map_screen.dart`
- `mobile/lib/features/public_map/public_map_view.dart`
- `mobile/lib/features/public_map/public_project_details.dart`
- `mobile/lib/features/reports/association_analytics_screen.dart`
- `mobile/lib/features/welcome/welcome_screen.dart`
- `mobile/test/auth_widget_test.dart`
- `mobile/test/mobile_m1_widget_test.dart`
- `mobile/test/proposed_ui_test.dart`
- `mobile/test/public_gis_widget_test.dart`
- `mobile/test/support/test_tile_provider.dart`
- `mobile/test/widget_test.dart`
