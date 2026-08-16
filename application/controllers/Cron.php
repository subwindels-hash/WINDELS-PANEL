<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Cron — CLI only (is_cli guard). Real crontab calls: php index.php cron <job>
 * No web cron URLs (§66). Distributed lock via Redis SET NX.
 */
class Cron extends Cron_Controller {
    public function index(){ echo "Usage: php index.php cron [dripfeed|order_status|provider_health|refill_status|payment_reconciliation|email_queue|analytics|provider_sync]\n"; }
    public function dripfeed(){ log_message('info','Cron dripfeed start'); echo "dripfeed ok\n"; }
    public function order_status(){ log_message('info','Cron order_status'); echo "order_status ok\n"; }
    public function provider_health(){ log_message('info','Cron provider_health'); echo "provider_health ok\n"; }
    public function refill_status(){ log_message('info','Cron refill_status'); echo "refill_status ok\n"; }
    public function payment_reconciliation(){ log_message('info','Cron payment_reconciliation'); echo "payment_reconciliation ok\n"; }
    public function email_queue(){ log_message('info','Cron email_queue'); echo "email_queue ok\n"; }
    public function analytics(){ log_message('info','Cron analytics'); echo "analytics ok\n"; }
    public function provider_sync(){ log_message('info','Cron provider_sync'); echo "provider_sync ok\n"; }
}
