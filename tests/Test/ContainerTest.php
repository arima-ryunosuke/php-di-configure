<?php

namespace ryunosuke\Test;

use A;
use ArrayAccess;
use ArrayObject;
use B;
use C234;
use C345;
use C456;
use Closure;
use Countable;
use Exception;
use I1;
use I2;
use I3;
use I4;
use I5;
use I6;
use I7;
use NoneResolve;
use Recursive1;
use Recursive2;
use ReflectionType;
use Required;
use Required2;
use ryunosuke\castella\Container;
use S3Client;
use SplTempFileObject;
use stdClass;
use Storage;
use Stringable;
use StringableCountable;
use UnexpectedValueException;

class ContainerTest extends AbstractTestCase
{
    function test___construct()
    {
        $container = new Container([
            'debugInfo'            => null,
            'delimiter'            => '@',
            'autowiring'           => false,
            'constructorInjection' => false,
            'propertyInjection'    => false,
            'resolver'             => fn() => 123,
        ]);

        that($container)->delimiter->is('@');
        that($container)->autowiring->is(false);
        that($container)->constructorInjection->is(false);
        that($container)->propertyInjection->is(false);
        that($container)->resolver->try(null)->is(123);

        ob_start();
        var_dump($container);
        $var_dump = ob_get_clean();
        that($var_dump)->stringContains('"entries":"ryunosuke\\castella\\Container":private');
        that($var_dump)->stringContains('"settled":"ryunosuke\\castella\\Container":private');

        $container = new Container([
            'debugInfo' => 'settled',
        ]);
        $container->extends(['x' => 'Z']);

        ob_start();
        var_dump($container);
        $var_dump = ob_get_clean();
        that($var_dump)->stringContains('["x"]');
        that($var_dump)->stringContains('string(1) "Z"');
    }

    function test_extend()
    {
        $container = new Container();
        $container->extends([
            'scalar1' => ['php', 'phtml'],
            'scalar2' => $container->array(['php', 'phtml']),
            'hash1'   => [
                'a' => 'A',
                'b' => 'B',
            ],
        ]);
        $container->extends([
            'scalar1' => ['x', 2 => 'z'],
            'scalar2' => $container->array(['x', 2 => 'z']),
            'hash1'   => [
                'a' => 'A2',
                'c' => 'C',
            ],
        ]);

        that($container[''])->is([
            'scalar1' => ['x', 'phtml', 'z'],
            'scalar2' => ['x', 2 => 'z'],
            'hash1'   => [
                'a' => 'A2',
                'b' => 'B',
                'c' => 'C',
            ],
        ]);
    }

    function test_alias()
    {
        $container = new Container();
        $container->extends([
            'key1 alias1' => 'val1',
            'key array'   => [
                'key2 alias2' => 'val2',
            ],
        ]);
        $container->extends([
            'key1' => 'val3',
            'key'  => [
                'key2' => 'val4',
            ],
        ]);

        that($container['alias1'])->is('val3');
        that($container['alias2'])->is('val4');
        that($container['array'])->is(['key2' => 'val4']);

        $container = new Container();
        $container->extends([
            'key1' => 'val1',
            'key'  => [
                'key2' => 'val2',
            ],
        ]);
        $container->extends([
            'key1 alias1' => 'val3',
            'key array'   => [
                'key2 alias2' => 'val4',
            ],
        ]);

        that($container['alias1'])->is('val3');
        that($container['alias2'])->is('val4');
        that($container['array'])->is(['key2' => 'val4']);

        $container = new Container();
        $container->extends([
            'key1 alias1' => 'val1',
            'key array'   => [
                'key2 alias2' => 'val2',
            ],
        ]);
        $container->extends([
            'key1 alias3' => 'val3',
            'key array'   => [
                'key2 alias4' => 'val4',
            ],
        ]);

        that($container['alias1'])->is('val3');
        that($container['alias2'])->is('val4');
        that($container['alias3'])->is('val3');
        that($container['alias4'])->is('val4');
        that($container['array'])->is(['key2' => 'val4']);
    }

