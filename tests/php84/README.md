# PHP 8.4 nullable-rewriter checks

Run with **actual PHP 8.4 CLI**:

```sh
php tests/php84/nullable-rewriter.php
```

The standalone test loads the existing rewriter without invoking its file-writing
main function. It covers nullable intersections and DNF unions, references,
grouped attributes, scalar/array types, promoted parameters, idempotence and
unchanged function bodies. Rewritten fixtures are parsed by PHP 8.4; function
cases exercise accepted/omitted-null/rejected calls under E_ALL, while the
promotion case checks default/data preservation.

The existing self-tests also parse their expected output on PHP 8.2+, where DNF
syntax exists. Earlier builders retain their textual self-tests. The existing
Composer upper bound remains below PHP 8.5; this suite does not extend that bound
or establish whole-platform compatibility on other PHP versions.

No CMS, database, network or installed-site files are loaded. Joomla, WordPress,
Grav and real template/particle rendering require separate integration tests.
The legacy SCSS Compiler interpolation change has PHP 8.4 lint coverage only;
this suite does not load its legacy dependencies or verify legacy arithmetic.

Maintenance author: Matthew C. Murphy, https://MatthewxMurphy.com.
Product/support: https://builtnotbegged.com. New maintenance/test contributions use
MIT; existing Gantry and third-party licenses and attribution remain unchanged.
