<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Restore the staff identity behind a customer support session. */
class Impersonation extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('ImpersonationService');
    }

    /** POST /impersonation/stop */
    public function stop() {
        if ($this->input->method(true) !== 'POST') show_404();
        if (!$this->impersonationservice->has_context()) show_404();

        $state = $this->impersonationservice->enforce(
            $this->input->ip_address(),
            $this->input->user_agent(),
            $this->request_id
        );
        if (!empty($state['ended'])) {
            $this->session->set_flashdata('warning', 'The customer impersonation session had already ended.');
            return redirect(!empty($state['actor_restored']) ? 'admin' : 'login');
        }
        if (empty($state['active'])) show_404();

        $result = $this->impersonationservice->end(
            'MANUAL',
            $this->input->ip_address(),
            $this->input->user_agent(),
            $this->request_id
        );
        if (empty($result['actor_restored'])) {
            $this->session->set_flashdata('warning', 'The staff account is no longer active. Sign in again.');
            return redirect('login');
        }

        $this->session->set_flashdata('success', 'Customer impersonation ended. Your staff session has been restored.');
        $target = $result['target'] ?? null;
        redirect($target ? 'admin/customers/'.$target->public_id : 'admin');
    }
}
