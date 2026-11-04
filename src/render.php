<?php
/**
 * Native Joomla production-artifact rebuild, using the shared change-engine lock.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: GPL-2.0-or-later
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) { fwrite(STDERR, "Use PHP 8.5.\n"); exit(1); }
$options = getopt('', ['theme:', 'origin:', 'help']);
if (isset($options['help'])) {
    echo "php libraries/gantry5/src/render.php --theme=<installed theme> --origin=https://example.com[/subdirectory]\n";
    exit;
}
$theme = $options['theme'] ?? '';
$origin = $options['origin'] ?? '';
$url = is_string($origin) ? parse_url($origin) : false;
if (!is_string($theme) || !preg_match('/\A[a-zA-Z0-9_-]{1,100}\z/D', $theme) || !is_array($url)
    || !in_array($url['scheme'] ?? '', ['http', 'https'], true) || empty($url['host'])
    || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
    || preg_match('/[\s\r\n]/', $origin) || str_contains($url['path'] ?? '', '..')) {
    fwrite(STDERR, "Supply an installed --theme and its HTTP(S) --origin without credentials/query/fragment.\n");
    exit(1);
}
// ConsoleApplication has no web document. Seed only deterministic URI inputs;
// keep its native null identity/session and never execute a web application.
$base = rtrim($url['path'] ?? '', '/');
$_SERVER['HTTP_HOST'] = $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
$_SERVER['SERVER_NAME'] = $url['host'];
$_SERVER['SERVER_PORT'] = (string) ($url['port'] ?? ($url['scheme'] === 'https' ? 443 : 80));
$_SERVER['HTTPS'] = $url['scheme'] === 'https' ? 'on' : 'off';
$_SERVER['REQUEST_SCHEME'] = $url['scheme'];
$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $base . '/index.php';
$_SERVER['REQUEST_URI'] = $base . '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$started = hrtime(true);
$completed = false;
$outputLevel = ob_get_level();
ob_start();
register_shutdown_function(static function () use (&$completed, $outputLevel): void {
    if (!$completed) {
        while (ob_get_level() > $outputLevel) { ob_end_clean(); }
        fwrite(STDERR, "Production rebuild did not complete. Inspect the native theme bootstrap.\n");
        exit(1);
    }
});
try {
    define('_JEXEC', 1);
    $site = dirname(__DIR__, 3);
    if (!is_file($site . '/includes/defines.php') || !is_file($site . '/configuration.php')) { throw new RuntimeException('Run this command from the installed Joomla Gantry library.'); }
    if (is_file($site . '/defines.php')) { require_once $site . '/defines.php'; }
    if (!defined('_JDEFINES')) {
        define('JPATH_BASE', $site);
        require_once $site . '/includes/defines.php';
    }
    require_once $site . '/includes/framework.php';
    $container = \Joomla\CMS\Factory::getContainer();
    foreach (['session', 'JSession', \Joomla\CMS\Session\Session::class, \Joomla\Session\Session::class, \Joomla\Session\SessionInterface::class] as $alias) { $container->alias($alias, 'session.cli'); }
    \Joomla\CMS\Factory::$application = $container->get(\Joomla\Console\Application::class);
    require_once __DIR__ . '/Loader.php';
    \Joomla\CMS\Plugin\PluginHelper::importPlugin('system', 'gantry5');
    \Gantry5\Loader::setup();
    \Gantry\Component\Render\BundleRuntime::source(static function () use ($theme, $site, $started): void {
        $gantry = \Gantry\Framework\Gantry::instance();
        $gantry['theme.name'] = $theme;
        $gantry['theme.path'] = $site . '/templates/' . $theme;
        $bootstrap = $gantry['theme.path'] . '/custom/includes/gantry.php';
        if (!is_file($bootstrap)) { $bootstrap = $gantry['theme.path'] . '/includes/gantry.php'; }
        if (!is_file($bootstrap)) { throw new RuntimeException('The installed theme has no native Gantry bootstrap.'); }
        $loaded = include $bootstrap;
        if (!$loaded instanceof \Gantry\Framework\Gantry) { throw new RuntimeException('The native theme could not be initialized.'); }
        // The native bootstrap registers a lazy theme. Its constructor installs
        // theme-specific streams before the engine resolves its journal path.
        $loaded['theme'];
        $engine = new \Gantry\Component\Changes\ChangeEngine($loaded);
        $result = $engine->rebuild($engine->revision());
        echo json_encode(['theme' => $theme, 'version' => GANTRY5_VERSION, 'compiled' => true,
            'revision' => $result['revision'], 'render_generation' => $result['render_generation'],
            'elapsed_ms' => round((hrtime(true) - $started) / 1000000, 3),
            'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    });
    $completed = true;
    ob_end_flush();
} catch (Throwable $error) {
    $completed = true;
    while (ob_get_level() > $outputLevel) { ob_end_clean(); }
    fwrite(STDERR, 'Production rebuild failed: ' . $error->getMessage() . "\n");
    exit(1);
}
