<?php
/**
 * Selected-outline upgrade assistance; existing platform admin/nonce routes only.
 * Copyright (c) 2026 Matthew C. Murphy, https://MatthewxMurphy.com.
 * @license MIT; existing Gantry and third-party licenses remain unchanged.
 */
namespace Gantry\Admin\Controller\Html\Configurations;

use Gantry\Component\Admin\HtmlController;
use Gantry\Component\Filesystem\Folder;
use Gantry\Component\Layout\Layout;
use Gantry\Component\Response\HtmlResponse;
use Gantry\Component\Upgrade\OutlineSnapshot;

class Upgrade extends HtmlController
{
    protected $httpVerbs = [
        'GET' => ['/' => 'index'],
        'POST' => ['/download' => 'download', '/preview' => 'preview', '/restore' => 'restore']
    ];

    private function service($allowMissing = false)
    {
        if (!$this->authorize('platform.settings.manage')) {
            $this->forbidden();
        }
        $outline = $this->params['outline'];
        if (!is_string($outline) || !isset($this->container['outlines'][$outline])) {
            throw new \RuntimeException('Outline not found.', 404);
        }
        $directory = $this->container['locator']->findResource("gantry-config://{$outline}", true, true);
        if ($allowMissing && is_string($directory) && !file_exists($directory) && !is_link($directory)) {
            $parent = dirname($directory);
            while (!file_exists($parent) && !is_link($parent) && dirname($parent) !== $parent) {
                $parent = dirname($parent);
            }
            if (is_link($parent) || !is_dir($parent) || !is_readable($parent)) {
                throw new \RuntimeException('The outline configuration location is inaccessible or linked.', 400);
            }
            return null;
        }
        return new OutlineSnapshot($directory, [
            'cms' => $this->container['platform']->getName(),
            'theme' => $this->container['theme']->name,
            'outline' => $outline
        ]);
    }

    public function index()
    {
        $service = $this->service(true);
        $outline = $this->params['outline'];
        $theme = $this->container['theme'];
        $platform = $this->container['platform'];
        $manifest = dirname((new \ReflectionClass(\Gantry5\Loader::class))->getFileName(), 2) . '/composer.json';
        $metadata = is_file($manifest) ? json_decode(file_get_contents($manifest), true) : [];
        $constraint = $metadata['require']['php'] ?? '';
        $phpSupported = preg_match('/\A>=([0-9.]+)\s+<([0-9.]+)\z/', $constraint, $bounds)
            ? version_compare(PHP_VERSION, $bounds[1], '>=') && version_compare(PHP_VERSION, $bounds[2], '<') : null;
        // Do not use instance(): it may persist a missing layout/index while loading.
        $index = $service ? Layout::loadIndex($outline, false) : [];
        $inspected = isset($index['particles']) && is_array($index['particles']);
        $used = $inspected ? array_keys($index['particles']) : [];
        $available = array_keys($this->container['particles']->all());
        $dependencies = $theme->details()->get('configuration.dependencies', []);
        $themeSupported = is_array($dependencies) ? $platform->checkDependencies($dependencies) : false;
        if (is_array($dependencies) && isset($dependencies['gantry'])) {
            $minimum = $dependencies['gantry'];
            $themeSupported = $themeSupported && is_string($minimum) && preg_match('/\A\d+(?:\.\d+){0,3}\z/D', $minimum)
                && version_compare(GANTRY5_VERSION, $minimum, '>=');
        }
        $this->params['upgrade'] = [
            'php' => PHP_VERSION, 'php_constraint' => $constraint, 'php_supported' => $phpSupported,
            'cms' => $platform->getName(), 'cms_version' => $platform->getVersion(),
            'gantry' => GANTRY5_VERSION, 'theme' => $theme->name, 'theme_version' => $theme->version,
            'theme_supported' => (bool) $themeSupported,
            'particles_inspected' => $inspected,
            'missing_particles' => array_values(array_diff($used, $available)),
            'has_overrides' => $service !== null,
            'snapshot_files' => $service ? count($service->preview($service->create())['files']) : 0
        ];
        return $this->page();
    }

    private function page()
    {
        $this->params['snapshot_limit'] = OutlineSnapshot::MAX_JSON_BYTES;
        $response = new HtmlResponse($this->render('@gantry-admin/pages/configurations/upgrade.html.twig', $this->params));
        $response->setHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    public function download()
    {
        $service = $this->service();
        if ($this->request->post['private_ack'] !== 'yes') {
            throw new \RuntimeException('Acknowledge the private contents before downloading.', 400);
        }
        $bytes = $service->create();
        if (($this->container['admin2.context'] ?? null) instanceof \Gantry\Admin\ApiContext) {
            $response = new HtmlResponse($bytes);
            $response->mimeType = 'application/json';
            $response->setHeader('Content-Disposition', 'attachment; filename="gantry-outline-snapshot.json"');
            $response->setHeader('Cache-Control', 'private, no-store');
            $response->setHeader('X-Content-Type-Options', 'nosniff');
            return $response;
        }
        // As with Gantry's existing download controller, terminate before CMS admin chrome.
        while (ob_get_level() && @ob_end_clean()) {
        }
        if (headers_sent()) {
            throw new \RuntimeException('Cannot send a private snapshot after output has started.', 500);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="gantry-outline-snapshot.json"');
        header('Cache-Control: private, no-store');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    private function upload()
    {
        $file = $_FILES['snapshot'] ?? null;
        if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK
            || !is_string($file['tmp_name'] ?? null) || !is_uploaded_file($file['tmp_name'])
            || filesize($file['tmp_name']) > OutlineSnapshot::MAX_JSON_BYTES) {
            throw new \RuntimeException('Choose a bounded Gantry outline snapshot file.', 400);
        }
        $bytes = file_get_contents($file['tmp_name'], false, null, 0, OutlineSnapshot::MAX_JSON_BYTES + 1);
        if (!is_string($bytes) || strlen($bytes) > OutlineSnapshot::MAX_JSON_BYTES) {
            throw new \RuntimeException('Cannot read the bounded snapshot upload.', 400);
        }
        return $bytes;
    }

    public function preview()
    {
        $service = $this->service();
        $this->params['restore_preview'] = $service->preview($this->upload());
        return $this->page();
    }

    public function restore()
    {
        $service = $this->service();
        $bytes = $this->upload();
        $change = $this->changeSave('admin.restore', function () use ($service, $bytes) {
            $result = $service->restore($bytes, $this->request->post['revision'],
                $this->request->post['snapshot_digest'], $this->request->post['restore_confirm'] === 'yes');
            (new \Gantry\Component\Changes\NativeWriter($this->container))->invalidateAll();
            return $result;
        });
        $this->params['restore_result'] = $change['result'];
        return $this->page();
    }
}
