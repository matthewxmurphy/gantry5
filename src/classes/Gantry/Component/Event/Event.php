<?php
/**
 * Gantry event payload on the maintained Symfony Contracts API.
 * Array payload behavior follows RocketTheme Toolbox's original MIT Event adapter.
 * Original adapter author: RocketTheme. New integration copyright (c) 2026
 * Matthew C. Murphy, https://MatthewxMurphy.com. MIT.
 * Existing Toolbox traits and Symfony retain their distributed licenses.
 */
namespace Gantry\Component\Event;

use RocketTheme\Toolbox\ArrayTraits\ArrayAccess;
use RocketTheme\Toolbox\ArrayTraits\Constructor;
use RocketTheme\Toolbox\ArrayTraits\Export;
use Symfony\Contracts\EventDispatcher\Event as BaseEvent;

class Event extends BaseEvent implements \ArrayAccess
{
    use ArrayAccess, Constructor, Export;

    protected $items = [];
}
