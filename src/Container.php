<?php

namespace ryunosuke\castella;

use ArrayAccess;
use Closure;
use FilesystemIterator;
use LogicException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use ryunosuke\castella\Attribute\Entry;
use ryunosuke\castella\Attribute\Factory;
use stdClass;
use Throwable;
use WeakMap;

class Container implements ContainerInterface, ArrayAccess
{
    /** future scope public const NOVALUE = new stdClass(); */
    private static object $novalue;

    private bool $including = false;

    private ?string $debugInfo;
    private string  $delimiter;
    private bool    $closureAsFactory;
    private bool    $autowiring;
    private bool    $constructorInjection;
    private bool    $propertyInjection;
    private Closure $resolver;

    private WeakMap $uninitializedObjects;
    private WeakMap $closureMetadata;

    private array $entries = [];
    private array $settled = [];
    private array $aliases = [];

    public function __construct(array $options = [])
    {
        self::$novalue ??= new stdClass();

        $this->debugInfo            = $options['debugInfo'] ?? null;
        $this->delimiter            = $options['delimiter'] ?? '.';
        $this->closureAsFactory     = $options['closureAsFactory'] ?? true; // for compatible. to false or delete in future scope
        $this->autowiring           = $options['autowiring'] ?? true;
        $this->constructorInjection = $options['constructorInjection'] ?? true;
        $this->propertyInjection    = $options['propertyInjection'] ?? true;
        $this->resolver             = Closure::bind(Closure::fromCallable($options['resolver'] ?? [$this, 'resolve']), $this, $this);

        $this->uninitializedObjects = new WeakMap();
        $this->closureMetadata      = new WeakMap();
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

    public function __isset(string $name): bool
    {
        return $this->has($name);
    }

    public function __get(string $name): mixed
    {
        return $this->get($name);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->set($name, $value);
    }

    public function __unset(string $name): void
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
                    if ($v !== self::$novalue) {
                        $ctype = $this->getValueType($current);
                        $vtype = $this->getValueType($v);
                        if (($ctype !== 'unsettled' && $vtype !== 'unsettled') && ($ctype === 'array' xor $vtype === 'array')) {
                            throw self::newContainerException("%s is not array", $id);
                        }
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

    public function mount(string $directory, ?array $pathes = null, ?string $user = null): self
    {
        $rdi = new RecursiveDirectoryIterator($directory,
            FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS | FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_SELF
        );
        $rii = new RecursiveIteratorIterator($rdi,
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $files = [];
        foreach ($rii as $path => $file) {
            /** @var RecursiveDirectoryIterator $file */
            $files[preg_replace('#/\\.|/#', '.', $file->getSubPathname())] = $path;
        }

        $pathes ??= array_reverse(explode('.', gethostname()));
        array_unshift($pathes, ''); // for $directory.php

        $user ??= $this->env('USER', 'USERNAME');

        $current = '';
        foreach ($pathes as $path) {
            $current = strlen($current) ? "$current.$path" : $path;
            if (isset($files[$fn = "$current.php"])) {
                $this->include($files[$fn]);
            }
            if (isset($user, $files[$fn = "$current@$user.php"])) {
                $this->include($files[$fn]);
            }
        }

        return $this;
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
            return new LazyValue($this, $id);
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
        catch (NotFoundExceptionInterface) {
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

    public function cache(string $filename, Closure $initializer): bool
    {
        if (is_readable($filename)) {
            assert(!$this->entries);
            // if debug mode, fall through for syntax check
            $debugMode = require $filename;
            if (!$debugMode) {
                return true;
            }
        }

        $debugMode = $initializer($this, $filename) ?? false;

        $export = function ($value, $nest = 0, $parents = []) use (&$export) {
            $spacer0    = str_repeat(" ", 4 * max(0, $nest + 0));
            $spacer1    = str_repeat(" ", 4 * max(0, $nest + 1));
            $raw_export = fn($v) => $v;
            $var_export = fn($v) => var_export($v, true);

            if ($value === $this) {
                return "\$this";
            }
            if (is_array($value)) {
                $value = array_filter($value, fn($v) => $v !== self::$novalue);
                if (!$value) {
                    return '[]';
                }

                $keys   = array_map($var_export, array_combine($keys = array_keys($value), $keys));
                $maxlen = max(array_map('strlen', $keys ?: ['']));
                $kvl    = [];
                foreach ($value as $k => $v) {
                    $keystr = $keys[$k] . str_repeat(" ", $maxlen - strlen($keys[$k])) . " => ";
                    $valstr = $export($v, $nest + 1, array_merge($parents, [$k]));
                    $kvl[]  = $keystr . $valstr;
                }
                $kvl = implode(",\n{$spacer1}", $kvl);
                return "[\n{$spacer1}{$kvl},\n{$spacer0}]";
            }
            if ($value instanceof Closure) {
                if (($this->closureMetadata[$value]->returnType ?? null) === 'unsettled') {
                    return $export($value(...$parents), $nest, $parents);
                }
                $ref  = new ReflectionFunction($value);
                $bind = $ref->getClosureThis();
                assert($bind === null || $bind === $this);

                [$meta, $body] = Utility::callable_code($value);
                $arrow  = str_starts_with($meta, 'fn') ? ' => ' : ' ';
                $tokens = array_slice(Utility::php_tokens("<?php $meta{$arrow}$body;", TOKEN_PARSE), 1, -1);

                $statics = $ref->getStaticVariables(); // to getClosureUsedVariables in future scope
                $uses    = [];
                foreach ($tokens as $n => $token) {
                    if ($token->id === T_VARIABLE) {
                        $varname = substr($token->text, 1);
                        if (array_key_exists($varname, $statics) && !isset($uses[$varname])) {
                            $recurself      = $statics[$varname] === $value ? '&' : '';
                            $uses[$varname] = "$spacer1\$$varname = $recurself{$export($statics[$varname], $nest + 1, $parents)};\n";
                        }
                    }

                    $tokens[$n] = $token->clone(text: $token->resolve($ref));
                }

                $code = implode('', array_column($tokens, 'text'));
                if (!@$value->bindTo($this)) {
                    $code = "static $code";
                }

                $attrs = [];
                foreach ($ref->getAttributes() as $attr) {
                    $attrs[] = "#[{$raw_export($attr->getName())}({$raw_export(implode(', ', array_map($export, $attr->getArguments())))})]";
                }
                $attrs = $attrs ? (implode(' ', $attrs) . ' ') : '';
                $code  = "$attrs$code";

                if (!$uses) {
                    return $code;
                }
                return "(function () {\n{$raw_export(implode('', $uses))}{$spacer1}return $code;\n$spacer0})()";
            }
            if (is_object($value)) {
                if ($value instanceof LazyValue) {
                    return $export($value->___resolve(), $nest, $parents);
                }
                if (get_class($value) === stdClass::class) {
                    return "(object) {$export((array) $value, $nest, $parents)}";
                }
                /** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
                if ($value instanceof \UnitEnum) {
                    return "\\" . $var_export($value); // @codeCoverageIgnore under php8.1
                }

                throw self::newContainerException('[%s] cache is not supported Object. use fn() => Object', implode($this->delimiter, $parents));
            }
            if (is_resource($value)) {
                throw self::newContainerException('[%s] cache is not supported Resource. use fn() => Resource', implode($this->delimiter, $parents));
            }

            return is_null($value) ? 'null' : $var_export($value);
        };

        $constants = implode("\n", array_map(fn($v, $k) => "define({$export($k)}, {$export($v)});", $c = $this->constants(), array_keys($c)));

        file_put_contents($filename, <<<PHP
            <?php
            // constants
            $constants
            // aliases
            \$this->aliases = {$export($this->aliases, 0)};
            // entries
            \$this->entries = {$export($this->entries, 0)};
            // debugMode
            return {$export($debugMode)};
            PHP, LOCK_EX);
        return false;
    }

    public function define(): array
    {
        $constants = $this->constants();
        foreach ($constants as $cname => $value) {
            define($cname, $value);
        }
        return $constants;
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

    public function const(mixed $value, ?string $name = null): Closure
    {
        $closure                         = #[Factory(true)] fn() => $value;
        $this->closureMetadata[$closure] = (object) [
            'dynamic' => false,
            'const'   => $name,
        ];
        return $closure;
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
        $closure                         = #[Factory(false)] fn() => $this->instance($classname, $arguments, false);
        $this->closureMetadata[$closure] = (object) [
            'dynamic'    => true,
            'returnType' => ltrim($classname, '\\'),
        ];
        return $closure;
    }

    public function static(string $classname, array $arguments = []): Closure
    {
        $closure                         = #[Factory(true)] fn() => $this->instance($classname, $arguments, false);
        $this->closureMetadata[$closure] = (object) [
            'dynamic'    => false,
            'returnType' => ltrim($classname, '\\'),
        ];
        return $closure;
    }

    public function parent(callable $callback): Closure
    {
        $parents                         = $this->entries;
        $closure                         = #[Factory(true)] function (...$keys) use ($callback, $parents) {
            $entry = $parents;
            foreach (array_reverse($keys) as $key) {
                $entry = $entry[$key] ?? null;
            }
            return $callback($this->factory($keys, $entry));
        };
        $this->closureMetadata[$closure] = (object) [
            'dynamic'    => false,
            'returnType' => 'unsettled',
        ];
        return $closure;
    }

    public function callable(callable $entry): Closure
    {
        return #[Factory(true)] fn(): Closure => Closure::fromCallable($entry);
    }

    public function array(array $entry): Closure
    {
        return #[Factory(false)] fn(...$keys): array => array_map(fn($v) => $this->factory($keys, $v), $entry);
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
        if (is_object($entry) && isset($this->uninitializedObjects[$entry])) {
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

    private function constants(): array
    {
        $array_find_recursive = function ($array, $keys) use (&$array_find_recursive) {
            $founds = [];
            foreach ($array as $key => $value) {
                if (is_array($value)) {
                    $founds += $array_find_recursive($value, array_merge($keys, [$key]));
                }
                elseif ($value instanceof Closure && isset($this->closureMetadata[$value])) {
                    if (property_exists($this->closureMetadata[$value], 'const')) {
                        $id          = implode($this->delimiter, array_merge($keys, [$key]));
                        $founds[$id] = $this->closureMetadata[$value];
                    }
                }
            }
            return $founds;
        };

        $constants = [];
        foreach ($array_find_recursive($this->entries, []) as $id => $metadata) {
            $cname = $metadata->const ?? strtr(strtoupper($id), [$this->delimiter => '\\']);
            $value = $this->get($id);

            $constants[$cname] = $value;
        }

        return $constants;
    }

    private function factory(array $keys, $entry, ?bool &$dynamic = false)
    {
        if ($entry instanceof LazyValue) {
            $entry = $entry->___resolve();
        }

        if (!$entry instanceof Closure) {
            return $entry;
        }

        $entryAttr   = Entry::single($entry);
        $factoryAttr = Factory::single($entry);

        if ((!$this->closureAsFactory && !$factoryAttr) || ($this->closureAsFactory && $entryAttr)) {
            return $entry;
        }

        $metadata = $this->closureMetadata[$entry] ??= (object) [];
        $dynamic  = $metadata->dynamic ??= !(($factoryAttr?->newInstance()?->getArguments()['once'] ?? !@$entry->bindTo($this)));

        if (!$dynamic && property_exists($metadata, 'result')) {
            return $metadata->result;
        }

        if ($factoryAttr) {
            return $metadata->result = $entry->call($this, ...$keys);
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

        $object                              = $refclass->newInstanceWithoutConstructor();
        $this->uninitializedObjects[$object] = function ($keys) use ($refclass, $object, $arguments, $constructorInjection, $propertyInjection) {
            unset($this->uninitializedObjects[$object]);

            if ($constructor = $refclass->getConstructor()) {
                $arguments = $constructorInjection(array_map(fn($v) => $this->factory($keys, $v), $arguments));
                $constructor->invokeArgs($object, $arguments);
            }
            $propertyInjection($object);
        };
        return $object;
    }

    private function resolve($reflection)
    {
        /** @var ReflectionParameter|ReflectionProperty $reflection */
        $type    = $reflection->getType();
        $name    = $reflection->getName();
        $message = function () use ($reflection): string {
            return match (true) {
                $reflection instanceof ReflectionParameter => vsprintf("failed to resolve $%s in %s::%s", [
                    $reflection,
                    $reflection->getDeclaringClass()->getName(),
                    $reflection->getDeclaringFunction()->getName(),
                ]),
                $reflection instanceof ReflectionProperty  => vsprintf("failed to resolve $%s in %s", [
                    $reflection,
                    $reflection->getDeclaringClass()->getName(),
                ]),
                default                                    => strval($reflection), // @codeCoverageIgnore
            };
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
            catch (NotFoundExceptionInterface) {
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
        catch (NotFoundExceptionInterface) {
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
            if ($type instanceof ReflectionUnionType) {
                return $type;
            }
            // @todo ReflectionIntersectionType
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
                $params  = [];
                foreach ($reffunc->getParameters() as $parameter) {
                    $params[] = implode('', [
                        $parameter->hasType() ? $parameter->getType() . ' ' : '',
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
            return match (true) {
                $targetType instanceof ReflectionNamedType        => $match($targetType),
                $targetType instanceof ReflectionUnionType        => $array_any($targetType->getTypes(), $match),
                $targetType instanceof ReflectionIntersectionType => $array_all($targetType->getTypes(), $match),
                default                                           => throw self::newContainerException('unknown ReflectionType (%s)', get_class($targetType)), // @codeCoverageIgnore
            };
        };

        return match (true) {
            is_null($type)                              => false,
            is_string($type)                            => $single_match($type, $targetType),
            $type instanceof ReflectionNamedType        => $single_match($type->getName(), $targetType),
            $type instanceof ReflectionUnionType        => $array_any($type->getTypes(), fn(ReflectionNamedType $type) => $single_match($type->getName(), $targetType)),
            $type instanceof ReflectionIntersectionType => $array_all($type->getTypes(), fn(ReflectionNamedType $type) => $single_match($type->getName(), $targetType)),
            default                                     => throw self::newContainerException('unknown ReflectionType (%s)', get_class($type)), // @codeCoverageIgnore
        };
    }

    private static function getTypeName($value): string
    {
        $type = gettype($value);
        return match (true) {
            $type === 'object'                 => (function ($value) {
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
            })($value),
            str_starts_with($type, 'resource') => 'resource',
            default                            => get_debug_type($value),
        };
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
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get($offset);
    }

    public function offsetSet(mixed $offset, $value): void
    {
        $this->set($offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        throw self::newContainerException('offsetUnset is not support');
    }
    #</editor-fold>
}
