<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Shared public header: announcement bar + primary navigation. Every public
// page renders exactly this block, so header heights, logo size and spacing
// can never drift between routes.
$this->load->view('partials/announcement');
$this->load->view('partials/navbar');
