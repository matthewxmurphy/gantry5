# Compiled production rendering candidate

This work extends **6.0.2-rc.1**, which remains an unpublished candidate.
The released 6.0.1 and Gantry 5 lines are unchanged. Joomla is the first native
compiled adapter; WordPress writes remain parked for its native adapter.

Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
Product/support: https://builtnotbegged.com. Existing upstream notices remain.

## Preparation and activation

The shared change engine holds the existing theme lock and checks the caller's
revision. Preview validates a proposal without compiling or activating anything.
Save and undo prepare the entire derived generation while native Joomla changes
are transactional. Preparation reads current configuration bytes, resolves native
inheritance, builds layouts and indexes, compiles SCSS and Twig, and freezes the
asset/image manifests. A source fingerprint detects changes during preparation.

After the native transaction commits, one rename activates the prepared pointer.
Each frontend request pins its generation once. Old generations and content-hash
assets remain available to in-flight readers. No automatic deletion or garbage
collection is introduced here.

The initial build uses the installed library, native Joomla bootstrap and existing
change engine, without a new component or plugin:

```sh
php8.5 libraries/gantry5/src/render.php --theme=g6_jmafia --origin=https://example.com
```

Run as the site's filesystem owner. Include the site's subdirectory in `--origin`
when applicable. This command reports the source revision and render generation;
it does not change authoritative theme data or add a history entry. Installers
must prepare the generation before admitting frontend traffic. Preparation needs
native GD AVIF/WebP support when eligible images are present.

## Request contract

Joomla frontend HTML uses guarded immutable PHP artifacts. It does not tokenize,
parse or compile Twig, read YAML source, discover config directories, resolve
layout inheritance, compile SCSS, minify assets or encode images. Metadata,
configuration and layouts are hydrated lazily for the selected outline. Source
editor/API contexts remain native and use the same shared change engine.

The strict Twig loader verifies generated PHP and preserves native template
override precedence, inheritance, includes and known inline Twig. Unknown dynamic
Twig is refused until a save/build prepares it. Existing rendered-HTML filtering
still parses rendered markup; this is not a claim of zero HTML parsing or zero
filesystem calls across the whole CMS.

## Assets use the existing configuration

Versioning, minification and combination are fields of `page.assets`, using the
same native outline settings, revision, preview, save and undo operations.
Mandatory native theme CSS always freezes to an immutable URL. Optional versioning
does not disable the compiled renderer. Per-particle manifests contain the assets
declared by resolved particles; unrequested assets are not injected into a page.

Combination uses prepared adjacent pairs, with identity, module, inline,
dependency, integrity, CORS and async barriers. Classic scripts additionally
require an explicit `@gantry-combine-safe` declaration. Defer honors authored
flags and native dependency order; it does not move scripts past inline
initializers. Native non-Gantry handles remain under Joomla Web Asset Manager.

Critical CSS is prepared ahead of requests and registered before the corresponding
stylesheet through WAM. Eligible public raster images receive AVIF/WebP sources,
original-format fallback, intrinsic dimensions and eager/high-priority delivery
for the primary image. Other admitted images load lazily. Remote images, authored
picture/srcset markup, animation, sensitive color profiles and unsupported inputs
retain native delivery. Background-image URLs retain their CSS semantics.

Offline derivation accepts bounded public paths and guest-visible published CMS
content. It refuses private/admin/configuration paths, escaping links and access
policies it cannot safely classify. A failed build cannot make private originals
eligible. Cached derivatives are reused only after their bytes and metadata are
verified.

## Recovery and acceptance

Missing/corrupt/incompatible artifacts fail with a controlled 503. There is no
frontend source fallback. Rebuild from verified current source under the same
lock. A failure before native commit rolls back the source operation. A failure
after commit keeps the pending recovery marker, preserves the old active pointer
and blocks further writes; it never attempts a rollback of an already committed
transaction. Operator reconciliation must establish current native data and
the matching generation before clearing a pending marker.

CI contains each issue-named Batch 1 regression plus actual native bundle,
source-removal, immutable-generation, asset, image, WAM and recovery checks.
Installed staging API/admin/frontend tests and the paired 5.7 benchmark remain
separate exit gates. No performance claim follows merely from compiling files.
