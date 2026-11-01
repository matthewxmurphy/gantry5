<?php
/**
 * Real filesystem/bitmap acceptance for the portable visual design-pack boundary.
 * Copyright (c) 2026 Matthew C. Murphy, https://MatthewxMurphy.com. MIT.
 * Usage: php tests/php84/design-pack.php (actual PHP 8.4 with GD).
 */
use Gantry\Component\DesignPack\DesignPack;

if (PHP_SAPI !== 'cli' || PHP_VERSION_ID < 80400 || PHP_VERSION_ID >= 80500 || !function_exists('imagecreatetruecolor')) {
    throw new RuntimeException('Use PHP 8.4 CLI with the real GD extension.');
}
require dirname(__DIR__, 2) . '/src/classes/Gantry/Component/DesignPack/DesignPack.php';
$checks = 0;
$expect = static function ($condition, $label) use (&$checks) {
    if (!$condition) {
        throw new RuntimeException('Failed: ' . $label);
    }
    ++$checks;
};
$refused = static function (callable $action, $label) use ($expect) {
    try {
        $action();
    } catch (RuntimeException $error) {
        $expect(true, $label);
        return;
    }
    $expect(false, $label);
};
$root = sys_get_temp_dir() . '/gantry-design-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$remove = static function ($path) use (&$remove) {
    if (is_dir($path) && !is_link($path)) {
        foreach (new DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) {
                $remove($entry->getPathname());
            }
        }
        rmdir($path);
    } else {
        unlink($path);
    }
};
try {
    mkdir($root . '/custom');
    mkdir($root . '/custom/images');
    mkdir($root . '/custom/config');
    mkdir($root . '/custom/config/existing');
    $private = "content: SYNTHETIC_PRIVATE_DO_NOT_EXPORT\n";
    file_put_contents($root . '/custom/config/existing/custom.yaml', $private);
    file_put_contents($root . '/outside.txt', 'preserve outside');
    $image = imagecreatetruecolor(2, 3);
    imagepng($image, $root . '/custom/images/mark.png');
    imagedestroy($image);
    $bitmap = file_get_contents($root . '/custom/images/mark.png');
    $styles = ['accent.color-1' => ['kind' => 'color', 'section' => null],
        'font.family-default' => ['kind' => 'font', 'section' => null],
        'header.background' => ['kind' => 'color', 'section' => 'header'],
        'footer.background' => ['kind' => 'color', 'section' => 'footer']];
    $particles = DesignPack::PARTICLE_FIELDS;
    $service = new DesignPack($styles, $particles, $root . '/custom');
    $asset = $service->asset('mark.png', 'SYNTHETIC FULL ASSET LICENSE', 'SYNTHETIC ORIGINAL NOTICE');
    $pack = ['schema' => 1, 'id' => 'studio', 'version' => '1.2.3', 'title' => 'Studio', 'author' => 'Synthetic designer',
        'license' => 'SYNTHETIC FULL PACK LICENSE', 'notices' => 'Retain this notice.', 'gantry_min' => '5.6.5',
        'styles' => ['accent.color-1' => '#123abc', 'font.family-default' => 'Arial, sans-serif'],
        'particle_defaults' => ['logo' => ['image' => 'asset:mark', 'height' => '3rem', 'link' => true], 'totop' => ['icon' => 'fa fa-chevron-up']],
        'layout' => [['section' => 'header', 'rows' => [[['type' => 'logo', 'width' => 100]]]],
            ['section' => 'footer', 'rows' => [[['type' => 'system-content', 'width' => 75], ['type' => 'totop', 'width' => 25]]]]],
        'assets' => ['mark' => $asset]];
    $bytes = DesignPack::encode($pack);
    $expect($service->decode($bytes) === $pack, 'canonical pack roundtrip');
    $preview = $service->preview($pack);
    $expect($preview['sections'] === ['header', 'footer'] && count($preview['assets']) === 1, 'actual compatibility preview');
    $expect(!isset($preview['assets']['mark']['bytes']) && strpos(DesignPack::encode($pack), 'SYNTHETIC_PRIVATE') === false, 'no instance content or bitmap bytes in preview');
    $expect(!is_dir($root . '/custom/images/design-packs') && count(scandir($root . '/custom/config')) === 3, 'preview writes no outline/assets');
    $installed = $service->installAssets($pack);
    $expect(file_get_contents($installed['directory'] . '/mark.png') === $bitmap, 'exact decoded bitmap installed');
    $expect(strpos($installed['files']['mark'], $root . '/custom/images/design-packs/studio/1.2.3-') === 0, 'new namespaced exact asset path');
    $second = $service->installAssets($pack);
    $expect($second['directory'] !== $installed['directory'] && file_get_contents($installed['directory'] . '/mark.png') === $bitmap, 'repeated installation never overwrites assets');
    $resolvedUrls = ['mark' => '/fixture/custom/images/design-packs/mark.png'];
    $layout = $service->layout($pack, $resolvedUrls);
    $attrs = $layout['content']['logo-1001']['attributes'];
    $expect($attrs['image'] === $resolvedUrls['mark'] && $attrs['svg'] === '' && $attrs['url'] === '' && $attrs['text'] === 'Logo', 'visual instance is resolved without inherited logo content');
    $expect($layout['content']['totop-1003']['attributes']['title'] === 'Back to top', 'static accessible label excludes inherited content');
    $expect(!isset($layout['atoms']) && !isset($layout['inherit']) && strpos(json_encode($layout), 'SYNTHETIC_PRIVATE') === false, 'structural layout excludes atoms and instance content');
    $fresh = $service->reserveOutline('design-studio-' . str_repeat('a', 16));
    $expect(is_dir($fresh) && count(scandir($fresh)) === 2, 'exclusive new outline reservation');
    $refused(static function () use ($service) { $service->reserveOutline('design-studio-' . str_repeat('a', 16)); }, 'existing outline cannot be reserved');
    $expect(file_get_contents($root . '/custom/config/existing/custom.yaml') === $private && file_get_contents($root . '/custom/images/mark.png') === $bitmap, 'existing outline and source bitmap preserved');

    foreach ([
        'unknown field' => static function (&$p) { $p['styles']['api.token'] = '#123abc'; },
        'missing style' => static function (&$p) { $p['styles']['other.color'] = '#123abc'; },
        'CSS payload' => static function (&$p) { $p['styles']['accent.color-1'] = 'url(https://invalid.test)'; },
        'font URL' => static function (&$p) { $p['styles']['font.family-default'] = 'url(https://invalid.test)'; },
        'private particle field' => static function (&$p) { $p['particle_defaults']['logo']['svg'] = '<svg/>'; },
        'CMS module binding' => static function (&$p) { $p['layout'][0]['rows'][0][0]['type'] = 'position-module'; },
        'unavailable section' => static function (&$p) { $p['layout'][0]['section'] = 'missing'; },
        'duplicate section' => static function (&$p) { $p['layout'][1]['section'] = 'header'; },
        'private layout attrs' => static function (&$p) { $p['layout'][0]['attributes'] = ['content' => 'private']; },
        'row width' => static function (&$p) { $p['layout'][0]['rows'][0][0]['width'] = 99; },
        'external image link' => static function (&$p) { $p['particle_defaults']['logo']['image'] = 'https://invalid.test/logo.png'; },
        'missing logical asset' => static function (&$p) { $p['particle_defaults']['logo']['image'] = 'asset:missing'; },
        'SVG disguised bitmap' => static function (&$p) { $p['assets']['mark']['bytes'] = base64_encode('<svg/>'); $p['assets']['mark']['sha256'] = hash('sha256', '<svg/>'); },
        'corrupt bitmap digest' => static function (&$p) { $p['assets']['mark']['sha256'] = str_repeat('0', 64); },
        'fake bitmap dimensions' => static function (&$p) { $p['assets']['mark']['width'] = 600; },
        'asset path traversal' => static function (&$p) { $p['assets']['mark']['name'] = '../outside.png'; },
        'asset case collision' => static function (&$p) { $p['assets']['other'] = $p['assets']['mark']; $p['assets']['other']['name'] = 'MARK.png'; },
        'missing full notice field' => static function (&$p) { unset($p['assets']['mark']['notice']); },
        'unknown schema' => static function (&$p) { $p['schema'] = 2; },
        'embedded executable' => static function (&$p) { $p['twig'] = 'code'; }
    ] as $label => $change) {
        $bad = $pack;
        $change($bad);
        $refused(static function () use ($service, $bad) { $service->validate($bad); }, $label);
    }
    $without = new DesignPack($styles, [], $root . '/custom');
    $refused(static function () use ($without, $pack) { $without->validate($pack); }, 'missing installed visual particle');
    $refused(static function () use ($service, $bytes) { $service->decode(str_replace('"schema": 1,', '"schema": 1, "schema": 1,', $bytes)); }, 'duplicate JSON field');
    $refused(static function () use ($service) { $service->decode(str_repeat('x', DesignPack::MAX_JSON_BYTES + 1)); }, 'bounded JSON input');
    $refused(static function () use ($service) { $service->asset('../outside.png', 'license', 'notice'); }, 'source traversal');
    $refused(static function () use ($service) { $service->asset('MARK.png', 'license', 'notice'); }, 'source case alias');
    symlink($root . '/custom/images/mark.png', $root . '/custom/images/link.png');
    $refused(static function () use ($service) { $service->asset('link.png', 'license', 'notice'); }, 'source symlink');
    link($root . '/custom/images/mark.png', $root . '/custom/images/hard.png');
    $refused(static function () use ($service) { $service->asset('hard.png', 'license', 'notice'); }, 'source hardlink');
    unlink($root . '/custom/images/hard.png');
    symlink($root . '/outside.txt', $root . '/custom/images/design-packs/trap');
    $badRoot = $root . '/linked';
    symlink($root . '/custom', $badRoot);
    $refused(static function () use ($styles, $particles, $badRoot) { new DesignPack($styles, $particles, $badRoot); }, 'linked custom root');
    $service->removeNewAssets($installed['directory']);
    $service->removeNewAssets($second['directory']);
    $expect(!is_dir($installed['directory']) && !is_dir($second['directory']), 'request-owned bitmap namespace cleanup');
    $service->removeNewAssets($root . '/custom/images');
    $expect(is_file($root . '/custom/images/mark.png') && file_get_contents($root . '/outside.txt') === 'preserve outside', 'cleanup cannot remove unowned asset directory/outside bytes');
    echo 'PASS ' . $checks . " design-pack filesystem/bitmap checks\n";
} finally {
    $remove($root);
}
