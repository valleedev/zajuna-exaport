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
// (c) 2016 GTN - Global Training Network GmbH <office@gtn-solutions.com>.

require_once(__DIR__ . '/inc.php');
$courseid = optional_param('courseid', 0, PARAM_INT);

require_login($courseid);

// Check if user can create/edit categories
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$pid = optional_param('pid', 0, PARAM_RAW); // Changed to RAW to support evidencias_123 format

// Administrators have full permissions, skip all checks
if (!block_exaport_user_is_admin()) {
    if (block_exaport_user_is_student()) {
        // Students are limited in what they can do with categories
        if (empty($action) || $action == 'userlist' || $action == 'grouplist') {
            // These actions are for viewing/sharing, allow them
        } else if ($action == 'add' || $action == 'addstdcat') {
            // Students can create categories in evidencias if they are within instructor folders
            $context_id = $pid;
            error_log("DEBUG CATEGORY CREATE: Student trying to create in pid=$context_id, action=$action");
            // Check if student can act within instructor-created folders
            if (!block_exaport_student_can_act_in_instructor_folder($context_id)) {
                error_log("DEBUG CATEGORY CREATE: Permission denied for student to create in pid=$context_id");
                print_error('nopermissions', 'error', '', get_string('nocategorycreatepermission', 'block_exaport'));
            } else {
                error_log("DEBUG CATEGORY CREATE: Permission granted for student to create in pid=$context_id");
            }
        } else if ($action == 'edit' || $action == 'delete') {
            // Students can edit/delete categories within instructor folders
            $context_id = optional_param('id', 0, PARAM_INT);
            if (!block_exaport_student_can_act_in_instructor_folder($context_id)) {
                print_error('nopermissions', 'error', '', get_string('nocategorycreatepermission', 'block_exaport'));
            }
        } else {
            // All other actions are not allowed for students
            print_error('nopermissions', 'error', '', get_string('nocategorycreatepermission', 'block_exaport'));
        }
    } else if (!block_exaport_user_is_student()) {
        // For instructors, check if they have permission for this action
        $context_id = null;
        if ($action == 'edit' || $action == 'delete') {
            $context_id = optional_param('id', 0, PARAM_INT);
        } else if ($action == 'add' || $action == 'addstdcat') {
            $context_id = $pid;
        }
        
        if (!empty($action) && !block_exaport_instructor_has_permission($action, $context_id)) {
            print_error('nopermissions', 'error', '', get_string('noevidenciascategorycreate', 'block_exaport'));
        }
    }
}

block_exaport_setup_default_categories();

$url = '/blocks/exaport/category.php';
$PAGE->set_url($url, ['courseid' => $courseid,
    'action' => optional_param('action', '', PARAM_ALPHA),
    'id' => optional_param('id', '', PARAM_INT)]);

// Get userlist for sharing category.
if (optional_param('action', '', PARAM_ALPHA) == 'userlist') {
    echo json_encode(exaport_get_shareable_courses_with_users(''));
    exit;
}
// Get grouplist for sharing category.
if (optional_param('action', '', PARAM_ALPHA) == 'grouplist') {
    $id = required_param('id', PARAM_INT);

    $category = $DB->get_record("block_exaportcate", array(
        'id' => $id,
        'userid' => $USER->id,
    ));
    if (!$category) {
        throw new \block_exaport\moodle_exception('category_not_found');
    }

    $groupgroups = block_exaport_get_shareable_groups_for_json();
    foreach ($groupgroups as $groupgroup) {
        foreach ($groupgroup->groups as $group) {
            $group->shared_to = $DB->record_exists('block_exaportcatgroupshar', [
                'catid' => $category->id,
                'groupid' => $group->id,
            ]);
        }
    }
    echo json_encode($groupgroups);
    exit;
}

