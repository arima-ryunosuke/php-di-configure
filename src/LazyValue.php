<?php

namespace ryunosuke\castella;

use ArrayAccess;
use LogicException;

class LazyValue implements ArrayAccess
{
    private array $lazy = [];

    public function __construct(private Container $container, private string $id) { }

    public function __debugInfo(): array
    {
        $argumentify = function ($args) {
            $i     = 0;
            $stmts = [];
            foreach ($args as $n => $arg) {
                $stmts[] = ($n === $i++ ? var_export($arg, true) : "$n: " . var_export($arg, true));
            }
            return implode(', ', $stmts);
        };

        $statement = "\$this[{$this->id}]";
        foreach ($this->lazy as [$method, $arguments]) {
            $statement .= match ($method) {
                'offsetGet' => "[{$arguments[0]}]",
                '__get'     => "->{$arguments[0]}",
                '__invoke'  => "({$argumentify($arguments)})",
                default     => "->{$method}({$argumentify($arguments)})",
            };
        }
        return ['' => $statement];
    }

    public function ___resolve()
    {
        $value = $this->container->get($this->id);
        foreach ($this->lazy as [$method, $arguments]) {
            $value = match ($method) {
                'offsetGet' => $value[$arguments[0]],
                '__get'     => $value->{$arguments[0]},
                default     => $value->$method(...$arguments),
            };
        }
        return $value;
    }

    public function ___closureize(): string
    {
        $V = fn($v) => var_export($v, true);

        $statement = "fn() => \$this[{$V($this->id)}]";
        foreach ($this->lazy as [$method, $arguments]) {
            $statement .= match ($method) {
                'offsetGet' => "[{$V($arguments[0])}]",
                '__get'     => "->{{$V($arguments[0])}}",
                '__invoke'  => "(...{$V($arguments)})",
                default     => "->{{$V($method)}}(...{$V($arguments)})",
            };
        }
        return $statement;
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
