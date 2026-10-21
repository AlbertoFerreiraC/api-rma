<?php

declare(strict_types=1);

function rmaConfig(string $tipo): array
{
    $defaults = [
        'rma' => [
            'host' => '162.220.10.11',
            'port' => '3306',
            'database' => 'dosisma_rma_bd',
            'user' => 'dosisma_rma',
            'password' => 'tesis2026bruno'
        ],
        'inventario' => [
            'host' => '162.220.10.11',
            'port' => '3306',
            'database' => 'dosisma_inventario_db',
            'user' => 'dosisma_stock',
            'password' => 'inventariobrunorma2k26'
        ]
    ];

    if (!isset($defaults[$tipo])) {
        throw new InvalidArgumentException('Conexion desconocida');
    }

    $settings = $defaults[$tipo];

    $local = __DIR__ . '/config.local.php';

    if (is_file($local)) {
        $custom = require $local;

        if (
            is_array($custom) &&
            isset($custom[$tipo]) &&
            is_array($custom[$tipo])
        ) {
            $settings = array_replace(
                $settings,
                $custom[$tipo]
            );
        }
    }

    $prefix = $tipo === 'rma'
        ? 'RMA_DB_'
        : 'RMA_INV_DB_';

    foreach (
        [
            'host' => 'HOST',
            'port' => 'PORT',
            'database' => 'NAME',
            'user' => 'USER',
            'password' => 'PASSWORD'
        ] as $key => $suffix
    ) {
        $env = getenv($prefix . $suffix);

        if ($env !== false) {
            $settings[$key] = $env;
        }
    }

    return $settings;
}

function rmaConexion(string $tipo): PDO
{
    $cfg = rmaConfig($tipo);

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $cfg['host'],
        $cfg['port'],
        $cfg['database']
    );

    return new PDO(
        $dsn,
        $cfg['user'],
        $cfg['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
}
