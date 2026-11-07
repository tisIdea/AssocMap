# Mobile Stabilization Batch C

Assessment date: 2026-10-31 (Asia/Manila). Repository: `C:\dev\AssocMap-STAGING`. Branch: `feature/member-application-r2`; starting HEAD: `ae4302c519c0468ae2d82148ad43ba3f4f5690c7`.

Member registration and the account's own application-status workflow are implemented and verified with isolated fixtures. Registration creates a **pending member application**, using the existing Laravel submission service. Field Officer approval remains the only route from an application to an official member. This is local implementation and automated-test evidence; staging account/device acceptance is still outstanding.

The checkout already contained uncommitted GIS Batch B work. It was preserved. No migrations, seeders, shared database writes, real Auth operations, provider-media writes, credential/cloud changes, commits, pushes, installation or deployment were performed. Anonymous, read-only staging HTTP probes of four URLs are identified separately below.

## Audit findings and disposition

| Priority | Finding | Disposition / remaining gate |
| --- | --- | --- |
| P1 | Registration was a prototype: nonpersisted inputs, disabled submit/photo/options, and fields without an established backend contract. | Replaced with canonical server options, validation, pending submission, optional private-photo upload and a receipt. No new registration process or direct-member creation. Prototype geography/livelihood/consent controls were removed rather than inventing requirements. |
| P1 | Laravel had the approved browser submission service but no mobile member-application adapter. | Added three protected JSON routes around the existing request/service. Existing web workflow, approval, models and media services remain unchanged. Deploy these routes before testing the updated APK against that backend. |
| P1 | Opening the Android document picker triggers app resume; fresh authorization previously destroyed a registration draft. | The guard retains transient widget state while verification is busy, hides private content and excludes focus. A denied/expired session, logout or a changed user/association destroys the old workspace. Tests cover successful and denied restoration. |
| P1 | Anonymous staging API and public-map requests encounter Cloudflare Access. | All four probes returned an Access login redirect. Native clients reject redirects and do not substitute demo data. An approved Access-compatible testing target/setup is required. No policy was disabled, bypassed or changed. |
| P1 for distribution | Android still uses `com.example.mobile`, label `mobile`, version `1.0.0+1` and debug signing for the release variant. | Preserved and documented. The locally built APK is for development evaluation; application identity, branding, versioning and controlled release signing need a separate release decision. |
| P2 | Retry after a lost submission response could create another application. | Disabled repeat taps/navigation during submission, guarded repository concurrency, and never replayed POST after 401 or a transport failure. An uncertain result locks the current form and directs the user to their application list. There is no durable server idempotency key; see limitations. |
| P2 | Account screens imply broader data availability than the existing API offers. | Corrected the roster guidance to reflect current permissions and direct users to their Field Officer. Partial screens remain explicitly unavailable. No roster authorization, photo projection, full project directory or training workflow was invented. |

## Screen, navigation and integration inventory

All production files under `mobile/lib` were inventoried; relevant routes, controls, models, repositories, HTTP/authentication call paths and associated tests were inspected. Status below describes source behavior and isolated verification, not hosted acceptance.

