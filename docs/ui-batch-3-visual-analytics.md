# UI Batch 3: Visual Analytics and Report Usability

Verified on 2026-10-26 in `C:\dev\AssocMap-MemberAPI`, branch `ui/visual-analytics`, starting HEAD `3477b240cf132f116898b1e182bdb13ff18a0d01`. This batch implements report presentation only. No backend, Python contract/calculation, database, route, authorization, dependency, contextual-export, or authentication code changed. No commit, push, merge, or deployment was performed.

## 1. Before and after

Before, canonical section summaries, notes, simple bars and tables were interleaved. Eleven sections had basic bar metadata; attendance, classifications, target/actual comparisons and recorded monthly quantities were available primarily as tables.

After, the existing filter/export toolbar leads to reporting scope and applied period, consolidated summary indicators, visual analytics, data interpretation/quality notes, and detailed tables. Each chart states what is measured, scope, period, units and limitations. Exact chart values are available through keyboard-operated native disclosures, with links to the full detailed table. Existing branding, filters, summaries and download handling remain in use.

The HTML workspace adds category bars, status doughnuts, grouped attendance/output bars, and a conditional monthly production line. PDF/XLSX retain canonical tables, summaries and notes. They do **not** include the new chart images; the page and export menu say this explicitly.

## 2. Charts by existing report type

| Report type | Implemented visuals when suitable recorded data exists |
| --- | --- |
| `associations` — Association Distribution | Municipality and barangay count bars; current-status doughnut or count bars. |
| `members` — Member Summary | Retained members per association; overlapping classification count bars. |
| `projects` — Project Portfolio | Current-status doughnut or count bars; component count bars. |
| `trainings` — Training Participation | Present/absent grouped occurrence bars per training; component activity-count bars. |
| `production` — Production Performance | Target/actual grouped bars per product-quarter observation, separated by recorded unit; observation-coverage count bars; conditional recorded monthly quantity line. |
| `income` — Gross Income | Recorded gross-income bars by existing quarter/frequency labels, in PHP. |
| `activities` — Activities and Monitoring | Program-activity counts by component; historically saved monitoring-status counts. |
| `comprehensive` — Comprehensive Report | Corresponding section visuals above. Its existing filters cannot explicitly select a product, so it does not show the single-product monthly line. |

Status doughnuts use the complete selected mutually exclusive population, with at most eight categories and a positive total. Larger/zero-total populations use bars. No percentage KPI is introduced. Classifications always use bars because a member can have several classifications.

## 3. Source fields and calculations

All numeric values come from unchanged [Python analytics](../reporting/python/analytics.py) and its validated canonical result. The browser changes presentation, proportional drawing scales and calendar placement only. It does not create new business aggregates or fetch data for individual charts.

| Visual / canonical section | Source and existing calculation | Measurement and interpretation |
| --- | --- | --- |
| Municipality/barangay / `association_municipality`, `association_barangay` | `associations.municipality_id` / `barangay_id`; Python ID-based grouping supplies `label`, `count`. | Distinct associations; barangay label includes municipality. Same-named localities are not merged by the browser. Current snapshot. |
| Association status / `association_status` | One `associations.status` per authorized association; canonical `label`, `count`. | Current status counts; archive state is separate. |
| Members / `members` | Python counts retained `members` by `association_id`; chart reads `association`, `total`. | Retained member count, including archived members. No personal member fields. Current snapshot. |
| Classifications / `classifications` | Python distinct member-ID sets per classification; chart reads `classification`, `members`. | Distinct members per overlapping classification. Counts cannot be added to obtain the member population. |
| Project status / `project_status` | One `projects.status` per selected project; canonical `label`, `count`. | Current statuses, including retained archived projects. No success/effectiveness inference. |
| Project components / `project_component` | Python counts selected `projects.component`; canonical `label`, `count`. | Portfolio counts; program-year cohort, not event-year trend. |
| Training attendance / `trainings` | Python participant rows per training with `attendance_status`; canonical `association`, `training`, `date`, `present`, `absent`. | Attendance occurrences, not distinct trained people. Training start date determines event-period selection. |
| Training components / `training_component` | Python counts selected training activities by component; canonical `label`, `count`. | Training activity count, not training effectiveness. |
| Target/actual / `production` | Canonical `association`, `project`, `product`, `year`, `quarter`, `unit`, `target`, `actual` from production `target_output` / `actual_output` plus parent context. | One pair per product-quarter observation. Exact unit panels; no sums across units or periods. |
| Monthly line / `monthly_production` | Canonical `year`, `quarter`, `month`, `quantity`, `unit`, `project`, `product` from monthly `total_quantity` plus parent production context. | Saved monthly quantities within selected year/quarter. Requires explicit project and product ID filters, one unit, unique year-month observations, and at least two recorded months. No parsing of display names to identify a series. |
| Production coverage / `production_coverage` | Python counts observations by parent `year`, `quarter`, recorded unit; chart reads `period_unit`, `count`. | Observation counts, not output quantities or a performance rate. |
| Income / `income_periods` | Existing Decimal sum of recorded `gross_income`, grouped by year/quarter and monthly/quarterly frequency; chart reads `period`, `gross`. | Recorded gross income in PHP, not profit/net income. Missing/incomplete monthly entries do not establish a complete-quarter total. |
| Activity components / `activity_component` | Python counts selected program activities by component; canonical `label`, `count`. | Recorded activities, not livelihood outcomes. Existing program-wide scope rules remain applicable. |
| Monitoring statuses / `monitoring_status` | Python counts selected quarterly monitoring containers by saved `project_status`; canonical `label`, `count`. | Historically recorded container status, not current project status or verified physical visits. |