    function test_access()
    {
        $container = new Container();
        $container->extends([
            'int'     => 42,
            'float'   => 3.14,
            'string'  => 'message',
            'array'   => [
                'hoge' => 'HOGE',
            ],
            'object'  => (object) [
                'fuga' => 'FUGA',
            ],
            'closure' => [
                'closureX' => fn($c, $keys) => fn($string) => implode('-', $keys) . ':' . strtoupper($string),
            ],
        ]);
        $container['array.piyo'] = 'PIYO';

        that($container['int'])->is(42);
        that($container['float'])->is(3.14);
        that($container['string'])->is('message');
        that($container['array'])->is(['hoge' => 'HOGE', 'piyo' => 'PIYO']);
        that($container['array.hoge'])->is('HOGE');
        that($container['object'])->is((object) ['fuga' => 'FUGA']);
        that($container['closure.closureX']('hoge'))->is('closureX-closure:HOGE');
        that($container->has('array.hoge'))->isTrue();

        that(isset($container['array.piyo']))->isTrue();
        that($container->has('array.piyo'))->isTrue();
        that($container['array.piyo'])->is('PIYO');
        that($container->get('array.piyo'))->is('PIYO');

        $container['append'] = ['ARRAY'];
        that(isset($container['append']))->isTrue();
        that($container->has('append'))->isTrue();
        that($container['append'])->is(['ARRAY']);
        that($container->get('append'))->is(['ARRAY']);
        that($container['append.0'])->is('ARRAY');
        that($container->get('append.0'))->is('ARRAY');

        that($container->fn('array.hoge'))->isInstanceOf(Closure::class)()->is('HOGE');
    }

    function test_lazy()
    {
        $container = new Container();
        $container->extends([
            'dynamic' => fn() => rand(),
            'static'  => static fn() => rand(),
        ]);

        $dynamic1 = $container->get('dynamic');
        $dynamic2 = $container->get('dynamic');
        $dynamic3 = $container->get('dynamic');
        that($dynamic1)->isNot($dynamic2);
        that($dynamic2)->isNot($dynamic3);
        that($dynamic3)->isNot($dynamic1);

        $static1 = $container->get('static');
        $static2 = $container->get('static');
        $static3 = $container->get('static');
        that($static1)->is($static2);
        that($static2)->is($static3);
        that($static3)->is($static1);
    }

    function test_novalue()
    {
        $container = new Container();
        $container->extends([
            'unset' => $container->unset(),
            'array' => [
                'a'    => 'A',
                'u'    => 'U',
                'z'    => 'Z',
                'nest' => [
                    'a' => 'A',
                    'u' => 'U',
                    'z' => 'Z',
                ],
                'hash' => ['data'],
            ],
        ]);
        $container->extends([
            'array' => [
                'a'    => 'A',
                'u'    => $container->unset(),
                'z'    => 'Z',
                'nest' => [
                    'a' => 'A',
                    'u' => $container->unset(),
                    'z' => 'Z',
                ],
                'hash' => $container->unset(),
            ],
        ]);

        that($container->has('array.u'))->isFalse();
        that($container->has('array.nest.u'))->isFalse();
        that($container->has('array.hash'))->isFalse();
        that($container->get('array'))->is([
            "a"    => "A",
            "z"    => "Z",
            "nest" => [
                "a" => "A",
                "z" => "Z",
            ],
        ]);
    }

