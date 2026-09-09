<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Embedded site operator — deterministic intent matching + knowledge retrieval.
 *
 * Not a generative model. No third-party AI API. Phrase catalogues live in
 * application/libraries/ai/SiteOperatorPhrases.php; product copy lives in
 * SiteOperatorKnowledge.
 */
class SiteOperatorEngine {

    const MAX_MESSAGE = 1000;
    const HISTORY_TURNS = 8;

    /** @var SiteOperatorKnowledge */
    private $knowledge;

    public function __construct($knowledge = null) {
        if (!class_exists('SiteOperatorPhrases', false)) {
            require_once __DIR__.'/ai/SiteOperatorPhrases.php';
        }
        if ($knowledge === null) {
            if (!class_exists('SiteOperatorKnowledge', false)) {
                require_once __DIR__.'/SiteOperatorKnowledge.php';
            }
            $knowledge = new SiteOperatorKnowledge();
        }
        $this->knowledge = $knowledge;
    }

    /**
     * @param string $message
     * @param array  $history  list of {role: user|assistant, content: string}
     * @return array{ok:bool,intent:string,reply:string,links:array,suggestions:array,honest:bool,unanswered:bool,error?:string}
     *         unanswered is true only when nothing in the knowledge base
     *         covered the question — the caller's cue that a signed-in
     *         customer's question may deserve a support ticket.
     */
    public function reply($message, array $history = array()) {
        $text = trim((string)$message);
        if ($text === '') {
            return $this->fail('EMPTY', 'Write a question and I will answer from the site knowledge.');
        }
        $len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($len > self::MAX_MESSAGE) {
            return $this->fail('TOO_LONG', 'Please keep questions under 1,000 characters.');
        }

        $normalized = $this->normalize($text);
        $prior = $this->last_topic($history);
        $intent = $this->detect_intent($normalized, $prior);

        return $this->compose($intent, $normalized, $prior);
    }

    public function welcome() {
        $name = SiteOperatorKnowledge::site_name();
        return $this->ok(
            'welcome',
            'Hello — I am the on-site assistant for '.$name.'. I can explain services, pricing, accounts, FAQ topics and where to go next. I am not a cloud generative AI model and I cannot place orders or change your account.',
            array(
                array('label' => 'Services', 'href' => 'services'),
                array('label' => 'Pricing', 'href' => 'pricing'),
                array('label' => 'Create account', 'href' => 'register'),
            )
        );
    }

    /* ----------------------------- compose ---------------------------- */

