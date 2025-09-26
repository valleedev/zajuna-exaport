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

use block_exaport\globals as g;

$courseid = optional_param('courseid', 0, PARAM_INT);
$sort = optional_param('sort', '', PARAM_RAW);
$categoryid = optional_param('categoryid', '', PARAM_RAW); // Changed to RAW to support course_123 format
$userid = optional_param('userid', 0, PARAM_INT);
$type = optional_param('type', '', PARAM_TEXT);
$layout = optional_param('layout', '', PARAM_TEXT);
$action = optional_param('action', '', PARAM_TEXT);

$wstoken = optional_param('wstoken', null, PARAM_RAW);

require_once($CFG->dirroot . '/webservice/lib.php');

$useBootstrapLayout = block_exaport_use_bootstrap_layout();

$authenticationinfo = null;
if ($wstoken) {
    $webservicelib = new webservice();
    $authenticationinfo = $webservicelib->authenticate_user($wstoken);
} else {
    block_exaport_require_login($courseid);
}


$context = context_system::instance();

$userpreferences = block_exaport_get_user_preferences();

if (!$sort && $userpreferences && isset($userpreferences->itemsort)) {
    $sort = $userpreferences->itemsort;
}

if ($type != 'shared' && $type != 'sharedstudent') {
    $type = 'mine';
}

// What's the display layout: tiles / details?
if (!$layout && isset($userpreferences->view_items_layout)) {
    $layout = $userpreferences->view_items_layout;
}
if ($layout != 'details') {
    $layout = 'tiles';
} // Default = tiles.

// Check sorting.
$parsedsort = block_exaport_parse_item_sort($sort, false);
$sort = $parsedsort[0] . '.' . $parsedsort[1];

$sortkey = $parsedsort[0];

if ($parsedsort[1] == "desc") {
    $newsort = $sortkey . ".asc";
} else {
    $newsort = $sortkey . ".desc";
}
$sorticon = $parsedsort[1] . '.png';
$sqlsort = block_exaport_item_sort_to_sql($parsedsort, false);

block_exaport_setup_default_categories();

