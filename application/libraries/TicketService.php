<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * TicketService — creates and replies to support tickets (Session 13).
 *
 * Customer replies can never be internal notes; a reply reopens a CLOSED
 * ticket and bumps last_reply_at. Attachments are stored as URLs (file upload
 * handling is a separate, upload-validated step).
 *
 * Session 15 adds the staff side: staff_reply() and note() are not scoped to
 * the requester (they are reachable only behind tickets.reply / tickets.manage)
 * and may mark a message as an internal note, which the customer view filters
 * out.
 */
class TicketService {

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Ticket_model','Ticket_message_model','Order_model','User_model'));
    }

    /**
     * Open a new ticket.
     *
     * @return array{ok:bool,ticket?:object,error?:string,code?:string}
     */
    public function open($user, array $input) {
        if (!marvy_feature_enabled('tickets', true)) {
            return array('ok'=>false,'error'=>'Support tickets are currently unavailable.','code'=>'FEATURE_DISABLED');
        }
        $subject = trim((string)($input['subject'] ?? ''));
        $message = trim((string)($input['message'] ?? ''));
        if ($subject === '' || mb_strlen($subject) > 255)
            return array('ok'=>false,'error'=>'Subject is required (max 255 chars)','code'=>'BAD_SUBJECT');
        if ($message === '' || mb_strlen($message) > 20000)
            return array('ok'=>false,'error'=>'Message is required','code'=>'BAD_MESSAGE');

        $order = null;
        if (!empty($input['order_id'])) {
            $order = $this->ci->Order_model->find_public_for_user((string)$input['order_id'], $user->id);
            if (!$order) return array('ok'=>false,'error'=>'Order not found','code'=>'NO_ORDER');
        }

        $this->ci->db->trans_start();
        $ticket = $this->ci->Ticket_model->create(array(
            'public_id'   => marvy_public_id(),
            'user_id'     => $user->id,
            'subject'     => $subject,
            'status'      => 'OPEN',
            'priority'    => in_array(($input['priority'] ?? ''), array('LOW','MEDIUM','HIGH'), true) ? $input['priority'] : 'MEDIUM',
            'department'  => $input['department'] ?? 'orders',
            'order_id'    => $order ? $order->id : null,
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ));
        $this->add_message($ticket->id, $user->id, $message, 0, $input['attachments'] ?? array());
        $this->ci->db->trans_complete();

        if ($this->ci->db->trans_status() === false)
            return array('ok'=>false,'error'=>'Could not create ticket','code'=>'PERSIST_FAILED');
        return array('ok'=>true,'ticket'=>$this->ci->Ticket_model->find_by_id($ticket->id));
    }

    /**
     * Add a customer (or staff) reply.
     */
    public function reply($public_id, $user, $body, $is_staff = false, array $attachments = array()) {
        $ticket = $this->ci->Ticket_model->find_public_for_user($public_id, $user->id);
        if (!$ticket) return array('ok'=>false,'error'=>'Ticket not found','code'=>'NO_TICKET');
        $body = trim((string)$body);
        if ($body === '' || mb_strlen($body) > 20000)
            return array('ok'=>false,'error'=>'Message is required','code'=>'BAD_MESSAGE');

        $this->ci->db->trans_start();
        $msg = $this->add_message($ticket->id, $user->id, $body, $is_staff ? 1 : 0, $attachments);
        // Reopen a closed ticket when the customer replies.
        $extra = $ticket->status === 'CLOSED' && !$is_staff ? array('status'=>'OPEN','closed_at'=>null) : array();
        $this->ci->Ticket_model->touch($ticket->id, $extra);
        $this->ci->db->trans_complete();

        // staff_reply() has always checked this; the customer path did not, so
        // a rolled-back reply was reported as sent and the message silently
        // vanished — indistinguishable, from the customer's side, from the
        // panel eating their second message.
        if ($this->ci->db->trans_status() === false) {
            return array('ok'=>false,'error'=>'Could not save reply','code'=>'PERSIST_FAILED');
        }

        return array('ok'=>true,'message'=>$msg,'ticket'=>$this->ci->Ticket_model->find_by_id($ticket->id));
    }

    /**
     * Staff reply to any ticket (not scoped to the requester).
     *
     * @param bool $internal true stores an internal note the customer never sees
     * @return array{ok:bool,message?:object,ticket?:object,error?:string,code?:string}
     */
    public function staff_reply($public_id, $staff, $body, $internal = false, array $attachments = array()) {
        $ticket = $this->ci->Ticket_model->admin_find($public_id);
        if (!$ticket) return array('ok'=>false,'error'=>'Ticket not found','code'=>'NO_TICKET');
        $body = trim((string)$body);
        if ($body === '' || mb_strlen($body) > 20000)
            return array('ok'=>false,'error'=>'Message is required','code'=>'BAD_MESSAGE');

        $this->ci->db->trans_start();
        $msg = $this->add_message($ticket->id, $staff->id, $body, 1, $attachments, $internal ? 1 : 0);
        // An internal note is bookkeeping: it must not flip the ticket into
        // ANSWERED or the customer would see a status change with no reply.
        $extra = $internal ? array() : array('status' => 'ANSWERED');
        $this->ci->Ticket_model->touch($ticket->id, $extra);
        $this->ci->db->trans_complete();

        if ($this->ci->db->trans_status() === false)
            return array('ok'=>false,'error'=>'Could not save reply','code'=>'PERSIST_FAILED');

        // An internal note is staff bookkeeping — notifying the customer about
        // it would leak the note's existence and, worse, promise a reply that
        // is not there.
        if (!$internal) {
            try {
                $this->ci->load->library('NotificationService');
                if (!isset($this->ci->notificationservice)) throw new RuntimeException('notification service unavailable');
                $this->ci->notificationservice->notify(
                    $ticket->user_id, 'ticket.replied',
                    'Support replied to your ticket: '.$ticket->subject,
                    array('ticket_id' => $ticket->public_id, 'url' => 'dashboard/tickets/'.$ticket->public_id),
                    array(
                        'ticket_id'  => $ticket->public_id,
                        'subject'    => $ticket->subject,
                        'ticket_url' => site_url('dashboard/tickets/'.$ticket->public_id),
                    )
                );
            } catch (Throwable $e) {
                log_message('error', 'ticket notification failed for '.$ticket->public_id.': '.$e->getMessage());
            }
        }

        return array(
            'ok'      => true,
            'message' => $msg,
            'ticket'  => $this->ci->Ticket_model->find_by_id($ticket->id),
        );
    }

    /** Staff-only status change (OPEN|PENDING|ANSWERED|CLOSED). */
    public function set_status($public_id, $status) {
        $allowed = array('OPEN','PENDING','ANSWERED','CLOSED');
        if (!in_array($status, $allowed, true))
            return array('ok'=>false,'error'=>'Unknown status','code'=>'BAD_STATUS');
        $ticket = $this->ci->Ticket_model->admin_find($public_id);
        if (!$ticket) return array('ok'=>false,'error'=>'Ticket not found','code'=>'NO_TICKET');

        $this->ci->Ticket_model->set_status($ticket->id, $status);
        return array('ok'=>true,'ticket'=>$this->ci->Ticket_model->find_by_id($ticket->id));
    }

    /** Assign (or unassign, with a null id) a ticket to a staff member. */
    public function assign($public_id, $staff_id) {
        $ticket = $this->ci->Ticket_model->admin_find($public_id);
        if (!$ticket) return array('ok'=>false,'error'=>'Ticket not found','code'=>'NO_TICKET');

        if ($staff_id) {
            $assignee = $this->ci->User_model->find_by_id((int)$staff_id);
            if (!$assignee || !$this->ci->User_model->is_staff($assignee))
                return array('ok'=>false,'error'=>'Assignee must be a staff member','code'=>'BAD_ASSIGNEE');
        }
        $this->ci->Ticket_model->assign($ticket->id, $staff_id ? (int)$staff_id : null);
        return array('ok'=>true,'ticket'=>$this->ci->Ticket_model->find_by_id($ticket->id));
    }

    public function close($public_id, $user) {
        $ticket = $this->ci->Ticket_model->find_public_for_user($public_id, $user->id);
        if (!$ticket) return array('ok'=>false,'error'=>'Ticket not found','code'=>'NO_TICKET');
        $this->ci->Ticket_model->close($ticket->id);
        return array('ok'=>true,'ticket'=>$this->ci->Ticket_model->find_by_id($ticket->id));
    }

    private function add_message($ticket_id, $author_id, $body, $is_staff, $attachments, $is_internal_note = 0) {
        // Only a staff message can ever be an internal note; a customer reply
        // is forced visible so nothing a customer writes can be hidden.
        $internal = ($is_staff && $is_internal_note) ? 1 : 0;
        $msg = $this->ci->Ticket_message_model->create(array(
            'public_id'        => marvy_public_id(),
            'ticket_id'        => $ticket_id,
            'author_id'        => $author_id,
            'message'          => $body,
            'is_staff'         => $is_staff,
            'is_internal_note' => $internal,
            'created_at'       => gmdate('Y-m-d H:i:s'),
        ));
        if (!empty($attachments) && is_array($attachments)) {
            $rows = array();
            foreach (array_slice($attachments, 0, 10) as $a) {
                if (empty($a['url']) || empty($a['name'])) continue;
                $rows[] = array(
                    'ticket_message_id' => $msg->id,
                    'file_url'          => substr((string)$a['url'], 0, 512),
                    'file_name'         => substr((string)$a['name'], 0, 255),
                    'mime_type'         => substr((string)($a['mime'] ?? 'application/octet-stream'), 0, 128),
                    'size'              => (int)($a['size'] ?? 0),
                    'created_at'        => gmdate('Y-m-d H:i:s'),
                );
            }
            if ($rows) $this->ci->db->insert_batch('ticket_attachments', $rows);
        }
        return $msg;
    }
}
