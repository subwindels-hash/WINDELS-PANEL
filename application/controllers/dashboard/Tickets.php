<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard/Tickets — customer support inbox (Session 13).
 */
class Tickets extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Ticket_model','Ticket_message_model','Order_model'));
        $this->load->library(array('TicketService','DashboardStats','form_validation'));
    }

    public function index() {
        $status = $this->input->get('status', true);
        $page = max(1, (int)$this->input->get('page'));
        $limit = 15;
        $rows = $this->Ticket_model->for_user($this->current_user->id, $status ?: null, $limit, ($page-1)*$limit);
        $total = $this->Ticket_model->count_for_user($this->current_user->id, $status ?: null);

        $this->load->view('layouts/app', array(
            'title' => 'Support',
            'nav_active' => 'dashboard/tickets',
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/tickets/index',
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
            'tickets' => $rows,
            'status' => $status,
            'page' => $page,
            'total_pages' => max(1, (int)ceil($total/$limit)),
        ));
    }

    public function create() {
        if ($this->input->method(true) !== 'POST') show_404();
        $res = $this->ticketservice->open($this->current_user, $this->input->post());
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error'] ?? 'Could not create ticket');
            redirect('dashboard/tickets');
        }
        $this->session->set_flashdata('success', 'Ticket opened.');
        redirect('dashboard/tickets/'.$res['ticket']->public_id);
    }

    public function detail($public_id) {
        $ticket = $this->Ticket_model->find_public_for_user($public_id, $this->current_user->id);
        if (!$ticket) show_404();
        $messages = $this->Ticket_message_model->for_ticket($ticket->id);
        $order = $ticket->order_id ? $this->Order_model->find_by_id($ticket->order_id) : null;

        $this->load->view('layouts/app', array(
            'title' => 'Ticket #'.$public_id,
            'nav_active' => 'dashboard/tickets',
            'unread' => $this->dashboardstats->unread_count($this->current_user->id),
            'content_view' => 'dashboard/tickets/detail',
            'current_user' => $this->current_user,
            'permissions' => $this->auth->permissions(),
            'ticket' => $ticket,
            'messages' => $messages,
            'linked_order' => $order,
        ));
    }

    public function reply($public_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $res = $this->ticketservice->reply($public_id, $this->current_user, $this->input->post('message'));
        if (empty($res['ok'])) {
            $this->session->set_flashdata('error', $res['error'] ?? 'Could not send reply');
        } else {
            $this->session->set_flashdata('success', 'Reply sent.');
        }
        redirect('dashboard/tickets/'.$public_id);
    }

    public function close($public_id) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->ticketservice->close($public_id, $this->current_user);
        $this->session->set_flashdata('success', 'Ticket closed.');
        redirect('dashboard/tickets/'.$public_id);
    }
}
