<?php
define('AREA', 'customer');
require __DIR__ . '/../../src/bootstrap.php';
if (is_post()) {                 // POST + CSRF token, so a stray link cannot sign people out
    session_unset(); session_destroy();
}
redirect('login.php');
