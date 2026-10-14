# Desktop 2 mobile authentication contract — Stage 3C-A

Status: source implementation complete; syntax, automated tests, deployed schema and real Auth behavior remain unverified.

## Authentication boundary

Flutter signs in with the approved Supabase public-client SDK using email/password, the project HTTPS origin and its publishable key. Flutter owns the access/refresh session, secure persistence, rotation and logout. Laravel verifies each bearer identity against the configured Supabase `/auth/v1/user`, then checks current AssocMap database permissions. There is no Laravel mobile login, refresh or logout broker.

Do not embed service-role keys, secret keys, Storage credentials or database connection information in Flutter. Do not send passwords or refresh tokens to Laravel. Organizational data goes through Laravel, not direct Supabase table access. Public GIS remains available without a session.

## Configuration names

- Laravel already uses `SUPABASE_URL` and `SUPABASE_PUBLISHABLE_KEY` from `config/services.php`. URL must be an HTTPS project origin, without `/rest/v1`, `/auth/v1`, query, fragment or user information. The public key must be a publishable key or a validly formatted legacy anon key; a privileged key is rejected by the API verifier.
- Flutter needs that same approved Auth project origin and publishable key, plus the Laravel API origin. Suggested build-setting names are `SUPABASE_URL`, `SUPABASE_PUBLISHABLE_KEY` and `ASSOCMAP_API_BASE_URL`; Flutter configuration is not implemented in this stage.
- No new Laravel environment variables are introduced. Invitation administration and photo Storage configuration are separate and unchanged.
- The owner must confirm the correct environment/project pairing, deployed migrations 001/006, Auth settings and SDK compatibility without sharing credentials. The Storage smoke-test project is not automatically the mobile Auth project.

## GET /api/v1/me

Send `Accept: application/json` and `Authorization: Bearer <user-access-token>` over HTTPS. No browser session, CSRF cookie, Supabase API key, refresh token, association selector or credentials are required in this Laravel request. Tokens in URL parameters are not accepted. API access tokens are JWT-shaped strings limited to 8 KiB; their authenticity is verified by Supabase, not by unverified JWT decoding.

Eligible response: HTTP 200.

```json
{
  "data": {
    "id": "11111111-1111-4111-8111-111111111111",
    "display_name": "Synthetic Association Account",
    "role": {
      "code": "association_account",
      "name": "Association Account"
    },
    "is_active": true,
    "mobile": {
      "eligible": true,
      "reason": null,
      "association": {
        "id": "22222222-2222-4222-8222-222222222222",
        "name": "Synthetic Test Association"
      }
    }
  }
}
```

Existing `data.id`, `display_name`, `role` and `is_active` fields are preserved. User IDs and association IDs are UUID strings. Display names are text, not HTML. `mobile.association` contains only `id` and `name` when eligible. Email, contact information, invitation history and internal link identifiers are not returned.

An otherwise authorized but mobile-ineligible profile still receives HTTP 200 with its existing identity fields and this mobile shape:

```json
{
  "eligible": false,
  "reason": "association_link_unavailable",
  "association": null
}
```

| Reason | Meaning |
| --- | --- |
| `role_not_supported` | Current active application role is not `association_account`. |
| `association_link_unavailable` | Missing, inactive, invalid or inconsistent account link. |
| `association_unavailable` | Linked association is missing, inactive, archived or lacks a usable display name. |
| `null` | Eligible; association object is present. |

Missing/inactive profiles, inactive/missing roles and existing invitation restrictions are HTTP 403, not mobile-context responses. Legitimate active legacy profiles need no universal invitation or `activated_at` value. Login never activates accounts or creates links.

## Error contract

```json
{
  "message": "Authentication verification is temporarily unavailable.",
  "error": { "code": "authentication_unavailable" }
}
```

Use HTTP status and `error.code`; do not parse English messages. Errors contain no `data` context.

| HTTP | Code | Meaning/action |
| --- | --- | --- |
| 401 | `unauthenticated` | Missing/malformed token, or recognized provider token/session rejection. A single coordinated refresh may recover an expired session; otherwise require sign-in. |
| 403 | `account_unavailable` | Confirmed application access denial, banned/anonymous identity, unavailable profile/role or existing invitation restriction. Do not refresh repeatedly. |
| 403 | `mobile_access_denied` | Future mobile guard rejects current Association Account eligibility. No business endpoint uses this guard in production yet. |
| 404 | `not_found` | Resource/route absent or outside scope. Future resource handlers must query within scope. |
| 405 | `method_not_allowed` | Unsupported request method. |
| 422 | `validation_failed` | Controlled generic validation failure; no business form APIs exist in this stage. |
| 429 | `rate_limited` | Laravel `/me` limit reached; respect numeric `Retry-After`. |
| 503 | `authentication_unavailable` | Provider timeout, outage, invalid public configuration, unknown/contradictory rejection, redirect or malformed identity response. This does not establish bad credentials. |
| 503 | `service_unavailable` | Database or unexpected internal failure; no eligibility context is granted. |

All `/me` responses and versioned API error responses use `Cache-Control: no-store, private, max-age=0`, `X-Content-Type-Options: nosniff` and `Referrer-Policy: no-referrer`. HTTP 401 also has `WWW-Authenticate: Bearer`. `/me` permits 60 requests per minute per client IP, before provider verification. This limit may affect shared networks and requires deployment review. Upstream provider throttling is represented as retryable authentication unavailability, not invalid credentials.

