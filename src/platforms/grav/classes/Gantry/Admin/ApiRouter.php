<?php
/** Gantry-owned router over a real authenticated Grav2 API request. Matthew C. Murphy, 2026. MIT. */
namespace Gantry\Admin;

use Gantry\Component\Request\Input;
use Gantry\Component\Response\Response;
use Gantry\Component\Router\Router as BaseRouter;
use Gantry\Framework\Gantry;
use Gantry\Framework\Request;
use Grav\Framework\Psr7\Response as NativeResponse;

final class ApiRouter extends BaseRouter
{
    public function __construct(Gantry $gantry, private readonly ApiContext $context)
    {
        parent::__construct($gantry);
    }

    public function boot()
    {
        $parts = $this->context->path === '' ? [] : explode('/', $this->context->path);
        $theme = $this->container['theme'];
        if (($parts[0] ?? null) === 'themes' && isset($parts[1])) {
            if ($parts[1] !== $theme->name) {
                throw new \RuntimeException('Open the active Gantry theme in this native admin page.', 404);
            }
            $parts = array_slice($parts, 2);
        }
        if (!$parts) {
            $parts = $this->context->settings ? ['configurations', 'default', 'layout'] : ['client'];
        }
        $last = count($parts) - 1;
        $this->format = str_ends_with($parts[$last], '.json') ? 'json' : 'html';
        if ($this->format === 'json') { $parts[$last] = substr($parts[$last], 0, -5); }
        $this->resource = array_shift($parts);
        $this->path = $parts;
        $this->method = $this->context->method;
        $this->params = ['user' => $this->context->user, 'ajax' => $this->format === 'json',
            'location' => $this->resource, 'method' => $this->method, 'format' => $this->format,
            'params' => []];

        // Real native parsed inputs, adapted into Gantry's own input abstraction.
        // Actual multipart uploads remain PHP's genuine $_FILES, never synthesized.
        $query = $this->context->query;
        $fields = $this->context->fields;
        $server = $_SERVER;
        $server['REQUEST_METHOD'] = $this->method;
        unset($server['X-HTTP-METHOD-OVERRIDE']);
        $all = $query + $fields;
        $request = new Request();
        $request->get = new Input($query);
        $request->post = new Input($fields);
        $request->server = new Input($server);
        $request->request = new Input($all);
        $this->params['params'] = $request->post->getJsonArray('params');
        unset($this->container['request']);
        $this->container['request'] = $request;
        $this->container['base_url'] = $this->context->baseUrl;
        $this->container['ajax_suffix'] = '.json';
        $this->container['ajax_nonce'] = '';
        $this->container['routes'] = ['1' => '/%s', 'themes' => '/themes', 'picker/layouts' => '/layouts'];
        return $this;
    }

    protected function checkSecurityToken()
    {
        // API middleware has authenticated this exact native request and enforced
        // its session-cookie same-origin guard (or a header-bound JWT/API key).
        return $this->container['admin2.context'] === $this->context;
    }

    protected function send(Response $response)
    {
        $headers = $response->getHeaders();
        $hasType = false;
        foreach (array_keys($headers) as $name) { $hasType = $hasType || strtolower($name) === 'content-type'; }
        if (!$hasType) { $headers['Content-Type'] = $response->mimeType . '; charset=' . $response->charset; }
        return new NativeResponse($response->getStatusCode(), $headers, (string) $response);
    }
}
