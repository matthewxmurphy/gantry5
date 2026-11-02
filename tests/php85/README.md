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

With existing Joomla library source/dependencies and PDO SQLite available:

```sh
php tests/php85/joomla-change-state.php "$GANTRY_AUTOLOAD" "$JOOMLA_AUTOLOAD" . "$JOOMLA_FACTORY_SOURCE"
```

The real Joomla Database driver, DI container, CMS Factory and Gantry Joomla
adapter operate on a private SQLite memory database. No database behavior is
mocked. The 22 checks cover by-reference row insertion, exact NULL restoration,
create/delete/rename undo metadata, transactions, unrelated rows and protection
against changed assignments/defaults or reused IDs. The actual source files and
Composer vendors remain outside the repository. This optional fixture is not
added to default CI, which does not install Joomla CMS source/vendor packages.

The installed Joomla SQLite connector emits one PHP 8.5 deprecation while
registering `ROW_NUMBER` with `PDO::sqliteCreateFunction`. This exact bootstrap
diagnostic is counted and reported; every other enabled diagnostic throws.
No vendor compatibility shim is installed. These 22 checks do not certify the
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
