<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MediaService — the panel's only file-upload path.
 *
 * Nothing in this build accepted a file before now: `media` was created in
 * migration 008 and never written to, and the `upload` config in
 * `config/marvy.php` had no reader. That means this class introduces a
 * capability the application did not previously have, and an upload endpoint
 * is the single most reliably exploited feature in a PHP admin panel.
 *
 * The document root is the repository root — `index.php` sits beside
 * `assets/` — so anything written under `assets/` is directly fetchable by
 * URL. A `.php` file placed there is not a stored file, it is remote code
 * execution. Every rule below exists because of that one fact:
 *
 *   - **The extension is chosen by us, never by the uploader.** It is derived
 *     from the sniffed MIME type through a fixed map, so `shell.php` uploaded
 *     as a PNG is stored as `<random>.png` and `evil.php.png` cannot round-trip
 *     back to `.php`.
 *   - **The name is random.** The original filename is recorded in the
 *     database for display and never used on disk, which disposes of path
 *     traversal, null bytes, overlong names and case-insensitive collisions
 *     in one move.
 *   - **The type is sniffed, not trusted.** `$_FILES['type']` is attacker-
 *     supplied. Images are additionally put through `getimagesize()`, so a
 *     PHP script with a `GIF89a` prefix fails: it has no valid dimensions.
 *   - **SVG is refused.** It is an image everywhere else and a script host
 *     here — an SVG can carry `<script>` and executes on view, same-origin.
 *   - **A hardened `.htaccess` is written beside the files** switching off
 *     PHP execution. Defence in depth: the extension allow-list is the real
 *     control, and this is what saves you when it is wrong.
 *
 * Uploads are deliberately *not* deduplicated by content hash — two brands
 * may legitimately upload the same logo, and deleting one must not blank the
 * other's.
 */
class MediaService {

