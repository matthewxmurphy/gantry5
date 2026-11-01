<?php
/**
 * Explicitly shareable visual design definitions; no executable particle installation.
 * Copyright (c) 2026 Matthew C. Murphy, https://MatthewxMurphy.com. MIT.
 * Existing Gantry, theme and supplied asset licenses remain unchanged.
 */
namespace Gantry\Component\DesignPack;

class DesignPack
{
    const MAX_JSON_BYTES = 2097152;
    const MAX_IMAGE_BYTES = 524288;
    const MAX_IMAGES_BYTES = 1048576;
    const RESERVED_SECTIONS = ['atoms', 'offcanvas', 'container', 'wrapper', 'grid', 'block'];
    const PARTICLE_FIELDS = [
        'logo' => ['image' => 'asset', 'height' => 'length', 'link' => 'boolean', 'target' => 'target', 'class' => 'classes'],
        'totop' => ['css.class' => 'classes', 'icon' => 'classes']
    ];

    private $styles;
    private $particles;
    private $root;
    private $createdAssetDirectories = [];

    /** Catalogues come from installed, platform-compatible style/particle blueprints. */
    public function __construct(array $styles, array $particles, $customRoot)
    {
        $this->styles = $styles;
        $this->particles = $particles;
        if (!is_string($customRoot) || $customRoot === '' || $customRoot[0] !== '/' || strpos($customRoot, '..') !== false) {
            throw new \RuntimeException('A local custom theme directory is required.', 400);
        }
        $this->root = rtrim($customRoot, '/');
        $this->path('', false);
    }

