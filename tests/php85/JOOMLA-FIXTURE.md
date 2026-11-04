# Native Joomla WAM fixture

The WAM regression uses the real Joomla 6.2.0 Event/WebAsset classes and its packaged
Composer dependencies. The fixture also includes the full native `libraries/src`
tree for the genuine Access/Factory/database regressions. It does not install or
bootstrap a Joomla site, or copy any site configuration or credentials.

Run with PHP 8.5 and ext-zip. A missing archive additionally requires ext-curl:

```sh
php tests/php85/prepare-joomla-fixture.php --directory=/tmp/gantry-joomla-6.2.0
php tests/php85/joomla-render-assets.php platforms/joomla/lib_gantry5/vendor/autoload.php /tmp/gantry-joomla-6.2.0/libraries/vendor/autoload.php /tmp/gantry-joomla-6.2.0/libraries/src
```

For offline use, pass `--zip=/absolute/path/Joomla_6.2.0-Stable-Full_Package.zip`.
A matching cached ZIP is used without networking; a mismatched ZIP fails without
being replaced. The preparer verifies the published SHA-256 before extraction and
checks an existing extraction against that archive before returning its paths.
Keep output outside the repository (for CI, use `$RUNNER_TEMP`) so fixture PHP and
downloaded vendor files cannot enter Gantry packages.

The pin is the [official Joomla 6.2.0 full package](https://github.com/joomla/joomla-cms/releases/tag/6.2.0),
SHA-256 `eaafc68b5990d7b9dd9156656d4c3c3547743d1d7a85682a92e2f9121b58f018`.
