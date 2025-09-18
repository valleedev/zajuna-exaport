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

require(__DIR__ . '/inc.php');

class block_exaport extends block_list {

    public function init() {
        // Use custom Zajuna key if available, fallback to standard key
        $stringman = get_string_manager();
        if ($stringman->string_exists('zajuna_blocktitle', 'block_exaport')) {
            $this->title = get_string('zajuna_blocktitle', 'block_exaport');
        } else {
            $this->title = get_string('blocktitle', 'block_exaport');
        }
    }

    public function instance_allow_multiple() {
        return false;
    }

    public function instance_allow_config() {
        return false;
    }

    public function has_config() {
        return true;
    }

    public function get_content() {
        global $CFG, $COURSE, $OUTPUT;

        $context = context_system::instance();
        if (!has_capability('block/exaport:use', $context)) {
            $this->content = '';
            return $this->content;
        }

        if ($this->content !== null) {
            return $this->content;
        }

        if (empty($this->instance)) {
            $this->content = '';
            return $this->content;
        }

        $this->content = new stdClass;
        $this->content->items = array();
        $this->content->icons = array();
        $this->content->footer = '';

        $output = block_exaport_get_renderer();

        $icon = '<img src="' . $output->image_url('my_portfolio', 'block_exaport') . '" class="icon" alt="" />';
        $this->content->items[] = '<a title="' . block_exaport_get_string('myportfoliotitle') . '" ' .
            ' href="' . $CFG->wwwroot . '/blocks/exaport/view_items.php?courseid=' . $COURSE->id . '" class="exaport-nav">' .
            $icon . block_exaport_get_string('myportfolio') . '</a>';

        return $this->content;
    }
}
