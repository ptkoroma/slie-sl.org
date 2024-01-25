<?php

// autoload_real.php @generated by Composer
use Composer\Autoload\ClassLoader;
use Composer\Autoload\ComposerStaticInit1126f2a1d20d4b0a19d60d62b0198ab5;

class ComposerAutoloaderInit1126f2a1d20d4b0a19d60d62b0198ab5
{
    private static $loader;

    public static function loadClassLoader($class)
    {
        if ('Composer\Autoload\ClassLoader' === $class) {
            require __DIR__ . '/ClassLoader.php';
        }
    }

    /**
     * @return \Composer\Autoload\ClassLoader
     */
    public static function getLoader()
    {
        if (null !== self::$loader) {
            return self::$loader;
        }

        require __DIR__ . '/platform_check.php';

        spl_autoload_register(array('ComposerAutoloaderInit1126f2a1d20d4b0a19d60d62b0198ab5', 'loadClassLoader'), true, true);
        self::$loader = $loader = new ClassLoader(\dirname(\dirname(__FILE__)));
        spl_autoload_unregister(array('ComposerAutoloaderInit1126f2a1d20d4b0a19d60d62b0198ab5', 'loadClassLoader'));

        $useStaticLoader = PHP_VERSION_ID >= 50600 && !defined('HHVM_VERSION') && (!function_exists('zend_loader_file_encoded') || !zend_loader_file_encoded());
        if ($useStaticLoader) {
            require __DIR__ . '/autoload_static.php';

            call_user_func(ComposerStaticInit1126f2a1d20d4b0a19d60d62b0198ab5::getInitializer($loader));
        } else {
            $map = require __DIR__ . '/autoload_namespaces.php';
            foreach ($map as $namespace => $path) {
                $loader->set($namespace, $path);
            }

            $map = require __DIR__ . '/autoload_psr4.php';
            foreach ($map as $namespace => $path) {
                $loader->setPsr4($namespace, $path);
            }

            $classMap = require __DIR__ . '/autoload_classmap.php';
            if ($classMap) {
                $loader->addClassMap($classMap);
            }
        }

        $loader->register(true);

        if ($useStaticLoader) {
            $includeFiles = ComposerStaticInit1126f2a1d20d4b0a19d60d62b0198ab5::$files;
        } else {
            $includeFiles = require __DIR__ . '/autoload_files.php';
        }
        foreach ($includeFiles as $fileIdentifier => $file) {
            composerRequire1126f2a1d20d4b0a19d60d62b0198ab5($fileIdentifier, $file);
        }

        return $loader;
    }
}

function composerRequire1126f2a1d20d4b0a19d60d62b0198ab5($fileIdentifier, $file)
{
    if (empty($GLOBALS['__composer_autoload_files'][$fileIdentifier])) {
        require $file;

        $GLOBALS['__composer_autoload_files'][$fileIdentifier] = true;
    }
}
