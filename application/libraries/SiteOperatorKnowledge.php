<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Maintainable knowledge for the public site and the embedded site operator.
 *
 * This is not a generative model. It is the structured product copy the
 * assistant retrieves and the marketing/legal pages can reuse so answers stay
 * consistent as the panel changes.
 */
class SiteOperatorKnowledge {

    const EFFECTIVE_DATE = '19 August 2026';
    const UPDATED_DATE   = '22 August 2026';

    public static function site_name() {
        if (function_exists('marvy_site_name')) return marvy_site_name();
        return 'MarvySocials';
    }

    public static function tagline() {
        if (function_exists('marvy_site_tagline')) return marvy_site_tagline();
        return 'Prepaid commerce for social media, VTU, virtual numbers, identity, gift cards and digital goods';
    }

    public static function pages() {
        return array(
            array('path' => '', 'title' => 'Home', 'summary' => 'Public homepage for '.self::site_name().'.', 'keywords' => 'home start welcome'),
            array('path' => 'services', 'title' => 'Services', 'summary' => 'Browse SMM services and the other product areas the panel sells.', 'keywords' => 'services catalogue smm order'),
            array('path' => 'pricing', 'title' => 'Pricing', 'summary' => 'Prepaid wallet pricing, volume groups and custom rates.', 'keywords' => 'pricing price cost wallet deposit'),
            array('path' => 'faq', 'title' => 'FAQ', 'summary' => 'Answers about accounts, orders, billing, security and the API.', 'keywords' => 'faq questions help'),
            array('path' => 'about', 'title' => 'About', 'summary' => 'What '.self::site_name().' is and who it is for.', 'keywords' => 'about company who'),
            array('path' => 'contact', 'title' => 'Contact', 'summary' => 'Contact support or open a ticket if you are signed in.', 'keywords' => 'contact support email ticket'),
            array('path' => 'blog', 'title' => 'Blog', 'summary' => 'Guides and product updates published by staff.', 'keywords' => 'blog news guides'),
            array('path' => 'terms', 'title' => 'Terms of Service', 'summary' => 'Legal terms for using the platform.', 'keywords' => 'terms legal conditions'),
            array('path' => 'privacy', 'title' => 'Privacy Policy', 'summary' => 'How account, usage and order data is handled.', 'keywords' => 'privacy data cookies'),
            array('path' => 'refund-policy', 'title' => 'Refund Policy', 'summary' => 'When wallet refunds and partial deliveries apply.', 'keywords' => 'refund cancel partial'),
            array('path' => 'acceptable-use', 'title' => 'Acceptable Use', 'summary' => 'What you may and may not use the panel for.', 'keywords' => 'acceptable use abuse prohibited'),
            array('path' => 'design-system', 'title' => 'Design System', 'summary' => 'Brand tokens and UI components used across the product.', 'keywords' => 'design system components'),
            array('path' => 'api/docs', 'title' => 'Reseller API docs', 'summary' => 'HTTP API for placing and checking orders programmatically.', 'keywords' => 'api reseller docs key'),
            array('path' => 'login', 'title' => 'Log in', 'summary' => 'Customer and staff sign-in.', 'keywords' => 'login sign in account'),
            array('path' => 'register', 'title' => 'Create account', 'summary' => 'Open a customer account and wallet.', 'keywords' => 'register signup create account'),
            array('path' => 'forgot-password', 'title' => 'Forgot password', 'summary' => 'Request a password reset email.', 'keywords' => 'forgot reset password'),
            array('path' => 'admin/login', 'title' => 'Staff sign-in', 'summary' => 'Separate administrator login. Customer accounts are refused.', 'keywords' => 'admin staff login'),
            array('path' => 'dashboard', 'title' => 'Customer dashboard', 'summary' => 'Signed-in customer home for orders, wallet and support.', 'keywords' => 'dashboard panel account'),
        );
    }

