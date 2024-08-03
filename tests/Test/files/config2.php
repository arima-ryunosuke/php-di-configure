<?php

/**
 * @var ryunosuke\castella\Container $this
 */

return [
    'env'      => [
        'loglevel'  => 'debug',
        'extension' => $this->array(['js']),
    ],
    'database' => [
        'ip'            => $this->const('127.0.0.2', 'DB_IP'),
        'cip'           => $this->const('127.0.0.3'),
        'user'          => 'app',
        'password'      => 'p@ssword',
        'driverOptions' => [
            \PDO::ATTR_EMULATE_PREPARES  => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ],
        'logger'        => $this['env.logger']('error'),
    ],
    's3'       => [
        'config' => [
            'version' => '2006-03-01',
        ],
    ],
    'storage'  => [
        'local1' => $this->static(Storage::class, [1 => 'local1']),
        'local2' => static fn($c): Storage => $c->new(Storage::class, ['bucket' => 'local2']),
        'local3' => $this->static(Storage::class, [0 => $this->fn('s3.client')]),
    ],
    'chain'    => [
        'z' => $this['chain.y'],
    ],
];
