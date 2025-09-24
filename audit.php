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

use block_exaport\audit\application\AuditService;
use block_exaport\audit\domain\AuditEventSearchCriteria;
use block_exaport\audit\domain\valueobjects\EventType;
use block_exaport\audit\domain\valueobjects\RiskLevel;

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', 'list', PARAM_ALPHA);

// Form parameters
$date_from = optional_param('date_from', '', PARAM_RAW);
$date_to = optional_param('date_to', '', PARAM_RAW);
$user_id = optional_param('user_id', 0, PARAM_INT);
$event_type = optional_param('event_type', '', PARAM_ALPHA);
$risk_level = optional_param('risk_level', '', PARAM_ALPHA);
$resource_type = optional_param('resource_type', '', PARAM_ALPHA);
$search_text = optional_param('search_text', '', PARAM_RAW);
$page = optional_param('page', 0, PARAM_INT);
$per_page = optional_param('per_page', 50, PARAM_INT);

block_exaport_require_login($courseid);

// Check permissions
if (!AuditService::canUserAccessAudit()) {
    throw new moodle_exception('audit_access_denied', 'block_exaport');
}

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/blocks/exaport/audit.php', ['courseid' => $courseid]);
$PAGE->set_title(get_string('audit_log', 'block_exaport'));
$PAGE->set_heading(get_string('audit_log', 'block_exaport'));

// Handle export action
if ($action === 'export') {
    require_capability('block/exaport:exportaudit', $context);
    
    $auditService = new AuditService();
    
    // Build search criteria from form
    $criteria = new AuditEventSearchCriteria();
    
    if (!empty($date_from)) {
        $from = DateTime::createFromFormat('Y-m-d', $date_from);
        if ($from) {
            $criteria = $criteria->withDateRange(
                DateTimeImmutable::createFromMutable($from->setTime(0, 0, 0)),
                !empty($date_to) ? 
                    DateTimeImmutable::createFromMutable(DateTime::createFromFormat('Y-m-d', $date_to)->setTime(23, 59, 59)) :
                    new DateTimeImmutable()
            );
        }
    }
    
    if ($user_id > 0) {
        $criteria = $criteria->withUserId($user_id);
    }
    
    if (!empty($event_type)) {
        $criteria = $criteria->withEventType(EventType::fromString($event_type));
    }
    
    if (!empty($risk_level)) {
        $criteria = $criteria->withRiskLevel(RiskLevel::fromString($risk_level));
    }
    
    if (!empty($resource_type)) {
        $criteria = $criteria->withResource($resource_type);
    }
    
    if (!empty($search_text)) {
        $criteria = $criteria->withSearchText($search_text);
    }
    
    // Set large limit for export
    $criteria = $criteria->withLimit(10000);
    
    $exportData = $auditService->exportEventsForCompliance(
        $criteria->getFromDate() ?? new DateTimeImmutable('-30 days'),
        $criteria->getToDate() ?? new DateTimeImmutable(),
        $criteria->getUserId()
    );
    
    // Generate CSV export
    $filename = 'audit_export_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // CSV headers
    fputcsv($output, [
        get_string('audit_timestamp', 'block_exaport'),
        get_string('audit_event_type', 'block_exaport'),
        get_string('audit_risk_level', 'block_exaport'),
        get_string('audit_user_name', 'block_exaport'),
        get_string('audit_resource', 'block_exaport'),
        get_string('audit_description', 'block_exaport'),
        get_string('audit_ip_address', 'block_exaport'),
        get_string('audit_session', 'block_exaport')
    ]);
    
    // CSV data
    foreach ($exportData as $row) {
        fputcsv($output, [
            $row['timestamp'],
            $row['event_type'],
            $row['risk_level'],
            $row['username'] . ' (' . $row['user_email'] . ')',
            $row['resource_type'] . ': ' . $row['resource_name'],
            $row['description'],
            $row['ip_address'] ?? '',
            $row['session_id'] ?? ''
        ]);
    }
    
    fclose($output);
    exit;
}

// Initialize audit service
$auditService = new AuditService();

// Build search criteria
$criteria = new AuditEventSearchCriteria();