if (optional_param('action', '', PARAM_ALPHA) == 'addstdcat') {
    block_exaport_import_categories('lang_categories');
    
    // Check if we should return to evidencias
    $evidencias = optional_param('evidencias', 0, PARAM_INT);
    $categoryid = optional_param('categoryid', 0, PARAM_RAW);
    $redirect_url = 'view_items.php?courseid=' . $courseid;
    if ($evidencias > 0) {
        $redirect_url .= '&evidencias=' . $evidencias;
        if (!empty($categoryid)) {
            $redirect_url .= '&categoryid=' . $categoryid;
        }
    }
    redirect($redirect_url);
}
if (optional_param('action', '', PARAM_ALPHA) == 'movetocategory') {
    confirm_sesskey();

    $category = $DB->get_record("block_exaportcate", array(
        'id' => required_param('id', PARAM_INT),
        'userid' => $USER->id,
    ));
    if (!$category) {
        die(block_exaport_get_string('category_not_found'));
    }

    if (!$targetcategory = block_exaport_get_category(required_param('categoryid', PARAM_INT))) {
        die('target category not found');
    }

    $DB->update_record('block_exaportcate', (object)array(
        'id' => $category->id,
        'pid' => $targetcategory->id,
    ));

    echo 'ok';
    exit;
}

if (optional_param('action', '', PARAM_ALPHA) == 'delete') {
    $id = required_param('id', PARAM_INT);

    // First check if user has permission to delete this category
    if (!block_exaport_instructor_has_permission('delete', $id)) {
        throw new \block_exaport\moodle_exception('nopermissions');
    }

    // Get the category (don't filter by userid for instructors deleting evidencias categories)
    $category = $DB->get_record("block_exaportcate", array('id' => $id));
    if (!$category) {
        throw new \block_exaport\moodle_exception('category_not_found');
    }
    
    // Additional check: if it's not an evidencias category, must belong to current user
    if (!isset($category->source) || $category->source <= 0) {
        // Regular category - must belong to user
        if ($category->userid != $USER->id) {
            throw new \block_exaport\moodle_exception('nopermissions');
        }
    }

    if (optional_param('confirm', 0, PARAM_INT)) {
        confirm_sesskey();

        function block_exaport_recursive_delete_category($id) {
            global $DB;

            // Delete subcategories.
            if ($entries = $DB->get_records('block_exaportcate', array("pid" => $id))) {
                foreach ($entries as $entry) {
                    block_exaport_recursive_delete_category($entry->id);
                }
            }
            $DB->delete_records('block_exaportcate', array('pid' => $id));

            // Delete itemsharing.
            if ($entries = $DB->get_records('block_exaportitem', array("categoryid" => $id))) {
                foreach ($entries as $entry) {
                    $DB->delete_records('block_exaportitemshar', array('itemid' => $entry->id));
                }
            }

            // Delete items.
            $DB->delete_records('block_exaportitem', array('categoryid' => $id));
        }

        block_exaport_recursive_delete_category($category->id);

        if (!$DB->delete_records('block_exaportcate', array('id' => $category->id))) {
            $message = "Could not delete your record";
        } else {
            block_exaport_add_to_log($courseid, "bookmark", "delete category", "", $category->id);
            
            // Check if we're in evidencias context and preserve it
            $evidencias = optional_param('evidencias', 0, PARAM_INT);
            $redirect_categoryid = $category->pid;
            
            // Special handling for evidencias categories
            if (!empty($category->source) && is_numeric($category->source)) {
                // This was an evidencias category
                if ($category->pid < 0) {
                    // If pid is negative (-courseid), redirect to evidencias root
                    $redirect_categoryid = 'evidencias_' . abs($category->pid);
                }
                $redirect_url = 'view_items.php?courseid=' . $courseid . '&categoryid=' . $redirect_categoryid . '&evidencias=' . $category->source;
                error_log("DEBUG DELETE: Evidencias category, redirecting to: {$redirect_url}");
            } else if ($evidencias > 0) {
                // Explicit evidencias parameter was passed
                if ($category->pid < 0) {
                    // If pid is negative (-courseid), redirect to evidencias root
                    $redirect_categoryid = 'evidencias_' . abs($category->pid);
                }
                $redirect_url = 'view_items.php?courseid=' . $courseid . '&categoryid=' . $redirect_categoryid . '&evidencias=' . $evidencias;
                error_log("DEBUG DELETE: Using explicit evidencias parameter, redirecting to: {$redirect_url}");
            } else {
                // Regular category
                $redirect_url = 'view_items.php?courseid=' . $courseid . '&categoryid=' . $redirect_categoryid;
                error_log("DEBUG DELETE: Regular category, redirecting to: {$redirect_url}");
            }
            
            // Debug logging
            error_log("DEBUG DELETE: category->id={$category->id}, category->pid={$category->pid}, category->source='{$category->source}', evidencias_param={$evidencias}");
            error_log("DEBUG DELETE: Final redirect URL: {$redirect_url}");
            redirect($redirect_url);
        }
    }

    $optionsyes = array('action' => 'delete', 'courseid' => $courseid, 'confirm' => 1, 'sesskey' => sesskey(), 'id' => $id);
    $optionsno = array(
        'courseid' => $courseid,
        'categoryid' => optional_param('back', '', PARAM_TEXT) == 'same' ? $category->id : $category->pid,
    );

    $strbookmarks = get_string("myportfolio", "block_exaport");
    $strcat = get_string("categories", "block_exaport");

    block_exaport_print_header("myportfolio");

    echo '<br />';
    echo $OUTPUT->confirm(get_string("deletecategoryconfirm", "block_exaport", $category),
        new moodle_url('category.php', $optionsyes),
        new moodle_url('view_items.php', $optionsno));
    echo block_exaport_wrapperdivend();
    $OUTPUT->footer();

    exit;
}

