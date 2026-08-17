<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
  <?php foreach (array('Revenue today','Orders','Active customers','Provider health') as $label): ?>
    <div class="rounded-2xl border border-slate-200 bg-white p-6">
      <div class="text-sm text-slate-500"><?=htmlspecialchars($label)?></div>
      <div class="mt-2 text-2xl font-bold tracking-tight text-slate-300">—</div>
    </div>
  <?php endforeach; ?>
</div>

<div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
  Admin widgets, charts and reports ship in <strong>Session 15 — Admin</strong>.
  The RBAC gate is active: this page required the <code>reports.view</code> permission
  (held by <?=htmlspecialchars($current_user->role)?>).
</div>
