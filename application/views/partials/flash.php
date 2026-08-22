<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Unified flash messages — one pattern for every module.
 *
 * Renders session flashdata (success / info / warning / error) with the shared
 * .alert-* component classes so no module invents its own notification markup.
 * Kept as a partial so the public, auth and app shells stay byte-identical.
 */
$flash_items = array(
    'success' => array('class' => 'success', 'role' => 'status'),
    'info'    => array('class' => 'info',    'role' => 'status'),
    'warning' => array('class' => 'warning', 'role' => 'status'),
    'error'   => array('class' => 'danger',  'role' => 'alert'),
);
foreach ($flash_items as $key => $spec):
    $msg = $this->session->flashdata($key);
    if ($msg === null || $msg === '') continue;
?>
  <div class="alert alert-<?=$spec['class']?>" role="<?=$spec['role']?>"><?=htmlspecialchars((string)$msg)?></div>
<?php endforeach; ?>
