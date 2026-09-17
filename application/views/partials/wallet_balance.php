<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * The wallet balance, in every currency that means something to the customer.
 *
 * Three currencies can be in play at once and a balance shown in only one of
 * them is unanswerable:
 *
 *   - the WALLET's own currency — what the number literally is;
 *   - the BASE currency — what orders, prices and limits are denominated in,
 *     so "can I afford this?" is answered here;
 *   - the PAY currency (the default display currency) — what the customer is
 *     charged in when they top up, so "how much do I need to add?" is
 *     answered here.
 *
 * Whichever of the three coincide are printed once. The rate that produced
 * each secondary line is printed with it, because a converted figure nobody
 * can check is just a number.
 *
 * @var object $wallet
 * @var string $size   'lg' (default) or 'sm'
 */
$w_cur   = strtoupper((string)($wallet->currency ?? marvy_base_currency()));
$balance = (string)($wallet->balance ?? '0');
$base    = marvy_base_currency();
$pay     = marvy_pay_currency();
$size    = $size ?? 'lg';

// Units of X per 1 base, or null when the rate is unusable — a balance is
// never converted at an invented rate.
$rate_of = function ($code) use ($base) {
    if (strtoupper((string)$code) === $base) return '1.00000000';
    $r = (string)marvy_display_rate($code);
    return (is_numeric($r) && (float)$r > 0) ? $r : null;
};

$w_rate = $rate_of($w_cur);
// The balance expressed in the accounting currency.
$in_base = $w_rate === null ? null : bcdiv($balance, $w_rate, 8);

$lines = array();
if ($in_base !== null && $w_cur !== $base) {
    $lines[] = array($in_base, $base, marvy_rate_line($w_rate, $w_cur, $base));
}
if ($in_base !== null && $w_cur !== strtoupper((string)$pay) && strtoupper((string)$pay) !== $base) {
    $p_rate = $rate_of($pay);
    if ($p_rate !== null) {
        $lines[] = array(bcmul($in_base, $p_rate, 8), $pay, marvy_rate_line($p_rate, $pay, $base));
    }
}
?>
<div class="<?=$size === 'sm' ? 'mt-1 text-3xl font-bold' : 'ws-stat-value'?>" style="font-family:var(--font-display)">
  <?=marvy_money($balance, $w_cur)?>
</div>
<?php foreach ($lines as $l): list($amount, $code, $rate_line) = $l; ?>
  <div class="text-xs muted mt-1">
    ≈ <?=marvy_money($amount, $code)?>
    <?php if ($rate_line !== ''): ?><span class="mono">· <?=html_escape($rate_line)?></span><?php endif; ?>
  </div>
<?php endforeach; ?>
