<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * ShopDeliveryService — secure digital-file delivery for shop orders.
 *
 * Storage rule: an uploaded digital-product file lives under `storage/`,
 * outside the web root, exactly like every other private runtime file this
 * panel writes (see application/core/Env.php::writable_paths()). It is never
 * moved into `assets/` and never served by a direct URL — the only way to
 * reach the bytes is dashboard/Downloads::file(), which requires an
 * authenticated owner (or a signed, time-limited token for a redirect-style
 * link) and records the access.
 *
 * Fulfilment sequence once a marketplace order for a DIGITAL listing reaches
 * PAID (the same escrow state a license-key delivery already uses):
 *
 *   MarketplaceService::purchase() succeeds (order PAID)
 *     -> ShopDeliveryService::provision() creates ONE digital_deliveries row
 *        per unit-of-access the listing grants (a `digital_products` file
 *        attached to the listing) — never exposing the storage path itself,
 *        only a public_id the customer can ask to download through the
 *        audited endpoint.
 *
 * A gift-card-category listing does not go through this class at all: it
 * fulfils through the already-built GiftcardService, wired in
 * ShopCatalogueService::purchase_giftcard_listing().
 */
class ShopDeliveryService {

    /** Where uploaded digital-product files live, under the private storage/ root. */
    const DIR = 'digital_products';

