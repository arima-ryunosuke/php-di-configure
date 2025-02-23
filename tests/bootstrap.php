<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../vendor/ryunosuke/phpunit-extension/inc/bootstrap.php';

@unlink(__DIR__ . '/../src/Utility.php');
file_put_contents(__DIR__ . '/../src/Utility.php', \ryunosuke\Functions\Transporter::exportClass(\ryunosuke\castella\Utility::class, __DIR__ . '/../src'));

\ryunosuke\PHPUnit\Actual::generateStub(__DIR__ . '/../src', __DIR__ . '/.stub');

if (!class_exists(ReflectionIntersectionType::class)) {
    class ReflectionIntersectionType extends ReflectionType
    {
        private array $types;

        public function __construct(ReflectionNamedType ...$type)
        {
            $this->types = $type;
        }

        public function __toString(): string
        {
            return implode('&', $this->types);
        }

        public function getTypes(): array
        {
            return $this->types;
        }
    }
}

interface I1 { }

interface I2 { }

interface I3 { }

interface I4 { }

interface I5 { }

interface I6 { }

interface I7 { }

class C234 implements I2, I3, I4 { }

class C345 implements I3, I4, I5 { }

class C456 implements I4, I5, I6 { }

class Recursive1
{
    private Recursive2 $buddy;

    public function __construct(Recursive2 $buddy)
    {
        $this->buddy = $buddy;
    }

    public function getBuddy(): Recursive2
    {
        return $this->buddy;
    }
}

class Recursive2
{
    private Recursive1 $buddy;

    public function __construct(Recursive1 $buddy)
    {
        $this->buddy = $buddy;
    }

    public function getBuddy(): Recursive1
    {
        return $this->buddy;
    }
}

class Required
{
    public function __construct(int $dummy_args) { }
}

class Required2
{
    public function __construct(int $dummy_args) { }
}

class NoneResolve
{
    public Required2 $dummy_property;

    public function __construct(Required $dummy_args) { }
}

trait StringableCountable
{
    public function __toString(): string { return ''; }

    public function count(): int { return 0; }
}

class S3Client
{
    public $s3config;

    public function __construct(array $s3_config)
    {
        $this->s3config = $s3_config;
    }
}

class Storage
{
    public $client;
    public $bucket;

    public SplTempFileObject $tmpfile;

    public function __construct(S3Client $s3_client, string $bucket = 'default')
    {
        $this->client = $s3_client;
        $this->bucket = $bucket;
    }
}

class A
{
    private ArrayObject  $fieldA;
    private ?ArrayObject $notInjection = null;
}

class B extends A
{
    private ArrayObject  $fieldB;
    private ?ArrayObject $notInjection = null;
}
