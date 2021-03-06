<?php

/**
 * Structure step to restore one astra activity
 */
class restore_astra_activity_structure_step extends restore_activity_structure_step {

    // gather learnig objects that have non-null parentid fields -> parentid is updated
    // in after_execute method after all learning objects have been restored and their new
    // IDs are known
    private $learningObjectsWithParents = array();
    
    protected function define_structure() {

        $paths = array();
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('astra', '/activity/astra');
        $paths[] = new restore_path_element('category', '/activity/astra/categories/category');
        $paths[] = new restore_path_element('learningobject',
                '/activity/astra/categories/category/learningobjects/learningobject');
        $paths[] = new restore_path_element('exercise',
                '/activity/astra/categories/category/learningobjects/learningobject/exercise');
        $paths[] = new restore_path_element('chapter',
                '/activity/astra/categories/category/learningobjects/learningobject/chapter');
        $paths[] = new restore_path_element('coursesetting',
                '/activity/astra/coursesetting');
        
        if ($userinfo) {
            $paths[] = new restore_path_element('submission',
                    '/activity/astra/categories/category/learningobjects/learningobject/exercise/submissions/submission');
            $paths[] = new restore_path_element('deadlinedeviation',
                    '/activity/astra/categories/category/learningobjects/learningobject/exercise/deadlinedeviations/deadlinedeviation');
            $paths[] = new restore_path_element('submitlimitdeviation',
                    '/activity/astra/categories/category/learningobjects/learningobject/exercise/submitlimitdeviations/submitlimitdeviation');
        }

        // Return the paths wrapped into standard activity structure
        return $this->prepare_activity_structure($paths);
    }

    protected function process_astra($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        $data->openingtime = $this->apply_date_offset($data->openingtime);
        $data->closingtime = $this->apply_date_offset($data->closingtime);
        $data->latesbmsdl = $this->apply_date_offset($data->latesbmsdl);
        
        // New exercise rounds and learning objects are created during restore even if
        // objects with the same remote keys already exist in the Moodle course.
        // If the course is empty before restoring, that can not happen of course.
        // The teacher should check remote keys (and exercise service configuration) after
        // restoring if existing rounds/learning objects are duplicated in the restore process.
        
        // insert the astra (exercise round) record
        $newitemid = $DB->insert_record(mod_astra_exercise_round::TABLE, $data);
        // immediately after inserting "activity" record, call this
        $this->apply_activity_instance($newitemid);
        
        if ($DB->count_records(mod_astra_exercise_round::TABLE,
                array('course' => $data->course, 'remotekey' => $data->remotekey)) > 1) {
            $this->get_logger()->process(
                'The course probably was not empty before restoring and now there are multiple exercise rounds with the same remote key. '.
                    'You should check and update them manually. The same applies to learning objects (exercises/chapters).',
                backup::LOG_INFO);
        }
    }
    
    protected function process_category($data) {
        global $DB;
        
        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        // each exercise round XML tree contains all categories in the course, thus
        // we cannot create a new category every time we find a category XML element
        $existingCat = $DB->get_record(mod_astra_category::TABLE, array('course' => $data->course, 'name' => $data->name));
        if ($existingCat === false) { // does not yet exist
            $newitemid = $DB->insert_record(mod_astra_category::TABLE, $data);
        } else {
            // do not modify the existing category
            $newitemid = $existingCat->id;
        }
        
        $this->set_mapping('category', $oldid, $newitemid);
    }
    
    protected function process_learningobject($data) {
        global $DB;
        
        $data = (object) $data;
        $oldid = $data->id;
        $data->categoryid = $this->get_new_parentid('category');
        $data->roundid = $this->get_new_parentid('astra');
        
        if ($data->parentid !== null) {
            $oldParentId = $data->parentid;
            $data->parentid = $this->get_mappingid('learningobject', $data->parentid, null);
            if ($data->parentid === null) {
                // mapping not found because the parent was not defined before the child in the XML
                // update this parentid later
                $lobject = array(
                        'id' => $oldid,
                        'parentid' => $oldParentId,
                );
                $this->learningObjectsWithParents[] = (object) $lobject;
            }
        }
        
        $newitemid = $DB->insert_record(mod_astra_learning_object::TABLE, $data);
        $this->set_mapping('learningobject', $oldid, $newitemid);
    }
    
    protected function process_exercise($data) {
        global $DB;
        
        $data = (object) $data;
        $oldid = $data->id;
        $data->lobjectid = $this->get_new_parentid('learningobject');
        $newitemid = $DB->insert_record(mod_astra_exercise::TABLE, $data);
    }
    
    protected function process_chapter($data) {
        global $DB;
        
        $data = (object) $data;
        $oldid = $data->id;
        $data->lobjectid = $this->get_new_parentid('learningobject');
        $newitemid = $DB->insert_record(mod_astra_chapter::TABLE, $data);
    }
    
    protected function process_coursesetting($data) {
        global $DB;
        
        $data = (object) $data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        // each exercise round XML tree contains one course settings element, thus
        // we only create a new settings row if it does not yet exist in the course
        $existingSetting = $DB->get_record(mod_astra_course_config::TABLE, array('course' => $data->course));
        if ($existingSetting === false) {
            $newitemid = $DB->insert_record(mod_astra_course_config::TABLE, $data);
        }
    }
    
    protected function process_submission($data) {
        global $DB;
        
        $data = (object) $data;
        $oldid = $data->id;
        
        $data->submissiontime = $this->apply_date_offset($data->submissiontime);
        $data->exerciseid = $this->get_new_parentid('learningobject');
        $data->submitter = $this->get_mappingid('user', $data->submitter);
        $data->grader = $this->get_mappingid('user', $data->grader);
        $data->gradingtime = $this->apply_date_offset($data->gradingtime);
        
        $newitemid = $DB->insert_record(mod_astra_submission::TABLE, $data);
        // set mapping for restoring submitted files
        $this->set_mapping('submission', $oldid, $newitemid, true);
    }
    
    protected function process_deadlinedeviation($data) {
        global $DB;
        
        $data = (object) $data;
        $oldid = $data->id;
        $data->submitter = $this->get_mappingid('user', $data->submitter);
        $data->exerciseid = $this->get_new_parentid('learningobject');
        
        $newitemid = $DB->insert_record(mod_astra_deadline_deviation::TABLE, $data);
    }
    
    protected function process_submitlimitdeviation($data) {
        global $DB;
        
        $data = (object) $data;
        $oldid = $data->id;
        $data->submitter = $this->get_mappingid('user', $data->submitter);
        $data->exerciseid = $this->get_new_parentid('learningobject');
        
        $newitemid = $DB->insert_record(mod_astra_submission_limit_deviation::TABLE, $data);
    }

    protected function after_execute() {
        global $DB;
        
        // Restore submitted files
        $this->add_related_files(mod_astra_exercise_round::MODNAME,
                mod_astra_submission::SUBMITTED_FILES_FILEAREA, 'submission');
        
        // fix learning object parentids
        foreach ($this->learningObjectsWithParents as $old_lobject) {
            $new_lobject = new stdClass();
            $new_lobject->parentid = $this->get_mappingid('learningobject', $old_lobject->parentid);
            $new_lobject->id = $this->get_mappingid('learningobject', $old_lobject->id);
            
            if (!$new_lobject->id || !$new_lobject->parentid) {
                // mapping not found even though all learning objects have been restored
                debugging('restore_astra_activity_structure_step::after_execute: learning object mapping not found while fixing parentids');
            } else {
                $DB->update_record(mod_astra_learning_object::TABLE, $new_lobject);
            }
        }
    }
}