<?php
/**
 * Explicit visual design-pack download, compatibility preview and new-outline import.
 * Copyright (c) 2026 Matthew C. Murphy, https://MatthewxMurphy.com. MIT.
 */
namespace Gantry\Admin\Controller\Html\Configurations;

use Gantry\Component\Admin\HtmlController;
use Gantry\Component\Config\Config;
use Gantry\Component\DesignPack\DesignPack;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\Filesystem\Folder;
use Gantry\Component\Layout\LayoutReader;
use Gantry\Component\Response\HtmlResponse;

class Designpacks extends HtmlController
{
    protected $httpVerbs = [
        'GET' => ['/' => 'index'],
        'POST' => ['/download' => 'download', '/preview' => 'preview', '/apply' => 'apply']
    ];

    private function catalogue()
    {
        $styles = [];
        foreach ($this->container['styles']->all() as $block => $definition) {
            if (!preg_match('/\A[a-z][a-z0-9-]*\z/D', $block)) {
                continue;
            }
            foreach ($definition['form']['fields'] ?? [] as $field => $data) {
                if (!is_array($data) || !preg_match('/\A[a-z][a-z0-9-]*\z/D', $field)) {
                    continue;
                }
                $kind = ['input.colorpicker' => 'color', 'input.fonts' => 'font'][$data['type'] ?? ''] ?? null;
                if ($kind) {
                    $styles[$block . '.' . $field] = ['kind' => $kind, 'section' => ($definition['type'] ?? null) === 'section' ? $block : null];
                }
            }
        }
        $particles = [];
        foreach ($this->container['particles']->all() as $type => $definition) {
            if (isset(DesignPack::PARTICLE_FIELDS[$type]) && ($definition['type'] ?? null) === 'particle') {
                $particles[$type] = array_intersect_key(DesignPack::PARTICLE_FIELDS[$type], $definition['form']['fields'] ?? []);
            }
        }
        return [$styles, $particles];
    }

    private function service()
    {
        if (!$this->authorize('platform.settings.manage')) {
            $this->forbidden();
        }
        $outline = $this->params['outline'];
        if (!is_string($outline) || !isset($this->container['outlines'][$outline])) {
            throw new \RuntimeException('Outline not found.', 404);
        }
        if (version_compare(GANTRY5_VERSION, '5.6.5', '<')) {
            throw new \RuntimeException('Design packs require Gantry 5.6.5 or later.', 400);
        }
        list($styles, $particles) = $this->catalogue();
        $default = $this->container['locator']->findResource('gantry-config://default', true, true);
        if (!is_string($default) || basename($default) !== 'default' || basename(dirname($default)) !== 'config') {
            throw new \RuntimeException('The writable theme configuration path is unavailable.', 400);
        }
        return new DesignPack($styles, $particles, dirname($default, 2));
    }

    public function index()
    {
        $this->service();
        return $this->page();
    }

