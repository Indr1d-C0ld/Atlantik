<?php

declare(strict_types=1);

/**
 * MODELLO di configurazione — solo segnaposto, nessun segreto.
 *
 * Il file vero viene generato da deploy/00-bootstrap.sh in
 *   /etc/atlantik/config.php
 * L'app lo cerca, in ordine: $ATLANTIK_CONFIG, /etc/atlantik/config.php,
 * /etc/atlantik/config.php, <progetto>/config/config.php.
 */

return [
    'app' => [
        'name'        => 'Atlantik',
        'env'         => 'production',
        'debug'       => false,
        'timezone'    => 'Europe/Rome',
        'pretty_urls' => true,
        'base_path'   => null,
        'public_url'  => 'https://example.com/atlantik',
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'atl_atlantik',
        'user'    => 'atl_atlantik',
        'pass'    => 'CAMBIAMI',
        'charset' => 'utf8mb4',
    ],

    'security' => [
        'session_name' => 'atlantik_sess',
        'session_ttl'  => 60 * 60 * 8,
    ],

    // 'log' scrive su storage/logs/app.log invece di inviare davvero.
    'mail' => [
        'transport'   => 'log',
        'smtp_host'   => 'smtp-relay.brevo.com',
        'smtp_port'   => 587,
        'smtp_secure' => 'tls',
        'smtp_user'   => 'CAMBIAMI',
        'smtp_pass'   => 'CAMBIAMI',
        'from_email'  => 'CAMBIAMI',   // mittente verificato dal provider
        'from_name'   => 'Atlantik — BdU',
        'timeout'     => 15,
    ],

    'notify' => [
        'new_registration' => true,
        'admin_email'      => 'admin@example.com',
    ],

    'world' => [
        'seed'       => 123456789,
        'time_ratio' => 30,
    ],
];
