<?php
// This file is part of Exabis Eportfolio (extension for Moodle)
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require(__DIR__.'/inc.php');
require_once($CFG->libdir.'/filelib.php');

global $USER;

$categoryid = optional_param('categoryid', 0, PARAM_INT);
$courseid = optional_param('courseid', 1, PARAM_INT);
$cancel = optional_param('cancel', '', PARAM_RAW);
$sesskey = optional_param('sesskey', '', PARAM_RAW);

block_exaport_require_login($courseid);

$conditions = block_exaport_check_competence_interaction();

// Check if user is a student and has permission to upload in this category
$is_student = !block_exaport_user_is_admin() && !block_exaport_user_is_teacher_in_course($USER->id, $courseid);
if (!$is_student) {
    print_error('onlystudentscanupload', 'block_exaport');
}

// Check if student can upload in this category (must be their own personal folder)
if (!block_exaport_student_owns_category($categoryid)) {
    print_error('cannotuploadhere', 'block_exaport', 
        new moodle_url('/blocks/exaport/view_items.php', ['courseid' => $courseid, 'categoryid' => $categoryid]));
}

$url = '/blocks/exaport/upload_file.php';
$PAGE->set_url($url, ['courseid' => $courseid, 'categoryid' => $categoryid]);

if ($cancel) {
    redirect(new moodle_url('/blocks/exaport/view_items.php', ['courseid' => $courseid, 'categoryid' => $categoryid]));
}

$context = context_user::instance($USER->id);
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');

// Set up the form
require_once($CFG->dirroot.'/lib/formslib.php');

class block_exaport_upload_file_form extends moodleform {
    
    public function definition() {
        global $CFG, $USER;
        
        $mform = $this->_form;
        $categoryid = $this->_customdata['categoryid'];
        $courseid = $this->_customdata['courseid'];
        $context = $this->_customdata['context'];
        
        // Get category name for display
        $category = block_exaport_get_category($categoryid);
        
        $mform->addElement('header', 'general', get_string('upload_file_evidence', 'block_exaport'));
        
        // Show where file will be uploaded
        if ($category) {
            $mform->addElement('static', 'uploadlocation', get_string('category', 'block_exaport'), 
                format_string($category->name));
        }
        
        // File name (simple text field)
        $mform->addElement('text', 'name', get_string('name'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required');
        
        // File picker for upload
        $draftitemid = file_get_submitted_draft_itemid('attachment');
        file_prepare_draft_area($draftitemid, $context->id, 'block_exaport', 'item_file', null,
            ['subdirs' => 0, 'maxbytes' => 0, 'areamaxbytes' => 10485760, 'maxfiles' => 1,
             'accepted_types' => ['document', 'image', 'video', 'audio', '.pdf']]);
        
        $mform->addElement('filemanager', 'attachment', get_string('attachment', 'block_exaport'), null,
            ['subdirs' => 0, 'maxbytes' => 0, 'areamaxbytes' => 10485760, 'maxfiles' => 1,
             'accepted_types' => ['document', 'image', 'video', 'audio', '.pdf']]);
        $mform->addRule('attachment', get_string('required'), 'required');
        
        // Hidden fields
        $mform->addElement('hidden', 'categoryid', $categoryid);
        $mform->setType('categoryid', PARAM_INT);
        
        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);
        
        $mform->addElement('hidden', 'sesskey', sesskey());
        $mform->setType('sesskey', PARAM_RAW);
        
        // Buttons
        $this->add_action_buttons(true, get_string('upload'));
    }
}

// Prepare file manager - no need for complex setup with draft area method
$entry = new stdClass();

// Create form
$form = new block_exaport_upload_file_form(null, ['categoryid' => $categoryid, 'courseid' => $courseid, 'context' => $context]);
$form->set_data($entry);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/blocks/exaport/view_items.php', ['courseid' => $courseid, 'categoryid' => $categoryid]));
} else if ($data = $form->get_data()) {
    
    // Validate sesskey - the sesskey is already validated by Moodle's form processing
    require_sesskey();
    
    // Create the artefact item
    $insert = new stdClass();
    $insert->userid = $USER->id;
    $insert->name = $data->name;
    $insert->type = 'file';
    $insert->categoryid = $categoryid;
    $insert->courseid = $courseid;
    $insert->timemodified = time();
    $insert->intro = ''; // Simple upload - no description needed
    $insert->url = '';
    $insert->shareall = 0;
    $insert->externaccess = 0;
    $insert->externcomment = 0;
    $insert->langid = 0; // Use 0 as default language ID
    
    // Insert the item
    $insert->id = $DB->insert_record('block_exaportitem', $insert);
    
    // Handle file upload using the same method as item.php
    if ($insert->type == 'file' && !empty($data->attachment)) {
        file_save_draft_area_files($data->attachment, $context->id, 'block_exaport', 'item_file', $insert->id,
            array('maxbytes' => 10485760)); // 10MB limit
    }
    
    // Log the action
    error_log("UPLOAD FILE: Student {$USER->id} uploaded file '{$data->name}' to category {$categoryid}");
    
    // Redirect back to the category view
    redirect(new moodle_url('/blocks/exaport/view_items.php', ['courseid' => $courseid, 'categoryid' => $categoryid]),
        get_string('filesaved', 'moodle'), null, \core\output\notification::NOTIFY_SUCCESS);
}

// Display the page
$PAGE->set_title(get_string('upload_file_evidence', 'block_exaport'));
$PAGE->set_heading(get_string('upload_file_evidence', 'block_exaport'));

echo $OUTPUT->header();

// Show form
$form->display();

echo $OUTPUT->footer();
