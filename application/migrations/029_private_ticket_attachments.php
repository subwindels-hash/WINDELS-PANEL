<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Migration 029 — move support attachments out of the document root.
 *
 * Until now every ticket attachment was written to `assets/uploads` and served
 * by the web server directly. The file name is 32 random hex characters, so
 * the URL is unguessable — but unguessable is not the same as authorised.
 * Anyone who came into possession of the link kept the file for ever: a
 * forwarded email, a `Referer` header sent to a third-party site, a screen
 * shared on a call, browser history on a shared machine, a staff member who
 * has since left. Closing the ticket, refunding the order or deleting the
 * account changed nothing, and support attachments are precisely where
 * customers put bank statements, ID photographs and payment screenshots.
 *
 * From migration 029 those files live under the private storage root and are
 * readable only through `Attachment::ticket()`, which checks that the caller
 * is signed in and is either the ticket's owner or support staff.
 *
 * This migration moves the files that already exist and rewrites the two
 * places their location is recorded (`media.url` / `media.storage_key` and
 * `ticket_attachments.file_url`, which is what the thread view renders).
 *
 * Re-runnable: rows already carrying the private prefix are skipped, and a
 * file that has already been moved is not moved twice.
 *
 * NOTE: the old URLs stop working the moment this runs. That is the entire
 * point — a link that leaked before the upgrade must not survive it.
 */
class Migration_Private_ticket_attachments extends CI_Migration {

    /** Creates no tables; declared for the schema linter. */
    public static function statements() {
        return array();
    }

    /** Creates no tables; declared for the schema linter. */
    public static function tables() {
        return array();
    }

    public function up() {
        // A fresh install has nothing to move, and `media` may not exist yet
        // on a partially-migrated database.
        if (!$this->db->table_exists('media')) return;

        require_once APPPATH.'libraries/MediaService.php';
        require_once APPPATH.'core/Env.php';

        $paths   = Env::writable_paths();
        $dir     = rtrim(str_replace('\\', '/', $paths['storage']), '/').'/'.MediaService::PRIVATE_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);

        $base = rtrim((string)config_item('base_url'), '/');
        $rows = $this->db->where('purpose', 'ticket')->get('media')->result();
        $moved = 0;

        foreach ($rows as $row) {
            $key = (string)$row->storage_key;
            if ($key === '' || strpos($key, MediaService::PRIVATE_PREFIX) === 0) continue;

            $name = basename(str_replace('\\', '/', $key));
            $from = rtrim(FCPATH, '/\\').'/'.ltrim($key, '/');
            $to   = $dir.'/'.$name;

            // Move if the source is still there; if it is not (already moved,
            // or cleaned up by hand) still rewrite the row, because the row is
            // what the panel trusts.
            if (is_file($from) && !is_file($to)) {
                if (!@rename($from, $to)) {
                    if (@copy($from, $to)) @unlink($from); else continue;
                }
                @chmod($to, 0600);
                $moved++;
            }

            $new_key = MediaService::PRIVATE_PREFIX.MediaService::PRIVATE_DIR.'/'.$name;
            $new_url = $base.'/support/attachment/'.$row->public_id;
            $old_url = (string)$row->url;

            $this->db->where('id', $row->id)->update('media', array(
                'url'         => $new_url,
                'storage_key' => $new_key,
            ));

            if ($this->db->table_exists('ticket_attachments') && $old_url !== '') {
                $this->db->where('file_url', $old_url)
                         ->update('ticket_attachments', array('file_url' => $new_url));
            }
        }

        log_message('info', 'migration 029: '.$moved.' support attachment(s) moved to private storage');
    }

    /**
     * Deliberately not reversible.
     *
     * Rolling back would copy customers' identity documents and bank
     * statements back into a publicly served directory. A migration must never
     * be the thing that re-opens a data leak, so `down()` leaves the files
     * where they are safe.
     */
    public function down() {
    }
}
