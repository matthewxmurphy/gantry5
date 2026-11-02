<?php

/**
 * @package   Gantry5
 * @author    Tiger12 http://tiger12.com
 * @originalCreator  RocketTheme (Gantry Framework) 
 * @currentDeveloper  Tiger12, LLC 
 * @copyright Copyright (C) 2007 - 2022 Tiger12, LLC
 * @license   Dual License: MIT or GNU/GPLv2 and later
 *
 * http://opensource.org/licenses/MIT
 * http://www.gnu.org/licenses/gpl-2.0.html
 *
 * Gantry Framework code that extends GPL code is considered GNU/GPLv2 and later
 */

namespace Gantry\Component\File;

use RocketTheme\Toolbox\File\YamlFile;
use Gantry\Component\Render\BundleRuntime;

/**
 * Class CompiledYamlFile
 * @package Gantry\Component\File
 */
class CompiledYamlFile extends YamlFile
{
    use CompiledFile { content as private sourceContent; }

    static public $defaultCachePath;
    static public $defaultCaching = true;

    protected function __construct()
    {
        parent::__construct();

        $this->caching(static::$defaultCaching);

        if (static::$defaultCachePath) {
            $this->setCachePath(static::$defaultCachePath);
        }
    }

    /** The frontend consumes frozen PHP values even if this object cached old source. */
    public function content($var = null)
    {
        if ($runtime = BundleRuntime::current()) {
            if ($var !== null) { BundleRuntime::fail('Production YAML values are immutable.'); }
            return $runtime->yaml((string) $this->filename);
        }
        if (!BundleRuntime::isSource()) { BundleRuntime::fail('Frozen YAML values are unavailable.'); }
        return $this->sourceContent($var);
    }

    public function raw($var = null)
    {
        if (!BundleRuntime::isSource()) { BundleRuntime::fail('Production rendering cannot read or replace YAML source.'); }
        return parent::raw($var);
    }

    public function save($data = null)
    {
        if (!BundleRuntime::isSource()) { BundleRuntime::fail('Production rendering cannot write YAML source.'); }
        return parent::save($data);
    }
}