| Screen / control | Current behavior | Boundary / incomplete feature |
| --- | --- | --- |
| Startup, `/`, `/public-map` | Public GIS is the entry point. Live and explicit demo modes stay separate. Loading, empty, error and retry states; location search, geometry/site filters, legend and details are connected. | Live public data is blocked by Access in the anonymous staging probes. Tiles, satellite configuration/attribution and physical-device performance require separate acceptance. |
| Public location details | Existing Batch B classification, original geometry and representative-point rules are retained. No bearer token is needed for public GIS. | Location photographs remain an explicit unavailable placeholder; private photos are not exposed. No GIS behavior was changed by this batch. |
| `/association-access` | Supabase sign-in followed by Laravel `/api/v1/me` eligibility; required inputs, loading, errors and retry. | Real account/provider and device secure-storage acceptance remains required. No public sign-up, invitation acceptance or password-reset workflow was added. |
| Access-denied screen | Wrong-role or invalid-link failures show the existing denial screen, with working return-to-sign-in and public-map actions. | No account/role provisioning from mobile; backend eligibility remains authoritative. |
| `/association-home`, dashboard | Current association, counts, recent project summaries, aggregate training information and links to workspace sections. | This is a bounded dashboard projection, not all underlying records. |
| Association profile | Name, municipality, active status and summary counts from the dashboard API. | Photo and additional profile fields remain unavailable; no editing. |
| Members / Registrations tabs | Official-member count; existing registration aggregates; link to **My member applications**. | Official roster/search remain unavailable. Current `MemberDirectoryQueryService::memberScope()` allows System Administrators and Field Officers, not Association Accounts; roster access needs an approved permission and projection. |
| New Member Registration | Server catalogs, canonical fields, optional Android photo selection/preview/removal, client/server validation, loading, pending receipt and link to applications. | Transient form only; no offline draft, applicant self-registration, official-member creation or mobile approval. Navigating away clears the draft. |
| My member applications | Account-owned list, Pending/Approved/Rejected filter, 25-row pagination, refresh/retry, reference, review/rejection information and attachment indicator. | Only the current submitter's applications in the current association. No edit/delete/approve buttons, binary-photo viewer or full application-detail endpoint. Empty/offline states are explicit. |
| Programs / Projects | Search and All/Ongoing/Completed filters operate on up to six recent unarchived dashboard summaries. | Clearly labelled as recent summaries; no full directory, project-detail navigation or project-photo API. |
| Programs / Trainings | Recorded/completed activities and distinct trained-member aggregates. | Calendar, dated activities and attendance records remain unavailable. |
| Production / Reports & Analytics | Existing protected options and report endpoints, report filters, charts/tables, loading/empty/errors/retry. Calculations remain on Laravel/Python. | No new mobile export/share/download pipeline. Native Python/PostgreSQL/deployed report acceptance was not performed here. |
| Account profile / Sign out | Verified display name, linked association, role, logout confirmation; session clearing/cancellation and return to public GIS. | Email is absent from the normalized session and is shown as unavailable. No account-management permissions were added. |
| Back, bottom tabs, contextual navigation | Existing destinations retained; new registrations destination connected. Navigation is blocked while the application POST is in flight; explicit logout still cancels requests. Unknown routes return safe feedback. | Drafts do not survive process death or leaving the form. |

## Canonical workflow, authorization and data flow

1. Flutter authenticates through the existing Supabase SDK and asks Laravel `/api/v1/me` to establish eligibility. Middleware remains authoritative for the current profile, active role/invitation/session boundary, exactly one current active account link, and active unarchived association. Neither a client-selected association nor a web session alone authorizes the API.
2. Flutter reads active sex categories, active positions and active member classifications from the new options endpoint. No fallback catalog is seeded or inferred. Occupied executive positions are disabled as guidance; the canonical service validates availability again. Pending applications do not reserve an executive position.
3. `StoreMobileMemberApplicationRequest` extends `StoreMemberApplicationRequest`; it additionally prohibits client-supplied identifiers, ownership, status/review fields and stored photo paths. Association and submitter come from the authenticated server context.
4. The existing `MemberApplicationService::submit()` performs role/association/actor/link freshness checks and transactional locking, validates business rules, creates the pending application and classification links, and delegates optional media to the existing private-photo service. It does not insert a `Member`.
5. Existing Field Officer review remains on the web. Approval/rejection use the established scope, transaction and audit behavior. Approval creates the official member using the application source reference. Mobile sees the resulting status on refresh.
6. List reads are scoped to **both** current association and current submitter. A replacement account holder does not inherit the previous holder's personal submission list. The receipt/list expose reference, name, status, timestamps, rejection reason and `has_photo`; they exclude photo paths, endorsement text, birth date, contact and FishR values.

Canonical inputs are first/last name (required, 120 characters), optional middle name (120) and suffix (20), required nonfuture birth date and sex category, optional contact (30) and FishR number (100), required position, optional classifications, required nonblank plain-text endorsement (10,000), and optional photo. `OTHER` positions require explanatory text (100). Existing FishR checks reject numbers already associated with an official member or pending/approved application. No minimum-age, geography, livelihood, consent or other new eligibility policy was introduced. Options show active catalogs; canonical validation rules are reused rather than independently tightened.

