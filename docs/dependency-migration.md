# Gantry 6.0.0-dev dependency and theme migration

This is an **unreleased development candidate** based on Gantry 5. It changes
runtime dependencies and extension APIs. It creates no release tag, installs no
CMS, and automatically upgrades no site, outline, theme or content. Keep existing
Grav 1.7 installations on the separate Gantry 5.6.5 line.

## Runtime and package boundaries

The candidate targets PHP **8.3 and 8.4**, below 8.5. Runtime package requirements,
plugin admission and the shared loader reflect that floor. Root/builder Composer
graphs are build tools; their separate requirements do not declare CMS runtime support.

WordPress and Joomla resolve these exact candidate versions:

| Package | Version | Purpose |
| --- | --- | --- |
| rockettheme/toolbox | 2.0.1 | Existing resource/file/configuration/array traits |
| symfony/event-dispatcher | 7.4.17 | Native dispatcher and subscriber contract |
| symfony/yaml | 7.4.22 | Configuration parsing and serialization |
| twig/twig | 3.30.0 | Template compilation/rendering |
| timber/timber | 2.5.1 | WordPress factories and rendering integration |

Toolbox 2 requires PHP 8.3 and Symfony YAML 7. Its removed dispatcher and
Grav-specific legacy Event shims are not standalone Gantry replacements. Gantry
owns its payload adapter and uses Symfony directly. No Composer aliases disguise
old APIs as new. WordPress/Joomla compat directories retain SCSS 1.13 only; they
no longer collect old Twig 1/Pimple or replace the PSR container package. This
candidate does not change the SCSS API or claim a SCSS 2 migration.

**Grav owns its host dependencies.** The plugin installs no competing Twig,
Symfony or Toolbox stack and overwrites no Grav vendor. Grav 2 supplies these
APIs. The loader requires Twig 3.21 or later within Twig 3 for its parser API;
WordPress/Joomla pin 3.30. Host development branches need actual native verification.

Grav 2 Admin2 has a different lifecycle from classic Admin. The candidate uses
its native API and [Admin2 transport](grav-admin2.md) to invoke the existing Gantry
controllers. Native administration acceptance is separate from frontend
compatibility; a rendered frontend alone does not establish editor, Client text
or Upgrade Assistant parity.

## Event API

Use `Gantry\Component\Event\Event` for Gantry payloads and Symfony's
`EventSubscriberInterface` for subscribers. Existing Gantry admin subclasses retain
their public data fields. Payloads retain Toolbox array construction/access/export
and native propagation stop. Getters remain by value; mutate with explicit offset assignment.

The public wrapper remains `$gantry->fireEvent('extension.example', $event)`.
Direct Symfony calls are now `$dispatcher->dispatch($event, 'extension.example')`.
Extensions type-hinting the removed Toolbox dispatcher/Event must update imports.
Grav CMS callbacks retain their own native event type; they are not Gantry dispatcher callbacks.

## Twig extension API

Cache methods implement typed Twig 3 `CacheInterface`, retaining PHP-specific keys
and bytecode invalidation. Captured bodies use actual Twig `CaptureNode` so yielded
content is captured; plain `ob_start()` around Twig 3 output is insufficient.
`YieldReady` nodes support both output modes. Parsers use the maintained entrypoint;
switch reads registered `or` precedence to preserve multiple case values.
Third-party nodes/templates still require migration; Twig 4 support is not claimed.

## WordPress and shipped themes

Timber is Composer-only and initialized with `Timber::init()`. A loaded Timber 1
plugin cannot be a fallback. Conflicting extensions must be updated; Gantry does
not alias over incompatible loaded classes. Framework and shipped Helium/Hydrogen
entrypoints migrate these consumed APIs, preserving content, markup and notices:

| Old API | Candidate API |
| --- | --- |
| `Timber::get_context()` | `Timber::context()` |
| `query_post()` / `new Post()` | `Timber::get_post()` |
| `new User($id)` | `Timber::get_user($id)`, nullable |
| `new TimberMenu($id)` | `Timber::get_menu($id)`, nullable |
| `Timber::get_pagination()` | Queried collection `pagination()` |
| Old PostQuery construction | `Timber::get_posts($query)` with array/WP_Query |
| `TimberHelper::function_wrapper()` | `Timber\Helper::ob_function()` |
| Lazy `wp_head` / `wp_footer` context values | `gantry.theme.wordpressHead()` / `wordpressFooter()` at their template positions |
| Old context/output hooks | `timber/context` / `timber/output` |
| Static cache/location globals | `timber/locations`, `timber/twig/environment/options` |

`timber/twig` receives an Environment. The `/filters`, `/functions`, `/escapers`
definition hooks receive arrays, not an Environment. The content-array particle
uses current collection pagination. Existing category/query choices are retained;
query strings must become WordPress argument arrays. Shipped WordPress themes
require the new major candidate. Site-owned overrides/helpers need their own
review; building this source never overwrites an installed theme or custom files.
Head/footer hooks are captured when their template blocks render. Capturing
`wp_head` eagerly while building context would run before Gantry registers its assets.

## Verification

Use an isolated checkout with PHP 8.3/8.4. Check actual platform requirements:

```sh
composer install --working-dir=platforms/wordpress/gantry5 --no-dev
composer check-platform-reqs --working-dir=platforms/wordpress/gantry5 --lock --no-dev
php tests/modern/dependencies.php platforms/wordpress/gantry5/vendor/autoload.php
```

The new shared-service test uses actual resolved libraries and Gantry container,
event, cache and parser code. It checks events/YAML/cache/switch/assets/throw/try
in both Twig output modes. Pageblock/Markdown compile into native PHP declarations;
their destinations remain real CMS acceptance. This test does not boot or impersonate a CMS.

Native checks must observe actual Reflection origins/runtime versions, install the
candidate, save/reload configuration, compile SCSS, serve generated CSS and exercise
real menu/post/page/template consumers. Native authority and host vendor ownership
remain separate requirements. See [itemized candidate notes](6.0-release-notes.md).

Primary references: [Toolbox 2.0.1](https://github.com/rockettheme/toolbox/tree/2.0.1),
[Symfony 7.4](https://symfony.com/releases/7.4),
[Twig 3](https://twig.symfony.com/doc/3.x/intro.html), and
[Timber 2 migration](https://timber.github.io/docs/v2/upgrade-guides/2.0/).