    private function page()
    {
        list($styles, $particles) = $this->catalogue();
        $this->params['design_fields'] = $styles;
        $this->params['design_particles'] = $particles;
        $this->params['pack_limit'] = DesignPack::MAX_JSON_BYTES;
        $sections = [];
        foreach ($styles as $definition) {
            if ($definition['section'] && !in_array($definition['section'], DesignPack::RESERVED_SECTIONS, true)) {
                $sections[$definition['section']] = $definition['section'];
            }
        }
        $this->params['design_sections'] = array_values($sections);
        $middle = isset($sections['intro']) ? 'intro' : (isset($sections['features']) ? 'features' : (reset($sections) ?: 'main'));
        $this->params['layout_example'] = json_encode([
            ['section' => 'header', 'rows' => [[['type' => 'logo', 'width' => 100]]]],
            ['section' => $middle, 'rows' => [[['type' => 'system-content', 'width' => 100]]]],
            ['section' => 'footer', 'rows' => [[['type' => 'totop', 'width' => 100]]]]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $response = new HtmlResponse($this->render('@gantry-admin/pages/configurations/designpacks.html.twig', $this->params));
        $response->setHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    private function inputJson($field, $max = 32768)
    {
        $bytes = $this->request->post[$field];
        if (!is_string($bytes) || strlen($bytes) > $max) {
            throw new \RuntimeException('A design definition is missing or exceeds its limit.', 400);
        }
        try {
            $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Use valid bounded JSON for the design definition.', 400);
        }
        if (!is_array($data)) {
            throw new \RuntimeException('A design definition must be a JSON object or array.', 400);
        }
        return $data;
    }

    public function download()
    {
        $service = $this->service();
        if ($this->request->post['share_ack'] !== 'yes') {
            throw new \RuntimeException('Review the selected shareable values and asset rights before downloading.', 400);
        }
        $pack = ['schema' => 1];
        foreach (['id', 'version', 'title', 'author', 'license', 'notices'] as $key) {
            $pack[$key] = $this->request->post[$key];
        }
        $pack += ['gantry_min' => '5.6.5', 'styles' => [], 'particle_defaults' => $this->inputJson('particle_json'),
            'layout' => $this->inputJson('layout_json'), 'assets' => []];
        list($catalogue) = $this->catalogue();
        $selected = $this->request->post['style_fields'];
        if ($selected !== null && !is_array($selected)) {
            throw new \RuntimeException('Choose visual style fields from the displayed list.', 400);
        }
        foreach ($selected ?: [] as $field) {
            if (!is_string($field) || !isset($catalogue[$field]) || isset($pack['styles'][$field])) {
                throw new \RuntimeException('Unknown or duplicated visual style selection.', 400);
            }
            // Read only selected known visual fields; layout/particle content is never copied.
            $pack['styles'][$field] = $this->container['config']->get('styles.' . $field);
        }
        $selections = $this->inputJson('asset_json', 131072);
        if (count($selections) > 8) {
            throw new \RuntimeException('Select at most eight custom bitmaps.', 400);
        }
        foreach ($selections as $id => $selection) {
            if (!is_string($id) || !is_array($selection) || count($selection) !== 3
                || array_diff(array_keys($selection), ['path', 'license', 'notice']) || !isset($selection['path'], $selection['license'], $selection['notice'])) {
                throw new \RuntimeException('Each selected bitmap needs its exact path, full license and notice.', 400);
            }
            $pack['assets'][$id] = $service->asset($selection['path'], $selection['license'], $selection['notice']);
        }
        $service->validate($pack);
        $bytes = DesignPack::encode($pack);
        if (($this->container['admin2.context'] ?? null) instanceof \Gantry\Admin\ApiContext) {
            $response = new HtmlResponse($bytes);
            $response->mimeType = 'application/json';
            $response->setHeader('Content-Disposition', 'attachment; filename="gantry-design-pack.json"');
            $response->setHeader('Cache-Control', 'private, no-store');
            $response->setHeader('X-Content-Type-Options', 'nosniff');
            return $response;
        }
        while (ob_get_level() && @ob_end_clean()) {
        }
        if (headers_sent()) {
            throw new \RuntimeException('Cannot download after output has started.', 500);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="gantry-design-pack.json"');
        header('Cache-Control: private, no-store');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    private function upload()
    {
        $file = $_FILES['designpack'] ?? null;
        if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
            || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) > DesignPack::MAX_JSON_BYTES) {
            throw new \RuntimeException('Choose a bounded Gantry design pack JSON file.', 400);
        }
        return file_get_contents($file['tmp_name'], false, null, 0, DesignPack::MAX_JSON_BYTES + 1);
    }

    public function preview()
    {
        $service = $this->service();
        $this->params['design_preview'] = $service->preview($service->decode($this->upload()));
        return $this->page();
    }

    public function apply()
    {
        $this->changeSave('admin.designpack', fn() => $this->applyNative());
        return $this->page();
    }

    private function applyNative()
    {
        $service = $this->service();
        $bytes = $this->upload();
        $pack = $service->decode($bytes);
        if ($this->request->post['apply_confirm'] !== 'yes' || !is_string($this->request->post['pack_digest'])
            || !hash_equals(hash('sha256', $bytes), $this->request->post['pack_digest'])) {
            throw new \RuntimeException('Preview the same pack and confirm creation of a new unassigned outline.', 400);
        }
        $id = 'design-' . $pack['id'] . '-' . bin2hex(random_bytes(8));
        if (isset($this->container['outlines'][$id])) {
            throw new \RuntimeException('The new outline identity is already in use.', 409);
        }
        $directory = null;
        $createdId = null;
        $assets = ['directory' => null, 'uris' => []];
        try {
            $assets = $service->installAssets($pack);
            $assets['uris'] = [];
            $siteRoot = realpath(GANTRY5_ROOT);
            foreach ($assets['files'] as $assetId => $path) {
                // Theme image streams can point elsewhere (notably Grav image://).
                // Resolve the exact newly written custom file using the existing CMS URL API.
                if (!$siteRoot || strpos($path, $siteRoot . '/') !== 0) {
                    throw new \RuntimeException('Custom bitmaps must be stored below the CMS public root.', 400);
                }
                $relative = substr($path, strlen($siteRoot) + 1);
                $relative = implode('/', array_map('rawurlencode', explode('/', $relative)));
                $document = $this->container['document'];
                $url = $document::url($relative, false, 0);
                if (!is_string($url) || $url === '' || $url[0] !== '/' || strpos($url, '//') === 0) {
                    throw new \RuntimeException('A local bitmap URL could not be resolved.', 400);
                }
                $assets['uris'][$assetId] = $url;
            }
            $layout = LayoutReader::data($service->layout($pack, $assets['uris']));
            $createdId = $this->container['outlines']->create($id, $pack['title'], $layout,
                static function ($actualId) use ($service, &$directory) {
                    $directory = $service->reserveOutline($actualId);
                });
            $styles = new Config([]);
            foreach ($pack['styles'] as $key => $value) {
                $styles->set($key, $value);
            }
            $this->saveNewYaml($directory . '/styles.yaml', $styles->toArray());
            // Retain full attribution and every supplied asset notice with this outline.
            $attribution = array_intersect_key($pack, array_flip(['schema', 'id', 'version', 'title', 'author', 'license', 'notices']));
            foreach ($pack['assets'] as $assetId => $asset) {
                $attribution['assets'][$assetId] = array_intersect_key($asset, array_flip(['name', 'sha256', 'license', 'notice'])) + ['uri' => $assets['uris'][$assetId]];
            }
            $this->saveNewYaml($directory . '/design-pack.yaml', $attribution);
        } catch (\Throwable $error) {
            // Only this request's exclusively reserved directory and new assets are removed.
            try {
                if ($createdId !== null) {
                    $this->container['outlines']->delete($createdId);
                } elseif ($directory !== null) {
                    Folder::delete($directory);
                }
            } finally {
                if ($assets['directory']) {
                    $service->removeNewAssets($assets['directory']);
                }
            }
            throw $error;
        }
        $this->params['design_result'] = ['id' => (string) $createdId, 'title' => $pack['title']];
        return $this->params['design_result'];
    }

    private function saveNewYaml($path, array $data)
    {
        if (file_exists($path) || is_link($path)) {
            throw new \RuntimeException('A new outline destination unexpectedly exists.', 409);
        }
        $file = CompiledYamlFile::instance($path);
        try {
            $file->save($data);
        } finally {
            $file->free();
        }
    }
}