    private function compose($intent, $normalized, $prior) {
        switch ($intent) {
            case 'greeting_morning':
                return $this->ok('greeting_morning',
                    'Good morning. I can help with '.SiteOperatorKnowledge::site_name().' — services, pricing, accounts, or finding a page. What would you like to know?',
                    $this->nav('register', 'services'));
            case 'greeting_afternoon':
                return $this->ok('greeting_afternoon',
                    'Good afternoon. Ask me about this panel’s services, pricing, or how to create an account.',
                    $this->nav('register', 'services'));
            case 'greeting_evening':
                return $this->ok('greeting_evening',
                    'Good evening. I am here if you want a walkthrough of services, pricing, or signing in.',
                    $this->nav('login', 'services'));
            case 'greeting_day':
                return $this->ok('greeting_day',
                    'Good day. I can explain what this site does and point you to the right page.',
                    $this->nav('services', 'pricing'));
            case 'greeting':
                return $this->ok('greeting',
                    'Hello. I can explain what '.SiteOperatorKnowledge::site_name().' sells, how the wallet works, and where to sign up or log in.',
                    $this->nav('register', 'services'));
            case 'farewell':
                return $this->ok('farewell',
                    'Good night. I will be here when you come back if you need help with the site.',
                    array());

            case 'courtesy':
                return $this->courtesy_reply($normalized);

            case 'wellbeing':
                return $this->ok('wellbeing',
                    'I am the local site assistant — running fine, and ready to help. I can explain this website, or we can pick a page to open.',
                    $this->nav('services', 'faq'),
                    array('What can you do?', 'What services do you offer?', 'How do I sign up?'));

            case 'help':
            case 'capabilities':
                return $this->ok('help',
                    'I can help with '.SiteOperatorKnowledge::site_name().' services, pricing, account registration, login, password reset, FAQs, privacy, terms, and navigating the website. I cannot create an account, move wallet funds, or place an order from this chat.',
                    $this->nav('services', 'pricing', 'faq', 'register'),
                    array('What services do you offer?', 'How do I sign up?', 'How much does it cost?'));

            case 'about':
                return $this->ok('about',
                    SiteOperatorKnowledge::site_name().' is a prepaid reseller panel for social-media services, Nigerian VTU and bills, virtual numbers, identity lookups, gift cards and a platform-owned marketplace. You add funds to a wallet and spend that balance here — there are no customer withdrawals. Start by creating an account, then browse Services or open Pricing.',
                    $this->nav('services', 'pricing', 'register', 'about'));

            case 'register':
                return $this->ok('register',
                    'To create your '.SiteOperatorKnowledge::site_name().' account, open the registration page, choose a username and a password of at least 8 characters, and accept the Terms. A wallet is created with the account. I cannot register you from this chat — use the form.',
                    $this->nav('register', 'terms'));

            case 'login':
                return $this->ok('login',
                    'Customer and staff sign-in is on the login page. Use your email or username and password. Staff who need the back office should use the separate staff sign-in. I cannot sign you in from this chat.',
                    $this->nav('login', 'forgot-password', 'admin/login'));

            case 'forgot':
                return $this->ok('forgot',
                    'Use Forgot password and enter your email or username. If an account matches, a reset link is emailed. The confirmation on screen is the same either way, so this form cannot be used to check whether an address is registered. I cannot reset the password for you.',
                    $this->nav('forgot-password', 'login', 'contact'));

            case 'pricing':
                return $this->pricing_reply();
            case 'wallet':
                return $this->ok('wallet',
                    'The wallet is a spending balance in the panel base currency (₦ unless the operator changes it). Add funds from the dashboard. There are no customer withdrawals. Default deposit minimum is ₦500.',
                    $this->nav('pricing', 'dashboard/add-funds'));

            case 'services':
                return $this->product_overview();
            case 'smm':
                return $this->product_card('smm');
            case 'vtu':
                return $this->product_card('vtu');
            case 'numbers':
                return $this->product_card('numbers');
            case 'identity':
                return $this->product_card('identity');
            case 'giftcards':
                return $this->product_card('giftcards');
            case 'marketplace':
                return $this->product_card('marketplace');
            case 'api':
                return $this->product_card('api');

            case 'faq':
                $faq = $this->best_faq($normalized);
                if ($faq && $faq['score'] >= 0.35) return $this->from_faq($faq);
                return $this->ok('faq',
                    'The FAQ covers accounts, wallet billing, SMM and VTU, security, the API and this assistant. Ask a specific question here, or open the FAQ page.',
                    $this->nav('faq'));

            case 'privacy':
                return $this->ok('privacy',
                    'The Privacy Policy describes what this instance actually stores: account details, hashed passwords, session cookies, ledger and order rows, and optional encrypted MFA secrets. Messages to this assistant stay on the server — they are not sent to a third-party AI API. The operator does not sell personal data. Full clauses are on the Privacy page.',
                    $this->nav('privacy'));

            case 'terms':
                return $this->ok('terms',
                    'The Terms of Service cover accounts, prepaid wallet billing, acceptable use, the on-site assistant, and disclaimers. The wallet is a spending balance — leftover deposits are not paid out as cash. Jurisdiction clauses are marked for the operator’s counsel. Open the Terms page for the full text.',
                    $this->nav('terms', 'acceptable-use', 'refund-policy'));

            case 'design_system':
                return $this->ok('design_system',
                    'The Design System page is the live reference for this product’s colours, type, buttons, forms, logo and imagery. It documents the classes the site actually uses, not a separate mock kit.',
                    $this->nav('design-system'));

            case 'contact':
                return $this->ok('contact',
                    'Use the contact form. Signed-in customers get a support ticket they can follow in the dashboard. Visitors’ messages are emailed to the operator. Include the public order ID. I cannot open the ticket for you.',
                    $this->nav('contact', 'dashboard/tickets', 'faq'));

            case 'how':
                $lines = array();
                foreach (SiteOperatorKnowledge::how_it_works() as $i => $step) {
                    $lines[] = ($i + 1).'. '.$step[0].' — '.$step[1];
                }
                return $this->ok('how', implode("\n", $lines), $this->nav('register', 'services'));

            case 'refund':
                return $this->ok('refund',
                    'Partial SMM deliveries can credit the undelivered quantity back to the wallet. Failed automated purchases are refunded when their engine marks them failed. Completed or revealed products are not automatically reversed. Details are on the Refund Policy.',
                    $this->nav('refund-policy', 'contact'));

            case 'admin':
                return $this->ok('admin',
                    'Staff sign in at the administrator login. After the password check the account must be SUPER_ADMIN, ADMIN or STAFF. Customer passwords are refused. I will not share credentials, seed passwords, or any restricted admin data.',
                    $this->nav('admin/login'));

            case 'dashboard':
                return $this->ok('dashboard',
                    'After you sign in, the customer dashboard is home for new orders, VTU, numbers, identity, gift cards, the marketplace, wallet deposits, tickets and API keys. Staff land in the admin area instead.',
                    $this->nav('dashboard', 'login'));

            case 'navigate':
                $page = $this->best_page($normalized);
                if ($page && ($page['_score'] ?? 0) >= 0.25) {
                    return $this->ok('navigate',
                        'The best match is '.$page['title'].': '.$page['summary'],
                        array(array('label' => $page['title'], 'href' => $page['path'])));
                }
                // A bare "take me somewhere" is a menu, not an unanswerable
                // question — no escalation.
                return $this->ok('navigate',
                    'I can open Services, Pricing, FAQ, Contact, Sign up, Login, or the legal pages. Which do you need?',
                    $this->nav('services', 'pricing', 'faq', 'register'));

            case 'action_refused':
                return $this->ok('action_refused',
                    'I cannot do that from this chat. I only explain the site and point at the right form. I will not pretend an account, payment, or order action succeeded.',
                    $this->nav('dashboard', 'contact'));
        }

        $faq = $this->best_faq($normalized);
        if ($faq && $faq['score'] >= 0.42) {
            return $this->from_faq($faq);
        }

        $page = $this->best_page($normalized);
        if ($page && ($page['_score'] ?? 0) >= 0.4) {
            // A "closest page" pointer is a scored guess, not an answer from
            // the knowledge base — the question still counts as unanswered.
            $out = $this->ok('unknown',
                'I am not sure I understood that. The closest page is '.$page['title'].' — '.$page['summary'].' I can also help with services, pricing, registration, login, FAQs, privacy and terms.',
                array(array('label' => $page['title'], 'href' => $page['path'])),
                SiteOperatorKnowledge::suggested_questions()
            );
            $out['unanswered'] = true;
            return $out;
        }

        // Nothing in the knowledge base covered this. `unanswered` tells the
        // caller that a signed-in customer's question may deserve a support
        // ticket; the page-pointer branch above is a (scored) guess from the
        // page index and does not set it.
        $out = $this->ok('unknown',
            'I am not sure I understood that. I can help with '.SiteOperatorKnowledge::site_name().' services, pricing, account registration, login, FAQs, privacy, terms, and navigating the website. What would you like to know?',
            $this->nav('services', 'pricing', 'faq', 'register'),
            SiteOperatorKnowledge::suggested_questions()
        );
        $out['unanswered'] = true;
        return $out;
    }

