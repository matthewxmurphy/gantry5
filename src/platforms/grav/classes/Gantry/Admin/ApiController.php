<?php
/** Authenticated Admin2 transport for existing Gantry controllers. Matthew C. Murphy, 2026. MIT. */
namespace Gantry\Admin;

use Gantry\Component\ClientEditing\Access;
use Gantry\Framework\Gantry;
use Gantry5\Loader;
use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Grav\Plugin\Api\Exceptions\ValidationException;
use Grav\Plugin\Api\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class ApiController extends AbstractApiController
{
    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.access');
        $native = $this->getRequestBody($request);
        if (isset($native['_gantry_request'])) {
            if (!is_string($native['_gantry_request']) || strlen($native['_gantry_request']) > 65536) {
                throw new ValidationException('Use a bounded Gantry request descriptor.');
            }
            try { $descriptor = json_decode($native['_gantry_request'], true, 16, JSON_THROW_ON_ERROR); }
            catch (\JsonException $error) { throw new ValidationException('Use a valid Gantry request descriptor.'); }
            $fields = $native;
            unset($fields['_gantry_request']);
        } else {
            $descriptor = $native;
            if (!is_string($descriptor['fields'] ?? '') || strlen($descriptor['fields'] ?? '') > 2 * 1024 * 1024) {
                throw new ValidationException('Use bounded form fields.');
            }
            parse_str($descriptor['fields'] ?? '', $fields);
        }
        if (!is_array($descriptor) || array_diff(array_keys($descriptor), ['method', 'path', 'query', 'fields'])) {
            throw new ValidationException('Use the supported Gantry request descriptor.');
        }
        $method = $descriptor['method'] ?? null;
        $path = $descriptor['path'] ?? null;
        $query = $descriptor['query'] ?? [];
        if (!is_string($method) || !in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
            || !is_string($path) || strlen($path) > 2048 || !is_array($query)
            || strlen(json_encode($query)) > 65536 || !mb_check_encoding($path, 'UTF-8')) {
            throw new ValidationException('Use a valid bounded method, path and query.');
        }
        $path = trim(rawurldecode($path), '/');
        if (!mb_check_encoding($path, 'UTF-8') || preg_match('~[\\\\\x00-\x20?#:]~', $path) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)
            || str_contains($path, '//')) {
            throw new ValidationException('Use a Gantry controller path.');
        }
        // Preserve the existing named METHOD override, but never smuggle a write
        // through a logically read-only request.
        if ($method === 'POST' && isset($fields['METHOD'])) {
            if (!is_string($fields['METHOD']) || !in_array($fields['METHOD'], ['PUT', 'PATCH', 'DELETE'], true)) {
                throw new ValidationException('Use a supported form method.');
            }
            $method = $fields['METHOD'];
        }
        $actor = $this->getUser($request);
        $settings = $this->nativePermission($request, 'api.config.themes');
        $pages = $this->nativePermission($request, 'api.pages.update');
        $packages = $this->nativePermission($request, 'api.gpm.write');
        $base = rtrim((string) $this->grav['uri']->rootUrl(false), '/')
            . '/' . trim((string) $this->config->get('plugins.api.route', '/api'), '/')
            . '/' . trim((string) $this->config->get('plugins.api.version_prefix', 'v1'), '/') . '/gantry/view';
        $context = new ApiContext($actor, $request, $method, $path, $query, $fields, $settings, $pages, $packages, $base);
        Loader::setup();
        $gantry = Gantry::instance();
        $gantry['admin2.context'] = $context;
        if (!defined('GANTRYADMIN_PATH')) { define('GANTRYADMIN_PATH', GANTRY5_LIBRARY . '/admin'); }
        // Use the real active theme and its normal streams. No host Admin instance,
        // alternate user, host-vendor mutation or token-bearing URL is introduced.
        $plugin = $this->grav['gantry5_plugin'];
        $plugin->initializeNativeApiTheme();
        if (!isset($gantry['theme'])) { throw new ValidationException('Activate a Gantry theme before opening its editor.'); }
        if (!$settings && !Access::mayEnter($gantry)) {
            throw new ForbiddenException('A settings administrator must explicitly approve client text editing.');
        }
        Access::restrictNative($gantry);
        $router = new ApiRouter($gantry, $context);
        $gantry['router'] = $router;
        $response = $router->dispatch();
        $headers = [];
        foreach (['Content-Type', 'Content-Disposition', 'Cache-Control', 'Pragma', 'Expires', 'X-Content-Type-Options', 'Location'] as $header) {
            if ($response->hasHeader($header)) { $headers[$header] = $response->getHeaderLine($header); }
        }
        $bytes = (string) $response->getBody();
        $attachment = $response->hasHeader('Content-Disposition');
        return ApiResponse::create(['status' => $response->getStatusCode(), 'headers' => $headers,
            'body' => $attachment ? base64_encode($bytes) : $bytes,
            'encoding' => $attachment ? 'base64' : 'utf8', 'base_url' => $base,
            'asset_base' => rtrim((string) $this->grav['uri']->rootUrl(false), '/') . '/',
            'bridge_url' => \Gantry\Framework\Document::url('plugin://gantry5/admin-next/gantry-frame.js', false, 0, false)]);
    }

    private function nativePermission(ServerRequestInterface $request, string $permission): bool
    {
        try { $this->requirePermission($request, $permission); return true; }
        catch (ForbiddenException $error) { return false; }
    }
}