    /** Sniffed MIME → the extension we store it under. Deliberately broader than images (MediaService's job). */
    const ALLOWED = array(
        'application/pdf' => 'pdf',
        'application/zip' => 'zip',
        'application/x-zip-compressed' => 'zip',
        'application/epub+zip' => 'epub',
        'text/plain' => 'txt',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/x-msdownload' => 'exe', // deliberately still name-randomised & never web-servable
        'application/octet-stream' => 'bin',
    );

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model(array('Digital_product_model', 'Digital_delivery_model', 'Marketplace_order_model'));
        $this->ci->load->library('SignedToken');
    }

    /** Largest digital-product upload, in bytes. */
    public function max_bytes() {
        return 200 * 1024 * 1024; // 200 MB
    }

    /**
     * Store an uploaded file for a DIGITAL listing. Same principles as
     * MediaService::store() (random name, sniffed type, extension chosen by
     * us) but writing under the private storage/ directory instead of
     * assets/, because this file must never be reachable by a guessed or
     * shared URL.
     */
    public function attach_file(array $file, $listing_id, $mover = null) {
        $err = $this->upload_error($file);
        if ($err !== null) return array('ok' => false, 'error' => $err);

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) return array('ok' => false, 'error' => 'That file is empty.');
        if ($size > $this->max_bytes()) {
            return array('ok' => false, 'error' => 'That file is larger than the '
                .number_format($this->max_bytes() / 1048576, 0).' MB limit.');
        }

        $mime = $this->sniff($file['tmp_name']);
        $ext = self::ALLOWED[$mime] ?? null;
        if ($ext === null) {
            // Fall back to the submitted extension for archive/document types
            // finfo cannot always distinguish (e.g. some .epub/.docx
            // variants); the byte content is still what gets stored, under a
            // random name, so this never lets a client choose what runs.
            $orig_ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
            $safe_exts = array('pdf','zip','epub','txt','doc','docx','xls','xlsx','jpg','jpeg','png','bin','psd','ai','fig','sketch');
            $ext = in_array($orig_ext, $safe_exts, true) ? $orig_ext : 'bin';
        }

        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return array('ok' => false, 'error' => 'The storage directory could not be created.');
        }
        $name = bin2hex(random_bytes(20)).'.'.$ext;
        $dest = $dir.'/'.$name;
        $ok = $mover ? call_user_func($mover, $file['tmp_name'], $dest) : @move_uploaded_file($file['tmp_name'], $dest);
        if (!$ok) return array('ok' => false, 'error' => 'The file could not be saved.');
        @chmod($dest, 0640);

        $existing = $this->ci->Digital_product_model->for_listing($listing_id);
        $storage_key = self::DIR.'/'.$name;
        $original_name = mb_substr($this->clean_name((string)($file['name'] ?? $name)), 0, 255);

        if ($existing) {
            // Replacing a file: remove the old one from disk so nothing orphaned
            // accumulates, and existing digital_deliveries rows simply point at
            // the same digital_product_id — customers see the newest file.
            $this->delete_file($existing->storage_key);
            $this->ci->Digital_product_model->update_fields($existing->id, array(
                'storage_key' => $storage_key,
                'original_filename' => $original_name,
                'mime_type' => $mime,
                'size_bytes' => $size,
            ));
            return array('ok' => true, 'digital_product_id' => $existing->id);
        }

        $id = $this->ci->Digital_product_model->create(array(
            'listing_id' => (int)$listing_id,
            'storage_key' => $storage_key,
            'original_filename' => $original_name,
            'mime_type' => $mime,
            'size_bytes' => $size,
        ));
        return array('ok' => true, 'digital_product_id' => $id);
    }

    /**
     * Grant access for a PAID marketplace order of a digital listing. Called
     * right after MarketplaceService::purchase() succeeds for a listing that
     * has an attached digital_products file — idempotent via the unique
     * (order, product) key, so a retried/duplicate purchase call never grants
     * a second delivery row.
     */
    public function provision($order, $listing) {
        $product = $this->ci->Digital_product_model->for_listing($listing->id);
        if (!$product) return array('ok' => true, 'skipped' => 'no file attached to this listing');

        $existing = $this->ci->Digital_delivery_model->for_order_and_product($order->id, $product->id);
        if ($existing) return array('ok' => true, 'delivery' => $existing);

        $id = $this->ci->Digital_delivery_model->create(array(
            'marketplace_order_id' => $order->id,
            'digital_product_id'   => $product->id,
            'user_id'              => $order->buyer_id,
        ));
        return array('ok' => true, 'delivery_id' => $id);
    }

    /** Every download this user currently has (My Downloads). */
    public function for_user($user_id, $limit = 100, $offset = 0) {
        return $this->ci->Digital_delivery_model->for_user($user_id, $limit, $offset);
    }

    /**
     * Issue a short-lived signed download token for one delivery. The token
     * itself never contains the storage path — only the delivery's public_id
     * — so nothing about the file layout is exposed even if the link leaks in
     * a referer header or browser history before it expires.
     */
    public function issue_link($delivery_public_id, $user_id) {
        $delivery = $this->ci->Digital_delivery_model->find_public($delivery_public_id);
        if (!$delivery || (int)$delivery->user_id !== (int)$user_id) {
            return array('ok' => false, 'error' => 'Download not found.', 'code' => 'NOT_FOUND');
        }
        if ((int)$delivery->revoked === 1) {
            return array('ok' => false, 'error' => 'This download has been revoked. Contact support.', 'code' => 'REVOKED');
        }
        if ($delivery->download_limit !== null && (int)$delivery->download_count >= (int)$delivery->download_limit) {
            return array('ok' => false, 'error' => 'The download limit for this item has been reached.', 'code' => 'LIMIT_REACHED');
        }
        $ttl_hours = max(1, (int)$delivery->link_ttl_hours);
        $token = $this->ci->signedtoken->issue($delivery->public_id, 'shop.download', $ttl_hours * 3600);
        return array('ok' => true, 'token' => $token, 'delivery' => $delivery);
    }

    /**
     * Verify a download token and stream the file. Returns an error array
     * rather than streaming on any failure — the caller (controller) decides
     * how to render that.
     */
    public function resolve_download($token, $ip) {
        $payload = $this->ci->signedtoken->verify($token, 'shop.download');
        if (!$payload) return array('ok' => false, 'error' => 'This download link is invalid or has expired.', 'code' => 'BAD_TOKEN');

        $delivery = $this->ci->Digital_delivery_model->find_public($payload['sub']);
        if (!$delivery) return array('ok' => false, 'error' => 'Download not found.', 'code' => 'NOT_FOUND');
        if ((int)$delivery->revoked === 1) {
            return array('ok' => false, 'error' => 'This download has been revoked.', 'code' => 'REVOKED');
        }
        if ($delivery->download_limit !== null && (int)$delivery->download_count >= (int)$delivery->download_limit) {
            return array('ok' => false, 'error' => 'The download limit for this item has been reached.', 'code' => 'LIMIT_REACHED');
        }

        $path = $this->path_for($delivery->storage_key);
        if ($path === null || !is_file($path)) {
            return array('ok' => false, 'error' => 'The file for this download is no longer available.', 'code' => 'MISSING_FILE');
        }

        $this->ci->Digital_delivery_model->record_download($delivery->id, $ip);
        return array('ok' => true, 'path' => $path, 'filename' => $delivery->original_filename, 'mime' => $delivery->mime_type);
    }

    /** Admin: revoke a customer's access to a specific delivery. */
    public function revoke($delivery_public_id, $actor_id, $reason) {
        $delivery = $this->ci->Digital_delivery_model->find_public($delivery_public_id);
        if (!$delivery) return array('ok' => false, 'error' => 'Download not found.');
        $this->ci->Digital_delivery_model->revoke($delivery->id, $actor_id, $reason);
        $this->audit($actor_id, 'shop.download.revoked', $delivery_public_id, array('reason' => $reason));
        return array('ok' => true);
    }

    public function restore($delivery_public_id, $actor_id) {
        $delivery = $this->ci->Digital_delivery_model->find_public($delivery_public_id);
        if (!$delivery) return array('ok' => false, 'error' => 'Download not found.');
        $this->ci->Digital_delivery_model->restore($delivery->id);
        $this->audit($actor_id, 'shop.download.restored', $delivery_public_id);
        return array('ok' => true);
    }

    /* ------------------------------------------------------------------ */

    private function dir() {
        require_once APPPATH.'core/Env.php';
        $paths = Env::writable_paths();
        return rtrim($paths['storage'], '/\\').'/'.self::DIR;
    }

    /** Confines any path lookup to the digital_products directory. */
    private function path_for($storage_key) {
        $storage_key = ltrim(str_replace('\\', '/', (string)$storage_key), '/');
        if (strpos($storage_key, self::DIR.'/') !== 0) return null;
        $base = realpath($this->dir());
        if ($base === false) return null;
        $full = realpath($base.'/'.basename($storage_key));
        if ($full === false) return null;
        if (strpos($full, $base) !== 0) return null;
        return $full;
    }

    private function delete_file($storage_key) {
        $path = $this->path_for($storage_key);
        if ($path !== null && is_file($path)) @unlink($path);
    }

    private function sniff($tmp) {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmp);
            finfo_close($finfo);
            if ($mime) return $mime;
        }
        return 'application/octet-stream';
    }

    private function clean_name($name) {
        $name = basename($name);
        return preg_replace('/[^a-zA-Z0-9 ._\-()]/', '', $name) ?: 'file';
    }

    private function upload_error(array $file) {
        $code = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        switch ($code) {
            case UPLOAD_ERR_OK: return null;
            case UPLOAD_ERR_NO_FILE: return 'Choose a file to upload.';
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE: return 'That file is too large.';
            default: return 'The upload failed. Try again.';
        }
    }

    private function audit($actor_id, $action, $entity, array $meta = array()) {
        try {
            $this->ci->load->model('Audit_log_model');
            $this->ci->Audit_log_model->record(
                $actor_id ?: null, $action, 'digital_deliveries', (string)$entity, null, $meta ?: null,
                isset($this->ci->input) ? $this->ci->input->ip_address() : null,
                isset($this->ci->input) ? $this->ci->input->user_agent() : null,
                method_exists($this->ci, 'request_id') ? $this->ci->request_id() : null
            );
        } catch (Throwable $e) {
            log_message('error', 'shop delivery audit failed: '.$e->getMessage());
        }
    }
}