    private function courtesy_reply($normalized) {
        if ($this->contains($normalized, 'thank')) {
            return $this->ok('courtesy',
                'You\'re welcome. If you need anything else about '.SiteOperatorKnowledge::site_name().', I am here to help.',
                array());
        }
        if ($this->contains($normalized, 'welcome') || $this->contains($normalized, 'no problem')) {
            return $this->ok('courtesy', 'Glad that helped.', array());
        }
        return $this->ok('courtesy',
            'Sounds good. I am here if you need anything.',
            array());
    }

    private function pricing_reply() {
        $plans = SiteOperatorKnowledge::pricing_plans();
        $lines = array(
            'There is no public monthly subscription. You add funds to a prepaid wallet and pay the published rate for each service or product. Checkout freezes that rate.',
        );
        foreach ($plans as $plan) {
            $lines[] = '• '.$plan['name'].' ('.$plan['price_label'].') — '.$plan['price_note'];
        }
        $lines[] = 'Default deposit minimum is ₦500. Volume groups are assigned by staff, not purchased at checkout.';
        return $this->ok('pricing', implode("\n", $lines),
            $this->nav('pricing', 'contact', 'register'),
            array('Can I withdraw wallet funds?', 'How do volume discounts work?', 'What payment methods can I use?'));
    }

    private function product_overview() {
        $areas = SiteOperatorKnowledge::product_areas();
        $lines = array(SiteOperatorKnowledge::site_name().' sells these product areas — only those the operator has enabled and priced are actually buyable:');
        $links = array();
        foreach ($areas as $area) {
            $lines[] = '• '.$area['name'].' — '.$area['summary'];
            $links[] = array('label' => $area['name'], 'href' => $area['href']);
        }
        $links[] = array('label' => 'View Services', 'href' => 'services');
        return $this->ok('services', implode("\n", $lines), $links, array(
            'How much does that cost?',
            'How do I get started?',
            'Tell me about VTU',
        ));
    }

