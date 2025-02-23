<?php

namespace cache;

/**
 * @var \ryunosuke\castella\Container $this
 */

return [
    'array' => $this->parent(function ($array) {
        $array['b'] = 'B';
        return $array;
    }),
    'const' => $this->const('const2', 'CNAME2'),
];