Photos must be genuine JPEG/PNG with matching extension, at most 2 MiB and 2048 x 2048. Android uses `ACTION_OPEN_DOCUMENT` through `assocmap/member_photo`, reads only the selected document with a byte bound, and retains no URI grant or local file copy. Dart validates bytes/dimensions before preview; Laravel remains authoritative. No camera/broad-storage permission, dependency or public object URL was added. Non-Android/missing picker support gives explicit feedback and still permits a submission without an optional photo.

Existing `MemberApplicationPhotoService` / `PhotoLifecycleService` behavior supports the already configured local, Supabase or R2 transport. This batch does not select/change the deployed transport. Private media and unknown-commit reconciliation remain owned by those services. Photo viewing/review remains the existing authorized web workflow; an attachment flag is not a mobile photo-download capability.

## Laravel API compatibility

Existing required endpoints remain `GET /api/v1/me`, `GET /api/v1/association/dashboard`, `GET /api/v1/association/reports/options` and `GET /api/v1/association/reports`. Public GIS continues to use `GET /map/data` and Batch B's `GET /map/site-types` without authentication.

| Added route | Contract | Protection |
| --- | --- | --- |
| `GET /api/v1/association/member-applications/options` | `data.association`, `sex_categories`, `positions` including `occupied`, `classifications`. | API JSON guard, existing authentication throttle, bearer verification, current mobile association eligibility, 30/minute. |
| `GET /api/v1/association/member-applications` | Optional `status=pending|approved|rejected`, `page=1..100000`; `data.association_id`, application summaries, `page`, `last_page`, `total`; 25/page. Caller-supplied scope fields prohibited. | Same eligibility, 30/minute; current submitter/association SQL scope. |
| `POST /api/v1/association/member-applications` | Multipart canonical fields, `classification_ids[n]`, optional `applicant_photo`; HTTP 201 pending summary and server association. HTTP 422 returns canonical field errors. | Same eligibility, 10/minute; request/service rules and locking; prohibited ownership/review fields. Request-body guard 2 MiB + 65,536 bytes. |

Field-error JSON is additive and limited to the new member-application route namespace. Other API error contracts are preserved. Native requests use the configured HTTPS backend origin/path prefix, fixed allowed routes, no redirects and bounded responses. Normal protected traffic retains the 25-second/4 MiB client bound; report requests keep their existing larger limits. The write client accepts only a 201 JSON receipt and only allowlisted bounded validation errors. A missing/older backend produces unavailable feedback, not fabricated success or demo writes.

The existing auth refresh coordinator, secure storage and session-generation checks are reused. Safe reads retain their existing 401-refresh behavior. **A POST is never replayed after 401**, timeout, disconnect, redirect, malformed response or uncertain completion. 401/403 invalidates access; logout cancels active requests and suppresses late success. Successful app-resume reauthorization preserves a hidden draft; failed authorization removes it. Tokens, response bodies with private evidence and provider diagnostics are not logged by the new code.

## Exact Batch C files

Paths are repository-relative. `(new)` distinguishes additions. Other dirty GIS paths belong to Batch B and are not presented as Batch C changes.

| Responsibility | Files |
| --- | --- |
| API adapter | `web/app/Http/Controllers/Api/V1/MemberApplicationController.php` (new); `web/app/Http/Requests/StoreMobileMemberApplicationRequest.php` (new) |
| API registration / bounded field errors | `web/routes/api.php`; `web/app/Http/Middleware/ApiRequestGuard.php` |
| Laravel integration regression | `web/tests/Feature/MemberApplicationSubmissionTest.php`; `web/tests/Feature/MobileAuthenticationTest.php` |
| App injection / resume guard | `mobile/lib/app/app.dart`; `mobile/lib/core/authentication/association_guard.dart` |
| Authenticated transport | `mobile/lib/core/networking/laravel_api_client.dart`; `mobile/lib/data/remote/api_auth_repository.dart` |
| Application model / repositories | `mobile/lib/data/models/member_application.dart` (new); `mobile/lib/data/repositories/member_applications_repository.dart` (new); `mobile/lib/data/remote/api_member_applications_repository.dart` (new) |
| Optional photo picker | `mobile/lib/core/media/member_photo_picker.dart` (new); `mobile/android/app/src/main/kotlin/com/example/mobile/MainActivity.kt` |
| Account navigation / status / form | `mobile/lib/features/account/association_home_screen.dart`; `mobile/lib/features/account/association_sections.dart`; `mobile/lib/features/account/member_registration_screen.dart`; `mobile/lib/features/account/member_applications_screen.dart` (new) |
| New isolated fixtures and tests | `mobile/test/support/member_application_fixtures.dart` (new); `mobile/test/member_applications_test.dart` (new); `mobile/test/member_applications_widget_test.dart` (new) |
| Existing mobile regression coverage | `mobile/test/mobile_m1_auth_test.dart`; `mobile/test/mobile_m1_widget_test.dart`; `mobile/test/proposed_ui_test.dart` (also contains a preserved Batch B fixture-signature change) |
| Handoff | `docs/mobile-stabilization-batch-c.md` (new) |

