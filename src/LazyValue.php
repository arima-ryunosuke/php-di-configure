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
            $value = match ($method) {
                'offsetGet' => $value[$arguments[0]],
                '__get'     => $value->{$arguments[0]},
                default     => $value->$method(...$arguments),
            };
        }
        return $value;
    }

    public function __get(string $name): static
    {
        $this->lazy[] = [__FUNCTION__, [$name]];
        return $this;
    }

    public function __call(string $name, array $arguments): static
    {
        $this->lazy[] = [$name, $arguments];
        return $this;
    }

    public function __invoke(...$args): static
    {
        $this->lazy[] = [__FUNCTION__, $args];
        return $this;
    }

    public function offsetGet(mixed $offset): static
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