    /** Sniffed MIME → the extension we will store it under. */
    const ALLOWED = array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    );

    /** What each file may be attached to. */
    const PURPOSES = array('branding', 'blog', 'service', 'avatar', 'ticket', 'marketplace');

    /**
     * Purposes whose files are NOT public.
     *
     * A branding logo or a blog image is meant to be fetched by anyone with
     * the page. A support attachment is not: it is the screenshot of a failed
     * payment, a bank statement, a passport photo sent to prove an identity
     * order. Those used to land in `assets/uploads` like everything else —
     * directly fetchable by URL, protected only by the filename being random.
     * That is not authorisation. Anyone who ever saw the link (a forwarded
     * email, a referer header, a shared screen, a staff member who has since
     * left) kept the file for ever, and closing the ticket changed nothing.
     *
     * Files for these purposes are written OUTSIDE the document root and are
     * served only by `Attachment::ticket()`, which checks who is asking.
     */
    const PRIVATE_PURPOSES = array('ticket');

    /** Where uploads land, relative to the document root. */
    const DIR = 'assets/uploads';

    /** Where private uploads land, relative to the storage root. */
    const PRIVATE_DIR = 'ticket_attachments';

    /**
     * Marks a `storage_key` that lives under the private storage root instead
     * of the document root. Prefixed rather than guessed, so `path_for()`
     * never has to infer which of two bases a row belongs to.
     */
    const PRIVATE_PREFIX = 'storage:';

    private $ci;

    public function __construct() {
        $this->ci =& get_instance();
        $this->ci->load->model('Media_model');
    }

    /* ------------------------------ reading ----------------------------- */

    public function grid(array $filters = array(), $limit = 40, $offset = 0) {
        return array(
            'rows'  => $this->ci->Media_model->admin_search($filters, $limit, $offset),
            'total' => $this->ci->Media_model->admin_count($filters),
        );
    }

    public function find($public_id) {
        return $this->ci->Media_model->find_by_public_id($public_id);
    }

    /** Largest upload we accept, in bytes, from config. */
    public function max_bytes() {
        $cfg = $this->ci->config->item('upload');
        $kb  = is_array($cfg) && !empty($cfg['max_size_kb']) ? (int)$cfg['max_size_kb'] : 5120;
        return $kb * 1024;
    }

    /* ------------------------------ writing ----------------------------- */

    /**
     * Store one uploaded file.
     *
     * `$file` is a `$_FILES` entry. `$mover` exists only so tests can stand in
     * for `move_uploaded_file()`, which refuses anything that did not arrive
     * over HTTP; production must never pass it.
     */
    public function store(array $file, $purpose, $uploader_id, $mover = null) {
        $purpose = in_array($purpose, self::PURPOSES, true) ? $purpose : 'branding';

        $err = $this->upload_error($file);
        if ($err !== null) return $this->err('UPLOAD', $err);

        $tmp  = $file['tmp_name'];
        $size = (int)$file['size'];
        if ($size <= 0)                 return $this->err('EMPTY', 'That file is empty.');
        if ($size > $this->max_bytes()) {
            return $this->err('TOO_BIG', 'That file is larger than the '
                .number_format($this->max_bytes() / 1048576, 1).' MB limit.');
        }

        // Sniff the real type. $_FILES['type'] is supplied by the client and
        // is worth nothing.
        $mime = $this->sniff($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            return $this->err('BAD_TYPE', 'Files of type "'.$mime.'" are not accepted. '
                .'Allowed: JPEG, PNG, GIF, WebP and PDF.');
        }

        // An image must actually decode as one. This is what stops a PHP
        // script wearing a GIF89a header.
        if ($mime !== 'application/pdf') {
            $info = @getimagesize($tmp);
            if ($info === false || empty($info[0]) || empty($info[1])) {
                return $this->err('BAD_IMAGE', 'That file claims to be an image but does not decode as one.');
            }
        }

        // We choose the extension; the uploader never does.
        $ext  = self::ALLOWED[$mime];
        $name = bin2hex(random_bytes(16)).'.'.$ext;

        $private = self::is_private($purpose);
        $dir = $private ? $this->private_dir() : $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return $this->err('STORAGE', 'The upload directory could not be created.');
        }
        $this->harden($dir);

        $dest = $dir.'/'.$name;
        $ok = $mover ? call_user_func($mover, $tmp, $dest) : @move_uploaded_file($tmp, $dest);
        if (!$ok) return $this->err('STORAGE', 'The file could not be saved.');
        // A private file is readable by the web user and nobody else: it is
        // never served by the web server, only read by PHP.
        @chmod($dest, $private ? 0600 : 0644);

        $public_id = strtoupper(bin2hex(random_bytes(13)));

        $this->ci->db->insert('media', array(
            'public_id'   => $public_id,
            'uploader_id' => $uploader_id ? (int)$uploader_id : null,
            // A private file has no direct URL at all. Its "url" is the
            // authorising route, so every existing caller — including the
            // ticket thread view, which renders `file_url` straight into an
            // <a href> — gets the checked path without knowing anything
            // changed.
            'url'         => $private
                ? rtrim(base_url('support/attachment/'.$public_id), '/')
                : base_url(self::DIR.'/'.$name),
            'storage_key' => $private
                ? self::PRIVATE_PREFIX.self::PRIVATE_DIR.'/'.$name
                : self::DIR.'/'.$name,
            // Shown in the library, never used to build a path.
            'file_name'   => mb_substr($this->clean_name($file['name'] ?? $name), 0, 255),
            'mime_type'   => $mime,
            'size'        => $size,
            'purpose'     => $purpose,
            'created_at'  => gmdate('Y-m-d H:i:s'),
        ));

        return array('ok' => true, 'error' => null, 'code' => null,
                     'media' => $this->ci->Media_model->find_by_id($this->ci->db->insert_id()));
    }

    /** Delete the row and the file behind it. */
    public function delete($media) {
        $path = $this->path_for($media);
        // Confine the unlink to the upload directory, whatever the row says.
        if ($path !== null && is_file($path)) @unlink($path);
        $this->ci->db->where('id', $media->id)->delete('media');
        return array('ok' => true, 'error' => null, 'code' => null);
    }

    /**
     * Absolute path for a stored file, or null if the row points outside the
     * upload directory.
     *
     * `storage_key` is written by this class alone, but a delete that trusts a
     * database string to build a filesystem path is one SQL bug away from
     * removing arbitrary files.
     */
    public function path_for($media) {
        $key = (string)(isset($media->storage_key) ? $media->storage_key : '');
        if ($key === '') return null;

        if (strpos($key, self::PRIVATE_PREFIX) === 0) {
            $rel  = substr($key, strlen(self::PRIVATE_PREFIX));
            $base = realpath($this->private_dir());
            if ($base === false) return null;
            // basename() alone: a row that says `../../.env` resolves to
            // `.env` inside the attachment directory and then fails realpath.
            $full = realpath($base.'/'.basename(str_replace('\\', '/', $rel)));
            if ($full === false) return null;
            if (strpos($full, $base.DIRECTORY_SEPARATOR) !== 0) return null;
            return $full;
        }

        $base = realpath($this->dir());
        $full = realpath(FCPATH.$key);
        if ($base === false || $full === false) return null;
        if (strpos($full, $base.DIRECTORY_SEPARATOR) !== 0) return null;
        return $full;
    }

    /** True when files for this purpose must never be web-reachable. */
    public static function is_private($purpose) {
        return in_array($purpose, self::PRIVATE_PURPOSES, true);
    }

    /** True when this media row is stored outside the document root. */
    public static function is_private_row($media) {
        $key = (string)(isset($media->storage_key) ? $media->storage_key : '');
        return strpos($key, self::PRIVATE_PREFIX) === 0;
    }

    /* ------------------------------ helpers ----------------------------- */

    private function dir() {
        return rtrim(FCPATH, '/\\').'/'.self::DIR;
    }

    /**
     * The private attachment directory, under the same storage root the logs
     * and cache use — a path the deployment docs already require to be
     * writable and which `.htaccess` already refuses to serve, and which on a
     * correctly configured host sits outside the document root entirely.
     */
    public function private_dir() {
        require_once APPPATH.'core/Env.php';
        $paths = Env::writable_paths();
        return rtrim(str_replace('\\', '/', $paths['storage']), '/').'/'.self::PRIVATE_DIR;
    }

    /**
     * Belt and braces: switch off script execution inside the upload folder.
     *
     * The extension allow-list is the control that matters. This is what
     * limits the damage on the day the allow-list turns out to be wrong.
     */
    private function harden($dir) {
        $file = $dir.'/.htaccess';
        if (file_exists($file)) return;

        // The private store gets a blanket deny, not the "data, never code"
        // guard: nothing in it should ever reach a browser directly. A support
        // attachment is a bank statement, and the only reader is PHP.
        if (strpos(str_replace('\\', '/', $dir), '/'.self::PRIVATE_DIR) !== false) {
            @file_put_contents($file, implode("\n", array(
                '# Runtime directory — never served over HTTP.',
                '<IfModule mod_authz_core.c>',
                '    Require all denied',
                '</IfModule>',
                '<IfModule !mod_authz_core.c>',
                '    Order deny,allow',
                '    Deny from all',
                '</IfModule>',
            ))."\n");
            return;
        }

        @file_put_contents($file, implode("\n", array(
            '# Uploaded files are data, never code.',
            'php_flag engine off',
            'AddType text/plain .php .phtml .php3 .php4 .php5 .php7 .phps .cgi .pl .py .sh',
            '<IfModule mod_rewrite.c>',
            '  RewriteEngine Off',
            '</IfModule>',
            'Options -ExecCGI -Indexes',
        ))."\n");
    }

    private function sniff($tmp) {
        if (function_exists('finfo_open')) {
            $f = @finfo_open(FILEINFO_MIME_TYPE);
            if ($f) {
                $mime = @finfo_file($f, $tmp);
                @finfo_close($f);
                if ($mime) return strtolower(explode(';', $mime)[0]);
            }
        }
        // finfo is compiled in almost everywhere; getimagesize covers the
        // image cases if it is not, and anything else is refused.
        $info = @getimagesize($tmp);
        if ($info !== false && !empty($info['mime'])) return strtolower($info['mime']);
        return 'application/octet-stream';
    }

    /** A display name, stripped of anything that could be read as a path. */
    private function clean_name($name) {
        $name = str_replace(array("\0", "\r", "\n"), '', (string)$name);
        $name = basename($name);
        return $name === '' ? 'file' : $name;
    }

    private function upload_error(array $file) {
        $code = isset($file['error']) ? (int)$file['error'] : UPLOAD_ERR_NO_FILE;
        switch ($code) {
            case UPLOAD_ERR_OK:        return null;
            case UPLOAD_ERR_NO_FILE:   return 'Choose a file to upload.';
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE: return 'That file is larger than the server allows.';
            case UPLOAD_ERR_PARTIAL:   return 'The upload did not finish. Try again.';
            default:                   return 'The upload failed (error '.$code.').';
        }
    }

    private function err($code, $message) {
        return array('ok' => false, 'error' => $message, 'code' => $code, 'media' => null);
    }
}