The existing submission/review/media services, schema, migrations, seeders, configuration sources, dependencies/lockfiles, Android manifest/signing/application ID, public-map code, geometry and location records were not changed for Batch C. Sample SHA-256 comparisons of 12 GIS/catalog files during implementation were unchanged. Framework build outputs and tool-generated ignored Android configuration are local artifacts, not deployed configuration changes.

## Executed isolated verification

| Check | Result | Evidence boundary |
| --- | --- | --- |
| Pre-change full Flutter suite | 197 passed | Synthetic/mock services; serial execution. |
| Full Flutter suite after implementation | **234 passed**, zero failures | Includes existing public GIS, reports, auth and UI tests plus 37 added cases. No live accounts/providers. Followed by the text-only roster-guidance correction. |
| Final widget regression after roster-guidance correction | **24 passed**, zero failures | `proposed_ui_test.dart`, `member_applications_widget_test.dart`, `mobile_m1_widget_test.dart`; includes navigation, large text, registration, auth/resume and application list. |
| Dart formatting / diff whitespace | **18 files conform; no changes required; `git diff --check` clean** | Only Batch C Dart paths were formatted/checked; no public-map formatting refactor. |
| Flutter analyzer | **No issues** (`analyze --no-pub`) | Static analysis, not device acceptance. |
| Laravel selected baseline (five suites) | 213 tests, 2,411 assertions passed | Isolated SQLite/mocked HTTP/media. |
| Final Laravel regression (eight suites below) | **240 tests, 2,697 assertions; zero errors/failures/skips** | PHPUnit JUnit verified; guarded SQLite `:memory:` and isolated temporary storage/cache. |
| PHP syntax | Six changed/new PHP files passed | Controller, request, API guard, routes and two feature tests. |
| Android release-variant APK | Built locally; final artifact/signature details below | Compilation/package verification only; no install or physical-device test. |

Final Laravel suites:

```text
tests/Feature/MobileAuthenticationTest.php
tests/Feature/MemberApplicationSubmissionTest.php
tests/Unit/MemberApplicationEvidenceTest.php
tests/Unit/MemberApplicationPhotoControllerTest.php
tests/Unit/MemberApplicationPhotoServiceCharacterizationTest.php
tests/Unit/GisFeatureSerializerTest.php
tests/Feature/GisScopedReadsTest.php
tests/Feature/PublicGisSiteTypesTest.php
```

JUnit evidence: `%TEMP%\assocmap-mobile-batch-c-8629b4ce95854a87a9421488e638637d\after.xml`. Baseline: `%TEMP%\assocmap-mobile-batch-c-41f7cafb7f084b1a9e1ec19c2b6c503c\baseline.xml`. These are machine-local temporary artifacts, not checked-in files. TestEnvironmentGuard rejects cached/shared database configuration, loads a dedicated test environment, permits only SQLite `:memory:`, and blocks stray Laravel HTTP requests. Process-only synthetic settings and temporary cache/storage paths were used; fixtures construct their schema directly. No migration or seeder was run. Provider identity responses and storage were faked.

Coverage includes active/empty catalogs, genuine-image pending submission and no Member insertion, forbidden ownership/review injection, required/OTHER/date/photo validation, FishR duplicate checks, own-submitter and cross-association isolation, status/pagination, missing bearer/wrong-role/inactive-link denial and safe provider failures. Mobile tests cover malformed/scope-mismatched replies, fixed multipart route, HTTP 302/401/403/422/429/503, bounded photos, repeat taps/concurrent submissions, uncertain writes, editable validation corrections, session expiry/logout/late responses and resume preserving or destroying the draft appropriately.

