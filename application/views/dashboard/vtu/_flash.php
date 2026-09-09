<?php defined('BASEPATH') OR exit('No direct script access allowed');
/* Flash messages, rendered with design-system card/alert components. */ ?>
<?php if ($msg = $this->session->flashdata('error')): ?>
  <div class="card alert alert-error mb-4" role="alert"><?=htmlspecialchars($msg)?></div>
<?php endif; ?>
<?php if ($msg = $this->session->flashdata('success')): ?>
  <div class="card alert alert-success mb-4" role="status"><?=htmlspecialchars($msg)?></div>
<?php endif; ?>
