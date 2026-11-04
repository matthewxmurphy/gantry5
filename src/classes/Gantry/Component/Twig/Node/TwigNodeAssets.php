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
use Twig\Error\SyntaxError;
use Twig\Node\Expression\AbstractExpression;
use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Expression\Variable\LocalVariable;
use Twig\Node\CaptureNode;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\NodeCaptureInterface;

/**
 * Class TwigNodeAssets
 * @package Gantry\Component\Twig\Node
 */
#[YieldReady]
class TwigNodeAssets extends Node implements NodeCaptureInterface
{
    protected $tagName = 'assets';

    /**
     * TwigNodeAssets constructor.
     * @param Node|null $body
     * @param AbstractExpression|null $location
     * @param AbstractExpression|null $variables
     * @param int $lineno
     * @param string|null $tag
     */
    public function __construct(?Node $body = null, ?AbstractExpression $location = null, ?AbstractExpression $variables = null, $lineno = 0, $tag = null)
    {
        $capture = new CaptureNode($body ?: new Node(), $lineno);
        $capture->setAttribute('raw', true);
        parent::__construct(['body' => $capture, 'location' => $location, 'variables' => $variables], [], $lineno);
    }
    /**
     * Compiles the node to PHP.
     *
     * @param Compiler $compiler A Twig Compiler instance
     */
    public function compile(Compiler $compiler): void
    {
        $function = $compiler->getEnvironment()->getFunction('parse_assets');
        if ($function === null) {
            throw new SyntaxError('The parse_assets function must be registered.', $this->getTemplateLine(), $this->getSourceContext());
        }
        // Native callable compilation preserves the effective extension, runtime
        // or custom callback and its options, without a forced runtime lookup.
        $call = new FunctionExpression($function, new Nodes([
            new LocalVariable('content', $this->getTemplateLine()),
            new LocalVariable('location', $this->getTemplateLine()),
            new LocalVariable('priority', $this->getTemplateLine()),
        ]), $this->getTemplateLine());
        $compiler->addDebugInfo($this)
            ->write('$assetVariables = ')
            ->subcompile($this->getNode('variables'))
            ->raw(";\n")
            ->write("if (\$assetVariables && !is_array(\$assetVariables)) {\n")
            ->indent()
            ->write("throw new UnexpectedValueException('{% {$this->tagName} with x %}: x is not an array');\n")
            ->outdent()
            ->write("}\n")
            ->write('$location = ')
            ->subcompile($this->getNode('location'))
            ->raw(";\n")
            ->write("if (\$location && !is_string(\$location)) {\n")
            ->indent()
            ->write("throw new UnexpectedValueException('{% {$this->tagName} in x %}: x is not a string');\n")
            ->outdent()
            ->write("}\n")
            ->write("\$priority = isset(\$assetVariables['priority']) ? \$assetVariables['priority'] : 0;\n")
            ->write('$content = ')
            ->subcompile($this->getNode('body'))
            ->raw("\n")
            ->write('')
            ->subcompile($call)
            ->raw(";\n");
    }
}