    /**
     * Product areas the panel actually implements. Do not invent modules.
     */
    public static function product_areas() {
        return array(
            array(
                'id' => 'smm',
                'name' => 'Social media services',
                'audience' => 'Creators, agencies and resellers',
                'href' => 'services',
                'cta' => 'Browse SMM services',
                'summary' => 'Order followers, likes, views, comments and related social services from a live catalogue. Pricing is frozen at checkout. Refill, cancel, drip-feed, mass order and subscription options are available when the individual service supports them.',
                'capabilities' => array(
                    'Searchable public catalogue with category, platform and type filters',
                    'Wallet checkout with the rate locked when the order is placed',
                    'Order tracking, refill requests and cancellation where the service allows it',
                    'Drip-feed, subscriptions and mass order for supported services',
                    'Reseller HTTP API with per-key scopes, IP allowlists and rate limits',
                ),
            ),
            array(
                'id' => 'vtu',
                'name' => 'VTU and bills',
                'audience' => 'Customers who need Nigerian airtime, data and bill payment',
                'href' => 'dashboard/vtu',
                'cta' => 'Open VTU',
                'summary' => 'Buy airtime, mobile data, cable TV, electricity units and exam pins from your wallet. Networks and products are configured by the operator; live fulfilment depends on a connected VTU provider.',
                'capabilities' => array(
                    'Airtime for MTN, Glo, Airtel and 9mobile',
                    'Data bundles, DSTV / GOtv / StarTimes, electricity and WAEC / NECO / JAMB pins',
                    'Meter and smart-card verification before purchase where the provider supports it',
                    'Receipts and a dedicated VTU history',
                ),
            ),
            array(
                'id' => 'numbers',
                'name' => 'Virtual numbers',
                'audience' => 'Customers who need a temporary number for an SMS or OTP',
                'href' => 'dashboard/numbers',
                'cta' => 'Open numbers',
                'summary' => 'Rent a virtual number for a supported service (WhatsApp, Telegram, Google and others), wait for the SMS, then cancel or release the number. Products appear after a number provider is connected and priced.',
                'capabilities' => array(
                    'Country and service catalogue (Nigeria, Ghana, Kenya, South Africa, UK, US, India and more)',
                    'Status checks while waiting for the message',
                    'Cancel, release and report flows from the reservation detail',
                ),
            ),
            array(
                'id' => 'identity',
                'name' => 'Identity verification',
                'audience' => 'Businesses that need NIN or BVN confirmation',
                'href' => 'dashboard/identity',
                'cta' => 'Open identity checks',
                'summary' => 'Run NIN and BVN lookups against a connected identity provider. Products stay hidden until an operator sets a real price, because each lookup has a vendor cost. Results are retained only for the configured retention window.',
                'capabilities' => array(
                    'NIN verification, BVN verification and NIN-by-phone lookups',
                    'Wallet debit before the lookup is sent',
                    'Reveal controls and a retention/purge policy',
                ),
            ),
            array(
                'id' => 'giftcards',
                'name' => 'Gift cards',
                'audience' => 'Customers buying digital gift cards',
                'href' => 'dashboard/giftcards',
                'cta' => 'Open gift cards',
                'summary' => 'Purchase gift cards (Amazon, Apple, Google Play, Steam, Netflix, Spotify, Xbox, PlayStation and others once priced). Codes are stored encrypted and revealed only to authorised viewers.',
                'capabilities' => array(
                    'Brand catalogue with redeem instructions',
                    'Denominations imported from the connected gift-card vendor and priced by staff',
                    'Encrypted codes, reveal audit and refunds if a code is never issued',
                ),
            ),
            array(
                'id' => 'marketplace',
                'name' => 'Marketplace',
                'audience' => 'Customers buying platform-owned digital listings',
                'href' => 'dashboard/marketplace',
                'cta' => 'Open marketplace',
                'summary' => 'A storefront of listings the platform itself sells — digital goods, gaming items, accounts and software keys. There is no customer vendor or seller programme. Escrow releases after the configured window or staff fulfilment.',
                'capabilities' => array(
                    'Browse, search and buy platform listings',
                    'Secure delivery and reveal of fulfilment details',
                    'Accept delivery or open a dispute',
                ),
            ),
            array(
                'id' => 'api',
                'name' => 'Reseller API',
                'audience' => 'Resellers and agencies automating SMM orders',
                'href' => 'api/docs',
                'cta' => 'Read API docs',
                'summary' => 'A versioned JSON API for services, orders, mass orders, balance, refills and cancellations. Authenticate with an API key from your dashboard. Keys can be scoped, IP-allowlisted and rate-limited.',
                'capabilities' => array(
                    'Create and inspect orders, including mass orders',
                    'Check wallet balance and referral data',
                    'Request refills and cancellations',
                    'Interactive docs at /api/docs',
                ),
            ),
        );
    }

