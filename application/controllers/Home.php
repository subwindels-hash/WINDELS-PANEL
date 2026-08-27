<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Home extends Public_Controller {
    public function index(){
        $preview = $this->input->get('preview');
        $allowed = array('AURORA','NEXUS','PULSE');
        // Preview override — admin only, not persisted
        if ($preview && in_array(strtoupper($preview), $allowed, TRUE)) {
            $is_admin = $this->session->userdata('role') && in_array($this->session->userdata('role'), array('SUPER_ADMIN','ADMIN','STAFF'), TRUE);
            if ($is_admin) { $active = strtoupper($preview); } else { $active = $this->active_homepage(); }
        } else {
            $active = $this->active_homepage();
        }
        $data = array(
            'active_homepage'=>$active,
            'title'=>'Grow and manage your social presence',
            'meta_description'=>'MarvySocials is a prepaid panel for social media growth services, Nigerian VTU and bills, virtual numbers, identity checks and gift cards. Add funds, place an order, track it from one dashboard.',
            'canonical' => '',
        );

        // The homepage advertises the *live* catalogue: real service names,
        // real rates, real categories. An empty catalogue renders an honest
        // "being prepared" state rather than invented placeholder cards, so
        // the site never promises something the operator cannot deliver.
        $data['showcase'] = array();
        $data['categories'] = array();
        $data['catalogue_size'] = 0;
        if ($this->db_ready) {
            try {
                $this->load->model(array('Service_model', 'Service_category_model'));
                $data['showcase'] = $this->Service_model->homepage_showcase(6);
                $data['categories'] = $this->Service_model->categories_with_counts(8);
                $data['catalogue_size'] = $this->Service_model->count_active();
            } catch (Throwable $e) {
                log_message('error', 'homepage catalogue unavailable: '.$e->getMessage());
            }
        }
        // Single switch — no Node
        $view = 'homepages/'.strtolower($active).'/index';
        // Fallback if template missing. CI_Loader has no view-exists helper, so
        // check the filesystem directly (AURORA is the guaranteed default).
        if (!is_file(VIEWPATH.$view.'.php')) $view = 'homepages/aurora/index';
        $this->load->view('layouts/main', array('content_view'=>$view,'data'=>$data));
    }
    private function active_homepage(){
        try {
            if (!marvy_load_database()) {
                throw new RuntimeException('database unavailable');
            }
            $this->load->model('Setting_model');
            $v = $this->Setting_model->get('active_homepage');
            if ($v) return $v;
        } catch(Throwable $e){}
        $cfg = $this->config->item('marvy');
        return $cfg['active_homepage'] ?? 'AURORA';
    }
    public function pricing(){
        $this->load->library('SiteOperatorKnowledge');
        $this->load->view('layouts/main', array('content_view'=>'public/pricing','data'=>array(
            'title'=>'Pricing',
            'meta_description'=>'Prepaid wallet pricing for MarvySocials. No invented monthly plans — you pay published service rates. Volume groups are assigned by staff.',
        )));
    }
    public function about(){
        $this->render_page('about', 'public/about', 'About',
            'What MarvySocials is, who it is for, and what this site will not invent about the operator.');
    }
    public function faq(){
        $this->load->library('SiteOperatorKnowledge');
        $faqs = array();
        $categories = array();
        try {
            if ($this->db_ready) {
                $this->load->model('Faq_model');
                $faqs = $this->Faq_model->active();
                $categories = $this->Faq_model->categories();
            }
        } catch (Throwable $e) {
            $faqs = array();
        }
        if (empty($faqs)) {
            foreach (SiteOperatorKnowledge::faqs() as $row) {
                $faqs[] = (object)array(
                    'question' => $row['q'],
                    'answer'   => $row['a'],
                    'category' => $row['category'],
                );
            }
        }
        $this->load->view('layouts/main', array('content_view'=>'public/faq','data'=>array(
            'title'=>'FAQ',
            'meta_description'=>'Answers about MarvySocials accounts, wallet billing, services, security, the reseller API and the on-site assistant.',
            'faqs'=>$faqs,
            'categories'=>$categories,
        )));
    }

    /**
     * Contact page.
     *
     * This used to render a heading and nothing else — there was no form, so
     * "the contact form is broken" was literally true: there was nothing to
     * submit. It now posts to contact_submit() below.
     */
    public function contact($data = array()){
        $this->load->view('layouts/main', array(
            'content_view' => 'public/contact',
            'data' => array_merge(array(
                'title'           => 'Contact',
                'meta_description'=> 'Contact MarvySocials support about an order, payment or the reseller API. Signed-in customers get a ticket.',
                'support_email'   => $this->support_email(),
            ), $data),
        ));
    }

    /**
     * Handle a contact submission.
     *
     * Two destinations, deliberately: a signed-in customer gets a real support
     * ticket (threaded, visible in their dashboard, answerable by staff),
     * while a visitor's message is queued as email to the support address.
     * Inventing a ticket for someone with no account would create a
     * conversation they could never read a reply to.
     *
     * Throttled per IP through the same table the login screen uses, with a
     * honeypot field for the bots that do not read it.
     */
    public function contact_submit(){
        if ($this->input->method(true) !== 'POST') { redirect('contact'); return; }

        $this->load->library('RateLimiter');
        $ip     = $this->input->ip_address();
        $bucket = RateLimiter::scope('contact');

        $form = array(
            'name'    => trim((string)$this->input->post('name')),
            'email'   => trim((string)$this->input->post('email')),
            'subject' => trim((string)$this->input->post('subject')),
            'message' => trim((string)$this->input->post('message')),
            'department' => trim((string)$this->input->post('department')),
        );

        if ($this->ratelimiter->too_many_failures($ip, $bucket, 5, 3600)) {
            $retry = $this->ratelimiter->retry_after($ip, $bucket, 3600, 5);
            $this->contact(array(
                'error' => 'Too many messages from this network. Try again in '
                           .max(1, (int)ceil($retry / 60)).' minute(s).',
                'form'  => $form,
            ));
            return;
        }

        // Honeypot: a field no human sees and every naive bot fills in. Answer
        // with the success page so the bot has nothing to learn.
        if (trim((string)$this->input->post('website')) !== '') {
            log_message('info', 'contact: honeypot triggered from '.$ip);
            $this->contact(array('success' => $this->thanks_message()));
            return;
        }

        $error = $this->validate_contact($form);
        if ($error !== null) {
            $this->ratelimiter->record($bucket, $ip, false, 'CONTACT_INVALID', $this->input->user_agent());
            $this->contact(array('error' => $error, 'form' => $form));
            return;
        }

        $user = $this->current_user();
        if ($user) {
            $this->load->library('TicketService');
            $departments = array('orders', 'payments', 'api', 'other');
            $res = $this->ticketservice->open($user, array(
                'subject'    => $form['subject'],
                'message'    => $form['message'],
                'department' => in_array($form['department'], $departments, true) ? $form['department'] : 'other',
                'priority'   => 'MEDIUM',
            ));
            if (empty($res['ok'])) {
                $this->ratelimiter->record($bucket, $ip, false, 'CONTACT_FAILED', $this->input->user_agent());
                $this->contact(array(
                    'error' => $res['error'] ?? 'Your message could not be sent. Please try again.',
                    'form'  => $form,
                ));
                return;
            }
            $this->ratelimiter->record($bucket, $ip, true, 'CONTACT_TICKET', $this->input->user_agent());
            $this->session->set_flashdata('success', 'Thanks — we opened a ticket for your message.');
            redirect('dashboard/tickets/'.$res['ticket']->public_id);
            return;
        }

        $this->load->library('MailService');
        $support = $this->support_email();
        $queued = $this->mailservice->enqueue_raw(
            $support,
            '[Contact] '.$form['subject'],
            '<p><strong>From:</strong> '.html_escape($form['name']).' &lt;'.html_escape($form['email']).'&gt;</p>'
            .'<p><strong>IP:</strong> '.html_escape($ip).'</p>'
            .'<hr><p>'.nl2br(html_escape($form['message'])).'</p>',
            $form['name'].' <'.$form['email'].'>'."\n\n".$form['message'],
            'Support',
            'contact.message'
        );

        if (!$queued) {
            $this->ratelimiter->record($bucket, $ip, false, 'CONTACT_QUEUE_FAILED', $this->input->user_agent());
            $this->contact(array(
                'error' => 'Your message could not be sent right now. Please email '.$support.' directly.',
                'form'  => $form,
            ));
            return;
        }

        $this->ratelimiter->record($bucket, $ip, true, 'CONTACT_EMAIL', $this->input->user_agent());
        $this->contact(array('success' => $this->thanks_message()));
    }

    /** @return string|null the first problem with the submission, or NULL */
    private function validate_contact(array $form) {
        if ($form['name'] === '' || mb_strlen($form['name']) > 100) {
            return 'Please tell us your name (100 characters or fewer).';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            return 'That email address does not look valid — we need it to reply.';
        }
        if ($form['subject'] === '' || mb_strlen($form['subject']) > 150) {
            return 'Please give the message a subject (150 characters or fewer).';
        }
        if (mb_strlen($form['message']) < 10) {
            return 'Please write a little more so we can actually help.';
        }
        if (mb_strlen($form['message']) > 5000) {
            return 'That message is longer than 5,000 characters — please trim it.';
        }
        return null;
    }

    private function thanks_message() {
        return 'Thanks — your message is on its way to our support team. '
              .'We reply to the address you gave us, usually within one business day.';
    }

    /** Support address from settings, falling back to config/.env. */
    private function support_email() {
        try {
            $this->load->model('Setting_model');
            $value = $this->Setting_model->get('support_email');
            if ($value) return $value;
        } catch (Exception $e) { /* settings unavailable — fall through */ }
        $cfg = $this->config->item('marvy');
        return $cfg['support_email'] ?? 'support@marvy.local';
    }
    public function terms(){
        $this->render_page('terms', 'public/terms', 'Terms of Service',
            'Terms of Service for this MarvySocials instance, including accounts, wallet billing, acceptable use and the on-site assistant.');
    }
    public function privacy(){
        $this->render_page('privacy', 'public/privacy', 'Privacy Policy',
            'How MarvySocials handles account, order, payment, identity and assistant data — written from the actual application.');
    }
    public function refund_policy(){
        $this->render_page('refund-policy', 'public/refund_policy', 'Refund Policy',
            'When MarvySocials credits a prepaid wallet for partial deliveries, failed purchases or staff decisions.');
    }
    public function acceptable_use(){
        $this->render_page('acceptable-use', 'public/acceptable_use', 'Acceptable Use',
            'What you may and may not do with a MarvySocials account, wallet, API key and catalogue orders.');
    }

    /**
     * Render a policy/marketing page, preferring an administrator override.
     *
     * These pages change for legal reasons, on legal timescales, decided by
     * people who do not deploy code — so their text must be editable from
     * Admin -> Website content without touching a PHP file. When no override
     * exists the bundled view still renders, which keeps a fresh install
     * complete and makes "clear the override" a real undo rather than a way to
     * blank a legal page.
     */
    private function render_page($key, $fallback_view, $title, $meta) {
        $this->load->library('SiteOperatorKnowledge');

        $override = null;
        if ($this->db_ready) {
            try {
                $this->load->model('Managed_page_model');
                $override = $this->Managed_page_model->published($key);
            } catch (Throwable $e) {
                log_message('error', 'managed page lookup failed for '.$key.': '.$e->getMessage());
            }
        }

        if ($override) {
            return $this->load->view('layouts/main', array(
                'content_view' => 'public/managed_page',
                'data' => array(
                    'title' => $override->title ?: $title,
                    'meta_description' => $override->meta_description ?: $meta,
                    'page' => $override,
                ),
            ));
        }

        $this->load->view('layouts/main', array(
            'content_view' => $fallback_view,
            'data' => array('title' => $title, 'meta_description' => $meta),
        ));
    }

    public function not_found(){
        $this->output->set_status_header(404);
        $this->load->view('layouts/main', array(
            'content_view' => 'public/not_found',
            'data' => array(
                'title' => 'Page not found',
                'meta_description' => 'That address is not a page on MarvySocials.',
                'meta_robots' => 'noindex,follow',
            ),
        ));
    }

    /**
     * Design-system guide. Staff/reviewers only — it documents internal design
     * tokens and component classes, so it must never surface in the public nav,
     * footer or sitemap.
     */
    public function styleguide(){
        if (!$this->auth || !$this->auth->has_role(array('SUPER_ADMIN','ADMIN','STAFF'))) {
            show_404();
        }
        $this->load->view('layouts/main', array(
            'content_view' => 'public/styleguide',
            'data' => array(
                'title' => 'Design System',
                'meta_description' => 'MarvySocials design tokens and component inventory.',
                'meta_robots' => 'noindex,follow',
                'active_homepage' => $this->active_homepage(),
            ),
        ));
    }
    public function sitemap(){
        $urls = array(
            '', 'services', 'pricing', 'about', 'faq', 'blog', 'contact',
            'terms', 'privacy', 'refund-policy', 'acceptable-use',
            'api/docs',
        );
        if ($this->db_ready) {
            try {
                $this->load->model('Blog_post_model');
                foreach ($this->Blog_post_model->published(null, 200, 0) as $post) {
                    $urls[] = 'blog/'.$post->slug;
                }
            } catch (Throwable $e) { /* public pages only */ }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (array_unique($urls) as $path) {
            $xml .= '<url><loc>'.htmlspecialchars(site_url($path)).'</loc></url>';
        }
        $xml .= '</urlset>';
        $this->output->set_content_type('application/xml')->set_output($xml);
    }
    public function robots(){
        $this->output->set_content_type('text/plain')->set_output(
            "User-agent: *\nAllow: /\nDisallow: /dashboard\nDisallow: /admin\nDisallow: /login\nDisallow: /register\nSitemap: ".site_url('sitemap.xml')."\n"
        );
    }
}
