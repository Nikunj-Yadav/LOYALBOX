<?php
define('AREA', 'customer');
require __DIR__ . '/../../src/bootstrap.php';
$user = require_customer();

$error = ''; $code = get_str('code', 40);
if (is_post()) {
    $code = post_str('code', 40);
    if ($code === '') {
        $error = 'Enter the code from inside your box.';
    } else {
        $r = claim_code((int)$user['id'], $code);
        if ($r['ok']) {
            flash('success', '+' . fmt_num($r['points']) . ' points added for ' . $r['product'] . '.');
            redirect('claim.php');
        }
        $error = $r['error'];
    }
}
page_start(['title' => 'Claim a product', 'subtitle' => 'Enter the code printed inside the box.', 'active' => 'claim', 'who' => $user]); ?>
<section class="card form-card stagger">
  <form method="post" class="form" novalidate>
    <?= csrf_field() ?>
    <?= field(['name' => 'code', 'label' => 'Product code', 'value' => $code, 'error' => $error, 'hint' => 'Looks like XXXX-XXXX-XXXX-XXXX. Letters are not case sensitive.',
        'attrs' => ['required' => true, 'autocomplete' => 'off', 'autocapitalize' => 'characters', 'spellcheck' => 'false', 'maxlength' => 40, 'class' => 'code-input', 'data-autofocus' => true]]) ?>
    <button class="btn btn-primary" type="submit">Claim points</button>
  </form>
  <p class="note"><?= icon('info') ?>A code proves you hold a valid, unused code from a product box. It does not prove a retail purchase, and the factory may check claims that look unusual.</p>
</section>
<?php page_end();