    public static function how_it_works() {
        return array(
            array('Create an account', 'Register with a username, email and password. A wallet in the panel base currency is created automatically. Email verification may be required before some actions.'),
            array('Add funds', 'Deposit through a payment method the operator has enabled (manual bank transfer is available by default; card and regional gateways are enabled only after credentials are configured). The wallet is a spending balance — there are no customer withdrawals.'),
            array('Place an order', 'Choose a service or product, confirm the frozen price, and pay from the wallet. The panel records a ledger entry and submits the order to the connected provider when one is configured.'),
            array('Track and follow up', 'Watch status in the dashboard. Request a refill or cancellation when the service supports it. Partial deliveries refund the undelivered quantity to the wallet. Open a ticket if you need a human.'),
        );
    }

    public static function advantages() {
        return array(
            array('One wallet, several product lines', 'SMM, VTU, numbers, identity, gift cards and the marketplace share the same account, ledger and support tools.'),
            array('Frozen checkout prices', 'The rate you accept is the rate you pay. User and price-group discounts are resolved before the order is committed.'),
            array('Audited ledger', 'Wallet balances move only through a double-entry ledger. Refunds, partials and staff adjustments leave an audit trail.'),
            array('Reseller-ready API', 'API keys, scopes, IP allowlists and published docs — the same order engine the dashboard uses.'),
            array('Staff back office', 'Orders, providers, catalogue, payments, tickets, content and RBAC live in a separate admin area.'),
            array('No invented live metrics', 'The public site describes what the panel does. It does not publish fake customer counts or guaranteed uptime figures.'),
        );
    }

    public static function security_practices() {
        return array(
            'Passwords are hashed (Argon2id when available, otherwise bcrypt). Unknown logins still run a dummy verify so timing does not reveal whether an email exists.',
            'Login, registration, password reset, contact and the site assistant are rate-limited. Repeat failures can lock an identifier or IP for a window.',
            'Sessions are regenerated after login. Logout is POST-only with a CSRF token so a third party cannot sign you out with a link.',
            'Optional TOTP two-factor authentication. Recovery codes are hashed; TOTP secrets are encrypted at rest.',
            'Administrator routes require a SUPER_ADMIN, ADMIN or STAFF role in addition to a valid session. Customer accounts cannot open the back office.',
            'Payment webhooks are signature-checked and credited idempotently so a replay does not double-fund a wallet.',
            'Provider credentials, MFA secrets and gift-card codes are encrypted at rest. Outbound HTTP verifies TLS and blocks private destinations unless explicitly allowed.',
            'The embedded site assistant runs inside this application. It does not call OpenAI, Anthropic, Gemini or any other third-party AI API.',
        );
    }

    public static function pricing_plans() {
        return array(
            array(
                'name' => 'Pay as you go',
                'audience' => 'Individual customers and new resellers',
                'model' => 'Prepaid wallet',
                'period' => 'No subscription',
                'price_label' => 'Service rates',
                'price_note' => 'You pay the published rate for each service or product. There is no monthly platform fee to open an account.',
                'features' => array(
                    'Free account and wallet in the panel base currency',
                    'Access to every product area the operator has enabled',
                    'Default retail price group',
                    'Support tickets and the public FAQ',
                    'Optional reseller API key',
                ),
                'limits' => 'Minimum and maximum quantities are set per service. Deposit minimum and maximum are set by the operator (defaults: ₦500 minimum, ₦5,000,000 maximum).',
                'upgrade' => 'Ask support to be moved to a volume price group once your usage justifies it.',
                'cta' => 'Create an account',
                'cta_href' => 'register',
                'status' => 'available',
            ),
            array(
                'name' => 'Volume groups',
                'audience' => 'Agencies and active resellers',
                'model' => 'Assigned price group',
                'period' => 'No subscription',
                'price_label' => 'Custom rates',
                'price_note' => 'Silver, Gold and Reseller groups exist in the panel. Staff assign a group; they are not self-serve checkout plans.',
                'features' => array(
                    'Lower per-1k rates on selected SMM services',
                    'Same wallet, orders, refill and API features',
                    'Group rate can still be overridden per user',
                    'Referral programme remains available',
                ),
                'limits' => 'Eligibility is decided by the operator. Groups do not unlock product areas that are not configured.',
                'upgrade' => 'Contact sales or open a ticket with typical monthly volume.',
                'cta' => 'Contact sales',
                'cta_href' => 'contact',
                'status' => 'contact',
            ),
            array(
                'name' => 'Custom / operator',
                'audience' => 'Businesses that need tailored catalogue, providers or process',
                'model' => 'Agreed with the operator',
                'period' => 'By agreement',
                'price_label' => 'Contact sales',
                'price_note' => 'Use this when you need dedicated provider routing, custom catalogues or contractual terms. Nothing here is a public price commitment.',
                'features' => array(
                    'Catalogue and provider setup discussed with staff',
                    'Manual payment methods and invoicing if enabled',
                    'Staff-managed marketplace listings',
                    'Security and retention settings reviewed together',
                ),
                'limits' => 'Availability depends on the operator’s providers and capacity.',
                'upgrade' => 'This is the top conversational path — there is no further self-serve tier.',
                'cta' => 'Contact sales',
                'cta_href' => 'contact',
                'status' => 'contact',
            ),
        );
    }

