# AssocMap reporting runtime

Status (2026-10-16, Asia/Manila): targeted repairs and isolated local execution are complete; see [the audit and repair report](../docs/reporting-analytics-audit-and-repair.md) for exact scope, results and remaining limits. The actual Laravel/PostgreSQL/Python path, all eight PDF/XLSX report types, safe failure paths and shared Dart contracts passed locally. No dependencies were installed, no project environment file was used for testing, and live Supabase was not accessed. PHP currency labeling was explicitly approved by the owner during implementation. The sections below retain the existing architecture and deployment requirements.

## Source layout

```text
reporting/
  requirements.txt
  python/
    __init__.py
    main.py              # bounded stdin/stdout CLI transport
    contracts.py         # version, schemas, types, parent/period checks
    analytics.py         # canonical calculations
    exporters.py         # ReportLab PDF / openpyxl XLSX
    tests/test_analytics.py
```

Laravel integration is in `web/app/Services/Reporting`. No separate Python server, Supabase client, database credential, SQL input, reporting table, or migration was added. The installed Symfony Process component is reused through its argument-array constructor. Frontend charts use native bars and accessible data tables; no new JavaScript dependency was added.

## Contract and processing

The contract version is `assocmap.reports.v1`.

Laravel supplies exactly `version`, `action`, `meta`, and `datasets`. Actions are `analytics`, `pdf`, or `xlsx`. Metadata contains the report type, normalized filters, authorized association names, scope basis, generation timestamp with `+08:00`, `Asia/Manila`, and `currency: PHP`.

The allowlisted datasets are associations, members, classifications, projects, trainings, participants, monitoring, production, monthly production, income, activities, and visit reports. Only the datasets needed for the selected report are populated. Internal record IDs support relationship and distinct-count validation; member identity IDs are never included in the public report result. No member names, contacts, birth dates, FishR numbers, application evidence, tokens, or credentials are sent to Python.

Python validates fields, unique records, parent references, income frequencies, and quarter/month compatibility. Decimal inputs are strings; Python `Decimal` performs monetary arithmetic. The common result contains `version`, `title`, `meta`, `empty`, `notes`, and `sections`. Each section has typed columns, rows, measures, interpretation notes, and an optional chart description. Absent charts are empty JSON objects. Decimal results remain decimal strings in JSON to avoid JavaScript/Dart rounding; renderers write numeric Excel cells.

The engine returns `{ok, version, result}` and, for exports, `{artifact: {name, size}}`. Failures return a generic error code with a nonzero exit status. No tracebacks or private values are returned. Laravel validates the result, metadata, column types, bounds, and expected artifact before release. Exporters receive only the common result and never recalculate business totals.

## Authorization and database consistency

Web requests use existing `supabase.auth`, existing `role:*` middleware, and the `current_user` request attribute. The report scope service rereads the database profile and role; it does not use Laravel's default guard as a substitute identity.

- System Administrator: all associations, including archived associations, and unrestricted program-wide activities when no geographic scope is selected.
- Field Officer: `associations.primary_field_officer_id` must equal the current profile ID; the association must be active and unarchived, following the existing monitoring workspace's association eligibility. Archived child records within that authorized association remain available for historical reports.
- Association Account: exactly one active account link, exactly one link to that association, and an active unarchived association. This matches the existing mobile access policy. Another association/municipality ID cannot broaden access.

The mobile endpoints additionally require the existing Supabase bearer verifier and `mobile.association` middleware. No mobile export route exists. Web export requests by Association Accounts receive server-side denial before extraction or Python invocation.

Dataset extraction uses a PostgreSQL `REPEATABLE READ READ ONLY` transaction and a fixed 10-second statement timeout. Scope is rechecked inside that snapshot before child queries; independent child registers avoid multiplied joins. The transaction ends before Python processing. Current role/link/assignment scope is rechecked again outside the snapshot before releasing results/files. Read replicas are not used for report scope decisions. No domain writes occur.

Scope/option retrieval is bounded at 1,000 associations/projects/products, datasets at 5,000 rows each and 20,000 rows overall, JSON at 8 MiB, and artifacts at 12 MiB. Dataset extraction has a 30-second deadline checked before each bounded fetch; a query in progress can run up to its statement timeout. Oversized datasets are refused without silently truncating totals. Very large option catalogs need a separately reviewed searchable-options design.

## Deployment prerequisites — owner action pending