    function test_new()
    {
        $container = new Container();
        $container->extends([
            's3'  => [
                'config' => [
                    'version' => 'latest',
                ],
            ],
            'ex'  => $container->static(Exception::class, ['code' => 123, 0 => 'hoge', 2 => null]),
            'ex2' => $container->yield(Exception::class, ['code' => 456, 0 => 'fuga', 2 => $container->fn('ex')]),
            'ao'  => $ao = new ArrayObject(),
        ]);

        that($container->has('S3Client'))->isTrue();
        that($container->has('MyS3Client'))->isFalse();

        that($container->get('S3Client'))->isInstanceOf(S3Client::class);
        that($container->get('Storage'))->isInstanceOf(Storage::class);
        that($container->get('S3Client')->s3config)->is(['version' => 'latest']);
        that($container->get('S3Client'))->isSame($container->get('Storage')->client);

        that($container->get('ex')->getCode())->is(123);
        that($container->get('ex')->getMessage())->is('hoge');
        that($container->get('ex')->getPrevious())->is(null);

        that($container->get('ex2')->getCode())->is(456);
        that($container->get('ex2')->getMessage())->is('fuga');
        that($container->get('ex2')->getPrevious())->isSame($container->get('ex'));

        that($container->get('ex'))->is($container->get('ex'));
        that($container->get('ex2'))->isNotSame($container->get('ex2'));

        that(get_mangled_object_vars($container->new(A::class)))->is([
            "\0A\0fieldA"       => $ao,
            "\0A\0notInjection" => null,
        ]);

        that(get_mangled_object_vars($container->new(B::class)))->is([
            "\0B\0fieldB"       => $ao,
            "\0B\0notInjection" => null,
            "\0A\0fieldA"       => $ao,
            "\0A\0notInjection" => null,
        ]);
    }

    function test_new_recursive()
    {
        $container = new Container();
        $container->extends([
            'r1' => $container->static(Recursive1::class),
            'r2' => $container->static(Recursive2::class),
        ]);

        that($container->get('r1'))->isSame($container->get('r2')->getBuddy());
        that($container->get('r2'))->isSame($container->get('r1')->getBuddy());
    }

    function test_yield_static()
    {
        $yieldCounter  = 0;
        $staticCounter = 0;
        $container     = new Container();
        $container->extends([
            'lazy' => [
                'yield'  => $yield = $container->yield(ArrayObject::class, [
                    function ($c, $keys) use (&$yieldCounter) {
                        $yieldCounter++;
                        return $keys;
                    },
                ]),
                'static' => $static = $container->static(ArrayObject::class, [
                    function ($c, $keys) use (&$staticCounter) {
                        $staticCounter++;
                        return $keys;
                    },
                ]),
            ],
        ]);

        that(@Closure::bind($yield, $this))->isNotNull();
        that(@Closure::bind($static, $this))->isNull();

        that($container->get('lazy.yield'))->is(new ArrayObject(['yield', 'lazy']));
        that($container->get('lazy.static'))->is(new ArrayObject(['static', 'lazy']));

        that($container->get('lazy.yield'))->isNotSame($container->get('lazy.yield'));
        that($container->get('lazy.static'))->isSame($container->get('lazy.static'));

        that($yieldCounter)->is(3);
        that($staticCounter)->is(1);
    }

    function test_callable()
    {
        $container = new Container();
        $container->extends([
            'callable' => [
                'closure'  => $container->callable(fn($n) => $n ** 2),
                'callable' => $container->callable('strtoupper'),
            ],
        ]);

        that($container->get('callable.closure'))->isInstanceOf(Closure::class)(4)->is(16);
        that($container->get('callable.callable'))->isInstanceOf(Closure::class)('hoge')->is('HOGE');
    }

    function test_array()
    {
        $dynamicCounter = 0;
        $staticCounter  = 0;
        $container      = new Container();
        $container->extends([
            'array' => [
                'dynamic' => $container->array([
                    function () use (&$dynamicCounter) {
                        $dynamicCounter++;
                        return func_get_args();
                    },
                ]),
                'static'  => $container->array([
                    static function () use (&$staticCounter) {
                        $staticCounter++;
                        return func_get_args();
                    },
                ]),
            ],
        ]);

        that($container->get('array.dynamic'))->isSame([0 => [$container, ['dynamic', 'array']]]);
        that($container->get('array.static'))->isSame([0 => [$container, ['static', 'array']]]);

        $container->get('array.dynamic');
        $container->get('array.static');

        that($dynamicCounter)->is(2);
        that($staticCounter)->is(1);
    }