    /**
     * Comprehensive FAQ used when the database is empty and as assistant knowledge.
     * Categories match the product, not placeholder buckets.
     */
    public static function faqs() {
        return array(
            // General
            array('category' => 'General', 'q' => 'What is '.self::site_name().'?', 'a' => self::site_name().' is a reseller platform for social-media services, Nigerian VTU and bills, virtual numbers, identity lookups, gift cards and a platform-owned marketplace. You add funds to a wallet and spend that balance inside the panel. It does not pay out wallet withdrawals.'),
            array('category' => 'General', 'q' => 'Who is it for?', 'a' => 'Creators placing occasional orders, agencies running many orders, and resellers who want an API. Staff use a separate admin area to run catalogue, providers, payments and support.'),
            array('category' => 'General', 'q' => 'Do I need a subscription?', 'a' => 'No. Accounts are free to open. You pay for the services and products you order from a prepaid wallet. There is no public monthly SaaS plan.'),
            array('category' => 'General', 'q' => 'Which currency is used?', 'a' => 'The panel’s base currency is Nigerian Naira (₦) unless the operator changes it. Display conversion to other currencies may exist for reference; wallets and charges stay in the base currency.'),
            array('category' => 'General', 'q' => 'Where can I read the rules?', 'a' => 'Terms of Service, Privacy Policy, Refund Policy and Acceptable Use are linked in the footer. The design system documents the interface components the site uses.'),

            // Accounts
            array('category' => 'Accounts', 'q' => 'How do I create an account?', 'a' => 'Open the registration page, choose a username and password (at least 8 characters), and accept the Terms. A wallet is created with the account. Registration can be turned off by the operator.'),
            array('category' => 'Accounts', 'q' => 'Do I have to verify my email?', 'a' => 'If the operator requires email verification, we send a signed link that expires after 24 hours. You can still sign in; some sensitive actions may wait until the address is confirmed. You can resend the link from the dashboard.'),
            array('category' => 'Accounts', 'q' => 'I forgot my password. What now?', 'a' => 'Use Forgot password and enter your email or username. If an account matches, we email a reset link that expires in 60 minutes. The confirmation message is the same whether or not the account exists, so addresses cannot be probed.'),
            array('category' => 'Accounts', 'q' => 'Can I enable two-factor authentication?', 'a' => 'Yes. In Dashboard → Security you can enrol a TOTP app. After a correct password we ask for a 6-digit code (or a recovery code). Administrator accounts can be required to use MFA.'),
            array('category' => 'Accounts', 'q' => 'How do I log out?', 'a' => 'Use the Log out control. Logout is a POST with a CSRF token — visiting /logout in a browser will not sign you out.'),
            array('category' => 'Accounts', 'q' => 'Can I delete my account?', 'a' => 'Open a support ticket and ask staff to close the account. Closing stops further use; ledger history is retained as required to keep financial records consistent. See the Privacy Policy for retention.'),

            // Services
            array('category' => 'Services', 'q' => 'What can I order?', 'a' => 'The live SMM catalogue is on /services. Signed-in customers can also use VTU, virtual numbers, identity checks, gift cards and the marketplace from the dashboard, when the operator has enabled and priced those products.'),
            array('category' => 'Services', 'q' => 'How fast are SMM orders delivered?', 'a' => 'Each service card shows an average start time. Most automated services begin within minutes once a provider is connected. Drip-feed orders follow the interval you choose. The panel does not guarantee a specific completion time.'),
            array('category' => 'Services', 'q' => 'What is a refill?', 'a' => 'Services marked Refill let you request a top-up from the order detail page if the delivered quantity later drops, within that service’s refill window.'),
            array('category' => 'Services', 'q' => 'What is a partial order?', 'a' => 'If a provider delivers only part of the quantity, the order is marked PARTIAL and the undelivered portion is refunded to your wallet when partial refunds are enabled.'),
            array('category' => 'Services', 'q' => 'Can I cancel an order?', 'a' => 'Only when the service supports cancellation and the order is still in a cancellable state. Request it from the order detail page. Completed deliveries are not cancelled.'),
            array('category' => 'Services', 'q' => 'What are drip-feed and subscriptions?', 'a' => 'Drip-feed splits a quantity across an interval you choose. Subscriptions repeat an order on a schedule. Both require the service to support the feature and enough wallet balance for each run.'),
            array('category' => 'Services', 'q' => 'How does VTU work?', 'a' => 'From Dashboard → VTU pick airtime, data, cable, electricity or education, enter the destination, confirm the amount and pay from your wallet. Keep the receipt. Fulfilment needs a configured VTU provider.'),
            array('category' => 'Services', 'q' => 'Are virtual numbers always in stock?', 'a' => 'No. A number product only appears after a provider sync and a staff price. Stock and SMS delivery depend on the upstream vendor.'),
            array('category' => 'Services', 'q' => 'Why is identity verification empty?', 'a' => 'NIN and BVN products are seeded inactive without a price. Staff must set a price they have actually agreed with the identity vendor before the storefront lists them. That avoids selling a billable lookup at a guessed margin.'),
            array('category' => 'Services', 'q' => 'Who sells on the marketplace?', 'a' => 'Only the platform. There is no customer seller or vendor programme. Staff create listings; customers browse and buy.'),

            // Pricing
            array('category' => 'Pricing and billing', 'q' => 'How does pricing work?', 'a' => 'SMM services publish a rate, usually per 1,000 units. Your payable amount is quantity × resolved rate, frozen at checkout. VTU, numbers, identity, gift cards and marketplace items use their own product prices. There is no public flat monthly fee.'),
            array('category' => 'Pricing and billing', 'q' => 'What payment methods can I use?', 'a' => 'Manual / bank transfer is available by default. Stripe, PayPal, Paystack, Flutterwave, Razorpay and CoinPayments exist in the software and stay disabled until the operator adds live credentials. Never assume a gateway is active just because this documentation names it.'),
            array('category' => 'Pricing and billing', 'q' => 'What is the minimum deposit?', 'a' => 'The default minimum is ₦500 and the default maximum is ₦5,000,000. The operator can change both in settings.'),
            array('category' => 'Pricing and billing', 'q' => 'Can I withdraw wallet funds?', 'a' => 'No. The wallet is a spending balance for purchases on this panel. Affiliate commissions are accounted separately and paid according to the affiliate settings, not as a customer cash-out of leftover deposit.'),
            array('category' => 'Pricing and billing', 'q' => 'How do volume discounts work?', 'a' => 'Staff can assign Silver, Gold or Reseller price groups, or a per-user override. Those rates apply automatically at checkout. They are not purchased as a plan.'),
            array('category' => 'Pricing and billing', 'q' => 'When do I get a refund?', 'a' => 'Undelivered quantities on partial SMM orders return to the wallet. Failed VTU, number, identity or gift-card purchases are refunded when the relevant engine marks them failed or abandoned. Discretionary refunds go through support. See the Refund Policy.'),

            // Security
            array('category' => 'Security', 'q' => 'How are passwords stored?', 'a' => 'Only a one-way hash is stored. The panel never emails your password and staff cannot read it.'),
            array('category' => 'Security', 'q' => 'Do you sell my data?', 'a' => 'No. Account, order and technical data are used to run the service, prevent abuse and meet legal duties. The Privacy Policy lists processors such as payment gateways and fulfilment providers when they are configured.'),
            array('category' => 'Security', 'q' => 'Is the site assistant a cloud AI?', 'a' => 'No. The assistant is an embedded operational engine. It matches your question to this site’s knowledge and does not send the conversation to OpenAI, Anthropic, Gemini or any other third-party AI API. It cannot place orders or change your account.'),
            array('category' => 'Security', 'q' => 'What should I never send the panel?', 'a' => 'Never give social-network passwords, one-time codes you did not request here, or payment-card details outside a configured checkout. SMM orders only need a public link. Identity lookups only need the identifier the product asks for.'),

            // Support
            array('category' => 'Technical support', 'q' => 'How do I contact support?', 'a' => 'Signed-in customers should open a ticket from the dashboard or the contact form — the message becomes a thread you can follow. Visitors can use the contact form; the message is emailed to the support address. Include the order or transaction ID.'),
            array('category' => 'Technical support', 'q' => 'How fast do you reply?', 'a' => 'We aim to reply within one business day. That is an operational target, not a contractual uptime or response-time guarantee.'),
            array('category' => 'Technical support', 'q' => 'An order is stuck. What should I do?', 'a' => 'Open the order (or VTU / number / gift-card receipt) and note the public ID and status. If a refill or cancel action is offered, try that first. Otherwise open a ticket. Do not place a duplicate order unless support asks you to.'),

            // AI
            array('category' => 'AI assistant', 'q' => 'What can the site assistant do?', 'a' => 'It can explain services, pricing, FAQ topics, navigation and common account questions, and point you at the right page. It cannot log you in, move money, place orders, reset a password, or speak to a provider on your behalf.'),
            array('category' => 'AI assistant', 'q' => 'Why did the assistant say it cannot do that?', 'a' => 'If a request needs a signed-in action or a human decision, the assistant will say so and send you to the form or ticket that actually performs it. It will not pretend an action succeeded.'),

            // Admin
            array('category' => 'Administrators', 'q' => 'Where do staff sign in?', 'a' => 'Use /admin/login. After a valid password the account must have the SUPER_ADMIN, ADMIN or STAFF role. Ordinary customer credentials are rejected with the same invalid-credentials message used on failed logins.'),
            array('category' => 'Administrators', 'q' => 'How is the first administrator created?', 'a' => 'On a production import, the first administrator is created by the official database package or by `php index.php seed core` in development. Do not commit a live password to the repository. Rotate the initial password immediately after first login.'),
            array('category' => 'Administrators', 'q' => 'Can staff impersonate a customer?', 'a' => 'Only if they have the impersonation permission. The session is read-only, time-boxed and audited. Operators must end it with the dedicated control before they can change anything again.'),
        );
    }