if (!empty($date_from)) {
    $from = DateTime::createFromFormat('Y-m-d', $date_from);
    if ($from) {
        $to = !empty($date_to) ? DateTime::createFromFormat('Y-m-d', $date_to) : new DateTime();
        $criteria = $criteria->withDateRange(
            DateTimeImmutable::createFromMutable($from->setTime(0, 0, 0)),
            DateTimeImmutable::createFromMutable($to->setTime(23, 59, 59))
        );
    }
}

if ($user_id > 0) {
    $criteria = $criteria->withUserId($user_id);
}

if (!empty($event_type)) {
    $criteria = $criteria->withEventType(EventType::fromString($event_type));
}

if (!empty($risk_level)) {
    $criteria = $criteria->withRiskLevel(RiskLevel::fromString($risk_level));
}

if (!empty($resource_type)) {
    $criteria = $criteria->withResource($resource_type);
}

if (!empty($search_text)) {
    $criteria = $criteria->withSearchText($search_text);
}

// Set pagination
$criteria = $criteria->withLimit($per_page)->withOffset($page * $per_page);

// Get filtered results
$searchResult = $auditService->getFilteredEvents($criteria);
$events = $searchResult->getEvents();
$totalCount = $searchResult->getTotalCount();

// Get statistics for dashboard
$statistics = $auditService->getStatistics();

block_exaport_print_header('audit_log');

echo $OUTPUT->heading(get_string('audit_log', 'block_exaport'));

// Statistics dashboard
echo '<div class="row mb-4">';
echo '<div class="col-md-3"><div class="card text-center"><div class="card-body">';
echo '<h5 class="card-title">' . $statistics->getTotalEvents() . '</h5>';
echo '<p class="card-text">' . get_string('audit_total_events', 'block_exaport') . '</p>';
echo '</div></div></div>';

echo '<div class="col-md-3"><div class="card text-center"><div class="card-body">';
echo '<h5 class="card-title">' . $statistics->getEventsToday() . '</h5>';
echo '<p class="card-text">' . get_string('audit_events_today', 'block_exaport') . '</p>';
echo '</div></div></div>';

echo '<div class="col-md-3"><div class="card text-center"><div class="card-body">';
echo '<h5 class="card-title">' . $statistics->getHighRiskEvents() . '</h5>';
echo '<p class="card-text">' . get_string('audit_high_risk_events', 'block_exaport') . '</p>';
echo '</div></div></div>';

echo '<div class="col-md-3"><div class="card text-center"><div class="card-body">';
echo '<h5 class="card-title">' . number_format($statistics->getHighRiskPercentage(), 1) . '%</h5>';
echo '<p class="card-text">' . get_string('audit_high_risk_percentage', 'block_exaport') . '</p>';
echo '</div></div></div>';
echo '</div>';

// Search form
echo '<div class="card mb-4">';
echo '<div class="card-header">';
echo '<h5>' . get_string('audit_filters', 'block_exaport') . '</h5>';
echo '</div>';
echo '<div class="card-body">';

echo '<form method="get" action="' . $CFG->wwwroot . '/blocks/exaport/audit.php">';
echo '<input type="hidden" name="courseid" value="' . $courseid . '">';

echo '<div class="row">';

// Date from
echo '<div class="col-md-3">';
echo '<label for="date_from">' . get_string('audit_date_from', 'block_exaport') . '</label>';
echo '<input type="date" class="form-control" id="date_from" name="date_from" value="' . s($date_from) . '">';
echo '</div>';

// Date to
echo '<div class="col-md-3">';
echo '<label for="date_to">' . get_string('audit_date_to', 'block_exaport') . '</label>';
echo '<input type="date" class="form-control" id="date_to" name="date_to" value="' . s($date_to) . '">';
echo '</div>';

// Event type
echo '<div class="col-md-3">';
echo '<label for="event_type">' . get_string('audit_event_type', 'block_exaport') . '</label>';
echo '<select class="form-control" id="event_type" name="event_type">';
echo '<option value="">' . get_string('all') . '</option>';
$eventTypes = EventType::getAllValidTypes();
foreach ($eventTypes as $type) {
    $selected = $event_type === $type ? 'selected' : '';
    $eventTypeObj = EventType::fromString($type);
    echo '<option value="' . $type . '" ' . $selected . '>' . $eventTypeObj->getDescription() . '</option>';
}
echo '</select>';
echo '</div>';

