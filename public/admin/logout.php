<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
if (is_post()) { session_unset(); session_destroy(); }
redirect('login.php');
