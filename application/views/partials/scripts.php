<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Global JavaScript. This is the single script include for the whole app;
// anything a page needs beyond this belongs in the component that owns it,
// and must be loaded through this partial so CSP/CSRF plumbing stays in one
// place.
?>
<script src="<?=base_url('assets/js/app.js')?>"></script>
