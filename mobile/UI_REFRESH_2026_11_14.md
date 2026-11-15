# AssocMap Mobile UI refresh — November 14, 2026

Worktree: `C:\dev\AssocMap-STAGING`, branch `feature/member-application-r2`,
HEAD `7a70758fe810f0c25ea824cb48937db6a9de389a`. Existing uncommitted mobile,
web and documentation changes were present and preserved. No commit, push,
deployment, database mutation, account change, registration submission or upload
was performed for this work.

## Presentation changes

- Forest green primary (`#1B624B`), warm neutral canvas, coordinated typography,
  restrained borders and consistent form/button spacing. Primary on white has
  7.25:1 contrast; secondary text on white has 5.45:1 contrast.
- Reuse the actual AssocMap map symbol in the app and Android launcher. Launcher
  name: **AssocMap Mobile**. The original 1024px logo at
  `web/public/images/brand/assocmap-logo.png` supplies the unchanged artwork;
  omit its wordmark for small icons. Legacy five-density, round and adaptive
  resources are included. `tool/generate_android_branding.py` regenerates them
  with the already available Pillow tooling. Package, signing and permissions
  are unchanged.
- Keep the five working authenticated tabs and Android Back behavior. Use Material NavigationBar with standard touch targets, tooltips and label scaling so large system fonts do not split destination names. Public GIS
  retains visible login, gains normal app-bar back navigation when opened from
  the account, and no longer duplicates login in a two-item bottom bar.
- Collapse map filters/legend initially to give the map more room. Search,
  classifications, geometry filters, boundary visibility, marker meanings,
  selection, attribution and API request semantics are retained.
- Disable map autofocus: a delayed public-map response on a background route
  must not move focus out of an active registration field. Gesture controls and
  keyboard interaction settings remain enabled. A delayed-response regression
  failed before this change with primary focus on `FlutterMap`, then passed.
- Use compact account/profile cards, readable status badges and expandable
  secondary count definitions/application references. Displayed dates retain their year. Remove unsupported project
  and association photo placeholders and the unavailable email placeholder.
- Reports use labeled record rows on phones, with the existing columns, values,
  missing-versus-zero distinctions and 25-record pagination. Wide layouts retain
  the table. Page changes bring their section into view.
- Registration retains its controllers, fields, validation, payload, native
  photo picker, upload and submission behavior. Improve labels, grouping,
  keyboard actions, scroll clearance, validation messages, selected-photo area,
  progress and success feedback. No live submission/upload was attempted.
  A physical Samsung keyboard check exposed a numeric-only birth-date layout
  without the hyphen required by the existing validation. That field now uses
  the text keyboard; its format, length limit, validator and payload are unchanged.

## Existing API boundaries

| Information | Existing mobile support |
| --- | --- |
| Association | Authorized identity, status, municipality and summary counts |
| Projects | Up to six recent summaries; existing filters and program year |
| Program activities | Existing dashboard's recent authorized activity summaries |
| Trainings | Recorded/completed/distinct-trained totals; dated calendar/attendance unavailable |
| Monitoring/accomplishment | Dashboard counts/recent quarters and existing reporting datasets |
| Applications | Server-provided options, current-account list and unchanged pending submission workflow |
| Public GIS photos | Existing anonymous publication-filtered gallery and thumbnail/display routes |

No authorized mobile JSON contract was added for full project directories,
individual visit reports/photos, training attendance, private GIS or official
member roster access. These gaps remain explicit. Monitoring photos belong to
Visit Reports; no additional monitoring/project photo sections were invented.

The new presentation widget reads only
`GET /map/{location}/photos` and the returned thumbnail/display URLs. Existing
Laravel routes and publication/approval checks remain authoritative. Requests
send no bearer token, reject redirects, require HTTPS and the configured origin
and exact public route, bound metadata to 64 KiB and image bodies to 8 MiB, and
have a 15-second deadline. Images remain in memory. Loading, empty, retry,
caption, swipe and enlarged-image states are included. Existing authenticated
networking clients, models and repositories were not changed.

## Files changed in this work

Paths below are relative to `mobile/`; this list excludes pre-existing changes.

