<?php
/**
 * OPTIONAL. The defaults already match a fresh XAMPP (user root, empty password), so most people
 * do not need this file. If you set a MySQL password, copy this file to config.local.php and edit it.
 * config.local.php is never committed to Git.
 */
return [
    'DB_HOST' => '127.0.0.1',
    'DB_NAME' => 'loyalbox',
    'DB_USER' => 'root',
    'DB_PASS' => '',
    // 'APP_URL' => 'https://rewards.example.com/public/customer',   // base URL printed in claim links
    // 'CODE_PEPPER' => '',    // leave out: a secret key is created automatically in config/secret.key
];
