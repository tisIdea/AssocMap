# Reports and Analytics — owner QA plan

Status: this remains the owner's real-session browser/deployment checklist. Overlapping automated scenarios have passed in the separately isolated [2026-10-16 audit and repair run](reporting-analytics-audit-and-repair.md); that does not mark an unperformed manual checklist row complete. Use synthetic data in an isolated approved test environment. Do not replay migrations, modify production records, bypass test isolation or use organizational evidence files to create fixtures.

## Prerequisites and evidence

- Review `reporting/README.md`, approve dependency installation, provision the configured CPython runtime/packages and a suitable host, and confirm the existing deployed schema independently.
- Keep the existing Laravel test-environment guard enabled. The new scope test uses its own in-memory SQLite connection; it does not verify PostgreSQL extraction, constraints or transaction behavior.
- Record date/time/timezone, source revision/diff snapshot, role, synthetic association scope, filters, expected result, actual result, redacted screenshot/API result, file inspection and pass/fail for each case. Never record tokens, credentials, member identities or evidence photos.
- Consult the linked run for executed Laravel/Python/Dart/native PostgreSQL/browser-fixture checks. Flutter widget execution and actual authenticated mobile integration remain unverified. Do not point test fixtures at a real dataset.

## Access and filters

| Case | Steps | Expected / what it proves |
| --- | --- | --- |
| Admin overview | Open `/admin/reports`, select Comprehensive and a recorded year. | Authorized association data and event results; current-snapshot sections explicitly labeled. No fabricated dashboard totals reused. |
| Municipality | Choose municipality A, then association A within it; compare with municipality B. | Results narrow correctly through association → area → barangay → municipality. Another municipality/association combination is rejected. |
| Association | Select one association, then all authorized associations. | Both dataset and filter options stay within permitted scope; aggregate counts match distinct IDs. |
| FO current assignments | Sign in as an FO assigned only to A, open `/field-officer/reports`. | A appears; B's names, IDs, projects, products, periods and amounts do not appear in responses or option lists. |
| FO forged filters | Send direct GETs to the FO data/options endpoints with B's association/project/product UUID and B's municipality. | Scope is denied or safely yields no unauthorized data; forged association/municipality/project selections receive 403. A municipality filter never adds B to scope. |
| FO reassignment | Load A, reassign A away using the approved normal workflow, request results/export again. Also revoke assignment while a generation is in progress. | Future requests omit A; an in-flight scope change is denied before response release. Existing downloaded files are not revocable. |
| Role/account revocation | Disable the role/profile/link in the isolated setup and repeat a request. | Existing auth and fresh reporting scope checks deny access. No stale-role export is delivered. |
| Association web | Open `/association/reports` with one active linked association. Forge another association/municipality/project UUID. | Own-association analytics only; unrelated filters rejected. No PDF/XLSX controls. |
| Association export | With that browser session, POST to both `/association/reports/export/pdf` and `/association/reports/export/xlsx` with valid CSRF. Try the Admin/FO export paths too. | HTTP 403; no extraction/engine execution or file. Hidden controls are not the authorization boundary. |
| Inconsistent links | Test absent/inactive/duplicate user links and duplicate association links in disposable fixtures only. | Fail closed with no association report data. Production constraints must not be weakened to create fixtures. |
| Archived access | Admin views an archived association. FO and Association Account request the same archived association. Check archived members/projects/trainings within a currently eligible association. | Admin can report archived association data. FO/account are excluded from the archived association. Authorized child history remains recorded, not deleted. |
| Unsupported user/guest | Request all three page/data/options/export surfaces while unauthenticated or in another role. | No restricted data. Web login redirect or 403; bearer API 401/403 as applicable. |
| Validation | Submit arrays for scalar fields, malformed UUIDs, unknown keys/report types, year outside 2000–2100, quarter 5, month 13, month outside chosen quarter and irrelevant filters on Member Summary. | Controlled validation response; no raw SQL/traceback and no out-of-scope fallback. |
| Empty scope | FO has no assignments, or eligible association has no members/projects/events in the selected period. | Truthful empty sections/results; missing income shows unobserved, not zero or successful fabricated analytics. |

