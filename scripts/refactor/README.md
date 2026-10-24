# Route extraction verification

Run from `C:\dev\AssocMap-Refactor` in PowerShell. PHP and Composer dependencies must already exist.
This harness supports this Windows worktree and branch only. It never runs Composer scripts or Artisan commands.

Use the approved `routes.compatibility.json` captured during Day 2. Its SHA-256 is pinned in both scripts:
`f782e373299cf19d13d138dcab8293171c7f04e4471a02f826df0883a6bbd06d`.
Keep that snapshot and all generated evidence outside the repository. A missing or different snapshot fails verification;
the harness does not regenerate or approve baselines.

```powershell
$baseline = 'C:\Users\Dea\AppData\Local\Temp\assocmap-refactor-day2-retry-49ac605090394d16bf74f21044ce8621\routes.compatibility.json'
$format = 'C:\Users\Dea\AppData\Local\Temp\assocmap-refactor-day2-retry-49ac605090394d16bf74f21044ce8621\batch3-resumed\pint-before.json'
& .\scripts\refactor\verify.ps1 -Baseline $baseline -FormattingBaseline $format `
    -Tests @('tests/Feature/AreaManagementTest.php') -RouteFiles @('routes/web.php')
# After extraction, use the pre-change evidence directory printed above:
& .\scripts\refactor\verify.ps1 -Baseline $baseline -FormattingBaseline '<before-evidence>\pint.json' `
    -Tests @('tests/Feature/AreaManagementTest.php') -RouteFiles @('routes/web.php', 'routes/admin/areas.php')
```

Every invocation runs `TestEnvironmentIsolationTest` first and stops on failure. It reuses the unchanged
`TestEnvironmentGuard` and `IsolatedDatabaseManager`: no credential file loading, only SQLite `:memory:`,
blocked stray Laravel HTTP requests, array mail/session/cache, and uncached configuration. Process environment
overrides are restored on exit. Cache/storage, compiled views, PHPUnit, Pint and registry output use a fresh
temporary directory on the worktree drive. Leading-slash paths accommodate Laravel's Windows cache normalization.

The capture script is the successful Day 2 collector with an immutable input baseline and comparison added.
It records both effective routes and registration calls in order, complete actions, resolved/declared/excluded
middleware, global middleware and aliases, domains, constraints, defaults, binding signatures/callbacks/scoping,
fallback and session-lock metadata. It requires byte-for-byte equivalence, 247 routes, 244 named, 3 unnamed,
and no duplicate names; inventory must open zero database connections.

Targeted tests run directly through PHPUnit, never through `composer test`. Select tests already reviewed for
in-memory fixtures and mocked external services; the harness does not sandbox arbitrary PHP or raw HTTP clients.
Pint runs in check mode. A reviewed pre-change JSON report permits existing fixer findings for the same file;
new files must pass, and newly reported fixer rules fail. Review the source diff as well, since Pint JSON lists
fixer rules rather than individual violation locations. No automatic formatting or application changes occur.
