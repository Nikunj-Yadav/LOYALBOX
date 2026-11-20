<?php
define('AREA', 'customer');
require __DIR__ . '/../../src/bootstrap.php';

if (current_user()) { redirect('index.php'); }
$errors = []; $email = '';

if (is_post()) {
    $email = mb_strtolower(post_str('email', 190));
    $password = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Enter a valid e-mail address.'; }
    if ($password === '') { $errors['password'] = 'Enter your password.'; }
    if (!$errors) {
        $r = check_login('users', $email, $password);
        if ($r['ok']) {
            $_SESSION['user_id'] = (int)$r['row']['id'];
            log_activity('customer', (int)$r['row']['id'], 'login', 'user', (int)$r['row']['id']);
            redirect('index.php');
        }
        $errors['form'] = $r['error'];
    }
}
page_start(['title' => 'Sign in', 'bare' => true]); ?>
<section class="card auth-card">
  <h1>Welcome back</h1>
  <p class="subtitle">Sign in to see your points and rewards.</p>
  <?php if (!empty($errors['form'])): ?><p class="form-error" role="alert"><?= e($errors['form']) ?></p><?php endif; ?>
  <form method="post" class="form" novalidate>
    <?= csrf_field() ?>
    <?= field(['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'value' => $email, 'error' => $errors['email'] ?? '', 'attrs' => ['required' => true, 'autocomplete' => 'email', 'maxlength' => 190]]) ?>
    <?= field(['name' => 'password', 'label' => 'Password', 'type' => 'password', 'error' => $errors['password'] ?? '', 'attrs' => ['required' => true, 'autocomplete' => 'current-password']]) ?>
    <button class="btn btn-primary btn-block" type="submit">Sign in</button>
  </form>
  <p class="auth-alt">New here? <a href="register.php">Create an account</a></p>
</section>
<?php page_end();
