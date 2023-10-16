<?php

namespace ryunosuke\castella;

use ArrayAccess;
use Closure;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use SplObjectStorage;
use stdClass;
use Throwable;

class Container implements ContainerInterface, ArrayAccess
{
    /** future scope public const NOVALUE = new stdClass(); */
    private static object $novalue;

    private bool $including = false;

    private ?string $debugInfo;
    private string  $delimiter;
    private bool    $autowiring;
    private bool    $constructorInjection;
    private bool    $propertyInjection;
    private Closure $resolver;

    private SplObjectStorage $uninitializedObjects;
    private SplObjectStorage $closureMetadata;

    private array $entries = [];
    private array $settled = [];
    private array $aliases = [];

    public function __construct(array $options = [])
    {
        self::$novalue ??= new stdClass();

        $this->debugInfo            = $options['debugInfo'] ?? null;
        $this->delimiter            = $options['delimiter'] ?? '.';
        $this->autowiring           = $options['autowiring'] ?? true;
        $this->constructorInjection = $options['constructorInjection'] ?? true;
        $this->propertyInjection    = $options['propertyInjection'] ?? true;
        $this->resolver             = Closure::bind(Closure::fromCallable($options['resolver'] ?? [$this, 'resolve']), $this, $this);

        $this->uninitializedObjects = new SplObjectStorage();
        $this->closureMetadata      = new SplObjectStorage();
    }

    public function __debugInfo()
    {
        // default var_dump
        if ($this->debugInfo === null) {
            return (array) $this;
        }

        // resolve all value
        if ($this->debugInfo === 'settled') {
            $this->get('');
        }

        return $this->{$this->debugInfo};
    }

    #<editor-fold desc="MagicAccess">

    public function __isset($name)
    {
        return $this->has($name);
    }

    public function __get($name)
    {
        return $this->get($name);
    }

    public function __set($name, $value)
    {
        $this->set($name, $value);
    }

    public function __unset($name)
    {
        throw self::newContainerException('__unset is not support');
    }

    #</editor-fold>

    public function extends(array $values): self
    {
        $extends = function (array $currents, array $array, array $keys) use (&$extends) {
            foreach ($array as $k => $v) {
                [$key, $alias] = preg_split('#\s#', $k, 2, PREG_SPLIT_NO_EMPTY) + [1 => null];

                $keyskey = array_merge($keys, [$key]);
                $id      = implode($this->delimiter, $keyskey);

                if (array_key_exists($id, $this->settled)) {
                    throw self::newContainerException("%s is already settled", $id);
                }

                if ($alias !== null) {
                    assert(!isset($this->aliases[$alias]) || $this->aliases[$alias] === $id);
                    $this->aliases[$alias] = $id;
                }

                $current = [];
                if (array_key_exists($key, $currents)) {
                    $current = $currents[$key];
                    // closures that return array are also allowed to merge
                    if ($v !== self::$novalue && ($this->getValueType($current) === 'array' xor $this->getValueType($v) === 'array')) {
                        throw self::newContainerException("%s is not array", $id);
                    }
                }
                if (is_array($current) && is_array($v)) {
                    $v = $extends($current, $v, $keyskey);
                }
                $currents[$key] = $v;
            }
            return $currents;
        };

        $this->entries = $extends($this->entries, $values, []);

        return $this;
    }

    public function include(string $filename): self
    {
        $this->including = true;
        try {
            return $this->extends(require $filename);
        }
        finally {
            $this->including = false;
        }
    }

    public function set(string $id, $value): self
    {
        $values = [];
        $tmp    = &$values;
        foreach (array_filter(explode($this->delimiter, $id), 'strlen') as $key) {
            $tmp = &$tmp[$key];
        }
        $tmp = $value;

        return $this->extends($values);
    }

    public function get(string $id)
    {
        if ($this->including) {
            return new LazyValue(fn() => $this[$id]);
        }

        try {
            return $this->settle($id, $this->fetch($id));
        }
        catch (NotFoundExceptionInterface $e) {
            if ($this->autowiring && class_exists($id)) {
                return $this->settle($id, $this->instance($id, [], false));
            }
            throw $e;
        }
    }

    public function has(string $id): bool
    {
        try {
            $this->fetch($id);
            return true;
        }
        catch (NotFoundExceptionInterface $e) {
            if ($this->autowiring && class_exists($id)) {
                return true;
            }
            return false;
        }
    }

    public function unset(): object
    {
        return self::$novalue;
    }

    public function env(string ...$names): ?string
    {
        foreach ($names as $name) {
            $env = getenv($name, true);
            if ($env !== false) {
                return $env;
            }
        }
        return null;
    }