    function test_annotate()
    {
        $container = new Container();
        $container->extends([
            'int'            => 42,
            'float'          => 3.14,
            'string'         => 'message',
            'array'          => [
                'hoge' => 'HOGE',
            ],
            'object'         => (object) [
                'fuga' => 'FUGA',
            ],
            'closure'        => fn(): ArrayAccess => new ArrayObject([1, 2, 3]),
            'closureclosure' => fn() => fn($string) => strtoupper($string),
            'alias a1'       => [
                'key a2' => 'value',
            ],
            'NS\\sub\\'      => 'NS\\class',
        ]);

        $phpstorm_meta_php = sys_get_temp_dir() . '/phpstorm.meta.php';
        @unlink($phpstorm_meta_php);
        that($container->annotate($phpstorm_meta_php))->is([
            'int'            => 'int',
            'float'          => 'float',
            'string'         => 'string',
            'array'          => 'array',
            'array.hoge'     => 'string',
            'object'         => 'stdClass',
            'closure'        => 'ArrayObject',
            'closureclosure' => 'Closure',
            'alias'          => 'array',
            'alias.key'      => 'string',
            'a1'             => 'array',
            'a2'             => 'string',
            'NS\\sub\\'      => 'string',
        ]);

        that($phpstorm_meta_php)->fileContainsAll(['PHPSTORM_META', 'closureclosure']);
    }

    function test_dump()
    {
        $container = new Container();
        $container->extends([
            'closure' => static fn() => fn() => 123,
            'object1' => static fn() => (object) ['hoge' => 'HOGE'],
            'object2' => static fn() => $container['object1'],
            'nest A'  => [
                'nest B' => [
                    'nest C' => 'X',
                ],
            ],
        ]);

        that($container->dump('closure'))->stringStartsWith('function (): void');
        that($container->dump('object1'))->stringStartsWith('stdClass#')->notStringStartsWith('{...}');
        that($container->dump('object2'))->stringStartsWith('stdClass#')->notStringStartsWith('{...}');

        that($container->dump())->stringContainsAll(['{...}', 'nest A', 'nest B', 'nest C']);
    }

    function test_factory()
    {
        $container = new Container();

        that($container)->factory(['a', 'b', 'c'], 'scalar')->is('scalar');

        $counter = 0;
        $closure = function ($c, $keys) use (&$counter) {
            $counter++;
            return $keys;
        };
        that($container)->factory(['a', 'b', 'c'], $closure)->is(['a', 'b', 'c']);
        that($container)->factory(['x', 'y', 'z'], $closure)->is(['x', 'y', 'z']);
        that($counter)->is(2);

        $counter = 0;
        $closure = static function ($c, $keys) use (&$counter) {
            $counter++;
            return $keys;
        };
        that($container)->factory(['a', 'b', 'c'], $closure)->is(['a', 'b', 'c']);
        that($container)->factory(['x', 'y', 'z'], $closure)->is(['a', 'b', 'c']);
        that($counter)->is(1);
    }

    function test_instance()
    {
        $container = new Container();

        that($container)->instance(ArrayObject::class, [[1, 2, 3]], true)->is(new ArrayObject([1, 2, 3]));
        that($container)->instance(ArrayObject::class, [[1, 2, 3]], false)->is(new ArrayObject([]));
    }

