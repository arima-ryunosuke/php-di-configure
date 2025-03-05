<?php

namespace ryunosuke\Test;

use ArrayObject;
use ryunosuke\castella\Container;
use ryunosuke\castella\LazyValue;

class LazyValueTest extends AbstractTestCase
{
    function test_all()
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
}
