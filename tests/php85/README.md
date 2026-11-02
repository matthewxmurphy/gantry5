# PHP 8.5 runtime regressions

Run on actual PHP8.5 CLI with cURL:

```sh
php tests/php85/runtime.php
```

The suite promotes all enabled PHP diagnostics to exceptions and exercises the
real Gantry classes. Its 16 checks cover ASCII/control/Unicode attribute escaping,
malformed UTF-8 rejection, exact cURL file-transfer bytes, CurlHandle progress
and failure exceptions. Stream progress, completion and unrelated-notification
checks call the real notification handler with native-shaped arguments; they
do not establish HTTP transport acceptance. Temporary files are removed.

The existing design-pack bitmap suite covers the PHP 8.5 GD cleanup change.
Joomla's actual template installer must separately exercise the protected
extension/manifest reflection path. The runtime suite loads no CMS or vendor
dependencies and cannot establish whole-platform compatibility.

PHP 8.5 deprecates the obsolete cleanup/accessibility calls and multibyte ord()
usage described in the [official migration guide](https://www.php.net/manual/en/migration85.deprecated.php).
Gantry6.0.1 admits PHP8.5.x only. Gantry 5's separate PHP 8.4 work remains on its existing branch.

Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
Product/support: https://builtnotbegged.com. New tests use MIT; existing code
retains its original licenses and notices.

## Core API boundaries

Run `php tests/php85/api-input.php` on PHP8.5. Enabled diagnostics become
exceptions. These checks cover malformed payloads, revisions, native node
envelopes, size/depth limits, unsafe values and path controls. They do not
establish Joomla authentication, native persistence or browser acceptance;
follow [the installed API acceptance](../../docs/core-api.md) for those gates.

Native cache/blueprint regressions use the installed Composer autoloader:
`php tests/php85/api-cache.php platforms/joomla/lib_gantry5/vendor/autoload.php .`.
The fixture reproduces stale equal-size/same-mtime native caches and verifies
API/native freshness, revision changes, blueprint boundaries and flag restoration.

## Native Joomla database adapter

With the pinned, verified Joomla 6.2.0 fixture and PDO SQLite available:

```sh
php tests/php85/prepare-joomla-fixture.php --directory="$JOOMLA_FIXTURE"
php tests/php85/joomla-change-state.php platforms/joomla/lib_gantry5/vendor/autoload.php "$JOOMLA_FIXTURE/libraries/vendor/autoload.php" . "$JOOMLA_FIXTURE/libraries/src/Factory.php"
```

The real Joomla Database driver, DI container, CMS Factory and Gantry Joomla
adapter operate on a private SQLite memory database. No database behavior is
mocked. The 64 checks cover by-reference row insertion, exact NULL restoration,
create/delete/rename undo metadata, transactions, unrelated rows and protection
against changed assignments/defaults or reused IDs. Native Joomla Access reads
real SQLite viewlevel rules, nested-set groups and com_users guest parameters.
An administrator identity loaded through native IdentityAware does not widen
guest inputs. Public and Guest article intro/fulltext, frontend modules and
category descriptions are included; unpublished, private, Special, administrator
modules, future/expired rows and content beneath hidden ancestors are excluded.
Orphaned and cyclic categories fail closed. No authorisation implementation is
mocked. The fixture uses native application configuration and identity handling;
it does not bootstrap an installed CMS request, session or authentication plugin.
The preparation helper verifies the official archive and every extracted file;
native source and Composer vendors remain outside the repository and packages.

The installed Joomla SQLite connector emits one PHP 8.5 deprecation while
registering `ROW_NUMBER` with `PDO::sqliteCreateFunction`. This exact bootstrap
diagnostic is counted and reported; every other enabled diagnostic throws.
No vendor compatibility shim is installed. These 64 checks do not certify the
staging MariaDB path; the installed token-authenticated metadata round-trip is
the required runtime gate.

## Batch 1 core regressions

Run the issue-named checks after the native dependency build:

```sh
php tests/php85/issue-2486.php platforms/joomla/lib_gantry5/vendor/autoload.php .
php tests/php85/issue-3419.php platforms/joomla/lib_gantry5/vendor/autoload.php .
node tests/frontend/issue-2894.js platforms/joomla/lib_gantry5/vendor/autoload.php
```

Issue 2486 exercises real Unicode outline create/rename, duplicate allocation and
layout/index persistence, including bounded long CJK names and invalid UTF-8.
Generic catalogue reload still derives display labels from safe folder IDs;
there is no new durable title store. Joomla titles remain native database data.
The parked non-Joomla adapters must settle their durable title policy before
mutation acceptance. These fixtures do not enable WordPress writes.

Issue 3419 renders the shipped Joomla head block through native Twig with debug
on/off. Its version objects are branch fixtures, not installed CMS acceptance.
Issue 2894 invokes its PHP helper for real Twig asset/version admission and then
headless Chrome for CSS glyph pixels, SVG paths and JS-mode pseudo glyphs. An
isolated child HTTP server records successful matching font requests; no npm
package or external asset service is used. Set `CHROME_BIN` when Chrome is not at
a standard path. Missing browsers or incomplete assertions fail the check.
The FA6 payload comes from the official Font Awesome 6.5.1 distribution; its
saved selector keys, existing FA5/FA7 files and complete license texts remain.

## Compiled production readers

```sh
php tests/php85/compiled-twig.php platforms/joomla/lib_gantry5/vendor/autoload.php .
php tests/php85/compiled-readers.php platforms/joomla/lib_gantry5/vendor/autoload.php .
php tests/php85/compiled-bundle.php platforms/joomla/lib_gantry5/vendor/autoload.php .
php tests/php85/joomla-render-admission.php "$GANTRY_AUTOLOAD" "$JOOMLA_AUTOLOAD" "$JOOMLA_LIBRARIES_SOURCE"
```

The first fixture executes immutable native Twig PHP after removing all source
template directories, including inline strings and direct HTTP admission. The
second resolves a real inherited native layout before pinning, then removes its
source directories and rejects all locator discovery. Exact native data views,
references and source-write/SCSS/discovery failures are checked. See
[the contracts and limits](../../docs/compiled-twig.md). Installed Joomla
round-trip and comparison benchmarks remain required separate acceptance.

The admission fixture checks real Joomla early/null-document routing in an isolated PHP HTTP process, including document/query precedence, exception-safe build scopes and native plugin recognition after source YAML is removed.

The full bundle fixture has 47 checks using the actual registry, BundleCompiler,
native SCSS/Twig and maintained asset minifiers. It compiles Hydrogen-derived
inherited layouts and system layouts, preserves custom YAML precedence, prepares
and activates two generations, and keeps old pages/assets pinned while the next
generation activates. It checks content-addressed public bytes, source-change
refusal/cleanup, malformed manifests/pointers/descriptors and missing/corrupt
artifacts. Removing every source input directory still permits compiled rendering
with zero source discovery or Twig interpretation. This controlled native fixture
does not establish installed Joomla API/admin/frontend or benchmark acceptance.

## Immutable assets and responsive images

```sh
php tests/php85/render-assets.php platforms/joomla/lib_gantry5/vendor/autoload.php
php tests/php85/joomla-render-assets.php "$GANTRY_AUTOLOAD" "$JOOMLA_AUTOLOAD" "$JOOMLA_LIBRARIES_SOURCE"
```

The first fixture runs the real maintained PHP minifier and GD AVIF/WebP codecs,
with every enabled diagnostic promoted to an exception. It covers immutable
native CSS, existing `page.assets` settings, source notices, CSS URL rebasing,
logical URI identity, prepared adjacent pairs and attribute/order barriers.
It also exercises verified reuse across fresh compilers, responsive dimensions,
primary eager loading, private/denied/symlink source refusal, Apache access-policy
boundaries, subdirectory CMS URLs, animation/profile preservation and malformed
image metadata. Runtime image processing operates on rendered HTML; it does not
eliminate rendered-HTML parsing. Unsupported, remote, authored-picture/srcset,
animated and color-profile-sensitive images retain their native markup.

The second fixture requires actual Joomla 6.2 CMS source and Composer packages,
and uses its real Web Asset Manager, registry, events and dependency graph.
It checks critical-CSS relations, native header dependencies, footer inline
barriers, explicit defer, CSP nonces, no head/footer duplicate emission, native
handle preservation, head-to-footer refusal and manager locking. It does not
replace the installed CMS/browser round-trip or mobile/desktop benchmark gates.

Versioning/minification/combination default on in the existing asset blueprint;
mandatory compiled theme CSS remains immutable when optional versioning is off.
Combination uses only prepared adjacent pairs at runtime. Scripts need an explicit
`@gantry-combine-safe` source declaration and classic IIFE form; module, integrity,
CORS, identity, async, dependency and inline boundaries retain native delivery.
There is no blanket script-defer promotion. Joomla WAM remains authoritative for
native assets; non-Gantry handles are neither combined nor silently relocated.
