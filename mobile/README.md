# AssocMap mobile

Flutter Android application with public GIS and Association Account access.
Live mode uses Supabase Auth for identity and Laravel for account eligibility,
Association ownership, Dashboard data, and Reports & Analytics. Administrative
and Field Officer accounts use the web application.

## Staging build

From `C:\dev\AssocMap\mobile`:

```powershell
flutter pub get
flutter analyze --no-pub
flutter test --no-pub --concurrency=1
flutter build apk --release --no-pub --dart-define-from-file=config/staging.json
```

`config/staging.json` contains the approved staging origin, Supabase origin and
user-supplied **public publishable key**. Public client configuration is embedded
in the APK. Never put a secret/service-role key, password, signing key, database
credential, or user token in this file or a Dart define.

APK output: `build/app/outputs/flutter-apk/app-release.apk`. The repository's
existing release configuration uses its debug signing configuration for local
evaluation. No private signing configuration is supplied here.

For this development laptop, the successful build used a 1 GB Gradle heap,
768 MB Metaspace and one worker through process-only settings. The exact command,
APK hash and verification results are in [M1 verification](docs/m1-verification.md).
No repository or global signing/memory settings were changed.

For development, use separate public configuration JSON files with the same
keys and pass the chosen file using `--dart-define-from-file`. The backend URL
must be the HTTPS application origin, without `/api` or an endpoint suffix.
The Supabase URL must be the HTTPS project origin, without `/rest/v1`.

## Modes and persistence

- `ASSOCMAP_DATA_MODE=api`: real SDK authentication and Laravel data; missing or
  invalid configuration blocks login. There is no demo fallback.
- `ASSOCMAP_DATA_MODE=mock`: synthetic in-memory authentication, disabled in
  release builds. Dashboard and Reports never fabricate organizational data.
- `ASSOCMAP_GIS_MODE=live|mock`: independently selects the public GIS adapter.
  With no GIS override, API mode selects live GIS and mock mode selects fixtures.
- Sessions use `flutter_secure_storage`, not ordinary preference storage.
  Passwords and report/dashboard snapshots are not persisted. Restored sessions
  must pass Laravel `/api/v1/me` again before protected navigation is available.
- Protected requests use HTTPS bearer authentication. A 401 permits one safe GET
  retry after SDK refresh; a repeated 401 or 403 removes protected access.
  Outages retain their error state instead of becoming empty or synthetic data.

The default unconfigured debug run remains the existing synthetic demo. Use
the staging configuration explicitly for the evaluation APK.

## Verification

See [M1 device acceptance](docs/m1-acceptance.md) for the manual release gate.
Automated SDK/HTTP tests use synthetic sessions and fake responses. They do not
prove a valid account can sign in to shared staging or that Android secure
storage works on a physical device. A real-account device check remains required.

Existing documents under `docs/` describe earlier phases; statements that live
authentication is pending in those historical documents predate Mobile M1.
