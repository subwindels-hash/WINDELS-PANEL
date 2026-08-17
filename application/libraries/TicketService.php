<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * TicketService — creates and replies to support tickets (Session 13).
 *
 * Customer replies can never be internal notes; a reply reopens a CLOSED
 * ticket and bumps last_reply_at. Attachments are stored as URLs (file upload
 * handling is a separate, upload-validated step).
 */
class TicketService {

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Ticket_model','Ticket_message_model','Order_model'));
    }

    /**
     * Open a new ticket.
     *
     * @return array{ok:bool,ticket?:object,error?:string,code?:string}
     */
    public function open($user, array $input) {
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
            'public_id'   => windels_public_id(),
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

        return array('ok'=>true,'message'=>$msg,'ticket'=>$this->ci->Ticket_model->find_by_id($ticket->id));
    }

    public function close($public_id, $user) {
        $ticket = $this->ci->Ticket_model->find_public_for_user($public_id, $user->id);
        if (!$ticket) return array('ok'=>false,'error'=>'Ticket not found','code'=>'NO_TICKET');
        $this->ci->Ticket_model->close($ticket->id);
        return array('ok'=>true,'ticket'=>$this->ci->Ticket_model->find_by_id($ticket->id));
    }

    private function add_message($ticket_id, $author_id, $body, $is_staff, $attachments) {
        $msg = $this->ci->Ticket_message_model->create(array(
            'public_id'        => windels_public_id(),
            'ticket_id'        => $ticket_id,
            'author_id'        => $author_id,
            'message'          => $body,
            'is_staff'         => $is_staff,
            'is_internal_note' => 0,
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