Registration and application-list widget tests cover 320 x 640 portrait and 640 x 360 landscape at 200% text scale, scrolling, validation and actionable states. This does not establish TalkBack, real keyboard/IME, native document-provider behavior, contrast compliance or device performance. Intermediate implementation/test issues were corrected before the reported passing runs, including mutation of an immutable field-error map. No known pre-existing failure remained in the selected final checks. A full unrelated Laravel suite, native PostgreSQL/PostGIS integration and provider/device UAT were not executed.

Reproduction from `mobile` with the installed SDK:

```text
flutter test --no-pub --concurrency=1
flutter analyze --no-pub
flutter build apk --release --no-pub --dart-define-from-file=config/staging.json
```

The installed toolchain was Flutter 3.44.6 / Dart 3.12.2, PHP 8.4.27 and PHPUnit 11.5.56. On this machine the Flutter tool snapshot was invoked using the SDK's Dart executable. Android builds used process-local limits (1 GiB Gradle heap, 768 MiB Metaspace, one worker, no parallelism/daemon); no checked-in build setting was changed. SDK/native-access warnings did not prevent packaging. Do not reproduce Laravel checks using ambient shared `.env` settings; retain the synthetic test guard and isolated cache/storage configuration.

## Android artifact and configuration audit

Artifact: `mobile/build/app/outputs/flutter-apk/app-release.apk`. Package verification found `com.example.mobile`, version `1.0.0`/code `1`, minimum SDK 24, target/compile SDK 36, and ARM64/ARMv7/x86_64 libraries. The application label is `mobile`. APK manifest verification confirms INTERNET permission, `allowBackup=false` and `usesCleartextTraffic=false`; no broad photo-storage/camera permission was introduced. Java/Kotlin compilation targets remain Java 17.

Final rebuild after the roster wording correction exited **0** (`assembleRelease`, 205.3 seconds). Artifact timestamp: 2026-10-31 16:43:39 Asia/Manila; size: **56,554,309 bytes**. SHA-256: `EF469B7C39E6DF880713EB226A2B6D4D010517E0F32268858BE3287E65E306FD`. Its signature and package metadata were verified again after this rebuild. The final analyzer also passed with no issues.

`apksigner verify --verbose --print-certs` verifies the v2 signature, with certificate subject `C=US, O=Android, CN=Android Debug`. The release build still uses the existing debug signing configuration. Production identity/signing/version requirements are unresolved and were not changed.

Staging build configuration selects API/live mode and `https://staging.assocmap.app`. Only public client configuration belongs in an APK; never embed a Supabase service key, private storage credentials or a Cloudflare Access service secret. No `.env`, checked-in mobile config or cloud policy was changed. A different approved API origin requires an appropriate build configuration and rebuild, including its existing public Supabase settings.

## Cloudflare Access: executed read-only evidence

Anonymous HTTPS GET requests used `Accept: application/json`, no bearer/cookie, and disabled redirects. Only status/content-type/redirect host/path were examined. The probes were repeated on 2026-10-31 with the same result. Each returned **302**, `text/html; charset=UTF-8`, with an Access login location at `cool-moon-1216.cloudflareaccess.com`, path beginning `/cdn-cgi/access/login/staging.assocmap.app`:

```text
https://staging.assocmap.app/up
https://staging.assocmap.app/api/v1/me
https://staging.assocmap.app/map/data?mode=overview
https://staging.assocmap.app/map/site-types
```

Therefore ordinary anonymous native public GIS and identity requests cannot reach Laravel through this tested staging context. A browser's Access session is not proof that Flutter has an equivalent authenticated Access session. Real bearer requests, authorized-network behavior and the underlying Cloudflare policies were not tested/inspected. The origin's deployment state cannot be established from these redirects. No Access login, policy modification, bypass or shared mutation was attempted.

## Deployment requirements and sequence

