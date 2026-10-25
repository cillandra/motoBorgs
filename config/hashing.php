<?php

return [
    'driver' => 'argon2id',
    'bcrypt' => ['rounds' => 12, 'verify' => true, 'limit' => null],
    'argon' => ['memory' => env('ARGON_MEMORY', 65536), 'threads' => 1, 'time' => env('ARGON_TIME', 3), 'verify' => true],
    'rehash_on_login' => true,
];
