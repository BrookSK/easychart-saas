<?php

require_once __DIR__ . '/config.php';

function db_config(): array
{
    if (ENVIRONMENT === 'development') {
        return [
            'host' => 'localhost',
            'dbname' => 'easychart-saas',
            'user' => 'root',
            'pass' => '',
            'charset' => 'utf8',
        ];
    }

    return [
        'host' => 'localhost',
        'dbname' => 'easychart-saas',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8',
    ];
}