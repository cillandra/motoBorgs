<?php

return [
    'root' => env('MOTO_BACKUP_ROOT', 'C:/xampp/moto-runtime/backups'),
    'operations' => env('MOTO_OPERATION_ROOT', base_path('.runtime')),
    'lock_timeout' => 5,
    'max_archive_bytes' => 268435456,
    'max_uncompressed_bytes' => 536870912,
    'max_entries' => 20002,
    'dump_binary' => env('MOTO_DUMP_BINARY', 'C:/xampp/mysql/bin/mysqldump.exe'),
    'client_binary' => env('MOTO_CLIENT_BINARY', 'C:/xampp/mysql/bin/mysql.exe'),
];