    public static function encode(array $pack)
    {
        return json_encode($pack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function decode($bytes)
    {
        if (!is_string($bytes) || strlen($bytes) > self::MAX_JSON_BYTES) {
            throw new \RuntimeException('Choose a design pack within the upload limit.', 400);
        }
        try {
            $pack = json_decode($bytes, true, 20, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('The design pack is not valid bounded JSON.', 400);
        }
        if (!is_array($pack) || self::encode($pack) !== $bytes) {
            throw new \RuntimeException('Use the original canonical design pack JSON; ambiguous or reformatted documents are refused.', 400);
        }
        $this->validate($pack);
        return $pack;
    }

    /** Validate every value before preview or any filesystem write. */
    public function validate(array $pack)
    {
        $this->keys($pack, ['schema', 'id', 'version', 'title', 'author', 'license', 'notices', 'gantry_min', 'styles', 'particle_defaults', 'layout', 'assets']);
        if ($pack['schema'] !== 1 || !is_string($pack['id']) || !preg_match('/\A[a-z][a-z0-9-]{0,47}\z/D', $pack['id'])
            || !is_string($pack['version']) || !preg_match('/\A\d{1,4}\.\d{1,4}\.\d{1,4}\z/D', $pack['version'])
            || $pack['gantry_min'] !== '5.6.5') {
            throw new \RuntimeException('Unsupported design pack identity or schema.', 400);
        }
        foreach (['title' => 120, 'author' => 200, 'license' => 32768, 'notices' => 65536] as $key => $limit) {
            $this->text($pack[$key], $limit, $key === 'notices');
        }
        if (!is_array($pack['styles']) || count($pack['styles']) > 80 || !is_array($pack['particle_defaults']) || count($pack['particle_defaults']) > 2
            || !is_array($pack['assets']) || count($pack['assets']) > 8) {
            throw new \RuntimeException('Design pack collections exceed supported bounds.', 400);
        }
        $total = 0;
        $names = [];
        foreach ($pack['assets'] as $id => $asset) {
            if (!is_string($id) || !preg_match('/\A[a-z][a-z0-9-]{0,31}\z/D', $id) || !is_array($asset)) {
                throw new \RuntimeException('Invalid asset identity.', 400);
            }
            $this->keys($asset, ['name', 'mime', 'width', 'height', 'sha256', 'bytes', 'license', 'notice']);
            $this->text($asset['license'], 32768);
            $this->text($asset['notice'], 65536, true);
            if (!is_string($asset['bytes']) || !is_string($asset['sha256']) || !preg_match('/\A[a-f0-9]{64}\z/D', $asset['sha256'])) {
                throw new \RuntimeException('Invalid bitmap encoding.', 400);
            }
            $binary = base64_decode($asset['bytes'], true);
            if (!is_string($binary) || base64_encode($binary) !== $asset['bytes'] || hash('sha256', $binary) !== $asset['sha256']) {
                throw new \RuntimeException('Bitmap digest or encoding does not match.', 400);
            }
            $info = $this->image($asset['name'], $binary);
            if ($info['mime'] !== $asset['mime'] || $info['width'] !== $asset['width'] || $info['height'] !== $asset['height']) {
                throw new \RuntimeException('Bitmap metadata does not match the actual image.', 400);
            }
            $name = strtolower($asset['name']);
            if (isset($names[$name])) {
                throw new \RuntimeException('Asset filenames must be unique, including letter case.', 400);
            }
            $names[$name] = true;
            $total += strlen($binary);
            if ($total > self::MAX_IMAGES_BYTES) {
                throw new \RuntimeException('The selected bitmaps exceed the combined limit.', 400);
            }
        }
        foreach ($pack['styles'] as $key => $value) {
            if (!is_string($key) || !isset($this->styles[$key])) {
                throw new \RuntimeException('A design style field is not available in this theme.', 400);
            }
            $this->value($this->styles[$key]['kind'], $value, $pack['assets']);
        }
        foreach ($pack['particle_defaults'] as $type => $fields) {
            if (!isset(self::PARTICLE_FIELDS[$type], $this->particles[$type]) || !is_array($fields)) {
                throw new \RuntimeException('A visual particle type is unavailable or unsupported.', 400);
            }
            foreach ($fields as $field => $value) {
                if (!isset(self::PARTICLE_FIELDS[$type][$field], $this->particles[$type][$field])) {
                    throw new \RuntimeException('A visual particle field is unavailable or unsupported.', 400);
                }
                $this->value(self::PARTICLE_FIELDS[$type][$field], $value, $pack['assets']);
            }
        }
        if (!is_array($pack['layout']) || !$pack['layout'] || count($pack['layout']) > 12 || array_values($pack['layout']) !== $pack['layout']) {
            throw new \RuntimeException('Choose one to twelve structural sections.', 400);
        }
        $sections = [];
        $placements = 0;
        foreach ($pack['layout'] as $section) {
            if (!is_array($section)) {
                throw new \RuntimeException('Invalid structural section.', 400);
            }
            $this->keys($section, ['section', 'rows']);
            $id = $section['section'];
            if (!is_string($id) || !preg_match('/\A[a-z][a-z0-9-]{0,31}\z/D', $id) || in_array($id, self::RESERVED_SECTIONS, true) || isset($sections[$id])
                || !is_array($section['rows']) || count($section['rows']) > 8 || array_values($section['rows']) !== $section['rows']) {
                throw new \RuntimeException('Sections need unique names and bounded rows.', 400);
            }
            // A section style target is mandatory: no fabricated cross-theme mapping.
            $hasSection = false;
            foreach ($this->styles as $field => $definition) {
                if (($definition['section'] ?? null) === $id) {
                    $hasSection = true;
                    break;
                }
            }
            if (!$hasSection) {
                throw new \RuntimeException('A matching section style blueprint is missing.', 400);
            }
            $sections[$id] = true;
            foreach ($section['rows'] as $row) {
                if (!is_array($row) || !$row || count($row) > 4 || array_values($row) !== $row) {
                    throw new \RuntimeException('Rows need one to four visual placements.', 400);
                }
                $width = 0;
                foreach ($row as $item) {
                    if (!is_array($item)) {
                        throw new \RuntimeException('Invalid visual placement.', 400);
                    }
                    $this->keys($item, ['type', 'width']);
                    if (!is_string($item['type']) || !in_array($item['type'], ['logo', 'totop', 'system-content'], true)
                        || ($item['type'] !== 'system-content' && !isset($this->particles[$item['type']]))
                        || !is_int($item['width']) || $item['width'] < 1 || $item['width'] > 100 || ++$placements > 40) {
                        throw new \RuntimeException('Unsupported particle, CMS binding or placement size.', 400);
                    }
                    $width += $item['width'];
                }
                if ($width !== 100) {
                    throw new \RuntimeException('Each structural row must total 100 percent.', 400);
                }
            }
        }
        if (strlen(self::encode($pack)) > self::MAX_JSON_BYTES) {
            throw new \RuntimeException('The complete pack exceeds the download limit.', 400);
        }
        return $pack;
    }

    /** Explicit custom/images selection only; no fallback to vendor or packaged files. */
    public function asset($relative, $license, $notice)
    {
        if (!is_string($relative) || !preg_match('#\A(?:[a-zA-Z0-9_-]+/){0,4}[a-zA-Z0-9_-]+\.(?:png|jpg|jpeg|webp)\z#D', $relative)) {
            throw new \RuntimeException('Select a bitmap below this theme custom images directory.', 400);
        }
        $path = $this->path('images/' . $relative, true);
        $stat = lstat($path);
        if (!is_array($stat) || !is_file($path) || $stat['nlink'] !== 1 || $stat['size'] > self::MAX_IMAGE_BYTES) {
            throw new \RuntimeException('The selected bitmap is linked, too large or unavailable.', 400);
        }
        $binary = file_get_contents($path, false, null, 0, self::MAX_IMAGE_BYTES + 1);
        $info = $this->image(basename($relative), $binary);
        return ['name' => basename($relative)] + $info + ['sha256' => hash('sha256', $binary), 'bytes' => base64_encode($binary), 'license' => $license, 'notice' => $notice];
    }

    public function preview(array $pack)
    {
        $this->validate($pack);
        return ['id' => $pack['id'], 'version' => $pack['version'], 'title' => $pack['title'], 'author' => $pack['author'],
            'license' => $pack['license'], 'notices' => $pack['notices'], 'styles' => array_keys($pack['styles']),
            'sections' => array_column($pack['layout'], 'section'), 'particles' => array_keys($pack['particle_defaults']),
            'assets' => array_map(static function ($asset) {
                return array_intersect_key($asset, array_flip(['name', 'mime', 'width', 'height', 'license', 'notice']));
            }, $pack['assets']), 'digest' => hash('sha256', self::encode($pack))];
    }

    /** Build native v2 layout data; arbitrary attrs/content/atoms never cross this boundary. */
    public function layout(array $pack, array $assetUris = [])
    {
        $this->validate($pack);
        $data = ['version' => 2, 'preset' => ['name' => $pack['id']], 'layout' => [], 'structure' => [], 'content' => []];
        $number = 1000;
        foreach ($pack['layout'] as $section) {
            $name = $section['section'];
            $data['structure'][$name] = ['type' => 'section'];
            $rows = [];
            foreach ($section['rows'] as $row) {
                $items = [];
                foreach ($row as $item) {
                    $type = $item['type'];
                    $id = $type . '-' . ++$number;
                    $items[] = $id . ' ' . $item['width'];
                    if ($type !== 'system-content') {
                        $attrs = ['enabled' => 1];
                        foreach ($pack['particle_defaults'][$type] ?? [] as $field => $value) {
                            $value = is_string($value) && strpos($value, 'asset:') === 0 ? ($assetUris[substr($value, 6)] ?? '') : $value;
                            $parts = explode('.', $field);
                            $target = &$attrs;
                            foreach ($parts as $part) {
                                if (!isset($target[$part])) {
                                    $target[$part] = [];
                                }
                                $target = &$target[$part];
                            }
                            $target = $value;
                            unset($target);
                        }
                        // Empty explicit values block inherited site-specific content/URLs/SVG.
                        $attrs += $type === 'logo' ? ['url' => '', 'svg' => '', 'text' => 'Logo', 'image' => ''] : ['content' => '', 'title' => 'Back to top'];
                        $data['content'][$id] = ['attributes' => $attrs];
                    }
                }
                $rows[] = $items;
            }
            $data['layout']['/' . $name . '/'] = $rows;
        }
        return $data;
    }

    /** Reserve a fresh namespace; files are opened exclusively and never overwrite assets. */
    public function installAssets(array $pack)
    {
        $this->validate($pack);
        if (!$pack['assets']) {
            return ['directory' => null, 'files' => []];
        }
        $relative = 'images/design-packs/' . $pack['id'] . '/' . $pack['version'] . '-' . bin2hex(random_bytes(6));
        $directory = $this->directory($relative, true);
        $this->createdAssetDirectories[$directory] = true;
        $files = [];
        try {
            foreach ($pack['assets'] as $id => $asset) {
                $handle = fopen($directory . '/' . $asset['name'], 'xb');
                if (!$handle) {
                    throw new \RuntimeException('Cannot create a new design bitmap.', 500);
                }
                try {
                    $bytes = base64_decode($asset['bytes'], true);
                    if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                        throw new \RuntimeException('Cannot save the complete design bitmap.', 500);
                    }
                } finally {
                    fclose($handle);
                }
                $files[$id] = $directory . '/' . $asset['name'];
            }
        } catch (\Throwable $error) {
            $this->removeNewAssets($directory);
            throw $error;
        }
        return ['directory' => $directory, 'files' => $files];
    }

    public function reserveOutline($id)
    {
        $id = (string) $id;
        if (!preg_match('/\A(?:design-[a-z0-9-]+-[a-f0-9]{16}|[1-9][0-9]{0,11})\z/D', $id)) {
            throw new \RuntimeException('Invalid new outline identity.', 400);
        }
        return $this->directory('config/' . $id, true);
    }

    public function removeNewAssets($directory)
    {
        if (!is_string($directory) || !isset($this->createdAssetDirectories[$directory]) || is_link($directory)) {
            return;
        }
        foreach (new \DirectoryIterator($directory) as $entry) {
            if (!$entry->isDot() && $entry->isFile() && !$entry->isLink()) {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
        unset($this->createdAssetDirectories[$directory]);
    }

    private function directory($relative, $exclusive)
    {
        $path = $this->root;
        $parts = explode('/', trim($relative, '/'));
        $rootParts = explode('/', ltrim($this->root, '/'));
        $path = '';
        foreach (array_merge($rootParts, $parts) as $index => $part) {
            $path .= '/' . $part;
            $last = $index === count($rootParts) + count($parts) - 1;
            if (file_exists($path) || is_link($path)) {
                $this->path(substr($path, strlen($this->root) + 1), false, $path);
                if (!is_dir($path) || ($last && $exclusive)) {
                    throw new \RuntimeException('A design destination already exists or is unsafe.', 400);
                }
            } elseif (!mkdir($path, 0755)) {
                throw new \RuntimeException('Cannot create a new design destination.', 500);
            }
        }
        return $path;
    }

    private function path($relative, $required, $absolute = null)
    {
        $path = $absolute ?: $this->root . ($relative !== '' ? '/' . $relative : '');
        $current = '';
        foreach (explode('/', ltrim($path, '/')) as $part) {
            $parent = $current ?: '/';
            if (is_dir($parent)) {
                foreach (new \DirectoryIterator($parent) as $entry) {
                    if (!$entry->isDot() && strtolower($entry->getFilename()) === strtolower($part) && $entry->getFilename() !== $part) {
                        throw new \RuntimeException('Ambiguous letter case in a design path.', 400);
                    }
                }
            }
            $current .= '/' . $part;
            if (is_link($current)) {
                throw new \RuntimeException('Linked design paths are not supported.', 400);
            }
            if (file_exists($current)) {
                $stat = lstat($current);
                if (!is_dir($current) && (!is_file($current) || $stat['nlink'] !== 1)) {
                    throw new \RuntimeException('Special or hard-linked design paths are not supported.', 400);
                }
            } elseif ($required) {
                throw new \RuntimeException('Selected custom bitmap is unavailable.', 400);
            }
        }
        return $path;
    }

    private function image($name, $bytes)
    {
        if (!is_string($name) || !preg_match('/\A[a-zA-Z0-9_-]+\.(png|jpe?g|webp)\z/D', $name, $match)
            || !is_string($bytes) || !$bytes || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new \RuntimeException('Only bounded PNG, JPEG or WebP bitmaps are supported.', 400);
        }
        $info = @getimagesizefromstring($bytes);
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$match[1]];
        if (!$info || ($info['mime'] ?? '') !== $mime || $info[0] < 1 || $info[1] < 1 || $info[0] > 4096 || $info[1] > 4096 || $info[0] * $info[1] > 16000000) {
            throw new \RuntimeException('The bitmap format or decoded dimensions are unsupported.', 400);
        }
        if (!function_exists('imagecreatefromstring') || !($decoded = @imagecreatefromstring($bytes))) {
            throw new \RuntimeException('The bitmap cannot be decoded; a working GD image decoder is required.', 400);
        }
        // GdImage is released with its last reference on all supported PHP versions.
        unset($decoded);
        return ['mime' => $mime, 'width' => $info[0], 'height' => $info[1]];
    }

    private function value($kind, $value, array $assets)
    {
        $ok = false;
        if ($kind === 'boolean') {
            $ok = is_bool($value);
        } elseif (is_string($value)) {
            switch ($kind) {
                case 'color': $ok = (bool) preg_match('/\A(?:#[a-fA-F0-9]{3}|#[a-fA-F0-9]{6}|#[a-fA-F0-9]{8}|transparent)\z/D', $value); break;
                case 'font': $ok = strlen($value) <= 200 && (bool) preg_match('/\A[A-Za-z0-9 ,\'-]+\z/D', $value); break;
                case 'length': $ok = $value === '' || (bool) preg_match('/\A\d{1,4}(?:\.\d{1,3})?(?:rem|em|px|%)\z/D', $value); break;
                case 'classes': $ok = strlen($value) <= 160 && (bool) preg_match('/\A[a-zA-Z0-9 _-]*\z/D', $value); break;
                case 'target': $ok = in_array($value, ['_self', '_parent', '_blank'], true); break;
                case 'asset': $ok = strpos($value, 'asset:') === 0 && isset($assets[substr($value, 6)]); break;
            }
        }
        if (!$ok) {
            throw new \RuntimeException('A design value is outside the supported visual field format.', 400);
        }
    }

    private function keys(array $data, array $keys)
    {
        if (count($data) !== count($keys) || array_diff(array_keys($data), $keys) || array_diff($keys, array_keys($data))) {
            throw new \RuntimeException('Unknown or missing design pack fields are not supported.', 400);
        }
    }

    private function text($value, $limit, $empty = false)
    {
        if (!is_string($value) || (!$empty && trim($value) === '') || strlen($value) > $limit || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            throw new \RuntimeException('Design attribution or notice text is missing or exceeds its limit.', 400);
        }
    }
}
