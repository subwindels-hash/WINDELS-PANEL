<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Admin/Media — the media library and the branding screen.
 *
 * The last two routed-but-missing screens. They ship together because they
 * only make sense together: `admin/appearance` sets a logo and a favicon, and
 * before this there was no way to get an image into the panel to point them
 * at. Migration 008 created `media` and nothing ever wrote to it.
 *
 * This introduces the application's first file-upload path, which is the
 * feature most likely to become a remote-code-execution hole in a PHP admin
 * panel — the document root is the repository root, so anything written under
 * `assets/` is directly fetchable. Every rule that prevents that lives in
 * MediaService; read its class comment before touching the upload flow.
 *
 * The appearance half also closes a gap the settings screen documented: three
 * of the `brand_*` keys were seeded in Session 02 and honoured by nothing, so
 * SettingsService listed them as unwired rather than pretending. Saving a logo
 * here now writes those settings *and* the layout renders them, so they move
 * out of that list honestly rather than by deleting the note.
 */
class Media extends Admin_Controller {

    const PER_PAGE = 40;

    public function __construct() {
        parent::__construct();
        if (!$this->auth->can('media.manage') && !$this->auth->can('appearance.manage')) {
            $this->require_perm('media.manage');
        }
        $this->load->library(array('MediaService', 'DashboardStats'));
        $this->load->model(array('Media_model', 'Setting_model', 'Audit_log_model'));
    }

    /** GET /admin/media — the library. */
    public function index() {
        $this->require_perm('media.manage');

        $filters = array(
            'purpose' => $this->input->get('purpose', true),
            'search'  => $this->input->get('q', true),
        );
        $page  = max(1, (int)$this->input->get('page'));
        $grid  = $this->mediaservice->grid($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE);
        $total = (int)$grid['total'];

        $this->render('Media', 'admin/media/index', 'media', array(
            'files'       => $grid['rows'],
            'filters'     => $filters,
            'purposes'    => MediaService::PURPOSES,
            'max_bytes'   => $this->mediaservice->max_bytes(),
            'page'        => $page,
            'total'       => $total,
            'total_pages' => max(1, (int)ceil($total / self::PER_PAGE)),
        ));
    }

    /** POST /admin/media/upload */
    public function upload() {
        $this->guard('media.manage');

        $file = isset($_FILES['file']) ? $_FILES['file'] : array();
        $res  = $this->mediaservice->store($file, $this->input->post('purpose', true),
            $this->current_user->id);
        if (empty($res['ok'])) return $this->fail('admin/media', $res['error']);

        // The stored name and type, not the uploader's — see MediaService.
        $this->audit('media.uploaded', $res['media']->id, null, array(
            'storage_key' => $res['media']->storage_key,
            'mime_type'   => $res['media']->mime_type,
            'size'        => $res['media']->size,
            'purpose'     => $res['media']->purpose,
        ));
        $this->session->set_flashdata('success', 'File uploaded.');
        redirect('admin/media');
    }

    /** POST /admin/media/:id/delete */
    public function delete($public_id) {
        $this->guard('media.manage');
        $media = $this->mediaservice->find($public_id);
        if (!$media) show_404();

        $res = $this->mediaservice->delete($media);
        if (empty($res['ok'])) return $this->fail('admin/media', $res['error']);

        $this->audit('media.deleted', $media->id, array('storage_key' => $media->storage_key), null);
        $this->session->set_flashdata('success', 'File deleted.');
        redirect('admin/media');
    }

    /* ---------------------------- appearance ---------------------------- */

    /** GET /admin/appearance */
    public function appearance() {
        $this->require_perm('appearance.manage');

        $this->render('Appearance', 'admin/media/appearance', 'appearance', array(
            'branding' => $this->branding(),
            'images'   => $this->Media_model->images(60),
        ));
    }

    /** POST /admin/appearance/save */
    public function save_appearance() {
        $this->guard('appearance.manage');

        $before = $this->branding();
        $after  = array();
        $errors = array();

        $colour = trim((string)$this->input->post('brand_primary_color', true));
        if ($colour !== '' && !preg_match('/^#[0-9a-f]{6}$/i', $colour)) {
            $errors[] = 'The brand colour must be a six-digit hex value such as #6366f1.';
        } else {
            $after['brand_primary_color'] = $colour ?: '#6366f1';
        }

        // Logos are chosen from the library, so the stored value is always a
        // URL this panel produced — never a string somebody typed.
        foreach (array('brand_logo_url', 'brand_favicon_url') as $key) {
            $choice = trim((string)$this->input->post($key, true));
            if ($choice === '') { $after[$key] = null; continue; }
            $media = $this->mediaservice->find($choice);
            if (!$media) { $errors[] = 'That image is no longer in the media library.'; continue; }
            $after[$key] = $media->url;
        }

        if ($errors) return $this->fail('admin/appearance', implode(' ', $errors));

        foreach ($after as $key => $value) {
            $this->Setting_model->set($key, $value, 'branding', 1);
        }

        $this->audit('appearance.updated', null, $before, $after);
        $this->session->set_flashdata('success', 'Appearance updated.');
        redirect('admin/appearance');
    }

    /* ------------------------------ helpers ----------------------------- */

    private function branding() {
        return array(
            'brand_primary_color' => $this->Setting_model->get('brand_primary_color', '#6366f1'),
            'brand_logo_url'      => $this->Setting_model->get('brand_logo_url'),
            'brand_favicon_url'   => $this->Setting_model->get('brand_favicon_url'),
        );
    }

    private function render($title, $view, $area, array $data) {
        $tabs = array(
            'media'      => array('Library',    'admin/media',      $this->auth->can('media.manage')),
            'appearance' => array('Appearance', 'admin/appearance', $this->auth->can('appearance.manage')),
        );
        $this->load->view('layouts/app', array_merge(array(
            'title'        => $title,
            'nav_active'   => 'admin/media',
            'content_view' => $view,
            'current_user' => $this->current_user,
            'permissions'  => $this->auth->permissions(),
            'unread'       => $this->dashboardstats->unread_count($this->current_user->id),
            'area'         => $area,
            'tabs'         => $tabs,
        ), $data));
    }

    private function guard($perm) {
        if ($this->input->method(true) !== 'POST') show_404();
        $this->require_perm($perm);
    }

    private function fail($url, $message) {
        $this->session->set_flashdata('error', $message);
        redirect($url);
    }

    private function audit($action, $id, $before, $after) {
        $this->Audit_log_model->record(
            $this->current_user->id, $action, 'media', $id === null ? null : (string)$id,
            $before, $after,
            $this->input->ip_address(), $this->input->user_agent(), $this->request_id
        );
    }
}
