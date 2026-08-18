<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/** Durable ownership and replay state for a mass-order submission. */
class Mass_order_batch_model extends MY_Model {
    protected $table = 'mass_order_batches';

    /**
     * Claim a user-scoped token or report the state of an earlier claim.
     *
     * @return array{state:string,batch?:object} state is CLAIMED, REPLAY,
     *                                                CONFLICT, or IN_PROGRESS
     */
    public function claim($user_id, $token_hash, $request_hash) {
        $existing = $this->find_token($user_id, $token_hash);
        if ($existing) return $this->existing_state($existing, $request_hash);

        $now = $this->now_utc();
        $inserted = $this->db->insert($this->table, array(
            'public_id' => windels_public_id(),
            'user_id' => (int)$user_id,
            'token_hash' => (string)$token_hash,
            'request_hash' => (string)$request_hash,
            'status' => 'PROCESSING',
            'result_json' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ));

        if ($inserted) {
            return array('state' => 'CLAIMED', 'batch' => $this->find_by_id($this->db->insert_id()));
        }

        // A concurrent request may have won the unique (user_id, token_hash)
        // insert. Read its row and apply the same conflict/replay rules.
        $existing = $this->find_token($user_id, $token_hash);
        return $existing
            ? $this->existing_state($existing, $request_hash)
            : array('state' => 'IN_PROGRESS');
    }

    public function complete($id, array $result) {
        $json = json_encode($result, JSON_UNESCAPED_SLASHES);
        if ($json === false) return false;

        return (bool)$this->db->where('id', (int)$id)->where('status', 'PROCESSING')
            ->update($this->table, array(
                'status' => 'COMPLETED',
                'result_json' => $json,
                'updated_at' => $this->now_utc(),
            ));
    }

    private function find_token($user_id, $token_hash) {
        return $this->db->where('user_id', (int)$user_id)
            ->where('token_hash', (string)$token_hash)->get($this->table)->row();
    }

    private function existing_state($batch, $request_hash) {
        if (!hash_equals((string)$batch->request_hash, (string)$request_hash)) {
            return array('state' => 'CONFLICT', 'batch' => $batch);
        }
        if ($batch->status !== 'COMPLETED' || !$batch->result_json) {
            return array('state' => 'IN_PROGRESS', 'batch' => $batch);
        }

        $result = json_decode($batch->result_json, true);
        if (!is_array($result)) {
            return array('state' => 'IN_PROGRESS', 'batch' => $batch);
        }
        $batch->decoded_result = $result;
        return array('state' => 'REPLAY', 'batch' => $batch);
    }
}
