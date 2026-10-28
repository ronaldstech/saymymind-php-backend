<?php
declare(strict_types=1);

/*
 * Copy this file to config/config.php and update the credentials.
 * Never commit config/config.php or publish database credentials.
 */
return [
    'app_env' => 'development',
    'app_debug' => true,
    'app_url' => 'http://localhost/anonymous-social-backend',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'anonymous_social_app',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'session_ttl_days' => 30,
];
