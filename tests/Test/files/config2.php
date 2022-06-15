<?php

/**
 * @var ryunosuke\castella\Container $this
 */

return [
    'env'      => [
        'loglevel'  => LOG_DEBUG,
        'extension' => $this->array(['js']),
    ],
    'database' => [
        'user'          => 'app',
        'password'      => 'p@ssword',
        'driverOptions' => [
            \PDO::ATTR_EMULATE_PREPARES  => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ],
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
];
