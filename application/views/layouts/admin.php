<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Admin shell. Admin controllers may load this instead of layouts/app; it
// forwards to the single authenticated layout (which already selects the admin
// sidebar/mobile nav from the current user's role). Keeping one implementation
// in layouts/app.php is what prevents the admin and customer shells drifting.
$this->load->view('layouts/app', array_merge(get_defined_vars(), array('is_admin' => true)));
