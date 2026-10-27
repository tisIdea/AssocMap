# AssocMap mobile

Flutter Android application with public GIS and Association Account access.
Live mode uses Supabase Auth for identity and Laravel for account eligibility,
Association ownership, Dashboard data, and Reports & Analytics. Administrative
and Field Officer accounts use the web application.

## Staging build

From this checkout's `mobile` directory:

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

The default unconfigured run selects API mode and fails closed when required
configuration is absent. Release mode also rejects an explicitly selected mock
GIS repository. Synthetic identities and organizational records are not a live
data fallback. Use the staging configuration explicitly for an evaluation APK.

## Proposed mobile UI

The public satellite map is the entry screen. The authenticated workspace follows
the five destinations Home, Members, Programs, Register and Profile. See the
[ten-page design comparison and missing backend contracts](../docs/mobile-proposed-ui-comparison.md).

Satellite imagery is independent of Laravel business data. The default public
Esri World Imagery URL and visible attribution can be replaced using
`ASSOCMAP_SATELLITE_TILE_URL` and `ASSOCMAP_SATELLITE_ATTRIBUTION` at build time.
Use an HTTPS tile service, retain the provider's required attribution, and confirm
deployment usage terms and quotas. Tile requests carry no Laravel bearer token;
they have separate 15-second / 1 MiB limits and no automatic transport retries.
An imagery outage is displayed explicitly and does not substitute a street map.

## Verification

### Report transport policy

Only `GET /api/v1/association/reports` uses a **120-second total HTTP deadline per attempt**
and a **25,166,848-byte (24 MiB + 1 KiB) decoded response-body limit**. The timer
starts before sending and covers both headers and the entire body; receiving
chunks does not reset it. Redirects remain disabled. Report options, identity
and Dashboard requests retain 25 seconds / 4 MiB. Supabase authentication keeps
25 seconds / 1 MiB; public GIS keeps 15 seconds / 3 MiB.

The deadline accounts for more than `web/config/reporting.php`'s 45-second Python
limit: Laravel identity verification permits 15 seconds, dataset extraction has
a 30-second cooperative deadline and a final query may use its 10-second statement
timeout. A further 20 seconds allows scope resolution/recheck, serialization and
network transfer. This is a finite client policy, not a server-wide execution
guarantee: unbounded scope queries, proxy limits or sufficiently slow transfers
can still exceed it.

The backend's 8 MiB `max_json_bytes` bounds the Python process output, not the
final HTTP body. Python emits compact UTF-8 (`ensure_ascii=False`); Laravel's
default JSON encoding escapes Unicode and slashes. A two-byte UTF-8 character
can become six ASCII bytes (`\u00e9`), and a four-byte character can become a
twelve-byte surrogate pair. The 3x allowance covers this expansion; another
1 KiB covers the `{"data":...}` envelope. Both declared Content-Length and
accumulated stream bytes are checked, including responses without a length.
Revisit these constants with changes to the backend size/encoding contract.
The larger bound also raises possible transient memory use during JSON decoding.

Timeout or cancellation closes the per-request client and cancels the body
subscription, including stalled streams. Logout/provider sign-out cancels active
Laravel requests; existing session-generation checks prevent late data or refresh
results from restoring access. The client remains reusable for a later sign-in.
No transport retries are added, including for writes. The session coordinator
permits one report GET retry after a 401 and SDK refresh, so that flow can exceed
120 seconds overall. SDK refresh retains its separate existing timeout/retry
policy. Report timeouts, 403, 429, 503, oversized bodies and malformed JSON do not
trigger automatic report retries.

See [M1 device acceptance](docs/m1-acceptance.md) for the manual release gate.
Automated SDK/HTTP tests use synthetic sessions and fake responses. They do not
prove a valid account can sign in to shared staging or that Android secure
storage works on a physical device. A real-account device check remains required.

Existing documents under `docs/` describe earlier phases; statements that live
authentication is pending in those historical documents predate Mobile M1.
