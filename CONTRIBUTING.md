# Contributing to this Gantry fork

Preserve upstream Gantry and dependency licenses. Fork maintenance is by
Matthew X Murphy, https://MatthewxMurphy.com; support is https://builtnotbegged.com.

Use scoped branches and draft pull requests with a concrete reproduction,
changed behavior, actual validation and limitations. Keep Gantry 5/PHP 8.4 work
separate from Gantry 6/PHP 8.5. Do not commit credentials, site data, generated
caches, private acceptance artifacts or dependencies. Scan the exact candidate
before pushing. Never force-push shared branches or overwrite release tags.

For this line use PHP 8.5.x. Build with `composer build-prod`; dependencies remain
locked. Existing focused checks are in `tests/php84`, `tests/php85`,
`tests/modern` and `tests/frontend`. API input checks are documented alongside
their tests. Run meaningful changed-behavior checks and the installed Joomla 6.2
staging acceptance in [API notes](docs/core-api.md). API success requires exact
native admin persistence, front-end rendering and clean PHP warning/fatal logs.
Do not use a production or protected proving site for these checks.

Document endpoint schemas, authentication/authorization, errors, concurrency and
rollback. Keep the shipped OpenAPI contract synchronized with the implementation.
The release owner authorizes publication; a green source check is not an
installed release. See [the runbook](docs/6.0.1-runbook.md).

This release adopts scoped engineering conventions from
[developer-standards](https://github.com/matthewxmurphy/developer-standards).
Portable checker integration and repository branch enforcement are separate
adoption states; this reference does not claim they are already enforced.
