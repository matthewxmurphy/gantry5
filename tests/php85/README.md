# PHP 8.5 runtime regressions

Run on actual PHP 8.3, 8.4 or 8.5 CLI with cURL:

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
The Gantry 6 development candidate retains PHP 8.3/8.4 support and caps admission
below 8.6. Gantry 5's separate PHP 8.4 work remains on its existing branch.

Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
Product/support: https://builtnotbegged.com. New tests use MIT; existing code
retains its original licenses and notices.
