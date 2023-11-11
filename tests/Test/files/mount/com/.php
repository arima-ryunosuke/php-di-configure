<?php

return [
    'config' => [
        'file' => $this->parent(fn($parent) => array_merge($parent, [__FILE__])),
        'com'  => 'com',
        'name' => 'com',
    ],
];
