# Frontend browser checks

Build the real frontend from its locked dependencies:

```sh
cd assets/common
npm ci --ignore-scripts --no-audit --no-fund
npx gulp js --production
cd ../..
python3 tests/frontend/serve.py --port 8787
```

In another terminal, open the fixture and run its browser checks with Playwright CLI:

```sh
npx --yes --package @playwright/cli playwright-cli --session gantry-frontend open http://127.0.0.1:8787/
npx --yes --package @playwright/cli playwright-cli --session gantry-frontend run-code --filename tests/frontend/browser-checks.js
npx --yes --package @playwright/cli playwright-cli --session gantry-frontend close
```

The returned result must report `failed: 0`. Stop the owned Python server with
Ctrl-C when finished. The server binds only to loopback and serves three fixed
files, rather than the repository or any installed website.

For a focused continuation, open `/?phase=keyboard` (23 checks) or
`/?phase=regressions` (13 checks) before running the same driver. With no phase,
the driver runs all 36 checks.

The checks use the actual source-built bundle and Nucleus CSS: fractional and
unitless breakpoints; opposing drawer transitions and ARIA state; observer
cleanup; a synthetic cancelable touch movement; native back-to-top scrolling
with smooth CSS, a body scroller and reduced motion; keyboard disclosure,
focus/Tab/Escape behaviour; mobile relocation; and actual browser-emulated taps.
The observer fixture counts only observers attached to the off-canvas element.

This selected-markup fixture does not establish whole-theme appearance,
assistive-technology compatibility, physical-device acceptance, or integration
with third-party modal dialogs. Named CMS/theme checks remain separate.

Copyright 2026 Matthew C. Murphy. New test tooling is MIT; Gantry's existing
licenses and notices remain unchanged.

## Gantry 6 Batch 1 issue gates

After the native package dependency build, run the issue-named regressions:

```sh
node tests/frontend/issue-2282.js platforms/joomla/lib_gantry5/vendor/autoload.php
node tests/frontend/issue-2519.js platforms/joomla/lib_gantry5/vendor/autoload.php
```

These require actual PHP 8.5.x, the shipped SCSS compiler, and a real Chromium
executable. Set `CHROME_BIN` if Chrome/Chromium is not in a detected standard
location (`/usr/bin/google-chrome` on CI). A missing compiler/browser fails the
gate rather than skipping it. The runner creates and removes its own temporary
profile and HTML fixture, with no network or installed-site dependency and no
new npm dependency.

- [#2282](https://github.com/gantry/gantry5/issues/2282), 76 checks: source-compiled
  and checked-in Joomla CSS are each combined with the shipped compatibility
  and Bootstrap 5 stylesheets. Native contact radios/labels must have visible
  geometry, pointer hit targets, focus, label selection and submitted values.
  Enhanced Bootstrap 5 button radios retain native clipping and activation.
- [#2519](https://github.com/gantry/gantry5/issues/2519), 64 checks: source-compiled
  and shipped core Nucleus CSS each run with frozen pre-fix theme CSS and current
  Helium Owl CSS. Intrinsic-image/background slides must fill 320px and 960px
  containers before measured inline widths are available. Measured single/multi-
  item widths remain authoritative; two measured slides fit side by side, and
  vertical touch panning remains enabled. The fallback lives in core so existing
  cloned themes receive the repair through a framework-only installation.

These shipped-asset browser fixtures verify the defect mechanisms. They do not
certify complete Joomla contact-component integration, Owl initialization from
third-party templates, physical-device interaction or whole-theme appearance.

New issue tooling: Copyright (c) 2026 Matthew X Murphy,
https://MatthewxMurphy.com. MIT; support https://builtnotbegged.com.
