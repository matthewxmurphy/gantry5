# Compiled Twig and frozen native reads

This source candidate extends the shared change engine's render registry. It
does not establish a public release or a measured performance improvement.
Staging round-trip and benchmark acceptance remain separate required gates.

Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
Product/support: https://builtnotbegged.com. Existing upstream notices remain.

## One prepared generation

The registry calls `AbstractTheme::compileTwigBundle($generation, $directory,
$inlineSources)` in its explicit `BundleRuntime::source()` scope. A fresh native
Twig environment retains the theme's `setTwigLoaderPaths` and `extendTwig`
hooks. Joomla therefore keeps its own timezone, localized date/number filters
and context. The builder walks visible `.twig` templates in every registered
filesystem-loader namespace, with normal override priority, and compiles their
PHP declarations without rendering them or loading the generated classes.

Generation-prefixed loader keys enter Twig's native class-name calculation.
Logical aliases of one physical template share a class and artifact; another
generation cannot accidentally reuse an already-loaded class. Known inline Twig
sources receive SHA256 identities and named compiled artifacts. Custom HTML with
Twig enabled must be registered by the save/build collector. A previously
unknown runtime Twig string is refused. Plain unprocessed HTML remains literal.

The returned map contains `schema`, `generation`, PHP major/minor, Twig version,
matching environment options, `templates` and `strings`. Each template entry
contains its generation-prefixed `key`, native main `class`, relative
`twig/<digest>.php` file and SHA256. The string map links a source hash to its
registered logical template. It stores no second set of editable settings.

## Closed runtime path

`activateCompiledTwig($map, $directory)` creates a fresh execution environment
for the request's pinned registry generation. Its loader is a logical-name map,
not a filesystem loader. Its cache loads PHP lazily, verifies each artifact once
per environment and refuses writes. Missing or changed bytes produce a
controlled failure, including when PHP already loaded that class in another
environment. Runtime source reads, tokenization, parsing, compilation, source
autoreload and loader/cache replacement are disabled. There is no file-edit or
cache-miss source fallback.

Generated PHP has a direct-request guard: without the compiled environment
already loaded, it returns an empty 404 before a Twig class can be linked.
Immutable generation directories remain private application data and must not
enter distributable source packages or public backups. Registry activation and
retention own their lifecycle; ordinary frontend requests do not repair them.

Native include-array and `ignore missing` behavior remains available inside the
closed map. An unresolved required template becomes a sticky registry failure,
so optional particle catches cannot turn a damaged bundle into a partial 200.
Rendered-HTML DOM asset capture remains the existing mechanism; this change
does not remove DOM parsing or replace the asset-capture Twig nodes.

## Native data hydration

Before any source discovery, native configuration readers select the pinned
`config`, `config_raw`, `config_single` or `config_single_raw` view and restore
the native blueprint schema state. Frozen theme/parent details, palettes and
saved assignment rules remain ordinary native objects. Joomla still applies
its live menu/style overlays to the saved assignment data.

Layout artifacts hold the already-resolved native node tree, preset metadata
and timestamp. Runtime instances deep-clone that tree and index references
without loading ancestors, interpreting layout files or allocating replacement
IDs. Native indexes and inheritance metadata retain their prepared values.
Stylesheet lookup uses the outline's compiled asset map and cannot start SCSS
compilation. Build scopes clear layout statics and disposable theme render
state before reuse.

Remaining YAML consumers receive frozen PHP values by the registered filename
or URI alias. An unknown alias fails closed. Direct YAML source access/writes
and every configuration-directory discovery entry point are refused during
production rendering. Admin, API and explicit build scopes retain their native
source readers; WordPress mutation acceptance remains parked with its adapter.

## Focused verification

```sh
php tests/php85/compiled-twig.php platforms/joomla/lib_gantry5/vendor/autoload.php .
php tests/php85/compiled-readers.php platforms/joomla/lib_gantry5/vendor/autoload.php .
```

The Twig fixture executes native Twig inheritance, includes, embedded classes,
aliases and registered inline templates after removing source directories. It
checks fresh render context/timezone, lazy loading, zero source/compiler calls,
immutable-generation failures and a real unauthenticated local PHP HTTP request
to a generated artifact. The reader fixture resolves a real inherited native
layout, removes its source directories and installs a locator that refuses all
discovery. It verifies exact frozen views and native references plus source
write/SCSS/discovery refusals. These are executable native dependency fixtures;
they do not substitute for Joomla staging or the Gantry 5.7 comparison benchmark.