```text
README.md
pubspec.yaml
assets/brand/assocmap-symbol.png
tool/generate_android_branding.py
UI_REFRESH_2026_11_14.md
lib/app/theme/app_theme.dart
lib/core/widgets/assocmap_widgets.dart
lib/features/account/association_access_screen.dart
lib/features/account/association_dashboard_screen.dart
lib/features/account/association_home_screen.dart
lib/features/account/association_sections.dart
lib/features/account/member_applications_screen.dart
lib/features/account/member_registration_screen.dart
lib/features/public_map/public_map_screen.dart
lib/features/public_map/public_map_view.dart
lib/features/public_map/public_project_details.dart
lib/features/public_map/public_location_photos.dart
lib/features/reports/association_analytics_screen.dart
test/auth_widget_test.dart
test/mobile_m1_widget_test.dart
test/member_applications_widget_test.dart
test/mobile_report_layout_test.dart
test/proposed_ui_test.dart
test/public_gis_widget_test.dart
test/public_location_photos_test.dart
test/widget_test.dart
android/app/src/main/AndroidManifest.xml
android/app/src/main/res/values/colors.xml
android/app/src/main/res/mipmap-anydpi-v26/ic_launcher.xml
android/app/src/main/res/mipmap-anydpi-v26/ic_launcher_round.xml
android/app/src/main/res/mipmap-mdpi/ic_launcher.png
android/app/src/main/res/mipmap-mdpi/ic_launcher_round.png
android/app/src/main/res/mipmap-mdpi/ic_launcher_foreground.png
android/app/src/main/res/mipmap-hdpi/ic_launcher.png
android/app/src/main/res/mipmap-hdpi/ic_launcher_round.png
android/app/src/main/res/mipmap-hdpi/ic_launcher_foreground.png
android/app/src/main/res/mipmap-xhdpi/ic_launcher.png
android/app/src/main/res/mipmap-xhdpi/ic_launcher_round.png
android/app/src/main/res/mipmap-xhdpi/ic_launcher_foreground.png
android/app/src/main/res/mipmap-xxhdpi/ic_launcher.png
android/app/src/main/res/mipmap-xxhdpi/ic_launcher_round.png
android/app/src/main/res/mipmap-xxhdpi/ic_launcher_foreground.png
android/app/src/main/res/mipmap-xxxhdpi/ic_launcher.png
android/app/src/main/res/mipmap-xxxhdpi/ic_launcher_round.png
android/app/src/main/res/mipmap-xxxhdpi/ic_launcher_foreground.png
```

Generated APKs, logs and device captures are local evidence under
`build/ui-refresh/` and `build/app/outputs/flutter-apk/`. Account screenshots and
UI dumps may contain private information and should not be distributed.

## Verification

- Pre-change suite: **242 tests passed** using mocked HTTP, synthetic accounts,
  fake secure storage and synthetic map tiles.
- Analysis: **No issues found**.
- Read-only Railway source-contract check: five public locations; an existing
  gallery returned one approved public photo and HTTP 200 JPEG display data.
  This check alone is separate from Flutter/device rendering evidence.
- Baseline file comparisons confirm no change to authentication, networking,
  media-upload logic, models, repositories, app configuration/routing or the
  pre-existing backend edits. Dependency lock and native picker are unchanged.

- Full regression after the final keyboard/map corrections: **252 tests passed**.
  Final small-screen visual capture checks: **7 tests passed** at normal and
  200% text, including 320×640, 360×800 and landscape 640×360 layouts.
- Public-photo/date affected regression: **25 tests passed**. Tests cover
  gallery loading, empty, errors/retry, same-origin HTTPS URL rejection,
  credential-free requests, enlarged viewing, long captions, report pagination
  and missing-versus-zero values, plus retained application submission guards.
- The member-form file now passes **12 widget tests**, including local-input
  retention and focus during keyboard resizing. The delayed background-map
  regression failed before the autofocus correction and passed afterwards.
- Android release builds completed using process-only Gradle memory/worker
  limits. Native plugin generation and the pre-existing JNI startup safeguards
  remain enabled. Dependency versions were not upgraded.

### Physical Samsung Galaxy A12

Android 12 / ARM64, 720×1600, 300 dpi. Updated with `adb install -r`, preserving
application data and the existing eligible session. Screenshots/XML and logs
are under `build/ui-refresh/device/`.

Verified on the phone:

- Public GIS, five returned locations, markers, fit controls and location
  details; map-to-account and Android Back navigation.