    public function fn(string $id): Closure
    {
        return fn() => $this->get($id);
    }

    public function new(string $classname, array $arguments = []): object
    {
        return $this->instance($classname, $arguments, true);
    }

    public function yield(string $classname, array $arguments = []): Closure
    {
        $closure = fn($c) => $c->instance($classname, $arguments, false);
        $this->closureMetadata->attach($closure, (object) [
            'dynamic'    => true,
            'returnType' => ltrim($classname, '\\'),
        ]);
        return $closure;
    }

    public function static(string $classname, array $arguments = []): Closure
    {
        $closure = static fn($c) => $c->instance($classname, $arguments, false);
        $this->closureMetadata->attach($closure, (object) [
            'dynamic'    => false,
            'returnType' => ltrim($classname, '\\'),
        ]);
        return $closure;
    }

    public function callable(callable $entry): Closure
    {
        return static fn(): Closure => Closure::fromCallable($entry);
    }

    public function array(array $entry): Closure
    {
        return fn($c, $keys): array => array_map(fn($v) => $c->factory($keys, $v), $entry);
    }

    public function annotate(?string $filename = null): array
    {
        $result = [];
        $walk   = function (array $array, array $keys) use (&$walk, &$result) {
            foreach ($array as $k => $v) {
                $keyskey     = array_merge($keys, [$k]);
                $id          = implode($this->delimiter, $keyskey);
                $result[$id] = self::getTypeName($v);
                if (is_array($v)) {
                    $walk($v, $keyskey);
                }
            }
        };
        $walk($this->get(''), []);

        foreach ($this->aliases as $alias => $id) {
            $result[$alias] = $result[$id];
        }

        if ($filename !== null) {
            $map       = $this->describeValue($result, 1);
            $classname = '\\' . get_class($this);
            $contents  = file_exists($filename) ? file_get_contents($filename) : '';
            if (strpos($contents, 'namespace PHPSTORM_META') === false) {
                $contents = <<<META
                <?php
                namespace PHPSTORM_META {
                
                    // @codeInjectionStart:$classname
                    // @codeInjectionEnd:$classname
                }
                
                META;
            }
            $qc = preg_quote($classname, '#');
            file_put_contents($filename, preg_replace_callback("#^\s*(// @codeInjectionStart:{$qc}).*?^\s*(// @codeInjectionEnd:{$qc})#smu", fn($m) => <<<META
                    {$m[1]}
                    override({$classname}::get(0), map({$map}));
                    override(new {$classname}, map({$map}));
                    {$m[2]}
                META, $contents));
        }
        return $result;
    }

    public function typehint(?string $filename = null): array
    {
        $result = [];
        foreach ($this->get('') as $k => $v) {
            $result[$k] = self::getTypeName($v);
        }

        foreach ($this->aliases as $alias => $id) {
            $result[$alias] = self::getTypeName($this->get($id));
        }

        if ($filename !== null) {
            $parts     = explode('\\', get_class($this));
            $classname = array_pop($parts);
            $namespace = implode('\\', $parts);

            $construct  = '    public function __construct(array $options = []) { }';
            $properties = '';
            foreach ($result as $name => $type) {
                if ($type === 'array') {
                    $properties .= "    /** @var " . self::getArrayType($this->get($name)) . " */\n";
                }
                if ($type === 'resource') {
                    $properties .= "    /** @var resource */\n";
                }

                $rtype = "$type ";
                if (in_array($type, ['resource', 'unknown'], true)) {
                    $rtype = '';
                }
                $properties .= "    public $rtype$$name;\n";
            }
            file_put_contents($filename, "<?php\nnamespace $namespace;\n\nclass $classname\n{\n$construct\n\n$properties}\n");
        }
        return $result;
    }

    public function dump(string $id = ''): string
    {
        $aliases   = array_flip($this->aliases);
        $withalias = function ($entry, array $keys) use (&$withalias, $aliases) {
            if (is_array($entry)) {
                $result = [];
                foreach ($entry as $k => $v) {
                    $keyskey     = implode($this->delimiter, array_merge($keys, [$k]));
                    $id          = $k . (isset($aliases[$keyskey]) ? ' ' . $aliases[$keyskey] : '');
                    $result[$id] = $withalias($v, array_merge($keys, [$k]));
                }
                return $result;
            }
            return $entry;
        };
        return $this->describeValue($withalias($this->get($id), []));
    }