if ($type == 'sharedstudent') {
    // Students for Teacher
    if (block_exaport_user_can_see_artifacts_of_students()) {
        $students = block_exaport_get_students_for_teacher();
    } else {
        throw new moodle_exception('not allowed');
    }

    $selecteduser = $userid && isset($students[$userid]) ? $students[$userid] : null;

    if (!$selecteduser) {
        throw new moodle_exception('wrong userid');
    } else {
        // Read all categories.
        $categorycolumns = g::$DB->get_column_names_prefixed('block_exaportcate', 'c');
        $categories = $DB->get_records_sql("
                            SELECT
                                {$categorycolumns}
                                , COUNT(i.id) AS item_cnt
                            FROM {block_exaportcate} c
                            LEFT JOIN {block_exaportitem} i ON i.categoryid=c.id AND " . block_exaport_get_item_where() . "
                            WHERE c.userid = ?
                            GROUP BY
                                {$categorycolumns}
                            ORDER BY c.name ASC
                        ", array($selecteduser->id));

        foreach ($categories as $category) {
            $category->url = $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $courseid . '&userid=' . $selecteduser->id .
                '&type=sharedstudent&categoryid=' . $category->id;
            $category->icon = block_exaport_get_category_icon($category);
        }

        // Build a tree according to parent.
        $categoriesbyparent = array();
        foreach ($categories as $category) {
            if (!isset($categoriesbyparent[$category->pid])) {
                $categoriesbyparent[$category->pid] = array();
            }
            $categoriesbyparent[$category->pid][] = $category;
        }

        // The main root category for student.
        $rootcategory = block_exaport_get_root_category($selecteduser->id);
        $rootcategory->url = $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $COURSE->id .
            '&type=sharedstudent&userid=' . $selecteduser->id;
        $categories[0] = $rootcategory;

        if (isset($categories[$categoryid])) {
            $currentcategory = $categories[$categoryid];
        } else {
            $currentcategory = $rootcategory;
        }

        // What's the parent category?.
        if ($currentcategory->id && isset($categories[$currentcategory->pid])) {
            $parentcategory = $categories[$currentcategory->pid];
        } else {
            // Link to shared categories
            $parentcategory = (object)[
                'id' => 0,
                'url' => new moodle_url('shared_categories.php', ['courseid' => $COURSE->id, 'sort' => 'mystudents']),
                'name' => '',
            ];
        }

        // Only look for subcategories if this is a numeric ID (traditional category)
        $subcategories = (is_numeric($currentcategory->id) && !empty($categoriesbyparent[$currentcategory->id])) 
            ? $categoriesbyparent[$currentcategory->id] : [];

        // Common items.
        // Only get items for traditional numeric categories, not course folders
        if (is_numeric($currentcategory->id)) {
            $items = $DB->get_records_sql("
                SELECT DISTINCT i.*, COUNT(com.id) As comments
                FROM {block_exaportitem} i
                LEFT JOIN {block_exaportitemcomm} com on com.itemid = i.id
                WHERE i.userid = ?
                    AND i.categoryid=?
                    AND " . block_exaport_get_item_where() .
                " GROUP BY i.id, i.userid, i.type, i.categoryid, i.name, i.url, i.intro,
                i.attachment, i.timemodified, i.courseid, i.shareall, i.externaccess,
                i.externcomment, i.sortorder, i.isoez, i.fileurl, i.beispiel_url,
                i.exampid, i.langid, i.beispiel_angabe, i.source, i.sourceid,
                i.iseditable, i.example_url, i.parentid
                $sqlsort
            ", [$selecteduser->id, $currentcategory->id]);
        } else {
            // For course folders and sections, no traditional items yet
            $items = array();
        }
    }

} else if ($type == 'shared') {
    $rootcategory = (object)[
        'id' => 0,
        'pid' => 0,
        'name' => block_exaport_get_string('shareditems_category'),
        'item_cnt' => '',
        'url' => $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $COURSE->id . '&type=shared',
    ];

    $sharedusers = block_exaport\get_categories_shared_to_user($USER->id);
    $selecteduser = $userid && isset($sharedusers[$userid]) ? $sharedusers[$userid] : null;

    /*
    if (!$selectedUser) {
        $currentCategory = $rootCategory;
        $parentCategory = null;
        $subCategories = $sharedUsers;

        foreach ($subCategories as $category) {
            $userpicture = new user_picture($category);
            $userpicture->size = ($layout == 'tiles' ? 100 : 32);
            $category->icon = $userpicture->get_url($PAGE);
        }

        $items = [];
    } else {
        $currentCategory = $selectedUser;
        $subCategories = $selectedUser->categories;
        $parentCategory = $rootCategory;
        $items = [];
    }
    */
    if (!$categoryid) {
        throw new moodle_exception('wrong category');
    } else if (!$selecteduser) {
        throw new moodle_exception('wrong userid');
    } else {
        $categorycolumns = g::$DB->get_column_names_prefixed('block_exaportcate', 'c');
        $categories = $DB->get_records_sql("
            SELECT
                {$categorycolumns}
                , COUNT(i.id) AS item_cnt
            FROM {block_exaportcate} c
            LEFT JOIN {block_exaportitem} i ON i.categoryid=c.id AND " . block_exaport_get_item_where() . "
            WHERE c.userid = ?
            GROUP BY
                {$categorycolumns}
            ORDER BY c.name ASC
        ", array($selecteduser->id));

        function category_allowed($selecteduser, $categories, $category) {
            while ($category) {
                if (isset($selecteduser->categories[$category->id])) {
                    return true;
                } else if ($category->pid && isset($categories[$category->pid])) {
                    $category = $categories[$category->pid];
                } else {
                    break;
                }
            }

            return false;
        }

        // Build a tree according to parent.
        $categoriesbyparent = [];
        foreach ($categories as $category) {
            $category->url = $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $courseid . '&type=shared&userid=' . $userid .
                '&categoryid=' . $category->id;
            $category->icon = block_exaport_get_category_icon($category);

            if (!isset($categoriesbyparent[$category->pid])) {
                $categoriesbyparent[$category->pid] = array();
            }
            $categoriesbyparent[$category->pid][] = $category;
        }

        if (!isset($categories[$categoryid])) {
            throw new moodle_exception('not allowed');
        }

        $currentcategory = $categories[$categoryid];
        // Only look for subcategories if this is a numeric ID (traditional category)
        $subcategories = (is_numeric($currentcategory->id) && !empty($categoriesbyparent[$currentcategory->id])) 
            ? $categoriesbyparent[$currentcategory->id] : [];
        if (isset($categories[$currentcategory->pid]) &&
            category_allowed($selecteduser, $categories, $categories[$currentcategory->pid])
        ) {
            $parentcategory = $categories[$currentcategory->pid];
        } else {
            $parentcategory = (object)[
                'id' => 0,
                'url' => new moodle_url('shared_categories.php', ['courseid' => $COURSE->id]),
                'name' => '',
            ];
        }

        if (!category_allowed($selecteduser, $categories, $currentcategory)) {
            throw new moodle_exception('not allowed');
        }

        $usercondition = ' i.userid = ' . intval($selecteduser->id) . ' ';
        if ($type == 'shared') {
            $usercondition = ' i.userid > 0 ';
        }

        // Only get items for traditional numeric categories, not course folders
        if (is_numeric($currentcategory->id)) {
            $items = $DB->get_records_sql("
                SELECT DISTINCT i.*, COUNT(com.id) As comments
                FROM {block_exaportitem} i
                LEFT JOIN {block_exaportitemcomm} com on com.itemid = i.id
                WHERE i.categoryid = ?
                    AND " . $usercondition . "
                    AND " . block_exaport_get_item_where() .
                " GROUP BY i.id, i.userid, i.type, i.categoryid, i.name, i.url, i.intro,
                i.attachment, i.timemodified, i.courseid, i.shareall, i.externaccess,
                i.externcomment, i.sortorder, i.isoez, i.fileurl, i.beispiel_url,
                i.exampid, i.langid, i.beispiel_angabe, i.source, i.sourceid,
                i.iseditable, i.example_url, i.parentid
                $sqlsort
            ", [$currentcategory->id]);
        } else {
            // For course folders and sections, no traditional items yet
            $items = array();
        }
    }

} else {
    // Read all categories.
    $categories = block_exaport_get_all_categories_for_user($USER->id);

    // Get course folders for drive-like experience
    $coursefolders = block_exaport_get_user_course_folders($USER->id, $courseid);
    
    // Get course sections if we're looking at a specific course OR if we're in a section OR if we're in evidencias
    $coursesections = array();
    $viewing_course_id = null;
    if (strpos($categoryid, 'course_') === 0 && strpos($categoryid, 'section_') !== 0) {
        // Extract course ID from category ID (format: course_123)
        $viewing_course_id = str_replace('course_', '', $categoryid);
        $coursesections = block_exaport_get_course_sections_as_folders($viewing_course_id, $courseid, $USER->id);
    } else if (strpos($categoryid, 'section_') === 0) {
        // If we're in a section, we also need to load sections to find the current one
        $parts = explode('_', $categoryid);
        if (count($parts) >= 3) {
            $viewing_course_id = $parts[1]; // Extract course ID from section_courseid_sectionnum
            $coursesections = block_exaport_get_course_sections_as_folders($viewing_course_id, $courseid, $USER->id);
        }
    } else if (strpos($categoryid, 'evidencias_') === 0) {
        // If we're in an evidencias folder, load sections for that course
        $parts = explode('_', $categoryid);
        if (count($parts) >= 2) {
            $viewing_course_id = $parts[1]; // Extract course ID from evidencias_courseid
            $coursesections = block_exaport_get_course_sections_as_folders($viewing_course_id, $courseid, $USER->id);
        }
    }
    
    $allcategories = array_merge($categories, $coursefolders, $coursesections);

    foreach ($allcategories as $category) {
        if (!isset($category->url)) {
            $category->url = $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $courseid . '&categoryid=' . $category->id;
        }
        if (!isset($category->icon)) {
            $category->icon = block_exaport_get_category_icon($category);
        }
    }

    // Build a tree according to parent.
    $categoriesbyparent = array();
    foreach ($allcategories as $category) {
        if (!isset($categoriesbyparent[$category->pid])) {
            $categoriesbyparent[$category->pid] = array();
        }
        $categoriesbyparent[$category->pid][] = $category;
    }

    // The main root category.
    $rootcategory = block_exaport_get_root_category();
    $allcategories[0] = $rootcategory;

    // Handle course folder and section selection
    $currentcategory = null;
    
    // Convert empty categoryid to 0 for backward compatibility
    if (empty($categoryid)) {
        $categoryid = 0;
    }
    
    if (strpos($categoryid, 'section_') === 0) {
        // This is a course section
        if (isset($coursesections[$categoryid])) {
            $currentcategory = $coursesections[$categoryid];
            $currentcategory->name = get_string('course_section', 'block_exaport') . ': ' . $currentcategory->name;
        } else {
            // Section not found in coursesections, try to build it manually
            $parts = explode('_', $categoryid);
            if (count($parts) >= 3) {
                $section_courseid = $parts[1];
                $section_number = $parts[2];
                
                // Try to get the section information directly
                try {
                    $course = get_course($section_courseid);
                    $modinfo = get_fast_modinfo($course, $USER->id);
                    $section = $modinfo->get_section_info($section_number);
                    
                    if ($section && $section->uservisible) {
                        $currentcategory = new stdClass();
                        $currentcategory->id = $categoryid;
                        $currentcategory->courseid = $section_courseid;
                        $currentcategory->sectionnum = $section_number;
                        $currentcategory->section_id = $section->id;
                        $currentcategory->pid = 'course_' . $section_courseid;
                        $currentcategory->type = 'course_section';
                        $currentcategory->icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100"><g><path fill="#00304d" d="M90.1,38.9h6.6c1.3,0,3.4,2.5,3.3,3.9v40.5c-.6,6.1-5.3,10.9-11.4,11.4H11.2c-6-.7-10.6-5.2-11.2-11.2V31.6c-.2-1.4,2-3.9,3.3-3.9h6.2l.3-.3V10.2c0-2.2,3.6-5,5.7-4.9h68.3c2.1.1,4.4,1.2,5.4,3.1s.8,1.8.8,1.9v28.7ZM86.9,15.9v-5.3c0-.6-1.5-2.1-2.2-2H16c-.9-.3-2.9,1.2-2.9,2v5.3h73.8ZM86.9,19.2H13.1v1.6h73.8v-1.6ZM86.9,23.8H13.1v3.8h15.8l.8.6,9.3,10.7h47.9v-15.1ZM4,30.9l-.8,1.1v51.4c.5,4.3,4,7.8,8.4,8.2h76.8c4.4-.3,8-4,8.4-8.4v-40.4c-.1,0-.6-.5-.6-.5h-58.1c0-.1-.7-.4-.7-.4l-9.6-10.9H4Z"/><rect fill="#39a900" x="13.1" y="19.2" width="73.8" height="1.6"/></g><g><path fill="#39a900" d="M23.4,86.7c.3-4.5.6-10.3,5-12.8s5.2-2,5.4-2.7c.5-1.5-.9-2-1.6-3s-1.2-1.6-1.5-2.4-.6-2.9-.9-3.3-1.6-.6-2.2-2,0-2.3.2-3.6,0-2.9,0-4.3c.6-4.5,4.6-7.4,9.1-6.6s2.1,1.1,3.2,1.2c1.7.2,2.4-.5,4.4.3,4.6,1.7,3.3,5.4,3.7,9s1.4,2.3.4,4.3-2,1.4-2.1,1.6-.2,1.6-.4,2.1c-.6,1.9-1.4,2.9-2.5,4.5s-1.6,1.1-1.3,2.3,5.4,2.5,6.3,3.1c3.9,2.7,4,9.2,4.1,13.5h-28.4s-.9-.5-.9-.5c0-.2,0-.3,0-.5ZM29.4,51.8c-.2,1.2.2,3,0,4.3,1.2,0,.7-1.8,1.3-2.2s1.9-.4,2.6-.8c1.1-.5,1.5-1.4,2.5-1.9.9.5,1.4,1.2,2.3,1.7,2.4,1.3,4.7,1.1,7.3.9.8.4.3,1.8,1.2,2.2.6.1.4-.3.4-.6.2-2.8-.5-6.4-3.8-6.9s-1.8.2-2.5.1c-2.5-.1-3.8-2-6.7-1.4s-4.2,2.7-4.6,4.5ZM44.7,55.3h-4.3c-1.3,0-3.6-1.6-4.6-2.3-1.1,1.2-2.5,1.7-4,2.1-1.6,4.9-1.1,10.8,3.3,14.1s4.3,1.5,6.6-.4c4-3.3,4-8.9,3-13.5ZM46.8,60.7c1.2-.8,1.1-2.8-.3-3.4l.3,3.4ZM29.9,57.6c-1.7,0-1.6,2.3-.4,3.1l.4-3.1ZM41.1,71.1c-2,.7-3.7.8-5.7,0,.1,1.6-1.2,1.3-2.1,2.2,1.6,2.4,4.2,5.7,7.3,3.1s2.3-2.7,2.3-2.9c.2-.7-2.4-1-1.8-2.4ZM51.4,86.4c-.4-5.3-.7-11-6.9-12.5-.9,0-2.4,4.3-5.5,4.7s-4.9-2.3-7.3-4.6c-6.2,1.1-6.7,7.2-6.8,12.4h3.6c.3-.9-.1-5.4,1.1-5.2,1,.5.4.8.4,1.2v4h16.1l-.3-4.8c0-.4,1.2-.4,1.3,0l.3,4.8h3.9Z"/><rect fill="#39a900" x="65.7" y="48.3" width="16.2" height="1.8" rx=".9" ry=".9"/><rect fill="#39a900" x="65.7" y="51.5" width="11.7" height="1.8" rx=".9" ry=".9"/><rect fill="#39a900" x="65.7" y="54.8" width="14.7" height="1.8" rx=".9" ry=".9"/><rect fill="#39a900" x="65.7" y="58.5" width="16.2" height="1.8" rx=".9" ry=".9"/><rect fill="#39a900" x="65.7" y="61.8" width="11.7" height="1.8" rx=".9" ry=".9"/><rect fill="#39a900" x="65.7" y="65" width="14.7" height="1.8" rx=".9" ry=".9"/><path fill="#39a900" d="M55.6,56.6c-.3-.2-.4-6.3-.3-7.1s.2-1,.7-1.4h7.5l.4.4v7.8l-.4.4h-7.9ZM62.6,49.6h-5.7v5.7h5.7v-5.7Z"/><path fill="#39a900" d="M63.9,58.7v8.3h-8.3c-.3-1.3-.6-7.6.1-8.3s8.2-.4,8.2,0ZM62.6,60h-5.7v5.7h5.7v-5.7Z"/><path fill="#39a900" d="M63.9,79.4v8.3h-8.3c-.4-2.6-.4-5.7,0-8.3h8.3ZM62.6,80.7h-5.7v5.7h5.7v-5.7Z"/><path fill="#39a900" d="M63.9,69.1v8.3h-8.3c-.4-2.6-.4-5.7,0-8.3h8.3ZM62.6,70.4h-5.7v5.7h5.7v-5.7Z"/><path fill="#00304d" d="M61.8,50.6c.9.6-1.8,3.2-2.4,3.4-.9.4-2.8-1.8-1.6-2.1s1.1.4,1.3.4c.4,0,1.8-2.4,2.7-1.7Z"/><path fill="#00304d" d="M61.8,61c.9.6-2.1,3.5-2.5,3.6-.8.2-2.7-1.9-1.5-2.3s1.1.4,1.3.4c.4,0,1.8-2.4,2.7-1.7Z"/><path fill="#00304d" d="M61.8,81.8c.9.7-2.1,3.5-2.5,3.6s-1.7-1-1.9-1.4c-.4-1.6,1.5-.4,1.6-.4.5,0,1.8-2.4,2.7-1.8Z"/><path fill="#00304d" d="M61,71.4c.5-.1.7.2,1,.5.2.4-2.1,2.8-2.5,3-.8.4-2.2-1.2-2.1-1.8.3-1,1.4.1,1.7.1.4,0,1.4-1.6,1.9-1.8Z"/><rect fill="#39a900" x="65.7" y="69" width="16.2" height="1.8" rx=".9" ry=".9"/><rect fill="#39a900" x="65.7" y="72.3" width="11.7" height="1.8" rx=".9" ry=".9"/><rect fill="#39a900" x="65.7" y="75.5" width="14.7" height="1.8" rx=".9" ry=".9"/><rect fill="#39a900" x="65.7" y="79.4" width="16.2" height="1.8" rx=".9" ry=".9"/><rect fill="#39a900" x="65.7" y="82.6" width="11.7" height="1.8" rx=".9" ry=".9"/><rect fill="#39a900" x="65.7" y="85.9" width="14.7" height="1.8" rx=".9" ry=".9"/></g></svg>';
                        
                        if (!empty($section->name)) {
                            $currentcategory->name = get_string('course_section', 'block_exaport') . ': ' . $section->name;
                        } else {
                            $currentcategory->name = get_string('course_section', 'block_exaport') . ': ' . get_section_name($course, $section);
                        }
                    }
                } catch (Exception $e) {
                    // If we can't get the section, it will fall back to root
                }
            }
        }
    } else if (strpos($categoryid, 'course_') === 0) {
        // This is a course folder
        $courseid_from_category = str_replace('course_', '', $categoryid);
        if (isset($coursefolders[$categoryid])) {
            $currentcategory = $coursefolders[$categoryid];
            $currentcategory->name = get_string('course_folder', 'block_exaport') . ': ' . $currentcategory->name;
        }
    } else if (strpos($categoryid, 'evidencias_') === 0) {
        // This is an evidencias folder for a specific course
        if (isset($coursesections[$categoryid])) {
            $currentcategory = $coursesections[$categoryid];
        } else {
            // Try to build it manually if not found in coursesections
            $parts = explode('_', $categoryid);
            if (count($parts) >= 2 && $parts[0] === 'evidencias') {
                $target_courseid = $parts[1];
                if (is_numeric($target_courseid)) {
                    // Create the evidencias folder manually
                    $currentcategory = new stdClass();
                    $currentcategory->id = $categoryid;
                    $currentcategory->courseid = $target_courseid;
                    $currentcategory->name = 'Evidencias';
                    $currentcategory->summary = 'Carpeta para evidencias del curso';
                    $currentcategory->pid = 'course_' . $target_courseid;
                    $currentcategory->item_cnt = 0;
                    $currentcategory->type = 'evidencias_folder';
                    $currentcategory->icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100"><path fill="#00304d" d="M90.1,38.9h6.6c1.3,0,3.4,2.5,3.3,3.9v40.5c-.6,6.1-5.3,10.9-11.4,11.4H11.2c-6-.7-10.6-5.2-11.2-11.2V31.6c-.2-1.4,2-3.9,3.3-3.9h6.2l.3-.3V10.2c0-2.2,3.6-5,5.7-4.9h68.3c2.1.1,4.4,1.2,5.4,3.1s.8,1.8.8,1.9v28.7ZM86.9,15.9v-5.3c0-.6-1.5-2.1-2.2-2H16c-.9-.3-2.9,1.2-2.9,2v5.3h73.8ZM86.9,19.2H13.1v1.6h73.8v-1.6ZM86.9,23.8H13.1v3.8h15.8l.8.6,9.3,10.7h47.9v-15.1ZM4,30.9l-.8,1.1v51.4c.5,4.3,4,7.8,8.4,8.2h76.8c4.4-.3,8-4,8.4-8.4v-40.4c-.1,0-.6-.5-.6-.5h-58.1c0-.1-.7-.4-.7-.4l-9.6-10.9H4Z"/><rect fill="#39a900" x="13.1" y="19.2" width="73.8" height="1.6"/><path fill="#39a900" d="M47.6,51.9c5.5-.2,10.5,3.3,12.1,8.5,1.3,3.9.5,8.3-2.1,11.5l1.1,1.1c.3,0,.5-.2.8-.2.5,0,1.1,0,1.5.3,2.2,2.1,4.4,4.2,6.5,6.4,1.1,1.7-.2,3-1.4,4.1s-2.2.8-3.2,0l-5.9-5.9c-.6-.8-.7-1.7-.3-2.6l-1.1-1.1c-1.8,1.4-4.1,2.4-6.4,2.6-8.6.8-15.4-7.1-13.1-15.5s6.1-8.8,11.5-9ZM47.6,54.2c-7.9.3-12.4,9.3-7.8,15.8s13.1,5.6,16.8-.6c4-6.7-.7-15-8.4-15.2h-.6Z"/></svg>';
                }
            }
        }
        
        // Load evidencias categories for this specific course when navigating in evidencias folder
        if ($currentcategory && isset($currentcategory->courseid)) {
            $evidencias_categories = block_exaport_get_evidencias_categories_for_course($USER->id, $currentcategory->courseid);
            foreach ($evidencias_categories as $evidencias_cat) {
                // Convert to category format expected by categoriesbyparent
                $evidencias_cat->url = $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $courseid . 
                                       '&categoryid=' . $evidencias_cat->id;
                $evidencias_cat->icon = block_exaport_get_category_icon($evidencias_cat);
                // Add to allcategories and categoriesbyparent
                $allcategories[$evidencias_cat->id] = $evidencias_cat;
                if (!isset($categoriesbyparent[$evidencias_cat->pid])) {
                    $categoriesbyparent[$evidencias_cat->pid] = array();
                }
                $categoriesbyparent[$evidencias_cat->pid][] = $evidencias_cat;
            }
        }
    } else if (isset($allcategories[$categoryid])) {
        $currentcategory = $allcategories[$categoryid];
    } 
    
    // Fallback to root category if current category is not found
    if ($currentcategory === null) {
        // Last attempt: check if it's an evidencias folder that we need to create manually
        if (strpos($categoryid, 'evidencias_') === 0) {
            $parts = explode('_', $categoryid);
            if (count($parts) >= 2 && $parts[0] === 'evidencias') {
                $target_courseid = $parts[1];
                if (is_numeric($target_courseid)) {
                    // Create the evidencias folder manually as last resort
                    $currentcategory = new stdClass();
                    $currentcategory->id = $categoryid;
                    $currentcategory->courseid = $target_courseid;
                    $currentcategory->name = 'Evidencias';
                    $currentcategory->summary = 'Carpeta para evidencias del curso';
                    $currentcategory->pid = 'course_' . $target_courseid;
                    $currentcategory->item_cnt = 0;
                    $currentcategory->type = 'evidencias_folder';
                    $currentcategory->icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100"><path fill="#00304d" d="M90.1,38.9h6.6c1.3,0,3.4,2.5,3.3,3.9v40.5c-.6,6.1-5.3,10.9-11.4,11.4H11.2c-6-.7-10.6-5.2-11.2-11.2V31.6c-.2-1.4,2-3.9,3.3-3.9h6.2l.3-.3V10.2c0-2.2,3.6-5,5.7-4.9h68.3c2.1.1,4.4,1.2,5.4,3.1s.8,1.8.8,1.9v28.7ZM86.9,15.9v-5.3c0-.6-1.5-2.1-2.2-2H16c-.9-.3-2.9,1.2-2.9,2v5.3h73.8ZM86.9,19.2H13.1v1.6h73.8v-1.6ZM86.9,23.8H13.1v3.8h15.8l.8.6,9.3,10.7h47.9v-15.1ZM4,30.9l-.8,1.1v51.4c.5,4.3,4,7.8,8.4,8.2h76.8c4.4-.3,8-4,8.4-8.4v-40.4c-.1,0-.6-.5-.6-.5h-58.1c0-.1-.7-.4-.7-.4l-9.6-10.9H4Z"/><rect fill="#39a900" x="13.1" y="19.2" width="73.8" height="1.6"/><path fill="#39a900" d="M47.6,51.9c5.5-.2,10.5,3.3,12.1,8.5,1.3,3.9.5,8.3-2.1,11.5l1.1,1.1c.3,0,.5-.2.8-.2.5,0,1.1,0,1.5.3,2.2,2.1,4.4,4.2,6.5,6.4,1.1,1.7-.2,3-1.4,4.1s-2.2.8-3.2,0l-5.9-5.9c-.6-.8-.7-1.7-.3-2.6l-1.1-1.1c-1.8,1.4-4.1,2.4-6.4,2.6-8.6.8-15.4-7.1-13.1-15.5s6.1-8.8,11.5-9ZM47.6,54.2c-7.9.3-12.4,9.3-7.8,15.8s13.1,5.6,16.8-.6c4-6.7-.7-15-8.4-15.2h-.6Z"/></svg>';
                }
            }
        }
        
        if ($currentcategory === null) {
            // Last attempt: check if this is a numeric category ID that might be an evidencias category
            if (is_numeric($categoryid) && $categoryid > 0) {
                // Check if this category exists and is an evidencias category
                $evidencias_category = $DB->get_record('block_exaportcate', array('id' => $categoryid));
                if ($evidencias_category && !empty($evidencias_category->source) && is_numeric($evidencias_category->source)) {
                    // This is an evidencias category, set it as current
                    $currentcategory = $evidencias_category;
                    $currentcategory->type = 'evidencias_category';
                    
                    // Check if this category was created by an instructor
                    $category_creator_is_instructor = block_exaport_user_is_teacher_in_course($evidencias_category->userid, $evidencias_category->source);
                    
                    if ($category_creator_is_instructor) {
                        // Instructor-created competencia folder - use custom SVG icon
                        $currentcategory->icon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">
  <g>
    <path fill="#00304d" d="M90.11,38.91h6.55c1.3,0,3.43,2.54,3.33,3.93v40.53c-.56,6.08-5.25,10.89-11.38,11.39H11.2c-5.96-.66-10.56-5.22-11.19-11.2V31.56c-.17-1.44,2-3.93,3.32-3.93h6.25l.3-.3V10.19c0-2.2,3.56-4.96,5.75-4.94h68.34c2.09.1,4.35,1.23,5.37,3.08.13.23.76,1.76.76,1.86v28.73ZM86.89,15.93v-5.34c0-.56-1.5-2.05-2.21-2.03l-68.66-.1c-.95-.24-2.91,1.32-2.91,2.12v5.34h73.78ZM86.89,19.16H13.11v1.61h73.78v-1.61ZM86.89,23.79H13.11v3.83h15.82l.81.6,9.26,10.69h47.88v-15.12ZM3.98,30.89l-.76,1.06v51.41c.47,4.32,4.03,7.83,8.37,8.17h76.82c4.4-.35,8.02-3.96,8.37-8.37l-.11-40.42-.5-.5-58.14-.12-.71-.3-9.62-10.94H3.98Z"/>
    <rect fill="#39a900" x="13.11" y="19.16" width="73.78" height="1.61"/>
  </g>
  <g>
    <path fill="#39a900" d="M64.7,67.03c-.02.08.02.09.05.13.14.2.72.64.94.88,2.83,3.09,3.1,7.74.73,11.18l6.73,7.11c2.33,3.1-2.19,6.96-4.92,4.24l-5.67-8.22c-.6.19-1.2.41-1.82.52-4,.74-8.05-1.27-9.73-4.97l-.11.07c-.27,1.07-.41,2.19-.7,3.26-.19.71-.54,1.32-1.35,1.4-1.8.18-3.89-.14-5.72,0-.54-.07-1.04-.43-1.24-.95l-.88-3.86-.17-.15-1.74-.71c-.13-.04-.23.05-.34.11-.82.43-2.76,2.02-3.5,2.12-.37.05-.79-.04-1.11-.23-1.23-1.28-2.82-2.55-3.96-3.9-.47-.56-.64-1.11-.36-1.82.39-.97,1.52-2.15,2.01-3.14.04-.09.1-.18.09-.28l-.84-1.91-3.82-.87c-.64-.24-.99-.79-1.04-1.45-.14-1.76.1-3.72.02-5.5.08-.42.29-.82.64-1.08.64-.47,3.04-.72,3.96-.98.09-.03.28-.08.33-.15l.7-1.68.02-.34-2.06-3.24c-.23-.57-.16-1.2.22-1.68,1.1-1.37,2.82-2.62,3.99-3.98.52-.39,1.16-.41,1.73-.13.97.49,2.01,1.48,2.97,1.93.14.07.25.14.41.08l1.84-.78.83-3.77c.21-.64.77-1.05,1.44-1.1,1.78-.14,3.76.1,5.56.02.37.05.83.31,1.05.61.46.64.71,2.89.95,3.79.05.18.06.41.21.52l1.68.71h.33s3.17-2.01,3.17-2.01c.62-.32,1.28-.19,1.81.24,1.35,1.1,2.6,2.77,3.91,3.94.26.35.35.77.3,1.20-.1.74-1.69,2.68-2.12,3.5-.05.09-.12.18-.11.3l.86,1.95,3.8.84c.75.28,1.01.88,1.07,1.64.1,1.39.1,3.66,0,5.05-.05.7-.35,1.29-1.04,1.54ZM57.93,65.21c1.29-.15,2.57-.05,3.81.33.31.1,1.35.6,1.52.6.09,0,.92-.18,1.02-.23.11-.05.17-.1.2-.22l-.02-5.58c-.07-.11-.17-.15-.28-.18-1.1-.37-2.54-.41-3.63-.79-.38-.13-.7-.43-.88-.78-.17-.33-.77-1.78-.82-2.09-.06-.38-.01-.62.14-.96l2.03-3.17.02-.3-3.92-3.94-.37.03c-1.02.54-2.07,1.51-3.1,2-.27.13-.58.22-.88.19s-2.05-.76-2.35-.93-.51-.45-.63-.77c-.4-1.09-.4-2.64-.82-3.71-.08-.2-.16-.26-.38-.3-1.66.11-3.52-.16-5.15,0-.27.03-.38.11-.47.36-.31.92-.43,2.2-.67,3.18-.14.54-.27.94-.77,1.26-.27.17-1.73.76-2.06.85-.39.11-.65.08-1.02-.07l-3.25-2.06-.39.02-3.86,3.89.02.33,1.99,3.05c.22.43.27.8.14,1.26-.08.28-.73,1.84-.87,2.05-.17.26-.53.53-.83.63-1.1.38-2.53.41-3.63.79-.24.08-.28.13-.32.41-.19,1.56.14,3.49,0,5.1.05.49.22.46.64.56,1.03.26,2.21.38,3.22.69.34.1.61.24.82.53s.87,1.83.97,2.2.09.67-.05,1.04l-2.07,3.24c-.07.15-.06.32.04.45l3.72,3.72c.12.13.29.17.45.09,1-.57,1.96-1.37,2.96-1.93.37-.21.64-.31,1.08-.27.3.03,1.93.7,2.25.87s.55.45.68.78c.43,1.1.43,2.66.83,3.75.06.16.13.24.31.27,1.71-.11,3.62.16,5.31,0,.42-.04.38-.27.47-.63.21-.85.51-3.2.96-3.77.14-.17.32-.27.46-.42-.2-.74-.32-1.49-.35-2.26-5.86,2.15-12.54-.65-15.15-6.26-4.77-10.25,5.83-20.93,16.11-16.21,5.01,2.31,7.89,7.85,6.85,13.31Z"/>
    <path fill="#39a900" d="M58.7,67c-5.5.31-8.64,6.57-5.44,11.14,3.52,5.03,11.37,3.51,12.72-2.45,1.04-4.57-2.59-8.95-7.28-8.68Z"/>
    <path fill="#39a900" d="M58.7,67c4.69-.26,8.31,4.11,7.28,8.68-1.35,5.97-9.2,7.48-12.72,2.45-3.19-4.56-.06-10.83,5.44-11.14ZM58.34,68.25c-4.16.4-6.42,5.23-4.35,8.79,2.49,4.28,8.93,3.73,10.6-.93,1.52-4.23-1.85-8.29-6.26-7.87Z"/>
    <path fill="#fff" d="M62.27,72.72c-1.09,1.16-2.4,2.58-3.58,3.63-.36.32-.61.54-1.07.18-.34-.26-1.79-1.7-2.03-2.03-.39-.56.09-1.14.73-.94.32.1,1.28,1.34,1.63,1.59l.15.04,3.66-3.63c.62-.39,1.22.35.75.95-.08.1-.18.13-.24.2Z"/>
  </g>
</svg>';
                        error_log("VIEW_ITEMS DEBUG: Applied INSTRUCTOR SVG to category {$evidencias_category->id} in view_items.php");
                    } else {
                        // Student-created folder - keep default folder icon
                        $currentcategory->icon = 'fa-folder';
                        error_log("VIEW_ITEMS DEBUG: Applied fa-folder to STUDENT category {$evidencias_category->id} in view_items.php");
                    }
                    
                    $currentcategory->url = $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $courseid . '&categoryid=' . $categoryid;
                } else if (isset($allcategories[$categoryid])) {
                    // This is a normal category
                    $currentcategory = $allcategories[$categoryid];
                }
            }
            
            // If still null, fall back to root
            if ($currentcategory === null) {
                $currentcategory = $rootcategory;
                $categoryid = 0; // Reset to root
            }
        }
    }

    // What's the parent category?.
    if ($currentcategory && !empty($currentcategory->id) && $currentcategory->id !== 0) {
        if (isset($allcategories[$currentcategory->pid])) {
            $parentcategory = $allcategories[$currentcategory->pid];
        } else if ($currentcategory->pid && strpos($currentcategory->pid, 'course_') === 0 && isset($coursefolders[$currentcategory->pid])) {
            // Handle special case where parent is a course folder
            $parentcategory = $coursefolders[$currentcategory->pid];
        } else if (isset($currentcategory->type) && $currentcategory->type === 'evidencias_category' && !empty($currentcategory->source)) {
            // For evidencias categories, the parent should be the evidencias folder
            $evidencias_folder_id = 'evidencias_' . $currentcategory->source;
            // Create the parent evidencias folder object
            $parentcategory = new stdClass();
            $parentcategory->id = $evidencias_folder_id;
            $parentcategory->name = 'Evidencias';
            $parentcategory->type = 'evidencias_folder';
            $parentcategory->icon = 'fa-folder';
            $parentcategory->url = $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $courseid . '&categoryid=' . $evidencias_folder_id;
        } else {
            $parentcategory = null;
        }
    } else {
        $parentcategory = null;
    }

    // Only look for subcategories if this is a numeric ID (traditional category)
    $subcategories = ($currentcategory && is_numeric($currentcategory->id) && !empty($categoriesbyparent[$currentcategory->id])) 
        ? $categoriesbyparent[$currentcategory->id] : [];
    
    // If we're in a course folder, add course sections as subcategories
    if ($currentcategory && isset($currentcategory->id) && strpos($currentcategory->id, 'course_') === 0) {
        $course_id = str_replace('course_', '', $currentcategory->id);
        if (is_numeric($course_id)) {
            $course_sections = block_exaport_get_course_sections_as_folders($course_id);
            // Convert associative array to indexed array and merge with subcategories
            $subcategories = array_merge($subcategories, array_values($course_sections));
        }
    }
    
    // If we're in an evidencias folder, show categories that have this evidencias folder as parent
    if ($currentcategory && isset($currentcategory->id) && strpos($currentcategory->id, 'evidencias_') === 0) {
        // Extract course ID from evidencias_XX format
        $evidencias_courseid = intval(substr($currentcategory->id, 11));
        // Look for categories with PID = -courseid (evidencias categories)
        $evidencias_pid = -$evidencias_courseid;
        if (isset($categoriesbyparent[$evidencias_pid])) {
            $subcategories = array_merge($subcategories, $categoriesbyparent[$evidencias_pid]);
        }
    }

    // Common items.
    // Define numeric_category_id for backward compatibility
    $numeric_category_id = ($currentcategory && is_numeric($currentcategory->id)) ? $currentcategory->id : 0;
    
    if ($currentcategory && isset($currentcategory->id) && strpos($currentcategory->id, 'section_') === 0) {
        // For course sections, get files related to that section
        $items = block_exaport_get_section_files($currentcategory->id, $courseid, $USER->id);
    } else if ($currentcategory && isset($currentcategory->id) && strpos($currentcategory->id, 'course_') === 0) {
        // For course folders, get items related to that course (for now, empty)
        $items = array(); // TODO: In the future, get course-related artifacts
    } else if ($currentcategory && isset($currentcategory->id) && strpos($currentcategory->id, 'evidencias_') === 0) {
        // For evidencias root folder, don't show any items, only show subcategories
        // This prevents student files from appearing in the main evidencias folder
        $items = array(); // Empty array - no items shown at evidencias root level
    } else {
        // For regular categories, get items normally
        // But first check if this is an evidencias category
        if ($numeric_category_id > 0) {
            $category_record = $DB->get_record('block_exaportcate', array('id' => $numeric_category_id));
            if ($category_record && !empty($category_record->source) && is_numeric($category_record->source)) {
                // This is an evidencias category - use evidencias visibility rules
                $items = block_exaport_get_evidencias_items_for_course($USER->id, $category_record->source, $numeric_category_id, $sqlsort);
            } else {
                // Regular category
                $items = block_exaport_get_items_by_category_and_user($USER->id, $numeric_category_id, $sqlsort, true);
            }
        } else {
            $items = block_exaport_get_items_by_category_and_user($USER->id, $numeric_category_id, $sqlsort, true);
        }
    }
}

// Set page URL - ensure it's a local URL
$page_url = '/blocks/exaport/view_items.php';
$url_params = array('courseid' => $courseid);
if (!empty($categoryid)) {
    $url_params['categoryid'] = $categoryid;
}
$PAGE->set_url(new moodle_url($page_url, $url_params));
$PAGE->set_context(context_system::instance());

block_exaport_add_iconpack();

block_exaport_print_header($type == 'shared' || $type == 'sharedstudent' ? 'shared_categories' : "myportfolio");

// Información explicativa del portafolio comentada - ocultar texto informativo
/*
echo "<div class='box generalbox'>";
if (block_exaport_course_has_desp()) {
    $pref = "desp_";
} else {
    $pref = "";
}
$infobox = text_to_html(get_string($pref . "explaining", "block_exaport"));
$infobox .= '<a href="#more_artefacts_info" data-toggle="showmore">' . get_string('moreinfolink', 'block_exaport') . '</a>';
$infobox .= '<div id="more_artefacts_info" style="display: none;">' . get_string('explainingmoredata', 'block_exaport') . '</div>';
echo $OUTPUT->box($infobox, "center");

echo "</div>";
*/

// Save user preferences.
block_exaport_set_user_preferences(array('itemsort' => $sort, 'view_items_layout' => $layout));

echo '<div class="excomdos_cont layout_' . block_exaport_used_layout() . ' excomdos_cont-type-' . $type . '">';
if ($type == 'mine') {
    echo get_string("categories", "block_exaport") . ": ";
    echo '<select onchange="document.location.href=\'' . $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $courseid .
        '&categoryid=\'+this.value;">';
    echo '<option value="">';
    echo $rootcategory->name;
    if ($rootcategory->item_cnt) {
        echo ' (' . $rootcategory->item_cnt . ' ' . block_exaport_get_string($rootcategory->item_cnt == 1 ? 'item' : 'items') . ')';
    }
    echo '</option>';
    function block_exaport_print_category_select($categoriesbyparent, $currentcategoryid, $pid = 0, $level = 0) {
        if (!isset($categoriesbyparent[$pid])) {
            return;
        }

        foreach ($categoriesbyparent[$pid] as $category) {
            echo '<option value="' . $category->id . '"' . ($currentcategoryid == $category->id ? ' selected="selected"' : '') . '>';
            if ($level) {
                echo str_repeat('&nbsp;', 4 * $level) . ' &rarr;&nbsp; ';
            }
            echo $category->name;
            if ($category->item_cnt) {
                echo ' (' . $category->item_cnt . ' ' . block_exaport_get_string($category->item_cnt == 1 ? 'item' : 'items') . ')';
            }
            echo '</option>';
            block_exaport_print_category_select($categoriesbyparent, $currentcategoryid,
                $category->id, $level + 1);
        }
    }

    block_exaport_print_category_select($categoriesbyparent, $currentcategory->id);
    echo '</select>';
}

echo '<div class="excomdos_additem ' . ($useBootstrapLayout ? 'd-flex justify-content-between align-items-center flex-column flex-sm-row' : '') . '">';
if (in_array($type, ['mine', 'shared'])) {
    $cattype = '';
    if ($type == 'shared') {
        $cattype = '&cattype=shared';
    }
    echo '<div class="excomdos_additem_content">';
    if ($type == 'mine') {
        // Show category creation option for instructors and students with write permissions
        if (block_exaport_instructor_can_create_in_category($categoryid)) {
            error_log("DEBUG UI: Showing create category button for type=mine, categoryid=$categoryid");
            echo '<span><a href="' . $CFG->wwwroot . '/blocks/exaport/category.php?action=add&courseid=' . $courseid . '&pid=' . $categoryid . '">'
                . '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 30 30">'
                . '<path fill="#ffffff" d="M22.48,8.53c-.14-.73-.6-1.36-1.31-1.61l-8.75-.02c-1.27-.63-2.46-2-3.91-2.13-2.14-.2-4.59.13-6.76.02-.53.13-.99.51-1.21,1-.04.09-.24.67-.24.72v13.23l2.18-9.29c.26-.87,1.52-1.92,2.43-1.92h17.58ZM24.7,13.17c.02-1.34,1.35-3.29-.49-3.97-6.28.11-12.6-.13-18.87-.08-1.11,0-2,.65-2.38,1.69-.74,3.49-1.67,6.95-2.31,10.45.08.87.69,1.23,1.51,1.29h16.28c1.94,3.03,6.28,3.69,9.05,1.36,4-3.36,2.27-9.79-2.8-10.73Z"/>'
                . '<path fill="#00304d" d="M24.7,13.17c5.06.93,6.8,7.37,2.8,10.73-2.77,2.33-7.1,1.67-9.05-1.37H2.17c-.82-.06-1.43-.42-1.51-1.29.63-3.5,1.57-6.96,2.31-10.45.38-1.03,1.27-1.68,2.38-1.69,6.27-.04,12.58.19,18.87.08,1.84.68.5,2.63.49,3.97ZM22.98,14.3c-3.15.34-5.12,3.72-3.98,6.66,1.32,3.39,5.86,4.21,8.27,1.46,2.95-3.38.18-8.61-4.29-8.12Z"/>'
                . '<path fill="#39a900" d="M22.48,8.53H4.9c-.91,0-2.17,1.04-2.43,1.92L.3,19.73V6.5s.2-.63.24-.72c.23-.49.69-.87,1.21-1l6.76-.02c1.45.13,2.65,1.51,3.91,2.13l8.75.02c.71.25,1.17.88,1.31,1.61Z"/>'
                . '<path fill="#39a900" d="M22.98,14.3c4.47-.49,7.24,4.74,4.29,8.12-2.4,2.75-6.95,1.94-8.27-1.46-1.14-2.94.83-6.32,3.98-6.66ZM24.25,16.12h-1.33v2.36h-2.32l-.11.11v1.18l.11.11h2.32v2.36h1.33v-2.36h2.36v-1.4h-2.36v-2.36Z"/>'
                . '</svg>'
                . '<br />' . get_string("category", "block_exaport") . "</a></span>";
        } else {
            error_log("DEBUG UI: NOT showing create category button for type=mine, categoryid=$categoryid (no permissions)");
        }
    } else if (block_exaport_user_is_student() && block_exaport_student_can_act_in_instructor_folder($categoryid)) {
        // Also show category creation for students within instructor-created folders in evidencias
        error_log("DEBUG UI: Showing create category button for student, categoryid=$categoryid");
        echo '<span><a href="' . $CFG->wwwroot . '/blocks/exaport/category.php?action=add&courseid=' . $courseid . '&pid=' . $categoryid . '">'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 30 30">'
            . '<path fill="#ffffff" d="M22.48,8.53c-.14-.73-.6-1.36-1.31-1.61l-8.75-.02c-1.27-.63-2.46-2-3.91-2.13-2.14-.2-4.59.13-6.76.02-.53.13-.99.51-1.21,1-.04.09-.24.67-.24.72v13.23l2.18-9.29c.26-.87,1.52-1.92,2.43-1.92h17.58ZM24.7,13.17c.02-1.34,1.35-3.29-.49-3.97-6.28.11-12.6-.13-18.87-.08-1.11,0-2,.65-2.38,1.69-.74,3.49-1.67,6.95-2.31,10.45.08.87.69,1.23,1.51,1.29h16.28c1.94,3.03,6.28,3.69,9.05,1.36,4-3.36,2.27-9.79-2.8-10.73Z"/>'
            . '<path fill="#00304d" d="M24.7,13.17c5.06.93,6.8,7.37,2.8,10.73-2.77,2.33-7.1,1.67-9.05-1.37H2.17c-.82-.06-1.43-.42-1.51-1.29.63-3.5,1.57-6.96,2.31-10.45.38-1.03,1.27-1.68,2.38-1.69,6.27-.04,12.58.19,18.87.08,1.84.68.5,2.63.49,3.97ZM22.98,14.3c-3.15.34-5.12,3.72-3.98,6.66,1.32,3.39,5.86,4.21,8.27,1.46,2.95-3.38.18-8.61-4.29-8.12Z"/>'
            . '<path fill="#39a900" d="M22.48,8.53H4.9c-.91,0-2.17,1.04-2.43,1.92L.3,19.73V6.5s.2-.63.24-.72c.23-.49.69-.87,1.21-1l6.76-.02c1.45.13,2.65,1.51,3.91,2.13l8.75.02c.71.25,1.17.88,1.31,1.61Z"/>'
            . '<path fill="#39a900" d="M22.98,14.3c4.47-.49,7.24,4.74,4.29,8.12-2.4,2.75-6.95,1.94-8.27-1.46-1.14-2.94.83-6.32,3.98-6.66ZM24.25,16.12h-1.33v2.36h-2.32l-.11.11v1.18l.11.11h2.32v2.36h1.33v-2.36h2.36v-1.4h-2.36v-2.36Z"/>'
            . '</svg>'
            . '<br />' . get_string("category", "block_exaport") . "</a></span>";
    } else {
        if (block_exaport_user_is_student()) {
            error_log("DEBUG UI: NOT showing create category button for student, categoryid=$categoryid (no permissions)");
            error_log("DEBUG UI: block_exaport_user_is_student() = " . (block_exaport_user_is_student() ? 'true' : 'false'));
            error_log("DEBUG UI: block_exaport_student_can_act_in_instructor_folder($categoryid) = " . (block_exaport_student_can_act_in_instructor_folder($categoryid) ? 'true' : 'false'));
        }
    }
    
    // Add "Mixed" artefact - check permissions based on user role
    // BUT hide for instructors when they are at the evidencias root level
    $is_evidencias_root = (strpos($categoryid, 'evidencias_') === 0);
    $is_instructor = (block_exaport_user_is_teacher() && !block_exaport_user_is_student());
    $is_student = block_exaport_user_is_student();
    
    error_log("ADD ARTEFACT BUTTON DEBUG: categoryid='$categoryid', is_evidencias_root=" . ($is_evidencias_root ? 'true' : 'false') . ", is_instructor=" . ($is_instructor ? 'true' : 'false') . ", is_student=" . ($is_student ? 'true' : 'false'));
    
    $can_add_artefact = false;
    
    // Administrators have full permissions everywhere
    if (block_exaport_user_is_admin()) {
        $can_add_artefact = block_exaport_instructor_can_create_in_category($categoryid);
        error_log("ADD ARTEFACT BUTTON DEBUG: Administrator - granting artefact permissions");
    } else if ($is_instructor) {
        // Instructors can add artefacts if they have permission, but not at evidencias root
        $can_add_artefact = block_exaport_instructor_can_create_in_category($categoryid) && !$is_evidencias_root;
    } else if ($is_student) {
        // Students can add artefacts only in their own personal folders
        if (block_exaport_student_owns_category($categoryid)) {
            $can_add_artefact = true;
            error_log("ADD ARTEFACT BUTTON DEBUG: Student can add artefacts in their own personal folder");
        } else {
            $can_add_artefact = false;
            error_log("ADD ARTEFACT BUTTON DEBUG: Student cannot add artefacts outside their personal area");
        }
    } else {
        // Default permission check for other users
        $can_add_artefact = block_exaport_instructor_can_create_in_category($categoryid);
    }
    
    if ($can_add_artefact) {
        error_log("UPLOAD FILE BUTTON DEBUG: Showing upload file button (replacing add artefact)");
        echo '<span><a href="' . $CFG->wwwroot . '/blocks/exaport/upload_file.php?courseid=' . $courseid . '&categoryid=' . $categoryid . '">'
            . '<svg id="Capa_1" xmlns="http://www.w3.org/2000/svg" width="45" height="45" viewBox="0 0 30 30">'
            . '<defs><style>.st0{fill:#00304d;}</style></defs>'
            . '<path class="st0" d="M25.4,16.8c-.6-.2-1.3-.4-1.9-.4V7.9h-4.1c-.9,0-1.8-1.1-1.8-2V2H6.3c-.1,0-.3.3-.3.5v23.6c0,.1.2.3.3.3h9.5c.2.3.3.7.5,1s.4.7.6,1H6.1c-.9,0-2-1.2-2-2V2.1c.1-1,.8-1.8,1.7-2,4-.2,8,0,12,0,.5,0,.7.1,1.1.5,1.8,2.1,4.3,3.9,6,6s.5.6.5.8v9.5Z"/>'
            . '<path class="st0" d="M18.5,28.3c-3.4-3.3-2-9.1,2.6-10.4s9.4,3.3,7.6,8.1c-1.5,4.2-7,5.4-10.1,2.3ZM23.1,19.4c-.1,0-.4-.1-.5,0l-3,4c0,.5,1.3.2,1.6.4s0,.2,0,.3c.1,1.2-.1,2.7,0,3.8s0,.3.2.3c.8,0,1.9.1,2.7,0s.1,0,.2,0v-4.2c0,0,.1-.1.2-.2.3-.2,1.9.2,1.5-.5l-2.9-3.9Z"/>'
            . '</svg>'
            . '<br />' . get_string("upload_file_evidence", "block_exaport") . "</a></span>";
      
    } else {
        error_log("UPLOAD FILE BUTTON DEBUG: Hiding upload file button");
    }
    
    // Remove the separate student upload logic since we now use upload for everyone
    // Old logic was: Add simple "Upload file" button for students only
    // $is_student_upload = $is_student && block_exaport_student_owns_category($categoryid);
    // Now everyone who can add artefacts gets the upload button instead
    
    // Next types are disabled after adding 'mixed' type. Real artefact type will be changed after filling fields.
    // These types are hidden only in this view. All other functions are working with types as before.
    /*
    // Add "Link" artefact.
    echo '<span><a href="'.$CFG->wwwroot.'/blocks/exaport/item.php?action=add&courseid='.$courseid.'&categoryid='.$categoryid.$cattype.
            '&type=link">'.
            '<img src="pix/link_new_32.png" /><br />'.get_string("link", "block_exaport")."</a></span>";
    // Add "File" artefact.
    echo '<span><a href="'.$CFG->wwwroot.'/blocks/exaport/item.php?action=add&courseid='.$courseid.'&categoryid='.$categoryid.$cattype.
            '&type=file">'.
            '<img src="pix/file_new_32.png" /><br />'.get_string("file", "block_exaport")."</a></span>";
    // Add "Note" artefact.
    echo '<span><a href="'.$CFG->wwwroot.'/blocks/exaport/item.php?action=add&courseid='.$courseid.'&categoryid='.$categoryid.$cattype.
            '&type=note">'.
            '<img src="pix/note_new_32.png" /><br />'.get_string("note", "block_exaport")."</a></span>";
    */
    // Anzeigen wenn kategorien vorhanden zum importieren aus sprachfile.
    if ($type == 'mine') {
        $categories = trim(get_string("lang_categories", "block_exaport"));
        if ($categories && block_exaport_instructor_can_create_in_category($categoryid)) {
            echo '<span><a href="' . $CFG->wwwroot . '/blocks/exaport/category.php?action=addstdcat&courseid=' . $courseid . '">' .
                '<img src="pix/folder_new_32.png" /><br />' . get_string("addstdcat", "block_exaport") . "</a></span>";
        }
    }
    echo '</div>';
}

echo '<div class="excomdos_changeview ' . ($useBootstrapLayout ? 'my-4 my-sm-0 align-self-end align-self-sm-center' : '') . '"><p>';
//echo '<span>'.block_exaport_get_string('change_layout').':</span>';
// ZAJUNA: Hidden - Layout change buttons (Details/Tiles view)
/*
if ($layout == 'tiles') {
    echo '<span><a href="' . $PAGE->url->out(true, ['layout' => 'details']) . '">'
        . block_exaport_fontawesome_icon('list', 'solid', '2')
        //        .'<img src="pix/view_list.png" alt="Tile View" />'
        . '<br />' . block_exaport_get_string("details") . "</a></span>";
} else {
    echo '<span><a href="' . $PAGE->url->out(true, ['layout' => 'tiles']) . '">'
        . block_exaport_fontawesome_icon('table-cells-large', 'solid', '2')
        //            .'<img src="pix/view_tile.png" alt="Tile View" />'
        . '<br />' . block_exaport_get_string("tiles") . "</a></span>";
}
*/

// ZAJUNA: Hidden - Print button
/*
if ($type == 'mine') {
    echo '<span><a target="_blank" href="' . $CFG->wwwroot . '/blocks/exaport/view_items_print.php?courseid=' . $courseid . '">'
        . block_exaport_fontawesome_icon('print', 'solid', '2')
        //            .'<img src="pix/view_print.png" alt="Tile View" />'
        . '<br />' . get_string("printerfriendly", "group") . "</a></span>";
}
*/

// Add Audit link for users with permission
use block_exaport\audit\application\AuditService;
// Mostrar enlace de auditoría solo a administradores
if (block_exaport_user_is_admin()) {
    echo '<span><a href="' . $CFG->wwwroot . '/blocks/exaport/audit.php?courseid=' . $courseid . '">'
        . '<svg id="Capa_1" data-name="Capa 1" xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 30 30">'
        . '<g>'
        . '<path class="cls-1" fill="#00304d" d="M16.92,14.81c.38.42.79.82,1.19,1.23.16-.1.62-.75.8-.65l10.58,10.64-3.63,3.74-10.75-10.62s-.02-.07,0-.12c.06-.12.63-.57.63-.63l-1.32-1.32c-.66.17-1.29.51-2.01.57-7.2.59-8.7-9.23-2.36-11.09,4.88-1.44,9.02,3.59,6.7,8.08,0,.1.1.12.16.17ZM11.36,7.74c-5.52.48-4.92,8.81.58,8.53s5.13-9.03-.58-8.53ZM15.9,15.86l-.34.32,2.21,2.21c0,.06-.57.51-.63.63-.02.05-.04.07,0,.12l8.72,8.59,1.68-1.68-8.71-8.7-.71.65-2.22-2.14Z"/>'
        . '<path class="cls-1" fill="#00304d" d="M5.75,1.03v1.2h-3.32c-.22,0-.7.38-.75.63-.11,8.11-.07,16.25-.02,24.36.16.42.5.63.95.67h17.85s.47-.11.47-.11l.88.86c-.3.25-.72.42-1.1.45H2.42c-.97-.08-1.72-.75-1.98-1.67-.15-8.2-.13-16.46-.01-24.66.07-.68,1.08-1.72,1.75-1.72h3.56Z"/>'
        . '<path class="cls-1" fill="#00304d" d="M22.69,17.17c-.26-.28-1.2-1.01-1.25-1.35V3.03c.02-.32-.54-.81-.81-.81h-3.32v-1.2h3.56c.74,0,1.83,1.21,1.83,1.94v14.2Z"/>'
        . '<rect class="cls-2" fill="#39a900" x="6.05" y="24.11" width="7.78" height="1.02"/>'
        . '<rect class="cls-2" fill="#39a900" x="6.05" y="22.2" width="7.01" height="1.02"/>'
        . '<rect class="cls-2" fill="#39a900" x="6.05" y="20.28" width="5.33" height="1.02"/>'
        . '</g>'
        . '<rect class="cls-2" fill="#39a900" x="7.12" y=".64" width="8.81" height="2.28"/>'
        . '</svg>'
        . '<br />' . get_string("audit", "block_exaport") . "</a></span>";
}

echo '</p></div></div>';

echo '<div class="excomdos_cat">';
echo block_exaport_get_string('current_category') . ': ';

$currentcategoryPathItemButtons = '';

/*echo '<b>';
if (($type == 'shared' || $type == 'sharedstudent') && $selecteduser) {
    echo $selecteduser->name.' / ';
}
echo $currentcategory->name;
echo '</b> ';*/

if ($type == 'mine' && $numeric_category_id > 0) {
    if (@$currentcategory->internshare && (count(exaport_get_category_shared_users($numeric_category_id)) > 0 ||
            count(exaport_get_category_shared_groups($numeric_category_id)) > 0 || $currentcategory->shareall == 1)
    ) {
        $currentcategoryPathItemButtons .= block_exaport_fontawesome_icon('handshake', 'regular', 1);
        //        $currentcategoryPathItemButtons .= ' <img src="pix/noteitshared.gif" alt="file" title="shared to other users">';
    }
    $currentcategoryPathItemButtons .= ' <a href="' . $CFG->wwwroot . '/blocks/exaport/category.php?courseid=' . $courseid . '&id=' . $numeric_category_id .
    // Botón de editar eliminado para prueba
    $currentcategoryPathItemButtons .= ' <a href="' . $CFG->wwwroot . '/blocks/exaport/category.php?courseid=' . $courseid . '&id=' . $numeric_category_id .
        '&action=delete&back=same">'
        . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 15 15" width="15" height="15">' .
        '<path fill="#00304d" d="M6.01,1.71c.05-.28.38-.59.65-.67.28-.09,1.31-.1,1.59-.01.56.17.76.73.7,1.27h2.72c1.28.06,1.98,1.75.86,2.55-.11.08-.45.17-.47.27-.05.27-.05.66-.07.94-.13,2.37-.13,4.78-.3,7.16-.07.98-.42,1.66-1.5,1.74-1.78.13-3.73-.09-5.52,0-.8-.12-1.31-.65-1.38-1.46-.01-.16,0-.32,0-.47-.1-1.9-.18-3.8-.27-5.7-.04-.75-.04-1.51-.1-2.26-1.6-.48-1.23-2.6.35-2.76h2.75c.02-.18-.03-.42,0-.59ZM8.17,2.3c.04-.29.07-.53-.29-.57-.15-.02-.88-.02-.99.03-.21.09-.13.38-.14.55h1.42ZM12.25,3.95s.04-.11.04-.15c.05-.48-.31-.76-.76-.79H3.43c-.84,0-1.12,1.18-.2,1.32h8.54c.21-.01.33-.15.46-.29l-.07-.08s.07,0,.09,0ZM11.24,5.47l.02-.41-.07-.03c-2,0-4.02-.02-6.01.04-.23,0-.46,0-.69,0-.07.14.17.4.29.4h6.46ZM5.63,12.99c.1-.1.14-.28.15-.41,0-.13,0-4.7,0-5.13,0-.35-.18-.57-.56-.52-.46.05-.35.57-.35.9-.01.52,0,4.89,0,4.89.04.36.51.53.77.27ZM7.32,6.93c-.14.03-.3.24-.29.38v5.4c.06.51.84.55.91-.03v-5.3c-.02-.36-.27-.53-.62-.45ZM9.51,6.93c-.13.03-.21.13-.27.24l-.03,5.54c.06.51.85.55.91-.03v-5.3c-.03-.36-.26-.52-.62-.45Z"/>' .
        '<path fill="#00304d" d="M2.09,8.23c-.27.25-.6.04-.62-.32-.01-.24-.02-.6,0-.83,0-.1.02-.35.04-.43.07-.33.63-.36.7.1.03.24.03,1,0,1.25,0,.08-.06.19-.11.24Z"/>' .
        '<path fill="#00304d" d="M12.85,8.72c.27-.07.45.08.48.35.02.21.03.98,0,1.17-.08.39-.65.43-.7-.03-.03-.23-.03-.99,0-1.21.02-.11.11-.25.23-.28Z"/>' .
        '<path fill="#00304d" d="M10.46,1.09c-.11-.12-.44,0-.39-.29.06-.14.25-.09.35-.16.13-.09.07-.36.21-.4.26-.08.19.24.28.35.09.12.31.04.38.19.12.25-.29.21-.38.31-.1.11-.03.43-.28.35-.13-.04-.1-.28-.17-.36Z"/>' .
        '<path fill="#00304d" d="M2.36,10.44c.13.13-.11.2-.19.28-.12.11-.15.25-.29.31-.09-.06-.11-.16-.18-.25-.07-.08-.29-.19-.3-.24-.03-.12.18-.16.27-.23.09-.08.12-.22.22-.29.07-.01.19.23.25.28.06.06.19.09.22.13Z"/>' .
        '<path fill="#00304d" d="M12.7,7.39c-.09-.13.17-.23.23-.28s.14-.26.22-.26c.11.06.13.18.24.27.06.06.11.07.17.1.17.13-.1.21-.18.29-.06.06-.15.25-.22.25-.13-.06-.19-.21-.3-.31-.04-.04-.14-.06-.15-.07Z"/>' .
        '<path fill="#00304d" d="M3.24.92c.06.2.21.26.35.37.18.13-.13.23-.21.32s-.1.25-.25.22c-.02,0-.09-.15-.12-.19-.06-.07-.27-.21-.27-.27,0-.1.19-.14.26-.22.08-.08.09-.27.25-.23Z"/>' .
        '</svg>'
        //            .'<img src="pix/del.png" alt="'.get_string("delete").'"/>'
        . '</a>';

    // Show path only for "my" category. Shared category will not show it, because we need to hide inner Path of the user's structure
    echo '<span class="excomdos_cat_path">' . block_exaport_category_path($currentcategory, $courseid, $currentcategoryPathItemButtons) . '</span>';
} else if ($type == 'shared' && $selecteduser && $categoryid) {
    echo block_exaport_fontawesome_icon('circle-user', 'solid', 1)
        //        .'<strong><img src="pix/user1.png" width="16" />&nbsp;'
        . $selecteduser->name . '&nbsp;/&nbsp;'
        . block_exaport_fontawesome_icon('folder', 'regular', 1, [], ['color' => '#7a7a7a'])
        //        .'<img src="pix/cat_path_item.png" width="16" />'
        . '&nbsp;' . $currentcategory->name . '</strong>';
    // When category selected, allow copy.
    /*
    $url = $PAGE->url->out(true, ['action'=>'copy']);
    echo '<button onclick="document.location.href=\'shared_categories.php?courseid='.$courseid.'&action=copy&categoryid='.$categoryid.'\'">'.block_exaport_get_string('copycategory').'</button>';
    */
} else if ($type == 'mine') {
    // mine, but ROOT
    echo '<span class="excomdos_cat_path">' . block_exaport_category_path(null, $courseid, $currentcategoryPathItemButtons) . '</span>';
}
echo '</div>';

if ($layout == 'details') {
    $table = new html_table();
    $table->width = "100%";

    $table->head = array();
    $table->size = array();

    $table->head['type'] = "<a href='{$CFG->wwwroot}/blocks/exaport/view_items.php?courseid=$courseid&categoryid=$categoryid&sort=" .
        ($sortkey == 'type' ? $newsort : 'type') . "'>" . get_string("type", "block_exaport") . "</a>";
    $table->size['type'] = "10";

    $table->head['name'] = "<a href='{$CFG->wwwroot}/blocks/exaport/view_items.php?courseid=$courseid&categoryid=$categoryid&sort=" .
        ($sortkey == 'name' ? $newsort : 'name') . "'>" . get_string("name", "block_exaport") . "</a>";
    $table->size['name'] = "60";

    $table->head['date'] = "<a href='{$CFG->wwwroot}/blocks/exaport/view_items.php?courseid=$courseid&categoryid=$categoryid&sort=" .
        ($sortkey == 'date' ? $newsort : 'date.desc') . "'>" . get_string("date", "block_exaport") . "</a>";
    $table->size['date'] = "20";

    $table->head['icons'] = '';
    $table->size['icons'] = "10";

    // Add arrow to heading if available.
    if (isset($table->head[$sortkey])) {
        $table->head[$sortkey] = "<img src=\"pix/$sorticon\" alt='" . get_string("updownarrow", "block_exaport") . "' /> " .
            $table->head[$sortkey];
    }

    $table->data = array();
    $itemind = -1;

    if ($parentcategory) {
        // If isn't parent category, show link to go to parent category.
        $itemind++;
        $table->data[$itemind] = array();
        //        $table->data[$itemind]['type'] = '<img src="pix/folderup_32.png" alt="'.block_exaport_get_string('category').'">';
        $table->data[$itemind]['type'] = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="32" height="32"><g><path fill="#00304d" d="M85.14,41.11h6.09c1.21,0,3.19,2.36,3.1,3.66v37.7c-.53,5.65-4.89,10.13-10.59,10.6H11.72c-5.55-.62-9.83-4.86-10.41-10.42v-48.38c-.16-1.34,1.86-3.66,3.09-3.66h5.81l.28-.28v-15.94c0-2.05,3.31-4.62,5.35-4.59h63.58c1.94.09,4.05,1.14,5,2.87.12.21.71,1.63.71,1.73v26.72ZM82.14,19.73v-4.97c0-.52-1.39-1.91-2.05-1.89l-63.87-.09c-.88-.22-2.71,1.22-2.71,1.98v4.97h68.64ZM82.14,22.73H13.5v1.5h68.64v-1.5ZM82.14,27.04H13.5v3.56h14.72l.76.56,8.62,9.95h44.54v-14.06ZM5.01,33.65l-.71.98v47.83c.44,4.02,3.75,7.29,7.79,7.6h71.46c4.1-.32,7.46-3.69,7.78-7.78l-.1-37.6-.47-.47-54.09-.11-.66-.28-8.95-10.18H5.01Z"/><rect fill="#39a900" x="13.5" y="22.73" width="68.64" height="1.5"/></g><g><path fill="#39a900" d="M84.44,62.99l1.77.2c6.96,1.04,12.56,6.81,13.41,13.79l.13,1.4c-.02.41.02.84,0,1.25-.68,13.53-16.71,20.4-26.79,11.2-10.55-9.63-4.09-27.08,10.1-27.83h1.38ZM78.75,76.94h8.1c.67,0,1.75.55,2.25,1,2.34,2.07,1.34,6.08-1.71,6.74-.57.12-1.26,0-1.7.42-.63.6-.37,1.78.45,1.99,1.21.31,3.14-.46,4.11-1.18,4.71-3.53,2.41-10.98-3.46-11.35h-8.04c.39-.6,1.85-1.58,1.99-2.23.23-1.11-.94-1.88-1.86-1.24l-3.97,3.97c-.37.47-.27,1.14.13,1.57,1.32,1.11,2.52,2.79,3.85,3.85s2.68-.42,1.75-1.63c-.51-.66-1.36-1.23-1.87-1.91Z"/><path fill="#ffffff" d="M78.75,76.94c.51.68,1.37,1.25,1.87,1.91.93,1.2-.49,2.63-1.75,1.63-1.32-1.05-2.53-2.74-3.85-3.85-.39-.43-.5-1.09-.13-1.57l3.97-3.97c.92-.64,2.09.13,1.86,1.24-.13.65-1.6,1.63-1.99,2.23h8.04c5.87.37,8.18,7.82,3.46,11.35-.97.72-2.9,1.5-4.11,1.18-.82-.21-1.08-1.39-.45-1.99.44-.43,1.13-.3,1.7-.42,3.05-.66,4.05-4.66,1.71-6.74-.5-.44-1.58-1-2.25-1h-8.1Z"/></g></svg>';

        $table->data[$itemind]['name'] = '<a href="' . $parentcategory->url . '">' . $parentcategory->name . '</a>';
        $table->data[$itemind][] = null;
        $table->data[$itemind][] = null;
    }

    foreach ($subcategories as $category) {
        // Checking for shared items. If userid is null - show users, if userid > 0 - need to show items from user.
        $itemind++;
        $table->data[$itemind] = array();
        
        // Solo mostrar el SVG personalizado para carpetas de cursos
        if (isset($category->type) && $category->type === 'course_folder') {
            $table->data[$itemind]['type'] = '<span class="course-folder-icon">'
                . '<svg id="Capa_1" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 100 100">'
                . '<path fill="#00304d" d="M90.1,38.9h6.6c1.3,0,3.4,2.5,3.3,3.9v40.5c-.6,6.1-5.2,10.9-11.4,11.4H11.2c-6-.7-10.6-5.2-11.2-11.2V31.6c-.2-1.4,2-3.9,3.3-3.9h6.2l.3-.3V10.2c0-2.2,3.6-5,5.8-4.9h68.3c2.1,0,4.3,1.2,5.4,3.1.1.2.8,1.8.8,1.9v28.7h0ZM86.9,15.9v-5.3c0-.6-1.5-2.1-2.2-2H16c-.9-.3-2.9,1.2-2.9,2v5.3h73.8ZM86.9,19.2H13.1v1.6h73.8v-1.6ZM86.9,23.8H13.1v3.8h15.8l.8.6,9.3,10.7h47.9v-15.1h0ZM4,30.9l-.8,1.1v51.4c.5,4.3,4,7.8,8.4,8.2h76.8c4.4-.3,8-4,8.4-8.4v-40.4c-.1,0-.6-.5-.6-.5h-58.1c0-.1-.7-.4-.7-.4l-9.6-10.9H4Z"/>'
                . '<rect fill="#39a900" x="13.1" y="19.2" width="73.8" height="1.6"/>'
                . '</svg>'
                . '</span>';
            $categoryclass = 'exaport-course-folder';
        } else if (isset($category->type) && $category->type === 'course_section') {
            $table->data[$itemind]['type'] = '<span class="course-section-icon">'
                . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">'
                . '<g>'
                . '<path fill="#00304d" d="M90.1,38.9h6.6c1.3,0,3.4,2.5,3.3,3.9v40.5c-.6,6.1-5.3,10.9-11.4,11.4H11.2c-6-.7-10.6-5.2-11.2-11.2V31.6c-.2-1.4,2-3.9,3.3-3.9h6.2l.3-.3V10.2c0-2.2,3.6-5,5.7-4.9h68.3c2.1.1,4.4,1.2,5.4,3.1s.8,1.8.8,1.9v28.7ZM86.9,15.9v-5.3c0-.6-1.5-2.1-2.2-2H16c-.9-.3-2.9,1.2-2.9,2v5.3h73.8ZM86.9,19.2H13.1v1.6h73.8v-1.6ZM86.9,23.8H13.1v3.8h15.8l.8.6,9.3,10.7h47.9v-15.1ZM4,30.9l-.8,1.1v51.4c.5,4.3,4,7.8,8.4,8.2h76.8c4.4-.3,8-4,8.4-8.4v-40.4c-.1,0-.6-.5-.6-.5h-58.1c0-.1-.7-.4-.7-.4l-9.6-10.9H4Z"/>'
                . '<rect fill="#39a900" x="13.1" y="19.2" width="73.8" height="1.6"/>'
                . '</g>'
                . '<g>'
                . '<path fill="#39a900" d="M23.4,86.7c.3-4.5.6-10.3,5-12.8s5.2-2,5.4-2.7c.5-1.5-.9-2-1.6-3s-1.2-1.6-1.5-2.4-.6-2.9-.9-3.3-1.6-.6-2.2-2,0-2.3.2-3.6,0-2.9,0-4.3c.6-4.5,4.6-7.4,9.1-6.6s2.1,1.1,3.2,1.2c1.7.2,2.4-.5,4.4.3,4.6,1.7,3.3,5.4,3.7,9s1.4,2.3.4,4.3-2,1.4-2.1,1.6-.2,1.6-.4,2.1c-.6,1.9-1.4,2.9-2.5,4.5s-1.6,1.1-1.3,2.3,5.4,2.5,6.3,3.1c3.9,2.7,4,9.2,4.1,13.5h-28.4s-.9-.5-.9-.5c0-.2,0-.3,0-.5ZM29.4,51.8c-.2,1.2.2,3,0,4.3,1.2,0,.7-1.8,1.3-2.2s1.9-.4,2.6-.8c1.1-.5,1.5-1.4,2.5-1.9.9.5,1.4,1.2,2.3,1.7,2.4,1.3,4.7,1.1,7.3.9.8.4.3,1.8,1.2,2.2.6.1.4-.3.4-.6.2-2.8-.5-6.4-3.8-6.9s-1.8.2-2.5.1c-2.5-.1-3.8-2-6.7-1.4s-4.2,2.7-4.6,4.5ZM44.7,55.3h-4.3c-1.3,0-3.6-1.6-4.6-2.3-1.1,1.2-2.5,1.7-4,2.1-1.6,4.9-1.1,10.8,3.3,14.1s4.3,1.5,6.6-.4c4-3.3,4-8.9,3-13.5ZM46.8,60.7c1.2-.8,1.1-2.8-.3-3.4l.3,3.4ZM29.9,57.6c-1.7,0-1.6,2.3-.4,3.1l.4-3.1ZM41.1,71.1c-2,.7-3.7.8-5.7,0,.1,1.6-1.2,1.3-2.1,2.2,1.6,2.4,4.2,5.7,7.3,3.1s2.3-2.7,2.3-2.9c.2-.7-2.4-1-1.8-2.4ZM51.4,86.4c-.4-5.3-.7-11-6.9-12.5-.9,0-2.4,4.3-5.5,4.7s-4.9-2.3-7.3-4.6c-6.2,1.1-6.7,7.2-6.8,12.4h3.6c.3-.9-.1-5.4,1.1-5.2,1,.5.4.8.4,1.2v4h16.1l-.3-4.8c0-.4,1.2-.4,1.3,0l.3,4.8h3.9Z"/>'
                . '<rect fill="#39a900" x="65.7" y="48.3" width="16.2" height="1.8" rx=".9" ry=".9"/>'
                . '<rect fill="#39a900" x="65.7" y="51.5" width="11.7" height="1.8" rx=".9" ry=".9"/>'
                . '<rect fill="#39a900" x="65.7" y="54.8" width="14.7" height="1.8" rx=".9" ry=".9"/>'
                . '<rect fill="#39a900" x="65.7" y="58.5" width="16.2" height="1.8" rx=".9" ry=".9"/>'
                . '<rect fill="#39a900" x="65.7" y="61.8" width="11.7" height="1.8" rx=".9" ry=".9"/>'
                . '<rect fill="#39a900" x="65.7" y="65" width="14.7" height="1.8" rx=".9" ry=".9"/>'
                . '<path fill="#39a900" d="M55.6,56.6c-.3-.2-.4-6.3-.3-7.1s.2-1,.7-1.4h7.5l.4.4v7.8l-.4.4h-7.9ZM62.6,49.6h-5.7v5.7h5.7v-5.7Z"/>'
                . '<path fill="#39a900" d="M63.9,58.7v8.3h-8.3c-.3-1.3-.6-7.6.1-8.3s8.2-.4,8.2,0ZM62.6,60h-5.7v5.7h5.7v-5.7Z"/>'
                . '<path fill="#39a900" d="M63.9,79.4v8.3h-8.3c-.4-2.6-.4-5.7,0-8.3h8.3ZM62.6,80.7h-5.7v5.7h5.7v-5.7Z"/>'
                . '<path fill="#39a900" d="M63.9,69.1v8.3h-8.3c-.4-2.6-.4-5.7,0-8.3h8.3ZM62.6,70.4h-5.7v5.7h5.7v-5.7Z"/>'
                . '<path fill="#00304d" d="M61.8,50.6c.9.6-1.8,3.2-2.4,3.4-.9.4-2.8-1.8-1.6-2.1s1.1.4,1.3.4c.4,0,1.8-2.4,2.7-1.7Z"/>'
                . '<path fill="#00304d" d="M61.8,61c.9.6-2.1,3.5-2.5,3.6-.8.2-2.7-1.9-1.5-2.3s1.1.4,1.3.4c.4,0,1.8-2.4,2.7-1.7Z"/>'
                . '<path fill="#00304d" d="M61.8,81.8c.9.7-2.1,3.5-2.5,3.6s-1.7-1-1.9-1.4c-.4-1.6,1.5-.4,1.6-.4.5,0,1.8-2.4,2.7-1.8Z"/>'
                . '<path fill="#00304d" d="M61,71.4c.5-.1.7.2,1,.5.2.4-2.1,2.8-2.5,3-.8.4-2.2-1.2-2.1-1.8.3-1,1.4.1,1.7.1.4,0,1.4-1.6,1.9-1.8Z"/>'
                . '<rect fill="#39a900" x="65.7" y="69" width="16.2" height="1.8" rx=".9" ry=".9"/>'
                . '<rect fill="#39a900" x="65.7" y="72.3" width="11.7" height="1.8" rx=".9" ry=".9"/>'
                . '<rect fill="#39a900" x="65.7" y="75.5" width="14.7" height="1.8" rx=".9" ry=".9"/>'
                . '<rect fill="#39a900" x="65.7" y="79.4" width="16.2" height="1.8" rx=".9" ry=".9"/>'
                . '<rect fill="#39a900" x="65.7" y="82.6" width="11.7" height="1.8" rx=".9" ry=".9"/>'
                . '<rect fill="#39a900" x="65.7" y="85.9" width="14.7" height="1.8" rx=".9" ry=".9"/>'
                . '</g>'
                . '</svg>'
                . '</span>';
            $categoryclass = 'exaport-course-section';
        } else if (isset($category->type) && $category->type === 'evidencias_folder') {
            $table->data[$itemind]['type'] = '<span class="evidencias-folder-icon">'
                . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="32" height="32">'
                . '<path fill="#00304d" d="M90.1,38.9h6.6c1.3,0,3.4,2.5,3.3,3.9v40.5c-.6,6.1-5.3,10.9-11.4,11.4H11.2c-6-.7-10.6-5.2-11.2-11.2V31.6c-.2-1.4,2-3.9,3.3-3.9h6.2l.3-.3V10.2c0-2.2,3.6-5,5.7-4.9h68.3c2.1.1,4.4,1.2,5.4,3.1s.8,1.8.8,1.9v28.7ZM86.9,15.9v-5.3c0-.6-1.5-2.1-2.2-2H16c-.9-.3-2.9,1.2-2.9,2v5.3h73.8ZM86.9,19.2H13.1v1.6h73.8v-1.6ZM86.9,23.8H13.1v3.8h15.8l.8.6,9.3,10.7h47.9v-15.1ZM4,30.9l-.8,1.1v51.4c.5,4.3,4,7.8,8.4,8.2h76.8c4.4-.3,8-4,8.4-8.4v-40.4c-.1,0-.6-.5-.6-.5h-58.1c0-.1-.7-.4-.7-.4l-9.6-10.9H4Z"/>'
                . '<rect fill="#39a900" x="13.1" y="19.2" width="73.8" height="1.6"/>'
                . '<path fill="#39a900" d="M47.6,51.9c5.5-.2,10.5,3.3,12.1,8.5,1.3,3.9.5,8.3-2.1,11.5l1.1,1.1c.3,0,.5-.2.8-.2.5,0,1.1,0,1.5.3,2.2,2.1,4.4,4.2,6.5,6.4,1.1,1.7-.2,3-1.4,4.1s-2.2.8-3.2,0l-5.9-5.9c-.6-.8-.7-1.7-.3-2.6l-1.1-1.1c-1.8,1.4-4.1,2.4-6.4,2.6-8.6.8-15.4-7.1-13.1-15.5s6.1-8.8,11.5-9ZM47.6,54.2c-7.9.3-12.4,9.3-7.8,15.8s13.1,5.6,16.8-.6c4-6.7-.7-15-8.4-15.2h-.6Z"/>'
                . '</svg>'
                . '</span>';
            $categoryclass = 'exaport-evidencias-folder';
        } else {
            $table->data[$itemind]['type'] = '<span class="student-folder-icon">' .
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="32" height="32">' .
                '<g>' .
                '<path fill="#00304d" d="M90.1,38.9h6.6c1.3,0,3.4,2.5,3.3,3.9v40.5c-.6,6.1-5.3,10.9-11.4,11.4H11.2c-6-.7-10.6-5.2-11.2-11.2V31.6c-.2-1.4,2-3.9,3.3-3.9h6.2l.3-.3V10.2c0-2.2,3.6-5,5.7-4.9h68.3c2.1.1,4.4,1.2,5.4,3.1s.8,1.8.8,1.9v28.7ZM86.9,15.9v-5.3c0-.6-1.5-2.1-2.2-2H16c-.9-.3-2.9,1.2-2.9,2v5.3h73.8ZM86.9,19.2H13.1v1.6h73.8v-1.6ZM86.9,23.8H13.1v3.8h15.8l.8.6,9.3,10.7h47.9v-15.1ZM4,30.9l-.8,1.1v51.4c.5,4.3,4,7.8,8.4,8.2h76.8c4.4-.3,8-4,8.4-8.4v-40.4c-.1,0-.6-.5-.6-.5h-58.1c0-.1-.7-.4-.7-.4l-9.6-10.9H4Z"/>' .
                '<rect fill="#39a900" x="13.1" y="19.2" width="73.8" height="1.6"/>' .
                '</g>' .
                '<g>' .
                '<path fill="#39a900" d="M50.3,45.9l4.7.9c1.2,0,1.3-.1,2.3-.5s1.7,1.2,1.9,2.2c.4,2,0,2.9-.3,4.7s0,2.4-.2,3.6c0,.3.3.3.4.5.8,1.4.7,3.1-.7,4.1s-1.3.5-2,.7c-.2,1.8-1.3,3.1-2.5,4.2v1.4s8.3,3.3,8.3,3.3c3.4,1.6,3.7,5.1,3.4,8.5-.2.7-1.1.6-1.2,0-.2-1.4.2-2.9-.2-4.4s-1.6-2.5-3.2-3.1-3-1.1-4.5-1.7l-3.1,2.6c-.4.3-.8.2-1.2.1l-.4.7,1.3,13h6.4v-10.8c0-.3.7-.5.9-.3s.3.3.3.4v10.7h3.4c.1,0,.3-.3.3-.4.2-1.2-.1-2.7,0-4,.2-.7,1.1-.6,1.2,0s0,3.4,0,4.2-.7,1.2-1.3,1.4h-19.3c-.7.1-.9-.9-.3-1.1s2.1,0,2.2,0l1.4-13-.4-.7c-.4.1-.9.2-1.2-.1l-3.2-2.6-5.5,2.2c-1.4.8-2.3,2.3-2.4,3.9-.2,3.1.2,6.6,0,9.7,0,.1.2.5.3.5h3.4v-10.7c0-.4,1.1-.8,1.2,0v10.7h1.9s.3.3.3.3c.2.4,0,.8-.4.9-2-.1-4.3.2-6.4,0s-1.3-.6-1.6-1.3c.2-3.4-.3-7.2,0-10.5s1.7-4,3.5-4.9l8.3-3.2v-1.5c-1.3-1.1-2.3-2.5-2.6-4.3-2.3.1-3.9-2.3-2.8-4.4s.4-.5.5-.6c.1-.3,0-2.1,0-2.6,0-1.5-.6-3.6,1-4.5s.9-.3,1.1-.5.6-1,.8-1.3c1-1.4,2.7-2.1,4.4-2.3h1.9ZM57.7,47.6c-2.2,1.1-4.5-.1-6.8-.4s-4.3-.1-5.6,1.5-.6,1.5-1.7,2.1-.5.1-.7.3c-.8.5-.4,2.1-.4,2.9s0,1.5,0,2.2h1c-.2-1.8.7-3.1,2.6-2.9s3.4.5,5.5.4,3-.8,4-.1,1.1,1.5,1,2.6h1c0,0,.2-3.1.2-3.1.5-1.8.6-3.6,0-5.4ZM45.4,54.5c-.4,0-.6.4-.7.8.2,3.5-1.1,7.6,2.3,10s6.6.8,7.8-2.2.6-5.9.5-7.6-.3-1-.9-1-1.8.3-2.5.4c-1.5.1-3,0-4.5,0s-1.5-.4-1.9-.3ZM43.5,57.3c-2.5,0-2.5,3.4,0,3.4v-3.4ZM56.5,60.8c2.5,0,2.5-3.5,0-3.4v3.4ZM52.6,67c-1.7.7-3.5.6-5.2,0,0,.7-.2.9.3,1.3s1.3,1.1,1.5,1.3,1.3.1,1.5,0,1.4-1.2,1.7-1.4.1,0,.2,0v-1.1ZM47.4,71.7l.8-1.3c0-.2-1.4-1.3-1.7-1.5l-1.7.6v.2c0,0,2.5,2,2.5,2ZM55.1,69.6c-.4,0-1.4-.6-1.7-.6s-1.6,1.3-1.6,1.4c.3.4.5.8.8,1.2s0,.1.1.1c.7-.7,1.6-1.3,2.3-1.9s.1,0,0-.2Z"/>' .
                '</g>' .
                '</svg>' .
                '</span>';
            $categoryclass = 'exaport-student-folder';
        }

        $table->data[$itemind]['name'] = '<a href="' . $category->url . '" class="' . $categoryclass . '">' . $category->name . '</a>';

        $table->data[$itemind][] = null;

        if ($type == 'mine' && ((is_numeric($category->id) && $category->id > 0) || 
                                (isset($category->type) && ($category->type === 'course_folder' || $category->type === 'course_section')))) {
            
            // Only show edit/delete icons for traditional categories, not course folders/sections
            if (is_numeric($category->id) && $category->id > 0) {
                $table->data[$itemind]['icons'] = '<span class="excomdos_listicons">';
                if ((isset($category->internshare) && $category->internshare == 1) &&
                    (count(exaport_get_category_shared_users($category->id)) > 0 ||
                        count(exaport_get_category_shared_groups($category->id)) > 0 ||
                        (isset($category->shareall) && $category->shareall == 1))
                ) {
                    $table->data[$itemind]['icons'] .= block_exaport_fontawesome_icon('handshake', 'regular', 1);
                    //                $table->data[$itemind]['icons'] .= '<img src="pix/noteitshared.gif" alt="file" title="shared to other users">';
                };
                if (@$category->structure_share) {
                    $table->data[$itemind]['icons'] .= ' <img src="pix/sharedfolder.png" title="shared to other users as a structure">';
                }

                $table->data[$itemind]['icons'] .= ' <a href="' . $CFG->wwwroot . '/blocks/exaport/category.php?courseid=' . $courseid .
                    // Botón de editar eliminado para prueba
                    ' <a href="' . $CFG->wwwroot . '/blocks/exaport/category.php?courseid=' . $courseid . '&id=' . $category->id .
                    '&action=delete">'
                    . '<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#d32f2f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>'
                    . '</a>' .
                    '</span>';
            } else {
                // For course folders and sections, no edit/delete icons
                $table->data[$itemind]['icons'] = '';
            }
        } else { // Category with shared items.
            $table->data[$itemind]['icons'] = '';
        }
    }

    $itemscnt = count($items);
    foreach ($items as $item) {
        // Check if this is an evidencias file - if so, use download endpoint
        $isEvidenciasFile = false;
        if ($item->type == 'file' && !empty($item->categoryid)) {
            $category = $DB->get_record('block_exaportcate', array('id' => $item->categoryid));
            if ($category && !empty($category->source) && is_numeric($category->source)) {
                $isEvidenciasFile = true;
            }
        }
        
        if ($isEvidenciasFile) {
            $url = $CFG->wwwroot . '/blocks/exaport/download_file.php?courseid=' . $courseid . '&itemid=' . $item->id;
        } else {
            $url = $CFG->wwwroot . '/blocks/exaport/shared_item.php?courseid=' . $courseid . '&access=portfolio/id/' . $item->userid . '&itemid=' .
                $item->id;
        }

        $itemind++;

        $table->data[$itemind] = array();

        //        $imgtype = '<img src="pix/'.$item->type.'_32.png" alt="'.get_string($item->type, "block_exaport").'">';
        //        $imgtype = '<img src="pix/'.$item->type.'_icon.png" alt="'.get_string($item->type, "block_exaport").'" title="'.get_string($item->type, "block_exaport").'" width="32">';
        // Artefact type.
        $iconTypeProps = block_exaport_item_icon_type_options($item->type);
        $imgtype = block_exaport_fontawesome_icon($iconTypeProps['iconName'], $iconTypeProps['iconStyle'], 2, [], [], [], '', [], [], [], ['exaport-items-type-icon']);

        $table->data[$itemind]['type'] = $imgtype;

        $table->data[$itemind]['name'] = "<a href=\"" . s($url) . "\">" . $item->name . "</a>";
        if ($item->intro) {
            $intro = file_rewrite_pluginfile_urls($item->intro, 'pluginfile.php', context_user::instance($item->userid)->id,
                'block_exaport', 'item_content', 'portfolio/id/' . $item->userid . '/itemid/' . $item->id);

            $shortintro = substr(trim(strip_tags($intro)), 0, 20);
            if (preg_match_all('#(?:<iframe[^>]*)(?:(?:/>)|(?:>.*?</iframe>))#i', $intro, $matches)) {
                $shortintro = $matches[0][0];
            }

            if (!$intro) {
                $tempvar = 1; // For code checker.
                // No intro.
            } else if ($shortintro == $intro) {
                // Very short one.
                $table->data[$itemind]['name'] .= "<table width=\"50%\"><tr><td width=\"50px\">" .
                    format_text($intro, FORMAT_HTML) . "</td></tr></table>";
            } else {
                // Display show/hide buttons.
                $table->data[$itemind]['name'] .= '<div><div id="short-preview-' . $itemind . '"><div>' . $shortintro . '...</div>
                        <a href="javascript:long_preview_show(' . $itemind . ')">[' . get_string('more') . '...]</a>
                        </div>
                        <div id="long-preview-' . $itemind . '" style="display: none;"><div>' . $intro . '</div>
                        <a href="javascript:long_preview_hide(' . $itemind . ')">[' . strtolower(get_string('hide')) . '...]</a>
                        </div>';
            }
        }

        $table->data[$itemind]['date'] = userdate($item->timemodified);

        $icons = '';

        // Link to export to my portfolio.
        if ($currentcategory->id == -1) {
            $table->data[$itemind]['icons'] = '<a href="' . $CFG->wwwroot . '/blocks/exaport/item.php?courseid=' . $courseid .
                '&id=' . $item->id . '&sesskey=' . sesskey() . '&action=copytoself' . '">' .
                '<img src="pix/import.png" title="' . get_string('make_it_yours', "block_exaport") . '"></a>';
            continue;
        };

        if (isset($item->comments) && $item->comments > 0) {
            $icons .= '<span class="excomdos_listcomments">
                            <a href="' . $url . '" >
                        ' . $item->comments . '<img src="pix/comments.png" alt="file">
                            </a>
                        </span>';
        }

        $icons .= block_exaport_get_item_comp_icon($item);

        // Copy files to course.
        if ($item->type == 'file' && block_exaport_feature_enabled('copy_to_course')) {
            $icons .= ' <a href="' . $CFG->wwwroot . '/blocks/exaport/copy_item_to_course.php?courseid=' . $courseid . '&itemid=' . $item->id .
                '&backtype=">' . get_string("copyitemtocourse", "block_exaport") . '</a>';
        }

        if ($type == 'mine') {
            // Use new evidencias-aware permission system
            if (block_exaport_user_can_edit_item($item, $courseid)) {
                // Botón de editar eliminado para prueba
            }
            
            if (block_exaport_user_can_delete_item($item, $courseid)) {
                if ($allowedit = block_exaport_item_is_editable($item->id)) {
                    $icons .= ' <a href="' . $CFG->wwwroot . '/blocks/exaport/item.php?courseid=' . $courseid . '&id=' . $item->id .
                        '&action=delete&categoryid=' . $categoryid . '">'
                        . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 15 15" width="15" height="15">' .
                        '<path fill="#00304d" d="M6.01,1.71c.05-.28.38-.59.65-.67.28-.09,1.31-.1,1.59-.01.56.17.76.73.7,1.27h2.72c1.28.06,1.98,1.75.86,2.55-.11.08-.45.17-.47.27-.05.27-.05.66-.07.94-.13,2.37-.13,4.78-.3,7.16-.07.98-.42,1.66-1.5,1.74-1.78.13-3.73-.09-5.52,0-.8-.12-1.31-.65-1.38-1.46-.01-.16,0-.32,0-.47-.1-1.9-.18-3.8-.27-5.7-.04-.75-.04-1.51-.1-2.26-1.6-.48-1.23-2.6.35-2.76h2.75c.02-.18-.03-.42,0-.59ZM8.17,2.3c.04-.29.07-.53-.29-.57-.15-.02-.88-.02-.99.03-.21.09-.13.38-.14.55h1.42ZM12.25,3.95s.04-.11.04-.15c.05-.48-.31-.76-.76-.79H3.43c-.84,0-1.12,1.18-.2,1.32h8.54c.21-.01.33-.15.46-.29l-.07-.08s.07,0,.09,0ZM11.24,5.47l.02-.41-.07-.03c-2,0-4.02-.02-6.01.04-.23,0-.46,0-.69,0-.07.14.17.4.29.4h6.46ZM5.63,12.99c.1-.1.14-.28.15-.41,0-.13,0-4.7,0-5.13,0-.35-.18-.57-.56-.52-.46.05-.35.57-.35.9-.01.52,0,4.89,0,4.89.04.36.51.53.77.27ZM7.32,6.93c-.14.03-.3.24-.29.38v5.4c.06.51.84.55.91-.03v-5.3c-.02-.36-.27-.53-.62-.45ZM9.51,6.93c-.13.03-.21.13-.27.24l-.03,5.54c.06.51.85.55.91-.03v-5.3c-.03-.36-.26-.52-.62-.45Z"/>' .
                        '<path fill="#00304d" d="M2.09,8.23c-.27.25-.6.04-.62-.32-.01-.24-.02-.6,0-.83,0-.1.02-.35.04-.43.07-.33.63-.36.7.1.03.24.03,1,0,1.25,0,.08-.06.19-.11.24Z"/>' .
                        '<path fill="#00304d" d="M12.85,8.72c.27-.07.45.08.48.35.02.21.03.98,0,1.17-.08.39-.65.43-.7-.03-.03-.23-.03-.99,0-1.21.02-.11.11-.25.23-.28Z"/>' .
                        '<path fill="#00304d" d="M10.46,1.09c-.11-.12-.44,0-.39-.29.06-.14.25-.09.35-.16.13-.09.07-.36.21-.4.26-.08.19.24.28.35.09.12.31.04.38.19.12.25-.29.21-.38.31-.1.11-.03.43-.28.35-.13-.04-.1-.28-.17-.36Z"/>' .
                        '<path fill="#00304d" d="M2.36,10.44c.13.13-.11.2-.19.28-.12.11-.15.25-.29.31-.09-.06-.11-.16-.18-.25-.07-.08-.29-.19-.3-.24-.03-.12.18-.16.27-.23.09-.08.12-.22.22-.29.07-.01.19.23.25.28.06.06.19.09.22.13Z"/>' .
                        '<path fill="#00304d" d="M12.7,7.39c-.09-.13.17-.23.23-.28s.14-.26.22-.26c.11.06.13.18.24.27.06.06.11.07.17.1.17.13-.1.21-.18.29-.06.06-.15.25-.22.25-.13-.06-.19-.21-.3-.31-.04-.04-.14-.06-.15-.07Z"/>' .
                        '<path fill="#00304d" d="M3.24.92c.06.2.21.26.35.37.18.13-.13.23-.21.32s-.1.25-.25.22c-.02,0-.09-.15-.12-.19-.06-.07-.27-.21-.27-.27,0-.1.19-.14.26-.22.08-.08.09-.27.25-.23Z"/>' .
                        '</svg>'
                        . '</a>';
                } else {
                    $icons .= '<img src="pix/deleteview.png" alt="' . get_string("delete") . '">';
                }
            }
        }

        $icons = '<span class="excomdos_listicons">' . $icons . '</span>';

        $table->data[$itemind]['icons'] = $icons;
    }

    echo html_writer::table($table);
} else {
    echo '<div class="excomdos_tiletable ' . ($useBootstrapLayout ? 'row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5' : '') . '">';
    echo '<script type="text/javascript" src="javascript/wz_tooltip.js"></script>';

    // show a link to parent category
    if ($parentcategory) {
        echo block_exaport_category_list_item($category, $courseid, $type, $currentcategory, $parentcategory);
    }

    foreach ($subcategories as $category) {
        echo block_exaport_category_list_item($category, $courseid, $type, $currentcategory, null);
    }

    foreach ($items as $item) {
        echo block_exaport_artefact_list_item($item, $courseid, $type, $categoryid, $currentcategory);
    }

    echo '</div>';
}

echo '<div style="clear: both;">&nbsp;</div>';
echo "</div>";
echo block_exaport_wrapperdivend();
echo $OUTPUT->footer();

function block_exaport_get_item_comp_icon($item) {
    global $DB;

    if (!block_exaport_check_competence_interaction()) {
        return;
    }

    $comps = block_exaport_get_active_comps_for_item($item);

    if (!$comps) {
        return;
    }

    // If item is assoziated with competences display them.
    $competences = "";
    foreach ($comps["descriptors"] as $comp) {
        $competences .= $comp->title . '<br>';
    }
    foreach ($comps["topics"] as $comp) {
        $competences .= $comp->title . '<br>';
    }
    $competences = str_replace("\r", "", $competences);
    $competences = str_replace("\n", "", $competences);
    $competences = str_replace("\"", "&quot;", $competences);
    $competences = str_replace("'", "&prime;", $competences);
    $competences = trim($competences);

    if (!$competences) {
        return;
    }

    return '<a class="artefact-button" onmouseover="Tip(\'' . $competences . '\')" onmouseout="UnTip()">'
        . block_exaport_fontawesome_icon('list', 'solid', 1)
        //        .'<img src="pix/comp.png" alt="'.'competences'.'" />'
        . '</a>';
}

function block_exaport_get_item_project_icon($item) {
    global $DB, $OUTPUT;

    $hasprojectdata = @$item->project_description || @$item->project_process || @$item->project_result;

    if (!$hasprojectdata) {
        return '';
    }

    $projectinfo = [];
    if (@$item->project_description) {
        $projectinfo[] = '<strong>' . get_string('project_description', 'block_exaport') . ':</strong>';
        $content = $item->project_description;
        $content = file_rewrite_pluginfile_urls($content, 'pluginfile.php', context_user::instance($item->userid)->id,
            'block_exaport', 'item_content_project_description', 'portfolio/id/' . $item->userid . '/itemid/' . $item->id);
        $projectinfo[] = $content;
    }
    if (@$item->project_process) {
        $projectinfo[] = '<strong>' . get_string('project_process', 'block_exaport') . ':</strong>';
        $content = $item->project_process;
        $content = file_rewrite_pluginfile_urls($content, 'pluginfile.php', context_user::instance($item->userid)->id,
            'block_exaport', 'item_content_project_process', 'portfolio/id/' . $item->userid . '/itemid/' . $item->id);
        $projectinfo[] = $content;
    }
    if (@$item->project_result) {
        $projectinfo[] = '<strong>' . get_string('project_result', 'block_exaport') . ':</strong>';
        $content = $item->project_result;
        $content = file_rewrite_pluginfile_urls($content, 'pluginfile.php', context_user::instance($item->userid)->id,
            'block_exaport', 'item_content_project_result', 'portfolio/id/' . $item->userid . '/itemid/' . $item->id);
        $projectinfo[] = $content;
    }

    $projectcontent = implode('<br>', $projectinfo);

    $projectcontent = str_replace("\r", "", $projectcontent);
    $projectcontent = str_replace("\n", "", $projectcontent);
    $projectcontent = str_replace("\"", "&quot;", $projectcontent);
    $projectcontent = str_replace("'", "&prime;", $projectcontent);
    $projectcontent = trim($projectcontent);

    if (!$projectcontent) {
        return '';
    }

    return '<a class="artefact-button" onmouseover="Tip(\'' . $projectcontent . '\')" onmouseout="UnTip()">'
        . block_exaport_fontawesome_icon('rectangle-list', 'regular', 1, [], [], [], '', [], [], [], [])
        //        .'<img src="pix/project.png" width="16" alt="'.get_string('item.project_information', 'block_exaport').'" />'
        . '</a>';
}

function block_exaport_category_path($category, $courseid = 1, $currentcategoryPathItemButtons = '') {
    global $DB, $CFG;
    $pathItem = function($id, $title, $courseid, $selected = false, $currentcategoryPathItemButtons = '') use ($CFG) {
        return '<span class="cat_path_item ' . ($selected ? 'active' : '') . '">'
            . '<a href="' . $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $courseid . ($id ? '&categoryid=' . $id : '') . '">'
            . block_exaport_fontawesome_icon('folder', 'regular', 1, [], ['color' => '#7a7a7a']) . '&nbsp;'
            . $title
            . '</a>' . ($selected ? $currentcategoryPathItemButtons : '') . '</span>';
    };
    $path = [];
    if ($category !== null) {
        // Special handling for evidencias categories
        if (isset($category->type) && $category->type === 'evidencias_category' && !empty($category->source)) {
            // Add the evidencias folder as parent
            $evidencias_folder_id = 'evidencias_' . $category->source;
            $path[] = $pathItem($evidencias_folder_id, 'Evidencias', $courseid, false, '');
            // Add the current evidencias category
            $path[] = $pathItem($category->id, $category->name, $courseid, true, $currentcategoryPathItemButtons);
        } else {
            // Traditional category path handling
            $currentId = $category->id;

            while ($currentId != NULL) {
                $item = $DB->get_record('block_exaportcate', array('id' => $currentId));
                if (!$item) {
                    break;
                }
                array_unshift($path, $pathItem($item->id, $item->name, $courseid, (bool)($category->id == $item->id), $currentcategoryPathItemButtons));
                $currentId = $item->pid;
            }
        }
    }
    // Add root.
    array_unshift($path, $pathItem('', 'Root', $courseid));

    $resultPath = implode('<span class="cat_path_delimeter">/</span>', $path);
    return $resultPath;
}

function block_exaport_category_template_tile($category, $courseid, $type, $currentcategory, $parentcategory = null) {
    global $CFG, $USER, $DB;
    $categoryContent = '';

    $categoryContent .= '<div class="excomdos_tile ';
    if ($parentcategory || ($parentcategory === null) && ($type == 'shared' || $type == 'sharedstudent')) {
        $categoryContent .= 'excomdos_tile_fixed';
    }
    $categoryContent .= ' excomdos_tile_category id-' . $category->id . '">
        <div class="excomdos_tilehead">
                <span class="excomdos_tileinfo">';
    if ($parentcategory) {
        $categoryContent .= block_exaport_get_string('category_up');
    } elseif ($currentcategory->id == -1) {
        $categoryContent .= block_exaport_get_string('user');
    } else {
        $categoryContent .= block_exaport_get_string('category');
    }
    $categoryContent .= '</span>';
    // edit buttons
    if (!$parentcategory) {
        $categoryContent .= '<span class="excomdos_tileedit">';

        if ($category->id == -1) {
            $tempvar = 1; // For code checker.
        } else if ($type == 'shared' || $type == 'sharedstudent') {
            $categoryContent .= block_exaport_fontawesome_icon('handshake', 'regular', 1);
            //                        echo '<img src="pix/noteitshared.gif" alt="file" title="shared to other users">';
        } else {
            // Type == mine.
            if (@$category->internshare && (count(exaport_get_category_shared_users($category->id)) > 0 ||
                    count(exaport_get_category_shared_groups($category->id)) > 0 ||
                    (isset($category->shareall) && $category->shareall == 1))) {
                $categoryContent .= block_exaport_fontawesome_icon('handshake', 'regular', 1);
                //                            echo '<img src="pix/noteitshared.gif" alt="file" title="shared to other users">';
            };
            if (@$category->structure_share) {
                $categoryContent .= ' <img src="pix/sharedfolder.png" title="shared to other users as a structure">';
            };
            // Only show edit/delete buttons if instructor has permission for this category
            if (block_exaport_instructor_has_permission('edit', $category->id)) {
                // Check if we're in evidencias context
                $evidencias_param = '';
                if (!empty($category->source) && is_numeric($category->source)) {
                    $evidencias_param = '&evidencias=' . $category->source;
                    error_log("DEBUG VIEW_ITEMS: Category {$category->id} detected as evidencias, source={$category->source}");
                } else {
                    error_log("DEBUG VIEW_ITEMS: Category {$category->id} NOT evidencias, source='{$category->source}'");
                }
                
                $delete_url = $CFG->wwwroot . '/blocks/exaport/category.php?courseid=' . $courseid . '&id=' . $category->id . '&action=delete' . $evidencias_param;
                error_log("DEBUG VIEW_ITEMS: Generated delete URL: {$delete_url}");
                
                $categoryContent .= '<a href="' . $CFG->wwwroot . '/blocks/exaport/category.php?courseid=' . $courseid . '&id=' . $category->id . '&action=edit' . $evidencias_param . '">'
                    // Ícono EDITAR (SVG personalizado)
                    . '<svg id="Capa_1" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 15 15">'
                    . '<path fill="#00304d" d="M11.1,8c-1.2,1.1-2.4,2.6-3.6,3.6-.3.2-.6.4-.9.4-.6,0-1.5.1-2.1.2-.8,0-1.4-.4-1.4-1.2,0-.6,0-1.5.2-2.2,0-.3,0-.6.3-.9L10.4,1c.7-.5,1.4-.5,2.1,0,.5.4,1.4,1.3,1.8,1.8.5.6.5,1.4,0,2-.9,1.1-2.1,2-3.1,3Z"/>'
                    . '<path fill="#00304d" d="M11.3,1.6c-.1,0-.2,0-.3.1-.3.2-.7.7-1,1l2.6,2.5c.4-.5,1.5-1,.9-1.7-.5-.6-1.3-1.2-1.8-1.8-.1,0-.3-.2-.4-.1Z"/>'
                    . '<path fill="#00304d" d="M4,11.2s.2,0,.2,0c.6,0,1.5,0,2.2-.2.1,0,.2,0,.3-.1l5.1-5.1-2.5-2.6-5.1,5.1c-.1.2-.1.7-.2.9,0,.4,0,.9,0,1.4,0,.1,0,.4,0,.4Z"/>'
                    . '<path fill="#00304d" d="M2.4,2.7c1-.1,2.1,0,3.1,0,.5.1.5.8,0,.9-.8.1-2,0-2.8,0-.6,0-1,.5-1.1,1.1v8.1c0,.6.5,1.1,1.2,1.1h8c1.8-.1,1-2.7,1.2-3.9.1-.5.8-.5.9,0,0,1.8.6,4.5-1.9,4.9H2.6c-1,0-1.8-.9-1.9-1.9V4.6c.1-.9.9-1.7,1.8-1.8Z"/>'
                    . '<path fill="#00304d" d="M3.9,11.3c0,0,0-.4,0-.5,0-.4,0-1,.1-1.4,0-.2,0-.8.2-1l5.2-5.2,2.6,2.6-5.2,5.2c-.1,0-.2,0-.3.1-.6,0-1.6.2-2.2.2,0,0-.2,0-.2,0Z"/>'
                    . '<path fill="#00304d" d="M11.4,1.6c.1,0,.3,0,.4.1.5.6,1.4,1.2,1.8,1.8.5.7-.6,1.3-1,1.8l-2.6-2.6c.3-.3.7-.8,1-1,.1,0,.2-.1.3-.1Z"/>'
                    . '</svg>'
                    . '</a>'
                    . '<a href="' . $delete_url . '">'
                    // Ícono ELIMINAR (SVG personalizado)
                    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 15 15" width="18" height="18">' .
                    '<path fill="#00304d" d="M6.01,1.71c.05-.28.38-.59.65-.67.28-.09,1.31-.1,1.59-.01.56.17.76.73.7,1.27h2.72c1.28.06,1.98,1.75.86,2.55-.11.08-.45.17-.47.27-.05.27-.05.66-.07.94-.13,2.37-.13,4.78-.3,7.16-.07.98-.42,1.66-1.5,1.74-1.78.13-3.73-.09-5.52,0-.8-.12-1.31-.65-1.38-1.46-.01-.16,0-.32,0-.47-.1-1.9-.18-3.8-.27-5.7-.04-.75-.04-1.51-.1-2.26-1.6-.48-1.23-2.6.35-2.76h2.75c.02-.18-.03-.42,0-.59ZM8.17,2.3c.04-.29.07-.53-.29-.57-.15-.02-.88-.02-.99.03-.21.09-.13.38-.14.55h1.42ZM12.25,3.95s.04-.11.04-.15c.05-.48-.31-.76-.76-.79H3.43c-.84,0-1.12,1.18-.2,1.32h8.54c.21-.01.33-.15.46-.29l-.07-.08s.07,0,.09,0ZM11.24,5.47l.02-.41-.07-.03c-2,0-4.02-.02-6.01.04-.23,0-.46,0-.69,0-.07.14.17.4.29.4h6.46ZM5.63,12.99c.1-.1.14-.28.15-.41,0-.13,0-4.7,0-5.13,0-.35-.18-.57-.56-.52-.46.05-.35.57-.35.9-.01.52,0,4.89,0,4.89.04.36.51.53.77.27ZM7.32,6.93c-.14.03-.3.24-.29.38v5.4c.06.51.84.55.91-.03v-5.3c-.02-.36-.27-.53-.62-.45ZM9.51,6.93c-.13.03-.21.13-.27.24l-.03,5.54c.06.51.85.55.91-.03v-5.3c-.03-.36-.26-.52-.62-.45Z"/>' .
                    '<path fill="#00304d" d="M2.09,8.23c-.27.25-.6.04-.62-.32-.01-.24-.02-.6,0-.83,0-.1.02-.35.04-.43.07-.33.63-.36.7.1.03.24.03,1,0,1.25,0,.08-.06.19-.11.24Z"/>' .
                    '<path fill="#00304d" d="M12.85,8.72c.27-.07.45.08.48.35.02.21.03.98,0,1.17-.08.39-.65.43-.7-.03-.03-.23-.03-.99,0-1.21.02-.11.11-.25.23-.28Z"/>' .
                    '<path fill="#00304d" d="M10.46,1.09c-.11-.12-.44,0-.39-.29.06-.14.25-.09.35-.16.13-.09.07-.36.21-.4.26-.08.19.24.28.35.09.12.31.04.38.19.12.25-.29.21-.38.31-.1.11-.03.43-.28.35-.13-.04-.1-.28-.17-.36Z"/>' .
                    '<path fill="#00304d" d="M2.36,10.44c.13.13-.11.2-.19.28-.12.11-.15.25-.29.31-.09-.06-.11-.16-.18-.25-.07-.08-.29-.19-.3-.24-.03-.12.18-.16.27-.23.09-.08.12-.22.22-.29.07-.01.19.23.25.28.06.06.19.09.22.13Z"/>' .
                    '<path fill="#00304d" d="M12.7,7.39c-.09-.13.17-.23.23-.28s.14-.26.22-.26c.11.06.13.18.24.27.06.06.11.07.17.1.17.13-.1.21-.18.29-.06.06-.15.25-.22.25-.13-.06-.19-.21-.3-.31-.04-.04-.14-.06-.15-.07Z"/>' .
                    '<path fill="#00304d" d="M3.24.92c.06.2.21.26.35.37.18.13-.13.23-.21.32s-.1.25-.25.22c-.02,0-.09-.15-.12-.19-.06-.07-.27-.21-.27-.27,0-.1.19-.14.26-.22.08-.08.09-.27.25-.23Z"/>' .
                    '</svg>'
                    . '</a>';
            }

        }
        $categoryContent .= '</span>';
    }
    $categoryContent .= '</div>';
    // category thumbnail
    if ($parentcategory) {
        $categoryThumbUrl = $parentcategory->url;
        $categoryName = $parentcategory->name;
        $categoryIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100"><g><path fill="#00304d" d="M85.14,41.11h6.09c1.21,0,3.19,2.36,3.1,3.66v37.7c-.53,5.65-4.89,10.13-10.59,10.6H11.72c-5.55-.62-9.83-4.86-10.41-10.42v-48.38c-.16-1.34,1.86-3.66,3.09-3.66h5.81l.28-.28v-15.94c0-2.05,3.31-4.62,5.35-4.59h63.58c1.94.09,4.05,1.14,5,2.87.12.21.71,1.63.71,1.73v26.72ZM82.14,19.73v-4.97c0-.52-1.39-1.91-2.05-1.89l-63.87-.09c-.88-.22-2.71,1.22-2.71,1.98v4.97h68.64ZM82.14,22.73H13.5v1.5h68.64v-1.5ZM82.14,27.04H13.5v3.56h14.72l.76.56,8.62,9.95h44.54v-14.06ZM5.01,33.65l-.71.98v47.83c.44,4.02,3.75,7.29,7.79,7.6h71.46c4.1-.32,7.46-3.69,7.78-7.78l-.1-37.6-.47-.47-54.09-.11-.66-.28-8.95-10.18H5.01Z"/><rect fill="#39a900" x="13.5" y="22.73" width="68.64" height="1.5"/></g><g><path fill="#39a900" d="M84.44,62.99l1.77.2c6.96,1.04,12.56,6.81,13.41,13.79l.13,1.4c-.02.41.02.84,0,1.25-.68,13.53-16.71,20.4-26.79,11.2-10.55-9.63-4.09-27.08,10.1-27.83h1.38ZM78.75,76.94h8.1c.67,0,1.75.55,2.25,1,2.34,2.07,1.34,6.08-1.71,6.74-.57.12-1.26,0-1.7.42-.63.6-.37,1.78.45,1.99,1.21.31,3.14-.46,4.11-1.18,4.71-3.53,2.41-10.98-3.46-11.35h-8.04c.39-.6,1.85-1.58,1.99-2.23.23-1.11-.94-1.88-1.86-1.24l-3.97,3.97c-.37.47-.27,1.14.13,1.57,1.32,1.11,2.52,2.79,3.85,3.85s2.68-.42,1.75-1.63c-.51-.66-1.36-1.23-1.87-1.91Z"/><path fill="#ffffff" d="M78.75,76.94c.51.68,1.37,1.25,1.87,1.91.93,1.2-.49,2.63-1.75,1.63-1.32-1.05-2.53-2.74-3.85-3.85-.39-.43-.5-1.09-.13-1.57l3.97-3.97c.92-.64,2.09.13,1.86,1.24-.13.65-1.6,1.63-1.99,2.23h8.04c5.87.37,8.18,7.82,3.46,11.35-.97.72-2.9,1.5-4.11,1.18-.82-.21-1.08-1.39-.45-1.99.44-.43,1.13-.3,1.7-.42,3.05-.66,4.05-4.66,1.71-6.74-.5-.44-1.58-1-2.25-1h-8.1Z"/></g></svg>';
    } else {
        $categoryThumbUrl = $category->url;
        $categoryName = $category->name;
        if ($category->icon) {
            if (isset($category->iconmerge) && $category->iconmerge) {
                // icon merge (also look JS - exaport.js - block_exaport_check_fontawesome_icon_merging()):
                $categoryIcon = block_exaport_fontawesome_icon('folder-open', 'regular', '6', ['icon-for-merging'], [], ['data-categoryId' => $category->id], '', [], [], [], ['exaport-items-category-big']);
                $categoryIcon .= '<img id="mergeImageIntoCategory' . $category->id . '" src="' . $category->icon . '?tcacheremove=' . date('dmYhis') . '" style="display:none;">';
                $categoryIcon .= '<canvas id="mergedCanvas' . $category->id . '" class="category-merged-icon" width="115" height="115" style="display: none;"></canvas>';
            } else if (strpos($category->icon, '<svg') === 0) {
                // Direct SVG code:
                $categoryIcon = $category->icon;
            } else if (strpos($category->icon, 'fa-') === 0) {
                // FontAwesome icon - convert to FontAwesome HTML
                $iconName = str_replace('fa-', '', $category->icon);
                $categoryIcon = block_exaport_fontawesome_icon($iconName, 'regular', '6', [], [], [], '', [], [], [], ['exaport-items-category-big']);
            } else {
                // just picture instead of folder icon:
                $categoryIcon = '<img src="' . $category->icon . '">';
            }
        } else {
            $categoryIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">' .
                '<g>' .
                '<path fill="#00304d" d="M90.1,38.9h6.6c1.3,0,3.4,2.5,3.3,3.9v40.5c-.6,6.1-5.3,10.9-11.4,11.4H11.2c-6-.7-10.6-5.2-11.2-11.2V31.6c-.2-1.4,2-3.9,3.3-3.9h6.2l.3-.3V10.2c0-2.2,3.6-5,5.7-4.9h68.3c2.1.1,4.4,1.2,5.4,3.1s.8,1.8.8,1.9v28.7ZM86.9,15.9v-5.3c0-.6-1.5-2.1-2.2-2H16c-.9-.3-2.9,1.2-2.9,2v5.3h73.8ZM86.9,19.2H13.1v1.6h73.8v-1.6ZM86.9,23.8H13.1v3.8h15.8l.8.6,9.3,10.7h47.9v-15.1ZM4,30.9l-.8,1.1v51.4c.5,4.3,4,7.8,8.4,8.2h76.8c4.4-.3,8-4,8.4-8.4v-40.4c-.1,0-.6-.5-.6-.5h-58.1c0-.1-.7-.4-.7-.4l-9.6-10.9H4Z"/>' .
                '<rect fill="#39a900" x="13.1" y="19.2" width="73.8" height="1.6"/>' .
                '</g>' .
                '<g>' .
                '<path fill="#39a900" d="M50.3,45.9l4.7.9c1.2,0,1.3-.1,2.3-.5s1.7,1.2,1.9,2.2c.4,2,0,2.9-.3,4.7s0,2.4-.2,3.6c0,.3.3.3.4.5.8,1.4.7,3.1-.7,4.1s-1.3.5-2,.7c-.2,1.8-1.3,3.1-2.5,4.2v1.4s8.3,3.3,8.3,3.3c3.4,1.6,3.7,5.1,3.4,8.5-.2.7-1.1.6-1.2,0-.2-1.4.2-2.9-.2-4.4s-1.6-2.5-3.2-3.1-3-1.1-4.5-1.7l-3.1,2.6c-.4.3-.8.2-1.2.1l-.4.7,1.3,13h6.4v-10.8c0-.3.7-.5.9-.3s.3.3.3.4v10.7h3.4c.1,0,.3-.3.3-.4.2-1.2-.1-2.7,0-4,.2-.7,1.1-.6,1.2,0s0,3.4,0,4.2-.7,1.2-1.3,1.4h-19.3c-.7.1-.9-.9-.3-1.1s2.1,0,2.2,0l1.4-13-.4-.7c-.4.1-.9.2-1.2-.1l-3.2-2.6-5.5,2.2c-1.4.8-2.3,2.3-2.4,3.9-.2,3.1.2,6.6,0,9.7,0,.1.2.5.3.5h3.4v-10.7c0-.4,1.1-.8,1.2,0v10.7h1.9s.3.3.3.3c.2.4,0,.8-.4.9-2-.1-4.3.2-6.4,0s-1.3-.6-1.6-1.3c.2-3.4-.3-7.2,0-10.5s1.7-4,3.5-4.9l8.3-3.2v-1.5c-1.3-1.1-2.3-2.5-2.6-4.3-2.3.1-3.9-2.3-2.8-4.4s.4-.5.5-.6c.1-.3,0-2.1,0-2.6,0-1.5-.6-3.6,1-4.5s.9-.3,1.1-.5.6-1,.8-1.3c1-1.4,2.7-2.1,4.4-2.3h1.9ZM57.7,47.6c-2.2,1.1-4.5-.1-6.8-.4s-4.3-.1-5.6,1.5-.6,1.5-1.7,2.1-.5.1-.7.3c-.8.5-.4,2.1-.4,2.9s0,1.5,0,2.2h1c-.2-1.8.7-3.1,2.6-2.9s3.4.5,5.5.4,3-.8,4-.1,1.1,1.5,1,2.6h1c0,0,.2-3.1,.2-3.1.5-1.8.6-3.6,0-5.4ZM45.4,54.5c-.4,0-.6.4-.7.8.2,3.5-1.1,7.6,2.3,10s6.6.8,7.8-2.2.6-5.9.5-7.6-.3-1-.9-1-1.8.3-2.5.4c-1.5.1-3,0-4.5,0s-1.5-.4-1.9-.3ZM43.5,57.3c-2.5,0-2.5,3.4,0,3.4v-3.4ZM56.5,60.8c2.5,0,2.5-3.5,0-3.4v3.4ZM52.6,67c-1.7.7-3.5.6-5.2,0,0,.7-.2.9.3,1.3s1.3,1.1,1.5,1.3,1.3.1,1.5,0,1.4-1.2,1.7-1.4.1,0,.2,0v-1.1ZM47.4,71.7l.8-1.3c0-.2-1.4-1.3-1.7-1.5l-1.7.6v.2c0,0,2.5,2,2.5,2ZM55.1,69.6c-.4,0-1.4-.6-1.7-.6s-1.6,1.3-1.6,1.4c.3.4.5.8.8,1.2s0,.1.1.1c.7-.7,1.6-1.3,2.3-1.9s.1,0,0-.2Z"/>' .
                '</g>' .
                '</svg>';
        }
    }
    $categoryContent .= '<div class="excomdos_tileimage">';
    $categoryContent .= '<a href="' . $categoryThumbUrl . '">';
    $categoryContent .= $categoryIcon;
    $categoryContent .= '</a>
        </div>
        <div class="exomdos_tiletitle">
            <a href="' . $categoryThumbUrl . '">' . $categoryName . '</a>
        </div>
    </div>';

    return $categoryContent;
}

function block_exaport_artefact_template_tile($item, $courseid, $type, $categoryid, $currentcategory) {
    global $CFG, $USER, $DB;
    $itemContent = '';

    // Check if this is an evidencias file - if so, use download endpoint
    $isEvidenciasFile = false;
    if ($item->type == 'file' && !empty($item->categoryid)) {
        $category = $DB->get_record('block_exaportcate', array('id' => $item->categoryid));
        if ($category && !empty($category->source) && is_numeric($category->source)) {
            $isEvidenciasFile = true;
        }
    }
    
    if ($isEvidenciasFile) {
        $url = $CFG->wwwroot . '/blocks/exaport/download_file.php?courseid=' . $courseid . '&itemid=' . $item->id;
    } else {
        $url = $CFG->wwwroot . '/blocks/exaport/shared_item.php?courseid=' . $courseid . '&access=portfolio/id/' . $item->userid . '&itemid=' . $item->id;
    }
    $itemContent .= '
        <div class="excomdos_tile excomdos_tile_item id-' . $item->id . '">
            <div class="excomdos_tilehead">
                    <span class="excomdos_tileinfo">';
    $iconTypeProps = block_exaport_item_icon_type_options($item->type);
    // Artefact type.
    $itemContent .= '<span class="excomdos_tileinfo_type">'
        . block_exaport_fontawesome_icon($iconTypeProps['iconName'], $iconTypeProps['iconStyle'], 1, ['artefact_icon'])
        . '<span class="type_title">'
        . get_string($item->type, "block_exaport")
        . '</span></span>';
    $itemContent .= '
        <br><span class="excomdos_tileinfo_time">' . userdate($item->timemodified) . '</span>
                </span>
            <span class="excomdos_tileedit">';

    if ($currentcategory->id == -1) {
        // Link to export to portfolio.
        $itemContent .= ' <a href="' . $CFG->wwwroot . '/blocks/exaport/item.php?courseid=' . $courseid . '&id=' . $item->id . '&sesskey=' .
            sesskey() . '&action=copytoself' . '"><img src="pix/import.png" title="' .
            get_string('make_it_yours', "block_exaport") . '"></a>';
    } else {
        if ($item->comments > 0) {
            $itemContent .= ' <span class="excomdos_listcomments">' . $item->comments
                . block_exaport_fontawesome_icon('comment', 'regular', 1, [], [], [], '', [], [], [], [])
                //                                    .'<img src="pix/comments.png" alt="file">'
                . '</span>';
        }
        $itemContent .= block_exaport_get_item_project_icon($item);
        $itemContent .= block_exaport_get_item_comp_icon($item);

        if (in_array($type, ['mine', 'shared'])) {
            $cattype = '';
            if ($type == 'shared') {
                $cattype = '&cattype=shared';
            }
            // Use new evidencias-aware permission system for edit
            if (block_exaport_user_can_edit_item($item, $courseid)) {
                $itemContent .= '<a href="' . $CFG->wwwroot . '/blocks/exaport/item.php?courseid=' . $courseid . '&id=' . $item->id .
                    '&action=edit' . $cattype . '">'
                    . '<svg id="Capa_1" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 15 15">'
                    . '<path fill="#00304d" d="M11.1,8c-1.2,1.1-2.4,2.6-3.6,3.6-.3.2-.6.4-.9.4-.6,0-1.5.1-2.1.2-.8,0-1.4-.4-1.4-1.2,0-.6,0-1.5.2-2.2,0-.3,0-.6.3-.9L10.4,1c.7-.5,1.4-.5,2.1,0,.5.4,1.4,1.3,1.8,1.8.5.6.5,1.4,0,2-.9,1.1-2.1,2-3.1,3Z"/>'
                    . '<path fill="#00304d" d="M11.3,1.6c-.1,0-.2,0-.3.1-.3.2-.7.7-1,1l2.6,2.5c.4-.5,1.5-1,.9-1.7-.5-.6-1.3-1.2-1.8-1.8-.1,0-.3-.2-.4-.1Z"/>'
                    . '<path fill="#00304d" d="M4,11.2s.2,0,.2,0c.6,0,1.5,0,2.2-.2.1,0,.2,0,.3-.1l5.1-5.1-2.5-2.6-5.1,5.1c-.1.2-.1.7-.2.9,0,.4,0,.9,0,1.4,0,.1,0,.4,0,.4Z"/>'
                    . '<path fill="#00304d" d="M2.4,2.7c1-.1,2.1,0,3.1,0,.5.1.5.8,0,.9-.8.1-2,0-2.8,0-.6,0-1,.5-1.1,1.1v8.1c0,.6.5,1.1,1.2,1.1h8c1.8-.1,1-2.7,1.2-3.9.1-.5.8-.5.9,0,0,1.8.6,4.5-1.9,4.9H2.6c-1,0-1.8-.9-1.9-1.9V4.6c.1-.9.9-1.7,1.8-1.8Z"/>'
                    . '<path fill="#00304d" d="M3.9,11.3c0,0,0-.4,0-.5,0-.4,0-1,.1-1.4,0-.2,0-.8.2-1l5.2-5.2,2.6,2.6-5.2,5.2c-.1,0-.2,0-.3.1-.6,0-1.6.2-2.2.2,0,0-.2,0-.2,0Z"/>'
                    . '<path fill="#00304d" d="M11.4,1.6c.1,0,.3,0,.4.1.5.6,1.4,1.2,1.8,1.8.5.7-.6,1.3-1,1.8l-2.6-2.6c.3-.3.7-.8,1-1,.1,0,.2-.1.3-.1Z"/>'
                    . '</svg>'
                    . '</a>';
            }
            
            // Use new evidencias-aware permission system for delete
            if (block_exaport_user_can_delete_item($item, $courseid)) {
                if ($allowedit = block_exaport_item_is_editable($item->id)) {
                    $itemContent .= '<a href="' . $CFG->wwwroot . '/blocks/exaport/item.php?courseid=' . $courseid . '&id=' . $item->id . '&action=delete&categoryid=' . $categoryid . $cattype . '" class="item_delete_icon">'
                        . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 15 15" width="18" height="18">' .
                        '<path fill="#00304d" d="M6.01,1.71c.05-.28.38-.59.65-.67.28-.09,1.31-.1,1.59-.01.56.17.76.73.7,1.27h2.72c1.28.06,1.98,1.75.86,2.55-.11.08-.45.17-.47.27-.05.27-.05.66-.07.94-.13,2.37-.13,4.78-.3,7.16-.07.98-.42,1.66-1.5,1.74-1.78.13-3.73-.09-5.52,0-.8-.12-1.31-.65-1.38-1.46-.01-.16,0-.32,0-.47-.1-1.9-.18-3.8-.27-5.7-.04-.75-.04-1.51-.1-2.26-1.6-.48-1.23-2.6.35-2.76h2.75c.02-.18-.03-.42,0-.59ZM8.17,2.3c.04-.29.07-.53-.29-.57-.15-.02-.88-.02-.99.03-.21.09-.13.38-.14.55h1.42ZM12.25,3.95s.04-.11.04-.15c.05-.48-.31-.76-.76-.79H3.43c-.84,0-1.12,1.18-.2,1.32h8.54c.21-.01.33-.15.46-.29l-.07-.08s.07,0,.09,0ZM11.24,5.47l.02-.41-.07-.03c-2,0-4.02-.02-6.01.04-.23,0-.46,0-.69,0-.07.14.17.4.29.4h6.46ZM5.63,12.99c.1-.1.14-.28.15-.41,0-.13,0-4.7,0-5.13,0-.35-.18-.57-.56-.52-.46.05-.35.57-.35.9-.01.52,0,4.89,0,4.89.04.36.51.53.77.27ZM7.32,6.93c-.14.03-.3.24-.29.38v5.4c.06.51.84.55.91-.03v-5.3c-.02-.36-.27-.53-.62-.45ZM9.51,6.93c-.13.03-.21.13-.27.24l-.03,5.54c.06.51.85.55.91-.03v-5.3c-.03-.36-.26-.52-.62-.45Z"/>' .
                        '<path fill="#00304d" d="M2.09,8.23c-.27.25-.6.04-.62-.32-.01-.24-.02-.6,0-.83,0-.1.02-.35.04-.43.07-.33.63-.36.7.1.03.24.03,1,0,1.25,0,.08-.06.19-.11.24Z"/>' .
                        '<path fill="#00304d" d="M12.85,8.72c.27-.07.45.08.48.35.02.21.03.98,0,1.17-.08.39-.65.43-.7-.03-.03-.23-.03-.99,0-1.21.02-.11.11-.25.23-.28Z"/>' .
                        '<path fill="#00304d" d="M10.46,1.09c-.11-.12-.44,0-.39-.29.06-.14.25-.09.35-.16.13-.09.07-.36.21-.4.26-.08.19.24.28.35.09.12.31.04.38.19.12.25-.29.21-.38.31-.1.11-.03.43-.28.35-.13-.04-.1-.28-.17-.36Z"/>' .
                        '<path fill="#00304d" d="M2.36,10.44c.13.13-.11.2-.19.28-.12.11-.15.25-.29.31-.09-.06-.11-.16-.18-.25-.07-.08-.29-.19-.3-.24-.03-.12.18-.16.27-.23.09-.08.12-.22.22-.29.07-.01.19.23.25.28.06.06.19.09.22.13Z"/>' .
                        '<path fill="#00304d" d="M12.7,7.39c-.09-.13.17-.23.23-.28s.14-.26.22-.26c.11.06.13.18.24.27.06.06.11.07.17.1.17.13-.1.21-.18.29-.06.06-.15.25-.22.25-.13-.06-.19-.21-.3-.31-.04-.04-.14-.06-.15-.07Z"/>' .
                        '<path fill="#00304d" d="M3.24.92c.06.2.21.26.35.37.18.13-.13.23-.21.32s-.1.25-.25.22c-.02,0-.09-.15-.12-.19-.06-.07-.27-.21-.27-.27,0-.1.19-.14.26-.22.08-.08.09-.27.25-.23Z"/>' .
                        '</svg>'
                        . '</a>';
                }
            } else if (!$allowedit = block_exaport_item_is_editable($item->id)) {
                $itemContent .= '<img src="pix/deleteview.png" alt="file">';
            }
            if ($item->userid != $USER->id) {
                $itemuser = $DB->get_record('user', ['id' => $item->userid]);
                // user icon
                $itemContent .= '<a class="" role="button" data-container="body"
                            title="' . fullname($itemuser) . '">'
                    . block_exaport_fontawesome_icon('circle-user', 'solid', 1)
                    . '</a>';
            }
        }
    }

    $itemContent .= '
                </span>
        </div>
        <div class="excomdos_tileimage">
            <a href="' . $url . '"><img alt="' . $item->name . '" title="' . $item->name . '"
                                    src="' . $CFG->wwwroot . '/blocks/exaport/item_thumb.php?item_id=' . $item->id . '"/></a>
        </div>
        <div class="exomdos_tiletitle">
            <a href="' . $url . '">' . $item->name . '</a>
        </div>
    </div>';

    return $itemContent;
}

/**
 * Different templates of category list. Depends on exaport settings
 */
function block_exaport_category_list_item($category, $courseid, $type, $currentcategory, $parentcategory = null) {
    $template = block_exaport_used_layout();
    switch ($template) {
        case 'moodle_bootstrap':
            return block_exaport_category_template_bootstrap_card($category, $courseid, $type, $currentcategory, $parentcategory);
            break;
        case 'exaport_bootstrap': // may we do not need this at all?
            return '<div>TODO: !!!!!! ' . $template . ' category !!!!!!!</div>';
            break;
        case 'clean_old':
            return block_exaport_category_template_tile($category, $courseid, $type, $currentcategory, $parentcategory);
            break;
    }
    return 'something wrong!! (code: 1716992027125)';

}

/**
 * Different templates of artefact list. Depends on exaport settings
 */
function block_exaport_artefact_list_item($item, $courseid, $type, $categoryid, $currentcategory) {
    $template = block_exaport_used_layout();
    switch ($template) {
        case 'moodle_bootstrap':
            return block_exaport_artefact_template_bootstrap_card($item, $courseid, $type, $categoryid, $currentcategory);
            break;
        case 'exaport_bootstrap': // may we do not need this at all?
            return '<div>TODO: !!!!!! ' . $template . ' !!!!!!!</div>';
            break;
        case 'clean_old':
            return block_exaport_artefact_template_tile($item, $courseid, $type, $categoryid, $currentcategory);
            break;
    }
    return 'something wrong!! (code: 1716990501476)';

}

function block_exaport_category_template_bootstrap_card($category, $courseid, $type, $currentcategory, $parentcategory = null) {
    global $CFG;
    $categoryContent = '';

    // Add special CSS class for course folders and sections
    $cardClasses = 'card h-100 excomdos_tile excomdos_tile_category id-' . $category->id;
    if (isset($category->type) && $category->type === 'course_folder') {
        $cardClasses .= ' exaport-course-folder-card';
    } else if (isset($category->type) && $category->type === 'course_section') {
        $cardClasses .= ' exaport-course-section-card';
    }

    $categoryContent .= '
    <div class="col mb-4">
                <div class="' . $cardClasses . ' " style="border: 1px solid #38a900a6;">
					<div class="card-header excomdos_tilehead d-flex justify-content-between">
						<span class="excomdos_tileinfo">
							';
    if ($parentcategory) {
        $categoryContent .= block_exaport_get_string('category_up');
    } elseif ($currentcategory->id == -1) {
        $categoryContent .= block_exaport_get_string('user');
    } else {
        $categoryContent .= block_exaport_get_string('category');
    }
    $categoryContent .= '</span>';
    // edit buttons
    if (!$parentcategory) {
        if ($type == 'shared' || $type == 'sharedstudent') {
            $categoryContent .= block_exaport_fontawesome_icon('handshake', 'regular', 1);
        } else {
            // Type == mine.
            if (@$category->internshare && (count(exaport_get_category_shared_users($category->id)) > 0 ||
                    count(exaport_get_category_shared_groups($category->id)) > 0 ||
                    (isset($category->shareall) && $category->shareall == 1))) {
                $categoryContent .= block_exaport_fontawesome_icon('handshake', 'regular', 1);
            };
            /*if (@$category->structure_share) {
                $categoryContent .= ' <img src="pix/sharedfolder.png" title="shared to other users as a structure">';
            };*/
            // Only show edit/delete buttons if instructor has permission for this category
            if (block_exaport_instructor_has_permission('edit', $category->id)) {
                // Check if we're in evidencias context
                $evidencias_param = '';
                if (!empty($category->source) && is_numeric($category->source)) {
                    $evidencias_param = '&evidencias=' . $category->source;
                }
                
                $categoryContent .= '
						<span class="excomdos_tileedit">
							<a href="' . $CFG->wwwroot . '/blocks/exaport/category.php?courseid=' . $courseid . '&id=' . $category->id . '&action=edit' . $evidencias_param . '">'
                    . '<svg id="Capa_1" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 15 15">'
                    . '<path fill="#00304d" d="M11.1,8c-1.2,1.1-2.4,2.6-3.6,3.6-.3.2-.6.4-.9.4-.6,0-1.5.1-2.1.2-.8,0-1.4-.4-1.4-1.2,0-.6,0-1.5.2-2.2,0-.3,0-.6.3-.9L10.4,1c.7-.5,1.4-.5,2.1,0,.5.4,1.4,1.3,1.8,1.8.5.6.5,1.4,0,2-.9,1.1-2.1,2-3.1,3Z"/>'
                    . '<path fill="#00304d" d="M11.3,1.6c-.1,0-.2,0-.3.1-.3.2-.7.7-1,1l2.6,2.5c.4-.5,1.5-1,.9-1.7-.5-.6-1.3-1.2-1.8-1.8-.1,0-.3-.2-.4-.1Z"/>'
                    . '<path fill="#00304d" d="M4,11.2s.2,0,.2,0c.6,0,1.5,0,2.2-.2.1,0,.2,0,.3-.1l5.1-5.1-2.5-2.6-5.1,5.1c-.1.2-.1.7-.2.9,0,.4,0,.9,0,1.4,0,.1,0,.4,0,.4Z"/>'
                    . '<path fill="#00304d" d="M2.4,2.7c1-.1,2.1,0,3.1,0,.5.1.5.8,0,.9-.8.1-2,0-2.8,0-.6,0-1,.5-1.1,1.1v8.1c0,.6.5,1.1,1.2,1.1h8c1.8-.1,1-2.7,1.2-3.9.1-.5.8-.5.9,0,0,1.8.6,4.5-1.9,4.9H2.6c-1,0-1.8-.9-1.9-1.9V4.6c.1-.9.9-1.7,1.8-1.8Z"/>'
                    . '<path fill="#00304d" d="M3.9,11.3c0,0,0-.4,0-.5,0-.4,0-1,.1-1.4,0-.2,0-.8.2-1l5.2-5.2,2.6,2.6-5.2,5.2c-.1,0-.2,0-.3.1-.6,0-1.6.2-2.2.2,0,0-.2,0-.2,0Z"/>'
                    . '<path fill="#00304d" d="M11.4,1.6c.1,0,.3,0,.4.1.5.6,1.4,1.2,1.8,1.8.5.7-.6,1.3-1,1.8l-2.6-2.6c.3-.3.7-.8,1-1,.1,0,.2-.1.3-.1Z"/>'
                    . '</svg>'
                    . '</a>
							<a href="' . $CFG->wwwroot . '/blocks/exaport/category.php?courseid=' . $courseid . '&id=' . $category->id . '&action=delete' . $evidencias_param . '">'
                    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 15 15" width="18" height="18">' .
                    '<path fill="#00304d" d="M6.01,1.71c.05-.28.38-.59.65-.67.28-.09,1.31-.1,1.59-.01.56.17.76.73.7,1.27h2.72c1.28.06,1.98,1.75.86,2.55-.11.08-.45.17-.47.27-.05.27-.05.66-.07.94-.13,2.37-.13,4.78-.3,7.16-.07.98-.42,1.66-1.5,1.74-1.78.13-3.73-.09-5.52,0-.8-.12-1.31-.65-1.38-1.46-.01-.16,0-.32,0-.47-.1-1.9-.18-3.8-.27-5.7-.04-.75-.04-1.51-.1-2.26-1.6-.48-1.23-2.6.35-2.76h2.75c.02-.18-.03-.42,0-.59ZM8.17,2.3c.04-.29.07-.53-.29-.57-.15-.02-.88-.02-.99.03-.21.09-.13.38-.14.55h1.42ZM12.25,3.95s.04-.11.04-.15c.05-.48-.31-.76-.76-.79H3.43c-.84,0-1.12,1.18-.2,1.32h8.54c.21-.01.33-.15.46-.29l-.07-.08s.07,0,.09,0ZM11.24,5.47l.02-.41-.07-.03c-2,0-4.02-.02-6.01.04-.23,0-.46,0-.69,0-.07.14.17.4.29.4h6.46ZM5.63,12.99c.1-.1.14-.28.15-.41,0-.13,0-4.7,0-5.13,0-.35-.18-.57-.56-.52-.46.05-.35.57-.35.9-.01.52,0,4.89,0,4.89.04.36.51.53.77.27ZM7.32,6.93c-.14.03-.3.24-.29.38v5.4c.06.51.84.55.91-.03v-5.3c-.02-.36-.27-.53-.62-.45ZM9.51,6.93c-.13.03-.21.13-.27.24l-.03,5.54c.06.51.85.55.91-.03v-5.3c-.03-.36-.26-.52-.62-.45Z"/>' .
                    '<path fill="#00304d" d="M2.09,8.23c-.27.25-.6.04-.62-.32-.01-.24-.02-.6,0-.83,0-.1.02-.35.04-.43.07-.33.63-.36.7.1.03.24.03,1,0,1.25,0,.08-.06.19-.11.24Z"/>' .
                    '<path fill="#00304d" d="M12.85,8.72c.27-.07.45.08.48.35.02.21.03.98,0,1.17-.08.39-.65.43-.7-.03-.03-.23-.03-.99,0-1.21.02-.11.11-.25.23-.28Z"/>' .
                    '<path fill="#00304d" d="M10.46,1.09c-.11-.12-.44,0-.39-.29.06-.14.25-.09.35-.16.13-.09.07-.36.21-.4.26-.08.19.24.28.35.09.12.31.04.38.19.12.25-.29.21-.38.31-.1.11-.03.43-.28.35-.13-.04-.1-.28-.17-.36Z"/>' .
                    '<path fill="#00304d" d="M2.36,10.44c.13.13-.11.2-.19.28-.12.11-.15.25-.29.31-.09-.06-.11-.16-.18-.25-.07-.08-.29-.19-.3-.24-.03-.12.18-.16.27-.23.09-.08.12-.22.22-.29.07-.01.19.23.25.28.06.06.19.09.22.13Z"/>' .
                    '<path fill="#00304d" d="M12.7,7.39c-.09-.13.17-.23.23-.28s.14-.26.22-.26c.11.06.13.18.24.27.06.06.11.07.17.1.17.13-.1.21-.18.29-.06.06-.15.25-.22.25-.13-.06-.19-.21-.3-.31-.04-.04-.14-.06-.15-.07Z"/>' .
                    '<path fill="#00304d" d="M3.24.92c.06.2.21.26.35.37.18.13-.13.23-.21.32s-.1.25-.25.22c-.02,0-.09-.15-.12-.19-.06-.07-.27-.21-.27-.27,0-.1.19-.14.26-.22.08-.08.09-.27.25-.23Z"/>' .
                    '</svg>'
                    . '</a>
						</span>';
            }
        }
    }
    if ($parentcategory) {
        $categoryThumbUrl = $parentcategory->url;
        $categoryName = $parentcategory->name;
        $categoryIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100"><g><path fill="#00304d" d="M85.14,41.11h6.09c1.21,0,3.19,2.36,3.1,3.66v37.7c-.53,5.65-4.89,10.13-10.59,10.6H11.72c-5.55-.62-9.83-4.86-10.41-10.42v-48.38c-.16-1.34,1.86-3.66,3.09-3.66h5.81l.28-.28v-15.94c0-2.05,3.31-4.62,5.35-4.59h63.58c1.94.09,4.05,1.14,5,2.87.12.21.71,1.63.71,1.73v26.72ZM82.14,19.73v-4.97c0-.52-1.39-1.91-2.05-1.89l-63.87-.09c-.88-.22-2.71,1.22-2.71,1.98v4.97h68.64ZM82.14,22.73H13.5v1.5h68.64v-1.5ZM82.14,27.04H13.5v3.56h14.72l.76.56,8.62,9.95h44.54v-14.06ZM5.01,33.65l-.71.98v47.83c.44,4.02,3.75,7.29,7.79,7.6h71.46c4.1-.32,7.46-3.69,7.78-7.78l-.1-37.6-.47-.47-54.09-.11-.66-.28-8.95-10.18H5.01Z"/><rect fill="#39a900" x="13.5" y="22.73" width="68.64" height="1.5"/></g><g><path fill="#39a900" d="M84.44,62.99l1.77.2c6.96,1.04,12.56,6.81,13.41,13.79l.13,1.4c-.02.41.02.84,0,1.25-.68,13.53-16.71,20.4-26.79,11.2-10.55-9.63-4.09-27.08,10.1-27.83h1.38ZM78.75,76.94h8.1c.67,0,1.75.55,2.25,1,2.34,2.07,1.34,6.08-1.71,6.74-.57.12-1.26,0-1.7.42-.63.6-.37,1.78.45,1.99,1.21.31,3.14-.46,4.11-1.18,4.71-3.53,2.41-10.98-3.46-11.35h-8.04c.39-.6,1.85-1.58,1.99-2.23.23-1.11-.94-1.88-1.86-1.24l-3.97,3.97c-.37.47-.27,1.14.13,1.57,1.32,1.11,2.52,2.79,3.85,3.85s2.68-.42,1.75-1.63c-.51-.66-1.36-1.23-1.87-1.91Z"/><path fill="#ffffff" d="M78.75,76.94c.51.68,1.37,1.25,1.87,1.91.93,1.2-.49,2.63-1.75,1.63-1.32-1.05-2.53-2.74-3.85-3.85-.39-.43-.5-1.09-.13-1.57l3.97-3.97c.92-.64,2.09.13,1.86,1.24-.13.65-1.6,1.63-1.99,2.23h8.04c5.87.37,8.18,7.82,3.46,11.35-.97.72-2.9,1.5-4.11,1.18-.82-.21-1.08-1.39-.45-1.99.44-.43,1.13-.3,1.7-.42,3.05-.66,4.05-4.66,1.71-6.74-.5-.44-1.58-1-2.25-1h-8.1Z"/></g></svg>';
    } else {
        $categoryThumbUrl = $category->url;
        $categoryName = $category->name;
        
        // Special handling for course folders and sections
        if (isset($category->type) && $category->type === 'course_folder') {
            $categoryIcon = '<div class="course-folder-icon-large">'
                . '<svg id="Capa_1" xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">'
                . '<path fill="#00304d" d="M90.1,38.9h6.6c1.3,0,3.4,2.5,3.3,3.9v40.5c-.6,6.1-5.2,10.9-11.4,11.4H11.2c-6-.7-10.6-5.2-11.2-11.2V31.6c-.2-1.4,2-3.9,3.3-3.9h6.2l.3-.3V10.2c0-2.2,3.6-5,5.8-4.9h68.3c2.1,0,4.3,1.2,5.4,3.1.1.2.8,1.8.8,1.9v28.7h0ZM86.9,15.9v-5.3c0-.6-1.5-2.1-2.2-2H16c-.9-.3-2.9,1.2-2.9,2v5.3h73.8ZM86.9,19.2H13.1v1.6h73.8v-1.6ZM86.9,23.8H13.1v3.8h15.8l.8.6,9.3,10.7h47.9v-15.1h0ZM4,30.9l-.8,1.1v51.4c.5,4.3,4,7.8,8.4,8.2h76.8c4.4-.3,8-4,8.4-8.4v-40.4c-.1,0-.6-.5-.6-.5h-58.1c0-.1-.7-.4-.7-.4l-9.6-10.9H4Z"/>'
                . '<rect fill="#39a900" x="13.1" y="19.2" width="73.8" height="1.6"/>'
                . '</svg>'
                . '</div>';
            $categoryName = $category->name; // Full course name
        } else if (isset($category->type) && $category->type === 'course_section') {
            $categoryIcon = '<div class="course-section-icon-large">' . 
                           '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">' .
                           '<g>' .
                           '<path fill="#00304d" d="M90.1,38.9h6.6c1.3,0,3.4,2.5,3.3,3.9v40.5c-.6,6.1-5.3,10.9-11.4,11.4H11.2c-6-.7-10.6-5.2-11.2-11.2V31.6c-.2-1.4,2-3.9,3.3-3.9h6.2l.3-.3V10.2c0-2.2,3.6-5,5.7-4.9h68.3c2.1.1,4.4,1.2,5.4,3.1s.8,1.8.8,1.9v28.7ZM86.9,15.9v-5.3c0-.6-1.5-2.1-2.2-2H16c-.9-.3-2.9,1.2-2.9,2v5.3h73.8ZM86.9,19.2H13.1v1.6h73.8v-1.6ZM86.9,23.8H13.1v3.8h15.8l.8.6,9.3,10.7h47.9v-15.1ZM4,30.9l-.8,1.1v51.4c.5,4.3,4,7.8,8.4,8.2h76.8c4.4-.3,8-4,8.4-8.4v-40.4c-.1,0-.6-.5-.6-.5h-58.1c0-.1-.7-.4-.7-.4l-9.6-10.9H4Z"/>' .
                           '<rect fill="#39a900" x="13.1" y="19.2" width="73.8" height="1.6"/>' .
                           '</g>' .
                           '<g>' .
                           '<path fill="#39a900" d="M23.4,86.7c.3-4.5.6-10.3,5-12.8s5.2-2,5.4-2.7c.5-1.5-.9-2-1.6-3s-1.2-1.6-1.5-2.4-.6-2.9-.9-3.3-1.6-.6-2.2-2,0-2.3.2-3.6,0-2.9,0-4.3c.6-4.5,4.6-7.4,9.1-6.6s2.1,1.1,3.2,1.2c1.7.2,2.4-.5,4.4.3,4.6,1.7,3.3,5.4,3.7,9s1.4,2.3.4,4.3-2,1.4-2.1,1.6-.2,1.6-.4,2.1c-.6,1.9-1.4,2.9-2.5,4.5s-1.6,1.1-1.3,2.3,5.4,2.5,6.3,3.1c3.9,2.7,4,9.2,4.1,13.5h-28.4s-.9-.5-.9-.5c0-.2,0-.3,0-.5ZM29.4,51.8c-.2,1.2.2,3,0,4.3,1.2,0,.7-1.8,1.3-2.2s1.9-.4,2.6-.8c1.1-.5,1.5-1.4,2.5-1.9.9.5,1.4,1.2,2.3,1.7,2.4,1.3,4.7,1.1,7.3.9.8.4.3,1.8,1.2,2.2.6.1.4-.3.4-.6.2-2.8-.5-6.4-3.8-6.9s-1.8.2-2.5.1c-2.5-.1-3.8-2-6.7-1.4s-4.2,2.7-4.6,4.5ZM44.7,55.3h-4.3c-1.3,0-3.6-1.6-4.6-2.3-1.1,1.2-2.5,1.7-4,2.1-1.6,4.9-1.1,10.8,3.3,14.1s4.3,1.5,6.6-.4c4-3.3,4-8.9,3-13.5ZM46.8,60.7c1.2-.8,1.1-2.8-.3-3.4l.3,3.4ZM29.9,57.6c-1.7,0-1.6,2.3-.4,3.1l.4-3.1ZM41.1,71.1c-2,.7-3.7.8-5.7,0,.1,1.6-1.2,1.3-2.1,2.2,1.6,2.4,4.2,5.7,7.3,3.1s2.3-2.7,2.3-2.9c.2-.7-2.4-1-1.8-2.4ZM51.4,86.4c-.4-5.3-.7-11-6.9-12.5-.9,0-2.4,4.3-5.5,4.7s-4.9-2.3-7.3-4.6c-6.2,1.1-6.7,7.2-6.8,12.4h3.6c.3-.9-.1-5.4,1.1-5.2,1,.5.4.8.4,1.2v4h16.1l-.3-4.8c0-.4,1.2-.4,1.3,0l.3,4.8h3.9Z"/>' .
                           '<rect fill="#39a900" x="65.7" y="48.3" width="16.2" height="1.8" rx=".9" ry=".9"/>' .
                           '<rect fill="#39a900" x="65.7" y="51.5" width="11.7" height="1.8" rx=".9" ry=".9"/>' .
                           '<rect fill="#39a900" x="65.7" y="54.8" width="14.7" height="1.8" rx=".9" ry=".9"/>' .
                           '<rect fill="#39a900" x="65.7" y="58.5" width="16.2" height="1.8" rx=".9" ry=".9"/>' .
                           '<rect fill="#39a900" x="65.7" y="61.8" width="11.7" height="1.8" rx=".9" ry=".9"/>' .
                           '<rect fill="#39a900" x="65.7" y="65" width="14.7" height="1.8" rx=".9" ry=".9"/>' .
                           '<path fill="#39a900" d="M55.6,56.6c-.3-.2-.4-6.3-.3-7.1s.2-1,.7-1.4h7.5l.4.4v7.8l-.4.4h-7.9ZM62.6,49.6h-5.7v5.7h5.7v-5.7Z"/>' .
                           '<path fill="#39a900" d="M63.9,58.7v8.3h-8.3c-.3-1.3-.6-7.6.1-8.3s8.2-.4,8.2,0ZM62.6,60h-5.7v5.7h5.7v-5.7Z"/>' .
                           '<path fill="#39a900" d="M63.9,79.4v8.3h-8.3c-.4-2.6-.4-5.7,0-8.3h8.3ZM62.6,80.7h-5.7v5.7h5.7v-5.7Z"/>' .
                           '<path fill="#39a900" d="M63.9,69.1v8.3h-8.3c-.4-2.6-.4-5.7,0-8.3h8.3ZM62.6,70.4h-5.7v5.7h5.7v-5.7Z"/>' .
                           '<path fill="#00304d" d="M61.8,50.6c.9.6-1.8,3.2-2.4,3.4-.9.4-2.8-1.8-1.6-2.1s1.1.4,1.3.4c.4,0,1.8-2.4,2.7-1.7Z"/>' .
                           '<path fill="#00304d" d="M61.8,61c.9.6-2.1,3.5-2.5,3.6-.8.2-2.7-1.9-1.5-2.3s1.1.4,1.3.4c.4,0,1.8-2.4,2.7-1.7Z"/>' .
                           '<path fill="#00304d" d="M61.8,81.8c.9.7-2.1,3.5-2.5,3.6s-1.7-1-1.9-1.4c-.4-1.6,1.5-.4,1.6-.4.5,0,1.8-2.4,2.7-1.8Z"/>' .
                           '<path fill="#00304d" d="M61,71.4c.5-.1.7.2,1,.5.2.4-2.1,2.8-2.5,3-.8.4-2.2-1.2-2.1-1.8.3-1,1.4.1,1.7.1.4,0,1.4-1.6,1.9-1.8Z"/>' .
                           '<rect fill="#39a900" x="65.7" y="69" width="16.2" height="1.8" rx=".9" ry=".9"/>' .
                           '<rect fill="#39a900" x="65.7" y="72.3" width="11.7" height="1.8" rx=".9" ry=".9"/>' .
                           '<rect fill="#39a900" x="65.7" y="75.5" width="14.7" height="1.8" rx=".9" ry=".9"/>' .
                           '<rect fill="#39a900" x="65.7" y="79.4" width="16.2" height="1.8" rx=".9" ry=".9"/>' .
                           '<rect fill="#39a900" x="65.7" y="82.6" width="11.7" height="1.8" rx=".9" ry=".9"/>' .
                           '<rect fill="#39a900" x="65.7" y="85.9" width="14.7" height="1.8" rx=".9" ry=".9"/>' .
                           '</g>' .
                           '</svg>' .
                           '</div>';
            $categoryName = $category->name; // Section name
        } else if (isset($category->type) && $category->type === 'evidencias_folder') {
            $categoryIcon = '<div class="evidencias-folder-icon-large">' . 
                           '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">' .
                           '<path fill="#00304d" d="M90.1,38.9h6.6c1.3,0,3.4,2.5,3.3,3.9v40.5c-.6,6.1-5.3,10.9-11.4,11.4H11.2c-6-.7-10.6-5.2-11.2-11.2V31.6c-.2-1.4,2-3.9,3.3-3.9h6.2l.3-.3V10.2c0-2.2,3.6-5,5.7-4.9h68.3c2.1.1,4.4,1.2,5.4,3.1s.8,1.8.8,1.9v28.7ZM86.9,15.9v-5.3c0-.6-1.5-2.1-2.2-2H16c-.9-.3-2.9,1.2-2.9,2v5.3h73.8ZM86.9,19.2H13.1v1.6h73.8v-1.6ZM86.9,23.8H13.1v3.8h15.8l.8.6,9.3,10.7h47.9v-15.1ZM4,30.9l-.8,1.1v51.4c.5,4.3,4,7.8,8.4,8.2h76.8c4.4-.3,8-4,8.4-8.4v-40.4c-.1,0-.6-.5-.6-.5h-58.1c0-.1-.7-.4-.7-.4l-9.6-10.9H4Z"/>' .
                           '<rect fill="#39a900" x="13.1" y="19.2" width="73.8" height="1.6"/>' .
                           '<path fill="#39a900" d="M47.6,51.9c5.5-.2,10.5,3.3,12.1,8.5,1.3,3.9.5,8.3-2.1,11.5l1.1,1.1c.3,0,.5-.2.8-.2.5,0,1.1,0,1.5.3,2.2,2.1,4.4,4.2,6.5,6.4,1.1,1.7-.2,3-1.4,4.1s-2.2.8-3.2,0l-5.9-5.9c-.6-.8-.7-1.7-.3-2.6l-1.1-1.1c-1.8,1.4-4.1,2.4-6.4,2.6-8.6.8-15.4-7.1-13.1-15.5s6.1-8.8,11.5-9ZM47.6,54.2c-7.9.3-12.4,9.3-7.8,15.8s13.1,5.6,16.8-.6c4-6.7-.7-15-8.4-15.2h-.6Z"/>' .
                           '</svg>' .
                           '</div>';
            $categoryName = $category->name; // Evidencias folder name
        } else if ($category->icon) {
            if (isset($category->iconmerge) && $category->iconmerge) {
                // icon merge (also look JS - exaport.js - block_exaport_check_fontawesome_icon_merging()):
                $categoryIcon = block_exaport_fontawesome_icon('folder-open', 'regular', '6', ['icon-for-merging'], [], ['data-categoryId' => $category->id], '', [], [], [], ['exaport-items-category-big']);
                $categoryIcon .= '<img id="mergeImageIntoCategory' . $category->id . '" src="' . $category->icon . '?tcacheremove=' . date('dmYhis') . '" style="display:none;">';
                $categoryIcon .= '<canvas id="mergedCanvas' . $category->id . '" class="category-merged-icon" width="115" height="115" style="display: none;"></canvas>';
            } else if (strpos($category->icon, '<svg') === 0) {
                // Direct SVG code:
                $categoryIcon = $category->icon;
            } else if (strpos($category->icon, 'fa-') === 0) {
                // FontAwesome icon - convert to FontAwesome HTML
                $iconName = str_replace('fa-', '', $category->icon);
                $categoryIcon = block_exaport_fontawesome_icon($iconName, 'regular', '6', [], [], [], '', [], [], [], ['exaport-items-category-big']);
            } else {
                // just picture instead of folder icon:
                $categoryIcon = '<img src="' . $category->icon . '">';
            }
        } else {
            $categoryIcon = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" width="100" height="100">' .
                '<g>' .
                '<path fill="#00304d" d="M90.1,38.9h6.6c1.3,0,3.4,2.5,3.3,3.9v40.5c-.6,6.1-5.3,10.9-11.4,11.4H11.2c-6-.7-10.6-5.2-11.2-11.2V31.6c-.2-1.4,2-3.9,3.3-3.9h6.2l.3-.3V10.2c0-2.2,3.6-5,5.7-4.9h68.3c2.1.1,4.4,1.2,5.4,3.1s.8,1.8.8,1.9v28.7ZM86.9,15.9v-5.3c0-.6-1.5-2.1-2.2-2H16c-.9-.3-2.9,1.2-2.9,2v5.3h73.8ZM86.9,19.2H13.1v1.6h73.8v-1.6ZM86.9,23.8H13.1v3.8h15.8l.8.6,9.3,10.7h47.9v-15.1ZM4,30.9l-.8,1.1v51.4c.5,4.3,4,7.8,8.4,8.2h76.8c4.4-.3,8-4,8.4-8.4v-40.4c-.1,0-.6-.5-.6-.5h-58.1c0-.1-.7-.4-.7-.4l-9.6-10.9H4Z"/>' .
                '<rect fill="#39a900" x="13.1" y="19.2" width="73.8" height="1.6"/>' .
                '</g>' .
                '<g>' .
                '<path fill="#39a900" d="M50.3,45.9l4.7.9c1.2,0,1.3-.1,2.3-.5s1.7,1.2,1.9,2.2c.4,2,0,2.9-.3,4.7s0,2.4-.2,3.6c0,.3.3.3.4.5.8,1.4.7,3.1-.7,4.1s-1.3.5-2,.7c-.2,1.8-1.3,3.1-2.5,4.2v1.4s8.3,3.3,8.3,3.3c3.4,1.6,3.7,5.1,3.4,8.5-.2.7-1.1.6-1.2,0-.2-1.4.2-2.9-.2-4.4s-1.6-2.5-3.2-3.1-3-1.1-4.5-1.7l-3.1,2.6c-.4.3-.8.2-1.2.1l-.4.7,1.3,13h6.4v-10.8c0-.3.7-.5.9-.3s.3.3.3.4v10.7h3.4c.1,0,.3-.3.3-.4.2-1.2-.1-2.7,0-4,.2-.7,1.1-.6,1.2,0s0,3.4,0,4.2-.7,1.2-1.3,1.4h-19.3c-.7.1-.9-.9-.3-1.1s2.1,0,2.2,0l1.4-13-.4-.7c-.4.1-.9.2-1.2-.1l-3.2-2.6-5.5,2.2c-1.4.8-2.3,2.3-2.4,3.9-.2,3.1.2,6.6,0,9.7,0,.1.2.5.3.5h3.4v-10.7c0-.4,1.1-.8,1.2,0v10.7h1.9s.3.3.3.3c.2.4,0,.8-.4.9-2-.1-4.3.2-6.4,0s-1.3-.6-1.6-1.3c.2-3.4-.3-7.2,0-10.5s1.7-4,3.5-4.9l8.3-3.2v-1.5c-1.3-1.1-2.3-2.5-2.6-4.3-2.3.1-3.9-2.3-2.8-4.4s.4-.5.5-.6c.1-.3,0-2.1,0-2.6,0-1.5-.6-3.6,1-4.5s.9-.3,1.1-.5.6-1,.8-1.3c1-1.4,2.7-2.1,4.4-2.3h1.9ZM57.7,47.6c-2.2,1.1-4.5-.1-6.8-.4s-4.3-.1-5.6,1.5-.6,1.5-1.7,2.1-.5.1-.7.3c-.8.5-.4,2.1-.4,2.9s0,1.5,0,2.2h1c-.2-1.8.7-3.1,2.6-2.9s3.4.5,5.5.4,3-.8,4-.1,1.1,1.5,1,2.6h1c0,0,.2-3.1,.2-3.1.5-1.8.6-3.6,0-5.4ZM45.4,54.5c-.4,0-.6.4-.7.8.2,3.5-1.1,7.6,2.3,10s6.6.8,7.8-2.2.6-5.9.5-7.6-.3-1-.9-1-1.8.3-2.5.4c-1.5.1-3,0-4.5,0s-1.5-.4-1.9-.3ZM43.5,57.3c-2.5,0-2.5,3.4,0,3.4v-3.4ZM56.5,60.8c2.5,0,2.5-3.5,0-3.4v3.4ZM52.6,67c-1.7.7-3.5.6-5.2,0,0,.7-.2.9.3,1.3s1.3,1.1,1.5,1.3,1.3.1,1.5,0,1.4-1.2,1.7-1.4.1,0,.2,0v-1.1ZM47.4,71.7l.8-1.3c0-.2-1.4-1.3-1.7-1.5l-1.7.6v.2c0,0,2.5,2,2.5,2ZM55.1,69.6c-.4,0-1.4-.6-1.7-.6s-1.6,1.3-1.6,1.4c.3.4.5.8.8,1.2s0,.1.1.1c.7-.7,1.6-1.3,2.3-1.9s.1,0,0-.2Z"/>' .
                '</g>' .
                '</svg>';
        }
    }
    
    $categoryContent .= '
                    </div>
					<div class="card-body excomdos_tileimage d-flex justify-content-center align-items-center">
						<a href="' . $categoryThumbUrl . '">
						    ' . $categoryIcon . '
						</a>
					</div>
					<div class="card-extitle exomdos_tiletitle">
						<a href="' . $categoryThumbUrl . '">' . $categoryName . '</a>
					</div>
				</div>
			</div>
    ';

    return $categoryContent;
}

;

function block_exaport_artefact_template_bootstrap_card($item, $courseid, $type, $categoryid, $currentcategory) {
    global $CFG, $USER, $DB;

    $iconTypeProps = block_exaport_item_icon_type_options($item->type);
    
    // Special handling for course files - use direct file URL instead of shared_item.php
    if (isset($item->is_course_file) && $item->is_course_file) {
        $url = $item->url; // Use the direct file URL
    } else {
        // Check if this is an evidencias file - if so, use download endpoint
        $isEvidenciasFile = false;
        if ($item->type == 'file' && !empty($item->categoryid)) {
            $category = $DB->get_record('block_exaportcate', array('id' => $item->categoryid));
            if ($category && !empty($category->source) && is_numeric($category->source)) {
                $isEvidenciasFile = true;
            }
        }
        
        if ($isEvidenciasFile) {
            $url = $CFG->wwwroot . '/blocks/exaport/download_file.php?courseid=' . $courseid . '&itemid=' . $item->id;
        } else {
            $url = $CFG->wwwroot . '/blocks/exaport/shared_item.php?courseid=' . $courseid . '&access=portfolio/id/' . $item->userid . '&itemid=' . $item->id;
        }
    }

    $itemContent = '
    <div class="col mb-4">
        <div class="card h-100 excomdos_tile excomdos_tile_item id-13 ui-draggable ui-draggable-handle" style="border: 2px solid #39a900;">
					<div class="card-header excomdos_tilehead d-flex justify-content-between flex-wrap">
						<div class="excomdos_tileinfo">
							<span class="excomdos_tileinfo_type">'
        . block_exaport_fontawesome_icon($iconTypeProps['iconName'], $iconTypeProps['iconStyle'], 1, ['artefact_icon'])
        . '<span class="type_title">' . get_string($item->type, "block_exaport") . '</span></span>
						</div>
						<div class="excomdos_tileedit">';

    if ($currentcategory->id == -1) {
        // Link to export to portfolio.
        $itemContent .= ' <a href="' . $CFG->wwwroot . '/blocks/exaport/item.php?courseid=' . $courseid . '&id=' . $item->id . '&sesskey=' .
            sesskey() . '&action=copytoself' . '"><img src="pix/import.png" title="' .
            get_string('make_it_yours', "block_exaport") . '"></a>';
    } else {
        // Don't show comments and edit buttons for course files
        if (!isset($item->is_course_file) || !$item->is_course_file) {
            if ($item->comments > 0) {
                $itemContent .= ' <span class="excomdos_listcomments">' . $item->comments
                    . block_exaport_fontawesome_icon('comment', 'regular', 1, [], [], [], '', [], [], [], [])
                    . '</span>';
            }
            $itemContent .= block_exaport_get_item_project_icon($item);
            $itemContent .= block_exaport_get_item_comp_icon($item);
        }

        if (in_array($type, ['mine', 'shared']) && (!isset($item->is_course_file) || !$item->is_course_file)) {
            $cattype = '';
            if ($type == 'shared') {
                $cattype = '&cattype=shared';
            }
            // Use new evidencias-aware permission system
            if (block_exaport_user_can_edit_item($item, $courseid)) {
                $itemContent .= '<a href="' . $CFG->wwwroot . '/blocks/exaport/item.php?courseid=' . $courseid . '&id=' . $item->id . '&action=edit' . $cattype . '">'
                    . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18">' .
                    '<path fill="#00304d" d="M11.1,8c-0.9,0.8-1.9,2-2.9,2.9c-0.2,0.2-0.4,0.3-0.8,0.3c-0.5,0.1-1.2,0.1-1.7,0.1c-0.6,0.0-1.1-0.3-1.1-1.0c0.0-0.5,0.1-1.2,0.1-1.7c0.0-0.2,0.1-0.5,0.2-0.7L10.3,2.1c0.5-0.4,1.1-0.4,1.6,0.1c0.4,0.3,1.1,1.0,1.4,1.4c0.4,0.5,0.4,1.1,0,1.6c-0.7,0.8-1.7,1.6-2.4,2.4L11.1,8z M11.3,2.6c-0.1,0-0.2,0.1-0.2,0.1c-0.3,0.2-0.5,0.6-0.8,0.8l2.0,2.0c0.3-0.4,1.2-0.8,0.8-1.4c-0.4-0.5-1.0-0.9-1.4-1.4C11.5,2.6,11.4,2.6,11.3,2.6z M5.0,11.2s0.1,0.1,0.2,0.1c0.5,0.0,1.2-0.1,1.7-0.1c0.1,0.0,0.2,0.0,0.3-0.1l4.1-4.1L9.3,5.0L5.1,9.1c-0.1,0.1-0.1,0.6-0.1,0.7c0.0,0.3-0.1,0.7-0.1,1.1C4.9,11.0,4.9,11.2,5.0,11.2z"/>' .
                    '<path fill="#00304d" d="M3.4,3.8c0.8-0.1,1.7,0.0,2.5,0c0.4,0.1,0.4,0.7,0.0,0.7c-0.7,0.1-1.6-0.1-2.3,0c-0.5,0.0-0.8,0.4-0.9,0.9v6.5c0.0,0.5,0.4,0.9,0.9,0.9h6.4c1.5-0.1,0.8-2.2,0.9-3.2c0.1-0.4,0.6-0.4,0.7,0c-0.1,1.5,0.5,3.6-1.5,3.9H3.2c-0.8-0.1-1.5-0.7-1.5-1.5V5.6C1.8,4.9,2.5,3.9,3.4,3.8z"/>' .
                    '<path fill="#00304d" d="M16.5,10.5c0.2,0,0.4,0.2,0.4,0.4s-0.2,0.4-0.4,0.4h-2.1v2.1c0,0.2-0.2,0.4-0.4,0.4s-0.4-0.2-0.4-0.4v-2.1h-2.1c-0.2,0-0.4-0.2-0.4-0.4s0.2-0.4,0.4-0.4h2.1V8.4c0-0.2,0.2-0.4,0.4-0.4s0.4,0.2,0.4,0.4v2.1H16.5z"/>' .
                    '<path fill="#00304d" d="M20.5,10.9c0,5.2-4.3,9.5-9.5,9.5s-9.5-4.3-9.5-9.5S5.8,1.4,11,1.4S20.5,5.7,20.5,10.9z M19.7,10.9c0-4.8-3.9-8.7-8.7-8.7s-8.7,3.9-8.7,8.7s3.9,8.7,8.7,8.7S19.7,15.7,19.7,10.9z"/>' .
                    '<path fill="#00304d" d="M22,10.9c0,6.1-4.9,11-11,11s-11-4.9-11-11s4.9-11,11-11S22,4.8,22,10.9z M21.2,10.9c0-5.6-4.6-10.2-10.2-10.2S0.8,5.3,0.8,10.9s4.6,10.2,10.2,10.2S21.2,16.5,21.2,10.9z"/>' .
                    '<path fill="#00304d" d="M23.2,10.9c0,6.8-5.5,12.3-12.3,12.3S-1.4,17.7-1.4,10.9S4.1-1.4,10.9-1.4S23.2,4.1,23.2,10.9z M22.4,10.9c0-6.4-5.1-11.5-11.5-11.5S-0.6,4.5-0.6,10.9s5.1,11.5,11.5,11.5S22.4,17.3,22.4,10.9z"/>' .
                    '</svg>'
                    . '</a>';
            }
            
            // Use new evidencias-aware permission system for delete
            if (block_exaport_user_can_delete_item($item, $courseid)) {
                if ($allowedit = block_exaport_item_is_editable($item->id)) {
                    $itemContent .= '<a href="' . $CFG->wwwroot . '/blocks/exaport/item.php?courseid=' . $courseid . '&id=' . $item->id . '&action=delete&categoryid=' . $categoryid . $cattype . '" class="item_delete_icon">'
                        . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 15 15" width="18" height="18">' .
                        '<path fill="#00304d" d="M6.01,1.71c.05-.28.38-.59.65-.67.28-.09,1.31-.1,1.59-.01.56.17.76.73.7,1.27h2.72c1.28.06,1.98,1.75.86,2.55-.11.08-.45.17-.47.27-.05.27-.05.66-.07.94-.13,2.37-.13,4.78-.3,7.16-.07.98-.42,1.66-1.5,1.74-1.78.13-3.73-.09-5.52,0-.8-.12-1.31-.65-1.38-1.46-.01-.16,0-.32,0-.47-.1-1.9-.18-3.8-.27-5.7-.04-.75-.04-1.51-.1-2.26-1.6-.48-1.23-2.6.35-2.76h2.75c.02-.18-.03-.42,0-.59ZM8.17,2.3c.04-.29.07-.53-.29-.57-.15-.02-.88-.02-.99.03-.21.09-.13.38-.14.55h1.42ZM12.25,3.95s.04-.11.04-.15c.05-.48-.31-.76-.76-.79H3.43c-.84,0-1.12,1.18-.2,1.32h8.54c.21-.01.33-.15.46-.29l-.07-.08s.07,0,.09,0ZM11.24,5.47l.02-.41-.07-.03c-2,0-4.02-.02-6.01.04-.23,0-.46,0-.69,0-.07.14.17.4.29.4h6.46ZM5.63,12.99c.1-.1.14-.28.15-.41,0-.13,0-4.7,0-5.13,0-.35-.18-.57-.56-.52-.46.05-.35.57-.35.9-.01.52,0,4.89,0,4.89.04.36.51.53.77.27ZM7.32,6.93c-.14.03-.3.24-.29.38v5.4c.06.51.84.55.91-.03v-5.3c-.02-.36-.27-.53-.62-.45ZM9.51,6.93c-.13.03-.21.13-.27.24l-.03,5.54c.06.51.85.55.91-.03v-5.3c-.03-.36-.26-.52-.62-.45Z"/>' .
                        '<path fill="#00304d" d="M2.09,8.23c-.27.25-.6.04-.62-.32-.01-.24-.02-.6,0-.83,0-.1.02-.35.04-.43.07-.33.63-.36.7.1.03.24.03,1,0,1.25,0,.08-.06.19-.11.24Z"/>' .
                        '<path fill="#00304d" d="M12.85,8.72c.27-.07.45.08.48.35.02.21.03.98,0,1.17-.08.39-.65.43-.7-.03-.03-.23-.03-.99,0-1.21.02-.11.11-.25.23-.28Z"/>' .
                        '<path fill="#00304d" d="M10.46,1.09c-.11-.12-.44,0-.39-.29.06-.14.25-.09.35-.16.13-.09.07-.36.21-.4.26-.08.19.24.28.35.09.12.31.04.38.19.12.25-.29.21-.38.31-.1.11-.03.43-.28.35-.13-.04-.1-.28-.17-.36Z"/>' .
                        '<path fill="#00304d" d="M2.36,10.44c.13.13-.11.2-.19.28-.12.11-.15.25-.29.31-.09-.06-.11-.16-.18-.25-.07-.08-.29-.19-.3-.24-.03-.12.18-.16.27-.23.09-.08.12-.22.22-.29.07-.01.19.23.25.28.06.06.19.09.22.13Z"/>' .
                        '<path fill="#00304d" d="M12.7,7.39c-.09-.13.17-.23.23-.28s.14-.26.22-.26c.11.06.13.18.24.27.06.06.11.07.17.1.17.13-.1.21-.18.29-.06.06-.15.25-.22.25-.13-.06-.19-.21-.3-.31-.04-.04-.14-.06-.15-.07Z"/>' .
                        '<path fill="#00304d" d="M3.24.92c.06.2.21.26.35.37.18.13-.13.23-.21.32s-.1.25-.25.22c-.02,0-.09-.15-.12-.19-.06-.07-.27-.21-.27-.27,0-.1.19-.14.26-.22.08-.08.09-.27.25-.23Z"/>' .
                        '</svg>'
                        . '</a>';
                }
            } else if (!$allowedit = block_exaport_item_is_editable($item->id)) {
                $itemContent .= '<img src="pix/deleteview.png" alt="file">';
            }
            if ($item->userid != $USER->id) {
                $itemuser = $DB->get_record('user', ['id' => $item->userid]);
                // user icon
                $itemContent .= '<a class="" role="button" data-container="body"
                            title="' . fullname($itemuser) . '">'
                    . block_exaport_fontawesome_icon('circle-user', 'solid', 1)
                    . '</a>';
            }
        }
    }

    $itemContent .= '</div>
					</div>
					<div class="card-body excomdos_tileimage d-flex justify-content-center align-items-center">
					    <a href="' . $url . '">';
    
    // Special handling for course files - show file type icon instead of thumbnail
    if (isset($item->is_course_file) && $item->is_course_file) {
        // Use file type icon based on mimetype
        $fileicon = block_exaport_get_file_icon($item);
        $itemContent .= $fileicon;
    } else {
        $itemContent .= '<img height="75" alt="' . $item->name . '" title="' . $item->name . '" src="' . $CFG->wwwroot . '/blocks/exaport/item_thumb.php?item_id=' . $item->id . '"/>';
    }
    
    $itemContent .= '</a>
					</div>
					<div class="card-extitle exomdos_tiletitle">
						<a href="' . $url . '">' . $item->name . '</a>
					</div>
					<div class="card-footer excomdos_tileinfo_time mt-2">
                        ' . date('d.m.Y H:i', $item->timemodified) . '
					</div>
				</div>
			</div>
    ';

    return $itemContent;
}