Summary indicators use existing section `measures[].label/value` unchanged; no new score or business formula was added.

## 4. Filters, scope and missing observations

The unchanged [ReportCatalog](../web/app/Services/Reporting/ReportCatalog.php), [ReportRequest](../web/app/Http/Requests/ReportRequest.php), [ReportScope](../web/app/Services/Reporting/ReportScope.php) and report service remain the authority. Charts consume the same result as the preview. Applied filters, generated timestamp/timezone, authorized association names and scope basis appear above the summaries.

| Report family | Existing supported filters besides `report_type` |
| --- | --- |
| Associations, members | `municipality_id`, `association_id` |
| Projects | Above plus `program_year`, `component_id`, `project_id` |
| Trainings, activities, comprehensive | Above plus `program_year`, `year`, `quarter`, `component_id`, `project_id` |
| Production, income | Above plus `program_year`, `year`, `quarter`, `month`, `component_id`, `project_id`, `product_id` |

System Administrators retain existing authorized organizational scope, Field Officers retain current assigned associations only, and Association Accounts retain their active own-association preview scope with **no export controls or export permission**. Guests receive no private reporting access. Archived records remain subject to current authorization; history does not restore ended access.

- Null output is labelled **Unobserved**, separately from recorded `0` / `0.00`. Empty selections show a clear message and retain canonical summaries/tables. Invalid/missing numeric chart data is never coerced to zero.
- Monthly lines use the selected calendar year/quarter, order months chronologically, retain explicit zeros and break at missing months. They do not interpolate, calculate growth, or combine monthly details with their quarterly actual output. Single-month selections cannot form a line. Ambiguous, duplicate, mixed-unit or insufficient series stay in detailed tables with an explanation.
- A month filter restricts monthly details; quarterly target/actual and coverage remain quarter context. Income frequency groups remain separate; quarterly income is not allocated to months.
- Recorded zero attendance rows indicate no recorded participation, not proven zero actual attendance. Distinct-member indicators remain separate from attendance occurrences.
- Charts do not reconstruct historical association/member/project populations or infer profitability, beneficiary success, effectiveness or self-sufficiency.
- Chart categories/observations are bounded to the first 20 in canonical order, with a visible limit and all detailed rows retained. Production uses at most eight exact-unit panels, with an explicit notice when more units exist. Doughnuts are never truncated subsets of a whole.
- Visible numeric labels and chart-value tables preserve canonical decimal digits. Drawing coordinates use JavaScript numbers only for proportions; monthly axis ticks use compact formatting while exact point values remain visible below the plot and in its table.

## 5. Files changed

| File | Responsibility |
| --- | --- |
| `web/resources/js/components/report-analytics.js` (new) | Bounded chart model, native HTML/SVG rendering, exact value formatting, descriptions and accessible table equivalents. |
| `web/resources/js/pages/admin/reports.js` | Organized results and lazy chart-module loading; stale-result and import-failure protection. Existing filters/tables/download integration retained. |
| `web/resources/css/pages/admin-reports.css` | Scoped reporting sections, chart cards, legends, axes, mobile layout and scrolling regions. |
| `web/resources/views/admin/reports/index.blade.php` | Matching no-JavaScript organization, scope/summary/notes/tables and truthful export explanation. |
| `web/tests/Frontend/report-analytics.test.mjs` (new) | Thirteen chart-model/rendering tests for data correctness, gaps, units, bounds and accessible equivalents. |
| `web/tests/Frontend/reports-workspace.test.mjs` | Updated organization assertions and lazy-load failure/stale-result/unavailable coverage. |
| `web/tests/Feature/ReportAuthorizationTest.php` | Organization and no-JavaScript regression cases for all three roles. |
| `docs/ui-batch-3-visual-analytics.md` (new) | This implementation and verification record. |

## 6. Dependencies and performance

No dependency additions/upgrades or manifest/lockfile changes. Native bars, SVG and HTML tables are sufficient for the bounded static comparisons; a charting library would add runtime weight without a required interactive feature.

The final Vite build emits a separate dynamic `report-analytics` chunk: **11.61 kB, 4.75 kB gzip**. It loads only when rendering a report result. Initial chart rendering reuses the already-generated canonical result; the browser made zero report data/options/export requests on fixture page loads. Ordinary module pages do not invoke the dynamic import. Existing extraction limits, payload/artifact limits, locks, timeouts, throttles and server error handling were not changed.

## 7. Verification executed

