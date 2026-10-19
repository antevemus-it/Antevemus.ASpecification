<?php

declare(strict_types=1);

// Composer's autoloader first: since 2.0.0 the library requires antevemus/adeep-property-access
// (installed by `composer install`); vendor/bin/phpunit loads it on its own, the module runner
// (tests/run_all.php) through this line.
if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

spl_autoload_register(function ($class) {
    $namespaces = [
        'Antevemus\\ASpecification\\Tests\\' => __DIR__ . '/',
        'Antevemus\\ASpecification\\' => dirname(__DIR__) . '/src/',
        'Antevemus\\ALinq\\' => dirname(__DIR__, 2) . '/Antevemus.AlinqCollection/src/',
        'Adianti\\Database\\' => __DIR__ . '/Stubs/Adianti/',
        // Forward 048, D33 (2.5.0): the dialogs of the Adianti host recipe (README example 22.3)
        'Adianti\\Widget\\Dialog\\' => __DIR__ . '/Stubs/Adianti/Widget/Dialog/',
    ];

    foreach ($namespaces as $prefix => $base_dir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
});

require_once dirname(__DIR__) . '/src/DSL/functions.php';
