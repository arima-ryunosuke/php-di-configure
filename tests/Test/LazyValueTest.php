<?php

namespace ryunosuke\Test;

use ArrayObject;
use ryunosuke\castella\LazyValue;

class LazyValueTest extends AbstractTestCase
{
    function test_all()
    {
        $lazyValue = new LazyValue(fn() => new ArrayObject(['x' => 'X']));
        that($lazyValue['x'])->isSame($lazyValue);
        that($lazyValue->___resolve())->isSame('X');

        $lazyValue = new LazyValue(fn() => new ArrayObject(['x' => 'X'], ArrayObject::ARRAY_AS_PROPS));
        that($lazyValue->x)->isSame($lazyValue);
        that($lazyValue->___resolve())->isSame('X');

        /** @var ArrayObject|LazyValue $lazyValue */
        $lazyValue = new LazyValue(fn() => new ArrayObject(['x' => 'X']));
        that($lazyValue->getArrayCopy())->isSame($lazyValue);
        that($lazyValue->___resolve())->isSame(['x' => 'X']);

        $lazyValue = new LazyValue(fn() => fn($v) => $v);
        that($lazyValue('Z'))->isSame($lazyValue);
        that($lazyValue->___resolve())->isSame('Z');

        $lazyValue = new LazyValue(fn() => new class([
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
        });
        that($lazyValue['x']->y->method(1)(2))->isSame($lazyValue);
        that($lazyValue->___resolve())->isSame(3);
    }
}
