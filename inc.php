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

require_once(__DIR__ . "/../../config.php");

global $CFG, $PAGE, $USER, $COURSE, $OUTPUT, $DB, $PARSER;

if (!$COURSE) {
    require_once __DIR__ . '/../../config.php';
}

// TODO: check if this is needed
// Authentication disabled for this block
// if (empty($USER->id)) {
//     require_login();
// }

// force clean theme - only if PAGE is available
if (isset($PAGE) && $PAGE !== null) {
    $PAGE->set_pagelayout('standard');
    $PAGE->requires->jquery();
    $PAGE->requires->jquery_plugin('ui');
}

// Get rid 'warning' messages for ajax request (regardless moodle configuration)
if (
    basename($_SERVER['SCRIPT_NAME']) == 'blocks.json.php' // ajax requests to work with blocks
    || (basename($_SERVER['SCRIPT_NAME']) == 'item_thumb.php' && optional_param('item_id', '0', PARAM_INT) > 0) // item thumbnials
) {
    @$CFG->debug = 5;
    @error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
    // @ini_set('display_errors', '5'); // Commented to prevent headers already sent
}
require_once($CFG->libdir . '/filelib.php');

require_once(__DIR__ . '/lib/lib.php');

// CSS and JS will be loaded when the block is rendered, not when inc.php is included
// This prevents "Cannot require a CSS file after <head> has been printed" errors