The verifier uses a 5-second connection timeout and 15-second request timeout, TLS verification, no redirects, no cookies and no automatic retry. It requires an `application/json` response and rejects non-object/malformed JSON and identity bodies larger than 64 KiB after receipt. Only validated identity ID/email are retained internally. It does not expose provider messages or metadata. Recognized Auth error identifiers are checked for consistency; an unexplained HTTP 401 from a gateway is not assumed to mean an invalid user token. Versioned API exception responses normalize server failures to controlled HTTP 503.

## Flutter session responsibilities

1. Sign in using the Supabase SDK, then request `/me` before unlocking protected navigation.
2. Require a valid response, `role.code == association_account`, `is_active == true`, `mobile.eligible == true`, null reason and valid association object. Missing/unknown/malformed contract fields deny protected access.
3. Store sessions using approved Android secure storage. Never log tokens, passwords, authorization headers or private response bodies.
4. Let one Supabase session/refresh coordinator own rotation. Use provider expiry information; the old demo expiry is not authoritative. Serialize refresh attempts and safely persist replacement sessions.
5. On an appropriate 401, refresh once and retry an idempotent read once. Do not automatically replay uncertain business writes. HTTP 403 and ineligible contexts are denial states, not refresh loops.
6. Recheck `/me` after sign-in, session restoration and return to protected activity. Auth-event stream errors, including offline refresh failures, must be handled.
7. Logout explicitly with `SignOutScope.local` to preserve independent browser/device sessions. Clear local credentials, protected caches and pending authenticated work. If offline provider revocation cannot be confirmed, do not claim successful remote revocation.
8. Keep public GIS usable when authentication is absent, denied or temporarily unavailable. No offline organizational authorization is granted by this contract.

Supabase manages refresh-token rotation and provider session revocation. Cryptographic JWT validity may outlast logout; current Auth `/user` implementations also check session existence and banned users. Immediate logout/ban rejection must be verified on the isolated deployment. This stage introduces no local token denylist or immediate revocation guarantee.

## Future Laravel endpoint requirements

- Apply `ApiRequestGuard`, `supabase.api.auth` and `mobile.association` middleware. Only `supabase.api.auth` creates the server-verified identity attribute; browser cookies and client JSON cannot supply it.
- `MobileAssociationAccessService::resolve()` rereads profile, role, invitation eligibility and current relationships using the write connection. Account-link/association data is read together in a bounded query. Duplicate user links or multiple links to the same association fail closed.
- `mobile.association` resolves eligibility again and supplies the server-side association ID. It does not automatically constrain arbitrary controller queries.
- Every record query must use `MobileAssociationAccessService::scope($query, $verifiedIdentity, $serverDefinedAssociationColumn)` or an equivalent authorized query. Never accept a client association ID as authority. This helper supports Laravel Eloquent and query builders and adds a bound association predicate; the column argument must be server-defined.
- Group OR filters in nested `where` callbacks. Top-level `orWhere` conditions or unions can broaden SQL beyond a bound association predicate; this helper is not a sandbox for arbitrary query construction. Review and test each future endpoint's complete query.
- Query within scope before returning a record, so out-of-scope records receive generic 404. Apply additional ownership and existing business rules separately.
- Revalidate and lock relevant authorization/lifecycle/ownership rows inside each future write transaction. Request middleware and `/me` are snapshots, not a concurrency barrier or permanent permission grant.

No member, project, training, monitoring or photo APIs are created. Existing web business services must be reviewed for lifecycle and transaction checks before reuse; this stage does not correct their unrelated write paths. `/api/user` is separate Laravel/Sanctum functionality and is not part of this contract.

## Verification pending

New focused suites are `tests/Unit/SupabaseApiIdentityTest.php` and `tests/Feature/MobileAuthenticationTest.php`. They use synthetic identities, HTTP fakes, existing stray-request prevention and the existing SQLite in-memory environment guard. The feature suite registers test-only scope routes and permits intentionally inconsistent relationships only in its disposable fixtures. These routes/schema fixtures do not modify application routes or migrations.

The owner must execute syntax/focused regression checks in an uncached isolated environment. Include existing invitation and test-isolation suites to check browser/invitation compatibility. SQLite results cannot prove PostgreSQL constraints, query-plan performance, locking or Supabase session behavior. Separate approved Auth integration testing is required for expiry, rotation, logout replay, disabled identities and environment mismatch. Never weaken the isolation guards or use organizational data for testing.

Manual commands, to be run by the owner only after confirming the isolated, uncached test setup:

```powershell
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --filter TestEnvironmentIsolationTest
php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml --filter 'SupabaseApiIdentityTest|MobileAuthenticationTest|InvitationActivationServiceTest|SupabaseInvitationAuthTest'
```

Run from the Laravel test workspace root with the reviewed source and installed dependencies. The global guard must remain enabled and will refuse cached configuration or non-memory database connections. Do not clear or bypass the guard to make a test run. No verification command was executed during implementation.

Official references: [Flutter sign-in](https://supabase.com/docs/reference/dart/auth-signinwithpassword), [Auth error codes](https://supabase.com/docs/guides/auth/debugging/error-codes), [session lifecycle](https://supabase.com/docs/guides/auth/sessions), [sign-out scopes](https://supabase.com/docs/guides/auth/signout), [publishable keys](https://supabase.com/docs/guides/getting-started/api-keys).
