<?php
/**
 * Copyright (c) 2026 Matthew X Murphy
 * https://MatthewxMurphy.com | https://builtnotbegged.com
 * SPDX-License-Identifier: GPL-2.0-or-later
 */
declare(strict_types=1);

namespace Gantry\Component\Gantry\Api\Controller;

use Gantry\Admin\Router;
use Gantry\Component\Api\ApiException;
use Gantry\Component\Api\ThemeApi;
use Gantry\Component\Gantry\Api\Serializer\ResourceSerializer;
use Gantry\Framework\Gantry;
use Gantry5\Loader;
use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Document\JsonapiDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\ApiController;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Tobscure\JsonApi\Collection;
use Tobscure\JsonApi\Resource;

defined('_JEXEC') or die;

/**
 * Joomla core-token API adapter. Authentication belongs exclusively to Joomla;
 * this controller rechecks Gantry's native administrator ACL before any data
 * lookup, bootstrap or mutation. Native ThemeApi owns validation and persistence.
 */
final class ThemeController extends ApiController
{
    private const MAX_BODY_BYTES = 1048576;

    public function listOutlines(): static
    {
        return $this->operation('gantry-outlines', static fn (ThemeApi $api): array => $api->listOutlines(), collection: true);
    }

    public function getOutline(): static
    {
        return $this->operation('gantry-outlines', fn (ThemeApi $api): array => $api->getOutline($this->outline()));
    }

    public function createOutline(): static
    {
        return $this->operation('gantry-outlines', fn (ThemeApi $api): array => $api->createOutline($this->body()), 201);
    }

    public function updateOutline(): static
    {
        return $this->operation('gantry-outlines', fn (ThemeApi $api): array => $api->updateOutline($this->outline(), $this->body()));
    }

    public function deleteOutline(): static
    {
        return $this->operation('gantry-outlines', fn (ThemeApi $api): array => $api->deleteOutline($this->outline(), $this->body()), 204);
    }

    public function getLayout(): static
    {
        return $this->operation('gantry-layouts', fn (ThemeApi $api): array => $api->getLayout($this->outline()));
    }

    public function updateLayout(): static
    {
        return $this->operation('gantry-layouts', fn (ThemeApi $api): array => $api->updateLayout($this->outline(), $this->body()));
    }

    public function getSettings(): static
    {
        return $this->operation('gantry-settings', fn (ThemeApi $api): array => $api->getSettings($this->outline()));
    }

    public function updateSettings(): static
    {
        return $this->operation('gantry-settings', fn (ThemeApi $api): array => $api->updateSettings($this->outline(), $this->body()));
    }

    private function operation(string $type, callable $callback, int $status = 200, bool $collection = false): static
    {
        $identity = $this->app->getIdentity();
        if (!$identity || $identity->guest || (
            !$identity->authorise('core.admin', 'com_gantry5')
            && !$identity->authorise('core.admin', 'com_templates')
        )) {
            throw new NotAllowed('JERROR_ALERTNOAUTHOR', 403);
        }

        $document = $this->app->getDocument();
        if (!$document instanceof JsonapiDocument) {
            throw new \RuntimeException('Gantry requires the Joomla JSON:API response format.', 406);
        }

        $this->app->setHeader('Cache-Control', 'no-store', true);

        try {
            $data = $callback($this->service());
            $serializer = new ResourceSerializer($type);
            if ($status === 204) {
                // Match Joomla ApiController::delete: no resource after delete.
            } elseif ($collection) {
                $items = array_map(static fn (array $item): object => (object) $item, $data['items']);
                $document->setData(new Collection($items, $serializer));
            } else {
                $document->setData(new Resource((object) $data, $serializer));
            }
            $this->app->setHeader('status', $status, true);
        } catch (ApiException $error) {
            $code = (int) $error->getCode();
            if (!in_array($code, [400, 404, 409, 413, 415, 422, 500, 503], true)) {
                $code = 500;
            }
            if ($code >= 500) {
                $this->logFailure($error);
            }
            $document->setErrors([[
                'status' => (string) $code,
                'code' => preg_match('/\A[a-z][a-z0-9_]{0,79}\z/D', $error->getErrorCode())
                    ? $error->getErrorCode() : 'gantry_api_failed',
                'title' => 'Gantry API request could not be completed.',
            ]]);
            $this->app->setHeader('status', $code, true);
        } catch (\Throwable $error) {
            // Never publish native exception messages, paths or request data.
            $this->logFailure($error);
            $document->setErrors([[
                'status' => '500',
                'code' => 'gantry_api_failed',
                'title' => 'Gantry API request could not be completed.',
            ]]);
            $this->app->setHeader('status', 500, true);
        }

        return $this;
    }

