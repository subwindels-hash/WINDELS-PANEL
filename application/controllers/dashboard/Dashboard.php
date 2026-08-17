<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard — customer landing page (Session 06 will flesh out widgets/charts).
 * Session 03 establishes the authenticated shell and RBAC boundary.
 */
class Dashboard extends Auth_Controller {

    public function index() {
        $this->load->model('Wallet_model');
        $wallet = $this->Wallet_model->for_user($this->current_user->id);

        $this->load->view('layouts/app', array(
            'title'        => 'Dashboard',
            'content_view' => 'dashboard/index',
            'current_user' => $this->current_user,
            'wallet'       => $wallet,
            'permissions'  => $this->auth->permissions(),
        ));
    }
}
