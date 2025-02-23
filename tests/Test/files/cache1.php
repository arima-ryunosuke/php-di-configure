<?php

namespace cache;

use stdClass as SC;

$local = 123;

/**
 * @var \ryunosuke\castella\Container $this
 */

return [
    'this'      => $this,
    'unset'     => $this->unset(),
    'string'    => 'cache',
    'float'     => M_PI,
    'array'     => $this->array([
        'a' => 'A',
    ]),
    'lazy'      => $this['array'],
    'stdclass'  => (object) [
        'x' => 'X',
    ],
    'callable'  => $this->callable(function ($x) use ($local) {
        return $x * 123 + $local;
    }),
    'bound'     => function () {
        $object = new class () {
            function method()
            {
                return 'method';
            }
        };
        return (fn() => $this)->bindTo($object);
    },
    'anonymous' => static fn($c) => new class($c) extends SC {
        public function __construct(private \ryunosuke\castella\Container $c) { }

        public function string()
        {
            return $this->c['string'];
        }
    },
    'const'     => $this->const('const1', 'CNAME1'),
    'misc'      => [
        'alias A' => 'alias',
        'empty'   => [],
        'magic'   => fn() => [
            __DIR__,
            __FILE__,
            __NAMESPACE__,
        ],
    ],
];
