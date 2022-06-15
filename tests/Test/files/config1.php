<?php

/**
 * @var ryunosuke\castella\Container $this
 */

return [
    'env'      => [
        'name'      => 'local',
        'origin'    => 'http://localhost',
        'loglevel'  => LOG_INFO,
        'logdir'    => '/var/log/app',
        'rundir'    => '/var/run/app',
        'datadir'   => '/var/opt/app',
        'extension' => ['js', 'es', 'ts'],
    ],
    'database' => [
        'driver'        => 'pdo_mysql',
        'host'          => '127.0.0.1',
        'port'          => 3306,
        'dbname'        => 'app',
        'user'          => 'user',
        'password'      => 'password',
        'charset'       => 'utf8mb4',
        'connect'       => $this->callable(function (\PDO $pdo) { }),
        'driverOptions' => [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ],
    ],
    's3'       => [
        'config' => [
            'region'  => 'ap-northeast-1',
            'version' => 'latest',
        ],
        'client' => $this->static(S3Client::class),
    ],
    'storage'  => [
        'private' => static fn($c, $keys) => new Storage($c['s3.client'], $keys[0]),
        'protect' => static fn($c, $keys) => new Storage($c['s3.client'], $keys[0]),
        'public'  => static fn($c, $keys) => new Storage($c['s3.client'], $keys[0]),
    ],
];
