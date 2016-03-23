<?php
namespace mod_stratumtwo\output;

defined('MOODLE_INTERNAL') || die;

class assess_page implements \renderable, \templatable {
    
    protected $submission;
    protected $form;
    
    public function __construct(\mod_stratumtwo_submission $sbms, $form) {
        $this->submission = $sbms;
        $this->form = $form; // HTML string
    }
    
    public function export_for_template(\renderer_base $output) {
        $ctx = $this->submission->getTemplateContext(true, true);
        $ctx->status = $ctx->state;
        
        $ctx->exercise = $this->submission->getExercise()->getTemplateContext(null, false, false);
        
        $ctx->toDateStr = new \mod_stratumtwo\output\date_to_string();
        $ctx->fileSizeFormatter = new \mod_stratumtwo\output\file_size_formatter();
        
        $ctx->form = $this->form;
        
        return $ctx;
    }
}