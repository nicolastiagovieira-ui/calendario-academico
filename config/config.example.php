<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'Calendário de disciplinas',
        'timezone' => 'America/Sao_Paulo',
        'base_path' => '',
        'session_name' => 'calendario_session',
        'require_host_session' => false,
        'host_session_key' => 'username',
        'legacy_sync' => true,
    ],
    'db' => [
        'host' => getenv('CAL_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('CAL_DB_PORT') ?: 3306),
        'database' => getenv('CAL_DB_NAME') ?: 'calendario_demo',
        'username' => getenv('CAL_DB_USER') ?: '',
        'password' => getenv('CAL_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
];

