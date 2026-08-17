<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Tickets — the staff support queue (Session 15).
 *
 * Reading the queue requires `tickets.view` and replying requires
 * `tickets.reply`; assignment, priority and status changes require
 * `tickets.manage`. Every mutation is POST-only, CSRF-protected and
 * audit-logged.
 *
 * Staff see the full conversation including internal notes; the customer view
 * (dashboard/Tickets) filters `is_internal_note` out, so a note written here is
 * never visible to the requester.
 */
class Tickets extends Admin_Controller {

    const PER_PAGE = 25;

    public function __construct() {
        parent::__construct();
        $this->require_perm('tickets.view');
        $this->load->library(array('TicketService', 'DashboardStats'));
        $this->load->model(array(
            'Ticket_model', 'Ticket_message_model', 'User_model',
            'Order_model', 'Audit_log_model',
        ));
    }

    public function index() {
        $filters = array(
            'status'     => $this->input->get('status', true),
            'priority'   => $this->input->get('priority', true),
            'department' => $this->input->get('department', true),
            'search'     => $this->input->get('q', true),
            'unassigned' => $this->input->get('unassigned') ? 1 : 0,
        );
        // "mine" is the queue a staff member lives in.
        if ($this->input->get('mine')) $filters['assigned_to_id'] = $this->current_user->id;

        $page  = max(1, (int)$this->input->get('page'));
        $limit = self::PER_PAGE;
        $total = $this->Ticket_model->admin_count($filters);

        $this->load->view('layouts/app', array(
            'title'        => 'Tickets',
            'nav_active'   => 'admin/tickets',
            'content_view' => 'admin/tickets/index',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'tickets'      => $this->Ticket_model->admin_search($filters, $limit, ($page - 1) * $limit),
            'counts'       => $this->Ticket_model->status_counts(),
            'filters'      => $filters,
            'mine'         => (bool)$this->input->get('mine'),
            'page'         => $page,
            'total'        => $total,
            'total_pages'  => max(1, (int)ceil($total / $limit)),
        ));
    }

    public function detail($public_id) {
        $ticket = $this->Ticket_model->admin_find($public_id);
        if (!$ticket) show_404();

        $this->load->view('layouts/app', array(
            'title'        => 'Ticket '.$ticket->public_id,
            'nav_active'   => 'admin/tickets',
            'content_view' => 'admin/tickets/detail',
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'ticket'       => $ticket,
            // Staff see everything, internal notes included.
            'messages'     => $this->Ticket_message_model->for_ticket($ticket->id, true),
            'order'        => $ticket->order_id ? $this->Order_model->find_by_id($ticket->order_id) : null,
            'staff'        => $this->User_model->staff_members(),
        ));
    }

    /** POST /admin/tickets/:id/reply — public reply or internal note. */
    public function reply($public_id) {
        $ticket   = $this->guard($public_id, 'tickets.reply');
        $internal = (bool)$this->input->post('internal');
        $body     = (string)$this->input->post('message', true);

        $result = $this->ticketservice->staff_reply($public_id, $this->current_user, $body, $internal);
        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $result['error'] ?? 'Could not save the reply.');
            return redirect('admin/tickets/'.$ticket->public_id);
        }

        $this->audit($internal ? 'ticket.note_added' : 'ticket.replied', $ticket, null, array(
            'is_internal_note' => $internal ? 1 : 0,
        ));
        $this->session->set_flashdata('success', $internal ? 'Internal note saved.' : 'Reply sent.');
        redirect('admin/tickets/'.$ticket->public_id);
    }

    /** POST /admin/tickets/:id/assign */
    public function assign($public_id) {
        $ticket   = $this->guard($public_id, 'tickets.manage');
        $staff_id = $this->input->post('assigned_to_id', true);

        $result = $this->ticketservice->assign($public_id, $staff_id ?: null);
        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $result['error'] ?? 'Could not assign the ticket.');
            return redirect('admin/tickets/'.$ticket->public_id);
        }

        $this->audit('ticket.assigned', $ticket,
            array('assigned_to_id' => $ticket->assigned_to_id),
            array('assigned_to_id' => $staff_id ?: null));
        $this->session->set_flashdata('success', $staff_id ? 'Ticket assigned.' : 'Ticket unassigned.');
        redirect('admin/tickets/'.$ticket->public_id);
    }

    /** POST /admin/tickets/:id/status */
    public function status($public_id) {
        $ticket = $this->guard($public_id, 'tickets.manage');
        $status = strtoupper((string)$this->input->post('status', true));

        $result = $this->ticketservice->set_status($public_id, $status);
        if (empty($result['ok'])) {
            $this->session->set_flashdata('error', $result['error'] ?? 'Could not change the status.');
            return redirect('admin/tickets/'.$ticket->public_id);
        }

        $this->audit('ticket.status_changed', $ticket,
            array('status' => $ticket->status), array('status' => $status));
        $this->session->set_flashdata('success', 'Ticket moved to '.$status.'.');
        redirect('admin/tickets/'.$ticket->public_id);
    }

    /** POST /admin/tickets/:id/priority */
    public function priority($public_id) {
        $ticket   = $this->guard($public_id, 'tickets.manage');
        $priority = strtoupper((string)$this->input->post('priority', true));
        if (!in_array($priority, array('LOW','MEDIUM','HIGH','URGENT'), true)) {
            $this->session->set_flashdata('error', 'Unknown priority.');
            return redirect('admin/tickets/'.$ticket->public_id);
        }

        $this->Ticket_model->set_priority($ticket->id, $priority);
        $this->audit('ticket.priority_changed', $ticket,
            array('priority' => $ticket->priority), array('priority' => $priority));
        $this->session->set_flashdata('success', 'Priority set to '.$priority.'.');
        redirect('admin/tickets/'.$ticket->public_id);
    }

    /* ----------------------------- helpers ----------------------------- */

    private function guard($public_id, $perm) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($perm);
        $ticket = $this->Ticket_model->admin_find($public_id);
        if (!$ticket) show_404();
        return $ticket;
    }

    private function audit($action, $ticket, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'tickets', (string)$ticket->id,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
