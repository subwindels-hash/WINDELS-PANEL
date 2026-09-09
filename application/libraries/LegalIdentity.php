<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * LegalIdentity — who, legally, is running this panel.
 *
 * The Terms, the Privacy Policy and the Refund Policy all shipped with the
 * same honest hole in them: *"the legal entity, registered address and
 * governing jurisdiction are those of the party that deployed this
 * instance"*. Honest, and useless to a customer. Nobody could tell who they
 * had contracted with, where to send a formal notice, whose consumer-
 * protection law applied, or which supervisory authority to complain to about
 * their data. There was no field anywhere in the panel to say any of it, so
 * the only fix was editing PHP.
 *
 * That is not a cosmetic gap. A prepaid wallet is money held on account: in
 * most jurisdictions the terms must name the trader, and a privacy notice
 * under GDPR-style law must name the controller. A panel that takes deposits
 * without naming its operator is one complaint away from a problem.
 *
 * Every value is an operator setting (Admin → Settings → Legal and company).
 * Nothing here invents a fact: when a value is absent the pages say so, in
 * those words, rather than printing an empty line or a fabricated placeholder.
 */
class LegalIdentity {

    /** Settings that make up the published identity, with display labels. */
    const FIELDS = array(
        'legal_entity_name'         => 'Legal entity name',
        'legal_registration_number' => 'Company registration number',
        'legal_registered_address'  => 'Registered address',
        'legal_jurisdiction'        => 'Governing law',
        'legal_courts'              => 'Courts',
        'legal_contact_email'       => 'Legal contact email',
        'legal_dpo_contact'         => 'Data protection contact',
        'legal_supervisory_authority' => 'Supervisory authority',
    );

    /**
     * The three that decide whether the pages can speak plainly.
     *
     * A trading name with no address is not an identity, and an address with
     * no governing law leaves section 20 of the terms unanswerable. Anything
     * outside this list improves the pages but is not required for them to be
     * honest.
     */
    const REQUIRED = array('legal_entity_name', 'legal_registered_address', 'legal_jurisdiction');

    private static $cache = null;

    /** Every legal setting, resolved, trimmed, memoised for the request. */
    public static function details() {
        if (self::$cache !== null) return self::$cache;

        $out = array();
        foreach (array_keys(self::FIELDS) as $key) {
            $out[$key] = trim((string)self::setting($key, ''));
        }

        // The legal contact falls back to the support address: an operator who
        // has published one contact route should not have to publish it twice,
        // and a notice clause pointing at nothing is worse than one pointing
        // at support.
        if ($out['legal_contact_email'] === '') {
            $out['legal_contact_email'] = trim((string)self::setting('support_email', ''));
        }
        if ($out['legal_dpo_contact'] === '') {
            $out['legal_dpo_contact'] = $out['legal_contact_email'];
        }
        // Courts default to the governing jurisdiction, which is the usual
        // arrangement and never a surprise to a reader.
        if ($out['legal_courts'] === '') {
            $out['legal_courts'] = $out['legal_jurisdiction'];
        }

        return self::$cache = $out;
    }

    /** True when the pages can name the operator instead of apologising. */
    public static function is_published() {
        return self::missing() === array();
    }

    /** Human labels for the required values that are still blank. */
    public static function missing() {
        $details = self::details();
        $out = array();
        foreach (self::REQUIRED as $key) {
            if ($details[$key] === '') $out[] = self::FIELDS[$key];
        }
        return $out;
    }

    /**
     * One-line identity for the footer and receipts:
     * "Acme Digital Ltd (RC 1234567), 12 Broad Street, Lagos, Nigeria".
     * Empty string when the operator has published nothing — the footer must
     * not render a stray comma.
     */
    public static function line() {
        $d = self::details();
        if ($d['legal_entity_name'] === '') return '';

        $name = $d['legal_entity_name'];
        if ($d['legal_registration_number'] !== '') {
            $name .= ' ('.$d['legal_registration_number'].')';
        }
        $address = self::address_inline();
        return $address === '' ? $name : $name.', '.$address;
    }

    /** The registered address as one line, however it was typed. */
    public static function address_inline() {
        $address = self::details()['legal_registered_address'];
        if ($address === '') return '';
        return trim(preg_replace('/\s*\R\s*/', ', ', $address), ', ');
    }

    /** Clear the memo — settings can change inside one long-running process. */
    public static function flush() {
        self::$cache = null;
    }

    private static function setting($key, $default = '') {
        if (!function_exists('get_instance')) return $default;
        $ci = @get_instance();
        if (!$ci) return $default;
        try {
            if (!isset($ci->Setting_model)) $ci->load->model('Setting_model');
            $value = $ci->Setting_model->get($key, $default);
            return $value === null ? $default : $value;
        } catch (Throwable $e) {
            // A legal page must render on a panel whose database is having a
            // bad day; it simply falls back to the unpublished wording.
            return $default;
        }
    }
}
