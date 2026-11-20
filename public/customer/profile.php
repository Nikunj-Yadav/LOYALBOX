<?php
define('AREA', 'customer');
require __DIR__ . '/../../src/bootstrap.php';
$user = require_customer();
$errors = []; $name = $user['name']; $phone = (string)$user['phone'];

if (is_post()) {
    $which = post_str('action', 20);
    if ($which === 'profile') {
        $name = post_str('name', 100); $phone = post_str('phone', 30);
        if ($name === '') { $errors['name'] = 'Enter your name.'; }
        if ($phone !== '' && !preg_match('/^[0-9+()\s-]{6,30}$/', $phone)) { $errors['phone'] = 'Use digits, spaces, + ( ) or - only.'; }
        if (!$errors) {
            q('UPDATE users SET name=?, phone=? WHERE id=?', [$name, $phone ?: null, $user['id']]);
            log_activity('customer', (int)$user['id'], 'update_profile', 'user', (int)$user['id']);
            flash('success', 'Profile saved.'); redirect('profile.php');
        }
    } elseif ($which === 'password') {
        $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['password'] ?? '');
        $hash = (string)scalar('SELECT password_hash FROM users WHERE id=?', [$user['id']]);
        if (too_many('login_failed', 'identifier', mb_strtolower($user['email']), 5, 15)) { $errors['current'] = 'Too many wrong attempts. Wait 15 minutes.'; }
        elseif (!password_verify($cur, $hash)) { security_event('login_failed', (int)$user['id'], mb_strtolower($user['email']), 'Wrong current password'); $errors['current'] = 'Current password is incorrect.'; }
        if ($e = password_error($new)) { $errors['password'] = $e; }
        if ($new !== (string)($_POST['password2'] ?? '')) { $errors['password2'] = 'The passwords do not match.'; }
        if (!$errors) {
            q('UPDATE users SET password_hash=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            session_regenerate_id(true);
            log_activity('customer', (int)$user['id'], 'change_password', 'user', (int)$user['id']);
            flash('success', 'Password changed.'); redirect('profile.php');
        }
    }
}
page_start(['title' => 'Profile', 'subtitle' => 'Your details and password.', 'active' => 'profile', 'who' => $user]); ?>
<div class="two-col stagger">
  <section class="card form-card">
    <h2>Your details</h2>
    <form method="post" class="form" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <?= field(['name' => 'email_ro', 'label' => 'E-mail', 'type' => 'email', 'value' => $user['email'], 'hint' => 'Your e-mail is your sign-in and cannot be changed here.', 'attrs' => ['readonly' => true, 'tabindex' => '-1']]) ?>
      <?= field(['name' => 'name', 'label' => 'Full name', 'value' => $name, 'error' => $errors['name'] ?? '', 'attrs' => ['required' => true, 'maxlength' => 100, 'autocomplete' => 'name']]) ?>
      <?= field(['name' => 'phone', 'label' => 'Phone (optional)', 'type' => 'tel', 'value' => $phone, 'error' => $errors['phone'] ?? '', 'attrs' => ['maxlength' => 30, 'autocomplete' => 'tel', 'pattern' => '[0-9+()\s\-]{6,30}']]) ?>
      <button class="btn btn-primary" type="submit">Save details</button>
    </form>
  </section>
  <section class="card form-card">
    <h2>Change password</h2>
    <form method="post" class="form" novalidate>
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <?= field(['name' => 'current', 'label' => 'Current password', 'type' => 'password', 'error' => $errors['current'] ?? '', 'attrs' => ['required' => true, 'autocomplete' => 'current-password']]) ?>
      <?= field(['name' => 'password', 'label' => 'New password', 'type' => 'password', 'hint' => 'At least 10 characters.', 'error' => $errors['password'] ?? '', 'attrs' => ['required' => true, 'minlength' => 10, 'maxlength' => 72, 'autocomplete' => 'new-password']]) ?>
      <?= field(['name' => 'password2', 'label' => 'Repeat new password', 'type' => 'password', 'error' => $errors['password2'] ?? '', 'attrs' => ['required' => true, 'autocomplete' => 'new-password', 'data-match' => 'password']]) ?>
      <button class="btn" type="submit">Change password</button>
    </form>
  </section>
</div>
<?php page_end();
