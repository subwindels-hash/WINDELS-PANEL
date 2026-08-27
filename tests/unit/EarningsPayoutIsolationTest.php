<?php
use PHPUnit\Framework\TestCase;

/**
 * The deposit wallet must stay non-withdrawable.
 *
 * Migration 018 removed customer withdrawals deliberately: a balance that can
 * be topped up with a payment method and then cashed out makes the platform a
 * money transmitter, which it is not licensed to be. Adding an earnings payout
 * feature is only safe while the two balances remain structurally separate.
 *
 * These tests are that guarantee, expressed as code rather than as a comment
 * someone has to remember. They fail if a future change lets a payout draw on
 * `wallets`, or reintroduces the withdrawal feature under a new name.
 */
class EarningsPayoutIsolationTest extends TestCase
{
    private static $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(dirname(__DIR__));
    }

    private function payoutService()
    {
        return file_get_contents(self::$root.'/application/libraries/PayoutService.php');
    }

    /**
     * A payout may only ever reserve rows from the earnings ledger.
     *
     * The reservation logic is the single place money is selected for
     * withdrawal, so it is the single place this could go wrong.
     */
    public function testPayoutsReserveOnlyFromTheEarningsLedger()
    {
        $src = $this->payoutService();

        $this->assertStringContainsString('available_for_user', $src,
            'payouts must draw on the earnings ledger');
        $this->assertStringContainsString("'AVAILABLE', 'LOCKED'", $src,
            'reservation must move earnings from AVAILABLE to LOCKED');

        // The only wallet interaction permitted is crediting *into* the wallet
        // when a user converts earnings into spending money. Debiting the
        // wallet to fund a payout must not exist.
        $this->assertStringNotContainsString('ledgerservice->charge', $src,
            'a payout must never charge the deposit wallet');
        $this->assertStringNotContainsString('ledgerservice->refund', $src,
            'a payout must never refund from the deposit wallet');
    }

    /** The one wallet write is a credit, and it is the conversion path. */
    public function testTheOnlyWalletWriteIsAnEarningsConversion()
    {
        $src = $this->payoutService();

        $credits = substr_count($src, 'ledgerservice->credit');
        $this->assertSame(1, $credits,
            'PayoutService should credit the wallet in exactly one place: WALLET_CREDIT conversion');
        $this->assertStringContainsString('EARNINGS_CONVERSION', $src,
            'the conversion must be labelled in the ledger so it is auditable');
    }

    /**
     * Earnings and deposits live in different tables.
     *
     * If a future migration merged them, the isolation above would be
     * meaningless, so the separation itself is pinned.
     */
    public function testEarningsAreNotStoredInTheWalletsTable()
    {
        $migration = file_get_contents(
            self::$root.'/application/migrations/023_referral_earnings_payouts.php'
        );

        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS earnings', $migration);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS payout_requests', $migration);

        // payout_requests must not carry a wallet reference: it has no business
        // knowing about the deposit balance at all.
        $this->assertDoesNotMatchRegularExpression(
            '~CREATE TABLE IF NOT EXISTS payout_requests[\s\S]*?wallet_id~i',
            $migration,
            'a payout request must not reference a wallet'
        );
    }

    /** The withdrawal feature removed in 018 must not have come back. */
    public function testTheOldWithdrawalFeatureHasNotReturned()
    {
        foreach (array('application/libraries/PayoutService.php',
                       'application/controllers/admin/Payouts.php',
                       'application/models/Payout_request_model.php') as $rel) {
            $src = file_get_contents(self::$root.'/'.$rel);
            $this->assertStringNotContainsStringIgnoringCase('withdrawal_request', $src,
                $rel.' must not resurrect the removed withdrawal tables');
        }

        // The tables 018 dropped must stay dropped.
        $migration = file_get_contents(
            self::$root.'/application/migrations/023_referral_earnings_payouts.php'
        );
        $this->assertStringNotContainsString('withdrawal_requests', $migration);
        $this->assertStringNotContainsString('withdrawal_events', $migration);
    }

    /**
     * Cash payouts must be off unless an operator explicitly enables them.
     *
     * Turning them on is a licensing, KYC and tax decision, so the default
     * cannot be "on".
     */
    public function testCashPayoutsAreDisabledByDefault()
    {
        $settings = file_get_contents(self::$root.'/application/libraries/SettingsService.php');

        $this->assertMatchesRegularExpression(
            "~'earnings_payouts_enabled'\s*=>\s*array\('bool',[^)]*?,\s*false\)~s",
            $settings,
            'earnings_payouts_enabled must default to false'
        );

        $service = file_get_contents(self::$root.'/application/libraries/EarningsService.php');
        $this->assertStringContainsString("'earnings_payouts_enabled', false", $service,
            'the service must also default the flag to false');
    }

    /** Converting earnings to spend credit stays available even with cash off. */
    public function testWalletConversionIsAllowedWhenCashPayoutsAreOff()
    {
        $src = $this->payoutService();
        $this->assertStringContainsString("\$method === 'BANK_TRANSFER' && !\$this->ci->earningsservice->payouts_enabled()", $src,
            'only BANK_TRANSFER should be gated on the cash-payout flag');
    }

    /** Nothing settles without a human recording a real transfer reference. */
    public function testAPayoutCannotSettleWithoutAReference()
    {
        $src = $this->payoutService();
        $this->assertStringContainsString("'NO_REFERENCE'", $src,
            'mark_paid must refuse an empty payout reference');
        $this->assertStringContainsString("self::STATUS_APPROVED, self::STATUS_PAID", $src,
            'only an approved payout may be marked paid');
    }
}
