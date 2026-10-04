<?php

declare(strict_types=1);

spl_autoload_register(function ($class) {
    $namespaces = [
        'Antevemus\\ASpecification\\Tests\\' => __DIR__ . '/',
        'Antevemus\\ASpecification\\' => dirname(__DIR__) . '/src/',
        'Antevemus\\ALinq\\' => dirname(__DIR__, 2) . '/Antevemus.AlinqCollection/src/',
        'Adianti\\Database\\' => __DIR__ . '/Stubs/Adianti/',
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