1. Deploy Laravel with its existing PHP `^8.2` / Laravel 12 dependencies and PostgreSQL configuration. Existing schema migrations 001/003/004/005 and the current authentication/link schema must already be deployed; source inspection did not verify live migration history. **Do not replay migrations for this module.**
2. Provide an approved CPython **3.12.x** virtual environment and review/install the two direct pinned dependencies in `requirements.txt`: ReportLab **4.4.10**, openpyxl **3.1.5**. No package was installed and no existing lockfile was modified. These provisional pins are not a vulnerability scan or a complete transitive lock; review transitive dependencies and create a deployment lock in the approved installation phase.
3. Configure `REPORTING_PYTHON_EXECUTABLE` through the deployment environment as an absolute executable path, including the actual virtual environment's `Scripts/python.exe` on Windows or `bin/python` on Linux. The source default is empty and fails safely. Do not point it at a shell command or a user-provided path. The entrypoint is fixed relative to the repository root.
4. Ship the `reporting/python` directory alongside `web`, preserving the monorepo directory relationship. Do not put it in `web/public` or mobile assets. A PHP-only hosting plan without child-process support cannot use this adapter; select a compatible host or approve a separate transport design.
5. Permit PHP `proc_open` and Symfony Process. Process execution is capped at 45 seconds, and outputs are byte-limited. Configure PHP/FPM/proxy request budgets for authentication, extraction, Python execution, and response transfer; approximately 120 seconds is a starting review target, not a benchmarked guarantee. Benchmark on the chosen host.
6. Restrict `web/storage/app/private/reporting` to the application OS account. New directories request POSIX mode `0700`; Windows requires NTFS ACL review. Keep storage outside public document roots and never link this folder under public storage. The private workspace is a random UUID directory.
7. Use a configured cache driver supporting shared atomic locks. There is one report process per user and two global process slots by default, with finite lock leases. Multiple app nodes need a shared cache driver. A process-slot limit is not an OS memory limit; configure resource limits and measure PDF/XLSX memory under load.
8. Python uses isolated mode, disabled bytecode writing, UTF-8 I/O, and a restricted inherited environment. Supabase/database credentials are removed. Only required OS directory variables, private temp directories and explicitly configured font paths remain. The CLI is a trusted subprocess, not an OS sandbox; deploy source read-only and run it with minimum OS permissions.
9. PDF defaults to ReportLab's bundled Vera TTF fonts. For broader Unicode, configure absolute `REPORTING_FONT_REGULAR` and `REPORTING_FONT_BOLD` paths to approved licensed TrueType fonts with all required glyphs. Missing glyphs stop PDF generation instead of silently producing broken text. `PHP` is used as the approved currency label; no peso glyph is required. No logo, signature or approval stamp was invented.
10. Review Laravel route/configuration caching during the owner's deployment phase. The follow-up Vite build passed with an empty environment directory and isolated output; deployment caches were not refreshed. This repair changes PHP/routes/Python, not frontend JS/CSS. Build assets when deploying the earlier frontend changes if necessary. Do not modify secrets based on this document or expose font/executable paths to clients.
11. Normal success/failure paths remove the workspace after loading the bounded artifact into the response. Files have no persistent download URL. Hard worker/host termination can leave an orphan. The source-only `php artisan reports:cleanup` command removes only direct UUID directories older than one day under the fixed private root and skips links. The owner may schedule it after review; no scheduler/infrastructure change was made and this command was not run.
12. Review operational failure monitoring using the generic `reporting_unavailable` / `report_cleanup_failed` log codes. No new audit event vocabulary or audit-table writes were introduced. Logs must never capture request tokens, datasets, artifact contents or raw process stderr.

PDF output wraps long cells, repeats headers, selects A4 orientation by table width, splits long rows and includes page numbers. XLSX has metadata, numeric summary, detail and data-quality worksheets, typed dates, frozen headings, filters, column widths and explicit literal-text cells to prevent formula injection. An amount exceeding Excel's 15-significant-digit numeric capacity fails generation rather than silently losing precision. PDF/XLSX layout and compatibility still require actual rendering/reopening checks.

Downloads use attachment disposition, the proper PDF/XLSX MIME type, `private, no-store` caching, `nosniff`, and no referrer. Results and options receive the same privacy headers.

## Flutter integration boundary

`ApiAuthRepository` still has `canAttemptSignIn == false`; the app has no installed Supabase SDK or secure session provider. Existing Laravel `/api/v1/me` and `mobile.association` source support a safe server boundary, but this does not establish an operational Flutter session.

The Reports tab, typed result parser, period controls, cards, bars, paginated tables, pull-to-refresh and failure states are implemented. The default repository deliberately reports integration unavailable, including in demo mode, and does not fabricate analytics. An API repository adapter is prepared to receive the **future approved authenticated JSON reader**; it is not wired to a token shortcut, web cookie, direct Supabase table query or unauthenticated client. It uses the configured HTTPS backend origin and server-defined relative reporting routes.

Before wiring that adapter, approve and implement SDK sign-in, secure persistence, coordinated refresh, `/me` eligibility, cancellation, a bounded authenticated reader with redirects disabled, safe 401/403/offline mapping, and logout handling as described by `docs/mobile-authentication-api.md`. Then inject the repository at `AssocMapApp.analytics`. No credentials, offline authorization cache or session architecture was added here. The screen clears report state when the app leaves the foreground and re-fetches on resume; actual secure session behavior requires that future integration and end-to-end QA.

## References consulted

- [Symfony Process](https://symfony.com/doc/current/components/process.html) — safe argument-array invocation, environment and timeouts.
- [ReportLab 4.4.10 release](https://pypi.org/project/reportlab/4.4.10/) — provisional dependency version.
- [openpyxl tutorial](https://openpyxl.readthedocs.io/en/stable/tutorial.html) and [3.1.5 release](https://pypi.org/project/openpyxl/3.1.5/) — workbook API and provisional dependency version.
- [Supabase API security](https://supabase.com/docs/guides/api/securing-your-api) — existing grants/RLS remain a separate database boundary; none were changed by this module.

The Supabase markdown changelog fetch was unavailable through the documentation tool. No new Supabase SDK/API surface was introduced; existing source contracts were retained. The follow-up verified the existing local packages and representative Unicode rendering. Hosted permissions, exact Supabase roles/RLS, complete font coverage and real-session browser/mobile behavior remain unverified.