require_once("$CFG->libdir/formslib.php");

class simplehtml_form extends block_exaport_moodleform {
    // Add elements to form.
    public function definition() {
        global $CFG;
        global $DB;
        global $USER;

        $id = optional_param('id', 0, PARAM_INT);
        
        // First get the category without userid filter
        $category = $DB->get_record_sql('
            SELECT c.id, c.name, c.pid, c.internshare, c.shareall, c.iconmerge, c.source, c.userid
            FROM {block_exaportcate} c
            WHERE id = ?
            ', array($id));
            
        // If category exists, check permissions
        if ($category && $id > 0) {
            // Check if user has permission to edit this category
            if (!block_exaport_instructor_has_permission('edit', $id)) {
                // User doesn't have permission - treat as if category doesn't exist
                $category = false;
            }
        }
        
        if (!$category) {
            $category = new stdClass;
            $category->shareall = 0;
            $category->id = 0;
            $category->iconmerge = 0;
            $category->source = null;
        };

        // Don't forget the underscore!
        $mform = $this->_form;
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'pid');
        $mform->setType('pid', PARAM_RAW); // Changed to RAW to support evidencias_123 format
        $mform->addElement('hidden', 'courseid');
        $mform->setType('courseid', PARAM_INT);
        $mform->addElement('hidden', 'back');
        $mform->setType('back', PARAM_TEXT);

        $mform->addElement('text', 'name', get_string('name'));
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', block_exaport_get_string('titlenotemtpy'), 'required', null, 'client');
        $mform->add_exaport_help_button('name', 'forms.category.name');

        // Check if we're creating in evidencias folder or editing an evidencias category - simplify form
        $pid = optional_param('pid', '', PARAM_RAW);
        $is_evidencias = (strpos($pid, 'evidencias_') === 0);
        
        // Also check if the parent category (pid) belongs to evidencias
        if (!$is_evidencias && is_numeric($pid) && $pid > 0) {
            $parent_category = $DB->get_record('block_exaportcate', array('id' => $pid));
            if ($parent_category && is_numeric($parent_category->source)) {
                $is_evidencias = true;
                error_log("DEBUG FORM: Parent category {$pid} belongs to evidencias (source={$parent_category->source})");
            }
        }
        
        // Also check if we're editing a category that belongs to evidencias
        if (!$is_evidencias && $category->id > 0) {
            // Check if this category has a source field set (indicating it's from evidencias)
            // The source field contains the course ID for evidencias categories
            $is_evidencias = !empty($category->source) && is_numeric($category->source);
            error_log("DEBUG FORM: Editing category ID {$category->id}, source='{$category->source}', is_evidencias=" . ($is_evidencias ? 'true' : 'false'));
        }
        
        // Check if this is a student creating in evidencias - simplified form for students
        $is_student_in_evidencias = $is_evidencias && block_exaport_user_is_student();
        
        error_log("DEBUG FORM: pid='$pid', is_evidencias=" . ($is_evidencias ? 'true' : 'false') . ", is_student=" . (block_exaport_user_is_student() ? 'true' : 'false') . ", is_student_in_evidencias=" . ($is_student_in_evidencias ? 'true' : 'false'));
        
        error_log("DEBUG FORM: Final is_evidencias=" . ($is_evidencias ? 'true' : 'false') . ", is_student_in_evidencias=" . ($is_student_in_evidencias ? 'true' : 'false'));
        
        if (!$is_evidencias || $is_student_in_evidencias) {
            // For students in evidencias OR regular categories, don't show icon options
            if (!$is_student_in_evidencias) {
                // Show icon options only for regular categories (not for students in evidencias)
                $mform->addElement('filemanager',
                    'iconfile',
                    get_string('iconfile', 'block_exaport'),
                    null,
                    array('subdirs' => false,
                        'maxfiles' => 1,
                        'maxbytes' => $CFG->block_exaport_max_uploadfile_size,
                        'accepted_types' => array('image', 'web_image')));
                $mform->add_exaport_help_button('iconfile', 'forms.category.iconfile');

                //        if (extension_loaded('gd') && function_exists('gd_info')) {
                // changed into Fontawesome and Javascript
                $mform->addElement('advcheckbox',
                    'iconmerge',
                    get_string('iconfile_merge', 'block_exaport'),
                    get_string('iconfile_merge_description', 'block_exaport'),
                    array('group' => 1),
                    array(0, 1));
                $mform->add_exaport_help_button('iconmerge', 'forms.category.iconmerge');
            }
        }

        // Sharing - simplified for students in evidencias, full for instructors/regular categories
        if (has_capability('block/exaport:shareintern', context_system::instance())) {
            if ($is_evidencias && !$is_student_in_evidencias) {
                // Simplified permissions for evidencias instructors - checkbox for write permissions to students
                $mform->addElement('checkbox', 'allow_student_uploads', 'Permitir subir archivos a aprendices', 'Los aprendices podrán crear elementos y subir archivos en esta carpeta');
                $mform->setType('allow_student_uploads', PARAM_INT);
                $mform->setDefault('allow_student_uploads', 1); // Default checked
            } else if (!$is_evidencias && !$is_student_in_evidencias) {
                // Full sharing options for regular categories (not for students)
                $mform->addElement('checkbox', 'internshare', get_string('share', 'block_exaport'));
                $mform->setType('internshare', PARAM_INT);
                $mform->add_exaport_help_button('internshare', 'forms.category.internshare');
            }
            // Students in evidencias get no sharing options (simplified form)
            
            if (!$is_evidencias && !$is_student_in_evidencias) {
                $mform->addElement('html', '<div id="internaccess-settings" class="fitem"">' .
                    '<div class="fitemtitle"></div><div class="felement">');

                $mform->addElement('html', '<div style="padding: 4px 0;"><table width=100%>');
                // Share to all.
                if (block_exaport_shareall_enabled()) {
                    $mform->addElement('html', '<tr><td>');
                    $mform->addElement('html', '<input type="radio" name="shareall" value="1"' .
                        ($category->shareall == 1 ? ' checked="checked"' : '') . '/>');
                    $mform->addElement('html', '</td><td>' . get_string('internalaccessall', 'block_exaport') . '</td></tr>');
                    $mform->setType('shareall', PARAM_INT);
                    $mform->addElement('html', '</td></tr>');
                }

                // Share to users.
                $mform->addElement('html', '<tr><td>');
                $mform->addElement('html', '<input type="radio" name="shareall" value="0"' .
                    (!$category->shareall ? ' checked="checked"' : '') . '/>');
                $mform->addElement('html', '</td><td>' . get_string('internalaccessusers', 'block_exaport') . '</td></tr>');
                $mform->addElement('html', '</td></tr>');
                if ($category->id > 0) {
                    $sharedusers = $DB->get_records_menu('block_exaportcatshar',
                        array("catid" => $category->id),
                        null,
                        'userid, userid AS tmp');
                    $mform->addElement('html', '<script> var sharedusersarr = [];');
                    foreach ($sharedusers as $i => $user) {
                        $mform->addElement('html', 'sharedusersarr[' . $i . '] = ' . $user . ';');
                    }
                    $mform->addElement('html', '</script>');
                }
                $mform->addElement('html', '<tr id="internaccess-users"><td></td>' .
                    '<td><div id="sharing-userlist">userlist</div></td></tr>');

                // Share to groups.
                $mform->addElement('html', '<tr><td>');
                $mform->addElement('html', '<input type="radio" name="shareall" value="2"' .
                    ($category->shareall == 2 ? ' checked="checked"' : '') . '/>');
                $mform->addElement('html', '</td><td>' . get_string('internalaccessgroups', 'block_exaport') . '</td></tr>');
                $mform->addElement('html', '</td></tr>');
                $mform->addElement('html', '<tr id="internaccess-groups"><td></td>' .
                    '<td><div id="sharing-grouplist">grouplist</div></td></tr>');
                $mform->addElement('html', '</table></div>');
                $mform->addElement('html', '</div></div>');
            }
        }

        $this->add_action_buttons();
    }