    public static function suggested_questions() {
        return array(
            'What services can I order?',
            'How does pricing work?',
            'How do I create an account?',
            'How do I add funds?',
            'Is there a reseller API?',
            'How do I contact support?',
        );
    }

    public static function assistant_disclaimer() {
        return 'I am '.self::site_name().'’s on-site assistant. I answer from the platform’s built-in knowledge and navigation rules. I am not a cloud generative AI model, I do not call a third-party AI API, and I cannot place orders, move wallet funds, or change account settings.';
    }

    public static function data_inventory() {
        return array(
            'Account' => 'Username, email, optional first and last name, hashed password, role, status, timezone, locale, referral code, email-verification timestamp, MFA flag, last login IP and time.',
            'Session and security' => 'Session cookie, CSRF token, login-attempt rows (identifier, IP, user agent, success/failure), optional TOTP secret (encrypted) and hashed recovery codes, signed email-verify and password-reset tokens.',
            'Wallet and orders' => 'Wallet balance, ledger entries, SMM orders (link, quantity, charge, status), VTU transactions, number reservations, identity lookups, gift-card orders, marketplace orders, tickets and ticket messages.',
            'Device and technical' => 'IP address and user agent on auth, contact and rate-limited actions. Request IDs in application logs.',
            'Cookies' => 'A first-party session cookie required to stay signed in and to validate CSRF. No third-party advertising cookies ship in this codebase.',
            'Assistant' => 'Messages you send to the on-site assistant are processed locally to produce a reply. They are not forwarded to an external AI provider. Rate-limit rows may record that a request occurred.',
        );
    }

    public static function processors() {
        return array(
            array('Payment gateways', 'Stripe, PayPal, Paystack, Flutterwave, Razorpay, CoinPayments, plus manual bank transfer. Only gateways the operator enables receive payment data.'),
            array('SMM providers', 'Upstream social-media panels configured by staff. They receive the public link and quantity needed to fulfil an order — never your panel password.'),
            array('VTU', 'VTpass or another configured VTU adapter for airtime, data, cable, electricity and exam pins.'),
            array('Virtual numbers', '5sim or another configured number adapter.'),
            array('Identity', 'Dojah or another configured KYC adapter for NIN/BVN lookups. These checks are billable and retain whatever the vendor returns for the operator’s retention window.'),
            array('Gift cards', 'Reloadly or another configured gift-card adapter.'),
            array('Email', 'The configured SMTP server (MailHog in development).'),
            array('Object storage', 'Optional S3-compatible storage for media uploads.'),
        );
    }
}
