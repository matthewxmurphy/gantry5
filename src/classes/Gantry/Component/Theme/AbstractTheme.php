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

namespace Gantry\Component\Theme;

use Gantry\Component\Config\Config;
use Gantry\Component\File\CompiledYamlFile;
use Gantry\Component\Filesystem\Folder;
use Gantry\Component\Gantry\GantryTrait;
use Gantry\Component\Render\BundleRuntime;
use Gantry\Component\Twig\CompiledEnvironment;
use Gantry\Component\Twig\CompiledLoader;
use Gantry\Component\Twig\GenerationFilesystemLoader;
use Gantry\Component\Twig\ImmutableCache;
use Gantry\Component\Twig\ThemeRuntime;
use Gantry\Component\Twig\TwigManifest;
use Gantry\Component\Twig\TwigBundle;
use Gantry\Component\Twig\TwigCacheFilesystem;
use Gantry\Component\Twig\TwigExtension;
use Gantry\Framework\Gantry;
use Gantry\Framework\Platform;
use Gantry\Framework\Services\ErrorServiceProvider;
use RocketTheme\Toolbox\ResourceLocator\UniformResourceLocator;
use Twig\Cache\FilesystemCache;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Extension\DebugExtension;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\Loader\LoaderInterface;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Twig\TwigFilter;

/**
 * Class AbstractTheme
 * @package Gantry\Component
 *
 * @property string $path
 * @property string $layout
 */
abstract class AbstractTheme
{
    use GantryTrait;

    /** @var string */
    public $name;
    /** @var string */
    public $path;

    /** @var Environment|null */
    protected $renderer;

    /**
     * Construct theme object.
     *
     * @param string $path
     * @param string $name
     */
    public function __construct($path, $name = null)
    {
        if (!is_dir($path)) {
            throw new \LogicException('Theme not found!');
        }

        $this->name = $name ?: Gantry::basename($path);
        $this->path = $path;

        $this->init();
    }

    /**
     * Get context for render().
     *
     * @param array $context
     * @return array
     */
    public function getContext(array $context)
    {
        $context['theme'] = $this;

        return $context;
    }

    /**
     * Define twig environment.
     *
     * @param Environment $twig
     * @param LoaderInterface $loader
     * @return Environment
     */
    public function extendTwig(Environment $twig, ?LoaderInterface $loader = null)
    {
        // Native runtime loaders avoid initializing Twig's compiler registry when
        // executing a precompiled theme callback. Keep the actual theme bound.
        $twig->addRuntimeLoader(new FactoryRuntimeLoader([
            ThemeRuntime::class => fn (): ThemeRuntime => new ThemeRuntime($this),
        ]));
        if ($twig->hasExtension(TwigExtension::class)) {
            $this->addGantryTwigGlobal($twig);

            return $twig;
        }

        if (!$loader) {
            $loader = $twig->getLoader();
        }

        $this->setTwigLoaderPaths($loader);

        $twig->addExtension(new TwigExtension);
        $this->addGantryTwigGlobal($twig);

        if (method_exists($this, 'toGrid')) {
            $filter = new TwigFilter('toGrid', [ThemeRuntime::class, 'toGrid']);
            $twig->addFilter($filter);
        }

        return $twig;
    }

    /**
     * Return renderer.
     *
     * @return Environment
     */
    public function renderer()
    {
        $gantry = static::gantry();
        $sourceMode = class_exists(BundleRuntime::class) && BundleRuntime::isSource();
        $compiledRequired = defined('GANTRY5_PLATFORM') && GANTRY5_PLATFORM === 'joomla' && !$gantry->admin();
        if ($compiledRequired && !$sourceMode) {
            if (class_exists(BundleRuntime::class)) {
                $bundle = BundleRuntime::current($gantry);
                if (!$bundle) { throw new \RuntimeException('The compiled render bundle is unavailable. Rebuild it before serving the theme.', 503); }
                if (!$this->renderer instanceof CompiledEnvironment || $this->renderer->generation() !== $bundle->id()) {
                    $this->activateCompiledTwig($bundle->data('twig'), $bundle->root());
                }
            } elseif (!$this->renderer instanceof CompiledEnvironment) {
                throw new \RuntimeException('The compiled render bundle is unavailable.', 503);
            }
        } elseif ($sourceMode && $this->renderer instanceof CompiledEnvironment) {
            $this->renderer = null;
        }
        if (!$this->renderer) {
            /** @var Config $global */
            $global = $gantry['global'];

            $cachePath = $global->get('compile_twig', 1) ? $this->getCachePath('twig') : null;
            if ($cachePath) {
                /** @phpstan-ignore-next-line */
                if (Environment::VERSION_ID > 3) {
                    // Twig 3 support.
                    $cache = new FilesystemCache($cachePath, FilesystemCache::FORCE_BYTECODE_INVALIDATION);
                /** @phpstan-ignore-next-line */
                } else {
                    $cache = new TwigCacheFilesystem($cachePath, FilesystemCache::FORCE_BYTECODE_INVALIDATION);
                }
            } else {
                $cache = false;
            }
            $debug = $gantry->debug();
            $production = (bool) $global->get('production', 1);
            $loader = new FilesystemLoader();
            $params = [
                'cache' => $cache,
                'debug' => $debug,
                'auto_reload' => !$production,
                'autoescape' => 'html'
            ];

            $twig = new Environment($loader, $params);

            $this->setTwigLoaderPaths($loader);

            if ($debug) {
                $twig->addExtension(new DebugExtension());
            }

            $this->renderer = $this->extendTwig($twig, $loader);
        }

        return $this->renderer;
    }