    // Custom validation should be added here.
    public function validation($data, $files) {
        return array();
    }
}

// Instantiate simplehtml_form.
$mform = new simplehtml_form(null, null, 'post', '', ['id' => 'categoryform']);

// Form processing and displaying is done here.
if ($mform->is_cancelled()) {
    $same = optional_param('back', '', PARAM_TEXT);
    $id = optional_param('id', 0, PARAM_INT);
    $pid = optional_param('pid', 0, PARAM_RAW); // Changed to RAW to support evidencias_123
    $evidencias = optional_param('evidencias', 0, PARAM_INT);
    
    error_log("DEBUG CANCEL: id={$id}, pid={$pid}, evidencias_param={$evidencias}");
    
    // Check if we're in evidencias context and preserve it
    $redirect_categoryid = ($same == 'same' ? $id : $pid);
    $redirect_url = 'view_items.php?courseid=' . $courseid . '&categoryid=' . $redirect_categoryid;
    
    // If we have an explicit evidencias parameter, use it
    if ($evidencias > 0) {
        $redirect_url .= '&evidencias=' . $evidencias;
        error_log("DEBUG CANCEL: Using explicit evidencias parameter: {$evidencias}");
    }
    // If we have an ID, check if it's an evidencias category
    else if ($id > 0) {
        $cat = $DB->get_record('block_exaportcate', array('id' => $id));
        if ($cat && !empty($cat->source) && is_numeric($cat->source)) {
            $redirect_url .= '&evidencias=' . $cat->source;
            error_log("DEBUG CANCEL: Using category source as evidencias: {$cat->source}");
        }
    }
    // Or if pid starts with evidencias_
    else if (strpos($pid, 'evidencias_') === 0) {
        $evidencias_id = str_replace('evidencias_', '', $pid);
        $redirect_url .= '&evidencias=' . $evidencias_id;
        error_log("DEBUG CANCEL: Using pid evidencias: {$evidencias_id}");
    }
    
    error_log("DEBUG CANCEL: Final redirect URL: {$redirect_url}");
    redirect($redirect_url);
} else if ($newentry = $mform->get_data()) {
    require_sesskey();
    
    // Permission check using the new unified function
    $action = empty($newentry->id) ? 'add' : 'edit';
    $context_id = empty($newentry->id) ? $newentry->pid : $newentry->id;
    
    if (!block_exaport_instructor_has_permission($action, $context_id)) {
        if (block_exaport_user_is_student()) {
            print_error('nopermissions', 'error', '', get_string('nocategorycreatepermission', 'block_exaport'));
        } else {
            print_error('nopermissions', 'error', '', get_string('noevidenciascategorycreate', 'block_exaport'));
        }
    }
    
    // Handle evidencias categories specially
    $original_pid = $newentry->pid; // Save for redirect
    $courseid_for_evidencias = null;
    
    if (strpos($newentry->pid, 'evidencias_') === 0) {
        // Extract course ID from evidencias_XX format
        $courseid_for_evidencias = intval(substr($newentry->pid, 11));
        // Use negative course ID to represent evidencias folder as parent
        $newentry->pid = -$courseid_for_evidencias;
    } elseif (is_numeric($newentry->pid) && $newentry->pid > 0) {
        // Check if the parent category is an evidencias category
        $parent_category = $DB->get_record('block_exaportcate', array('id' => $newentry->pid));
        if ($parent_category && !empty($parent_category->source) && is_numeric($parent_category->source)) {
            // This is a subcategory of an evidencias category - maintain hierarchy but mark as evidencias
            $courseid_for_evidencias = $parent_category->source;
            // Keep the original pid to maintain hierarchy (don't set to 0)
        }
    }
    
    $newentry->userid = $USER->id;
    
    // Handle sharing settings - simplified for evidencias
    if (strpos($original_pid, 'evidencias_') === 0) {
        // For evidencias categories, use a special field to mark write permissions
        $allow_uploads = optional_param('allow_student_uploads', 0, PARAM_INT);
        error_log("DEBUG CATEGORY SAVE: allow_student_uploads = $allow_uploads");
        // Store this permission in a special way - we'll use the internshare field
        // but with a special value to indicate "student write permissions"
        if ($allow_uploads) {
            $newentry->internshare = 2; // Special value: 2 = student write permissions in evidencias
            error_log("DEBUG CATEGORY SAVE: Setting internshare = 2 (write permissions enabled)");
        } else {
            $newentry->internshare = 0; // No special permissions
            error_log("DEBUG CATEGORY SAVE: Setting internshare = 0 (no write permissions)");
        }
        $newentry->shareall = 0; // Not using the shareall system for evidencias
    } else {
        // For regular categories, use full sharing options
        $newentry->shareall = optional_param('shareall', 0, PARAM_INT);
        if (optional_param('internshare', 0, PARAM_INT) > 0) {
            $newentry->internshare = optional_param('internshare', 0, PARAM_INT);
        } else {
            $newentry->internshare = 0;
        }
    }
    
    // Mark categories created in evidencias with the course ID as source
    if ($courseid_for_evidencias !== null) {
        $newentry->source = $courseid_for_evidencias;
    }

    if ($newentry->id) {
        $DB->update_record("block_exaportcate", $newentry);
    } else {
        $newentry->id = $DB->insert_record("block_exaportcate", $newentry);
    }

    // Delete all shared users.
    $DB->delete_records("block_exaportcatshar", array('catid' => $newentry->id));
    // Add new shared users - only for regular categories, not evidencias
    if ($newentry->internshare == 1 && !$newentry->shareall) {
        // Regular sharing for non-evidencias categories
        $shareusers = \block_exaport\param::optional_array('shareusers', PARAM_INT);
        foreach ($shareusers as $shareuser) {
            $shareuser = clean_param($shareuser, PARAM_INT);
            $shareitem = new stdClass();
            $shareitem->catid = $newentry->id;
            $shareitem->userid = $shareuser;
            $DB->insert_record("block_exaportcatshar", $shareitem);
        }
    }
    // Note: For evidencias with internshare=2, we don't use the sharing table
    // Instead, we check permissions dynamically based on course enrollment

    // Delete all shared groups.
    $DB->delete_records("block_exaportcatgroupshar", array('catid' => $newentry->id));
    // Add new shared groups.
    if ($newentry->internshare && $newentry->shareall == 2) {
        $sharegroups = \block_exaport\param::optional_array('sharegroups', PARAM_INT);
        $usergroups = block_exaport_get_user_cohorts();

        foreach ($sharegroups as $groupid) {
            if (!isset($usergroups[$groupid])) {
                // Not allowed.
                continue;
            }
            $DB->insert_record("block_exaportcatgroupshar", [
                'catid' => $newentry->id,
                'groupid' => $groupid,
            ]);
        }
    }

    // Icon for item.
    $context = context_user::instance($USER->id);
    $uploadfilesizes = block_exaport_get_filessize_by_draftid($newentry->iconfile);
    // Merge with folder icon.
    // FontAwesome icons uses icon merge by JS in Frontend. So, this code is redundant now
    // (also, from now we have new category field 'iconmerge')
    /*if (isset($newentry->iconmerge) && $newentry->iconmerge == 1 && $uploadfilesizes > 0) {
        $fs = get_file_storage();
        $image = $DB->get_record_sql('SELECT * '.
                'FROM {files} '.
                'WHERE contextid = ? '.
                'AND component = "user" '.
                'AND filearea="draft" '.
                'AND itemid = ? '.
                'AND filename<>"."',
                array($context->id, $newentry->iconfile));
        if ($image) {
            $fileimage = $fs->get_file($context->id, 'user', 'draft', $newentry->iconfile, '/', $image->filename);
            $imagecontent = $fileimage->get_content();
            // Merge images.
            $imicon = imagecreatefromstring($imagecontent);
            $imfolder = imagecreatefrompng($CFG->dirroot.'/blocks/exaport/pix/folder_tile.png');

            imagealphablending($imfolder, false);
            imagesavealpha($imfolder, true);

            // Max width/height.
            $maxwidth = 150;
            $maxheight = 80;
            $skew = 10;
            $imicon = skewscaleimage($imicon, $maxwidth, $maxheight, $skew);

            $swidth = imagesx($imfolder);
            $sheight = imagesy($imfolder);
            $owidth = imagesx($imicon);
            $oheight = imagesy($imicon);
            $x = 0;
            $y = 0;
            // Overlay's opacity (in percent).
            $opacity = 75;

            // Coordinates - only for current folder icon..
            imagecopymerge($imfolder,
                    $imicon,
                    $swidth / 2 - $owidth / 2,
                    $sheight / 2 - $oheight / 2 + 10,
                    0,
                    0,
                    $owidth,
                    $oheight,
                    $opacity);

            ob_start();
            imagepng($imfolder);
            $imagedata = ob_get_contents();
            ob_end_clean();

            // Simple checking to PNG.
            if (stripos($imagedata, 'png') == 1) {
                // Delete old file.
                $fileimage->delete();
                // Create file containing new image.
                $fileinfo = array(
                        'contextid' => $context->id,
                        'component' => 'user',
                        'filearea' => 'draft',
                        'itemid' => $image->itemid,
                        'filepath' => '/',
                        'filename' => $image->filename);
                $fs->create_file_from_string($fileinfo, $imagedata);
            };
            imagedestroy($imicon);
            imagedestroy($imfolder);
        };
    };
    unset($newentry->iconmerge);*/
    // Checking userquoata.
    $userquotecheck = block_exaport_file_userquotecheck($uploadfilesizes, $newentry->id);
    $filesizecheck = block_exaport_get_maxfilesize_by_draftid_check($newentry->iconfile);
    if ($userquotecheck && $filesizecheck) {
        file_save_draft_area_files($newentry->iconfile,
            $context->id,
            'block_exaport',
            'category_icon',
            $newentry->id,
            array('maxbytes' => $CFG->block_exaport_max_uploadfile_size));
    };

    // Check if we're in evidencias context and preserve it
    $redirect_categoryid = ($newentry->back == 'same' ? $newentry->id : $original_pid);
    $redirect_url = 'view_items.php?courseid=' . $courseid . '&categoryid=' . $redirect_categoryid;
    
    // Check if the category being created/edited belongs to evidencias
    if ($newentry->id > 0) {
        // Editing existing category - get fresh record to check source
        $cat = $DB->get_record('block_exaportcate', array('id' => $newentry->id));
        if ($cat && !empty($cat->source) && is_numeric($cat->source)) {
            $redirect_url .= '&evidencias=' . $cat->source;
        }
    } else if (!empty($newentry->source) && is_numeric($newentry->source)) {
        // New category with evidencias source
        $redirect_url .= '&evidencias=' . $newentry->source;
    } else if (strpos($original_pid, 'evidencias_') === 0) {
        // Parent is evidencias root
        $evidencias_id = str_replace('evidencias_', '', $original_pid);
        $redirect_url .= '&evidencias=' . $evidencias_id;
    } else if (is_numeric($original_pid) && $original_pid > 0) {
        // Check if parent category belongs to evidencias
        $parent_cat = $DB->get_record('block_exaportcate', array('id' => $original_pid));
        if ($parent_cat && !empty($parent_cat->source) && is_numeric($parent_cat->source)) {
            $redirect_url .= '&evidencias=' . $parent_cat->source;
        }
    }
    
    redirect($redirect_url);
} else {
    block_exaport_print_header("myportfolio");

    $category = null;
    if ($id = optional_param('id', 0, PARAM_INT)) {
        $category = $DB->get_record_sql('
            SELECT c.id, c.name, c.pid, c.internshare, c.shareall, c.iconmerge
            FROM {block_exaportcate} c
            WHERE c.userid = ? AND id = ?
        ', array($USER->id, $id));
    }
    if (!$category) {
        $category = new stdClass;
    }

    $category->courseid = $courseid;
    if (!isset($category->id)) {
        $category->id = null;
    }
    $category->back = optional_param('back', '', PARAM_TEXT);
    if (empty($category->pid)) {
        $category->pid = optional_param('pid', 0, PARAM_RAW); // Changed to RAW to support evidencias_123
        error_log("DEBUG CATEGORY: Set category->pid from URL param to: '" . $category->pid . "' (type: " . gettype($category->pid) . ")");
    }

    // Filemanager for editing icon picture.
    $draftitemid = file_get_submitted_draft_itemid('iconfile');
    $context = context_user::instance($USER->id);
    file_prepare_draft_area($draftitemid,
        $context->id,
        'block_exaport',
        'category_icon',
        $category->id,
        array('subdirs' => false, 'maxfiles' => 1, 'maxbytes' => $CFG->block_exaport_max_uploadfile_size));
    $category->iconfile = $draftitemid;

    // For evidencias categories, set the allow_student_uploads field based on internshare value
    if ($is_evidencias) {
        $category->allow_student_uploads = ($category->internshare == 2) ? 1 : 1; // Default to 1 (checked) for evidencias
    }

    $mform->set_data($category);
    $mform->display();
    echo block_exaport_wrapperdivend();

    $PAGE->requires->js('/blocks/exaport/javascript/category.js', true);

    // Translations.
    $translations = array(
        'name', 'role', 'nousersfound',
        'internalaccessgroups', 'grouptitle', 'membercount', 'nogroupsfound',
        'internalaccess', 'externalaccess', 'internalaccessall', 'internalaccessusers', 'view_sharing_noaccess', 'sharejs',
        'notify', 'checkall',
    );

    $translations = array_flip($translations);
    foreach ($translations as $key => &$value) {
        $value = block_exaport_get_string($key);
    }
    unset($value);
    ?>
    <script type="text/javascript">
        //<![CDATA[
        ExabisEportfolio.setTranslations(<?php echo json_encode($translations); ?>);
        //]]>
    </script>
    <?php /**/

    echo $OUTPUT->footer();

}

function skewscaleimage($srcimg, $maxwidth = 100, $maxheight = 100, $skew = 10) {
    $w = imagesx($srcimg);
    $h = imagesy($srcimg);
    // Scale.
    if ($h > $maxheight) {
        $koeff = $h / $maxheight;
        $newwidth = $w / $koeff;
        $srcimg = imagescale($srcimg, $newwidth, $maxheight);
        $h = $maxheight;
        $w = imagesx($srcimg);
    }
    if ($w > $maxwidth) {
        $srcimg = imagescale($srcimg, $maxwidth);
        $w = $maxwidth;
        $h = imagesy($srcimg);
    }
    // Skew it.
    $neww = abs($h * tan(deg2rad($skew)) + $w);
    $step = tan(deg2rad($skew));
    $dstimg = imagecreatetruecolor($neww, $h);
    $bgcolour = imagecolorallocate($dstimg, 0, 0, 0);
    imagecolortransparent($dstimg, $bgcolour);
    imagefill($dstimg, 0, 0, $bgcolour);

    for ($i = 0; $i < $h; $i++) {
        imagecopyresampled($dstimg, $srcimg, $neww - ($w + $step * $i), $i, 0, $i, $w, 1, $w, 1);
    }

    return $dstimg;
}
