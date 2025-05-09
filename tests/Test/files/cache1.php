<?php

namespace cache;

use ryunosuke\castella\Attribute\Factory;
use stdClass as SC;

$local = 123;

/**
 * @var \ryunosuke\castella\Container $this
 */

return [
    'this'       => $this,
    'unset'      => $this->unset(),
    'string'     => 'cache',
    'float'      => M_PI,
    'array'      => $this->array([
        'a' => 'A',
    ]),
    'object'     => fn() => new class('hoge') {
        public function __construct(public string $name) { }

        public function withName(string $name)
        {
            $that       = clone $this;
            $that->name = $name;
            return $that;
        }
    },
    'lazy'       => $this['array'],
    'lazyObject' => $this['object']->withName('fuga'),
    'stdclass'   => (object) [
        'x' => 'X',
    ],
    'callable'   => $this->callable(function ($x) use ($local) {
        return $x * 123 + $local;
    }),
    'bound'      => #[Factory(once: false)] function () {
        $object = new class () {
            function method()
            {
                return 'method';
            }
        };
        return (fn() => $this)->bindTo($object);
    },
    'anonymous'  => #[Factory(once: true)] fn() => new class($this) extends SC {
        public function __construct(private \ryunosuke\castella\Container $c) { }

        public function string()
        {
            return $this->c['string'];
        }
    },
    'const'      => $this->const('const1', 'CNAME1'),
    'misc'       => [
        'alias A' => 'alias',
        'empty'   => [],
        'magic'   => fn() => [
            __DIR__,
            __FILE__,
            __NAMESPACE__,
        ],
    ],
];
