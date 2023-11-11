<?php

return [
    'config' => [
        'file' => $this->parent(fn($parent) => array_merge($parent, [__FILE__])),
        'name' => 'host.example.net',
    ],
];
