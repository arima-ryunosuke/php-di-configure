<?php

namespace ryunosuke\Test;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionType;

class AbstractTestCase extends TestCase
{
    public static function getReflectionType(...$type): ReflectionType
    {
        if (is_array($type[0])) {
            if (version_compare(PHP_VERSION, 8.1) >= 0) {
                $type = implode('&', $type[0]);
                return (new ReflectionFunction(eval("return fn(): $type => null;")))->getReturnType();
            }
            return new ReflectionIntersectionType(...array_map(fn($v) => self::getReflectionType($v), $type[0]));
        }

        if (count($type) > 1) {
            $type = implode('|', $type);
            return (new ReflectionFunction(eval("return fn(): $type => null;")))->getReturnType();
        }

        return (new ReflectionFunction(eval("return fn(): {$type[0]} => null;")))->getReturnType();
    }
}