    function test_resolve()
    {
        $container = new Container();
        $container->extends([
            'scalar'    => 123,
            'object1'   => new C234(),
            'object2'   => static fn(): I5 => new C345(),
            'nest'      => [
                'object1' => new C345(),
                'object2' => static fn(): I6 => new C456(),
            ],
            'mixobject' => new class implements I1, I7 { },
        ]);

        $mock = new class {
            private $name, $type;

            public function getName(): string { return $this->name; }

            public function getType(): ReflectionType { return $this->type; }

            public function __invoke($name, ...$type)
            {
                $this->name = $name;
                $this->type = ContainerTest::getReflectionType(...$type);
                return $this;
            }
        };
        that($container)->resolve($mock('scalar', 'int'))->isSame($container->get('scalar'));
        that($container)->resolve($mock('', C234::class))->isSame($container->get('object1'));
        that($container)->resolve($mock('', C345::class))->isSame($container->get('nest.object1'));
        that($container)->resolve($mock('', I6::class))->isSame($container->get('nest.object2'));
        that($container)->resolve($mock('', stdClass::class))->is(new stdClass());

        that($container)->resolve($mock('', I1::class, I7::class))->isSame($container->get('mixobject'));
        that($container)->resolve($mock('', [I1::class, I7::class]))->isSame($container->get('mixobject'));
    }

    function test_matchReflectionType()
    {
        that(Container::class)::matchReflectionType(null, self::getReflectionType(I1::class))->isFalse();
        that(Container::class)::matchReflectionType(I1::class, self::getReflectionType(I1::class))->isTrue();

        $named = self::getReflectionType(C234::class);
        that(Container::class)::matchReflectionType(I1::class, self::getReflectionType(I1::class))->isTrue();
        that(Container::class)::matchReflectionType($named, self::getReflectionType(I1::class))->isFalse();
        that(Container::class)::matchReflectionType($named, self::getReflectionType(I2::class))->isTrue();
        that(Container::class)::matchReflectionType($named, self::getReflectionType(I3::class))->isTrue();
        that(Container::class)::matchReflectionType($named, self::getReflectionType(I4::class))->isTrue();
        that(Container::class)::matchReflectionType($named, self::getReflectionType(I5::class))->isFalse();
        that(Container::class)::matchReflectionType($named, self::getReflectionType(I6::class))->isFalse();
        that(Container::class)::matchReflectionType($named, self::getReflectionType(I1::class, I2::class))->isTrue();
        that(Container::class)::matchReflectionType($named, self::getReflectionType(I1::class, I3::class))->isTrue();
        that(Container::class)::matchReflectionType($named, self::getReflectionType(I1::class, I4::class))->isTrue();
        that(Container::class)::matchReflectionType($named, self::getReflectionType(I1::class, I5::class))->isFalse();
        that(Container::class)::matchReflectionType($named, self::getReflectionType([I2::class, I3::class]))->isTrue();
        that(Container::class)::matchReflectionType($named, self::getReflectionType([I2::class, I5::class]))->isFalse();

        $union = self::getReflectionType(C234::class, C345::class);
        that(Container::class)::matchReflectionType($union, self::getReflectionType(I1::class))->isFalse();
        that(Container::class)::matchReflectionType($union, self::getReflectionType(I2::class))->isTrue();
        that(Container::class)::matchReflectionType($union, self::getReflectionType(I3::class))->isTrue();
        that(Container::class)::matchReflectionType($union, self::getReflectionType(I4::class))->isTrue();
        that(Container::class)::matchReflectionType($union, self::getReflectionType(I5::class))->isTrue();
        that(Container::class)::matchReflectionType($union, self::getReflectionType(I6::class))->isFalse();
        that(Container::class)::matchReflectionType($union, self::getReflectionType(I1::class, I2::class))->isTrue();
        that(Container::class)::matchReflectionType($union, self::getReflectionType(I1::class, I6::class))->isFalse();
        that(Container::class)::matchReflectionType($union, self::getReflectionType([I2::class, I3::class]))->isTrue();
        that(Container::class)::matchReflectionType($union, self::getReflectionType([I2::class, I6::class]))->isFalse();

        $intersection = self::getReflectionType([C234::class, C345::class]);
        that(Container::class)::matchReflectionType($intersection, self::getReflectionType(I1::class))->isFalse();
        that(Container::class)::matchReflectionType($intersection, self::getReflectionType(I2::class))->isFalse();
        that(Container::class)::matchReflectionType($intersection, self::getReflectionType(I3::class))->isTrue();
        that(Container::class)::matchReflectionType($intersection, self::getReflectionType(I4::class))->isTrue();
        that(Container::class)::matchReflectionType($intersection, self::getReflectionType(I5::class))->isFalse();
        that(Container::class)::matchReflectionType($intersection, self::getReflectionType(I6::class))->isFalse();
        that(Container::class)::matchReflectionType($intersection, self::getReflectionType(I2::class, I3::class))->isTrue();
        that(Container::class)::matchReflectionType($intersection, self::getReflectionType(I1::class, I2::class))->isFalse();
        that(Container::class)::matchReflectionType($intersection, self::getReflectionType([I3::class, I4::class]))->isTrue();
        that(Container::class)::matchReflectionType($intersection, self::getReflectionType([I2::class, I5::class]))->isFalse();
    }

