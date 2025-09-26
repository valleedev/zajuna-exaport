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

    /**
     * Load CSS and JavaScript resources for Exaport
     * This method ensures resources are loaded only when the block is rendered
     */
    private function load_exaport_resources() {
        global $PAGE;

        if (isset($PAGE) && $PAGE !== null) {
            // Load main CSS
            $PAGE->requires->css('/blocks/exaport/styles.css');
            
            // Load the main JS/CSS initialization
            block_exaport_init_js_css();
            
            // JavaScript to maintain aside navigation state across Exaport modules
            $aside_js = <<<EOF
(function() {
    var ExaportAside = {
        store: function() {
            try {
                var drawer = document.querySelector('[data-region="drawer"]');
                if (drawer) {
                    var isOpen = drawer.classList.contains('show') || drawer.getAttribute('aria-expanded') === 'true';
                    sessionStorage.setItem('exaport_drawer_open', isOpen ? 'true' : 'false');
                }
                
                var navDrawer = document.querySelector('#nav-drawer');
                if (navDrawer) {
                    var isOpen = navDrawer.classList.contains('show') || !navDrawer.classList.contains('closed');
                    sessionStorage.setItem('exaport_nav_open', isOpen ? 'true' : 'false');
                }
            } catch(e) {
                console.log('Exaport store error:', e);
            }
        },
        
        restore: function() {
            try {
                var self = this;
                setTimeout(function() {
                    if (sessionStorage.getItem('exaport_drawer_open') === 'true') {
                        var drawer = document.querySelector('[data-region="drawer"]');
                        var toggle = document.querySelector('[data-action="toggle-drawer"]');
                        if (drawer && toggle && !drawer.classList.contains('show')) {
                            toggle.click();
                        }
                    }
                    
                    if (sessionStorage.getItem('exaport_nav_open') === 'true') {
                        var navDrawer = document.querySelector('#nav-drawer');
                        var navToggle = document.querySelector('[data-action="toggle-nav-drawer"]');
                        if (navDrawer && navToggle && !navDrawer.classList.contains('show')) {
                            navToggle.click();
                        }
                    }
                }, 500);
            } catch(e) {
                console.log('Exaport restore error:', e);
            }
        },
        
        init: function() {
            var self = this;
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', function() {
                    self.restore();
                });
            } else {
                self.restore();
            }
            
            document.addEventListener('click', function(e) {
                var target = e.target.closest('a');
                if (target && target.href && 
                    (target.href.indexOf('/blocks/exaport/') !== -1 || 
                     target.classList.contains('exaport-nav') ||
                     target.closest('.block_exaport'))) {
                    self.store();
                }
            });
            
            document.addEventListener('click', function(e) {
                if (e.target.matches('[data-action*="toggle"]')) {
                    setTimeout(function() { self.store(); }, 100);
                }
            });
        }
    };
    
    ExaportAside.init();
})();
EOF;

            $PAGE->requires->js_init_code($aside_js);
        }
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

        // Load CSS and JS only when block is being rendered
        $this->load_exaport_resources();

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