    private function fetch(string $id)
    {
        $id = $this->aliases[$id] ?? $id;
        if (array_key_exists($id, $this->settled)) {
            return $this->settled[$id];
        }

        $keys  = [];
        $entry = $this->entries;
        foreach (array_filter(explode($this->delimiter, $id), 'strlen') as $key) {
            $keys[] = $key;
            if (!is_array($entry)) {
                throw self::newContainerException("%s is not array", implode($this->delimiter, $keys));
            }
            if (!array_key_exists($key, $entry)) {
                throw self::newNotFoundException("undefined config key '%s' in %s", $key, implode($this->delimiter, $keys));
            }
            $entry = $entry[$key];
        }

        if ($entry === self::$novalue) {
            throw self::newNotFoundException("unsetted config key '%s'", implode($this->delimiter, $keys));
        }

        return $entry;
    }

    private function settle(string $id, $entry)
    {
        if (array_key_exists($id, $this->settled)) {
            return $this->settled[$id];
        }

        $keys  = array_reverse(array_filter(explode($this->delimiter, $id), 'strlen'));
        $entry = $this->factory($keys, $entry, $dynamic);

        if (is_array($entry)) {
            foreach ($entry as $key => $value) {
                if ($value === self::$novalue) {
                    unset($entry[$key]);
                    continue;
                }
                $entry[$key] = $this->settle(strlen($id) ? $id . $this->delimiter . $key : $key, $value);
            }
        }

        // set ahead to deter infinite loops and unset on catch
        $this->settled[$id] = $entry;
        if (is_object($entry) && $this->uninitializedObjects->contains($entry)) {
            try {
                $this->uninitializedObjects[$entry]($keys);
            }
            catch (Throwable $t) {
                unset($this->settled[$id]);
                throw $t;
            }
        }

        if ($dynamic) {
            unset($this->settled[$id]);
        }
        return $entry;
    }

    private function factory(array $keys, $entry, ?bool &$dynamic = false)
    {
        if ($entry instanceof LazyValue) {
            $entry = $entry->___resolve();
        }

        if (!$entry instanceof Closure) {
            return $entry;
        }

        $metadata = $this->closureMetadata[$entry] ??= (object) [];

        $dynamic = $metadata->dynamic ??= !!@$entry->bindTo($this);
        if (!$dynamic && property_exists($metadata, 'result')) {
            return $metadata->result;
        }

        return $metadata->result = $entry($this, $keys);
    }

    private function instance(string $classname, array $arguments, bool $initialize): object
    {
        $refclass = new ReflectionClass($classname);

        $constructorInjection = function ($arguments) use ($refclass) {
            if ($constructor = $refclass->getConstructor()) {
                $ctor_args = [];
                foreach ($constructor->getParameters() as $n => $parameter) {
                    if (array_key_exists($key = $parameter->getPosition(), $arguments) || array_key_exists($key = $parameter->getName(), $arguments)
                    ) {
                        $ctor_args[$n] = $arguments[$key];
                    }
                    elseif ($this->constructorInjection && !$parameter->isOptional() && $parameter->hasType()) {
                        $ctor_args[$n] = ($this->resolver)($parameter);
                    }
                    elseif ($parameter->isDefaultValueAvailable()) {
                        $ctor_args[$n] = $parameter->getDefaultValue();
                    }
                }
                return $ctor_args;
            }
            return $arguments;
        };
        $propertyInjection    = function ($object) use ($refclass) {
            if ($this->propertyInjection) {
                for ($class = $refclass; $class; $class = $class->getParentClass()) {
                    foreach ($class->getProperties() as $property) {
                        $property->setAccessible(true);
                        if (!$property->isStatic() && !$property->isInitialized($object) && $property->hasType() && !$property->getType()->allowsNull()) {
                            $property->setValue($object, ($this->resolver)($property));
                        }
                    }
                }
            }
            return $object;
        };

        if ($initialize) {
            return $propertyInjection(new $classname(...$constructorInjection($arguments)));
        }

        $object = $refclass->newInstanceWithoutConstructor();
        $this->uninitializedObjects->attach($object, function ($keys) use ($refclass, $object, $arguments, $constructorInjection, $propertyInjection) {
            $this->uninitializedObjects->detach($object);

            if ($constructor = $refclass->getConstructor()) {
                $arguments = $constructorInjection(array_map(fn($v) => $this->factory($keys, $v), $arguments));
                $constructor->invokeArgs($object, $arguments);
            }
            $propertyInjection($object);
        });
        return $object;
    }

