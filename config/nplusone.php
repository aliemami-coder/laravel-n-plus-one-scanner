<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Paths to Scan
    |--------------------------------------------------------------------------
    |
    | Directories (relative to base_path()) that the scanner will inspect for
    | PHP files, API Resources, Controllers, and Blade templates.
    |
    */
    'scan_paths' => [
        'app',
        'routes',
        'resources/views',
    ],

    /*
    |--------------------------------------------------------------------------
    | Eloquent Model Paths
    |--------------------------------------------------------------------------
    |
    | Directories where Eloquent models are located. Used to build the
    | relationship registry before scanning.
    |
    */
    'model_paths' => [
        'app/Models',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Paths
    |--------------------------------------------------------------------------
    |
    | Glob/substring patterns for files or directories to skip during scanning.
    |
    */
    'exclude_paths' => [
        'vendor',
        'storage',
        'bootstrap/cache',
        'node_modules',
        'tests',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ignored Relations
    |--------------------------------------------------------------------------
    |
    | Relations listed here will never be reported as N+1 issues. You can use
    | either 'relationName' globally or 'ModelClass@relationName'.
    |
    */
    'ignore_relations' => [
        // 'User@roles',
        // 'tenant',
    ],

    /*
    |--------------------------------------------------------------------------
    | Enabled Analyzers
    |--------------------------------------------------------------------------
    */
    'analyzers' => [
        'php_loops' => true,
        'queries_in_loops' => true,
        'api_resources' => true,
        'blade_views' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Baseline File Path
    |--------------------------------------------------------------------------
    */
    'baseline_path' => 'nplusone-baseline.json',

    /*
    |--------------------------------------------------------------------------
    | CI / Exit Code Behavior
    |--------------------------------------------------------------------------
    */
    'fail_on_issues' => true,
];
