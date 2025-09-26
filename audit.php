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

use block_exaport\globals as g;

$courseid = optional_param('courseid', 0, PARAM_INT);
$date_from = optional_param('date_from', '', PARAM_TEXT);
$date_to = optional_param('date_to', '', PARAM_TEXT);
$user_id = optional_param('user_id', 0, PARAM_INT);
$event_type = optional_param('event_type', '', PARAM_TEXT);
$risk_level = optional_param('risk_level', '', PARAM_TEXT);
$resource_type = optional_param('resource_type', '', PARAM_TEXT);
$search_text = optional_param('search_text', '', PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 50, PARAM_INT);
$action = optional_param('action', '', PARAM_TEXT);

// Login like view_items.php
block_exaport_require_login($courseid);

$context = context_system::instance();

// Check capability to view audit logs
require_capability('block/exaport:viewaudit', $context);

// Get courseid parameter like view_items.php - make it optional for direct access
if (!$courseid) {
    $courseid = optional_param('courseid', 1, PARAM_INT); // Default to course 1 if not provided
}

// Set page URL - ensure it's a local URL like view_items.php
$page_url = '/blocks/exaport/audit.php';
$url_params = array('courseid' => $courseid);
$PAGE->set_url(new moodle_url($page_url, $url_params));
$PAGE->set_context($context);

// Add iconpack like view_items.php
block_exaport_add_iconpack();

// Breadcrumbs se gestionan desde block_exaport_print_header para mantener orden deseado