- Existing session restoration; association dashboard/profile, recent projects,
  training totals, production report, current-account application history and
  account profile. Sign-out confirmation opened and was cancelled.
- Public photo loading, thumbnail, enlarged display, no-published-photo state,
  offline gallery error and successful retry after connectivity returned.
  Network settings were restored to their original values.
- Final photo-viewer status-bar contrast and complete years on application dates
  were checked after correction. Installed App info shows **AssocMap Mobile**
  and the AssocMap icon. App ID and signing certificate remain unchanged.
- Follow-up with the actual Samsung keyboard open: map search and map controls
  stay visible; registration focus/Next scrolls the first-name and birth-date
  fields into view; the multiline endorsement field stays above the keyboard.
  Android Back dismisses the keyboard. Only a synthetic local date was entered
  and cleared; no registration or upload request was submitted.

**Keyboard correction verified on version 11:** The diagnostic trace identified
focus moving to `FlutterMap` when the background public map finished loading,
closing the active editor's input connection. A delayed-map-response regression
failed before the autofocus correction and passed afterwards. On the Galaxy A12,
the final regular release keeps the first-name keyboard open, supports Next
navigation/scrolling to birth date, and exposes the required hyphen through the
Samsung symbol keys. The synthetic date `2000-01-02` was entered using those
native keys, then cleared before returning Home. No application was submitted.
The final ARM64 release and its process log contain no temporary tracing marker.

The native 200% font check exposed split labels in the old custom navigation;
the final implementation uses Material NavigationBar. On installed version 8,
all five destination labels remain intact at 200% system text size. The original
font scale (0.9), USB stay-awake setting (0), Wi-Fi (on) and mobile data (off)
were restored. The final process log contained no Flutter exception, overflow,
JNI startup or Android fatal-error signatures during the observed session.
Programs and Profile tabs were opened again on version 8 and returned to Home;
the resulting physical-device screenshots were inspected.

### Final APK

- Version: `1.0.0-uat.20261115`, version code **11**.
- Size: **58,124,181 bytes**.
- SHA-256: `1E6CEDAFC761C2111D91A4B7A69A49766C3D0D90720554D44412A5ED4A1BCFEF`.
- Package: `com.example.mobile`; minimum Android API 24, target API 36.
- Existing Android Debug certificate retained; APK v2 signature verified.
- Installed over the existing application successfully. Final cold launch:
  **5,865 ms** (ADB observation, not a general performance guarantee).

Artifact metadata is also recorded in `build/ui-refresh/artifact.json`.

Not verified in this work:

- **Live member application submission, photo selection/upload and review
  transitions.** The native form and local draft presentation were inspected;
  no registration or upload request was submitted to the shared environment.
- Complete keyboard coverage across landscape, large system text, validation
  errors and other keyboard apps. The portrait Samsung cases above were checked.
- Fresh password entry and completed logout were not repeated; the user reports
  these were already manually tested. The existing session was retained.
- Full device/OS, screen-reader and stakeholder acceptance matrices. Source and
  mocked checks do not replace those acceptance steps.

No backend functional defect was changed. The unsupported API capabilities in
the table above remain gaps for a separately authorized task.

## Rebuild

From `mobile/`:

```powershell
flutter pub get --offline
flutter analyze --no-pub
flutter test --no-pub --concurrency=1
flutter build apk --release --target=lib/main.dart --dart-define-from-file=config/staging.json --build-name=1.0.0-uat.20261115 --build-number=11
```

Output: `build/app/outputs/flutter-apk/app-release.apk`. Keep normal native
tooling generation enabled for Android builds. Preserve the existing startup
registration check and JNI rules. This remains a debug-signed internal evaluation
build with the existing application ID `com.example.mobile`.

## Remaining UAT checklist

1. Extend keyboard UAT to landscape, large system text and validation messages;
   portrait search, focused-field scrolling and multiline entry were inspected.
2. Obtain explicit authorization and an appropriate test-data plan before
   exercising member photo selection/upload, live submission and review states.
3. Complete fresh sign-in/sign-out, native photo-picker prompts, screen-reader
   and stakeholder acceptance checks on the intended UAT devices.
4. Rebuild the approved source, verify its version/signature and checksum, and
   distribute the internal UAT APK. Keep production signing as a separate release
   decision; the current artifact retains the existing debug certificate.
