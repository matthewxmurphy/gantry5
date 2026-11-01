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
