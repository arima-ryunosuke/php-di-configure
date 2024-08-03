<?php

/**
 * @var ryunosuke\castella\Container $this
 */

return [
    'env'      => [
        'ip'        => $this->const('127.0.0.1', 'LOCAL_IP'),
        'name'      => 'local',
        'origin'    => 'http://localhost',
        'loglevel'  => 'info',
        'logdir'    => '/var/log/app',
        'rundir'    => '/var/run/app',
        'datadir'   => '/var/opt/app',
        'extension' => ['js', 'es', 'ts'],
        'logger'    => static fn($c) => new class ($c['env.loglevel'], $c['env.logdir']) {
            private string $loglevel;
            private string $directory;

            public function __construct(string $loglevel, string $directory)
            {
                $this->loglevel  = $loglevel;
                $this->directory = $directory;
            }

            public function __invoke($loglevel)
            {
                $that           = clone $this;
                $that->loglevel = $loglevel;
                return $that;
            }

            public function __debugInfo()
            {
                return [
                    'loglevel'  => $this->loglevel,
                    'directory' => $this->directory,
                ];
            }
        },
    ],
    'database' => [
        'ip'            => $this->const('127.0.0.1', 'DB_IP'),
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
        'loglevel'      => $this['env.loglevel'],
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
    'chain'    => [
        'x' => new stdClass(),
        'y' => $this['chain.x'],
    ],
];
