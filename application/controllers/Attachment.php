<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attachment — the only way to read a support attachment.
 *
 * Support attachments used to be ordinary files under `assets/uploads`, served
 * by the web server itself. The only thing standing between a stranger and a
 * customer's bank statement was the filename being 32 random hex characters:
 * an *unguessable* URL, not an *authorised* one. Every place a URL leaks —
 * a forwarded email, a `Referer` header to a third-party site, a support
 * screen shared on a call, a staff laptop that left with its owner, browser
 * history on a shared machine — handed over the file permanently. Closing the
 * ticket, deleting the account or sacking the agent changed nothing.
 *
 * The file now lives outside the document root (see
 * `MediaService::PRIVATE_PURPOSES`) and reaches the browser only through this
 * controller, which answers three questions on every single request:
 *
 *   1. Is anyone signed in?            (`Auth_Controller`)
 *   2. Is this their ticket, or are they support staff? (`tickets.view`)
 *   3. Is it an internal note?         (staff only, always)
 *
 * A 404 — never a 403 — is returned when the answer is no, so this endpoint
 * cannot be used to confirm that a given attachment id exists.
 */
class Attachment extends Auth_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->model(array('Media_model', 'Ticket_message_model'));
        $this->load->library(array('MediaService', 'TicketService'));
    }

    /** GET /support/attachment/:media_public_id */
    public function ticket($public_id = null) {
        $media = $public_id ? $this->Media_model->find_by_public_id((string)$public_id) : null;

        // Only ticket media is served here. A branding image has a real
        // public URL and must not be laundered through an authorising route.
        if (!$media || $media->purpose !== 'ticket') show_404();

        $ctx = $this->Ticket_message_model->attachment_context($media->url);
        if (!$this->may_read($media, $ctx)) show_404();

        $path = $this->mediaservice->path_for($media);
        if ($path === null || !is_file($path)) show_404();

        $this->stream($path, $media, $ctx);
    }

    /**
     * The access rule, in one place so a test can state it.
     *
     * Staff with `tickets.view` read everything — that is the support queue's
     * whole job. A customer reads an attachment on their own ticket, and never
     * one attached to an internal note: staff write those *about* the customer
     * and the thread view already hides them, so serving the file would leak
     * exactly what that flag exists to protect.
     *
     * An orphan upload (accepted, but the message it belonged to was never
     * saved) is readable only by whoever uploaded it.
     */
    private function may_read($media, $ctx) {
        return TicketService::may_read_attachment(
            $this->auth->can('tickets.view'),
            (int)$this->current_user->id,
            $media,
            $ctx
        );
    }

    /**
     * Send the bytes.
     *
     * `Content-Disposition: attachment` unconditionally, and a MIME type taken
     * from what the media library sniffed at upload time rather than from the
     * filename: a customer-supplied PDF or image must never be rendered inline
     * in the panel's own origin, where an HTML-ish payload would run against
     * the signed-in session. `nosniff` closes the same door from the other
     * side. `private, no-store` keeps a shared proxy from caching one
     * customer's document and handing it to the next.
     */
    private function stream($path, $media, $ctx) {
        $name = $ctx && !empty($ctx->file_name) ? $ctx->file_name : $media->file_name;
        $name = str_replace(array('"', "\r", "\n", "\0"), '', basename((string)$name));
        if ($name === '') $name = 'attachment';

        $this->output->set_content_type($media->mime_type ?: 'application/octet-stream');
        header('Content-Disposition: attachment; filename="'.$name.'"');
        header('Content-Length: '.filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');

        while (ob_get_level() > 0) { @ob_end_clean(); }
        readfile($path);
        exit;
    }
}
