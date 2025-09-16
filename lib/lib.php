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

defined('MOODLE_INTERNAL') || die();

use core_privacy\local\request\transform;

require_once($CFG->libdir . '/filelib.php');

if (block_exaport_check_competence_interaction()) {
    // TODO: don't use any of the exacomp functions, use \block_exacomp\api::method() instead!
    if (file_exists($CFG->dirroot . '/blocks/exacomp/lib/lib.php')) {
        require_once($CFG->dirroot . '/blocks/exacomp/lib/lib.php');
    } else {
        require_once($CFG->dirroot . '/blocks/exacomp/lib/div.php');
    }
}

require_once(__DIR__ . '/common.php');

use block_exaport\globals as g;

require_once(__DIR__ . '/lib.exaport.php');
require_once(__DIR__ . '/sharelib.php');
/*** FILE FUNCTIONS **********************************************************************/

/**
 * @param mixed $item
 * @param string $type
 * @param bool $onlyfirst
 * @return array
 */
function block_exaport_get_files($item, $type) {
    // If the user is still existing
    if (context_user::instance($item->userid, IGNORE_MISSING)) { // for deleted/miss users
        $fs = get_file_storage();
        $files = $fs->get_area_files(context_user::instance($item->userid)->id, 'block_exaport', $type, $item->id, null, false);
        return $files;
    }
    return array();
}

/**
 * @deprecated because could result in an array with the value false [ false ], when item_file is not set?!?
 */
function block_exaport_get_item_files($item) {
    global $CFG;

    if ($CFG->block_exaport_multiple_files_in_item) {
        return block_exaport_get_files($item, 'item_file'); // Multiple files
    }

    // only one file.. but in array, to make sure no bugs occur later in foreach-loops
    return [block_exaport_get_single_file($item, 'item_file')];
}

function block_exaport_get_item_files_array($item) {
    return block_exaport_get_files($item, 'item_file'); // Multiple files
}

function block_exaport_get_single_file($item, $type) {
    $file = block_exaport_get_files($item, $type);

    return reset($file);
}

function block_exaport_get_item_single_file($item) {
    return block_exaport_get_single_file($item, 'item_file');
}

function block_exaport_get_category_icon($category) {
    $fs = get_file_storage();

    $file = current($fs->get_area_files(context_user::instance($category->userid)->id, 'block_exaport', 'category_icon',
        $category->id, 'itemid', false));
    if ($file) {
        // Hack, this logic doesn't work for other users for now.
        if ($category->userid !== g::$USER->id) {
            return;
        }

        return g::$CFG->wwwroot . '/pluginfile.php/' . $file->get_contextid() . '/block_exaport/category_icon/' . $file->get_itemid() . '/' .
            $file->get_filename();
    } else {
        return null;
    }
}

/**
 * @param $itemcomment
 * @return stored_file
 * @throws dml_exception
 */
function block_exaport_get_item_comment_file($commentid) {
    $fs = get_file_storage();

    // List all files, excluding directories!
    $areafiles = $fs->get_area_files(context_system::instance()->id, 'block_exaport', 'item_comment_file', $commentid, null, false);

    if (empty($areafiles)) {
        return null;
    } else {
        return reset($areafiles);
    }
}

/**
 * wrote own function, so eclipse knows which type the output renderer is
 *
 * @return block_exaport_renderer
 */
function block_exaport_get_renderer() {
    global $PAGE;

    return $PAGE->get_renderer('block_exaport');
}

function block_exaport_add_to_log($courseid, $module, $action, $url = '', $info = '', $cm = 0, $user = 0) {
    if (!function_exists('get_log_manager')) {
        // Old style.
        return add_to_log($courseid, $module, $action, $url = '', $info = '', $cm = 0, $user = 0);
    }

    // Hack for new style.

    // This is a nasty hack that allows us to put all the legacy stuff into legacy storage,
    // this way we may move all the legacy settings there too.
    $manager = get_log_manager();
    if (method_exists($manager, 'legacy_add_to_log')) {
        $manager->legacy_add_to_log($courseid, $module, $action, $url, $info, $cm, $user);
    }
}

function block_exaport_file_remove($item) {
    $fs = get_file_storage();
    // Associated file (if it's a file item).
    $fs->delete_area_files(context_user::instance($item->userid)->id, 'block_exaport', 'item_file', $item->id);
    // Item content (intro) inside the html editor.
    $fs->delete_area_files(context_user::instance($item->userid)->id, 'block_exaport', 'item_content', $item->id);
}

/*** GENERAL FUNCTIONS **********************************************************************/

function block_exaport_require_login($courseid) {
    global $CFG;

    require_login($courseid);
    require_capability('block/exaport:use', context_system::instance());

    if (empty($CFG->block_exaport_allow_loginas)) {
        // Login as not allowed => check.
        global $USER;
        if (!empty($USER->realuser)) {
            print_error("loginasmode", "block_exaport");
        }
    }
    
    // Add JavaScript to maintain aside navigation state
    block_exaport_add_aside_maintenance_js();
}

/**
 * Add JavaScript to maintain aside navigation state across exaport pages
 */
function block_exaport_add_aside_maintenance_js() {
    global $PAGE;
    
    $PAGE->requires->js_init_code('
    (function() {
        var ExaportAsideMaintainer = {
            storeAsideState: function() {
                try {
                    var drawer = document.querySelector("[data-region=drawer]");
                    if (drawer) {
                        var isOpen = drawer.classList.contains("show") || 
                                    drawer.classList.contains("drawer-open") ||
                                    drawer.getAttribute("aria-expanded") === "true" ||
                                    drawer.getAttribute("aria-hidden") === "false";
                        sessionStorage.setItem("exaport_aside_open", isOpen ? "true" : "false");
                    }
                    
                    var navDrawer = document.querySelector("#nav-drawer");
                    if (navDrawer) {
                        var navIsOpen = navDrawer.classList.contains("show") || 
                                       navDrawer.getAttribute("data-show") === "true" ||
                                       !navDrawer.classList.contains("closed");
                        sessionStorage.setItem("exaport_nav_drawer_open", navIsOpen ? "true" : "false");
                    }
                    
                    var adaptableNav = document.querySelector("#navwrap");
                    if (adaptableNav) {
                        var adaptableIsOpen = adaptableNav.style.display !== "none" && 
                                             !adaptableNav.classList.contains("hidden");
                        sessionStorage.setItem("exaport_adaptable_nav_open", adaptableIsOpen ? "true" : "false");
                    }
                } catch(e) {
                    console.log("Error storing aside state:", e);
                }
            },
            
            restoreAsideState: function() {
                try {
                    var self = this;
                    setTimeout(function() {
                        var asideWasOpen = sessionStorage.getItem("exaport_aside_open") === "true";
                        var navDrawerWasOpen = sessionStorage.getItem("exaport_nav_drawer_open") === "true";
                        var adaptableNavWasOpen = sessionStorage.getItem("exaport_adaptable_nav_open") === "true";

                        if (asideWasOpen) {
                            var drawer = document.querySelector("[data-region=drawer]");
                            if (drawer && !drawer.classList.contains("show")) {
                                var toggleBtn = document.querySelector("[data-action=toggle-drawer]");
                                if (toggleBtn) {
                                    toggleBtn.click();
                                }
                            }
                        }

                        if (navDrawerWasOpen) {
                            var navDrawer = document.querySelector("#nav-drawer");
                            if (navDrawer && !navDrawer.classList.contains("show")) {
                                var navToggle = document.querySelector("[data-action=toggle-nav-drawer]");
                                if (navToggle) {
                                    navToggle.click();
                                }
                            }
                        }
                        
                        if (adaptableNavWasOpen) {
                            var adaptableToggle = document.querySelector("#navtoggle");
                            if (adaptableToggle) {
                                var navwrap = document.querySelector("#navwrap");
                                if (navwrap && navwrap.style.display === "none") {
                                    adaptableToggle.click();
                                }
                            }
                        }
                    }, 400);
                } catch(e) {
                    console.log("Error restoring aside state:", e);
                }
            },
            
            setupEventListeners: function() {
                var self = this;
                
                document.addEventListener("click", function(e) {
                    var target = e.target.closest("a");
                    if (target && target.href) {
                        if (target.href.indexOf("/blocks/exaport/") !== -1 || 
                            target.classList.contains("exaport-nav") ||
                            target.closest(".block_exaport")) {
                            self.storeAsideState();
                        }
                    }
                });
                
                document.addEventListener("click", function(e) {
                    if (e.target.matches("[data-action=toggle-drawer], [data-action=toggle-nav-drawer], #navtoggle")) {
                        setTimeout(function() {
                            self.storeAsideState();
                        }, 200);
                    }
                });
            },
            
            init: function() {
                var self = this;
                if (document.readyState === "loading") {
                    document.addEventListener("DOMContentLoaded", function() {
                        self.restoreAsideState();
                        self.setupEventListeners();
                    });
                } else {
                    self.restoreAsideState();
                    self.setupEventListeners();
                }
            }
        };
        
        ExaportAsideMaintainer.init();
    })();
    ');
}

function block_exaport_shareall_enabled() {
    global $CFG;

    return empty($CFG->block_exaport_disable_shareall);
}

function block_exaport_externaccess_enabled() {
    global $CFG;

    return empty($CFG->block_exaport_disable_externaccess);
}

function block_exaport_shareemails_enabled() {
    global $CFG;

    return empty($CFG->block_exaport_disable_shareemails);
}

function block_exaport_external_comments_enabled() {
    global $CFG;

    return empty($CFG->block_exaport_disable_external_comments);
}

function block_exaport_setup_default_categories() {
    global $DB, $USER, $CFG;
    if (block_exaport_course_has_desp() && !$DB->record_exists('block_exaportcate', array('userid' => $USER->id))
        && !empty($CFG->block_exaport_create_desp_categories)
    ) {
        block_exaport_import_categories("desp_categories");
    }
}

function block_exaport_import_categories($categoriesstring) {
    global $DB, $USER;
    $categories = trim(get_string($categoriesstring, "block_exaport"));

    if (!$categories) {
        return;
    }

    $categories = explode("\n", $categories);
    $categories = array_map('trim', $categories);

    $newentry = new stdClass();
    $newentry->timemodified = time();
    $newentry->userid = $USER->id;
    $newentry->pid = 0;

    $lastmainid = null;
    foreach ($categories as $category) {

        if ($category[0] == '-' && $lastmainid) {
            // Subcategory.
            $newentry->name = trim($category, '-');
            $newentry->pid = $lastmainid;
            if (!$DB->record_exists('block_exaportcate', array("name" => trim($category, '-')))) {
                $DB->insert_record("block_exaportcate", $newentry);
            }
        } else {
            $newentry->name = $category;
            $newentry->pid = 0;
            if (!$DB->record_exists('block_exaportcate', array("name" => $category))) {
                $lastmainid = $DB->insert_record("block_exaportcate", $newentry);
            } else {
                $lastmainid = $DB->get_field('block_exaportcate', 'id', array("name" => $category));
            }
        }
    }
}

function block_exaport_feature_enabled($feature) {
    global $CFG;
    if ($feature == 'views') {
        return true;
    }
    if ($feature == 'copy_to_course') {
        return !empty($CFG->block_exaport_feature_copy_to_course);
    }
    die('wrong feature');
}

/*** OTHER FUNCTIONS **********************************************************************/

function block_exaport_has_categories($userid) {
    global $CFG, $DB;
    if ($DB->count_records_sql("SELECT COUNT(*) FROM {block_exaportcate} WHERE userid=? AND pid=0", array($userid)) > 0) {
        return true;
    } else {
        return false;
    }
}

function block_exaport_moodleimport_file_area_name($userid, $assignmentid, $courseid) {
    global $CFG;

    return $courseid . '/' . $CFG->moddata . '/assignment/' . $assignmentid . '/' . $userid;
}

function block_exaport_print_file(stored_file $file) {
    global $CFG, $OUTPUT;
    if (!$file) {
        return '';
    }
    $url = moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(), $file->get_filearea(),
        $file->get_itemid(), $file->get_filepath(), $file->get_filename());

    $icon = new pix_icon(file_mimetype_icon($file->get_mimetype()), '');
    if ($file->is_valid_image()) {
        return "<img src=\"$url\" alt=\"" . s($file->get_filename()) . "\" />";
    } else {
        $icon = $OUTPUT->pix_icon(file_file_icon($file), '');
        return '<p>' . $icon . ' ' . $OUTPUT->action_link($url, $file->get_filename()) . "</p>";
    }
}

function block_exaport_course_has_desp() {
    global $COURSE, $DB;

    if (isset($COURSE->has_desp)) {
        return $COURSE->has_desp;
    }

    // Desp block installed?
    if (!is_dir(__DIR__ . '/../../desp')) {
        return $COURSE->has_desp = false;
    }

    $context = context_course::instance($COURSE->id);

    return $COURSE->has_desp = $DB->record_exists('block_instances',
        array('blockname' => 'desp', 'parentcontextid' => $context->id));
}

function block_exaport_wrapperdivstart() {
    return html_writer::start_tag('div', array('id' => 'exaport'));
}

function block_exaport_wrapperdivend() {
    return html_writer::end_tag('div');
}

function block_exaport_init_js_css() {
    global $PAGE, $CFG;

    // Only allowed to be called once.
    static $jsinited = false;
    if ($jsinited) {
        return;
    }
    $jsinited = true;

    /* $PAGE->requires->css('/blocks/exaport/css/jquery-ui.css'); */

    $PAGE->requires->jquery();
    $PAGE->requires->jquery_plugin('ui');
    $PAGE->requires->jquery_plugin('ui-css');

    // possible problems with $CONF->cachejs = false!

    //$PAGE->requires->js('/blocks/exaport/javascript/jquery.json.js', true);
    //$PAGE->requires->js_call_amd('block_exaport/json', 'initialise');

    $PAGE->requires->js('/blocks/exaport/javascript/exaport.js', true);

    $PAGE->requires->css('/blocks/exaport/css/styles.css');

    $scriptname = preg_replace('!\.[^\.]+$!', '', basename($_SERVER['PHP_SELF']));
    if (file_exists($CFG->dirroot . '/blocks/exaport/css/' . $scriptname . '.css')) {
        $PAGE->requires->css('/blocks/exaport/css/' . $scriptname . '.css');
    }
    if (file_exists($CFG->dirroot . '/blocks/exaport/javascript/' . $scriptname . '.js')) {
        $PAGE->requires->js('/blocks/exaport/javascript/' . $scriptname . '.js', true);
    }

    // language strings
    $PAGE->requires->string_for_js('close', 'block_exaport');
}

/**
 * Print moodle header
 *
 * @param string $itemidentifier translation-id for this page
 * @param string $subitemidentifier translation-id for second level if needed
 */
