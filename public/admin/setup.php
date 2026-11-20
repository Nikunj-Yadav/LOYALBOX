<?php
/** First-time setup: create the admin account in the browser. Only works while NO admin exists. */
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';

if ((int)scalar('SELECT COUNT(*) FROM admins') > 0) { redirect('login.php'); }

$errors = []; $v = ['name' => '', 'email' => ''];
if (is_post()) {
    $v = ['name' => post_str('name', 100), 'email' => mb_strtolower(post_str('email', 190))];
    $pw = (string)($_POST['password'] ?? '');
    if ($v['name'] === '') { $errors['name'] = 'Enter your name.'; }
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Enter a valid e-mail address.'; }
    if ($e = password_error($pw)) { $errors['password'] = $e; }
    if ($pw !== (string)($_POST['password2'] ?? '')) { $errors['password2'] = 'The passwords do not match.'; }
    if (!$errors) {
        // A database lock makes sure two simultaneous visitors cannot both become "first admin".
        $got = (int)scalar("SELECT GET_LOCK('loyalbox_admin_setup', 5)");
        try {
            if ($got !== 1 || (int)scalar('SELECT COUNT(*) FROM admins') > 0) { redirect('login.php'); }
            q('INSERT INTO admins (name, email, password_hash) VALUES (?,?,?)', [$v['name'], $v['email'], password_hash($pw, PASSWORD_DEFAULT)]);
            $id = (int)db()->lastInsertId();
        } finally {
            if ($got === 1) { scalar("SELECT RELEASE_LOCK('loyalbox_admin_setup')"); }
        }
        session_regenerate_id(true);
        $_SESSION['admin_id'] = $id;
        log_activity('admin', $id, 'create_admin_account', 'admin', $id, 'First-time setup');
        flash('success', 'Admin account created. Welcome!');
        redirect('index.php');
    }
}
page_start(['title' => 'Create admin account', 'bare' => true]); ?>
<section class="card auth-card">
  <h1>Welcome to LoyalBox</h1>
  <p class="subtitle">Create the factory admin account. This page disappears once an admin exists.</p>
  <form method="post" class="form" novalidate>
    <?= csrf_field() ?>
    <?= field(['name' => 'name', 'label' => 'Your name', 'value' => $v['name'], 'error' => $errors['name'] ?? '', 'attrs' => ['required' => true, 'maxlength' => 100, 'autocomplete' => 'name', 'data-autofocus' => true]]) ?>
    <?= field(['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'value' => $v['email'], 'error' => $errors['email'] ?? '', 'attrs' => ['required' => true, 'maxlength' => 190, 'autocomplete' => 'username']]) ?>
    <?= field(['name' => 'password', 'label' => 'Password', 'type' => 'password', 'hint' => 'At least 10 characters.', 'error' => $errors['password'] ?? '', 'attrs' => ['required' => true, 'minlength' => 10, 'maxlength' => 72, 'autocomplete' => 'new-password']]) ?>
    <?= field(['name' => 'password2', 'label' => 'Repeat password', 'type' => 'password', 'error' => $errors['password2'] ?? '', 'attrs' => ['required' => true, 'autocomplete' => 'new-password', 'data-match' => 'password']]) ?>
    <button class="btn btn-primary btn-block" type="submit">Create admin account</button>
  </form>
</section>
<?php page_end();
