<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 * Outgoing mail transport for CI3's email library.
 *
 * `mail` (PHP's mail(), which every cPanel account has working out of the box)
 * is the default, because requiring SMTP credentials to send a password-reset
 * email would put a terminal-free deployment back in the support queue. Set
 * VP_MAIL_DRIVER=smtp with the values from cPanel → Email Accounts for a
 * proper authenticated sender.
 */
require_once APPPATH.'core/Env.php';
Env::bootstrap(rtrim(realpath(APPPATH.'..'), DIRECTORY_SEPARATOR));

$windels_mail_driver = strtolower((string) Env::get('MAIL_DRIVER', 'mail'));

$config['protocol']    = in_array($windels_mail_driver, array('smtp', 'sendmail', 'mail'), TRUE)
    ? $windels_mail_driver
    : 'mail';
$config['smtp_host']   = (string) Env::get('SMTP_HOST', '');
$config['smtp_port']   = Env::get_int('SMTP_PORT', 587);
$config['smtp_user']   = (string) Env::get('SMTP_USER', '');
$config['smtp_pass']   = (string) Env::get('SMTP_PASSWORD', '');
$config['smtp_crypto'] = (string) Env::get('SMTP_CRYPTO', 'tls');
$config['smtp_timeout'] = 15;
$config['mailtype']    = 'html';
$config['charset']     = 'utf-8';
$config['newline']     = "\r\n";
$config['crlf']        = "\r\n";
$config['wordwrap']    = TRUE;
$config['validate']    = TRUE;
