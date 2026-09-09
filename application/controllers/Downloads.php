<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Downloads — the actual byte-serving endpoint for shop digital deliveries.
 *
 * Deliberately public (no session wall): the signed token IS the credential,
 * exactly like a password-reset link. Requiring a session on top would break
 * the normal case of a customer downloading from a device they are not
 * signed into, or forwarding the link to themselves — the token's own
 * signature, expiry, revocation and download-limit checks
 * (ShopDeliveryService::resolve_download()) are what actually protect this.
 *
 * The response never reveals the storage path or any information about the
 * uploaded file's location on disk — only its original filename and bytes.
 */
class Downloads extends MY_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('ShopDeliveryService');
    }

    /** GET /downloads/file?token=... */
    public function file() {
        $token = $this->input->get('token', true);
        $res = $this->shopdeliveryservice->resolve_download($token, $this->input->ip_address());

        if (empty($res['ok'])) {
            $this->output->set_status_header($res['code'] === 'NOT_FOUND' ? 404 : 403);
            $this->load->view('layouts/main', array(
                'content_view' => 'public/shop/download_error',
                'data' => array('title' => 'Download unavailable', 'error' => $res['error']),
            ));
            return;
        }

        // Stream, never buffer the whole file into memory — some digital
        // products are large (this class caps uploads at 200MB).
        $this->output->set_content_type($res['mime']);
        header('Content-Disposition: attachment; filename="'.basename($res['filename']).'"');
        header('Content-Length: '.filesize($res['path']));
        header('X-Content-Type-Options: nosniff');
        // A download must never be cached by an intermediary — the link is
        // single-purpose and time-limited on purpose.
        header('Cache-Control: private, no-store, max-age=0');

        // CI3's output class buffers by default; a raw readfile() after
        // sending headers directly is the correct way to stream a binary
        // response without CI re-encoding or double-buffering it.
        while (ob_get_level() > 0) { @ob_end_clean(); }
        readfile($res['path']);
        exit;
    }
}
