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

require_once(__DIR__ . '/inc.php');
require_once(__DIR__ . '/lib/lib.php');

$itemid = required_param('itemid', PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

// Get the item
$item = $DB->get_record('block_exaportitem', array('id' => $itemid));
if (!$item) {
    print_error("itemnotfound", "block_exaport");
}

// Check if it's a file type
if ($item->type != 'file') {
    print_error("notafile", "block_exaport");
}

// Get the category to check if it's evidencias
$category = $DB->get_record('block_exaportcate', array('id' => $item->categoryid));
if (!$category) {
    print_error("categorynotfound", "block_exaport");
}

// Check permissions using our evidencias permission system
if (!empty($category->source) && is_numeric($category->source)) {
    // This is an evidencias item - check permissions
    if (!block_exaport_user_can_view_item($item, $category->source)) {
        print_error("nopermissiontoviewitem", "block_exaport");
    }
} else {
    // Regular portfolio item - check if user owns it
    if ($item->userid != $USER->id) {
        print_error("nopermissiontoviewitem", "block_exaport");
    }
}

// Get the file from the file system
$fs = get_file_storage();
$context = context_user::instance($item->userid);
$files = $fs->get_area_files($context->id, 'block_exaport', 'item_file', $item->id, 'filename', false);

if (empty($files)) {
    print_error("filenotfound", "block_exaport");
}

$file = reset($files);

// Force download
send_stored_file($file, 0, 0, true);
