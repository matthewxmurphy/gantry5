# Gantry 6 step 2 gates

Candidate **6.0.2-rc.1** remains unpublished. Released 6.0.1, the Gantry 5 line
and the PHP 8.4 proving site remain outside this work. WordPress mutations stay
disabled pending its native adapter.

Developer: Matthew X Murphy, https://MatthewxMurphy.com.
Support: https://builtnotbegged.com. Existing licenses and upstream notices apply.

## Port-forward gate

Each reported defect was checked against the accepted shared-engine source.
The gate requires executable regressions, including actual browser behavior for
the visual asset defects; checking a source string alone is insufficient.

| Issue | Reproduced mechanism | Regression |
|---|---|---|
| [2282](https://github.com/gantry/gantry5/issues/2282) | Joomla CSS hides native contact radios; compatibility button groups reduce label text to zero size. | `tests/frontend/issue-2282.js` |
| [2519](https://github.com/gantry/gantry5/issues/2519) | Unmeasured Owl items shrink to intrinsic image width. | `tests/frontend/issue-2519.js` |
| [2486](https://github.com/gantry/gantry5/issues/2486) | ASCII-only normalization damages Unicode outline folders, including later layout saves. | `tests/php85/issue-2486.php` |
| [2894](https://github.com/gantry/gantry5/issues/2894) | The selected Font Awesome version emits missing JS/font URLs or loads fonts from another version. | `tests/frontend/issue-2894.js` |
| [3419](https://github.com/gantry/gantry5/issues/3419) | The Joomla head emits debug CSS when Gantry debug is disabled. | `tests/php85/issue-3419.php` |

The CI workflow names each issue explicitly and runs its regression immediately
after building native dependencies. A missing runtime, compiler or browser fails
the gate. Selected-markup/browser and native filesystem fixtures establish the
specific mechanisms; they do not certify every CMS integration.

The initial local pass on PHP 8.5.3 and real Chrome is 321 checks: issue 2282
(76), 2519 (64), 2486 (39), 2894 (120) and 3419 (22). Each defect reproduced
before its core repair. The Owl regression includes an unchanged pre-fix theme
fixture with both source-compiled and shipped core CSS, so its fix does not
depend on replacing an installed theme.

The two restored Font Awesome 6.5.1 JavaScript assets retain upstream whitespace
because they match the official tagged Git blobs byte for byte. Authored files
remain subject to normal whitespace checks; no upstream asset was reformatted
to satisfy a local style check.

Compiled-rendering implementation remains gated until all five regressions pass.

## Compiled-path acceptance

The accepted target is an immutable, versioned render bundle prepared by the
shared change engine and activated atomically. Production requests must use
resolved configuration/layouts and precompiled templates, without source-format
interpretation or configuration-directory discovery. Preview must not publish
artifacts. Save and undo must activate matching artifacts through that same
change boundary. Asset settings remain in the existing native configuration;
Joomla Web Asset Manager remains authoritative.

These are acceptance requirements, not a claim that the compiled path exists.
The final API/admin/real-content frontend round-trip, clean logs and green CI
must be established on the installed candidate.

## Benchmark exit gate

Use two isolated staging copies of identical real content. Gantry 5.7 must come
from its immutable published package; a similarly named earlier build is not a
valid baseline. Do not request or modify the proving site to obtain numbers.

Record PHP/runtime versions, worker/cache state, sample counts, cold and warm
median and slow-request timings, memory, asset bytes and mobile rendering side
by side. Do not flush shared host caches or compare a contended shared FPM pool
against an isolated worker. A regression keeps step 2 open. No speed claim or
public release follows from a source checkpoint or a passing unit test.