Web data/options paths are `<role-prefix>/reports/data` and `<role-prefix>/reports/options`. Exports are POST `<role-prefix>/reports/export/{pdf|xlsx}`. Refresh stale option lists after assignment changes.

## Calculations and periods

| Case | Synthetic setup / action | Expected |
| --- | --- | --- |
| Distinct associations/localities | Several associations in one barangay; equal barangay names in different municipalities. | Association total counts IDs once; groups remain separate by locality IDs. |
| Classification multiplication | One member with two classifications, another archived member with one. | Member total is two; class counts overlap and are explicitly not additive. Archived member retained. |
| Current vs historical status | Current project is completed; a prior quarterly monitoring row says ongoing. | Portfolio displays current completed; historical monitoring displays ongoing. |
| Training distinct people | One archived member is present in two selected trainings; a second member is absent in one. | Three participant rows, two distinct members recorded, two present occurrences, one distinct member marked present. No trained-member KPI. |
| Training dates/status | Include scheduled, completed, cancelled and archived trainings around quarter boundaries. | Date filter uses start date. Completed-activity count is separate; attendance is not silently restricted to an invented eligibility rule. |
| Decimal arithmetic | Gross income entries `0.10` and `0.20`. | Result and all exports show exactly `0.30` PHP. |
| Missing vs zero | One production observation has no income; another has recorded income `0.00`. | No-income amount is unobserved with coverage count; recorded zero stays numeric `0.00`. |
| Quarterly/monthly output | Quarterly actual `10.00 kg`; monthly detail `8.00 kg`; separate observation `5.00 pieces`. | Quarterly actual remains `10.00 kg`. Monthly detail remains separate. No `18.00` total and no `15.00` mixed-unit total. |
| Production month filter | Select month 1 in Q1 with monthly data only in months 1 and 3. | January details only; quarterly table clearly remains quarter context. February does not get fabricated zero output. |
| Income frequencies | One product uses monthly income, another quarterly income. Select January. | Only recorded January monthly income is included. Quarterly entry exclusion is disclosed; it is not divided into thirds. |
| Invalid source relationships | Isolated contract fixtures contain mixed monthly/quarterly income on one parent, wrong-quarter month or duplicate parent/product rows. | Contract rejects invalid input. Test source is disposable; never weaken live constraints. |
| Year vs program year | Project program year 2025, monitoring year 2026; training/activity dates in 2026. | Program-year cohort and reporting-year observations filter independently as documented. |
| Visits vs containers | One monitoring record with two visit reports, one of which is legacy imported; one visit date outside parent quarter. | One monitoring container, two visit-report rows, one legacy import. Scope uses parent period; dates/source remain visible. No verified physical-visit claim. |
| Global program activities | Admin views an unlinked activity with no geographic filters, then chooses a municipality/association. Repeat as FO/account. | Admin sees it only without geographic filters; FO/account never see it. |
| Cross-format consistency | Freeze fixture data. Request web result, PDF, XLSX with identical applied filters. | Same definitions, row values and totals; timestamp may differ. A later operational update may legitimately change a freshly generated export. |

## Export, UI and failure checks

