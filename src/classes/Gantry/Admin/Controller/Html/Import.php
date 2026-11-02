<?php
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

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

namespace Gantry\Admin\Controller\Html;

use Gantry\Component\Admin\HtmlController;
use Gantry\Component\Filesystem\Folder;
use Gantry\Framework\Importer;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use Symfony\Component\Yaml\Yaml;

/**
 * Class Import
 * @package Gantry\Admin\Controller\Html
 */
class Import extends HtmlController
{
    protected $httpVerbs = [
        'GET' => [
            '/'                 => 'index',
        ],
        'POST' => [
            '/'                 => 'import',
        ]
    ];

    /**
     * @return string
     */
    public function index()
    {
        return $this->render('@gantry-admin/pages/import/import.html.twig', $this->params);
    }

    /**
     * @return string
     */
    public function import()
    {
        throw new \RuntimeException('Legacy whole-theme ZIP import is unavailable in Gantry 6. Use reviewed Design packs or a private outline snapshot.', 409);
    }
}
