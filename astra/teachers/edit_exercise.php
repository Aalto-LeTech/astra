<?php
/** Page for manual editing/creation of an Astra learning object (exercise/chapter).
 */
require_once(dirname(dirname(dirname(dirname(__FILE__)))).'/config.php'); // defines MOODLE_INTERNAL for libraries
require_once(dirname(__FILE__) .'/editcourse_lib.php');

$id       = optional_param('id', 0, PARAM_INT); // learning object ID, edit existing
$roundid  = optional_param('round', 0, PARAM_INT); // exercise round ID, if creating new
$type     = optional_param('type', '', PARAM_ALPHA); // exercise or chapter, if creating new

if ($id) {
    $learningObject = mod_astra_learning_object::createFromId($id);
    $lobjectRecord = $learningObject->getRecord();
    $exround = $learningObject->getExerciseRound();
    $page_url = \mod_astra\urls\urls::editExercise($learningObject, true);
    $form_action = 'edit_exercise.php?id='. $id;
    if ($learningObject->isSubmittable()) {
        $heading = get_string('editexercise', mod_astra_exercise_round::MODNAME);
    } else {
        $heading = get_string('editchapter', mod_astra_exercise_round::MODNAME);
    }
} else if ($roundid && ($type == 'exercise' || $type == 'chapter')) {
    $exround = mod_astra_exercise_round::createFromId($roundid);
    $form_action = "edit_exercise.php?round=$roundid&type=$type";
    if ($type == 'exercise') {
        $heading = get_string('createexercise', mod_astra_exercise_round::MODNAME);
        $page_url = \mod_astra\urls\urls::createExercise($exround, true);
    } else {
        $heading = get_string('createchapter', mod_astra_exercise_round::MODNAME);
        $page_url = \mod_astra\urls\urls::createChapter($exround, true);
    }
} else {
    // missing parameter: cannot create new or modify existing
    print_error('missingparam', '', '', 'id');
}

$courseid = $exround->getCourse()->courseid;
$course = get_course($courseid);

require_login($course, false);
$context = context_course::instance($courseid);
require_capability('mod/astra:addinstance', $context);

// Print the page header.
$PAGE->set_pagelayout('incourse');
$PAGE->set_url($page_url);
$PAGE->set_title(format_string($heading));
$PAGE->set_heading(format_string($course->fullname));

// navbar
astra_edit_course_navbar_add($PAGE, $courseid,
        $heading, $page_url, 'editexercise');

// Output starts here.
// gotcha: moodle forms should be initialized before $OUTPUT->header
if (($id && $learningObject->isSubmittable()) || $type == 'exercise') {
    $form = new \mod_astra\form\edit_exercise_form($exround, $id, $form_action);
} else {
    $form = new \mod_astra\form\edit_chapter_form($exround, $id, $form_action);
}
if ($form->is_cancelled()) {
    // Handle form cancel operation, if cancel button is present on form
    redirect(\mod_astra\urls\urls::editCourse($courseid, true));
    exit(0);
}

$output = $PAGE->get_renderer(mod_astra_exercise_round::MODNAME);

echo $output->header();
echo $output->heading($heading);

if ($fromform = $form->get_data()) {
    // form submitted and input is valid
    $fromform->course = $courseid;
    if (isset($fromform->parentid) && $fromform->parentid == 0) {
        $fromform->parentid = null; // use null in DB for no parent
    }
    
    if ($id) { // edit
        $fromform->lobjectid = $id;
        $fromform->id = $learningObject->getSubtypeId();
        
        if ($learningObject->isSubmittable()) {
            // if the round of an exercise changes, gradebook requires additional changes
            if ($fromform->roundid == $lobjectRecord->roundid) { // round not changed 
                $fromform->gradeitemnumber = $lobjectRecord->gradeitemnumber; // keep the old value
                $updatedExercise = new mod_astra_exercise($fromform);
                $updatedExercise->save($updatedExercise->isHidden() ||
                        $updatedExercise->getExerciseRound()->isHidden() || $updatedExercise->getCategory()->isHidden());
                
                // update round max points
                $updatedExercise->getExerciseRound()->updateMaxPoints();
            } else {
                // round changed, delete old gradebook item, modify max points of both rounds
                $learningObject->deleteGradebookItem();
                
                // gradeitemnumber must be unique in the new round
                $newRound = mod_astra_exercise_round::createFromId($fromform->roundid);
                $fromform->gradeitemnumber = $newRound->getNewGradebookItemNumber();
                $newExercise = new mod_astra_exercise($fromform);
                $newExercise->save($newExercise->isHidden() ||
                        $newExercise->getExerciseRound()->isHidden() || $newExercise->getCategory()->isHidden());
                // save() updates gradebook item (creates new item), unless hidden
                
                $newRound->updateMaxPoints(); // max points of the new round change
                // reduce max points of previous round
                $learningObject->getExerciseRound()->updateMaxPoints();
            }
            
            // sort the grade items in the gradebook
            astra_sort_gradebook_items($courseid);
            
        } else {
            // chapters do not have any grading, so the gradebook requires no special changes
            $updatedChapter = new mod_astra_chapter($fromform);
            $updatedChapter->save();
        }
        // clear the exercise/learning object description cache
        \mod_astra\cache\exercise_cache::invalidate_exercise_all_lang($id);
        
        $message = get_string('lobjecteditsuccess', mod_astra_exercise_round::MODNAME);
        
    } else { // create new
        $category = mod_astra_category::createFromId($fromform->categoryid);
        $exround = mod_astra_exercise_round::createFromId($fromform->roundid);
        if ($type == 'exercise') {
            $learningObject = $exround->createNewExercise($fromform, $category);
            if ($learningObject !== null) {
                // sort the grade items in the gradebook
                astra_sort_gradebook_items($courseid);
            }
        } else {
            $learningObject = $exround->createNewChapter($fromform, $category);
        }
        if ($learningObject !== null) {
            // success
            $message = get_string('lobjcreatesuccess', mod_astra_exercise_round::MODNAME);
        } else {
            $message = get_string('lobjcreatefailure', mod_astra_exercise_round::MODNAME);
        }
    }
    
    echo '<p>'. $message .'</p>';
    echo '<p>'.
            html_writer::link(\mod_astra\urls\urls::editCourse($courseid, true),
              get_string('backtocourseedit', mod_astra_exercise_round::MODNAME)) .
         '</p>';
    
} else {
    if ($id && !$form->is_submitted()) { // if editing, fill the form with old values
        $form->set_data($lobjectRecord);
    }
    $form->display();
}

// Finish the page.
echo $output->footer();
