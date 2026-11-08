# Batch F2: Public landing page redesign

Date: 2026-11-03. Baseline: `9e5665779d02b6c011186dac452ba73d9a169366`.

## Location and safety boundary

The approved Batch F2 page is now integrated into the main checkout at:

`C:\dev\AssocMap-STAGING`

Integration baseline: branch `feature/member-application-r2`, HEAD `9e5665779d02b6c011186dac452ba73d9a169366`. The main working tree was clean before integration; there were no unrelated uncommitted changes or merge conflicts.

The current files in `C:\dev\AssocMap-STAGING\output\landing-f2` were compared directly with the main checkout. Exactly the fifteen listed files were copied, including the approved UI regression test and report. No old patch was applied. The isolated source and its preview server were left intact.

No environment file, APP_KEY, preview configuration, dependency tree, cache, temporary fixture, or testing server was transferred into application source. The main production assets were rebuilt locally. All unrelated tracked files and existing environment/bootstrap-cache file hashes were verified unchanged.

No route, controller, service, authentication workflow, API, database schema, provider setting, Flutter file, or Python file changed. No migration, seeder, live database write, commit, push, or deployment was performed. Earlier development/hero verification sections below describe the isolated F2 work; their evidence paths are relative to the isolated checkout. Current integration evidence is listed in the final section.

## Design and functional decisions

- Blue remains dominant: `#0B427B` primary, `#35679D` secondary, deep navy hero, white and light neutral surfaces. Sage appears in the headline accent, small details, and lightly tinted sections. Primary actions use blue or white.
- Large editorial typography, asymmetric content, ruled feature rows, and varied section proportions replace the previous repetitive cards. The existing system font is retained, with Georgia used only for the italic hero accent. No font downloads or new dependencies.
- Journey: hero, purpose, six capabilities, GIS showcase, official resources/contact, final map action, footer. Navigation includes Home, About, Features, GIS Map, Contact, and Login.
- All five map entry points use the existing `public.gis` route (`/map`); login uses the existing `login` route. Management capabilities are explicitly described as requiring authorized accounts.
- The GIS artwork is labeled as an illustration and contains no actual geography, project records, statistics, or coordinates. The existing Street Map viewer and publication/privacy rules remain in place. No satellite imagery or landing-page data fetch was added.
- Official channels are clearly separated from this independent academic system. No agency endorsement, official ownership, or AssocMap support inbox is implied.

## Exact changed files

Paths are relative to the isolated checkout:

1. `web/resources/css/pages/public-landing.css`
2. `web/resources/js/layouts/public-navigation.js`
3. `web/resources/views/partials/public-navbar.blade.php`
4. `web/resources/views/partials/public-footer.blade.php`
5. `web/resources/views/public/landing.blade.php`
6. `web/resources/views/public/sections/hero.blade.php`
7. `web/resources/views/public/sections/about.blade.php`
8. `web/resources/views/public/sections/features.blade.php`
9. `web/resources/views/public/sections/gis-preview.blade.php`
10. `web/resources/views/public/sections/contact.blade.php` (new)
11. `web/resources/views/public/sections/access.blade.php`
12. `web/tests/Feature/GisManagementUiTest.php`
13. `docs/public-landing-page-batch-f2.md` (new)
14. `web/public/images/public/landing-hero.webp` (new)
15. `web/public/images/public/landing-hero-mobile.webp` (new)

The existing map-entry regression test previously assumed exactly three links. It now checks each of the five intended entry points individually, including the new navbar and footer entries, against the unchanged route. No authorization or publication assertion was removed.

## Animation, accessibility, and responsive behavior

- CSS hero reveal with short stagger; IntersectionObserver adds a 450 ms scroll movement to visible content. Essential content is never hidden pending JavaScript or an observer.
- Button/link transitions last 180-200 ms. No animation library, continuous animation, or parallax.
- `prefers-reduced-motion` disables animations, transitions, and smooth scrolling, including when the preference changes during use.
- Sticky navigation is progressively enhanced. Without JavaScript, mobile links remain available and the expanded header is not sticky, so it cannot obscure anchor destinations.
- Mobile menu announces its expanded state, closes with Escape, restores focus, transfers focus for same-page section links, and responds to breakpoint changes.
- Skip link, semantic headings/landmarks, visible focus, descriptive links, external-link announcements, and mobile touch targets are retained or improved. Decorative SVGs and the optional background image do not add redundant screen-reader content.
- Tablet and mobile layouts recompose content rather than scaling the desktop layout. Hero actions stack on narrow screens; feature rows, GIS preview, contact information, and footer adapt separately.

## Hero photograph integration

