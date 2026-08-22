<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Backward-compatible wrapper for the new global public shell.
// All callers should move to layouts/main, but any remaining reference to
// layouts/public still produces the identical design system.
$this->load->view('layouts/main', array(
    'content_view' => $content_view ?? '',
    'data'         => $data ?? array(),
));
