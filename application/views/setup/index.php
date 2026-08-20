<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Deliberately standalone markup: no layout, no design-system CSS, no settings
 * lookups. This page has to render on a deployment where the database is not
 * reachable yet — which is exactly when someone needs to read it.
 *
 * @var string $token
 * @var array  $checks
 * @var string $error
 * @var string $success
 * @var array  $form
 */
$checks  = isset($checks) ? $checks : array();
$form    = isset($form) ? $form : array();
$failed  = 0;
foreach ($checks as $c) { if ($c['status'] === 'fail') $failed++; }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>WINDELS PANEL — deployment setup</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 16px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0;
         background: #f8fafc; color: #0f172a; }
  main { max-width: 52rem; margin: 0 auto; padding: 4vh 1.25rem 6rem; }
  h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
  h2 { font-size: 1.15rem; margin: 2.5rem 0 .75rem; }
  p.lede { color: #475569; margin-top: 0; }
  .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 1.25rem 1.5rem; }
  table { width: 100%; border-collapse: collapse; }
  td { padding: .5rem .25rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
  td.state { width: 5.5rem; font-weight: 600; font-size: .8rem; letter-spacing: .04em; text-transform: uppercase; }
  .ok { color: #047857; } .warn { color: #b45309; } .fail { color: #b91c1c; }
  .detail { color: #475569; font-size: .92rem; }
  .hint { color: #64748b; font-size: .85rem; margin-top: .15rem; }
  label { display: block; font-weight: 600; margin: 1rem 0 .25rem; }
  input[type=text], input[type=email], input[type=password] {
      width: 100%; padding: .6rem .7rem; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 1rem; }
  button { margin-top: 1.5rem; background: #4f46e5; color: #fff; border: 0; border-radius: 8px;
           padding: .7rem 1.4rem; font-size: 1rem; font-weight: 600; cursor: pointer; }
  .flash { border-radius: 10px; padding: .9rem 1.1rem; margin: 1.25rem 0; }
  .flash.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
  .flash.success { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
  .flash.notice { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
  code { background: #f1f5f9; border-radius: 5px; padding: .1rem .35rem; font-size: .9em; }
  footer { margin-top: 3rem; color: #64748b; font-size: .9rem; }
</style>
</head>
<body>
<main>
  <h1>Deployment setup</h1>
  <p class="lede">
    Everything below is read from <code>.env</code> and the database. Nothing on this page
    runs a migration, a seed or an installer — the import of
    <code>database/production.sql</code> already did all of that.
  </p>

  <?php if (!empty($error)): ?>
    <div class="flash error"><?= html_escape($error) ?></div>
  <?php endif; ?>
  <?php if (!empty($success)): ?>
    <div class="flash success"><?= $success ?></div>
  <?php endif; ?>

  <div class="flash notice">
    This page is open because <code>VP_SETUP_TOKEN</code> is set in <code>.env</code>.
    Delete that line (cPanel → File Manager → Edit) when you are finished; the page then
    returns 404 to everyone, including you.
  </div>

  <h2>Deployment checks<?= $failed ? ' — '.$failed.' need attention' : '' ?></h2>
  <div class="card">
    <table>
      <?php foreach ($checks as $c): ?>
        <tr>
          <td class="state <?= html_escape($c['status']) ?>"><?= html_escape($c['status']) ?></td>
          <td>
            <strong><?= html_escape($c['label']) ?></strong>
            <div class="detail"><?= html_escape($c['detail']) ?></div>
            <?php if ($c['status'] !== 'ok' && !empty($c['hint'])): ?>
              <div class="hint"><?= html_escape($c['hint']) ?></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <h2>Administrator account</h2>
  <div class="card">
    <p class="detail">
      The imported database already contains a SUPER_ADMIN whose password is printed in the
      header of <code>database/production.sql</code>. Setting your own credentials here
      replaces it, so that documented password is never a live password on your panel.
    </p>
    <?= form_open('setup/admin') ?>
      <input type="hidden" name="token" value="<?= html_escape($token) ?>">
      <label for="username">Username</label>
      <input id="username" name="username" type="text" autocomplete="username"
             value="<?= html_escape(isset($form['username']) ? $form['username'] : 'admin') ?>" required>

      <label for="email">Email</label>
      <input id="email" name="email" type="email" autocomplete="email"
             value="<?= html_escape(isset($form['email']) ? $form['email'] : '') ?>" required>

      <label for="password">Password (12 characters or more)</label>
      <input id="password" name="password" type="password" autocomplete="new-password" required>

      <label for="password_confirm">Repeat password</label>
      <input id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>

      <button type="submit">Save administrator</button>
    <?= form_close() ?>
  </div>

  <footer>
    Deployment guide: <code>docs/cpanel-deployment.md</code> in the package you uploaded.
  </footer>
</main>
</body>
</html>
