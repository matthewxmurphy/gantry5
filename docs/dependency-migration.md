# Modern dependency migration candidate

This is a proposed next-major route, not shipped support or a dependency update.
Gantry 5.7 retains the Gantry 5.6.6 dependency pins and PHP 8.1–8.4 range.
The separately implemented Gantry 6 alpha follows this migration; it is not part
of the Gantry 5.7 feature backport. No requirements,
lock files or installed CMS dependencies are changed by this document.

## Candidate runtime

Use PHP 8.3 or later: maintained `rockettheme/toolbox` 2.0.1 requires `^8.3` and
Symfony YAML `^7.0`. Symfony 7.4 LTS itself requires PHP 8.2, but that lower floor
does not satisfy Toolbox. Candidate WordPress/Joomla dependencies are Toolbox
`^2.0.1`, `symfony/event-dispatcher` and `symfony/yaml` `^7.4`, and Twig `^3.30`.
WordPress additionally needs Timber `^2.5.1` (PHP `^8.2`, Twig `^3.27`). These
constraints require an isolated real Composer resolution and runtime acceptance;
they are not a claim that the current source already supports them.

## Source changes required

- Toolbox 1.6.5's Event extends the removed Symfony component Event, and its
  dispatcher uses the old name-first call. Toolbox 2.0.1 removes that dispatcher;
  its Event/subscriber files are Grav upgrade shims, not standalone replacements.
  Introduce a Gantry-owned event payload using the Symfony Contracts event and
  retained array traits; use the native Symfony dispatcher/subscriber interface.
  `Framework/Base/Gantry.php::fireEvent()` is the single actual dispatch call to
  change to event-first. Update the existing event/subscriber imports and document
  this API migration for older integrations. Do not alias incompatible versions.
- `Component/Twig/TwigCacheFilesystem.php` needs the typed Twig 3 cache interface.
  Exercise the existing custom nodes/token parsers with real Twig 3 before making
  further changes; many already have modern typed methods.
- WordPress `Framework/Gantry.php`, `Theme.php`, `Platform.php` and `Site.php`
  use Timber 1 initialization, context, factories and cache/location globals.
  Migrate those actual calls to Timber 2 APIs; a constraint bump alone is insufficient.

## CMS ownership and acceptance

Grav 1.7.53.8 owns Twig 1.44/Symfony 4.4/Toolbox 1.6.5; keep it on the existing
line. Grav 2.2.19 owns Symfony 7.4/Toolbox 2.0.1 and currently locks development
Twig 3. Consume and verify its supplied APIs rather than installing competing
autoloaders or overwriting its vendors. Our earlier first-admin setup intercept
did not establish Grav 2 compatibility. A real configured Grav 2 control is still
required. Existing themes and particles also need template compilation/rendering,
event payload/priority/stop-propagation, YAML round-trip and CMS admin/save checks.

Sources: [Toolbox 2.0.1](https://github.com/rockettheme/toolbox/tree/2.0.1),
[Symfony 7.4 lifecycle](https://symfony.com/releases/7.4),
[Twig 3](https://twig.symfony.com/doc/3.x/intro.html), and the
[Timber 2 migration guide](https://timber.github.io/docs/v2/upgrade-guides/2.0/).
