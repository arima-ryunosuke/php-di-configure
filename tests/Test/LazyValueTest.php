<?php

namespace ryunosuke\Test;

use ArrayObject;
use ryunosuke\castella\Container;
use ryunosuke\castella\LazyValue;

class LazyValueTest extends AbstractTestCase
{
    function test___debugInfo()
    {
        $container = new Container();

        $lazyValue = new LazyValue($container, 'parent.key');
        $lazyValue = $lazyValue['x'];
        $lazyValue = $lazyValue->y;
        $lazyValue = $lazyValue->z(1, 2, x: 3);
        $lazyValue = $lazyValue(4, 5, x: 6);

        ob_start();
        var_dump($lazyValue);
        $var_dump = ob_get_clean();
        that($var_dump)->stringContains('$this[parent.key][x]->y->z(1, 2, x: 3)(4, 5, x: 6)');
    }

    function test____resolve()
    {
        $container = new Container();

        $container['ArrayObject'] = new ArrayObject(['x' => 'X'], ArrayObject::ARRAY_AS_PROPS);
        $container['Invoke']      = new class() {
            public function __invoke($v)
            {
                return $v;
            }
        };
        $container['Anonymous']   = new class([
            'x' => new class() {
                public $y;

                public function __construct()
                {
                    $this->y = new class() {
                        public function method($int1)
                        {
                            return fn($int2) => $int1 + $int2;
                        }
                    };
                }
            },
        ]) extends ArrayObject {
        };
        $container['String']      = 'string';

        $lazyValue = new LazyValue($container, 'ArrayObject');
        that($lazyValue['x'])->isSame($lazyValue);
        that($lazyValue->___resolve())->isSame('X');

        $lazyValue = new LazyValue($container, 'ArrayObject');
        that($lazyValue->x)->isSame($lazyValue);
        that($lazyValue->___resolve())->isSame('X');

        /** @var ArrayObject|LazyValue $lazyValue */
        $lazyValue = new LazyValue($container, 'ArrayObject');
        that($lazyValue->getArrayCopy())->isSame($lazyValue);
        that($lazyValue->___resolve())->isSame(['x' => 'X']);

        $lazyValue = new LazyValue($container, 'Invoke');
        that($lazyValue('Z'))->isSame($lazyValue);
        that($lazyValue->___resolve())->isSame('Z');

        $lazyValue = new LazyValue($container, 'Anonymous');
        that($lazyValue['x']->y->method(1)(2))->isSame($lazyValue);
        that($lazyValue->___resolve())->isSame(3);
    }

    function test____closureize()
    {
        $container = new Container();

        $lazyValue = new LazyValue($container, 'parent.key');
        $lazyValue = $lazyValue['x'];
        $lazyValue = $lazyValue->y;
        $lazyValue = $lazyValue->z(1, 2, x: 3);
        $lazyValue = $lazyValue(4, 5, x: 6);

        $closureString = $lazyValue->___closureize();
        that($closureString)->is(<<<'STMT'
            fn() => $this['parent.key']['x']->{'y'}->{'z'}(...array (
              0 => 1,
              1 => 2,
              'x' => 3,
            ))(...array (
              0 => 4,
              1 => 5,
              'x' => 6,
            ))
            STMT
        );
        $closure = eval("return $closureString;");
        that($closure->call(new class([
            'parent.key' => [
                'x' => new ArrayObject([
                    'y' => new class() {
                        public array $stack = [];

                        public function z(...$args)
                        {
                            $this->stack[] = $args;
                            return $this;
                        }

                        public function __invoke(...$args)
                        {
                            $this->stack[] = $args;
                            return $this;
                        }
                    },
                ], ArrayObject::ARRAY_AS_PROPS),
            ],
        ]) extends ArrayObject {
        })->stack)->is([
            [1, 2, 'x' => 3],
            [4, 5, 'x' => 6],
        ]);
    }

    function test___toString()
    {
        $container = new Container();
        $container->set('hoge', 'HOGE');

        $lazyValue = new LazyValue($container, 'hoge');
        that("$lazyValue")->is('HOGE');
    }
}
