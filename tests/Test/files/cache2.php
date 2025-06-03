<?php

namespace cache;

/**
 * @var \ryunosuke\castella\Container $this
 */

return [
    'string'     => "{$this['string']}/child",
    'array' => $this->parent(function ($array) {
        $array['b'] = 'B';
        return $array;
    }),
    'const' => $this->const('const2', 'CNAME2'),
];
