<?php
/** Client-mode admission; no capability or user changes. Matthew C. Murphy, 2026. MIT. */
namespace Gantry\Component\ClientEditing;

use Gantry\Component\Response\HtmlResponse;
use Gantry\Component\Response\JsonResponse;

class Access
{
    /** Absence must not require a catalogue, supported policy filesystem or ready theme services. */
    public static function policyExists($gantry)
    {
        try {
            $root = $gantry['locator']->findResource('gantry-theme://config', true, true);
            if (!is_string($root) || $root === '') { return false; }
            $path = rtrim($root, '/\\') . '/client-editing.yaml';
            return file_exists($path) || is_link($path);
        } catch (\Throwable $error) { return false; }
    }

    public static function store($gantry)
    {
        $locator = $gantry['locator'];
        $outlines = [];
        foreach ($gantry['outlines'] as $id => $title) { $outlines[] = (string) $id; }
        $safe = true;
        $definitions = [];
        foreach (['yaml', 'html.twig'] as $extension) {
            $actual = $locator->findResource('gantry-particles://client-text.' . $extension);
            $canonical = $locator->findResource('gantry-engines://nucleus/particles/client-text.' . $extension);
            $safe = $safe && is_string($actual) && is_string($canonical) && is_file($actual)
                && realpath($actual) === realpath($canonical);
            $definitions[$extension] = is_string($canonical) && is_file($canonical) ? hash_file('sha256', $canonical) : null;
        }
        $definitions['escape'] = hash_file('sha256', __DIR__ . '/SafeText.php');
        return new PolicyStore($locator->findResource('gantry-theme://config', true, true), [
            'cms' => $gantry['platform']->getName(), 'theme' => $gantry['theme']->name,
            'definition' => hash('sha256', json_encode($definitions))
        ], $outlines, $safe);
    }

    public static function mayEnter($gantry)
    {
        if (!self::policyExists($gantry)) { return false; }
        try {
            return ($gantry['platform']->isSettingsAdministrator() || $gantry['platform']->canEditClientContent())
                && self::store($gantry)->state()['enabled'];
        } catch (\Throwable $error) {
            return false;
        }
    }

    public static function clientView($view)
    {
        return is_string($view) && in_array(trim($view, '/'), ['', 'client', 'client/save'], true);
    }

    public static function restrictNative($gantry)
    {
        if (empty($gantry['client.restricted_entry']) && !self::policyExists($gantry)) { return; }
        if ($gantry['platform']->isSettingsAdministrator()) { return; }
        try { $restricted = self::store($gantry)->state()['enabled']; }
        catch (\Throwable $error) { $restricted = true; }
        if ($restricted && empty($gantry['client.restricted_entry'])) {
            $gantry['client.restricted_entry'] = true;
            try { $scope = self::contextRevision($gantry); } catch (\Throwable $error) { $scope = null; }
            $gantry['client.admission_scope'] = $scope;
        }
    }

    private static function contextRevision($gantry)
    {
        return hash('sha256', json_encode([$gantry['platform']->getName(), $gantry['theme']->name,
            $gantry['locator']->findResource('gantry-theme://config', true, true)]));
    }

    /** Null preserves existing default-off/native behaviour; a response denies dispatch. */
    public static function guard($gantry, $resource, $method, array $path, $format)
    {
        $restricted = !empty($gantry['client.restricted_entry']);
        if (!$restricted && !self::policyExists($gantry)) { return null; }
        if ($gantry['platform']->isSettingsAdministrator()) { return null; }
        $valid = true;
        try {
            $enabled = self::store($gantry)->state()['enabled'];
        } catch (\Throwable $error) {
            $enabled = true; // Corrupt existing policy never falls back to unrestricted legacy access.
            $valid = false;
        }
        if (!$restricted && !$enabled) { return null; }
        if ($restricted) {
            try {
                $bound = $gantry['client.admission_scope'] ?? null;
                $valid = $valid && is_string($bound) && hash_equals($bound, self::contextRevision($gantry));
            } catch (\Throwable $error) { $valid = false; }
        }
        $allowed = $valid && $enabled && $gantry['platform']->canEditClientContent() && $resource === 'client'
            && (($method === 'GET' && !$path) || ($method === 'POST' && $path === ['save']));
        if ($allowed) { return null; }
        $message = 'Client editing permits only explicitly approved text. Ask a settings administrator for other changes.';
        if ($format === 'json') {
            $response = new JsonResponse('', 403);
            $response->setContent(['message' => $message], false);
        } else {
            $response = new HtmlResponse('<p>' . $message . '</p>', 403);
        }
        $response->setHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    /** Restricted errors never render legacy navigation or unapproved configuration metadata. */
    public static function errorResponse($gantry, \Throwable $error, $json)
    {
        $restricted = !empty($gantry['client.restricted_entry']);
        if (!$restricted && !self::policyExists($gantry)) { return null; }
        try {
            if ($gantry['platform']->isSettingsAdministrator()) { return null; }
            $restricted = $restricted || self::store($gantry)->state()['enabled'];
        } catch (\Throwable $ignored) {
            // A known expanded entry is always sanitized, including partially booted services.
            $restricted = true;
        }
        if (!$restricted) { return null; }
        $code = in_array($error->getCode(), [400, 403, 404, 405, 409], true) ? $error->getCode() : 500;
        $message = $code === 409 ? 'Policy or layout changed. Reload before editing.'
            : ($code === 400 ? 'Use valid bounded plain text and the current approved form.'
                : 'The change could not be confirmed. Reload or ask a settings administrator.');
        if ($json) {
            $response = new JsonResponse('', $code);
            $response->setContent(['message' => $message], false);
        } else {
            $response = new HtmlResponse('<h2>Approved text: ' . $code . '</h2><p>' . $message . '</p>', $code);
        }
        $response->setHeader('Cache-Control', 'private, no-store');
        return $response;
    }
}