| Case | Steps | Expected |
| --- | --- | --- |
| PDF | Generate every report as Admin and FO; test long Cebuano/Filipino names, `ñ`, long project names, empty sections and multi-page/wide tables. Render/inspect every PDF page. | Correct scope/type/filters, PHP labels, units, timestamp/timezone, repeated headings and page numbers. No overlapping/clipped cells. Unicode either renders correctly or produces a controlled font failure. No unauthorized names. |
| XLSX | Open every report in Excel or LibreOffice; inspect metadata/summary/details/quality sheets, freeze panes, filters, numeric/date cell types. | Legitimate XLSX workbook, decimals usable numerically, dates typed, scope/units preserved. Empty cells are explained, not mistaken for recorded zero. |
| Formula injection | Set disposable text fields to `=1+1`, `+SUM(1,2)`, `-1+2`, `@SUM(1,2)`, and a leading whitespace formula string. | Literal text cells; no formulas, external links, macros or network requests created by export. |
| Excel precision limit | Use an isolated result with a decimal amount exceeding 15 significant digits. | Controlled failure, not silent rounding. |
| Download headers | Inspect response headers for both formats. | Correct MIME, attachment filename, private/no-store, nosniff and no-referrer. No public artifact URL. |
| Filter interaction | Switch among all report types, municipalities, associations and projects; use keyboard and narrow screens. | Only relevant fields submit; municipality choices do not broaden scope. Changed filters clear stale preview/disable export until applied. No mismatched export filter snapshot. |
| Chart alternatives | Inspect bar labels/units/context; paginate complete detail tables with keyboard/screen reader. | Charts use canonical values; 20-group chart cap disclosed. All bounded rows available through table pages. |
| No JavaScript | Reload page with JavaScript disabled; use the provided report-type links before applying that report's filters. | Server-filter form and report content work; first-100-row preview limit is disclosed. Export uses applied filters. No decorative values. |
| Large result | Exceed 5,000 rows in a selected dataset or 20,000 overall; also test a near-limit valid report. | Oversized report rejected with smaller-scope guidance, never partial totals. Near-limit performance/memory measured. More than 1,000 options is a documented catalog limitation requiring design review. |
| Process unavailable | Temporarily use an invalid executable in the isolated deployment configuration; deny process permission or omit renderer dependencies. | Generic unavailable state; no fake export, traceback, environment value or stderr exposed. Analytical JSON needs no renderer package import. |
| Timeout/size | In approved isolated fault fixtures exceed process timeout, output size or artifact bounds. | Process stopped, response controlled, workspace/locks released. No unfinished download labeled successful. |
| Concurrency | Start concurrent report requests with one user, then more than two users. | Per-user/global slots enforce limits and return retryable 429; no workspace collision. Validate shared-cache behavior on multiple nodes if used. |
| Cleanup | Check private folder after success, renderer failure, size failure and denial after reassignment. Simulate worker termination, then review/run `reports:cleanup` separately. | Normal workspaces removed; orphan cleanup touches only old direct UUID directories, skips links and leaves current workspaces alone. Verify Windows ACL/POSIX mode. |
| Browser session expiration | Expire session then request analytics/export. | Sign-in/controlled failure and no data; frontend does not treat an HTML login response as successful analytics. |

## Mobile — blocked until secure authentication integration

Current executable UI behavior is intentionally the integration-unavailable state. No end-to-end mobile result has been verified.

After separate approval and implementation of the SDK/session integration:

1. Sign in as an eligible Association Account using the approved real session. `/api/v1/me` must approve the account before protected navigation unlocks.
2. The authenticated reader must call GET `/api/v1/association/reports` and `/api/v1/association/reports/options` over the configured HTTPS backend origin, using bearer authorization. Do not use browser cookies, demo identity, hardcoded tokens or direct Supabase table reads.
3. Compare all eight report types and identical year/quarter/month/program-year filters with the same account's web result. Returned amounts/counts must be unchanged; Dart only displays/scales.
4. Confirm reporting navigation, summary cards, chart text alternatives, table pagination, keyboard/text scaling, pull-to-refresh and normal narrow phone widths without overflow.
5. With no recorded data, confirm empty state and unobserved-vs-zero labels. PDF/XLSX controls must never appear.
6. In airplane mode or a timeout, clear prior report data and show retry/offline feedback. No cached protected snapshot may establish offline authorization.
7. Exercise expired token (one coordinated refresh/retry through the approved reader), 403 (deny without refresh loop), association reassignment/link/archive changes, malformed JSON, oversized response and redirects. The reader must map failures to `AnalyticsFailure` without exposing private bodies.
8. Sign out while a request is pending; background/resume; switch accounts. Stale asynchronous responses must not restore old reports. Data must be cleared and eligibility revalidated.
9. Call the mobile endpoints with FO/Admin/guest bearer identities and forged association/project filters. They must not grant association analytics to unsupported roles or return unrelated data.

Do not mark mobile reporting, runtime exports, security certification or production readiness complete until the corresponding evidence is recorded.