// Export CSV if requested
if ($action === 'export') {
    // Build WHERE conditions for export
    $where_conditions = [];
    $params = [];
    
    if (!empty($date_from)) {
        $from_timestamp = strtotime($date_from . ' 00:00:00');
        if ($from_timestamp) {
            $where_conditions[] = 'ae.timestamp >= ?';
            $params[] = $from_timestamp;
        }
    }
    
    if (!empty($date_to)) {
        $to_timestamp = strtotime($date_to . ' 23:59:59');
        if ($to_timestamp) {
            $where_conditions[] = 'ae.timestamp <= ?';
            $params[] = $to_timestamp;
        }
    }
    
    if ($user_id > 0) {
        $where_conditions[] = 'ae.user_id = ?';
        $params[] = $user_id;
    }
    
    if (!empty($event_type)) {
        $where_conditions[] = 'ae.event_type = ?';
        $params[] = $event_type;
    }
    
    if (!empty($risk_level)) {
        $where_conditions[] = 'ae.risk_level = ?';
        $params[] = $risk_level;
    }
    
    if (!empty($resource_type)) {
        $where_conditions[] = 'ae.resource_type = ?';
        $params[] = $resource_type;
    }
    
    if (!empty($search_text)) {
        $where_conditions[] = '(ae.resource_metadata LIKE ? OR u.username LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ?)';
        $search_param = '%' . $search_text . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }
    
    $where_clause = '';
    if (!empty($where_conditions)) {
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    $sql = "SELECT ae.*, u.username, u.firstname, u.lastname, u.email
            FROM {block_exaport_audit_events} ae
            LEFT JOIN {user} u ON ae.user_id = u.id
            $where_clause
            ORDER BY ae.timestamp DESC";
    
    $events = $DB->get_records_sql($sql, $params);
    
    // Set CSV headers
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d_H-i-s') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // CSV header
    fputcsv($output, [
        block_exaport_get_string('audit_date'),
        block_exaport_get_string('audit_user'),
        block_exaport_get_string('audit_event_type'),
        block_exaport_get_string('audit_resource_type'),
        'Resource ID',
        block_exaport_get_string('audit_risk_level'),
        'Resource Name',
        'Description',
        'IP Address',
        'User Agent',
        'Session ID',
        'Course ID',
        'Timestamp',
        'Formatted Date'
    ]);
    
    // CSV data
    foreach ($events as $event) {
        $metadata = isset($event->metadata) && !empty($event->metadata) ? json_decode($event->metadata, true) : array();
        $resource_name = isset($metadata['resource_name']) ? $metadata['resource_name'] : '';
        $description = isset($metadata['description']) ? $metadata['description'] : '';
        
        fputcsv($output, [
            date('Y-m-d H:i:s', $event->timestamp),
            $event->firstname . ' ' . $event->lastname . ' (' . $event->username . ')',
            ucfirst(str_replace('_', ' ', $event->event_type)),
            ucfirst($event->resource_type),
            $event->resource_id,
            ucfirst($event->risk_level),
            $resource_name,
            $description,
            $event->ip_address,
            $event->user_agent,
            $event->session_id,
            $event->course_id,
            $event->timestamp,
            date('Y-m-d H:i:s', $event->timestamp)
        ]);
    }
    
    fclose($output);
    exit;
}

// Usar header del bloque con navegación: Portafolio Aprendiz > Auditoría
block_exaport_print_header("audit");

// Get users for filter dropdown
$users = $DB->get_records_sql("
    SELECT DISTINCT u.id, u.username, u.firstname, u.lastname 
    FROM {user} u 
    INNER JOIN {block_exaport_audit_events} ae ON u.id = ae.user_id 
    ORDER BY u.lastname, u.firstname
");

// Get event types for filter dropdown
$event_types = $DB->get_records_sql("
    SELECT DISTINCT event_type 
    FROM {block_exaport_audit_events} 
    ORDER BY event_type
");

// Get risk levels for filter dropdown
$risk_levels = $DB->get_records_sql("
    SELECT DISTINCT risk_level 
    FROM {block_exaport_audit_events} 
    ORDER BY risk_level
");

// Get resource types for filter dropdown
$resource_types = $DB->get_records_sql("
    SELECT DISTINCT resource_type 
    FROM {block_exaport_audit_events} 
    ORDER BY resource_type
");

?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h2><?php echo block_exaport_get_string('audit_log'); ?></h2>
            
            <!-- Filters Form -->
            <form method="get" action="audit.php" class="mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><?php echo block_exaport_get_string('audit_filters'); ?></h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="date_from"><?php echo block_exaport_get_string('audit_date_from'); ?>:</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo s($date_from); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="date_to"><?php echo block_exaport_get_string('audit_date_to'); ?>:</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo s($date_to); ?>">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="user_id"><?php echo block_exaport_get_string('audit_user'); ?>:</label>
                                    <select class="form-control" id="user_id" name="user_id">
                                        <option value="0"><?php echo block_exaport_get_string('audit_all_users'); ?></option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?php echo $user->id; ?>" <?php echo ($user_id == $user->id) ? 'selected' : ''; ?>>
                                                <?php echo s($user->firstname . ' ' . $user->lastname . ' (' . $user->username . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="event_type"><?php echo block_exaport_get_string('audit_event_type'); ?>:</label>
                                    <select class="form-control" id="event_type" name="event_type">
                                        <option value=""><?php echo block_exaport_get_string('audit_all_events'); ?></option>
                                        <?php foreach ($event_types as $type): ?>
                                            <option value="<?php echo s($type->event_type); ?>" <?php echo ($event_type == $type->event_type) ? 'selected' : ''; ?>>
                                                <?php echo s(ucfirst(str_replace('_', ' ', $type->event_type))); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="risk_level"><?php echo block_exaport_get_string('audit_risk_level'); ?>:</label>
                                    <select class="form-control" id="risk_level" name="risk_level">
                                        <option value=""><?php echo block_exaport_get_string('audit_all_risks'); ?></option>
                                        <?php foreach ($risk_levels as $risk): ?>
                                            <option value="<?php echo s($risk->risk_level); ?>" <?php echo ($risk_level == $risk->risk_level) ? 'selected' : ''; ?>>
                                                <?php echo s(ucfirst($risk->risk_level)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="resource_type"><?php echo block_exaport_get_string('audit_resource_type'); ?>:</label>
                                    <select class="form-control" id="resource_type" name="resource_type">
                                        <option value=""><?php echo block_exaport_get_string('audit_all_resources'); ?></option>
                                        <?php foreach ($resource_types as $resource): ?>
                                            <option value="<?php echo s($resource->resource_type); ?>" <?php echo ($resource_type == $resource->resource_type) ? 'selected' : ''; ?>>
                                                <?php echo s(ucfirst($resource->resource_type)); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="search_text"><?php echo block_exaport_get_string('audit_search_text'); ?>:</label>
                                    <input type="text" class="form-control" id="search_text" name="search_text" value="<?php echo s($search_text); ?>" placeholder="<?php echo block_exaport_get_string('audit_search_placeholder'); ?>">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <div>
                                        <button type="submit" class="btn btn-primary"><?php echo block_exaport_get_string('audit_search'); ?></button>
                                        <a href="<?php echo $PAGE->url->out(false, array()); ?>" class="btn btn-outline-secondary">Limpiar filtros</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

<?php

// Build WHERE conditions for search
$where_conditions = [];
$params = [];

if (!empty($date_from)) {
    $from_timestamp = strtotime($date_from . ' 00:00:00');
    if ($from_timestamp) {
        $where_conditions[] = 'ae.timestamp >= ?';
        $params[] = $from_timestamp;
    }
}

if (!empty($date_to)) {
    $to_timestamp = strtotime($date_to . ' 23:59:59');
    if ($to_timestamp) {
        $where_conditions[] = 'ae.timestamp <= ?';
        $params[] = $to_timestamp;
    }
}

if ($user_id > 0) {
    $where_conditions[] = 'ae.user_id = ?';
    $params[] = $user_id;
}

if (!empty($event_type)) {
    $where_conditions[] = 'ae.event_type = ?';
    $params[] = $event_type;
}

if (!empty($risk_level)) {
    $where_conditions[] = 'ae.risk_level = ?';
    $params[] = $risk_level;
}

if (!empty($resource_type)) {
    $where_conditions[] = 'ae.resource_type = ?';
    $params[] = $resource_type;
}

if (!empty($search_text)) {
    $where_conditions[] = '(ae.resource_metadata LIKE ? OR u.username LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ?)';
    $search_param = '%' . $search_text . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Count total records for pagination
$count_sql = "SELECT COUNT(*)
              FROM {block_exaport_audit_events} ae
              LEFT JOIN {user} u ON ae.user_id = u.id
              $where_clause";

$total_count = $DB->count_records_sql($count_sql, $params);

// Get audit events with pagination
$sql = "SELECT ae.*, u.username, u.firstname, u.lastname, u.email
        FROM {block_exaport_audit_events} ae
        LEFT JOIN {user} u ON ae.user_id = u.id
        $where_clause
        ORDER BY ae.timestamp DESC";

$events = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

?>

            <!-- Results Card -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><?php echo block_exaport_get_string('audit_results'); ?> (<?php echo $total_count; ?>)</h5>
                    <?php if ($total_count > 0): ?>
                        <div class="btn-group">
                            <a href="<?php echo $PAGE->url->out(false, array('action' => 'export') + $_GET); ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="fa fa-download"></i> <?php echo block_exaport_get_string('audit_export'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($events)): ?>
                        <p class="text-muted"><?php echo block_exaport_get_string('audit_no_results'); ?></p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="thead-dark">
                                    <tr>
                                        <th><?php echo block_exaport_get_string('audit_date'); ?></th>
                                        <th><?php echo block_exaport_get_string('audit_user'); ?></th>
                                        <th><?php echo block_exaport_get_string('audit_event_type'); ?></th>
                                        <th><?php echo block_exaport_get_string('audit_resource_type'); ?></th>
                                        <th>Resource</th>
                                        <th><?php echo block_exaport_get_string('audit_risk_level'); ?></th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($events as $event): ?>
                                        <?php
                                        $metadata = isset($event->resource_metadata) && !empty($event->resource_metadata) ? json_decode($event->resource_metadata, true) : array();
                                        $resource_name = isset($metadata['resource_name']) ? $metadata['resource_name'] : 'ID: ' . $event->resource_id;
                                        $description = isset($metadata['description']) ? $metadata['description'] : '';
                                        
                                        // Risk level badge classes
                                        $risk_class = 'badge-secondary';
                                        switch ($event->risk_level) {
                                            case 'high':
                                                $risk_class = 'badge-danger';
                                                break;
                                            case 'medium':
                                                $risk_class = 'badge-warning';
                                                break;
                                            case 'low':
                                                $risk_class = 'badge-success';
                                                break;
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <small><?php echo date('Y-m-d H:i:s', $event->timestamp); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($event->username): ?>
                                                    <strong><?php echo s($event->firstname . ' ' . $event->lastname); ?></strong><br>
                                                    <small class="text-muted"><?php echo s($event->username); ?></small>
                                                <?php else: ?>
                                                    <em class="text-muted">User ID: <?php echo $event->user_id; ?></em>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-primary"><?php echo s(ucfirst(str_replace('_', ' ', $event->event_type))); ?></span>
                                            </td>
                                            <td>
                                                <?php echo s(ucfirst($event->resource_type)); ?>
                                            </td>
                                            <td>
                                                <strong><?php echo s($resource_name); ?></strong>
                                                <?php if ($description): ?>
                                                    <br><small class="text-muted"><?php echo s($description); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge <?php echo $risk_class; ?>"><?php echo s(ucfirst($event->risk_level)); ?></span>
                                            </td>
                                            <td>
                                                <small>
                                                    IP: <?php echo s($event->ip_address); ?><br>
                                                    Course: <?php echo $event->course_id; ?>
                                                    
                                                    <?php if ($event->session_id): ?>
                                                        <br>Session: <?php echo s(substr($event->session_id, 0, 8)); ?>...
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <?php if ($total_count > $perpage): ?>
                            <nav aria-label="Audit log pagination">
                                <ul class="pagination justify-content-center">
                                    <?php
                                    $total_pages = ceil($total_count / $perpage);
                                    $current_page = $page + 1;
                                    
                                    // Previous page
                                    if ($page > 0):
                                        $prev_params = $_GET;
                                        $prev_params['page'] = $page - 1;
                                    ?>
                                        <li class="page-item">
                                            <a class="page-link" href="<?php echo $PAGE->url->out(false, $prev_params); ?>">&laquo; Previous</a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">&laquo; Previous</span>
                                        </li>
                                    <?php endif; ?>
                                    
                                    <?php
                                    // Page numbers
                                    $start_page = max(1, $current_page - 2);
                                    $end_page = min($total_pages, $current_page + 2);
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                        $page_params = $_GET;
                                        $page_params['page'] = $i - 1;
                                    ?>
                                        <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>">
                                            <a class="page-link" href="<?php echo $PAGE->url->out(false, $page_params); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <?php
                                    // Next page
                                    if ($page < $total_pages - 1):
                                        $next_params = $_GET;
                                        $next_params['page'] = $page + 1;
                                    ?>
                                        <li class="page-item">
                                            <a class="page-link" href="<?php echo $PAGE->url->out(false, $next_params); ?>">Next &raquo;</a>
                                        </li>
                                    <?php else: ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">Next &raquo;</span>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
echo block_exaport_wrapperdivend();
block_exaport_print_footer();
?>