// Risk level
echo '<div class="col-md-3">';
echo '<label for="risk_level">' . get_string('audit_risk_level', 'block_exaport') . '</label>';
echo '<select class="form-control" id="risk_level" name="risk_level">';
echo '<option value="">' . get_string('all') . '</option>';
$riskLevels = ['low', 'medium', 'high', 'critical'];
foreach ($riskLevels as $risk) {
    $selected = $risk_level === $risk ? 'selected' : '';
    echo '<option value="' . $risk . '" ' . $selected . '>' . get_string('audit_risk_' . $risk, 'block_exaport') . '</option>';
}
echo '</select>';
echo '</div>';

echo '</div>'; // End row

echo '<div class="row mt-3">';

// Search text
echo '<div class="col-md-6">';
echo '<label for="search_text">' . get_string('audit_search_text', 'block_exaport') . '</label>';
echo '<input type="text" class="form-control" id="search_text" name="search_text" value="' . s($search_text) . '" placeholder="' . get_string('search') . '...">';
echo '</div>';

// Results per page
echo '<div class="col-md-3">';
echo '<label for="per_page">' . get_string('audit_results_per_page', 'block_exaport') . '</label>';
echo '<select class="form-control" id="per_page" name="per_page">';
$perPageOptions = [25, 50, 100, 200];
foreach ($perPageOptions as $option) {
    $selected = $per_page == $option ? 'selected' : '';
    echo '<option value="' . $option . '" ' . $selected . '>' . $option . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="col-md-3 d-flex align-items-end">';
echo '<button type="submit" class="btn btn-primary mr-2">' . get_string('audit_apply_filters', 'block_exaport') . '</button>';
echo '<a href="' . $CFG->wwwroot . '/blocks/exaport/audit.php?courseid=' . $courseid . '" class="btn btn-secondary">' . get_string('audit_clear_filters', 'block_exaport') . '</a>';
echo '</div>';

echo '</div>'; // End row

echo '</form>';
echo '</div>'; // End card-body
echo '</div>'; // End card

// Export button (if user has permission)
if (has_capability('block/exaport:exportaudit', $context)) {
    echo '<div class="mb-3">';
    echo '<a href="' . $CFG->wwwroot . '/blocks/exaport/audit.php?action=export&courseid=' . $courseid . 
         '&date_from=' . urlencode($date_from) . '&date_to=' . urlencode($date_to) . 
         '&user_id=' . $user_id . '&event_type=' . urlencode($event_type) . 
         '&risk_level=' . urlencode($risk_level) . '&resource_type=' . urlencode($resource_type) . 
         '&search_text=' . urlencode($search_text) . '" class="btn btn-success">';
    echo get_string('audit_export', 'block_exaport') . '</a>';
    echo '</div>';
}

// Results table
echo '<div class="card">';
echo '<div class="card-header d-flex justify-content-between align-items-center">';
echo '<h5>' . get_string('audit_events', 'block_exaport') . ' (' . $totalCount . ')</h5>';
echo '</div>';

if (empty($events)) {
    echo '<div class="card-body text-center">';
    echo '<p class="text-muted">' . get_string('audit_no_events', 'block_exaport') . '</p>';
    echo '</div>';
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-striped table-hover">';
    echo '<thead class="thead-light">';
    echo '<tr>';
    echo '<th>' . get_string('audit_timestamp', 'block_exaport') . '</th>';
    echo '<th>' . get_string('audit_event_type', 'block_exaport') . '</th>';
    echo '<th>' . get_string('audit_risk_level', 'block_exaport') . '</th>';
    echo '<th>' . get_string('audit_user_name', 'block_exaport') . '</th>';
    echo '<th>' . get_string('audit_resource', 'block_exaport') . '</th>';
    echo '<th>' . get_string('audit_description', 'block_exaport') . '</th>';
    echo '<th>' . get_string('audit_details', 'block_exaport') . '</th>';
    echo '</tr>';
    echo '</thead>';
    echo '<tbody>';
    
    foreach ($events as $event) {
        echo '<tr>';
        
        // Timestamp
        echo '<td>';
        echo '<small>' . $event->getTimestamp()->format('Y-m-d H:i:s') . '</small>';
        echo '</td>';
        
        // Event type
        echo '<td>';
        echo '<span class="badge badge-info">' . $event->getEventType()->getDescription() . '</span>';
        echo '</td>';
        
        // Risk level
        echo '<td>';
        $riskBadgeClass = [
            'low' => 'success',
            'medium' => 'warning',
            'high' => 'danger',
            'critical' => 'dark'
        ][$event->getRiskLevel()->value()] ?? 'secondary';
        echo '<span class="badge badge-' . $riskBadgeClass . '">' . 
             get_string('audit_risk_' . $event->getRiskLevel()->value(), 'block_exaport') . '</span>';
        echo '</td>';
        
        // User
        echo '<td>';
        echo '<strong>' . s($event->getUserContext()->getFullName()) . '</strong><br>';
        echo '<small class="text-muted">' . s($event->getUserContext()->getUsername()) . '</small>';
        echo '</td>';
        
        // Resource
        echo '<td>';
        echo '<strong>' . s($event->getResourceContext()->getResourceName()) . '</strong><br>';
        echo '<small class="text-muted">' . s($event->getResourceContext()->getResourceType()) . '</small>';
        echo '</td>';
        
        // Description
        echo '<td>';
        echo s($event->getDescription());
        echo '</td>';
        
        // Details
        echo '<td>';
        if ($event->getUserContext()->getIpAddress()) {
            echo '<small><strong>IP:</strong> ' . s($event->getUserContext()->getIpAddress()) . '</small><br>';
        }
        if ($event->getSessionId()) {
            echo '<small><strong>Session:</strong> ' . substr(s($event->getSessionId()), 0, 8) . '...</small>';
        }
        echo '</td>';
        
        echo '</tr>';
    }
    
    echo '</tbody>';
    echo '</table>';
    echo '</div>'; // End table-responsive
}

echo '</div>'; // End card

// Pagination
if ($totalCount > $per_page) {
    $totalPages = ceil($totalCount / $per_page);
    
    echo '<nav aria-label="Audit pagination" class="mt-3">';
    echo '<ul class="pagination justify-content-center">';
    
    // Previous button
    if ($page > 0) {
        $prevUrl = $CFG->wwwroot . '/blocks/exaport/audit.php?' . http_build_query([
            'courseid' => $courseid,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'user_id' => $user_id,
            'event_type' => $event_type,
            'risk_level' => $risk_level,
            'resource_type' => $resource_type,
            'search_text' => $search_text,
            'per_page' => $per_page,
            'page' => $page - 1
        ]);
        echo '<li class="page-item"><a class="page-link" href="' . $prevUrl . '">' . get_string('previous') . '</a></li>';
    }
    
    // Page numbers
    $startPage = max(0, $page - 2);
    $endPage = min($totalPages - 1, $page + 2);
    
    for ($i = $startPage; $i <= $endPage; $i++) {
        $pageUrl = $CFG->wwwroot . '/blocks/exaport/audit.php?' . http_build_query([
            'courseid' => $courseid,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'user_id' => $user_id,
            'event_type' => $event_type,
            'risk_level' => $risk_level,
            'resource_type' => $resource_type,
            'search_text' => $search_text,
            'per_page' => $per_page,
            'page' => $i
        ]);
        
        $activeClass = $i == $page ? ' active' : '';
        echo '<li class="page-item' . $activeClass . '"><a class="page-link" href="' . $pageUrl . '">' . ($i + 1) . '</a></li>';
    }
    
    // Next button
    if ($page < $totalPages - 1) {
        $nextUrl = $CFG->wwwroot . '/blocks/exaport/audit.php?' . http_build_query([
            'courseid' => $courseid,
            'date_from' => $date_from,
            'date_to' => $date_to,
            'user_id' => $user_id,
            'event_type' => $event_type,
            'risk_level' => $risk_level,
            'resource_type' => $resource_type,
            'search_text' => $search_text,
            'per_page' => $per_page,
            'page' => $page + 1
        ]);
        echo '<li class="page-item"><a class="page-link" href="' . $nextUrl . '">' . get_string('next') . '</a></li>';
    }
    
    echo '</ul>';
    echo '</nav>';
}

echo $OUTPUT->footer();