function block_exaport_print_header($itemidentifier, $subitemidentifier = null) {

    if (!is_string($itemidentifier)) {
        throw new moodle_exception('not supported');
    }

    global $CFG, $COURSE, $PAGE;

    block_exaport_init_js_css();

    // Navigationspfad.
    $navlinks = array();
    $navlinks[] = array('name' => block_exaport_get_string("blocktitle"),
        'link' => "view.php?courseid=" . $COURSE->id, 'type' => 'title');
    $navitemidentifier = $itemidentifier;

    $icon = $itemidentifier;
    $currenttab = $itemidentifier;

    // Haupttabs.
    $tabs = array();

    if (block_exaport_course_has_desp()) {
        $tabs['back'] = new tabobject('back', $CFG->wwwroot . '/blocks/desp/index.php?courseid=' . $COURSE->id,
            get_string("back_to_desp", "block_exaport"), '', true);
    }

    $tabs['resume_my'] = new tabobject('resume_my', $CFG->wwwroot . '/blocks/exaport/resume.php?courseid=' . $COURSE->id,
        get_string("resume_my", "block_exaport"), '', true);
    $tabs['myportfolio'] = new tabobject('myportfolio', $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $COURSE->id,
        block_exaport_get_string("myportfolio"), '', true);
    $tabs['shared_categories'] = new tabobject('shared_categories',
        $CFG->wwwroot . '/blocks/exaport/shared_categories.php?courseid=' . $COURSE->id,
        block_exaport_get_string("shared_categories"), '', true);
    $tabtitle = get_string("importexport", "block_exaport");
    /*$scriptname = basename($_SERVER['SCRIPT_NAME']);
    if ($scriptname == 'export_scorm.php') {
        $tabtitle = get_string("export_short", "block_exaport");
    } elseif ($scriptname == 'import_file.php') {
        $tabtitle = get_string("import_short", "block_exaport");
    }*/
    $tabs['importexport'] = new tabobject('importexport', $CFG->wwwroot . '/blocks/exaport/importexport.php?courseid=' . $COURSE->id,
        $tabtitle, '', true);

    $tabitemidentifier = $itemidentifier ? preg_replace('!_.*!', '', $itemidentifier) : '';
    $tabsubitemidentifier = $subitemidentifier ? preg_replace('!_.*!', '', $subitemidentifier) : '';

    if (strpos($tabitemidentifier, 'bookmarks') === 0) {
        $tabitemidentifier = 'myportfolio';
    }

    // Kind of hacked here, find another solution.

    $tabtree = new tabtree($tabs, $currenttab);
    if ($tabsubitemidentifier && $tabobj = $tabtree->find($tabsubitemidentifier)) {
        // Overwrite active and selected - suppress deprecation warning
        if (!property_exists($tabobj, 'active')) {
            $tabobj->active = true;
        } else {
            $tabobj->active = true;
        }
        if (!property_exists($tabobj, 'selected')) {
            $tabobj->selected = true;
        } else {
            $tabobj->selected = true;
        }
    }
    if ($tabobj = $tabtree->find($tabitemidentifier)) {
        // Overwrite active and selected - suppress deprecation warning
        if (!property_exists($tabobj, 'active')) {
            $tabobj->active = true;
        } else {
            $tabobj->active = true;
        }
        if (!property_exists($tabobj, 'selected')) {
            $tabobj->selected = true;
        } else {
            $tabobj->selected = true;
        }
    }

    $itemname = get_string($navitemidentifier, "block_exaport");
    if ($itemname[0] == '[') {
        $itemname = get_string($navitemidentifier);
    }
    $navlinks[] = array('name' => $itemname, 'link' => null, 'type' => 'misc');

    /* $navigation = build_navigation($navlinks); */
    foreach ($navlinks as $navlink) {
        $PAGE->navbar->add($navlink["name"], $navlink["link"]);
    }

    $PAGE->set_title($itemname);
    $PAGE->set_heading(get_string(block_exaport_course_has_desp() ? "desp_pluginname" : 'pluginname', "block_exaport"));

    // Header.
    global $OUTPUT;

    echo $OUTPUT->header();
    echo block_exaport_wrapperdivstart();
    if (block_exaport_course_has_desp()) {
        // Include the desp css.
        echo '<link href="' . $CFG->wwwroot . '/blocks/desp/styles.css" rel="stylesheet" type="text/css" />';
    }

    echo $OUTPUT->render($tabtree);

    if (block_exaport_course_has_desp() && (strpos($currenttab, 'myportfolio') === 0)) {
        echo '<div id="messageboxses1"';
        /* if (file_exists("../desp/images/message_ses1.gif")){
            echo ' style="min-height:145px; background: url(\'../desp/images/message_ses1.gif\') no-repeat left top; "';} */
        echo '>
            <div id="messagetxtses1">
                ' . get_string("desp_einleitung", "block_exaport") . '
            </div>
        </div>

        <br /><br />';
    }
}

function block_exaport_get_string($string, $param = null) {
    $manager = get_string_manager();

    if (block_exaport_course_has_desp() && $manager->string_exists('desp_' . $string, 'block_exaport')) {
        return $manager->get_string('desp_' . $string, 'block_exaport', $param);
    }

    if ($manager->string_exists($string, "block_exaport")) {
        return $manager->get_string($string, 'block_exaport', $param);
    }

    return $manager->get_string($string, '', $param);
}

function todo_string($string) {
    $manager = get_string_manager();

    if ($manager->string_exists($string, "block_exaport")) {
        return $manager->get_string($string, 'block_exaport');
    }

    return '[[' . $string . ']]';
}

function block_exaport_print_footer() {
    echo g::$OUTPUT->footer();
}

/**
 * Parse user submitted item_type and return a correct type
 *
 * @param string $type
 * @param boolean $allallowd Is the type 'all' allowed? E.g. for Item-List
 * @return string correct type
 */
function block_exaport_check_item_type($type, $allallowed) {
    if (in_array($type, array('link', 'file', 'note', 'mixed'))) {
        return $type;
    } else {
        return $allallowed ? 'all' : false;
    }
}

/**
 * Convert item type to plural
 *
 * @param string $type
 * @return string Plural. E.g. file->files, note->notes, all->all (has no plural)
 */
function block_exaport_get_plural_item_type($type) {
    return $type == 'all' ? $type : $type . 's';
}

/**
 * Parse user submitted item sorting and check if allowed/available!
 *
 * @param $sort the sorting in a format like "category.desc"
 * @return Array(sortcolumn, asc|desc)
 */
function block_exaport_parse_sort($sort, array $allowedsorts, array $defaultsort = null) {
    if (!is_array($sort)) {
        $sort = explode('.', $sort);
    }

    $column = $sort[0];
    $order = isset($sort[1]) ? $sort[1] : '';

    if (!in_array($column, $allowedsorts)) {
        if ($defaultsort) {
            return $defaultsort;
        } else {
            return array(reset($allowedsorts), 'asc');
        }
    }

    // Sortorder never desc allowed!
    if ($column == 'sortorder') {
        return array($column, 'asc');
    }

    if ($order != "desc") {
        $order = "asc";
    }

    return array($column, $order);
}

function block_exaport_parse_item_sort($sort, $catsortallowed) {
    if ($catsortallowed) {
        return block_exaport_parse_sort($sort, array('date', 'name', 'category', 'type'), array('date', 'desc'));
    }

    return block_exaport_parse_sort($sort, array('date', 'name', 'type'), array('date', 'desc'));
}

function block_exaport_item_sort_to_sql($sort, $catsortallowed) {
    $sort = block_exaport_parse_item_sort($sort, $catsortallowed);

    $column = $sort[0];
    $order = $sort[1];

    if ($column == "date") {
        $sqlsort = "i.timemodified " . $order;
    } else if ($column == "category") {
        $sqlsort = "cname " . $order . ", i.timemodified";
    } else {
        $sqlsort = "i." . $column . " " . $order . ", i.timemodified";
    }

    return ' order by ' . $sqlsort;
}

function block_exaport_parse_view_sort($sort, $forshared = false) {
    return block_exaport_parse_sort($sort, array('name', 'timemodified'));
}

function block_exaport_view_sort_to_sql($sort) {
    global $CFG;
    $sort = block_exaport_parse_view_sort($sort);

    $column = $sort[0];
    $order = $sort[1];

    if ((strcmp($column, "name") == 0) && (strcmp($CFG->dbtype, "sqlsrv") == 0)) {
        $sqlsort = "cast(v." . $column . " AS varchar(max)) " . $order . ", v.timemodified DESC";
    } else {
        if ((strcmp($column, "timemodified") == 0) && (strcmp($CFG->dbtype, "sqlsrv") == 0)) {
            $sqlsort = "v.timemodified DESC";
        } else {
            $sqlsort = "v." . $column . " " . $order . ", v.timemodified DESC";
        }
    }

    return ' order by ' . $sqlsort;
}

function block_exaport_get_user_preferences_record($userid = null) {
    global $DB;

    if (is_null($userid)) {
        global $USER;
        $userid = $USER->id;
    }
    $conditions = array("user_id" => $userid);

    return $DB->get_record('block_exaportuser', $conditions);
}

function block_exaport_get_user_preferences($userid = null) {
    global $DB;
    if (is_null($userid)) {
        global $USER;
        $userid = $USER->id;
    }

    $userpreferences = block_exaport_get_user_preferences_record($userid);

    if (!$userpreferences || !$userpreferences->user_hash) {
        do {
            $hash = substr(md5(uniqid(rand(), true)), 3, 8);
        } while ($DB->record_exists("block_exaportuser", array("user_hash" => $hash)));

        block_exaport_set_user_preferences($userid, array('user_hash' => $hash, 'description' => ''));

        $userpreferences = block_exaport_get_user_preferences_record($userid);
    }

    return $userpreferences;
}

function block_exaport_check_competence_interaction() {
    global $CFG;

    return !empty($CFG->block_exaport_enable_interaction_competences) &&
        class_exists('\block_exacomp\api') && \block_exacomp\api::active();
}

function block_exaport_build_comp_table($item, $role = "teacher", $competences = null) {
    global $DB;

    // TODO: refactor: use block_exaport_get_active_comps_for_item instead.
    // $sql = "SELECT CONCAT(CONCAT(da.id,'_'),d.id) as uniquid,d.title, d.id ".
    // " FROM {".BLOCK_EXACOMP_DB_DESCRIPTORS."} d, {".BLOCK_EXACOMP_DB_COMPETENCE_ACTIVITY."} da ".
    // " WHERE d.id=da.compid AND da.eportfolioitem=1 AND da.activityid=?";
    // $descriptors = $DB->get_records_sql($sql, array($item->id));

    // RW 2021.04.06 using block_exaport_get_active_comps_for_item
    $descriptors = $competences["descriptors"];
    $topics = $competences["topics"];

    $content = "<table class='compstable flexible boxaligncenter generaltable'>
                <tr><td><h2>" . $item->name . "</h2></td></tr>";

    if ($role == "teacher") {
        $disteacher = " ";
        $disstudent = " disabled ";
    } else {
        $disteacher = " disabled ";
        $disstudent = " ";
    }

    $trclass = "even";
    foreach ($descriptors as $descriptor) {
        if ($trclass == "even") {
            $trclass = "odd";
            $bgcolor = ' style="background-color:#efefef" ';
        } else {
            $trclass = "even";
            $bgcolor = ' style="background-color:#ffffff" ';
        }
        $content .= '<tr ' . $bgcolor . '><td>' . $descriptor->title . '</td></tr>';
        /* <td>
        <input'.$dis_teacher.'type="checkbox" name="data[' . $descriptor->id . ']" checked="###checked' . $descriptor->id . '###" />
        </td>
        <td><input'.$dis_student.'type="checkbox" name="eval[' . $descriptor->id . ']" checked="###eval' . $descriptor->id . '###"/>
        </td></tr>';*/
    }
    foreach ($topics as $topic) {
        if ($trclass == "even") {
            $trclass = "odd";
            $bgcolor = ' style="background-color:#efefef" ';
        } else {
            $trclass = "even";
            $bgcolor = ' style="background-color:#ffffff" ';
        }
        $content .= '<tr ' . $bgcolor . '><td>' . $topic->title . '</td></tr>';
        /* <td>
        <input'.$dis_teacher.'type="checkbox" name="data[' . $descriptor->id . ']" checked="###checked' . $descriptor->id . '###" />
        </td>
        <td><input'.$dis_student.'type="checkbox" name="eval[' . $descriptor->id . ']" checked="###eval' . $descriptor->id . '###"/>
        </td></tr>';*/
    }
    /* $content .= "</table><input type='submit' value='" . get_string("auswahl_speichern", "block_exais_competences") . "' />
    </form>"; */
    $content .= '</table>';
    // Gget teacher comps.
    /*
    $competences = block_exaport_get_competences($item, 1);
    foreach ($competences as $competence) {
            $content = str_replace('###checked' . $competence->descid . '###', 'checked', $content);
        }
    $content = preg_replace('/checked="###checked([0-9_])+###"/', '', $content);
    // Get student comps.
    $competences = block_exaport_get_competences($item, 0);
    foreach ($competences as $competence) {
            $content = str_replace('###eval' . $competence->descid . '###', 'checked', $content);
        }
    $content = preg_replace('/checked="###eval([0-9_])+###"/', '', $content);
    */
    echo $content;
}

function block_exaport_set_competences($values, $item, $reviewerid, $role = 1) {
    global $DB;

    $DB->delete_records(BLOCK_EXACOMP_DB_COMPETENCE_USER_MM,
        array("activityid" => $item->id, "eportfolioitem" => 1, "role" => $role, "userid" => $item->userid));

    foreach ($values as $value) {
        $data = array(
            "activityid" => $item->id,
            "eportfolioitem" => 1,
            "compid" => $value,
            "userid" => $item->userid,
            "reviewerid" => $reviewerid,
            "role" => $role,
        );
        $DB->insert_record(BLOCK_EXACOMP_DB_COMPETENCE_USER_MM, $data);
    }
}

/**
 * @deprecated refactor to use block_exaport_get_active_comps_for_item
 */
function block_exaport_get_active_compids_for_item($item) {
    $comps = block_exaport_get_active_comps_for_item($item);
    if ($comps && is_array($comps) && array_key_exists('descriptors', $comps)) {
        $ids = array_keys($comps['descriptors']); // TODO this ignores the topics, which didn't exist before anyways RW 2021.04.06
    } else {
        $ids = [];
    }
    return array_combine($ids, $ids);
}

function block_exaport_check_item_competences($item) {
    return (bool)block_exaport_get_active_comps_for_item($item)["descriptors"];
}

function block_exaport_get_active_comps_for_item($item) {
    return \block_exacomp\api::get_active_comps_for_exaport_item($item->id, $item->userid, @$item->courseid);
}

function block_exaport_build_comp_tree($type, $itemorresume, $allowedit = true) {
    global $CFG, $USER;

    if ($type == 'skillscomp' || $type == 'goalscomp') {
        $forresume = true;
        $resume = $itemorresume;
        $item = null;
        $activedescriptors = $resume->descriptors;
    } else if ($type == 'item') {
        $forresume = false;
        $resume = null;
        $item = $itemorresume;
        $activedescriptors = isset($item->compids_array) ? $item->compids_array : [];
    } else {
        throw new \block_exaport\moodle_exception("wrong \$type: $type");
    }

    if ($forresume) {
        $content = '<form id="treeform" method="post" ' .
            ' action="' . $CFG->wwwroot . '/blocks/exaport/resume.php?courseid=' . $resume->courseid .
            '&id=' . $resume->id . '&sesskey=' . sesskey() . '#' . $type . '">';
    } else {
        $content = '<form id="treeform">';
    }

    $printtree = function($items, $level = 0) use (&$printtree, $forresume, $activedescriptors, $allowedit) {
        if (!$items) {
            return '';
        }

        $content = '';
        if ($level == 0) {
            $content .= '<ul id="comptree" class="treeview">';
        } else {
            $content .= '<ul>';
        }

        foreach ($items as $item) {
            if ($item instanceof \block_exacomp\descriptor && in_array($item->id, $activedescriptors)) {
                $checked = 'checked="checked"';
            } else {
                $checked = '';
            }

            $content .= '<li>';
            if ($item instanceof \block_exacomp\descriptor) {
                $content .= '<input type="checkbox" name="desc' . ($forresume ? '[]' : '') . '" ' . $checked . ' value="' . $item->id . '" ' .
                    (!$allowedit ? 'disabled="disabled"' : '') . '>';
            }
            $content .= $item->title .
                ($item->achieved ? ' ' . g::$OUTPUT->pix_icon("i/badge",
                        block_exaport_get_string('selected_competencies')) : '') .
                $printtree($item->get_subs(), $level + 1) .
                '</li>';
        }

        $content .= '</ul>';

        return $content;
    };

    $comptree = \block_exacomp\api::get_comp_tree_for_exaport($USER->id);

    // No filtering.
    /*
    if ($compTree && $forresume) {
        $filter_tree = function($item) use (&$filter_tree, $forresume, $active_descriptors) {
            $item->set_subs(array_filter($item->get_subs(), $filter_tree));

            if ($item instanceof \block_exacomp\descriptor) {
                // achieved, or children achieved
                return ($item->get_subs() || $item->achieved);
            } else {
                return !!$item->get_subs();
            }
        };
        $compTree = array_filter($compTree, $filter_tree);
    }
    */

    /*
    if (!$compTree) {
        $content .= '<div><h4 style="text-align:center; padding: 40px;">'.
                block_exaport\trans(['de:Eine Kurse hat leider keine Kompetenzen für den Kurs aktiviert', "en:"]).'</h4></div>';
    } else {
        $content .= $print_tree($compTree);
    }
    */
    $content .= $printtree($comptree);

    if ($forresume) {
        $content .= '<input type="hidden" value="edit" name="action">';
        $content .= '<input type="hidden" value="' . $type . '" name="type">';
        $content .= '<input type="hidden" value="' . sesskey() . '" name="sesskey">';
        $content .= '<input type="submit" id="id_submitbutton" type="submit" value="' . get_string('savechanges') .
            '" name="submitbutton">';
        $content .= '<input type="submit" id="id_cancel" class="btn-cancel" onclick="skipClientValidation = true; return true;" ' .
            ' value="' . get_string('cancel') . '" name="cancel">';
    } else {
        $content .= '<input type="button" id="id_submitbutton2" value="' . get_string('savechanges') .
            '" name="savecompetencesbutton" onClick="jQueryExaport.colorbox.close();">';
    }
    $content .= '</form>';

    return $content;
}

