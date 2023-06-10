<?php

namespace ryunosuke\castella;

use ArrayAccess;
use Closure;
use LogicException;

class LazyValue implements ArrayAccess
{
    private Closure $provider;

    private array $lazy = [];

    public function __construct(Closure $initialValueProvider)
    {
        $this->provider = $initialValueProvider;
    }

    public function ___resolve()
    {
        $value = ($this->provider)();
        foreach ($this->lazy as [$method, $arguments]) {
            switch ($method) {
                case 'offsetGet':
                    $value = $value[$arguments[0]];
                    break;
                case '__get':
                    $value = $value->{$arguments[0]};
                    break;
                default:
                    $value = $value->$method(...$arguments);
                    break;
            }
        }
        return $value;
    }

    public function __get($name)
    {
        $this->lazy[] = [__FUNCTION__, [$name]];
        return $this;
    }

    public function __call($name, $arguments)
    {
        $this->lazy[] = [$name, $arguments];
        return $this;
    }

    public function __invoke(...$args)
    {
        $this->lazy[] = [__FUNCTION__, $args];
        return $this;
    }

    /** @noinspection PhpLanguageLevelInspection */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)//: mixed
    {
        $this->lazy[] = [__FUNCTION__, [$offset]];
        return $this;
    }

    #<editor-fold desc="ArrayAccess">
    // @codeCoverageIgnoreStart
    public function offsetExists($offset): bool
    {
        throw new LogicException('offsetUnset is not support');
    }

    public function offsetSet($offset, $value): void
    {
        throw new LogicException('offsetUnset is not support');
    }

    public function offsetUnset($offset): void
    {
        throw new LogicException('offsetUnset is not support');
    }
    // @codeCoverageIgnoreEnd
    #</editor-fold>
}
