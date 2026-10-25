# Route extraction verification

Run from `C:\dev\AssocMap-Refactor` in PowerShell. PHP and Composer dependencies must already exist.
This harness supports this Windows worktree and branch only. It never runs Composer scripts or Artisan commands.

The default baseline is version-controlled at `scripts/refactor/baselines/routes.compatibility.json`.
It was recovered from the unchanged checkpoint and matched the approved Day 2 SHA-256 pinned in both scripts:
`f782e373299cf19d13d138dcab8293171c7f04e4471a02f826df0883a6bbd06d`.
The baseline contains route metadata only, without credentials or environment configuration. Its local `.gitattributes`
disables text conversion and recognizes the original final CRLF to preserve the exact approved bytes.
All generated evidence remains outside the repository.
A missing or different baseline fails verification; the harness does not regenerate or approve baselines.
An explicit `-Baseline` override must match the same pinned hash.

Verify isolation and the complete registry without checking unrelated route formatting:

```powershell
& .\scripts\refactor\verify.ps1 -RouteFiles @()
```

If Windows blocks local scripts, use a one-off process without changing user or machine execution policy:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "& .\scripts\refactor\verify.ps1 -RouteFiles @()"
```

For a module extraction, select the affected tests and route files. If scoped files already have Pint violations,
review their pre-change `pint.json` in the temporary evidence directory and pass it as `-FormattingBaseline`.
Without that argument the harness saves its Pint report and stops on any findings, allowing review before retrying.

```powershell
$format = '<reviewed-pre-change-evidence>\pint.json'
& .\scripts\refactor\verify.ps1 -FormattingBaseline $format `
    -Tests @('tests/Feature/AreaManagementTest.php') -RouteFiles @('routes/web.php')
# After extraction, use the pre-change evidence directory printed above:
& .\scripts\refactor\verify.ps1 -FormattingBaseline '<before-evidence>\pint.json' `
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
