<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * One gift card code (§23).
 *
 * The most valuable row in the panel: whoever holds a card number can spend
 * it. Two habits are enforced here rather than left to callers:
 *
 *  - **Nothing in this model decrypts.** It stores and returns ciphertext;
 *    GiftcardService::reveal() is the only place that opens it, which is what
 *    makes the access trail impossible to bypass by writing a new screen.
 *  - **There is no delete.** Unlike identity results, a gift card code is the
 *    product the customer paid for, so no retention sweep exists to remove it
 *    and no method here offers to.
 */
class Giftcard_code_model extends MY_Model {
    protected $table = 'giftcard_codes';

    public function create(array $data){
        if (empty($data['public_id'])) $data['public_id'] = marvy_public_id();
        $now = $this->now_utc();
        $data += array('created_at'=>$now, 'updated_at'=>$now);
        $this->db->insert($this->table, $data);
        return (int)$this->db->insert_id();
    }

    /** Every card on an order, in the order the customer was given them. */
    public function for_order($order_id){
        return $this->db->where('giftcard_order_id',$order_id)
                        ->order_by('card_index','ASC')
                        ->get($this->table)->result();
    }

    public function count_for_order($order_id){
        return (int)$this->db->where('giftcard_order_id',$order_id)->count_all_results($this->table);
    }

    public function find_public_for_order($public_id, $order_id){
        return $this->db->where('public_id',$public_id)
                        ->where('giftcard_order_id',$order_id)
                        ->get($this->table)->row();
    }

    /** Stamp the first time this specific card was opened. */
    public function mark_revealed($id){
        $row = $this->find_by_id($id);
        if ($row && !empty($row->revealed_at)) return true;
        return $this->db->where('id',$id)->update($this->table, array(
            'revealed_at' => $this->now_utc(),
            'updated_at'  => $this->now_utc(),
        ));
    }
}