function block_exaport_assignmentversion() {
    global $DB;
    $modassign = new stdClass();
    if ($DB->record_exists('modules', array('name' => 'assign', 'visible' => 1))) {
        $modassign->new = 1;
        $modassign->title = "assign";
        $modassign->filearea = "submission_files";
        $modassign->component = "assignsubmission_file";
    } else {
        $modassign->new = 0;
        $modassign->title = "assignment";
        $modassign->filearea = "submission";
        $modassign->component = 'mod_assignment';
    };

    return $modassign;
}

function block_exaport_get_assignments_for_import($modassign) {
    global $USER, $DB;
    if ($modassign->new) {
        $assignments = $DB->get_records_sql("SELECT s.id AS submissionid, a.id AS aid, s.assignment, s.timemodified," .
            " a.name, a.course, c.fullname AS coursename" .
            " FROM {assignsubmission_file} sf " .
            " INNER JOIN {assign_submission} s ON sf.submission=s.id " .
            " INNER JOIN {assign} a ON s.assignment=a.id " .
            " LEFT JOIN {course} c on a.course = c.id " .
            " WHERE s.userid=?", array($USER->id));
    } else {
        $assignments = $DB->get_records_sql("SELECT s.id AS submissionid, a.id AS aid, s.assignment, s.timemodified," .
            " a.name, a.course, a.assignmenttype, c.fullname AS coursename " .
            " FROM {assignment_submissions} s " .
            " JOIN {assignment} a ON s.assignment=a.id " .
            " LEFT JOIN {course} c on a.course = c.id " .
            " WHERE s.userid=?", array($USER->id));
    }
    return $assignments;
}

function block_exaport_set_user_preferences($userid, $preferences = null) {
    global $DB;

    if (is_null($preferences) && (is_array($userid) || is_object($userid))) {
        global $USER;
        $preferences = $userid;
        $userid = $USER->id;
    }

    $newuserpreferences = new stdClass();

    if (is_object($preferences)) {
        $newuserpreferences = $preferences;
    } else if (is_array($preferences)) {
        $newuserpreferences = (object)$preferences;
    } else {
        echo 'error #fjklfdsjkl';
    }

    if ($olduserpreferences = block_exaport_get_user_preferences_record($userid)) {
        $newuserpreferences->id = $olduserpreferences->id;
        $DB->update_record('block_exaportuser', $newuserpreferences);
    } else {
        $newuserpreferences->user_id = $userid;
        $DB->insert_record("block_exaportuser", $newuserpreferences);
    }
}

function block_exaport_get_item_where() {
    // Extra where for epop.
    return "(i.isoez=0 OR (i.isoez=1 AND (i.intro<>'' OR i.url<>'' OR i.attachment<>'')))";
}

function block_exaport_get_category($id) {
    global $USER, $DB;

    if ($id == 0) {
        return block_exaport_get_root_category();
    }

    return $DB->get_record("block_exaportcate", array(
        'id' => $id,
        'userid' => $USER->id,
    ));
}