    function test_getTypeName()
    {
        that(Container::class)::getTypeName(null)->is('null');
        that(Container::class)::getTypeName(false)->is('bool');
        that(Container::class)::getTypeName(123)->is('int');
        that(Container::class)::getTypeName(3.14)->is('float');
        that(Container::class)::getTypeName('hoge')->is('string');
        that(Container::class)::getTypeName([null, 123, 3.14, 'hoge'])->is('array');

        that(Container::class)::getTypeName(new ArrayObject())->is(ArrayObject::class);
        that(Container::class)::getTypeName(new Container())->is(Container::class);
        that(Container::class)::getTypeName(new class { })->is('object');
        that(Container::class)::getTypeName(new class implements Stringable { use StringableCountable; })->is('Stringable');
        that(Container::class)::getTypeName(new class extends ArrayObject implements Stringable { use StringableCountable; })->is('ArrayObject|Stringable');
        that(Container::class)::getTypeName(new class extends UnexpectedValueException implements Countable { use StringableCountable; })->is('UnexpectedValueException|Countable');

        $fp = tmpfile();
        that(Container::class)::getTypeName($fp)->is('resource');
        fclose($fp);
        that(Container::class)::getTypeName($fp)->is('resource');
    }

    function test_describeValue()
    {
        that(Container::class)::describeValue([
            'empty' => [],
            'a'     => [
                'b' => [
                    'c' => 'xyz',
                ],
            ],
        ], 2)->is("[
            'empty' => [],
            'a'     => [
                'b' => [
                    'c' => 'xyz',
                ],
            ],
        ]");

        that(Container::class)::describeValue(fn($arg) => [])->stringStartsWith('function ($arg): void ');
        that(Container::class)::describeValue(fn(array $arg = null): ?array => [])->stringStartsWith('function (?array $arg = NULL): ?array ');
        that(Container::class)::describeValue(fn(?array &...$args): ?array => [])->stringStartsWith('function (?array &...$args): ?array ');

        $object = new stdClass();
        $id     = spl_object_id($object);
        that(Container::class)::describeValue([$object, $object])->stringContains("stdClass#$id {...}");
    }

    function test_exception()
    {
        $container = new Container();
        $container->extends([
            'unsetted-entry' => $container->unset(),
            'array'          => [],
            'scalar'         => 123,
        ]);

        that($container)->extends(['array' => null])->isThrowable('is not array');
        that($container)->extends(['array' => null])->isThrowable('is not array');
        that($container)->has('scalar.hoge')->isThrowable('is not array');

        $container->get('scalar');
        that($container)->set('scalar', 'changed')->isThrowable('is already settled');

        that(function () use ($container) { unset($container['scalar.hoge']); })()->isThrowable('is not support');

        that($container)->get('undefined-entry')->isThrowable('undefined config key');
        that($container)->get('unsetted-entry')->isThrowable('unsetted config key');

        that($container)->get(NoneResolve::class)->isThrowable('in Required::__construct');
        $container[1] = new Required(1);
        $container[2] = new Required2(2);
        $container[3] = new Required2(3);
        that($container)->get(NoneResolve::class)->isThrowable('in NoneResolve');
    }

