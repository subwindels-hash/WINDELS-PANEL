<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Phrase catalogues for the site operator.
 *
 * Organised by intent so the engine is not one giant conditional.
 * Longer / more specific phrases should be listed first inside a group
 * when the matcher uses “longest needle wins”.
 */
class SiteOperatorPhrases {

    public static function all() {
        return array(
            'farewell' => array(
                'have a good night', 'good night ai', 'good night', 'goodnight',
                'bye', 'goodbye', 'see you', 'talk later', 'thats all',
            ),
            'greeting_morning' => array(
                'good morning assistant', 'good morning ai', 'good morning there',
                'hello good morning', 'hi good morning', 'good morning',
            ),
            'greeting_afternoon' => array(
                'good afternoon assistant', 'good afternoon ai',
                'hello good afternoon', 'hi good afternoon', 'good afternoon',
            ),
            'greeting_evening' => array(
                'good evening assistant', 'good evening ai',
                'hi good evening', 'hello good evening', 'good evening',
            ),
            'greeting_day' => array(
                'good day assistant', 'good day ai', 'good day',
            ),
            'greeting' => array(
                'hey there', 'hi there', 'hello there',
                'hello assistant', 'hey assistant', 'hi assistant',
                'hello ai', 'hey ai', 'hi ai',
                'howdy', 'greetings', 'hello', 'hey',
            ),
            'courtesy' => array(
                'thank you so much', 'thanks a lot', 'thanks so much',
                'thank you', 'thanks', 'ok thanks', 'okay thanks',
                'you are welcome', 'youre welcome',
                'no problem', 'thats fine', 'that is fine',
                'i understand', 'got it', 'sounds good',
                'perfect', 'awesome', 'alright', 'okay', 'great',
                'nice', 'cool', 'good', 'ok',
            ),
            'wellbeing' => array(
                'what is going on with you', 'whats going on with you',
                'what is going on', 'whats going on', 'what s going on',
                'how are things', 'how are you doing', 'how are you',
                'how is it going', 'hows it going',
            ),
            'help' => array(
                'what can you help me with', 'what can you do',
                'can you help me', 'i need help', 'i have a question',
                'help me', 'i need assistance',
            ),
            'about' => array(
                'what does marvysocials do', 'what is marvysocials',
                'tell me about marvysocials', 'what does marvy do',
                'what is marvy', 'tell me about marvy',
                'what does this site do', 'what is this website for',
                'what is this website', 'tell me about this website',
                'what can i do here', 'what is this site',
                'what is this panel', 'what does this panel do',
                'who are you', 'what do you sell', 'what do you offer',
            ),
            'register' => array(
                'help me create an account', 'i want to create an account',
                'how do i create an account', 'how can i create an account',
                'can i make an account', 'i need an account',
                'take me to registration', 'where is registration',
                'how can i register', 'how do i register', 'can i register',
                'how can i sign up', 'how do i sign up', 'where can i sign up',
                'i want to sign up', 'i want to join', 'register me',
                'sign up', 'signup', 'create an account', 'create account',
                'join', 'register',
            ),
            'forgot' => array(
                'i forgot my password', 'forgot my password', 'forgot password',
                'how do i reset my password', 'reset my password',
                'reset password', 'cant log in', 'cannot log in',
                'can t log in', 'login isnt working', 'login is not working',
                'my login isnt working', 'i cant login',
            ),
            'login' => array(
                'how can i access my account', 'how do i access my account',
                'where is the login page', 'take me to login',
                'how do i log in', 'how do i login', 'i want to log in',
                'sign me in', 'log me in', 'sign in', 'log in', 'login',
            ),
            'design_system' => array(
                'design system', 'style guide', 'brand tokens', 'ui kit',
            ),
            'privacy' => array(
                'privacy policy', 'privacy', 'my data', 'personal data',
                'do you sell my data', 'cookies',
            ),
            'terms' => array(
                'terms of service', 'terms and conditions', 'acceptable use',
                'the terms', 'terms',
            ),
            'faq' => array(
                'frequently asked', 'where can i find the faq',
                'your faqs', 'the faq', 'faq',
            ),
            'pricing' => array(
                'where can i see the pricing', 'how much does it cost',
                'how much does that cost', 'what are your prices',
                'view pricing', 'the pricing', 'pricing', 'how much',
                'cost', 'price', 'plans', 'billing', 'subscription',
                'deposit', 'cheap',
            ),
            'wallet' => array(
                'add funds', 'add fund', 'wallet', 'withdraw', 'payout',
                'cash out', 'balance',
            ),
            'smm' => array(
                'social media', 'instagram', 'tiktok', 'youtube',
                'followers', 'likes', 'views', 'smm',
            ),
            'vtu' => array(
                'airtime', 'data bundle', 'dstv', 'gotv', 'electricity',
                'waec', 'neco', 'jamb', 'recharge', 'vtu',
            ),
            'numbers' => array(
                'virtual number', 'otp', 'sms number', 'rent a number',
            ),
            'identity' => array(
                'identity verif', 'nin', 'bvn', 'kyc',
            ),
            'giftcards' => array(
                'gift card', 'giftcard', 'itunes', 'steam card',
            ),
            'marketplace' => array(
                'marketplace', 'listing', 'escrow', 'digital good',
            ),
            'api' => array(
                'reseller api', 'api key', 'api docs', 'endpoint', 'api',
            ),
            'admin' => array(
                'admin login', 'staff login', 'back office', 'first admin',
                'administrator',
            ),
            'contact' => array(
                'contact support', 'speak to', 'email you', 'ticket',
                'support', 'contact', 'human',
            ),
            'how' => array(
                'how does this website work', 'how does the website work',
                'how it works', 'how do i start', 'getting started',
                'customer journey', 'get started',
            ),
            'refund' => array(
                'refund', 'cancel order', 'money back', 'partial',
            ),
            'services' => array(
                'what services do you offer', 'what services can i order',
                'what do you sell', 'what can i buy', 'your services',
                'the services', 'services', 'catalogue', 'catalog',
                'products',
            ),
            'navigate' => array(
                'take me to', 'where is the', 'where can i find',
                'link to', 'page for', 'show me',
            ),
            'dashboard' => array(
                'my dashboard', 'user area', 'my orders', 'my account',
                'dashboard',
            ),
            'followup' => array(
                'how much does that cost', 'how much is that', 'that cost',
                'where do i find it', 'where is it', 'where is that',
                'take me there', 'learn more', 'tell me more',
                'more about that', 'and that', 'what about that',
                'how do i get started',
            ),
        );
    }

    /** Exact-or-near-exact short messages that must not trigger a topic dump. */
    public static function short_courtesy() {
        return array(
            'ok', 'okay', 'ok thanks', 'okay thanks',
            'thanks', 'thank you', 'thank you so much', 'thanks a lot',
            'youre welcome', 'you are welcome', 'no problem',
            'great', 'perfect', 'good', 'nice', 'awesome',
            'alright', 'got it', 'i understand', 'thats fine',
            'that is fine', 'cool', 'sounds good',
        );
    }

    public static function short_greetings() {
        return array(
            'hi', 'hey', 'hello', 'howdy', 'greetings', 'yo',
            'hi there', 'hey there', 'hello there',
            'morning', 'afternoon', 'evening', 'night',
        );
    }

    /** Intents that describe a topic we can follow up on. */
    public static function topical() {
        return array(
            'about', 'services', 'smm', 'vtu', 'numbers', 'identity',
            'giftcards', 'marketplace', 'api', 'pricing', 'wallet',
            'register', 'login', 'forgot', 'faq', 'privacy', 'terms',
            'contact', 'how', 'design_system', 'dashboard', 'admin',
            'refund',
        );
    }
}
