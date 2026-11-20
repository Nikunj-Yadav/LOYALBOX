<?php
define('AREA', 'admin');
require __DIR__ . '/../../src/bootstrap.php';
if ((int)scalar('SELECT COUNT(*) FROM admins') === 0) { redirect('setup.php'); }   // brand-new install: create the admin first
if (current_admin()) { redirect('index.php'); }
$errors = []; $email = '';
if (is_post()) {
    $email = mb_strtolower(post_str('email', 190));
    $password = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Enter a valid e-mail address.'; }
    if ($password === '') { $errors['password'] = 'Enter your password.'; }
    if (!$errors) {
        $r = check_login('admins', $email, $password);
        if ($r['ok']) {
            $_SESSION['admin_id'] = (int)$r['row']['id'];
            log_activity('admin', (int)$r['row']['id'], 'login', 'admin', (int)$r['row']['id']);
            redirect('index.php');
        }
        $errors['form'] = $r['error'];
    }
}
page_start(['title' => 'Admin sign in', 'bare' => true]); ?>
<section class="card auth-card">
  <h1>Factory admin</h1>
  <p class="subtitle">Sign in to manage products, codes and rewards.</p>
  <?php if (!empty($errors['form'])): ?><p class="form-error" role="alert"><?= e($errors['form']) ?></p><?php endif; ?>
  <form method="post" class="form" novalidate>
    <?= csrf_field() ?>
    <?= field(['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'value' => $email, 'error' => $errors['email'] ?? '', 'attrs' => ['required' => true, 'autocomplete' => 'username', 'maxlength' => 190]]) ?>
    <?= field(['name' => 'password', 'label' => 'Password', 'type' => 'password', 'error' => $errors['password'] ?? '', 'attrs' => ['required' => true, 'autocomplete' => 'current-password']]) ?>
    <button class="btn btn-primary btn-block" type="submit">Sign in</button>
  </form>
</section>
<?php page_end();
