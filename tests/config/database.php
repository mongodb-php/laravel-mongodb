<?php

declare(strict_types=1);

return [
    'connections' => [
        'mongodb' => [
            'name' => 'mongodb',
            'driver' => 'mongodb',
            'dsn' => env('MONGODB_URI', 'mongodb://127.0.0.1/'),
            'database' => env('MONGODB_DATABASE', 'unittest'),
            'options' => [
                'connectTimeoutMS'         => 1000,
                'serverSelectionTimeoutMS' => 1000,
            ],
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('SQLITE_DATABASE', ':memory:'),
            'charset' => 'utf8',
            'collation' => 'utf8_unicode_ci',
            'prefix' => '',
        ],
    ],
];