All write-oriented tests used synthetic fixtures, SQLite `:memory:`, isolated cache/session/storage paths and fake external services. No configured shared Supabase database/Auth operation or migration was performed.

| Check | Result / evidence boundary |
| --- | --- |
| Focused PHP baseline | **131 tests, 1,738 assertions passed** before changes. |
| Final PHP regressions | **134 tests, 1,795 assertions passed**: `TestEnvironmentIsolationTest`, `ReportAuthorizationTest`, `ContextualReportTest`, `WorkspaceHeaderTest`. Includes existing scope/reassignment/revocation/cross-association denial, CSRF/expiry, valid/invalid filters, PDF/XLSX binary response handling, limits and export-policy checks, plus new three-role Blade fallback checks. |
| All frontend Node tests | **261 total: 258 passed, 0 failed, 3 skipped**. The skips are existing native association-form browser fixtures, unrelated to this change; they were not counted as passed or rerun in this batch. Thirteen dedicated chart tests verify exact values/formatting, proper chart selection, overlapping classes, units, zero/null, time ordering, explicit series identities, line connections/gaps, bounds, safe text output and stable status colors. |
| Real Blade and native Edge | **90 role/report/viewport cases passed**: three roles, eight report types plus selected monthly-line and empty variants, at 320/768/1440 px. Checked chart counts/kinds, role export visibility, scope/period/unit context, captions/value tables, keyboard disclosures, detail-table links, no page overflow and no chart-generated report requests. Additional no-script fallback, three unavailable-role pages and simulated chart-chunk failure passed. No browser runtime exceptions. Fixtures rendered with zero DB connections. |
| Visual review | Reviewed desktop report charts, mobile layout, scope/summary header and monthly line at 1440/320 px. Reviewed both pages of synthetic Production and Gross Income PDFs. Missing-month gaps and scrollable narrow-screen month labels remain understandable; exact values stay available. |
| Real Python outputs | Generated **16 artifacts: PDF and XLSX for all eight existing report types** through the unchanged Python entrypoint. Canonical JSON results matched preview calculation for both formats. Reopened all workbooks and checked row counts, exact text/integer/date/decimal cell values and absence of formula cells. Reopened all eight PDFs and verified all canonical section titles; Production/Income rendered for visual review. These are isolated Python/artifact checks, not a live PostgreSQL-to-browser integration test. |
| Vite production build | Passed using the real Vite config with only ignored output and empty environment directories overridden. **37 modules**; separate chart chunk as above. |
| Routes | Complete before/after registry byte-identical: **247 routes, 244 named, 3 unnamed, no duplicate names**, including 12 Reports web and 2 Reports API routes. Capture opened zero DB connections. Current-checkout SHA256: `bcd49c31e4b11c204b9dad3f77c16b322eea6080a234195741e57bd5173b7808`. |
| Syntax/style/scope | PHP syntax and `git diff --check` passed. Added PHP method passes Pint in isolation. Whole-file Pint reports the same 16 existing fixer categories in HEAD and the final file; unrelated formatting was left alone. No changes under backend/routes/database/Python or dependency manifests/locks. |

The repository's older route-check utility still reports a mismatch against its historical approved hash `f782e373...` both before and after this batch. That is a pre-existing baseline mismatch; the fresh complete registry comparison for this checkout passes. The historical baseline was not rewritten.

Local evidence: `%TEMP%\assocmap-ui-batch3-20261026\` contains isolated PHP logs, route captures and final frontend output. Ignored `web/node_modules/.cache/assocmap-ui-batch3/` contains canonical synthetic fixtures, Blade pages, native browser results/screenshots, artifacts/PNG renders, Vite output and temporary harnesses. Python artifact checks reused the independent ignored Batch 2 test environment with pinned ReportLab/openpyxl and a test-only PDF renderer; no application runtime or dependency declaration changed. Exclude all generated evidence from any later commit.

## 8. Remaining limitations

- No live PostgreSQL/Laravel/Python/authenticated browser chain, deployed-provider test, full PHP suite, physical-touch device or screen-reader acceptance was run. These local checks establish scoped implementation readiness, not deployment/UAT approval.
- Monthly lines deliberately require a single explicitly selected project/product, one recorded unit and comparable saved months. Broader aggregated or cross-year series need separately approved grouping/time contracts. Gross-income lines were not added because current grouped output exposes display period/frequency labels rather than a structured time-series contract.
- HTML charts are not embedded in PDF/XLSX. Export graphics, interactive chart filtering and additional detailed-record exports remain future work.
- Existing policy has no invented small-group suppression threshold. Classification counts already authorized in report tables are now more visible in bars; any new disclosure threshold requires a separate approved rule.
- Current snapshot data cannot establish historical growth, and recorded status/income/attendance alone cannot establish success, profit, effectiveness or self-sufficiency.

## 9. Commit recommendation

**GO for committing these eight scoped source/test/documentation files.** Required data-integrity, role, fallback, frontend, artifact, responsive and route-preservation checks passed within the boundaries above. Existing whole-file style findings and historical route-baseline mismatch were identified separately and do not originate in this batch. No commit was made. Work stops after implementation and verification as requested.
