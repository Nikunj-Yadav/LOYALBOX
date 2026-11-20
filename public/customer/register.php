<?php
define('AREA', 'customer');
require __DIR__ . '/../../src/bootstrap.php';

if (current_user()) { redirect('index.php'); }
$errors = []; $v = ['name' => '', 'email' => '', 'phone' => ''];

if (is_post()) {
    $v = ['name' => post_str('name', 100), 'email' => mb_strtolower(post_str('email', 190)), 'phone' => post_str('phone', 30)];
    $pw = (string)($_POST['password'] ?? '');
    if ($v['name'] === '') { $errors['name'] = 'Enter your name.'; }
    if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'Enter a valid e-mail address.'; }
    if ($v['phone'] !== '' && !preg_match('/^[0-9+()\s-]{6,30}$/', $v['phone'])) { $errors['phone'] = 'Use digits, spaces, + ( ) or - only.'; }
    if ($e = password_error($pw)) { $errors['password'] = $e; }
    if ($pw !== (string)($_POST['password2'] ?? '')) { $errors['password2'] = 'The passwords do not match.'; }
    if (!$errors && too_many('register', 'ip', client_ip(), 5, 60)) {
        $errors['form'] = 'Too many sign-ups from this network. Try again in an hour.';
    }
    if (!$errors) {
        security_event('register', null, $v['email'], 'Registration attempt');
        try {
            q('INSERT INTO users (name, email, phone, password_hash) VALUES (?,?,?,?)',
              [$v['name'], $v['email'], $v['phone'] ?: null, password_hash($pw, PASSWORD_DEFAULT)]);
            $id = (int)db()->lastInsertId();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $id;
            log_activity('customer', $id, 'register', 'user', $id);
            flash('success', 'Account created. Welcome to LoyalBox!');
            redirect('index.php');
        } catch (PDOException $ex) {
            if (($ex->errorInfo[1] ?? 0) !== 1062) { throw $ex; }
            $errors['email'] = 'An account with this e-mail already exists.';
        }
    }
}
page_start(['title' => 'Create account', 'bare' => true]); ?>
<section class="card auth-card">
  <h1>Create your account</h1>
  <p class="subtitle">Scan the code inside a box and turn it into rewards.</p>
  <?php if (!empty($errors['form'])): ?><p class="form-error" role="alert"><?= e($errors['form']) ?></p><?php endif; ?>
  <form method="post" class="form" novalidate>
    <?= csrf_field() ?>
    <?= field(['name' => 'name', 'label' => 'Full name', 'value' => $v['name'], 'error' => $errors['name'] ?? '', 'attrs' => ['required' => true, 'autocomplete' => 'name', 'maxlength' => 100]]) ?>
    <?= field(['name' => 'email', 'label' => 'E-mail', 'type' => 'email', 'value' => $v['email'], 'error' => $errors['email'] ?? '', 'attrs' => ['required' => true, 'autocomplete' => 'email', 'maxlength' => 190]]) ?>
    <?= field(['name' => 'phone', 'label' => 'Phone (optional)', 'type' => 'tel', 'value' => $v['phone'], 'error' => $errors['phone'] ?? '', 'attrs' => ['autocomplete' => 'tel', 'maxlength' => 30, 'pattern' => '[0-9+()\s\-]{6,30}']]) ?>
    <?= field(['name' => 'password', 'label' => 'Password', 'type' => 'password', 'hint' => 'At least 10 characters.', 'error' => $errors['password'] ?? '', 'attrs' => ['required' => true, 'minlength' => 10, 'maxlength' => 72, 'autocomplete' => 'new-password']]) ?>
    <?= field(['name' => 'password2', 'label' => 'Repeat password', 'type' => 'password', 'error' => $errors['password2'] ?? '', 'attrs' => ['required' => true, 'autocomplete' => 'new-password', 'data-match' => 'password']]) ?>
    <button class="btn btn-primary btn-block" type="submit">Create account</button>
  </form>
  <p class="auth-alt">Already registered? <a href="login.php">Sign in</a></p>
</section>
<?php page_end();