    private function product_card($id) {
        foreach (SiteOperatorKnowledge::product_areas() as $area) {
            if ($area['id'] !== $id) continue;
            $caps = array();
            foreach ($area['capabilities'] as $c) $caps[] = '• '.$c;
            return $this->ok($id,
                $area['name'].' is for '.$area['audience'].'. '.$area['summary']."\n\n".implode("\n", $caps),
                array(array('label' => $area['cta'], 'href' => $area['href']))
            );
        }
        return $this->product_overview();
    }

    private function from_faq($hit) {
        $item = $hit['item'];
        return $this->ok('faq', $item['a'], $this->nav('faq'), array(
            'What services can I order?',
            'How do I contact support?',
        ));
    }

    /* ----------------------------- matching --------------------------- */

    private function detect_intent($normalized, $prior) {
        if ($this->is_action_request($normalized)) {
            return 'action_refused';
        }

        if (in_array($normalized, SiteOperatorPhrases::short_courtesy(), true)) {
            return 'courtesy';
        }
        if (in_array($normalized, SiteOperatorPhrases::short_greetings(), true)) {
            if ($normalized === 'morning') return 'greeting_morning';
            if ($normalized === 'afternoon') return 'greeting_afternoon';
            if ($normalized === 'evening') return 'greeting_evening';
            if ($normalized === 'night') return 'farewell';
            return 'greeting';
        }

        $catalog = SiteOperatorPhrases::all();

        // Specific account paths before the generic “help me” catch-all:
        // “Help me create an account” is a registration request, not a
        // “what can you help me with” question.
        foreach (array('forgot', 'register', 'login') as $key) {
            if ($this->match_group($normalized, $catalog[$key])) {
                return $key;
            }
        }

        // Conversational intents next so "good morning" is never "unknown".
        foreach (array(
            'farewell', 'greeting_morning', 'greeting_afternoon',
            'greeting_evening', 'greeting_day', 'greeting',
            'wellbeing', 'help',
        ) as $key) {
            if ($this->match_group($normalized, $catalog[$key])) {
                return $key;
            }
        }

        if ($this->match_group($normalized, $catalog['courtesy']) && $this->word_count($normalized) <= 6) {
            return 'courtesy';
        }

        foreach (array(
            'about', 'design_system', 'privacy', 'terms', 'faq',
            'wallet', 'pricing', 'smm', 'vtu', 'numbers', 'identity',
            'giftcards', 'marketplace', 'api', 'admin', 'contact',
            'refund', 'how', 'dashboard', 'services', 'navigate',
        ) as $key) {
            if ($this->match_group($normalized, $catalog[$key])) {
                return $key;
            }
        }

        if ($prior && $this->match_group($normalized, $catalog['followup'])) {
            return $this->resolve_followup($normalized, $prior);
        }

        if ($prior && $this->word_count($normalized) <= 5 && $this->looks_underspecified($normalized)) {
            return $this->resolve_followup($normalized, $prior);
        }

        return 'unknown';
    }

    private function resolve_followup($normalized, $prior) {
        if ($this->contains($normalized, 'how much') || $this->contains($normalized, 'cost') || $this->contains($normalized, 'price')) {
            return 'pricing';
        }
        if ($this->contains($normalized, 'get started') || $this->contains($normalized, 'sign up') || $this->contains($normalized, 'register')) {
            return 'register';
        }
        if ($this->contains($normalized, 'where') || $this->contains($normalized, 'find') || $this->contains($normalized, 'take me')) {
            return $prior;
        }
        if ($this->contains($normalized, 'more') || $this->contains($normalized, 'about')) {
            return $prior;
        }
        return $prior;
    }

    private function last_topic(array $history) {
        $topical = array_flip(SiteOperatorPhrases::topical());
        for ($i = count($history) - 1; $i >= 0; $i--) {
            $row = $history[$i];
            if (!is_array($row) || ($row['role'] ?? '') !== 'user') continue;
            $n = $this->normalize(isset($row['content']) ? $row['content'] : '');
            if ($n === '') continue;
            $guess = $this->detect_intent($n, null);
            if (isset($topical[$guess])) return $guess;
        }
        return null;
    }

    private function match_group($normalized, array $needles) {
        foreach ($needles as $needle) {
            $n = $this->normalize($needle);
            if ($n === '') continue;
            if ($normalized === $n) return true;
            if (strlen($n) >= 4 && $this->contains($normalized, $n)) return true;
        }
        return false;
    }

    private function is_action_request($normalized) {
        $verbs = array(
            'place an order', 'place order', 'buy now', 'pay now',
            'add funds for me', 'fund my wallet', 'send money',
            'delete my account', 'enable mfa', 'disable mfa', 'impersonate',
            'open a ticket for me', 'cancel my order', 'refund me now',
        );
        foreach ($verbs as $v) {
            if ($this->contains($normalized, $v)) return true;
        }
        return false;
    }

