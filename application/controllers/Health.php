<?php
defined('BASEPATH') OR exit('No direct script access allowed');
class Health extends MY_Controller {
    public function index(){ $this->live(); }
    public function live(){ $this->json_success(array('status'=>'ok','time'=>gmdate('c'))); }
    public function ready(){
        $checks=array();
        try { $this->load->database(); $this->db->query('SELECT 1'); $checks['database']='ok'; } catch(Exception $e){ $checks['database']='fail: '.$e->getMessage(); }
        // Redis check (optional)
        try { $this->load->config('redis'); $checks['redis']='ok'; } catch(Exception $e){ $checks['redis']='skip'; }
        $ok = $checks['database']==='ok';
        $this->json(array('success'=>$ok,'data'=>array('checks'=>$checks)), $ok?200:503);
    }
}
