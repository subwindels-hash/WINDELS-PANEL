<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Dashboard — admin landing. The `reports.view` permission is enforced
 * here (Session 15 will populate revenue/order/provider widgets).
 */
class Dashboard extends Admin_Controller {

    public function index() {
        $this->require_perm('reports.view');

        $this->load->view('layouts/app', array(
            'title'        => 'Admin',
            'content_view' => 'admin/dashboard',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
        ));
    }
}