The supplied Unsplash photograph is now integrated locally. Photographer: [Hector John Periquin](https://unsplash.com/@hectorjp). Original photo: [man riding row boat on sea / HbDEhWpouM8](https://unsplash.com/photos/man-riding-row-boat-on-sea-HbDEhWpouM8). Its photo page identifies the photographer and the [Unsplash License](https://unsplash.com/license), which permits downloading, modifying, and using the photograph. Linked credit appears within the hero; no agency or program association is inferred from the photograph.

Local assets:

| Asset | Dimensions | File size |
| --- | --- | --- |
| `web/public/images/public/landing-hero.webp` | 2400 x 1600 | 371,996 bytes |
| `web/public/images/public/landing-hero-mobile.webp` | 960 x 640 | 95,686 bytes |

The desktop JPEG was downloaded from Unsplash's image host at 2400 pixels wide, then converted locally to WebP at quality 82. The mobile file is a proportionally resized copy. Neither file has a baked-in reflection: CSS applies `scaleX(-1)` only to the image element. Text, links, icons, and layout remain in their original direction.

The existing missing-image fallback is retained. With the supplied WebP present, `srcset` and `sizes="100vw"` select the appropriate local size. The hero image remains decorative (`alt=""`), eagerly loaded with high fetch priority. No external image request occurs during page use.

At desktop widths the image covers the full hero, with the fisherman on the right and a navy overlay concentrated beneath the left-aligned copy. The headline is smaller than the original F2 fallback composition to leave room for the subject. Tablet copy and overlay strength adapt to bright areas in the actual photograph.

At mobile widths the complete 3:2 scene spans the hero width below the copy. A vertical mask blends its edges into navy, avoiding a severe portrait crop of the fisherman and boat. The photograph remains a background layer within the hero, not a separate card. Primary/secondary actions, their destinations, keyboard focus, and reduced-motion behavior are retained.

## Official resource verification

Checked on 2026-11-03:

- [BFAR Region VII contact page](https://r7.bfar.da.gov.ph/contact-us/): `region7@bfar.da.gov.ph`. The page was available through its indexed official content; direct retrieval timed out.
- [SAAD's BFAR regional directory](https://saad.da.gov.ph/contact-us/bfarcontacts/): confirms the regional address and lists `saad.r7@bfar.da.gov.ph` for Central Visayas.
- External resource links point to the official [BFAR Region VII website](https://r7.bfar.da.gov.ph/) and [SAAD website](https://saad.da.gov.ph/).

No unverified social account was published. Email links were inspected and browser-checked as links; no message was sent. Addresses should be rechecked during future content maintenance.

## Initial F2 verification (before photograph integration)

All checks ran in the isolated checkout, with no shared staging/Supabase data mutations.

| Check | Result |
| --- | --- |
| Pre-change focused Laravel baseline | 41 tests, 519 assertions passed |
| Final focused Laravel regression | 41 tests, 528 assertions passed |
| Real Laravel HTTP kernel: `/` and `/login` | HTTP 200; no database connections or provider requests |
| Existing broader frontend harness, extended to the five required widths | 662 checks passed; public/staff/association GIS, login, and shared UI fixtures |
| Final focused landing browser checks | 172 passed; all five widths, anchors, focus, mobile menu/resize, all map hrefs, map/login navigation, no-JS fallback, motion preferences, sampled body text contrast >= 4.5:1, no document overflow |
| Browser screenshots | Visually inspected at 1440, 1024, 768, 390, and 320 pixels |
| Vite production build | Passed; final app CSS 165.69 KB / 26.68 KB gzip, app JS 58.35 KB / 18.28 KB gzip |
| JS syntax, changed PHP test syntax, `git diff --check` | Passed |

Focused Laravel command, run from `web` with a process-only synthetic `APP_KEY`:

```powershell
php vendor/bin/phpunit --filter 'GisManagementUiTest|GisScopedReadsTest|PublicGisSiteTypesTest|GisFeatureSerializerTest|TestEnvironmentIsolationTest'
node --check resources/js/layouts/public-navigation.js
php -l tests/Feature/GisManagementUiTest.php
npm.cmd run build
git diff --check
```

The repository's `TestEnvironmentGuard` enforces uncached test configuration, SQLite `:memory:`, array sessions/cache/mail, and blocked stray Laravel HTTP. Browser verification used real rendered Blade and production bundles in headless Edge, a task-owned loopback server, synthetic GIS responses, and blocked external requests. All owned browser/server processes closed after checks.

The first redesign regression run exposed the old three-link test expectation; the strengthened five-entry test then passed. Screenshot review also found an encoding-dependent compass glyph, which was replaced with the existing SVG icon. Final screenshots include that correction and the no-JavaScript navigation refinement.

Local evidence, relative to the isolated checkout:

- `output/assocmap-ui-f1-a935d27404174499a97fd4c1573a13ea/final/home-{width}.png`
- `output/assocmap-ui-f1-a935d27404174499a97fd4c1573a13ea/final/hero-1440.png`
- `output/assocmap-ui-f1-a935d27404174499a97fd4c1573a13ea/final/checks.json`
- `output/assocmap-ui-f1-a935d27404174499a97fd4c1573a13ea/after-results.json`
- `output/qa/landing.browser.mjs` and `output/qa/http-kernel.php`

These generated fixtures, bundles, and screenshots are ignored local evidence, not deployment source. The browser script reuses an existing locally installed Playwright package; no dependency or lockfile was changed.

## Hero background update verification

This follow-up changes only these five files relative to the completed F2 redesign:

1. `web/resources/views/public/sections/hero.blade.php`
2. `web/resources/css/pages/public-landing.css` (hero selectors only)
3. `web/public/images/public/landing-hero.webp` (new)
4. `web/public/images/public/landing-hero-mobile.webp` (new)
5. `docs/public-landing-page-batch-f2.md`

No JavaScript, route, API, authentication, backend workflow, or other page section changed. Pre-update SHA-256 comparisons confirm that all other existing source files are unchanged. Comparing the CSS with hero rules removed gives identical content; the intro band following the hero is also unchanged.

Executed for this update:

| Check | Result |
| --- | --- |
| Focused frontend browser checks | 192 passed at 1440, 1024, 768, 390, and 320 pixels |
| Hero screenshots | Final photograph, cropping, overlay, copy, buttons, and attribution visually inspected at 1440, 390, and 320; tablet composition also inspected |
| Photograph-backed text contrast | 212 word-range background samples passed across the five widths; at least 3:1 for large headline text and 4.5:1 for normal text |
| Existing map-entry Laravel regression | 1 test, 16 assertions passed using the existing isolated test guard |
| Vite production build | Passed; app CSS 167.40 KB / 27.05 KB gzip; app JS unchanged at 58.35 KB / 18.28 KB gzip |
| JavaScript syntax and `git diff --check` | Passed |
| Serving-checkout status | Clean; no serving files changed |

The browser checks confirm local image loading, image-only reflection, unchanged five GIS links, navigation/login behavior, keyboard focus, mobile navigation, no-JavaScript access, no overflow, and reduced-motion behavior. Existing 450 ms entrance animations and short button transitions are reused; the hero adds a small button lift without a new library.

Contrast was checked against screenshots of the real photograph and overlays with the text temporarily hidden while preserving its layout. The check samples backgrounds under individual word rectangles, including the attribution, rather than assuming a flat navy background. Initial tablet readings exposed bright-water failures; localized overlay adjustments resolved them. This is targeted contrast evidence, not a full accessibility certification.

Current evidence is in `output/assocmap-ui-f1-748a9c6d88bc41d2823a6807e7bf2f90/final/`:

- `hero-complete-{width}.png`: complete hero crops, including attribution
- `hero-{width}.png` and `home-{width}.png`: viewport and full-page captures
- `checks.json`: 192 browser checks
- `contrast-results.json`: photograph-backed contrast results
- `contrast-text-{width}.json` and `contrast-background-{width}.png`: measurement inputs

Task-local checks are retained in `output/qa/hero.browser.mjs` and `output/qa/hero-contrast.py`. Pre-update hashes and the three edited text-file copies are in `output/qa/hero-before/`. These are ignored local evidence, not deployment files.

All browser/provider isolation boundaries described above still apply. No external requests were allowed in browser tests. No physical-device or Safari/Firefox acceptance is claimed. The earlier 41-test and 662-check results belong to the initial F2 pass and were not rerun for this hero-only update.

## Deployment impact and handoff

Batch F2 is integrated locally in the main checkout, with its production bundle rebuilt using `npm.cmd run build` in the main `web` directory. No deployment was performed. The earlier F2 patch files are historical review artifacts and were not used for this integration.

The real main Laravel application is running at [http://127.0.0.1:8000/](http://127.0.0.1:8000/), using its existing configuration. This is the main application, rather than the static :8001 preview. The landing and real login page were browser-checked without submitting credentials or requesting live GIS data.

If the local server has stopped, start it with:

```powershell
cd C:\dev\AssocMap-STAGING\web
php artisan serve --host=127.0.0.1 --port=8000
```

Then open `http://127.0.0.1:8000/`. Use Ctrl+F5 if an existing browser tab displays stale content. No environment edits, migrations, seeders, or cache-clearing commands are needed.

A future release must include the source changes, WebP files, and matching Vite manifest/bundles together. There is no provider configuration or database cutover.

## Rollback and remaining limits

- Before any future commit/release, review the fifteen-file diff. Restore only the affected tracked paths to the pre-integration baseline and omit the four newly added files if rollback is required. Preserve unrelated edits and rebuild the matching assets. Do not reset or clean the entire worktree.
- No database, authentication, provider, or environment rollback is required.
- The photograph, responsive presentation, and existing Login appearance were verified in headless Edge. Physical devices, Safari/Firefox, and full assistive-technology acceptance remain separate.
- Live Supabase login, actual GIS records, external map tiles, deployed CSP, and production/staging UAT were not exercised. The actual main server was checked only for the landing page, login page, and local assets.
- Database-backed regressions used synthetic SQLite `:memory:` fixtures under the existing isolation guard; this does not establish native PostgreSQL/PostGIS or hosted-provider acceptance.
- The full Laravel suite was not rerun because the focused integration suite and frontend checks covered the affected boundaries.

## Final main-checkout integration verification

Integrated on 2026-11-03 from the current approved source, with the file allowlist and pre-change hashes captured in `output/f2-integration/before.json`.

One integration-specific CSS safeguard was necessary: the existing Login page also has `public-page` on its body and depends on the previous public tokens, brand, and button primitives. A login-only compatibility block in `public-landing.css` restores those original styles. Root smooth scrolling excludes the Login page. No authentication template, validation, controller, route, or workflow changed. Outside that compatibility block and root-selector restriction, the landing CSS matches the approved F2 CSS; other copied implementation files/assets match the approved source byte for byte.

| Executed check | Result |
| --- | --- |
| Pre-integration Laravel baseline | 158 tests, 2,173 assertions passed |
| Post-integration Laravel regression | 158 tests, 2,182 assertions passed |
| Route registry comparison | All 287 methods, paths, names, actions, and middleware lists unchanged |
| Main Vite production build | Passed; CSS `assets/app-DClzdZ-n.css`, JS `assets/app-C5zQVno-.js` |
| Broader frontend regression | 662 checks passed at 320, 390, 768, 1024, and 1440 pixels |
| Focused landing regression | 192 checks passed across the same five widths |
| Authenticated/shared presentation comparison | 40 before/after screenshots pixel-identical, including Login and settled GIS editor states |
| Real main Laravel runtime on :8000 | HTTP 200; desktop 1440 and mobile 390/320 browser checks passed |
| Production manifest and local images | Every referenced production asset exists; both WebP hashes match the approved source |
| JavaScript/PHP syntax and `git diff --check` | Passed |
| Preservation check | No unrelated tracked-file or environment/bootstrap-cache changes |

The Laravel selection covers `GisManagementUiTest`, `GisScopedReadsTest`, `PublicGisSiteTypesTest`, `GisFeatureSerializerTest`, `TestEnvironmentIsolationTest`, `StaffAuthenticationCharacterizationTest`, `DashboardServiceTest`, and `AssociationProgramReadTest`. Tests used uncached configuration, a process-only synthetic key, task-local storage/cache, in-memory SQLite, and blocked stray Laravel HTTP. No shared database was used.

The frontend checks exercise all five GIS entry links, the real Login destination, section navigation and focus, mobile menu/breakpoint behavior, reduced motion, no-JavaScript fallback, hero mirroring/local loading, attribution links, contact/resource URLs, GIS UI states, and overflow. The initial screenshot comparison detected the Login style dependency; the compatibility block resolved it. GIS editor captures wait for the parent options and tile-error state to settle, so asynchronous loading does not create false layout differences.

The real main-server browser run confirms the rebuilt stylesheet is loaded, the image alone is mirrored, animations and navigation work, the actual password form is present, all five GIS hrefs point to the existing main route, and no preview-only sign-in restriction is present. No credentials were submitted, no email link was activated, and no live GIS endpoint was queried.

Current evidence relative to the main checkout:

- `output/f2-integration/routes-before.json` and `routes-after.json`
- `output/f2-integration/integrity.json`
- `output/f2-integration/authenticated-visual-final.json`
- `output/f2-integration/controlled-visual-comparison.json`
- `output/f2-integration/main-live-results.json`
- `output/f2-integration/main-live-{1440,390,320}.png`
- `output/assocmap-ui-f1-bc24788f8cc440248b99b606a5303aaf/after-results.json` (662 checks)
- `output/assocmap-ui-f1-bc24788f8cc440248b99b606a5303aaf/final/checks.json` (192 checks)
- `output/assocmap-ui-f1-bc24788f8cc440248b99b606a5303aaf/before/`, `after/`, and `controlled-before/` / `controlled-after/` screenshots

Generated QA evidence and local server logs are ignored artifacts, not deployment source. The source diff contains exactly the fifteen listed files and no configuration, dependency, cloud, or backend workflow changes.