1. Review the additive backend/mobile change together with the existing GIS Batch A/B changes. Confirm an approved development test target that Flutter can reach under the intended Access policy. Keep Access enabled; resolve reachability through the infrastructure owner before device UAT.
2. **Backend before mobile:** deploy the three API routes, request/controller and API guard changes through the normal release procedure. Existing canonical application tables, catalogs, constraints, actor/link rules and private-photo lifecycle must already be provisioned. This batch requires no schema change or catalog seed. Do not run a migration or seeder as an implied part of this work.
3. Verify hosted API middleware/route/cache state, active account association links and private-photo transport permissions, capacity and failure/reconciliation handling. Check upstream request/PHP upload limits permit the canonical 2 MiB photo plus multipart/form overhead and the API body limit. Confirm TLS, actual runtime configuration and hosted response limits separately; local SQLite tests do not prove these.
4. Confirm existing dashboard/report/Python dependencies and public GIS endpoints remain deployed coherently. No report-engine or public-GIS contract change is required for registration.
5. Build for the approved HTTPS origin with its correct public Auth configuration. Decide application ID, label, version and signing before any distribution beyond development evaluation. Protect the release keystore outside source control; no release credential was created in this batch.
6. Perform the device/role/provider acceptance below before calling the application functionally accepted. No backend/mobile deployment or account provisioning was performed here.

**Database/media impact at future authorized runtime:** a valid submission adds a pending application, its classification links and optional normalized private photo through the existing services. Approval adds the official member through the existing review workflow. Existing geometries, projects, associations, publication status, identifiers, official members, authorization and audit history remain intact. The new API does not return object-provider paths or alter photo visibility. This batch's automated fixtures did not touch shared Supabase/R2/staging data.

**Rollback:** the mobile build can be rolled back without schema rollback. Reverting additive API routes makes this mobile feature unavailable; retain the existing web submission/review functionality and all applications/photos already created at authorized runtime. Never delete pending applications, media or official members as an application rollback step. Coordinate the web release with existing Batch A/B changes; do not overwrite unrelated dirty GIS work.

## Remaining requirements and acceptance

No durable server idempotency key was added: submission guards prevent repeated concurrent calls in this process, not exactly-once delivery across process death/reinstalls or independent devices. On an uncertain result the UI requires reviewing the account's applications before a new attempt. Leaving/restarting the form loses that uncertainty state; name-only applications do not have a newly invented uniqueness constraint. A durable retry/idempotency contract would require a separately reviewed persistence/API change. Existing FishR duplicate rules and approval source constraints remain in force.

Unfinished functionality needing approved scope/contracts includes Association Account official-roster access, complete association fields/authorized photographs, full project records/details, dated trainings/attendance, profile email/account management and mobile exports. No permission or API was invented to fill these gaps. Android photo selection has a compiled native bridge and mocked channel tests; provider/lifecycle behavior, process recreation, and any iOS/desktop picker implementation remain unaccepted. Registration without an optional photo remains available on unsupported platforms.

Required device acceptance on the approved target:

- Eligible Association Account login, cold start/secure restore, expiry/refresh, revoked role/invitation/link, inactive/archived association, logout during a request and late-response suppression. Verify cross-account/association isolation and replacement-holder behavior.
- Guest GIS before login and after logout; live catalog, search/filter/legend/details behavior; no login token attached to public requests. Verify tile/provider availability and accessibility separately.
- Canonical required fields, nonfuture birth date, OTHER position, occupied-position races, optional classifications, server validation correction and empty/unavailable catalogs. Submit synthetic approved test data only in an explicitly authorized isolated target.
- Native chooser cancel/unsupported provider, valid JPEG/PNG, corrupt/oversize/dimension failures, preview/removal, resume authorization success/denial, orientation, app suspension/process death and no private draft remaining after logout.
- Double tap/slow upload/offline/timeout/uncertain completion: no automatic POST replay, clear list-review instruction and no accidental duplicate. Verify a real private media write/failure/reconciliation only under separate provider-test authorization.
- Field Officer review in the existing web workflow: pending application is not an official member; approval/rejection is reflected on mobile refresh, with no mobile approval control and no private-photo leakage.
- Small/large phones, portrait/landscape, 200% text, keyboard/IME, focus order, TalkBack labels/error announcements, touch targets and network/battery/performance behavior.

Stop point: local implementation, isolated verification, read-only reachability evidence and deployment handoff only. Staging UAT, provider acceptance, signing decisions and deployment remain outstanding.