    private function looks_underspecified($normalized) {
        $hints = array('that', 'it', 'this', 'there', 'more', 'cost', 'price', 'where', 'started');
        foreach ($hints as $h) {
            if ($this->contains($normalized, $h)) return true;
        }
        return false;
    }

    /* ----------------------------- retrieval -------------------------- */

    private function best_faq($normalized) {
        $best = null;
        foreach (SiteOperatorKnowledge::faqs() as $item) {
            $hay = $this->normalize($item['q'].' '.$item['a'].' '.$item['category']);
            $score = $this->overlap($normalized, $hay);
            $qnorm = $this->normalize($item['q']);
            if ($this->contains($normalized, $qnorm) || $this->contains($qnorm, $normalized)) {
                $score = max($score, 0.9);
            }
            if ($best === null || $score > $best['score']) {
                $best = array('item' => $item, 'score' => $score);
            }
        }
        return $best;
    }

    private function best_page($normalized) {
        $best = null;
        foreach (SiteOperatorKnowledge::pages() as $page) {
            $hay = $this->normalize($page['title'].' '.$page['summary'].' '.$page['keywords'].' '.$page['path']);
            $score = $this->overlap($normalized, $hay);
            if ($this->contains($normalized, $this->normalize($page['title']))) {
                $score = max($score, 0.8);
            }
            $page['_score'] = $score;
            if ($best === null || $score > $best['_score']) {
                $best = $page;
            }
        }
        return $best;
    }

    private function overlap($query, $haystack) {
        $q = $this->tokens($query);
        if (!$q) return 0.0;
        $hits = 0;
        $denom = 0;
        foreach ($q as $tok) {
            if (strlen($tok) < 3) continue;
            $denom++;
            if ($this->contains($haystack, $tok)) $hits++;
        }
        return $denom === 0 ? 0.0 : $hits / $denom;
    }

    private function tokens($text) {
        $parts = preg_split('/\s+/', $text);
        return $parts ? $parts : array();
    }

    private function word_count($text) {
        $t = $this->tokens($text);
        return $t ? count($t) : 0;
    }

    private function normalize($text) {
        $text = strtolower((string)$text);
        $text = str_replace(array("'", '’', '`'), '', $text);
        $text = preg_replace('/[^a-z0-9\s\+\-\/]/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function contains($haystack, $needle) {
        if ($needle === '') return false;
        return strpos($haystack, $needle) !== false;
    }

    private function nav() {
        $map = array(
            'register' => array('Sign up', 'register'),
            'login' => array('Log in', 'login'),
            'forgot-password' => array('Forgot password', 'forgot-password'),
            'admin/login' => array('Staff sign-in', 'admin/login'),
            'services' => array('View Services', 'services'),
            'pricing' => array('View Pricing', 'pricing'),
            'faq' => array('View FAQ', 'faq'),
            'privacy' => array('Privacy Policy', 'privacy'),
            'terms' => array('Terms of Service', 'terms'),
            'acceptable-use' => array('Acceptable Use', 'acceptable-use'),
            'refund-policy' => array('Refund Policy', 'refund-policy'),
            'contact' => array('Contact', 'contact'),
            'about' => array('About', 'about'),
            'design-system' => array('Design System', 'design-system'),
            'dashboard' => array('Dashboard', 'dashboard'),
            'dashboard/add-funds' => array('Add funds', 'dashboard/add-funds'),
            'dashboard/tickets' => array('Your tickets', 'dashboard/tickets'),
            'api/docs' => array('API docs', 'api/docs'),
        );
        $out = array();
        foreach (func_get_args() as $key) {
            if (!isset($map[$key])) continue;
            $out[] = array('label' => $map[$key][0], 'href' => $map[$key][1]);
        }
        return $out;
    }

    private function ok($intent, $reply, array $links = array(), array $suggestions = array()) {
        if (!$suggestions) $suggestions = SiteOperatorKnowledge::suggested_questions();
        return array(
            'ok' => true,
            'intent' => $intent,
            'reply' => $reply,
            'links' => $links,
            'suggestions' => array_slice($suggestions, 0, 6),
            'honest' => true,
            'unanswered' => false,
        );
    }

    private function fail($code, $message) {
        return array(
            'ok' => false,
            'intent' => 'error',
            'reply' => $message,
            'links' => array(),
            'suggestions' => SiteOperatorKnowledge::suggested_questions(),
            'honest' => true,
            'unanswered' => false,
            'error' => $code,
        );
    }
}
