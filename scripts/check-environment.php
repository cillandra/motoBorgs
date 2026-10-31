<?php

if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, "PHP 8.4 or newer is required.\n");
    exit(1);
}

foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'gd', 'intl', 'zip'] as $extension) {
    if (! extension_loaded($extension)) {
        fwrite(STDERR, 'Missing extension: '.$extension."\n");
        exit(1);
    }
}

fwrite(STDOUT, "MOTO PHP extensions ready.\n");
