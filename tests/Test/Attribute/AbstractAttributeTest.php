<?php

namespace ryunosuke\Test\Attribute;

use Attribute;
use ryunosuke\castella\Attribute\AbstractAttribute;
use ryunosuke\Test\AbstractTestCase;

class AbstractAttributeTest extends AbstractTestCase
{
    function test_single()
    {
        $object = new #[MockAttribute(1)] class { };
        that(MockAttribute::single($object))->isNotNull();

        $object = new class { };
        that(MockAttribute::single($object))->isNull();
    }

    function test_getArguments()
    {
        $object = new #[MockAttribute(1)] class { };
        $attr   = MockAttribute::single($object);
        that($attr->newInstance()->getArguments())->is(['a' => 1, 'b' => 2, 'c' => 3]);

        $object = new #[MockAttribute(a: 7, c: 9)] class { };
        $attr   = MockAttribute::single($object);
        that($attr->newInstance()->getArguments())->is(['a' => 7, 'b' => 2, 'c' => 9]);
    }
}

#[Attribute(Attribute::TARGET_ALL)]
class MockAttribute extends AbstractAttribute
{
    public function __construct(private int $a, private int $b = 2, private int $c = 3) { }
}