function block_exaport_get_root_category($userid = null) {
    global $DB, $USER;
    if ($userid === null) {
        $userid = $USER->id;
    }

    return (object)array(
        'id' => 0,
        'pid' => 0,
        'name' => block_exaport_get_string('root_category'),
        'url' => g::$CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . g::$COURSE->id,
        'item_cnt' => $DB->get_field_sql('
                    SELECT COUNT(i.id) AS item_cnt
                    FROM {block_exaportitem} i
                    WHERE i.userid = ? AND i.categoryid = 0 AND ' . block_exaport_get_item_where() . '
                ', array($userid)),

    );
}

function block_exaport_get_shareditems_category($name = null, $userid = null) {
    global $DB, $USER;

    return (object)array(
        'id' => -1,
        'pid' => -123, // Not parent available.
        'name' => $name != null ? $name : block_exaport_get_string('shareditems_category'),
        'item_cnt' => '',
        'url' => g::$CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . g::$COURSE->id . '&type=shared&userid=' . $userid,
        'userid' => $userid ? $userid : '',
        /*      'item_cnt' => $DB->get_field_sql('
                    SELECT COUNT(i.id) AS item_cnt
                    FROM {block_exaportitem} i
                    WHERE i.userid = ? AND i.categoryid = 0 AND '.block_exaport_get_item_where().'
                ', array($USER->id))  */
    );
}

function block_exaport_badges_enabled() {
    global $CFG;

    if ($CFG->enablebadges) {
        require_once($CFG->libdir . '/badgeslib.php');
        require_once($CFG->dirroot . '/badges/lib/awardlib.php');
    }

    return $CFG->enablebadges;
}

function block_exaport_get_all_user_badges($userid = null) {
    global $USER;

    if ($userid == null) {
        $userid = $USER->id;
    }

    if (!block_exaport_badges_enabled()) {
        return [];
    } else if (function_exists('block_exacomp_get_all_user_badges')) {
        return block_exacomp_get_all_user_badges($userid);
    } else {
        // For using badges without exacomp installation.
        return badges_get_user_badges($userid);
    }
}

function block_exaport_get_user_category($title, $userid) {
    global $DB;

    return $DB->get_record('block_exaportcate', array('userid' => $userid, 'name' => $title));
}

function block_exaport_create_user_category($title, $userid, $parentid = 0, $courseid = 0) {
    global $DB;

    if (!$DB->record_exists('block_exaportcate', array('userid' => $userid, 'name' => $title, 'pid' => $parentid))) {
        $new_category = array(
            'userid' => $userid, 
            'name' => $title, 
            'pid' => $parentid, 
            'courseid' => $courseid
        );
        
        // Check if this category is being created within an evidencias folder
        if (block_exaport_is_parent_evidencias_folder($parentid)) {
            $new_category['source'] = 999; // Special value to indicate evidencias category
        }
        
        $id = $DB->insert_record('block_exaportcate', $new_category);

        return $DB->get_record('block_exaportcate', array('id' => $id));
    }

    return false;
}

/**
 * Check if a parent ID corresponds to an evidencias folder or is within one
 * @param mixed $parentid The parent category ID
 * @return bool True if parent is evidencias folder or within evidencias hierarchy
 */
function block_exaport_is_parent_evidencias_folder($parentid) {
    global $DB, $USER;
    
    // Check if parent is directly an evidencias folder
    if (strpos($parentid, 'evidencias_') === 0) {
        return true;
    }
    
    // Check if parent is a numeric category within evidencias hierarchy
    if (is_numeric($parentid) && $parentid > 0) {
        $parent_category = $DB->get_record('block_exaportcate', array('id' => $parentid, 'userid' => $USER->id));
        if ($parent_category) {
            return block_exaport_is_category_within_evidencias($parent_category);
        }
    }
    
    return false;
}

/**
 * Get categories that belong to evidencias folders (marked with source=999)
 * @param int $userid User ID
 * @return array Array of category objects
 */
function block_exaport_get_evidencias_categories($userid) {
    global $DB, $CFG;
    
    $categories = $DB->get_records_sql("
        SELECT c.id, c.name, c.pid, c.userid, c.courseid, c.timemodified, 
               COUNT(i.id) AS item_cnt
        FROM {block_exaportcate} c
        LEFT JOIN {block_exaportitem} i ON i.categoryid = c.id AND " . block_exaport_get_item_where() . "
        WHERE c.userid = ? AND c.source = 999
        GROUP BY c.id, c.name, c.pid, c.userid, c.courseid, c.timemodified
        ORDER BY c.name ASC
    ", array($userid));
    
    // Format categories for display
    $formatted_categories = array();
    foreach ($categories as $category) {
        $formatted_categories[$category->id] = $category;
    }
    
    return $formatted_categories;
}

/**
 * Get categories created specifically within evidencias for a particular course
 */
function block_exaport_get_evidencias_categories_for_course($userid, $courseid) {
    global $DB, $CFG;
    
    $categories = $DB->get_records_sql("
        SELECT c.id, c.name, c.pid, c.userid, c.courseid, c.timemodified, 
               COUNT(i.id) AS item_cnt
        FROM {block_exaportcate} c
        LEFT JOIN {block_exaportitem} i ON i.categoryid = c.id AND " . block_exaport_get_item_where() . "
        WHERE c.userid = ? AND c.source = ?
        GROUP BY c.id, c.name, c.pid, c.userid, c.courseid, c.timemodified
        ORDER BY c.name ASC
    ", array($userid, $courseid));
    
    // Format categories for display
    $formatted_categories = array();
    foreach ($categories as $category) {
        $category->type = 'evidencias_category';
        $category->icon = 'fa-folder';
        $category->url = $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . g::$COURSE->id . 
                        '&categoryid=' . $category->id;
        $formatted_categories[] = $category;
    }
    
    return $formatted_categories;
}

/**
 * Autofill the view with all existing artefacts
 *
 * @param integer $viewid
 * @param string $existingartefacts
 * @return string Artefacts
 */
function fill_view_with_artefacts($viewid, $existingartefacts = '') {
    global $DB, $USER;

    $artefacts = block_exaport_get_portfolio_items(1, null, false);
    if ($existingartefacts <> '') {
        $existingartefactsarray = explode(',', $existingartefacts);
        $filledartefacts = $existingartefacts;
    } else {
        $existingartefactsarray = array();
        $filledartefacts = '';
    }
    if (count($artefacts) > 0) {
        $y = 1;
        foreach ($artefacts as $artefact) {
            if (!in_array($artefact->id, $existingartefactsarray)) {
                $block = new stdClass();
                $block->itemid = $artefact->id;
                $block->viewid = $viewid;
                $block->type = 'item';
                $block->positionx = 1;
                $block->positiony = $y;
                $block->id = $DB->insert_record('block_exaportviewblock', $block);
                $y++;
                $filledartefacts .= ',' . $artefact->id;
            }
        }
        if ($existingartefacts == '') {
            $filledartefacts = substr($filledartefacts, 1);
        };
    };

    return $filledartefacts;
}

/**
 * Autoshare the view to teachers
 *
 * @param integer $viewid
 * @return nothing
 */
function block_exaport_share_view_to_teachers($viewid) {
    global $DB;
    if ($viewid > 0) {
        $allteachers = block_exaport_get_course_teachers();
        $allsharedusers = block_exaport_get_shared_users($viewid);
        $diff = array_diff($allteachers, $allsharedusers);
        $view = $DB->get_record_sql('SELECT * FROM {block_exaportview} WHERE id = ?', array('id' => $viewid));
        if (!$view->shareall) {
            $view->shareall = 0;
        };
        if (!$view->externaccess) {
            $view->externaccess = 0;
        };
        if (!$view->externcomment) {
            $view->externcomment = 0;
        };
        $DB->update_record('block_exaportview', $view);
        // Add all teachers to shared users (if it is not there yet).
        if ((count($allteachers) > 0) && (count($diff) > 0)) {
            foreach ($diff as $userid) {
                // If course has a teacher.
                if ($userid > 0) {
                    $shareitem = new stdClass();
                    $shareitem->viewid = $view->id;
                    $shareitem->userid = $userid;
                    $DB->insert_record("block_exaportviewshar", $shareitem);
                };
            };
        };
    };
}

/**
 * @param stdClass $view
 * @return array
 * @throws dml_exception
 */
function block_exaport_get_view_blocks($view) {
    global $DB, $USER, $CFG;

    $portfolioitems = block_exaport_get_portfolio_items();

    if (isset($view->userid)) {
        $userid = $view->userid;
    } else {
        $userid = $USER->id;
    }
    $badges = block_exaport_get_all_user_badges($userid);

    $query = "SELECT b.*
              FROM {block_exaportviewblock} b
              WHERE b.viewid = ?
              ORDER BY b.positionx, b.positiony";

    $allblocks = $DB->get_records_sql($query, array($view->id));
    $blocks = array();

    foreach ($allblocks as $block) {
        if ($block->type == 'item') {
            if (!isset($portfolioitems[$block->itemid])) {
                // Could be shared sometime (because found in block_exaportviewblock with viewid).
                if (!$potentialitem = $DB->get_record("block_exaportitem", array('id' => $block->itemid))) {
                    // Item not found.
                    continue;
                } else {
                    $items = block_exaport_get_portfolio_items(0, $block->itemid);
                    $portfolioitems[$block->itemid] = $items[$block->itemid];
                    $block->unshared = 1;
                }
            }
            if (!$block->width) {
                $block->width = 320;
            }
            if (!$block->height) {
                $block->height = 240;
            }
            $portfolioitems[$block->itemid]->intro = process_media_url($portfolioitems[$block->itemid]->intro,
                $block->width, $block->height);
            $block->item = $portfolioitems[$block->itemid];
        } else if ($block->type == 'badge') {
            // Find badge by id.
            $badge = null;
            foreach ($badges as $tmp) {
                if ($tmp->id == $block->itemid) {
                    $badge = $tmp;
                    break;
                }
            }
            if (!$badge) {
                // Badge not found.
                continue;
            }

            if (!$badge->courseid) {
                // For badges with courseid = NULL.
                $badge->imageUrl = (string)moodle_url::make_pluginfile_url(1, 'badges', 'badgeimage', $badge->id, '/', 'f1', false);
            } else {
                $context = context_course::instance($badge->courseid);
                $badge->imageUrl = (string)moodle_url::make_pluginfile_url($context->id,
                    'badges', 'badgeimage', $badge->id, '/', 'f1', false);
            }

            $block->badge = $badge;
        } else if ($block->type == 'cv_information') {
            // Nothing to do here
        } else {
            $block->print_text = file_rewrite_pluginfile_urls($block->text, 'draftfile.php',
                context_user::instance($USER->id)->id, 'user', 'draft', $view->draft_itemid);
            $block->itemid = null;
        }

        // Clean html texts for output.
        if (isset($block->print_text) && $block->print_text) {
            $block->print_text = format_text($block->print_text, FORMAT_HTML,
                array('filter' => false)); // TODO: $options['filter']=false - not very good solution.
        }
        if (isset($block->intro) && $block->intro) {
            $block->intro = format_text($block->intro, FORMAT_HTML);
        }

        $blocks[$block->id] = $block;
    }

    return $blocks;
}

/**
 * @param int $epopwhere
 * @param null $itemid
 * @param bool $withshareditems
 * @param bool $onlyexisting for performance: checking at least one item
 * @return array
 * @throws dml_exception
 */
function block_exaport_get_portfolio_items($epopwhere = 0, $itemid = null, $withshareditems = true, $onlyexisting = false) {
    global $DB, $USER;
    if ($epopwhere == 1) {
        $addwhere = " AND " . block_exaport_get_item_where();
    } else {
        $addwhere = "";
    };
    // Only needed item by id.
    if ($itemid) {
        if (is_array($itemid) && count($itemid) > 0) {
            $where = ' i.id IN (' . implode(',', $itemid) . ') ';
        } else {
            $where = ' i.id = ' . intval($itemid) . ' ';
        }
    } else {
        $where = " i.userid = ? " . $addwhere;
    }
    $query = "SELECT i.id, i.name, i.type, i.intro AS intro, i.url AS link, ic.name AS cname, ic.id AS catid, " .
        " ic2.name AS cname_parent, i.userid, COUNT(com.id) AS comments" .
        " FROM {block_exaportitem} i" .
        " LEFT JOIN {block_exaportcate} ic ON i.categoryid = ic.id" .
        " LEFT JOIN {block_exaportcate} ic2 ON ic.pid = ic2.id" .
        " LEFT JOIN {block_exaportitemcomm} com ON com.itemid = i.id" .
        " WHERE " . $where .
        " GROUP BY i.id, i.name, i.type, i.intro, i.url, ic.id, ic.name, ic2.name, i.userid" .
        " ORDER BY i.name";
    $portfolioitems = $DB->get_records_sql($query, array($USER->id));
    if (!$portfolioitems) {
        $portfolioitems = array();
    }
    if ($onlyexisting && count($portfolioitems) > 0) {
        return $portfolioitems;
    }

    // Add shared items.
    if ($withshareditems) {
        $shareditems = block_exaport_get_items_shared_to_user($USER->id, true, $itemid);
        $portfolioitems = $portfolioitems + $shareditems;
    }
    if ($onlyexisting && count($portfolioitems) > 0) {
        return $portfolioitems;
    }

    $fs = get_file_storage();

    foreach ($portfolioitems as $item) {
        if (null == $item->cname) {
            $item->category = format_string(block_exaport_get_root_category()->name);
            $item->catid = 0;
        } else if (null == $item->cname_parent) {
            $item->category = format_string($item->cname);
        } else {
            $catid = $item->catid;
            $item->category = "";
            while ($catid) {
                $cat = $DB->get_record("block_exaportcate", ["userid" => $item->userid, "id" => $catid]);

                if ($cat) {
                    if (!$item->category) {
                        $item->category = format_string($cat->name);
                    } else {
                        $item->category = format_string($cat->name) . " &rArr; " . $item->category;
                    }

                    $catid = $cat->pid;
                } else {
                    break;
                }
            }
        }

        if ($item->type == 'file') {
            // if not icon - store information about count of related files
            if (!block_exaport_get_single_file($item, 'item_iconfile')) {
                $files = block_exaport_get_item_files($item);
                $item->filescount = count($files);
            }
        }

        if ($item->intro) {
            $item->intro = file_rewrite_pluginfile_urls($item->intro, 'pluginfile.php', context_user::instance($item->userid)->id,
                'block_exaport', 'item_content', 'portfolio/id/' . $item->userid . '/itemid/' . $item->id);
            if (strpos($item->intro, '<iframe') !== false) {
                $item->intro = format_text($item->intro, FORMAT_HTML, ['noclean' => true]);
            } else {
                $item->intro = format_text($item->intro, FORMAT_HTML);
            }
        }

        // Get competences of the item.
        $item->userid = $USER->id;

        $comp = block_exaport_check_competence_interaction();
        if ($comp) {
            $compids = block_exaport_get_active_compids_for_item($item);

            if ($compids) {
                $competences = "";
                foreach ($compids as $compid) {
                    $conditions = array("id" => $compid);
                    $competencesdb = $DB->get_record(BLOCK_EXACOMP_DB_DESCRIPTORS, $conditions, $fields = '*',
                        $strictness = IGNORE_MISSING);

                    if ($competencesdb != null) {
                        $competences .= $competencesdb->title . '<br>';
                    }
                }
                $competences = str_replace("\r", "", $competences);
                $competences = str_replace("\n", "", $competences);
                $competences = str_replace("\"", "&quot;", $competences);
                $competences = str_replace("'", "&prime;", $competences);

                $item->competences = $competences;
            }
        }

        unset($item->userid);

        unset($item->cname);
        unset($item->cname_parent);
    }

    return $portfolioitems;
}

function block_exaport_get_shared_categories($categorycolumns, $usercats, $sqlsort) {
    global $DB, $USER;

    $categories = $DB->get_records_sql("
    SELECT
        {$categorycolumns}, u.firstname, u.lastname, u.picture,
        COUNT(DISTINCT cshar_total.userid) AS cnt_shared_users,
        COUNT(DISTINCT cgshar.groupid) AS cnt_shared_groups
    FROM {user} u
    JOIN {block_exaportcate} c ON (u.id = c.userid AND c.userid != ?)
    LEFT JOIN {block_exaportcatshar} cshar ON c.id=cshar.catid AND cshar.userid=?
    LEFT JOIN {block_exaportcatgroupshar} cgshar ON c.id = cgshar.catid
    LEFT JOIN {block_exaportcatshar} cshar_total ON c.id = cshar_total.catid
    WHERE (
        (" . (block_exaport_shareall_enabled() ? 'c.shareall=1 OR ' : '') . " cshar.userid IS NOT NULL) -- only shared all, if enabled
        -- Shared for you group
        " . ($usercats ? " OR c.id IN (" . join(',', array_keys($usercats)) . ") " : "") . "
        )
        AND internshare = 1
        AND u.deleted = 0
    GROUP BY
        {$categorycolumns}, u.firstname, u.lastname, u.picture
    $sqlsort", array($USER->id, $USER->id));

    return $categories;
}


/**
 * Function gets teachers array of course
 *
 * @return array
 */
function block_exaport_get_course_teachers($exceptmyself = true) {
    global $DB, $USER;

    $courseid = optional_param('courseid', 0, PARAM_INT);
    $context = context_course::instance($courseid);

    // Role id='3' - teachers. '4'- assistents.
    $query = "SELECT u.id as userid, u.id AS tmp
    FROM {user} u
    JOIN {role_assignments} ra ON ra.userid = u.id
    WHERE ra.contextid=? AND ra.roleid = '3' AND u.deleted = 0";
    $exastudteachers = $DB->get_records_sql($query, [$context->id]);

    // If exacomp is not installed this function returns an emtpy array.
    $exacompteachers = get_enrolled_users($context, 'block/exacomp:teacher');

    $teachers = $exastudteachers + $exacompteachers;

    if ($exceptmyself) {
        unset($teachers[$USER->id]);
    }

    $teachers = array_keys($teachers);

    return $teachers;
}

// This user is a teacher of any course?
function block_exaport_user_is_teacher($userid = null) {
    global $DB, $USER;
    if ($userid === null) {
        $userid = $USER->id;
    }
    // Role 3 = teacher
    $query = "SELECT DISTINCT u.id as userid, u.id AS tmp
      FROM {role_assignments} ra
      JOIN {user} u ON ra.userid = u.id
      JOIN {context} c ON c.id = ra.contextid
      WHERE c.contextlevel = ? AND u.id = ? AND ra.roleid = '3' AND u.deleted = 0 ";
    $roles = $DB->get_records_sql($query, [CONTEXT_COURSE, $userid]);
    if (count($roles) > 0) {
        return true;
    }
    return false;
}

// Check if user is a student (role 5 = student)
function block_exaport_user_is_student($userid = null) {
    global $DB, $USER;
    if ($userid === null) {
        $userid = $USER->id;
    }
    // Role 5 = student
    $query = "SELECT DISTINCT u.id as userid, u.id AS tmp
      FROM {role_assignments} ra
      JOIN {user} u ON ra.userid = u.id
      JOIN {context} c ON c.id = ra.contextid
      WHERE c.contextlevel = ? AND u.id = ? AND ra.roleid = '5' AND u.deleted = 0 ";
    $roles = $DB->get_records_sql($query, [CONTEXT_COURSE, $userid]);
    if (count($roles) > 0) {
        return true;
    }
    return false;
}

/**
 * Check if we are in the root category (categoryid = 0 or empty)
 * @param mixed $categoryid The category ID to check
 * @return bool True if we are in root category
 */
function block_exaport_is_root_category($categoryid) {
    return ($categoryid === 0 || $categoryid === '0' || empty($categoryid));
}

/**
 * Check if instructor can create items in the current category
 * Instructors can only create in "Evidencias" folders, not in root or other categories
 * @param mixed $categoryid The category ID to check
 * @return bool True if instructor can create items
 */
function block_exaport_instructor_can_create_in_category($categoryid) {
    error_log("DEBUG INSTRUCTOR: Checking permissions for categoryid='" . $categoryid . "' (type: " . gettype($categoryid) . ")");
    
    // Check if students can write in evidencias categories
    if (block_exaport_user_is_student()) {
        error_log("DEBUG INSTRUCTOR: User is student, checking evidencias write permissions");
        // Students can create in evidencias categories if they have write permissions
        if (is_numeric($categoryid) && $categoryid > 0) {
            if (block_exaport_student_can_write_in_evidencias($categoryid)) {
                error_log("DEBUG INSTRUCTOR: Student has write permissions in evidencias category");
                return true;
            }
        }
        error_log("DEBUG INSTRUCTOR: Student has no write permissions, denying");
        return false;
    }
    
    // Instructors can only create in evidencias folders
    if (strpos($categoryid, 'evidencias_') === 0) {
        error_log("DEBUG INSTRUCTOR: categoryid starts with 'evidencias_', allowing");
        return true;
    }
    
    error_log("DEBUG INSTRUCTOR: categoryid does not start with 'evidencias_', checking other conditions");
    
    // Check if this is a subcategory within an evidencias folder
    global $DB, $USER;
    if (is_numeric($categoryid) && $categoryid > 0) {
        // Get the category to check its path
        $category = $DB->get_record('block_exaportcate', array('id' => $categoryid, 'userid' => $USER->id));
        if ($category) {
            // Check if this category or any of its parents is within an evidencias folder
            return block_exaport_is_category_within_evidencias($category);
        }
    }
    
    // Also allow creating inside evidencias - check the URL parameter for parentid
    $parentid = optional_param('pid', 0, PARAM_RAW);
    if ($parentid && strpos($parentid, 'evidencias_') === 0) {
        error_log("DEBUG INSTRUCTOR: parentid from URL starts with 'evidencias_', allowing");
        return true;
    }
    
    // Check if parentid is a numeric category within evidencias
    if (is_numeric($parentid) && $parentid > 0) {
        $parent_category = $DB->get_record('block_exaportcate', array('id' => $parentid, 'userid' => $USER->id));
        if ($parent_category) {
            return block_exaport_is_category_within_evidencias($parent_category);
        }
    }
    
    // Instructors cannot create in root or course folders
    error_log("DEBUG INSTRUCTOR: No conditions met, denying");
    return false;
}

/**
 * Check if a category is within an evidencias folder hierarchy
 * @param object $category The category object to check
 * @return bool True if the category is within an evidencias folder
 */
function block_exaport_is_category_within_evidencias($category) {
    global $DB;
    
    if (!$category) {
        return false;
    }
    
    // Check if this category has a special marker indicating it's from evidencias
    // We'll use a naming convention or special field to identify evidencias subcategories
    // For now, let's check if the category name contains evidencias or if it was created in an evidencias context
    
    // Traverse up the category hierarchy to find if any parent is an evidencias folder
    $current_category = $category;
    $max_depth = 10; // Prevent infinite loops
    $depth = 0;
    
    while ($current_category && $depth < $max_depth) {
        // Check if current category name suggests it's from evidencias
        if (stripos($current_category->name, 'evidencias') !== false) {
            return true;
        }
        
        // Check if this category has a special source field indicating it's from evidencias
        // Categories created in evidencias have source = courseid (not 999)
        if (isset($current_category->source) && $current_category->source > 0 && is_numeric($current_category->source)) {
            return true;
        }
        
        // Move to parent category
        if ($current_category->pid && $current_category->pid > 0) {
            $current_category = $DB->get_record('block_exaportcate', 
                array('id' => $current_category->pid, 'userid' => $current_category->userid));
        } else {
            break;
        }
        
        $depth++;
    }
    
    return false;
}

/**
 * Check if instructors have full permissions for a given action and context
 * Instructors have full permissions when working within evidencias folders
 * @param string $action The action to check (add, edit, delete, etc.)
 * @param mixed $context_id The context ID (could be pid for creation, category id for edit/delete)
 * @return bool True if instructor has permission
 */
function block_exaport_instructor_has_permission($action, $context_id = null) {
    global $DB, $USER;
    
    // Students have special permissions for writing in evidencias
    if (block_exaport_user_is_student()) {
        // Students can add items to evidencias categories with write permissions
        if (in_array($action, ['add', 'addstdcat']) && $context_id) {
            // Check if trying to create in an evidencias category with write permissions
            if (is_numeric($context_id)) {
                return block_exaport_student_can_write_in_evidencias($context_id);
            }
        }
        // Students cannot edit or delete categories, only create items
        return false;
    }
    
    // For creation actions, check if the parent is evidencias
    if (in_array($action, ['add', 'addstdcat'])) {
        $pid = $context_id ? $context_id : optional_param('pid', 0, PARAM_RAW);
        return strpos($pid, 'evidencias_') === 0;
    }
    
    // For edit/delete actions, check if the category is within evidencias
    if (in_array($action, ['edit', 'delete']) && $context_id) {
        // Special case: if context_id is not numeric, it's a virtual folder (course_XX, evidencias_XX, etc.)
        // These cannot be edited or deleted
        if (!is_numeric($context_id)) {
            error_log("PERMISSION INFO: Cannot edit/delete virtual folder: $context_id");
            return false;
        }
        
        $category = $DB->get_record('block_exaportcate', array('id' => $context_id, 'userid' => $USER->id));
        if (!$category) {
            // Category doesn't exist or doesn't belong to user
            error_log("PERMISSION ERROR: Category ID $context_id not found or doesn't belong to user {$USER->id}");
            return false;
        }
        
        // Check if this category was created in evidencias (has source > 0)
        if (isset($category->source) && $category->source > 0 && is_numeric($category->source)) {
            return true;
        }
        
        // Check if it's a subcategory within evidencias
        return block_exaport_is_category_within_evidencias($category);
    }
    
    // Default: no permission
    return false;
}

/**
 * Check if a student has write permissions in an evidencias category
 * Students can upload/create items in evidencias categories that have write permissions enabled
 * @param int $categoryid The category ID to check
 * @param int $userid The user ID to check (optional, uses current user if not provided)
 * @return bool True if student has write permission in this evidencias category
 */
function block_exaport_student_can_write_in_evidencias($categoryid, $userid = null) {
    global $DB, $USER;
    
    if (!$userid) {
        $userid = $USER->id;
    }
    
    // Only students need this check
    if (!block_exaport_user_is_student()) {
        return false;
    }
    
    // Get the category
    $category = $DB->get_record('block_exaportcate', array('id' => $categoryid));
    if (!$category) {
        return false;
    }
    
    // Check if this is an evidencias category with write permissions enabled
    // internshare = 2 means "student write permissions in evidencias"
    if (isset($category->source) && $category->source > 0 && $category->internshare == 2) {
        // Check if student is enrolled in the course
        $courseid = $category->source;
        $context = context_course::instance($courseid);
        return is_enrolled($context, $userid, 'mod/assign:submit');
    }
    
    return false;
}

function block_exaport_get_students_for_teacher($userid = null, $courseid = 0) {
    global $DB, $USER;
    if ($userid === null) {
        $userid = $USER->id;
    }
    $students = array();
    // Across all enrolled cources
    $query = "SELECT c.id as contextid, c.instanceid as courseid, course.fullname AS coursetitle, u.id as userid
      FROM {role_assignments} ra
      JOIN {user} u ON ra.userid = u.id
      JOIN {context} c ON c.id = ra.contextid
      JOIN {course} course ON course.id = c.instanceid
      WHERE c.contextlevel = ? AND u.id = ? AND ra.roleid = '3' AND u.deleted = 0 ";
    $courses = $DB->get_records_sql($query, [CONTEXT_COURSE, $userid]);
    foreach ($courses as $course) {
        // Get students of current course
        $querystudents = "SELECT u.*
                  FROM {role_assignments} ra
                  JOIN {user} u ON ra.userid = u.id
                  JOIN {context} c ON c.id = ra.contextid
                  WHERE c.contextlevel = ? AND c.instanceid = ? AND ra.roleid = '5' AND u.deleted = 0 ";
        $users = $DB->get_records_sql($querystudents, [CONTEXT_COURSE, $course->courseid]);
        foreach ($users as $user) {
            if (!array_key_exists($user->id, $students)) {
                $user->name = fullname($user);
                $students[$user->id] = $user;
            }
            if (!isset($students[$user->id]->courses)) {
                $students[$user->id]->courses = array();
                $students[$user->id]->courseids = array();
            }
            $students[$user->id]->courses[] = $course->coursetitle;
            $students[$user->id]->courseids[] = $course->courseid;
            // Check needed course
            if ($courseid > 0) {
                // delete student if his cources are not needed
                if (!in_array($courseid, $students[$user->id]->courseids)) {
                    unset($students[$user->id]);
                }
            }
        }
    }
    return $students;
}

/**
 * Function gets all shared users
 *
 * @param $viewid
 * @return array
 */
function block_exaport_get_shared_users($viewid) {
    global $DB, $USER;
    $sharedusers = array();
    if ($viewid > 0) {
        $query = "SELECT userid FROM {block_exaportviewshar} s WHERE s.viewid=" . $viewid;
        $users = $DB->get_records_sql($query);
        foreach ($users as $user) {
            $sharedusers[] = $user->userid;
        };
    };
    sort($sharedusers);

    return $sharedusers;
}

;

function block_exaport_file_userquotecheck($addingfiles = 0, $id = 0) {
    global $DB, $USER, $CFG;
    $result = $DB->get_record_sql("SELECT SUM(filesize) as allfilesize " .
        " FROM {files} WHERE contextid = ? and component='block_exaport'",
        array(context_user::instance($USER->id)->id));
    if ($result->allfilesize + $addingfiles > $CFG->block_exaport_userquota) {
        $courseid = optional_param('courseid', 0, PARAM_INT);
        $categoryid = optional_param('categoryid', 0, PARAM_INT);
        $type = optional_param('type', 0, PARAM_RAW);
        print_error('userquotalimit', '', new moodle_url('/blocks/exaport/item.php',
            array('sesskey' => sesskey(),
                'courseid' => $courseid,
                'action' => 'edit',
                'type' => $type,
                'id' => $id,
                'categoryid' => $categoryid)), null);
    }

    return true;
}

function block_exaport_get_filessize_by_draftid($draftid = 0) {
    global $DB, $USER, $CFG;
    $result = $DB->get_record_sql("SELECT SUM(filesize) AS allfilesize FROM {files} " .
        " WHERE contextid = ? AND component = 'user' AND filearea='draft' AND itemid = ?",
        array(context_user::instance($USER->id)->id, $draftid));
    if ($result) {
        return $result->allfilesize;
    } else {
        return 0;
    }
}

function block_exaport_get_maxfilesize_by_draftid_check($draftid = 0) {
    global $DB, $USER, $CFG;
    $result = $DB->get_record_sql("SELECT MAX(filesize) AS maxfilesize FROM {files} " .
        " WHERE contextid = ? AND component = 'user' AND filearea='draft' AND itemid = ?",
        array(context_user::instance($USER->id)->id, $draftid));
    if (($CFG->block_exaport_max_uploadfile_size > 0) && ($result->maxfilesize > $CFG->block_exaport_max_uploadfile_size)) {
        print_error('maxbytes', 'exaport', 'blocks/exaport/view_items.php', null);
    }

    return true;
}

function block_exaport_is_valid_media_by_filename($filename) {
    global $DB, $USER, $CFG;
    $pathparts = pathinfo($filename);
    switch ($pathparts['extension']) {
        case 'avi':
        case 'mp4':
        case 'flv':
        case 'swf':
        case 'mpg':
        case 'mpeg':
        case '3gp':
        case 'webm':
        case 'ogg':
            return true;
        default:
            return false;
    }
}

function block_exaport_item_is_editable($itemid) {
    global $CFG, $DB, $USER;

    if ($CFG->block_exaport_app_alloweditdelete) {
        return true;
    }

    if (block_exaport_check_competence_interaction() && !block_exaport_item_is_resubmitable($itemid)) {
        return false;
    }

    if (block_exaport_check_competence_interaction()) {
        $itemexample = $DB->get_record(BLOCK_EXACOMP_DB_ITEM_MM, array(
            "itemid" => $itemid,
        ));

        // Check item grading and teacher comment.
        if ($itemexample) {
            if ($itemexample->teachervalue) {
                // Lehrerbewertung da.
                return false;
            } else {
                $itemcomments = $DB->get_records('block_exaportitemcomm', array(
                    'itemid' => $itemid,
                ), 'timemodified ASC', 'entry, userid', 0, 2);
                foreach ($itemcomments as $itemcomment) {
                    if ($USER->id != $itemcomment->userid) {
                        // Somebody commented on this item -> must be teacher.
                        return false;
                    }
                }
            }
        }
    }

    return true;
}

/**
 * checks if exacomp is installed and the item can be resubmitted there
 *
 * @param $itemid
 * @return bool
 */
function block_exaport_item_is_resubmitable($itemid) {
    global $CFG, $DB, $USER, $COURSE;

    if ($CFG->block_exaport_app_alloweditdelete) {
        return true;
    }

    if (!block_exaport_check_competence_interaction()) {
        return false;
    }

    if ($itemexample = $DB->get_record(BLOCK_EXACOMP_DB_ITEM_MM, array("itemid" => $itemid))) {
        $item = $DB->get_record('block_exaportitem', array('id' => $itemid));
        if ($eval = $DB->get_record(BLOCK_EXACOMP_DB_EXAMPLEEVAL,
            array('exampleid' => $itemexample->exampleid, 'studentid' => $USER->id, 'courseid' => $item->courseid))
        ) {
            if (!$eval->resubmission) {
                return false;
            }
        }
    }
    return true;
}

function block_exaport_example_is_submitable($exampleid) {
    global $DB, $USER, $COURSE;

    if ($eval = $DB->get_record(BLOCK_EXACOMP_DB_EXAMPLEEVAL,
        array('exampleid' => $exampleid, 'studentid' => $USER->id, 'courseid' => $COURSE->id))
    ) {
        return $eval->resubmission;
    }
    return true;

}

function block_exaport_has_grading_permission($itemid) {
    global $DB;

    if (!block_exaport_check_competence_interaction()) {
        return false;
    }

    // Check if item is a submission for an exacomp example.
    $itemexample = $DB->get_record(BLOCK_EXACOMP_DB_ITEM_MM, array("itemid" => $itemid));
    if (!$itemexample) {
        return false;
    }

    $item = $DB->get_record('block_exaportitem', array('id' => $itemid));
    if (!$item || !$item->courseid) {
        return false;
    }

    $coursecontext = context_course::instance($item->courseid);

    return has_capability('block/exacomp:teacher', $coursecontext);
}

function block_exaport_get_item_tags($itemid, $orderby = '') {
    global $DB, $CFG;
    $tags = array();
    $result = array();
    if (is_array($itemid)) {
        // Tags for a few items.
        if (count($itemid) > 0) {
            list($whereitems, $paramitems) = $DB->get_in_or_equal($itemid, SQL_PARAMS_NAMED);
            $result = $DB->get_records_sql('SELECT DISTINCT rawname ' .
                ' FROM {tag_instance} ti LEFT JOIN {tag} t ON t.id=ti.tagid ' .
                ' WHERE component=\'block_exaport\' AND itemtype=\'block_exaportitem\' AND itemid ' . $whereitems .
                ' ' . ($orderby != '' ? ' ORDER BY ' . $orderby : ''),
                $paramitems);
        }
    } else {
        // Tags for one item.
        $result = $DB->get_records_sql('SELECT * ' .
            ' FROM {tag_instance} ti LEFT JOIN {tag} t ON t.id=ti.tagid ' .
            ' WHERE component=\'block_exaport\' AND itemtype=\'block_exaportitem\' AND itemid = ? ' .
            ($orderby != '' ? ' ORDER BY ' . $orderby : ''),
            array($itemid));
    }
    foreach ($result as &$tag) {
        $tags[] = $tag->rawname;
    }

    return $tags;
}

/**
 * Returns artefacts tagged with a specified tag.
 *
 * This is a callback used by the tag area block_exaport/block_exaportitem to search for artefacts
 * tagged with a specific tag.
 *
 * @param core_tag_tag $tag
 * @param bool $exclusivemode if set to true it means that no other entities tagged with this tag
 *             are displayed on the page and the per-page limit may be bigger
 * @param int $fromctx context id where the link was displayed, may be used by callbacks
 *            to display items in the same context first
 * @param int $ctx context id where to search for records
 * @param bool $rec search in subcontexts as well
 * @param int $page 0-based number of page being displayed
 * @return \core_tag\output\tagindex
 */
function block_exaport_get_tagged_items($tag, $exclusivemode = false, $fromctx = 0, $ctx = 0, $rec = 1, $page = 0) {
    global /*$OUTPUT, */
    $USER;
    $OUTPUT = block_exaport_get_renderer();
    $perpage = $exclusivemode ? 20 : 5;

    // Build the SQL query.
    /* $ctxselect = context_helper::get_preload_record_columns_sql('ctx'); */
    $query = "SELECT i.id, i.name, i.type, i.userid, cat.name AS categoryname, i.categoryid, i.courseid
                FROM {block_exaportitem} i
                LEFT JOIN {block_exaportcate} cat ON i.categoryid = cat.id
                JOIN {tag_instance} tt ON i.id = tt.itemid
                WHERE tt.itemtype = :itemtype
                    AND tt.tagid = :tagid
                    AND tt.component = :component
                    AND i.id %ITEMFILTER%";

    $params = array('itemtype' => 'block_exaportitem', 'tagid' => $tag->id, 'component' => 'block_exaport');

    $totalpages = $page + 1;

    // Use core_tag_index_builder to build and filter the list of items.
    $builder = new core_tag_index_builder('block_exaport', 'block_exaportitem', $query, $params, $page * $perpage, $perpage + 1);
    $shareditems = block_exaport_get_items_shared_to_user($USER->id, true, null);
    if ($shareditems && is_array($shareditems)) {
        $shareditemuids = array_keys($shareditems);
    } else {
        $shareditemuids = [];
    }

    // access rules
    while ($item = $builder->has_item_that_needs_access_check()) {
        context_helper::preload_from_record($item);
        $courseid = $item->courseid;
        if (!$builder->can_access_course($courseid)) {
            $builder->set_accessible($item, false);
            continue;
        }
        $modinfo = get_fast_modinfo($builder->get_course($courseid));
        // Set accessibility of this item and all other items in the same course.
        $builder->walk(function($taggeditem) use ($courseid, $modinfo, $builder, $item, $shareditemuids, $USER) {
            //if ($taggeditem->courseid == $courseid) {
            $accessible = false;
            // TODO: check rules: courseid?,...
            if ($taggeditem->userid == $USER->id // owner of artifact
                || in_array($taggeditem->id, $shareditemuids) // shared items
            ) {
                $accessible = true;
            }
            $builder->set_accessible($taggeditem, $accessible);
            // }
        });
    }
    $items = $builder->get_items();
    if (count($items) > $perpage) {
        $totalpages = $page + 2; // We don't need exact page count, just indicate that the next page exists.
        array_pop($items);
    }

    // Build the display contents.
    if ($items) {
        $content = '';
        $tagfeed = new core_tag\output\tagfeed();
        foreach ($items as $item) {
            // only accessible items
            // TODO: check rules: courseid?,...
            if ($item->userid != $USER->id // owner of artifact
                && !in_array($item->id, $shareditemuids) // shared items
            ) {
                continue;
            }

            $itemurl = new moodle_url('/blocks/exaport/shared_item.php',
                array('itemid' => $item->id, 'access' => 'portfolio/id/' . $item->userid));
            $itemname = format_string($item->name, true);
            $itemname = html_writer::link($itemurl, $itemname);
            $categoryname = $item->categoryname;
            $iconsrc = new moodle_url('/blocks/exaport/item_thumb.php', array('item_id' => $item->id));
            $icon = html_writer::link($itemurl, html_writer::empty_tag('img', array('src' => $iconsrc, 'style' => 'height: 100%;')));
            $tagfeed->add($icon, $itemname, $categoryname);
        }

        $content .= $OUTPUT->render_from_template('core_tag/tagfeed',
            $tagfeed->export_for_template($OUTPUT));

        return new core_tag\output\tagindex($tag, 'block_exaport', 'block_exaportitem', $content,
            $exclusivemode, $fromctx, $ctx, $rec, $page, $totalpages);
    }
}

function block_exaport_securephrase_viewemail(&$view, $email) {
    // Secure phrase relates to the email.
    return substr(sha1(rand() . $view->id . $email . time() . rand()), rand(0, 7), 32);
}

function block_exaport_mix_images($sourceimages = array()) {
    global $PAGE, $CFG;
    $width = 90;
    $height = 90;
    $tempimages = array();
    // $fs = get_file_storage();
    $PAGE->set_context(context_system::instance());
    $output = block_exaport_get_renderer();
    // $themedir = $output->get_theme_dir();
    $pixdir = $CFG->dirroot . '/pix/';

    $circlepointcoordinates = function($countallpoints, $currentpoint, $iconwidth, $iconheight) use ($width, $height) {
        $radius = ceil(min($width, $height) / 4);
        $alpha = 360 / $countallpoints;
        $angle = $alpha * $currentpoint;
        $x = ceil($radius * cos(deg2rad($angle)));
        $y = ceil($radius * sin(deg2rad($angle)));
        // with using of image axis
        $x = $x + ceil($width / 2);
        $y = $y + ceil($height / 2);
        // correction by icon sizes
        $x = $x - ceil($iconwidth / 2);
        $y = $y - ceil($iconheight / 2);
        return array($x, $y);
    };

    foreach ($sourceimages as $image) {
        if (!$image) {
            continue;
        }
        // $imagefile = $image->getFile
        if ($image->is_valid_image()) {
            // $tempfile = $fs->create_file_from_pathname($image, $image->filepath);
            $tempimgcontent = $image->get_content();
        } else {
            // $tempfile = $output->image_url(file_file_icon($image, 90));
            $tempfile = $pixdir . file_file_icon($image, $width) . '.png';
            $tempimgcontent = file_get_contents($tempfile);
        }
        if ($t = imagecreatefromstring($tempimgcontent)) {
            $tempimages[] = $t;
        }
    }
    if (count($tempimages) > 0) {
        $resimage = imagecreatetruecolor($width, $height);
        // imagesavealpha($resimage, true);
        // $trans_background = imagecolorallocatealpha($resimage, 0, 0, 0, 127);
        $black = imagecolorallocate($resimage, 0, 0, 0);
        imagefill($resimage, 0, 0, $black);
        imagecolortransparent($resimage, imagecolorallocate($resimage, 0, 0, 0));
        /** @var resource tmpimg */
        $copy = 0;
        // subicon sizes
        $imagecount = count($tempimages);
        switch ($imagecount) {
            case 2:
                $proportion = 60;
                break;
            case 3:
                $proportion = 50;
                break;
            default:
                $proportion = 40;
                break;
        }
        // $proportion = 40;
        $newwidth = ceil($width * $proportion / 100);
        $newheight = ceil($height * $proportion / 100);
        // round mask
        $mask = imagecreatetruecolor($newwidth, $newheight);
        $black = imagecolorallocate($mask, 0, 0, 0);
        $magenta = imagecolorallocate($mask, 255, 0, 255);
        $white = imagecolorallocate($mask, 255, 255, 255);
        $gray = imagecolorallocate($mask, 230, 230, 230);
        imagefill($mask, 0, 0, $magenta);
        $r = min($newwidth, $newheight);
        // with the border
        imagefilledellipse($mask, ($newwidth / 2), ($newheight / 2), $r, $r, $gray);
        imagefilledellipse($mask, ($newwidth / 2), ($newheight / 2), $r - 2, $r - 2, $white);
        imagefilledellipse($mask, ($newwidth / 2), ($newheight / 2), $r - 5, $r - 5, $black); // for white circle border
        imagecolortransparent($mask, $black);
        foreach ($tempimages as $tmpimg) {
            $sizex = imagesx($tmpimg);
            $sizey = imagesy($tmpimg);
            $centerx = ceil($sizex / 2);
            $centery = ceil($sizey / 2);
            $ratio = $sizex / $sizey; // width/height
            // wee need to have smallest side = newsize ($newwidth or $newheight)
            if ($sizex > $sizey) {
                $sizex = ceil($sizex - ($sizey * abs($ratio - $newwidth / $newheight)));
            } else {
                $sizey = ceil($sizey - ($sizey * abs($ratio - $newwidth / $newheight)));
            }

            $newtmpimg = imagecreatetruecolor($newwidth, $newheight);
            // crop icon to small size (from center)
            imagecopyresampled($newtmpimg, $tmpimg, 0, 0, ceil($centerx - ($sizex / 2)), ceil($centery - ($sizey / 2)), $newwidth, $newheight, $sizex, $sizey);
            imagedestroy($tmpimg);
            $tmpimg = $newtmpimg;
            imagecolortransparent($tmpimg, imagecolorallocate($tmpimg, 255, 0, 255));
            imagecopymerge($tmpimg, $mask, 0, 0, 0, 0, $newwidth, $newheight, 100);

            // position calculating (line)
            // $pos_x = ceil($copy * $newwidth / 1.75);
            // $pos_y = ceil($copy * $newwidth / 1.75);

            // insert via circle points
            list ($pos_x, $pos_y) = $circlepointcoordinates($imagecount, $copy, $newwidth, $newheight);
            imagecopy($resimage, $tmpimg, $pos_x, $pos_y, 0, 0, $newwidth, $newheight);
            $copy++;
        }
        // return result image
        header('Content-Type: image/png');
        imagepng($resimage);
        // free memory!
        imagedestroy($resimage);
        foreach ($tempimages as $tmpimg) {
            imagedestroy($tmpimg);
        }

    }
    return true;
}

/**
 * clean HTML code for next displaying. Must be modified more and more if need
 * @param string $content
 * @param string $format
 * @return string
 */
function block_exaport_html_secure($content = '', $format = FORMAT_HTML) {
    $content = format_text($content, $format, ['newlines' => false]);
    return $content;
}

require_once($CFG->libdir . '/formslib.php');

class BlockExaportMoodleQuickForm extends MoodleQuickForm {

    public function add_exaport_help_button($elementname, $identifier, $component = 'block_exaport', $linktext = '', $suppresscheck = false) {
        $sm = get_string_manager();
        if (!$sm->string_exists($identifier . '_help', 'block_exaport')) {
            $element = $this->_elements[$this->_elementIndex[$elementname]];
            $element->_helpbutton = '<span class="exaportHelpButtonMarker" style="display: none;">' . $identifier . '_help ==and== ' . $identifier . '</span>';
        } else {
            return parent::addHelpButton($elementname, $identifier, $component, $linktext, $suppresscheck);
        }
    }

}


abstract class block_exaport_moodleform extends moodleform {

    // copy of original __constract, but changed _form class (merged from Moodle 3.2 and 3.9)
    public function __construct($action = null, $customdata = null, $method = 'post', $target = '', $attributes = null, $editable = true,
        $ajaxformdata = null) {
        global $CFG, $FULLME;
        // no standard mform in moodle should allow autocomplete with the exception of user signup
        if (empty($attributes)) {
            $attributes = array('autocomplete' => 'off');
        } else if (is_array($attributes)) {
            $attributes['autocomplete'] = 'off';
        } else {
            if (strpos($attributes, 'autocomplete') === false) {
                $attributes .= ' autocomplete="off" ';
            }
        }

        if (empty($action)) {
            // do not rely on PAGE->url here because dev often do not setup $actualurl properly in admin_externalpage_setup()
            $action = strip_querystring($FULLME);
            if (!empty($CFG->sslproxy)) {
                // return only https links when using SSL proxy
                $action = preg_replace('/^http:/', 'https:', $action, 1);
            }
            // TODO: use following instead of FULLME - see MDL-33015
            // $action = strip_querystring(qualified_me());
        }
        // Assign custom data first, so that get_form_identifier can use it.
        $this->_customdata = $customdata;
        $this->_formname = $this->get_form_identifier();
        $this->_ajaxformdata = $ajaxformdata;
        // CUSTOM MoodleQuickForm
        $this->_form = new BlockExaportMoodleQuickForm($this->_formname, $method, $action, $target, $attributes, $ajaxformdata);
        // $this->_form = new MoodleQuickForm($this->_formname, $method, $action, $target, $attributes, $ajaxformdata);
        if (!$editable) {
            $this->_form->hardFreeze();
        }

        $this->definition();

        $this->_form->addElement('hidden', 'sesskey', null); // automatic sesskey protection
        $this->_form->setType('sesskey', PARAM_RAW);
        $this->_form->setDefault('sesskey', sesskey());
        $this->_form->addElement('hidden', '_qf__' . $this->_formname, null);   // form submission marker
        $this->_form->setType('_qf__' . $this->_formname, PARAM_RAW);
        $this->_form->setDefault('_qf__' . $this->_formname, 1);
        $this->_form->_setDefaultRuleMessages();

        // Hook to inject logic after the definition was provided.
        if (method_exists($this, 'after_definition')) {
            $this->after_definition();
        }

        // we have to know all input types before processing submission ;-)
        $this->_process_submission($method);
    }

}

function block_exaport_get_all_categories_for_user($userid) {
    global $DB;
    $categorycolumns = g::$DB->get_column_names_prefixed('block_exaportcate', 'c');
    
    // Include evidencias categories if the user is a student enrolled in the course
    $evidencias_condition = '';
    if (block_exaport_user_is_student()) {
        // For students, also include evidencias categories from courses they're enrolled in
        $evidencias_condition = ' OR (c.source > 0 AND c.source IN (
            SELECT e.courseid 
            FROM {enrol} en 
            JOIN {user_enrolments} ue ON ue.enrolid = en.id 
            WHERE ue.userid = ? AND en.status = 0 AND ue.status = 0
        ))';
    }
    
    $categories = $DB->get_records_sql("
        SELECT
            {$categorycolumns}
            , COUNT(i.id) AS item_cnt
        FROM {block_exaportcate} c
        LEFT JOIN {block_exaportitem} i ON i.categoryid=c.id AND " . block_exaport_get_item_where() . "
        WHERE c.userid = ? AND ((c.source IS NULL OR c.source = 0) {$evidencias_condition})
        GROUP BY
            {$categorycolumns}
        ORDER BY c.name ASC
    ", block_exaport_user_is_student() ? array($userid, $userid) : array($userid));
    return $categories;
}

function block_exaport_get_all_categories_for_user_simpletree_selectbox($userid, $selectName = '', $selectId = '') {
    global $DB;
    $content = '<select ' . ($selectId ? 'id="' . $selectId . '" ' : '') . ($selectName ? 'name="' . $selectName . '" ' : '') . '>';
    $content .= '<option value="0">Root</option>';
    // get ALL categories. Simple list
    $cats = $DB->get_records_sql('
        SELECT id, pid, name
        FROM {block_exaportcate} c
        WHERE c.userid = ?
        ORDER BY c.name ASC
    ', array($userid));
    // convert the list into the simple "tree" list. Starts from level 0
    function buildTree(array &$categories, $parentId = 0, $level = 1) {
        $treeString = '';
        foreach ($categories as $key => $category) {
            if ($category->pid == $parentId) {
                $treeString .= '<option value="' . $category->id . '">' . str_repeat("&nbsp;", $level * 3) . htmlspecialchars($category->name) . "</option>";
                $treeString .= buildTree($categories, $category->id, $level + 1);
            }
        }
        return $treeString;
    }

    $treeViewOptions = buildTree($cats);
    $content .= $treeViewOptions;
    $content .= '</select>';

    return $content;
}


/**
 * convert categories and artifacts into tree
 * @param integer $userid
 * @param bool $with_artifacts
 */
function block_exaport_user_categories_into_tree($userid, $with_artifacts = false, $for_privacy = false) {
    global $DB;
    /*$return_tree = array();
    if ($cat_tree === null) {
      //$cat_tree = array_merge(['0' => block_exaport_get_root_category()], block_exaport_get_all_categories_for_user($userid)); // start with ALL categories
        $cat_tree = block_exaport_get_all_categories_for_user($userid); // start with ALL categories
        $return_tree = block_exaport_get_root_category();
        $return_tree->subcategories = block_exaport_user_categories_into_tree($userid, $cat_tree, 0);
         //file_put_contents('D://222.222', print_r($cat_tree, true));
    } else {
        # Traverse the tree and search for direct children of the root
        foreach ($cat_tree as $cat_id => $category) {
           $newCategoryEntry = clone $category;
        // $cat_id = $category->id;
            if ($category->pid == $rootId) {
                unset($cat_tree[$cat_id]); // we do not need it in next iterations
                $newCategoryEntry->subcategories = block_exaport_user_categories_into_tree($userid, $cat_tree, $category->pid);
                $return_tree[$cat_id] = $newCategoryEntry;
            }

        }
    } */
    // without recursions
    $references = [];
    $return_tree = [];
    $cat_tree = ['0' => block_exaport_get_root_category()] + block_exaport_get_all_categories_for_user($userid); // start with ALL categories
    // TODO: here is cleaning of all parameters which is not saw for the user. Is this right?
    $clean_category_parameters = ['id', 'pid', 'userid', 'courseid', 'subjid', 'topicid', 'source', 'sourceid', 'parent_ids', 'parent_titles', 'stid',
        'sourcemod', 'name_short', 'item_cnt'];
    $clean_item_parameters = ['id', 'userid', 'categoryid', 'courseid', 'sortorder', 'beispiel_url', 'langid', 'beispiel_angabe', 'source', 'sourceid',
        'iseditable', 'example_url', 'parentid', 'exampid'];

    foreach ($cat_tree as $cat_id => &$category) {
        if (!array_key_exists($cat_id, $references)) {
            $references[$cat_id] =& $category;
        }
        $category->subcategories = [];
        if ($with_artifacts) {
            $items = block_exaport_get_items_by_category_and_user($userid, $cat_id);
            if ($for_privacy) {
                foreach ($items as &$item) {
                    $item->timemodified = transform::datetime(@$item->timemodified);
                    $comments = block_exaport_get_comments_for_item($item->id);
                    if ($comments && count($comments) > 0) {
                        foreach ($comments as &$comment) {
                            $comment->timemodified = transform::datetime(@$comment->timemodified);
                            $user_obj = $DB->get_record('user', ['id' => $comment->userid]);
                            $comment->fromUser = fullname($user_obj, $userid);
                            unset($comment->userid);
                            unset($comment->id);
                            unset($comment->itemid);
                        }
                        $item->comments = $comments;
                    }
                    if (block_exaport_check_competence_interaction()) {
                        $comps = block_exaport_get_active_comps_for_item($item);
                        if ($comps && is_array($comps) && array_key_exists('descriptors', $comps)) {
                            $competencies = $comps['descriptors'];
                        } else {
                            $competencies = null;
                        }

                        if ($competencies) {
                            $competenciesoutput = "";
                            foreach ($competencies as $competence) {
                                $competenciesoutput .= $competence->title . '<br>';
                            }
                            $item->competences = $competenciesoutput;
                        }
                    }
                    foreach ($clean_item_parameters as $param) {
                        if (property_exists($item, $param)) {
                            unset($item->{$param});
                        }
                    }
                }
            }
            $category->items = $items;
        }
        if ($cat_id == 0) { // only single Root
            unset($category->pid);
            unset($category->url);
            unset($category->item_cnt);
            $return_tree[0] =& $category;
        } else {
            $references[$category->pid]->subcategories[$cat_id] =& $category;
            if ($for_privacy) {
                // clean properties for readable data in privacy report
                $category->timemodified = transform::datetime(@$category->timemodified);
                foreach ($clean_category_parameters as $param) {
                    if (property_exists($category, $param)) {
                        unset($category->{$param});
                    }
                }
            }
        }

    }

    return $return_tree;
}


function block_exaport_get_items_by_category_and_user($userid, $categoryid, $sort = '', $withShared = false) {
    global $DB;
    $where = ' i.categoryid = ? ';
    if ($withShared) {
        if ($categoryid > 0) {
            // add items from other users if the category is shared
        } else {
            // only own
            $where .= ' AND i.userid = ? ';
        }
    } else {
        $where .= ' AND i.userid = ? ';
    }
    $where .= " AND " . block_exaport_get_item_where() . " ";
    $items = $DB->get_records_sql("
            SELECT DISTINCT i.*, COUNT(com.id) As comments
            FROM {block_exaportitem} i
            LEFT JOIN {block_exaportitemcomm} com on com.itemid = i.id
            WHERE $where
            GROUP BY i.id, i.userid, i.type, i.categoryid, i.name, i.url, i.intro,
                i.attachment, i.timemodified, i.courseid, i.shareall, i.externaccess,
                i.externcomment, i.sortorder, i.isoez, i.fileurl, i.beispiel_url,
                i.exampid, i.langid, i.beispiel_angabe, i.source, i.sourceid,
                i.iseditable, i.example_url, i.parentid
            $sort
        ", [$categoryid, $userid]);

    return $items;
}

function block_exaport_get_comments_for_item($itemid) {
    global $DB;
    $comments = $DB->get_records("block_exaportitemcomm", ['itemid' => $itemid], 'timemodified DESC');
    return $comments;
}

function block_exaport_get_view_layout_style_from_settings($view, $styleFor = 'shared_view') {
    global $CFG;

    // Get layout options from exaport settings
    $layoutSettings = @$CFG->block_exaport_layout_settings;
    if ($layoutSettings) {
        $layoutSettings = unserialize($layoutSettings);
    } else {
        $layoutSettings = [];
    }

    if (@$CFG->block_exaport_allow_custom_layout && $view->layout_settings) {
        $viewLayoutSettings = unserialize($view->layout_settings);
        foreach ($viewLayoutSettings as $settingName => $value) {
            // we need this foreach because '-1' is for default (so - from exaport settings) value
            switch ($settingName) {
                case 'customCss':
                    if (trim($value)) {
                        // ADD custom CSS to existing
                        $layoutSettings[$settingName] .= $value;
                    }
                    break;
                default:
                    if ($value != -1) {
                        $layoutSettings[$settingName] = $value;
                    }
            }
        }
    }

    $layoutSettings = array_filter($layoutSettings);
    if (!$layoutSettings) {
        return '';
    }
    $style = '<style>';
    $style .= '/* Custom view styles */';
    switch ($styleFor) {
        case 'edit_form': // do we need it?
            /*if (@$layoutSettings['header_fontSize'] != -1) {
                $style .= '
                    #exaport .item .header > .body,
                    #exaport .item .header > .headerText
                                        {font-size: '.$layoutSettings['header_fontSize'].'rem;}
                    ';
            }
            if (@$layoutSettings['text_fontSize'] != -1) {
                $style .= '#exaport .item :not(.header) .body {font-size: '.$layoutSettings['text_fontSize'].'rem;}';
            }*/
            break;
        case 'shared_view':
            if (@$layoutSettings['header_fontSize'] != -1) {
                $style .= '
                    #exaport #view .header {
                        font-size: ' . $layoutSettings['header_fontSize'] . 'rem;
                    }';
                $style .= "\r\n";
            }
            if (@$layoutSettings['headerBold']) {
                $style .= '
                    #exaport #view .header {
                        font-weight: bold;
                    }
                    ';
                $style .= "\r\n";
            }
            if (@$layoutSettings['text_fontSize'] != -1) {
                $style .= '#exaport #view .view-personal-information,
                            #exaport #view .view-text,
                            #exaport #view .view-item,
                            #exaport #view .view-cv-information
                    {
                        font-size: ' . $layoutSettings['text_fontSize'] . 'rem;
                    }';
                $style .= "\r\n";
            }
            if (@$layoutSettings['header_borderWidth'] != -1) {
                if (!$layoutSettings['header_borderWidth']) { // zero
                    $styleVal = 'border-bottom: none !important;';
                } else {
                    $styleVal = 'border-bottom: solid ' . $layoutSettings['header_borderWidth'] . 'px #dddddd !important;';
                }
                $style .= '#exaport #view .header { ' . $styleVal . ' }';
                $style .= "\r\n";
            }
            if (@$layoutSettings['block_borderWidth'] != -1) {
                if (!@$layoutSettings['block_borderWidth']) { // zero
                    $styleVal = 'border: none !important;';
                } else {
                    $styleVal = 'border: solid ' . $layoutSettings['block_borderWidth'] . 'px #dddddd !important;';
                }
                $style .= '#exaport #view .view-personal-information,
                            #exaport #view .view-text,
                            #exaport #view .view-item,
                            #exaport #view .view-cv-information
                    {
                        ' . $styleVal . '
                    }';
                $style .= "\r\n";
            }
            if (@$layoutSettings['customCss']) {
                $style .= $layoutSettings['customCss'];
                $style .= "\r\n";
            }
            break;
    }
    $style .= '</style>';
    return $style;
}

function block_exaport_layout_fontsizes() {
    $fontSizes = [
        '-1' => 'default',
        '0.25' => '25%',
        '0.5' => '50%',
        '0.75' => '75%',
        '1' => '100%',
        '1.25' => '125%',
        '1.5' => '150%',
        '1.75' => '175%',
        '2.0' => '200%',
        '2.25' => '225%',
        '2.5' => '250%',
    ];
    return $fontSizes;
}

function block_exaport_layout_borderwidths() {
    $borderWidths = [
        '-1' => 'default',
        '0' => 'none',
        '1' => '1px',
        '2' => '2px',
        '3' => '3px',
        '4' => '4px',
        '5' => '5px',
    ];
    return $borderWidths;
}

/**
 * @param $content
 * @param string|object $accessOrView
 * @param $forAttributes
 * @return array|mixed|string|string[]|null
 */
function block_exaport_add_view_access_parameter_to_url($content, $accessOrView, $forAttributes = ['src']) {
    global $USER, $DB;
    if (!$accessOrView) {
        return $content;
    }
    if (is_object($accessOrView)) {
        $access = 'id/' . $USER->id . '-' . $accessOrView->id;
        $accessOrView = $access; // to check the access to the view again
    }
    // check access
    if (is_string($accessOrView)) {
        if (strpos($accessOrView, 'resume') !== false) {
            $resumeattrs = explode('/', $accessOrView);
            $resumeid = $resumeattrs[1];
            $resume = $DB->get_record('block_exaportresume', array("id" => $resumeid));
            if ($resume->user_id == $USER->id && $resume->user_id == $resumeattrs[2]) {
                $access = $accessOrView;
            }
        } else {
            $view = block_exaport_get_view_from_access($accessOrView);
            $access = $accessOrView;
            if (!$view) {
                return $content;
            }
        }
    }
    if (!$access) {
        return $content;
    }
    $addParams = [
        'access' => $access,
    ];
    $pattern = '/(' . implode('|', $forAttributes) . ')=["\']([^"\']+)["\']/';
    $content = preg_replace_callback($pattern, function($matches) use ($addParams) {
        $url = $matches[2];
        $parsedUrl = parse_url($url);
        $query = isset($parsedUrl['query']) ? $parsedUrl['query'] : '';
        parse_str($query, $urlParams);
        $urlParams = array_merge($addParams, $urlParams); // If 'access' parameter exists - keep it, not change
        $parsedUrl['query'] = http_build_query($urlParams);
        $newUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . $parsedUrl['path'] . '?' . $parsedUrl['query'];
        return $matches[1] . '="' . $newUrl . '"';
    }, $content);

    return $content;
}

/**
 * Right now we can use only FREE icons. So, use https://fontawesome.com/icons/ to choose what you need
 * @param string $icon fontAwesome class
 * @param string $iconStyle fontAwesome style: regular, solid, light, duotone, thin
 * @param integer $iconSize fontAwesome class to change icon size: 0-do not use fa-size; 1,2,3,4,...
 * @param array $addClasses add classes to the icon tag <i> (used for additional fa-configuration)
 * @param array $styles add styles to the icon tag <i>
 * @param array $iconAttributes add custom tag attribute to the icon tag <i> (used for additional fa-configuration)
 * @param string $action add action icon into the main icon. simple: add, up, ...
 * @param array $actionClasses add classes to the 'action' icon tag
 * @param array $actionStyles add styles to the 'action' icon tag
 * @param array $actionAttributes add custom tag attribute to the 'action' tag <i>
 * @param array $iconContainerClasses add classes to the icon container. useful to our custom CSS rules (:hover or other)
 * @return string
 */
function block_exaport_fontawesome_icon($icon, $iconStyle = 'regular', $iconSize = 2, $addClasses = [], $styles = [], $iconAttributes = [],
    $action = '', $actionClasses = [], $actionStyles = [], $actionAttributes = [], $iconContainerClasses = []) {
    $iconContent = '';
    $getStylesAttr = function($styles) {
        $addStyle = '';
        if ($styles) {
            $addStyle = 'style="';
            foreach ($styles as $prop => $val) {
                $addStyle .= $prop . ': ' . $val . '; ';
            }
            $addStyle .= '"';
        }
        return $addStyle;
    };
    $customAttributes = function($attrs) {
        $returnAttrs = '';
        if ($attrs) {
            foreach ($attrs as $attrName => $attrVal) {
                $returnAttrs .= ' ' . $attrName . ' = "' . $attrVal . '" ';
            }
        }
        return $returnAttrs;
    };

    // Icon container.
    $iconContent .= '<span class="exaport-icon fa-layers fa-fw ' . ($iconSize !== null ? 'fa-' . $iconSize . 'x ' : '') . implode(' ', $iconContainerClasses) . '">';
    // Icon
    $iconContent .= '<i class="fa-' . $iconStyle . ' fa-' . $icon . ' ' . implode(' ', $addClasses) . '" ' . $getStylesAttr($styles) . ' ' . $customAttributes($iconAttributes) . '></i>';
    // Action
    switch ($action) {
        case 'add': // "add" icon (+)
            $defaultAttrs = ['data-fa-transform' => 'shrink-7 down-4 right-4'];
            $actionAttributes = array_merge($defaultAttrs, $actionAttributes);
            // add white circle below plus
            $stylesCircle = ['color' => '#ffffff'];
            $iconContent .= '<i class="fa-solid fa-circle" ' . $customAttributes($actionAttributes) . ' ' . $getStylesAttr($stylesCircle) . '></i>';
            // add plus icon
            $defaultStyles = ['color' => '#ef990f'];
            $actionStyles = array_merge($defaultStyles, $actionStyles);
            $iconContent .= '<i class="fa-solid fa-circle-plus" ' . $customAttributes($actionAttributes) . ' ' . $getStylesAttr($actionStyles) . '></i>';
            break;
        case 'up': // "up" icon. useful for folder-up
            $defaultAttrs = ['data-fa-transform' => 'shrink-8 down-5 right-4'];
            $actionAttributes = array_merge($defaultAttrs, $actionAttributes);
            // add white circle below plus
            $stylesCircle = ['color' => '#ffffff'];
            $iconContent .= '<i class="fa-solid fa-circle" ' . $customAttributes($actionAttributes) . ' ' . $getStylesAttr($stylesCircle) . '></i>';
            $defaultStyles = ['color' => '#ef990f'];
            $actionStyles = array_merge($defaultStyles, $actionStyles);
            $iconContent .= '<i class="fa-solid fa-circle-up" ' . $customAttributes($actionAttributes) . ' ' . $getStylesAttr($actionStyles) . '></i>';
            break;
        case 'edit': // "pen" icon.
            $defaultStyles = ['color' => '#777777'];
            $actionStyles = array_merge($defaultStyles, $actionStyles);
            $defaultAttrs = ['data-fa-transform' => 'shrink-8 down-1 right-6'];
            $actionAttributes = array_merge($defaultAttrs, $actionAttributes);
            $iconContent .= '<i class="fa-solid fa-pen" ' . $customAttributes($actionAttributes) . ' ' . $getStylesAttr($actionStyles) . '></i>';
            break;
    }

    // Close the container
    $iconContent .= '</span>';


    return $iconContent;
}

function block_exaport_item_icon_type_options($itemtype) {
    // Icon with the type of the item
    switch ($itemtype) {
        case 'link':
            $iconTypes = 'link';
            $st = 'solid';
            break;
        case 'file':
            $iconTypes = 'file-lines';
            $st = 'regular';
            break;
        default:
            $iconTypes = 'note-sticky';
            $st = 'regular';
            break;
    }
    return ['iconName' => $iconTypes, 'iconStyle' => $st];
}


/**
 * Add icon pack JS/CSS code. BE careful with edit forms. There are possible already existing icons from Moodle
 * @param bool $limitFaToExaportContent limit fontawesome icons only for content from exabis eportfolio. Useful if there is a conflict with icons.
 * @return void
 */
function block_exaport_add_iconpack($limitFaToExaportContent = false) {
    global $PAGE;

    if ($limitFaToExaportContent) {
        $PAGE->requires->js('/blocks/exaport/javascript/exaport_fa.js');
    }

    // add font awesome
    $PAGE->requires->js('/blocks/exaport/pix/icons/fontawesome/js/all.min.js');
    // add boxicons
    //$PAGE->requires->css('/blocks/exaport/pix/icons/boxicons/css/boxicons.min.css');
}

function block_exaport_use_bootstrap_layout() {
    return (bool)(strpos(block_exaport_used_layout(), 'bootstrap') !== false);
}

function block_exaport_used_layout() {
    global $CFG;

    //    return @$CFG->block_exaport_used_layout ?: 'clean_old';
    return @$CFG->block_exaport_used_layout ?: 'moodle_bootstrap';
}

/**
 * Get courses for the current user to display as course folders in view_items
 * Only enrolled courses for all users (including admins)
 * 
 * @param int $userid User ID (optional, defaults to current user)
 * @return array Array of course objects formatted as folder structure
 */
function block_exaport_get_user_course_folders($userid = null, $courseid = null) {
    global $DB, $USER, $CFG;
    
    if (!$userid) {
        $userid = $USER->id;
    }
    
    if (!$courseid) {
        $courseid = 1; // Default to site course
    }
    
    // Get only enrolled courses for the user
    $enrolledcourses = $DB->get_records_sql("
        SELECT c.id, c.fullname, c.shortname, c.visible, c.startdate, c.enddate,
               0 as item_cnt
        FROM {course} c
        INNER JOIN {enrol} e ON e.courseid = c.id
        INNER JOIN {user_enrolments} ue ON ue.enrolid = e.id
        WHERE ue.userid = ? AND c.id > 1 AND c.visible = 1
        ORDER BY c.fullname ASC
    ", array($userid));
    
    // Format courses as folder objects similar to categories
    $coursefolders = array();
    foreach ($enrolledcourses as $course) {
        $folder = new stdClass();
        $folder->id = 'course_' . $course->id; // Prefix to distinguish from categories
        $folder->courseid = $course->id;
        $folder->name = $course->fullname;
        $folder->shortname = $course->shortname;
        $folder->pid = 0; // Course folders are always at root level
        $folder->item_cnt = 0; // Will be updated later if needed
        $folder->type = 'course_folder';
        $folder->icon = 'fa-graduation-cap'; // Course icon
        $folder->url = $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $courseid . 
                      '&categoryid=course_' . $course->id;
        $coursefolders[$folder->id] = $folder;
    }
    
    return $coursefolders;
}

/**
 * Get course sections to display as subfolders when inside a course folder
 * 
 * @param int $target_courseid Course ID to get sections from
 * @param int $context_courseid Current course context for URLs
 * @param int $userid User ID (optional, defaults to current user)
 * @return array Array of section objects formatted as folder structure
 */
function block_exaport_get_course_sections_as_folders($target_courseid, $context_courseid = null, $userid = null) {
    global $DB, $USER, $CFG;
    
    if (!$target_courseid || $target_courseid <= 1) {
        return array();
    }
    
    if (!$userid) {
        $userid = $USER->id;
    }
    
    if (!$context_courseid) {
        $context_courseid = $target_courseid;
    }
    
    // Check if user is enrolled in the course
    $context = context_course::instance($target_courseid);
    if (!is_enrolled($context, $userid)) {
        return array();
    }
    
    // Get course sections
    $course = get_course($target_courseid);
    $modinfo = get_fast_modinfo($course, $userid);
    $sections = $modinfo->get_section_info_all();
    
    $sectionfolders = array();
    
    foreach ($sections as $sectionnum => $section) {
        // Skip section 0 (general section) unless it has a custom name
        if ($sectionnum == 0 && empty($section->name)) {
            continue;
        }
        
        // Check if section is visible to user
        if (!$section->uservisible) {
            continue;
        }
        
        $folder = new stdClass();
        $folder->id = 'section_' . $target_courseid . '_' . $sectionnum;
        $folder->courseid = $target_courseid;
        $folder->sectionnum = $sectionnum;
        $folder->section_id = $section->id;
        
        // Get section name
        if (!empty($section->name)) {
            $folder->name = $section->name;
        } else {
            // Use format-specific section name (e.g., "Topic 1", "Week 1")
            $folder->name = get_section_name($course, $section);
        }
        
        $folder->summary = $section->summary;
        $folder->pid = 'course_' . $target_courseid; // Parent is the course folder
        $folder->item_cnt = count($section->modinfo->sections[$sectionnum] ?? []);
        $folder->type = 'course_section';
        $folder->icon = 'fa-folder'; // Section icon
        $folder->url = $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $context_courseid . 
                      '&categoryid=section_' . $target_courseid . '_' . $sectionnum;
        
        $sectionfolders[$folder->id] = $folder;
    }
    
    // Add "Evidencias" folder for this course
    $evidencias_folder = new stdClass();
    $evidencias_folder->id = 'evidencias_' . $target_courseid;
    $evidencias_folder->courseid = $target_courseid;
    $evidencias_folder->name = 'Evidencias';
    $evidencias_folder->summary = 'Carpeta para evidencias del curso';
    $evidencias_folder->pid = 'course_' . $target_courseid; // Parent is the course folder
    $evidencias_folder->item_cnt = 0; // Could be calculated if needed
    $evidencias_folder->type = 'evidencias_folder';
    $evidencias_folder->icon = 'fa-folder-open'; // Evidencias folder icon
    $evidencias_folder->url = $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $context_courseid . 
                              '&categoryid=evidencias_' . $target_courseid;
    
    $sectionfolders[$evidencias_folder->id] = $evidencias_folder;
    
    return $sectionfolders;
}

/**
 * Get files and resources from a specific course section
 * 
 * @param string $section_id Section ID in format: section_courseid_sectionnum
 * @param int $context_courseid Current course context for URLs
 * @param int $userid User ID (optional, defaults to current user)
 * @return array Array of file/resource objects formatted as portfolio items
 */
function block_exaport_get_section_files($section_id, $context_courseid = null, $userid = null) {
    global $DB, $USER, $CFG, $OUTPUT;
    
    if (!$userid) {
        $userid = $USER->id;
    }
    
    if (!$context_courseid) {
        $context_courseid = 1; // Default to site context
    }
    
    // Parse section ID (format: section_courseid_sectionnum)
    $parts = explode('_', $section_id);
    if (count($parts) !== 3 || $parts[0] !== 'section') {
        return array();
    }
    
    $target_courseid = intval($parts[1]);
    $sectionnum = intval($parts[2]);
    
    if (!$target_courseid || $sectionnum < 0) {
        return array();
    }
    
    // Check if user is enrolled in the course
    try {
        $context = context_course::instance($target_courseid);
        if (!is_enrolled($context, $userid)) {
            return array();
        }
    } catch (Exception $e) {
        return array();
    }
    
    // Get course and section information
    try {
        $course = get_course($target_courseid);
        $modinfo = get_fast_modinfo($course, $userid);
        $section = $modinfo->get_section_info($sectionnum);
        
        if (!$section || !$section->uservisible) {
            return array();
        }
    } catch (Exception $e) {
        return array();
    }
    
    $files = array();
    
    // Get all course modules in this section
    if (!empty($modinfo->sections[$sectionnum])) {
        foreach ($modinfo->sections[$sectionnum] as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            
            // Skip if module is not visible to user
            if (!$cm->uservisible) {
                continue;
            }
            
            // Handle different module types
            switch ($cm->modname) {
                case 'resource':
                    $item = block_exaport_get_resource_file($cm, $target_courseid, $context_courseid);
                    break;
                case 'folder':
                    $folder_files = block_exaport_get_folder_files($cm, $target_courseid, $context_courseid);
                    $files = array_merge($files, $folder_files);
                    continue 2; // Skip the individual item adding since we added multiple
                case 'url':
                    $item = block_exaport_get_url_link($cm, $target_courseid, $context_courseid);
                    break;
                case 'assign':
                    $item = block_exaport_get_assignment($cm, $target_courseid, $context_courseid);
                    break;
                case 'quiz':
                    $item = block_exaport_get_quiz($cm, $target_courseid, $context_courseid);
                    break;
                case 'forum':
                    $item = block_exaport_get_forum($cm, $target_courseid, $context_courseid);
                    break;
                default:
                    // Generic handler for all other module types
                    $item = block_exaport_get_generic_activity($cm, $target_courseid, $context_courseid);
                    break;
            }
            
            if ($item) {
                $files[] = $item;
            }
        }
    }
    
    return $files;
}

/**
 * Get file information from a resource module
 */
function block_exaport_get_resource_file($cm, $target_courseid, $context_courseid) {
    global $DB, $CFG, $OUTPUT;
    
    try {
        $context = context_module::instance($cm->id);
        $fs = get_file_storage();
        
        // Get files from the content area of the resource
        $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'filename', false);
        
        foreach ($files as $file) {
            // Skip directories
            if ($file->is_directory()) {
                continue;
            }
            
            $filename = $file->get_filename();
            $mimetype = $file->get_mimetype();
            
            // Check if it's a document file (PDF, DOC, DOCX, etc.)
            $allowed_mimetypes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
                'image/jpeg',
                'image/png',
                'image/gif'
            ];
            
            if (!in_array($mimetype, $allowed_mimetypes)) {
                continue; // Skip files that are not documents or images
            }
            
            $item = new stdClass();
            $item->id = 'file_' . $cm->id . '_' . $file->get_id();
            $item->name = $cm->name . ' (' . $filename . ')';
            $item->type = 'file';
            $item->intro = $cm->intro ?? '';
            $item->timemodified = $file->get_timemodified();
            $item->courseid = $target_courseid;
            $item->userid = 0; // System generated
            $item->categoryid = 0; // Not in a traditional category
            $item->shareall = 0;
            $item->externaccess = 0;
            $item->externcomment = 0;
            $item->sortorder = 0;
            $item->comments = 0;
            
            // Generate file URL
            $item->url = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            )->out();
            
            $item->fileurl = $item->url;
            $item->attachment = $filename;
            $item->mimetype = $mimetype;
            $item->filesize = $file->get_filesize();
            $item->icon = $OUTPUT->pix_icon(file_file_icon($file), '');
            $item->is_course_file = true; // Flag to identify course files
            
            return $item; // Return the first valid file
        }
    } catch (Exception $e) {
        // Silently continue if there's an error accessing the file
    }
    
    return null;
}

/**
 * Get files from a folder module
 */
function block_exaport_get_folder_files($cm, $target_courseid, $context_courseid) {
    global $DB, $CFG, $OUTPUT;
    
    $files = array();
    
    try {
        $context = context_module::instance($cm->id);
        $fs = get_file_storage();
        
        // Get files from the content area of the folder
        $storedfiles = $fs->get_area_files($context->id, 'mod_folder', 'content', 0, 'filename', false);
        
        foreach ($storedfiles as $file) {
            // Skip directories
            if ($file->is_directory()) {
                continue;
            }
            
            $filename = $file->get_filename();
            $mimetype = $file->get_mimetype();
            
            // Check if it's a document file (PDF, DOC, DOCX, etc.)
            $allowed_mimetypes = [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'text/plain',
                'image/jpeg',
                'image/png',
                'image/gif'
            ];
            
            if (!in_array($mimetype, $allowed_mimetypes)) {
                continue; // Skip files that are not documents or images
            }
            
            $item = new stdClass();
            $item->id = 'folderfile_' . $cm->id . '_' . $file->get_id();
            $item->name = $filename;
            $item->type = 'file';
            $item->intro = $cm->intro ?? '';
            $item->timemodified = $file->get_timemodified();
            $item->courseid = $target_courseid;
            $item->userid = 0; // System generated
            $item->categoryid = 0; // Not in a traditional category
            $item->shareall = 0;
            $item->externaccess = 0;
            $item->externcomment = 0;
            $item->sortorder = 0;
            $item->comments = 0;
            
            // Generate file URL
            $item->url = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename()
            )->out();
            
            $item->fileurl = $item->url;
            $item->attachment = $filename;
            $item->mimetype = $mimetype;
            $item->filesize = $file->get_filesize();
            $item->icon = $OUTPUT->pix_icon(file_file_icon($file), '');
            $item->is_course_file = true; // Flag to identify course files
            
            $files[] = $item;
        }
    } catch (Exception $e) {
        // Silently continue if there's an error accessing the files
    }
    
    return $files;
}

