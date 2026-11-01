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

namespace Gantry\Component\Twig\Node;

use Twig\Attribute\YieldReady;

use Twig\Compiler;
use Twig\Node\CaptureNode;
use Twig\Node\Node;
use Twig\Node\NodeOutputInterface;

/**
 * Class TwigNodeMarkdown
 * @package Gantry\Component\Twig\Node
 */
#[YieldReady]
class TwigNodeMarkdown extends Node implements NodeOutputInterface
{
    /**
     * TwigNodeMarkdown constructor.
     * @param Node $body
     * @param int $lineno
     * @param string $tag
     */
    public function __construct(Node $body, $lineno, $tag = 'markdown')
    {
        $capture = new CaptureNode($body, $lineno);
        $capture->setAttribute('raw', true);
        parent::__construct(['body' => $capture], [], $lineno);
    }
    /**
     * Compiles the node to PHP.
     *
     * @param Compiler $compiler A Twig Compiler instance
     */
    public function compile(Compiler $compiler): void
    {
        $compiler
            ->addDebugInfo($this)
            ->write('$content = ')
            ->subcompile($this->getNode('body'))
            ->raw(PHP_EOL)
            ->write('preg_match("/^\s*/", $content, $matches);' . PHP_EOL)
            ->write('$lines = explode("\n", $content);' . PHP_EOL)
            ->write('$content = preg_replace(\'/^\' . $matches[0]. \'/\', "", $lines);' . PHP_EOL)
            ->write('$content = join("\n", $content);' . PHP_EOL)
            ->write('yield $this->env->getExtension(\'Gantry\Component\Twig\TwigExtension\')->markdownFunction($content);' . PHP_EOL);
    }
}
