<?php
/** Native Grav2 API authority and request context. Copyright 2026 Matthew C. Murphy. MIT. */
namespace Gantry\Admin;

use Grav\Common\User\Interfaces\UserInterface;
use Grav\Plugin\Api\Exceptions\ForbiddenException;
use Psr\Http\Message\ServerRequestInterface;

final class ApiContext
{
    public function __construct(
        public readonly UserInterface $user,
        public readonly ServerRequestInterface $nativeRequest,
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $fields,
        public readonly bool $settings,
        public readonly bool $pages,
        public readonly bool $packages,
        public readonly string $baseUrl,
    ) {
        if (!$user->authenticated || !$user->get('authorized', true)) {
            throw new ForbiddenException('Complete native authentication before opening Gantry.');
        }
    }

    public function authorize($action): bool
    {
        switch ($action) {
            case 'platform.settings.manage':
            case 'outline.create':
            case 'outline.rename':
            case 'outline.delete':
            case 'outline.assign':
                return $this->settings;
            case 'menu.manage':
            case 'menu.edit':
                return $this->settings && $this->pages;
            case 'updates.manage':
                return $this->settings && $this->packages;
            default:
                return false;
        }
    }
}