/**
 * Get URL from a URL module (link)
 */
function block_exaport_get_url_link($cm, $target_courseid, $context_courseid) {
    global $DB, $CFG;
    
    try {
        $url_module = $DB->get_record('url', array('id' => $cm->instance));
        if (!$url_module) {
            return null;
        }
        
        $item = new stdClass();
        $item->id = 'url_' . $cm->id;
        $item->name = $cm->name;
        $item->type = 'link';
        $item->intro = $cm->intro ?? '';
        $item->timemodified = $cm->added;
        $item->courseid = $target_courseid;
        $item->userid = 0; // System generated
        $item->categoryid = 0; // Not in a traditional category
        $item->shareall = 0;
        $item->externaccess = 0;
        $item->externcomment = 0;
        $item->sortorder = 0;
        $item->comments = 0;
        
        $item->url = $url_module->externalurl;
        $item->fileurl = $url_module->externalurl;
        $item->attachment = '';
        $item->is_course_file = true; // Flag to identify course files
        
        return $item;
    } catch (Exception $e) {
        // Silently continue if there's an error
    }
    
    return null;
}

/**
 * Get assignment activity
 */
function block_exaport_get_assignment($cm, $target_courseid, $context_courseid) {
    global $DB, $CFG;
    
    try {
        $item = new stdClass();
        $item->id = 'assign_' . $cm->id;
        $item->name = $cm->name;
        $item->type = 'link'; // Treat as link to assignment
        $item->intro = $cm->intro ?? '';
        $item->timemodified = $cm->added;
        $item->courseid = $target_courseid;
        $item->userid = 0;
        $item->categoryid = 0;
        $item->shareall = 0;
        $item->externaccess = 0;
        $item->externcomment = 0;
        $item->sortorder = 0;
        $item->comments = 0;
        
        $item->url = $CFG->wwwroot . '/mod/assign/view.php?id=' . $cm->id;
        $item->fileurl = $item->url;
        $item->attachment = '';
        $item->is_course_file = true;
        $item->activity_type = 'assign';
        $item->icon = 'fa-tasks';
        
        return $item;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get quiz activity
 */
function block_exaport_get_quiz($cm, $target_courseid, $context_courseid) {
    global $DB, $CFG;
    
    try {
        $item = new stdClass();
        $item->id = 'quiz_' . $cm->id;
        $item->name = $cm->name;
        $item->type = 'link';
        $item->intro = $cm->intro ?? '';
        $item->timemodified = $cm->added;
        $item->courseid = $target_courseid;
        $item->userid = 0;
        $item->categoryid = 0;
        $item->shareall = 0;
        $item->externaccess = 0;
        $item->externcomment = 0;
        $item->sortorder = 0;
        $item->comments = 0;
        
        $item->url = $CFG->wwwroot . '/mod/quiz/view.php?id=' . $cm->id;
        $item->fileurl = $item->url;
        $item->attachment = '';
        $item->is_course_file = true;
        $item->activity_type = 'quiz';
        $item->icon = 'fa-question-circle';
        
        return $item;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get forum activity
 */
function block_exaport_get_forum($cm, $target_courseid, $context_courseid) {
    global $DB, $CFG;
    
    try {
        $item = new stdClass();
        $item->id = 'forum_' . $cm->id;
        $item->name = $cm->name;
        $item->type = 'link';
        $item->intro = $cm->intro ?? '';
        $item->timemodified = $cm->added;
        $item->courseid = $target_courseid;
        $item->userid = 0;
        $item->categoryid = 0;
        $item->shareall = 0;
        $item->externaccess = 0;
        $item->externcomment = 0;
        $item->sortorder = 0;
        $item->comments = 0;
        
        $item->url = $CFG->wwwroot . '/mod/forum/view.php?id=' . $cm->id;
        $item->fileurl = $item->url;
        $item->attachment = '';
        $item->is_course_file = true;
        $item->activity_type = 'forum';
        $item->icon = 'fa-comments';
        
        return $item;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get generic activity for any module type
 */
function block_exaport_get_generic_activity($cm, $target_courseid, $context_courseid) {
    global $DB, $CFG;
    
    try {
        $item = new stdClass();
        $item->id = $cm->modname . '_' . $cm->id;
        $item->name = $cm->name;
        $item->type = 'link';
        $item->intro = $cm->intro ?? '';
        $item->timemodified = $cm->added;
        $item->courseid = $target_courseid;
        $item->userid = 0;
        $item->categoryid = 0;
        $item->shareall = 0;
        $item->externaccess = 0;
        $item->externcomment = 0;
        $item->sortorder = 0;
        $item->comments = 0;
        
        $item->url = $CFG->wwwroot . '/mod/' . $cm->modname . '/view.php?id=' . $cm->id;
        $item->fileurl = $item->url;
        $item->attachment = '';
        $item->is_course_file = true;
        $item->activity_type = $cm->modname;
        
        // Set icon based on activity type
        $item->icon = block_exaport_get_activity_icon($cm->modname);
        
        return $item;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Get appropriate icon for different activity types
 */
function block_exaport_get_activity_icon($modname) {
    $icons = array(
        'assign' => 'fa-tasks',
        'quiz' => 'fa-question-circle',
        'forum' => 'fa-comments',
        'chat' => 'fa-comment',
        'choice' => 'fa-check-square',
        'data' => 'fa-database',
        'feedback' => 'fa-star',
        'glossary' => 'fa-book',
        'lesson' => 'fa-graduation-cap',
        'page' => 'fa-file-alt',
        'book' => 'fa-book-open',
        'wiki' => 'fa-edit',
        'workshop' => 'fa-users',
        'scorm' => 'fa-play-circle',
        'survey' => 'fa-chart-bar',
        'h5pactivity' => 'fa-puzzle-piece',
        'lti' => 'fa-external-link-alt',
        'bigbluebuttonbn' => 'fa-video',
        'label' => 'fa-tag',
        'imscp' => 'fa-archive',
        'folder' => 'fa-folder',
        'resource' => 'fa-file',
        'url' => 'fa-link'
    );
    
    return isset($icons[$modname]) ? $icons[$modname] : 'fa-puzzle-piece';
}

/**
 * Get file icon based on mimetype for course files or activity type
 */
function block_exaport_get_file_icon($item) {
    global $OUTPUT;
    
    $iconsize = '48px';
    $iconcolor = '#666';
    
    // Check if this is an activity (not a file)
    if (isset($item->activity_type) && $item->activity_type) {
        $icon_class = block_exaport_get_activity_icon($item->activity_type);
        
        // Define colors for different activity types
        $activity_colors = array(
            'fa-tasks' => '#2196F3',        // assign - blue
            'fa-question-circle' => '#4CAF50',  // quiz - green
            'fa-comments' => '#FF9800',     // forum - orange
            'fa-comment' => '#9C27B0',      // chat - purple
            'fa-check-square' => '#607D8B', // choice - blue grey
            'fa-database' => '#795548',     // data - brown
            'fa-star' => '#FFC107',         // feedback - amber
            'fa-book' => '#3F51B5',         // glossary - indigo
            'fa-graduation-cap' => '#E91E63', // lesson - pink
            'fa-file-alt' => '#009688',     // page - teal
            'fa-book-open' => '#8BC34A',    // book - light green
            'fa-edit' => '#FF5722',         // wiki - deep orange
            'fa-users' => '#673AB7',        // workshop - deep purple
            'fa-play-circle' => '#F44336',  // scorm - red
            'fa-chart-bar' => '#00BCD4',    // survey - cyan
            'fa-puzzle-piece' => '#CDDC39', // h5p - lime
            'fa-external-link-alt' => '#9E9E9E', // lti - grey
            'fa-video' => '#2196F3',        // bigbluebutton - blue
        );
        
        $color = isset($activity_colors[$icon_class]) ? $activity_colors[$icon_class] : $iconcolor;
        return '<i class="fa ' . str_replace('fa-', '', $icon_class) . '" style="font-size: ' . $iconsize . '; color: ' . $color . ';"></i>';
    }
    
    // Handle file types (original logic)
    if (!isset($item->mimetype)) {
        // Default file icon
        return '<i class="fa fa-file" style="font-size: ' . $iconsize . '; color: ' . $iconcolor . ';"></i>';
    }
    
    $iconhtml = '';
    $iconsize = '48px';
    $iconcolor = '#666';
    
    switch ($item->mimetype) {
        case 'application/pdf':
            $iconhtml = '<i class="fa fa-file-pdf" style="font-size: ' . $iconsize . '; color: #d32f2f;"></i>';
            break;
        case 'application/msword':
        case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
            $iconhtml = '<i class="fa fa-file-word" style="font-size: ' . $iconsize . '; color: #1976d2;"></i>';
            break;
        case 'application/vnd.ms-excel':
        case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
            $iconhtml = '<i class="fa fa-file-excel" style="font-size: ' . $iconsize . '; color: #388e3c;"></i>';
            break;
        case 'application/vnd.ms-powerpoint':
        case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
            $iconhtml = '<i class="fa fa-file-powerpoint" style="font-size: ' . $iconsize . '; color: #f57c00;"></i>';
            break;
        case 'text/plain':
            $iconhtml = '<i class="fa fa-file-text" style="font-size: ' . $iconsize . '; color: ' . $iconcolor . ';"></i>';
            break;
        case 'image/jpeg':
        case 'image/png':
        case 'image/gif':
            $iconhtml = '<i class="fa fa-file-image" style="font-size: ' . $iconsize . '; color: #7b1fa2;"></i>';
            break;
        default:
            $iconhtml = '<i class="fa fa-file" style="font-size: ' . $iconsize . '; color: ' . $iconcolor . ';"></i>';
            break;
    }
    
    return $iconhtml;
}
