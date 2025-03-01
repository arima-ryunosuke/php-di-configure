<?php

namespace ryunosuke\castella\Attribute;

use Attribute;
use Closure;
use ReflectionAttribute;
use ReflectionFunction;
use ReflectionObject;

#[Attribute(Attribute::TARGET_ALL)]
class AbstractAttribute
{
    public static function single(object $object, int $flags = 0): ?ReflectionAttribute
    {
        $ref   = $object instanceof Closure ? new ReflectionFunction($object) : new ReflectionObject($object);
        $attrs = $ref->getAttributes(static::class, $flags);

        return $attrs[0] ?? null;
    }

    public function getArguments(): array
    {
        $ref = new ReflectionObject($this);

        $result = [];
        foreach ($ref->getProperties() as $property) {
            if ($property->isPromoted()) {
                $property->setAccessible(true);
                $result[$property->getName()] = $property->getValue($this);
            }
        }
        return $result;
    }
}
