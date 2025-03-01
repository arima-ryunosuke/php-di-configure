<?php

namespace ryunosuke\castella\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_FUNCTION)]
class Factory extends AbstractAttribute
{
    public function __construct(private bool $once = true) { }
}
