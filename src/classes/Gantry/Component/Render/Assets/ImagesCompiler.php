<?php
/**
 * Build responsive raster variants once, never from frontend image requests.
 * Copyright (c) 2026 Matthew X Murphy, https://MatthewxMurphy.com.
 * SPDX-License-Identifier: MIT
 * Product/support: https://builtnotbegged.com.
 */
declare(strict_types=1);

namespace Gantry\Component\Render\Assets;

use Gantry\Framework\Gantry;

final class ImagesCompiler
{
    private array $images = [];
    private int $encoded = 0;

    public function encodes(): int { return $this->encoded; }

    /** Publisher receives bytes/extension and returns immutable url/id/bytes. */
    public function __construct(private readonly Gantry $gantry, private readonly string $siteRoot, private readonly \Closure $publisher, private readonly PreparedCache $cache, private readonly PublicSources $sources) {}

    /**
     * Image entry: width/height, fallback URL, avif/webp arrays of width+url,
     * original aliases and primary selection remain in the private outline manifest.
     * Remote, SVG, animation, oversized and color-profile-sensitive files are not
     * converted. Their original rendered markup remains authoritative.
     */
    public function prepare(array $sources): array
    {
        $candidates = [];
        foreach ($sources as $source) {
            if (!is_string($source)) { continue; }
            if (!str_contains($source, '<') && preg_match('/\.(?:png|jpe?g|webp|avif)(?:[?#].*)?$/i', trim($source))) { $candidates[] = trim($source); }
            if (preg_match_all('/\b(?:src|poster)\s*=\s*(?:"([^"]+)"|\'([^\']+)\')/i', $source, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) { $candidates[] = html_entity_decode($match[1] !== '' ? $match[1] : $match[2], ENT_QUOTES | ENT_HTML5); }
            }
        }
        $map = [];
        foreach (array_unique($candidates) as $uri) {
            $resolved = $this->sources->resolve($uri, 'image');
            if (!$resolved) { continue; }
            [$path, $url] = $resolved;
            if (!array_key_exists($path, $this->images)) { $this->images[$path] = $this->compile($path); }
            $image = $this->images[$path];
            if (!$image) { continue; }
            $map[$uri] = $map[$url] = $image;
        }
        return $map;
    }

    private function compile(string $path): ?array
    {
        if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp', 'avif'], true)) { return null; }
        if (filesize($path) > 33554432) { return null; }
        $bytes = file_get_contents($path);
        if (!is_string($bytes) || str_contains($bytes, 'ICC_PROFILE') || (str_starts_with($bytes, "\x89PNG\r\n\x1a\n") && (str_contains($bytes, 'acTL') || str_contains($bytes, 'iCCP'))) || (str_starts_with($bytes, 'RIFF') && (str_contains($bytes, 'ANIM') || str_contains($bytes, 'ICCP')))) { return null; }
        $fingerprint = hash('sha256', 'images-v1:' . hash_file('sha256', __FILE__) . ':' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ':' . (defined('GD_VERSION') ? GD_VERSION : 'no-gd') . ':' . $bytes);
        if (($cached = $this->cache->get($fingerprint)) && ImageEntry::valid($cached)) { return $cached; }
        set_error_handler(static function (int $severity, string $message): never { throw new \UnexpectedValueException($message); });
        try { $size = getimagesizefromstring($bytes); }
        catch (\UnexpectedValueException) { return null; }
        finally { restore_error_handler(); }
        if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > 40000000) { return null; }
        if (!extension_loaded('gd') || !function_exists('imageavif') || !function_exists('imagewebp')) {
            throw new \RuntimeException('Responsive render assets require native GD AVIF and WebP encoders.');
        }
        set_error_handler(static function (int $severity, string $message): never { throw new \UnexpectedValueException($message); });
        try { $original = imagecreatefromstring($bytes); }
        catch (\UnexpectedValueException) { return null; }
        finally { restore_error_handler(); }
        if (!$original instanceof \GdImage) { return null; }
        // Browser JPEG orientation is respected; unsupported mirrored sources stay native.
        if (($size['mime'] ?? '') === 'image/jpeg' && function_exists('exif_read_data')) {
            set_error_handler(static function (int $severity, string $message): never { throw new \UnexpectedValueException($message); });
            try { $exif = exif_read_data($path, 'IFD0', true, false); }
            catch (\UnexpectedValueException) { $exif = false; }
            finally { restore_error_handler(); }
            $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);
            if (in_array($orientation, [2, 4, 5, 7], true)) { return null; }
            if (in_array($orientation, [3, 6, 8], true)) {
                $original = imagerotate($original, match ($orientation) { 3 => 180, 6 => -90, 8 => 90 }, 0);
                if (!$original instanceof \GdImage) { throw new \RuntimeException('Could not orient a responsive image.'); }
            }
        }
        $width = imagesx($original); $height = imagesy($original);
        $sizes = array_values(array_filter([320, 640, 960, 1280, 1920], static fn (int $candidate): bool => $candidate <= $width));
        if ($width < 1920 && !in_array($width, $sizes, true)) { $sizes[] = $width; }
        if (!$sizes) { $sizes[] = $width; }
        $formats = ['avif' => [], 'webp' => []];
        foreach ($sizes as $targetWidth) {
            $targetHeight = max(1, (int) round($height * $targetWidth / $width));
            $resized = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            if (!imagecopyresampled($resized, $original, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) { throw new \RuntimeException('Could not resize a responsive image.'); }
            foreach (array_keys($formats) as $format) {
                ++$this->encoded;
                ob_start();
                try {
                    $success = $format === 'avif' ? imageavif($resized, null, 55, 6) : imagewebp($resized, null, 82);
                    $encoded = ob_get_contents();
                } finally { ob_end_clean(); }
                if (!$success || !is_string($encoded) || $encoded === '') { throw new \RuntimeException('Could not encode a responsive image.'); }
                $published = ($this->publisher)($encoded, $format);
                $formats[$format][] = ['width' => $targetWidth, 'height' => $targetHeight, 'url' => $published['url'], 'bytes' => $published['bytes']];
            }
        }
        $extension = match ($size['mime'] ?? '') { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif', default => null };
        if ($extension === null) { return null; }
        $fallback = ($this->publisher)($bytes, $extension);
        $result = ['width' => $width, 'height' => $height, 'fallback' => $fallback['url'], 'avif' => $formats['avif'], 'webp' => $formats['webp']];
        $this->cache->set($fingerprint, $result);
        return $result;
    }
}