    /** Log only private source metadata, including wrapped native failures. */
    private function logFailure(\Throwable $error): void
    {
        for ($depth = 0; $depth < 16 && $error->getPrevious() !== null; ++$depth) {
            $error = $error->getPrevious();
        }
        Log::addLogger(['text_file' => 'gantry-api-error.php'], Log::ERROR, ['gantry-api']);
        Log::add(sprintf(
            'Gantry API internal failure: %s; code=%d; file=%s; line=%d',
            get_class($error),
            (int) $error->getCode(),
            $error->getFile(),
            $error->getLine()
        ), Log::ERROR, 'gantry-api');
    }

    private function outline(): string
    {
        $id = $this->routeIdentifier('outline');
        if (!preg_match('/\A[A-Za-z0-9_-]{1,80}\z/D', $id)) {
            throw new ApiException('The outline identifier is invalid.', 400, 'invalid_outline');
        }

        return $id;
    }

    /** Read the matched path variable where Joomla's API router placed it. */
    private function routeIdentifier(string $name): string
    {
        // ApiApplication::route stores POST path variables in post; every other
        // method uses global input. Do not fall back to query or JSON body data.
        $input = $this->input->getMethod() === 'POST' ? $this->input->post : $this->input;

        return $input->getString($name, '');
    }

    private function body(): array
    {
        $type = strtolower(trim(explode(';', $this->input->server->getString('CONTENT_TYPE', ''))[0]));
        if (!in_array($type, ['application/json', 'application/vnd.api+json'], true)) {
            throw new ApiException('Send a JSON object using application/json.', 415, 'unsupported_media_type');
        }
        if ($this->input->server->getInt('CONTENT_LENGTH') > self::MAX_BODY_BYTES) {
            throw new ApiException('The request body exceeds the Gantry API limit.', 413, 'request_too_large');
        }

        $raw = $this->input->json->getRaw();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new ApiException('The request body exceeds the Gantry API limit.', 413, 'request_too_large');
        }
        try {
            $value = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
            if (!$value instanceof \stdClass) {
                throw new \JsonException('Object required');
            }

            return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new ApiException('Send a valid JSON object.', 400, 'invalid_json_object');
        }
    }

    private function service(): ThemeApi
    {
        require_once JPATH_LIBRARIES . '/gantry5/src/Loader.php';
        Loader::setup();

        if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) {
            throw new ApiException('Gantry 6 API requires PHP 8.5.', 503, 'php_85_required');
        }
        $theme = $this->routeIdentifier('theme');
        if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $theme)) {
            throw new ApiException('The theme identifier is invalid.', 400, 'invalid_theme');
        }

        $base = realpath(JPATH_SITE . '/templates');
        $path = realpath(JPATH_SITE . '/templates/' . $theme);
        if (!$base || !$path || dirname($path) !== $base || !is_file($path . '/gantry/theme.yaml')) {
            throw new ApiException('The selected Gantry theme was not found.', 404, 'theme_not_found');
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('template'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('element') . ' = :theme')
            ->bind(':theme', $theme, ParameterType::STRING);
        if (!$db->setQuery($query)->loadResult()) {
            throw new ApiException('The selected Gantry theme was not found.', 404, 'theme_not_found');
        }

        $gantry = Gantry::instance();
        $gantry['theme.name'] = $theme;
        $gantry['theme.path'] = $path;
        if (!defined('GANTRYADMIN_PATH')) {
            define('GANTRYADMIN_PATH', JPATH_ADMINISTRATOR . '/components/com_gantry5');
        }

        // load() uses the same native theme include and admin services as the
        // clicked editor. boot() is deliberately not called: it adds browser
        // keepalive/session/form-token behavior that does not belong in an API.
        (new Router($gantry))->load();

        return new ThemeApi($gantry);
    }
}