    /** Compile only during the registry's explicit save/build source scope. */
    public function compileTwigBundle(string $generation, string $directory, array $inlineSources = []): array
    {
        if (class_exists(BundleRuntime::class) && !BundleRuntime::isSource()) {
            throw new \RuntimeException('Twig bundle compilation requires the registry source scope.', 503);
        }
        return TwigBundle::prepare($this, $generation, $directory, $inlineSources);
    }

    /** @internal Fresh native environment; never replace it with a cloned Twig instance. */
    public function compilationEnvironment(string $generation): Environment
    {
        if (class_exists(BundleRuntime::class) && !BundleRuntime::isSource()) {
            CompiledEnvironment::unavailable('Twig compiler environments require the registry source scope.');
        }
        $loader = new GenerationFilesystemLoader($generation);
        $twig = new Environment($loader, ['debug' => static::gantry()->debug(), 'auto_reload' => false, 'autoescape' => 'html']);
        $this->setTwigLoaderPaths($loader);
        if ($twig->isDebug()) { $twig->addExtension(new DebugExtension()); }
        return $this->extendTwig($twig, $loader);
    }

    /** Install the registry's pinned immutable generation without resolving source paths. */
    public function activateCompiledTwig(array $map, string $directory): void
    {
        TwigManifest::validate($map);
        $loader = new CompiledLoader($map['templates'], $map['generation']);
        $cache = new ImmutableCache($directory, $loader, $map['templates']);
        $twig = new CompiledEnvironment($loader, $cache, $map['strings'], $map['generation'], $map['options']);
        if ($twig->isDebug()) { $twig->addExtension(new DebugExtension()); }
        $this->renderer = $this->extendTwig($twig, $loader);
    }

    /**
     * Render a template file by using given context.
     *
     * @param string $file
     * @param array $context
     * @return string
     */
    public function render($file, array $context = [])
    {
        // Include Gantry specific things to the context.
        $context = $this->getContext($context);

        $renderer = $this->renderer();
        try { return $renderer->render($file, $context); }
        catch (LoaderError $error) {
            if ($renderer instanceof CompiledEnvironment) { CompiledEnvironment::unavailable('A required template is absent from the active render bundle.'); }
            throw $error;
        }
    }

    /**
     * Compile and render twig string.
     *
     * @param string $string
     * @param array $context
     * @return string
     */
    public function compile($string, array $context = [])
    {
        $renderer = $this->renderer();
        if ($renderer instanceof CompiledEnvironment && !str_contains($string, '{{') && !str_contains($string, '{%') && !str_contains($string, '{#')) {
            return $string;
        }
        $template = $renderer->createTemplate($string);

        // Include Gantry specific things to the context.
        $context = $this->getContext($context);

        try { return $template->render($context); }
        catch (LoaderError $error) {
            if ($renderer instanceof CompiledEnvironment) { CompiledEnvironment::unavailable('An inline template dependency is absent from the active render bundle.'); }
            throw $error;
        }
    }

    /**
     * Initialize theme.
     */
    protected function init()
    {
        $gantry = static::gantry();
        $gantry['streams']->register();

        // Only add the detailed error service for logged-in administrators (in debug mode or
        // in the admin). Never show it to logged-out visitors, even when debugging is on.
        if (ErrorServiceProvider::allowed() && ($gantry->debug() || $gantry->admin())) {
            $gantry->register(new ErrorServiceProvider);
        }

        // Initialize theme cache stream.
        $cachePath = $this->getCachePath();

        Folder::create($cachePath);

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];
        $locator->addPath('gantry-cache', 'theme', [$cachePath], true, true);

        CompiledYamlFile::$defaultCachePath = $locator->findResource('gantry-cache://theme/compiled/yaml', true, true);
        CompiledYamlFile::$defaultCaching = $gantry['global']->get('compile_yaml', 1);
    }

    /**
     * Set twig lookup paths to the loader.
     *
     * @param LoaderInterface $loader
     * @return FilesystemLoader|null
     * @internal
     */
    protected function setTwigLoaderPaths(LoaderInterface $loader)
    {
        if ($loader instanceof ChainLoader) {
            $new = new FilesystemLoader();
            $loader->addLoader($new);
            $loader = $new;
        } elseif (!($loader instanceof FilesystemLoader)) {
            return null;
        }

        $gantry = static::gantry();

        /** @var UniformResourceLocator $locator */
        $locator = $gantry['locator'];

        $loader->setPaths($locator->findResources('gantry-engine://templates'), 'nucleus');
        $loader->setPaths($locator->findResources('gantry-particles://'), 'particles');

        return $loader;
    }

    /**
     * Ensure phpBB-provided Twig environments have Gantry available as a global.
     *
     * @param Environment $twig
     */
    protected function addGantryTwigGlobal(Environment $twig)
    {
        if (!array_key_exists('gantry', $twig->getGlobals())) {
            $twig->addGlobal('gantry', static::gantry());
        }
    }

    /**
     * Get path to Twig cache.
     *
     * @param string $path
     * @return string
     */
    protected function getCachePath($path = '')
    {
        $gantry = static::gantry();

        /** @var Platform $patform */
        $patform = $gantry['platform'];

        // Initialize theme cache stream.
        return $patform->getCachePath() . '/' . $this->name . ($path ? '/' . $path : '');
    }

    /**
     * @deprecated 5.0.2
     */
    public function debug()
    {
        return static::gantry()->debug();
    }

    /**
     * @deprecated 5.1.5
     */
    public function add_to_context(array $context)
    {
        return $this->getContext($context);
    }

    /**
     * @deprecated 5.1.5
     */
    public function add_to_twig(Environment $twig, ?LoaderInterface $loader = null)
    {
        return $this->extendTwig($twig, $loader);
    }
}
