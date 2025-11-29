<?php

require_once __DIR__ . '/config.php';

function db_config(): array
{
    if (ENVIRONMENT === 'development') {
        return [
            'host' => 'localhost',
            'dbname' => 'easychart-saas',
            'user' => 'easychart-saas',
            'pass' => 'Yd8wEtQ*rjv$es41',
            'charset' => 'utf8',
        ];
    }

    return [
        'host' => 'localhost',
        'dbname' => 'easychart-saas',
        'user' => 'easychart-saas',
        'pass' => 'Yd8wEtQ*rjv$es41',
        'charset' => 'utf8',
    ];
}