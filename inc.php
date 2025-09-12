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
if (empty($USER->id)) {
    require_login();
}

// force clean theme.
$PAGE->set_pagelayout('standard');

$PAGE->requires->jquery();
$PAGE->requires->jquery_plugin('ui');

// Get rid 'warning' messages for ajax request (regardless moodle configuration)
if (
    basename($_SERVER['SCRIPT_NAME']) == 'blocks.json.php' // ajax requests to work with blocks
    || (basename($_SERVER['SCRIPT_NAME']) == 'views_mod.php' && optional_param('ajax', '0', PARAM_INT) === 1) // ajax requests in view editing
    || (basename($_SERVER['SCRIPT_NAME']) == 'item_thumb.php' && optional_param('item_id', '0', PARAM_INT) > 0) // item thumbnials
) {
    @$CFG->debug = 5;
    @error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
    @ini_set('display_errors', '5');
}
require_once($CFG->libdir . '/filelib.php');

require_once(__DIR__ . '/lib/lib.php');

// Only add CSS and JS if PAGE is available (not in CLI or other contexts)
if (isset($PAGE) && $PAGE !== null) {
    $PAGE->requires->css('/blocks/exaport/styles.css');

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