    private function resolve($reflection)
    {
        /** @var ReflectionParameter|ReflectionProperty $reflection */
        $type    = $reflection->getType();
        $name    = $reflection->getName();
        $message = function () use ($reflection): string {
            switch (true) {
                case $reflection instanceof ReflectionParameter:
                    return sprintf("failed to resolve $%s in %s::%s", $reflection, $reflection->getDeclaringClass()->getName(), $reflection->getDeclaringFunction()->getName());
                case $reflection instanceof ReflectionProperty:
                    return sprintf("failed to resolve $%s in %s", $reflection, $reflection->getDeclaringClass()->getName());
                default:
                    return strval($reflection); // @codeCoverageIgnore
            }
        };
        $detect  = function (string $id, $entry) {
            if (array_key_exists($id, $this->settled) && is_object($this->settled[$id])) {
                return get_class($this->settled[$id]);
            }
            if (is_object($entry)) {
                return $this->getValueType($entry);
            }
            return null;
        };

        try {
            if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                return $this->get(strtr($name, ['_' => $this->delimiter]));
            }

            $id = @ltrim("$type", '?');
            try {
                $entry = $this->fetch($id);
                if (self::matchReflectionType($detect($id, $entry), $type)) {
                    return $this->get($id);
                }
            }
            catch (NotFoundExceptionInterface $e) {
                // do nothing
            }
            $walk = function (array $array, array $keys, &$found = null) use (&$walk, $detect, $type, $message) {
                foreach ($array as $k => $v) {
                    if (is_array($v)) {
                        $walk($v, array_merge($keys, [$k]), $found);
                    }
                    else {
                        $id = implode($this->delimiter, array_merge($keys, [$k]));
                        if (self::matchReflectionType($detect($id, $v), $type)) {
                            if ($found !== null) {
                                throw self::newContainerException($message());
                            }
                            $found = $id;
                        }
                    }
                }
            };
            $walk($this->entries, [], $found);
            return $this->get($found ?? $id);
        }
        catch (NotFoundExceptionInterface $e) {
            throw self::newContainerException($message());
        }
    }

    private function getValueType($value, bool $withNullable = false): string
    {
        if ($value instanceof Closure) {
            if (isset($this->closureMetadata[$value]->returnType)) {
                return $this->closureMetadata[$value]->returnType;
            }
            $type = (new ReflectionFunction($value))->getReturnType();
            if ($type instanceof ReflectionNamedType) {
                if ($withNullable && $type->allowsNull()) {
                    return '?' . $type->getName();
                }
                return $type->getName();
            }
            // @todo ReflectionUnionType, ReflectionIntersectionType
            return 'void';
        }
        if (is_object($value)) {
            return get_class($value);
        }
        return self::getTypeName($value);
    }

    private function describeValue($value, int $nest = 0): string
    {
        $objects  = [];
        $describe = function ($value, $nest = 0) use (&$describe, &$objects) {
            if (is_array($value)) {
                if (!$value) {
                    return '[]';
                }

                $indent = str_repeat(' ', ($nest + 1) * 4);
                $keys   = array_map($describe, array_combine($keys = array_keys($value), $keys));
                $maxlen = max(array_map('strlen', $keys));
                $kvl    = "\n";
                foreach ($value as $k => $v) {
                    $kvl .= $indent . sprintf("%-{$maxlen}s => %s,\n", $keys[$k], $describe($v, $nest + 1));
                }
                return sprintf("[%s%s]", $kvl, str_repeat(' ', $nest * 4));
            }
            if ($value instanceof Closure) {
                $reffunc = new ReflectionFunction($value);
                $typestr = fn(ReflectionType $type) => @((version_compare(PHP_VERSION, 8.0) < 0 && $type->allowsNull() ? '?' : '') . $type);
                $params  = [];
                foreach ($reffunc->getParameters() as $parameter) {
                    $params[] = implode('', [
                        $parameter->hasType() ? $typestr($parameter->getType()) . ' ' : '',
                        $parameter->isPassedByReference() ? '&' : '',
                        $parameter->isVariadic() ? '...$' : '$',
                        $parameter->getName(),
                        $parameter->isDefaultValueAvailable() ? ' = ' . $describe($parameter->getDefaultValue(), $nest + 1) : '',
                    ]);
                }
                return sprintf('function (%s): %s {%s#%d~%d}',
                    implode(', ', $params),
                    $this->getValueType($value, true),
                    $reffunc->getFileName(),
                    $reffunc->getStartLine(),
                    $reffunc->getEndLine(),
                );
            }
            if (is_object($value)) {
                $oid = get_class($value) . '#' . spl_object_id($value);
                if (isset($objects[$oid])) {
                    return sprintf('%s {%s}', $oid, $objects[$oid]);
                }
                $objects[$oid] = "...";

                $vars = get_mangled_object_vars($value);
                $keys = array_map(fn($key) => substr(strrchr("\0$key", "\0"), 1), array_keys($vars));
                return sprintf('%s {%s}', $oid, substr($describe(array_combine($keys, $vars), $nest), 1, -1));
            }
            return var_export($value, true);
        };
        return $describe($value, $nest);
    }

    private static function matchReflectionType($type, ReflectionType $targetType): bool
    {
        $array_find = function (array $array, callable $condition, bool $not) {
            foreach ($array as $k => $v) {
                if ($condition($v, $k) xor $not) {
                    return $k;
                }
            }
            return null;
        };
        $array_any  = fn(array $array, callable $condition): bool => $array_find($array, $condition, false) !== null;
        $array_all  = fn(array $array, callable $condition): bool => $array_find($array, $condition, true) === null;

        $single_match = function (string $typename, ReflectionType $targetType) use ($array_any, $array_all): bool {
            $match = fn(ReflectionNamedType $type) => is_a($typename, $type->getName(), true);
            switch (true) {
                case $targetType instanceof ReflectionNamedType:
                    return $match($targetType);
                case $targetType instanceof ReflectionUnionType:
                    return $array_any($targetType->getTypes(), $match);
                case $targetType instanceof ReflectionIntersectionType:
                    return $array_all($targetType->getTypes(), $match);
                default:
                    throw self::newContainerException('unknown ReflectionType (%s)', get_class($targetType)); // @codeCoverageIgnore
            }
        };

        switch (true) {
            case is_null($type):
                return false;
            case is_string($type):
                return $single_match($type, $targetType);
            case $type instanceof ReflectionNamedType:
                return $single_match($type->getName(), $targetType);
            case $type instanceof ReflectionUnionType:
                return $array_any($type->getTypes(), fn(ReflectionNamedType $type) => $single_match($type->getName(), $targetType));
            case $type instanceof ReflectionIntersectionType:
                return $array_all($type->getTypes(), fn(ReflectionNamedType $type) => $single_match($type->getName(), $targetType));
            default:
                throw self::newContainerException('unknown ReflectionType (%s)', get_class($type)); // @codeCoverageIgnore
        }
    }

    private static function getTypeName($value): string
    {
        switch (gettype($value)) {
            case 'NULL':
                return 'null';
            case 'boolean':
                return 'bool';
            case 'integer':
                return 'int';
            case 'double':
                return 'float';
            case 'string':
                return 'string';
            case 'array':
                return 'array';
            case 'object':
                if (!(new ReflectionClass($value))->isAnonymous()) {
                    return '\\' . get_class($value);
                }
                $types = class_parents($value) + class_implements($value);
                foreach ($types as $type1) {
                    foreach ($types as $type2) {
                        if ($type1 !== $type2 && is_a($type1, $type2, true)) {
                            unset($types[$type2]);
                        }
                    }
                }
                // @see https://www.jetbrains.com/help/phpstorm/ide-advanced-metadata.html#using-union-types
                return implode('|', array_map(fn($v) => "\\$v", $types)) ?: 'object';
            case 'resource':
            case 'resource (closed)':
                return 'resource';
            default:
                return 'unknown'; // @codeCoverageIgnore
        }
    }

    private static function getArrayType(array $value): string
    {
        if (!$value) {
            return 'array';
        }

        $result = [];
        $types  = [];
        foreach ($value as $k => $v) {
            $key = addslashes($k);
            if ($key !== $k) {
                $key = "\"$key\"";
            }

            $typename         = is_array($v) ? self::getArrayType($v) : self::getTypeName($v);
            $types[$typename] = true;
            $result[]         = "$key: $typename";
        }

        if (count($types) === 1 && $value === array_values($value)) {
            return 'array<' . array_key_first($types) . '>';
        }

        return 'array{' . implode(', ', $result) . '}';
    }

    private static function newContainerException(string $message, ...$args): ContainerExceptionInterface
    {
        return new class ( $args ? vsprintf($message, $args) : $message ) extends LogicException implements ContainerExceptionInterface { };
    }

    private static function newNotFoundException(string $message, ...$args): NotFoundExceptionInterface
    {
        return new class ( $args ? vsprintf($message, $args) : $message ) extends RuntimeException implements NotFoundExceptionInterface { };
    }

    #<editor-fold desc="ArrayAccess">
    public function offsetExists($offset): bool
    {
        return $this->has($offset);
    }

    /** @noinspection PhpLanguageLevelInspection */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)//: mixed
    {
        return $this->get($offset);
    }

    public function offsetSet($offset, $value): void
    {
        $this->set($offset, $value);
    }

    public function offsetUnset($offset): void
    {
        throw self::newContainerException('offsetUnset is not support');
    }
    #</editor-fold>
}