    function test_integration()
    {
        $container = new Container();
        $container->include(__DIR__ . '/files/config1.php');
        $container->include(__DIR__ . '/files/config2.php');
        $container->set(SplTempFileObject::class, $container->yield(SplTempFileObject::class));

        $env    = $container['env'];
        $logger = $env['logger'];
        unset($env['logger']);
        that($logger->__debugInfo())->is([
            "loglevel"  => "debug",
            "directory" => "/var/log/app",
        ]);
        that($env)->is([
            'name'      => 'local',
            'origin'    => 'http://localhost',
            'loglevel'  => 'debug',
            'logdir'    => '/var/log/app',
            'rundir'    => '/var/run/app',
            'datadir'   => '/var/opt/app',
            'extension' => ['js'],
        ]);

        $database = $container['database'];
        $logger   = $database['logger'];
        unset($database['logger']);
        that($logger->__debugInfo())->is([
            "loglevel"  => "error",
            "directory" => "/var/log/app",
        ]);
        that($database)->is([
            'driver'        => 'pdo_mysql',
            'host'          => '127.0.0.1',
            'port'          => 3306,
            'dbname'        => 'app',
            'user'          => 'app',
            'password'      => 'p@ssword',
            'charset'       => 'utf8mb4',
            'connect'       => function () { },
            'driverOptions' => [
                \PDO::ATTR_ERRMODE           => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_EMULATE_PREPARES  => false,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
            'loglevel'      => 'debug',
        ]);

        that($container['s3.config'])->is([
            'region'  => 'ap-northeast-1',
            'version' => '2006-03-01',
        ]);

        that($container['storage.private']->bucket)->is('private');
        that($container['storage.protect']->bucket)->is('protect');
        that($container['storage.public']->bucket)->is('public');
        that($container['storage.local1']->bucket)->is('local1');
        that($container['storage.local2']->bucket)->is('local2');
        that($container['storage.local3']->bucket)->is('default');

        that($container['storage.private']->client)->isSame($container['s3.client']);
        that($container['storage.protect']->client)->isSame($container['s3.client']);
        that($container['storage.public']->client)->isSame($container['s3.client']);
        that($container['storage.local1']->client)->isSame($container['s3.client']);
        that($container['storage.local2']->client)->isSame($container['s3.client']);
        that($container['storage.local3']->client)->isSame($container['s3.client']);

        that($container['storage.local1']->tmpfile)->isNotSame($container[SplTempFileObject::class]);
        that($container['storage.local2']->tmpfile)->isNotSame($container[SplTempFileObject::class]);
        that($container['storage.local3']->tmpfile)->isNotSame($container[SplTempFileObject::class]);

        that($container['storage.protect'])->isNotSame($container['storage.private']);
        that($container['storage.public'])->isNotSame($container['storage.private']);
        that($container['storage.local1'])->isNotSame($container['storage.private']);
        that($container['storage.local2'])->isNotSame($container['storage.private']);
        that($container['storage.local3'])->isNotSame($container['storage.private']);

        that($container['chain.x'])->isSame($container['chain.y']);
        that($container['chain.y'])->isSame($container['chain.z']);
        that($container['chain.z'])->isSame($container['chain.x']);

        $phpstorm_meta_php = __DIR__ . '/files/.phpstorm.meta.php';
        @unlink($phpstorm_meta_php);
        $container->annotate($phpstorm_meta_php);
        $first = file_get_contents($phpstorm_meta_php);
        $container->annotate($phpstorm_meta_php);
        $second = file_get_contents($phpstorm_meta_php);
        that($first)->is($second);
    }
}
