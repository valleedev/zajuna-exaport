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

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib/lib.php');

require_login();

// Check capability
$context = context_system::instance();

// Page setup
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/exaport/test_audit.php'));
$PAGE->set_title('Test Sistema de Auditoría');
$PAGE->set_heading('Test Sistema de Auditoría - Exaport');
$PAGE->set_pagelayout('standard');

echo $OUTPUT->header();

echo '<div class="container-fluid">';
echo '<div class="row">';
echo '<div class="col-12">';

echo '<h2>Prueba del Sistema de Auditoría</h2>';

echo '<h3>1. Verificación de Base de Datos</h3>';
global $DB;
if ($DB->get_manager()->table_exists('block_exaport_audit_events')) {
    echo '<div class="alert alert-success">✓ La tabla block_exaport_audit_events existe</div>';
    $count = $DB->count_records('block_exaport_audit_events');
    echo '<p>Total de eventos: <strong>' . $count . '</strong></p>';
} else {
    echo '<div class="alert alert-danger">✗ La tabla block_exaport_audit_events NO existe</div>';
}

echo '<h3>2. Verificación de Archivos</h3>';
$files_to_check = [
    'classes/audit/application/AuditService.php',
    'classes/audit/infrastructure/MoodleAuditEventRepository.php',
    'classes/audit/domain/AuditEvent.php',
    'classes/audit/domain/EventType.php',
    'classes/audit/domain/RiskLevel.php',
    'classes/audit/domain/UserContext.php',
    'classes/audit/domain/ResourceContext.php',
    'audit.php'
];

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        echo '<div class="alert alert-success mb-1">✓ ' . $file . '</div>';
    } else {
        echo '<div class="alert alert-danger mb-1">✗ ' . $file . '</div>';
    }
}

echo '<h3>3. Verificación de Permisos</h3>';
if (has_capability('block/exaport:auditview', $context)) {
    echo '<div class="alert alert-success">✓ Usuario tiene permisos para ver auditoría</div>';
} else {
    echo '<div class="alert alert-warning">⚠ Usuario no tiene permisos para ver auditoría</div>';
}

echo '<h3>4. Estructura de la Tabla</h3>';
try {
    $columns = $DB->get_columns('block_exaport_audit_events');
    echo '<div class="table-responsive">';
    echo '<table class="table table-sm table-bordered">';
    echo '<thead><tr><th>Columna</th><th>Tipo</th><th>Nulo</th></tr></thead>';
    echo '<tbody>';
    foreach ($columns as $column) {
        echo '<tr>';
        echo '<td><code>' . $column->name . '</code></td>';
        echo '<td>' . $column->type . '</td>';
        echo '<td>' . ($column->not_null ? 'NO' : 'SI') . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
    echo '</div>';
} catch (Exception $e) {
    echo '<div class="alert alert-warning">No se pudo obtener la estructura de la tabla: ' . $e->getMessage() . '</div>';
}

echo '<h3>5. Eventos Recientes</h3>';
try {
    $events = $DB->get_records('block_exaport_audit_events', null, 'timestamp DESC', '*', 0, 10);
    if ($events) {
        echo '<div class="table-responsive">';
        echo '<table class="table table-striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>ID</th>';
        echo '<th>Tipo</th>';
        echo '<th>Usuario</th>';
        echo '<th>Recurso</th>';
        echo '<th>Riesgo</th>';
        echo '<th>Fecha</th>';
        echo '<th>Descripción</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        foreach ($events as $event) {
            $riskBadgeClass = '';
            switch ($event->risk_level) {
                case 'low':
                    $riskBadgeClass = 'badge-success';
                    break;
                case 'medium':
                    $riskBadgeClass = 'badge-warning';
                    break;
                case 'high':
                    $riskBadgeClass = 'badge-danger';
                    break;
                case 'critical':
                    $riskBadgeClass = 'badge-dark';
                    break;
            }
            
            echo '<tr>';
            echo '<td>' . $event->id . '</td>';
            echo '<td><code>' . $event->event_type . '</code></td>';
            echo '<td>' . $event->username . '</td>';
            echo '<td>' . $event->resource_name . ' (ID: ' . $event->resource_id . ')</td>';
            echo '<td><span class="badge ' . $riskBadgeClass . '">' . ucfirst($event->risk_level) . '</span></td>';
            echo '<td>' . date('Y-m-d H:i:s', $event->timestamp) . '</td>';
            echo '<td>' . substr($event->description, 0, 50) . '...</td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    } else {
        echo '<div class="alert alert-info">No hay eventos de auditoría registrados.</div>';
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al obtener eventos: ' . $e->getMessage() . '</div>';
}

echo '<h3>6. Test de Inserción Directa</h3>';
try {
    // Crear un evento de prueba directamente en la base de datos
    $testRecord = new stdClass();
    $testRecord->event_type = 'test_event';
    $testRecord->risk_level = 'low';
    $testRecord->user_id = $USER->id;
    $testRecord->username = $USER->username;
    $testRecord->user_email = $USER->email;
    $testRecord->full_name = fullname($USER);
    $testRecord->user_roles = json_encode(['student']);
    $testRecord->ip_address = getremoteaddr();
    $testRecord->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $testRecord->resource_type = 'test';
    $testRecord->resource_id = 999;
    $testRecord->resource_name = 'Test Resource';
    $testRecord->parent_id = null;
    $testRecord->resource_metadata = json_encode(['test' => true]);
    $testRecord->timestamp = time();
    $testRecord->description = 'Test event from test_audit.php';
    $testRecord->details = json_encode(['created_by' => 'test_script']);
    $testRecord->session_id = session_id();
    $testRecord->course_id = 1;
    $testRecord->change_log = json_encode(['action' => 'test_insert']);
    
    $id = $DB->insert_record('block_exaport_audit_events', $testRecord);
    echo '<div class="alert alert-success">✓ Evento de prueba insertado con ID: ' . $id . '</div>';
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error al insertar evento de prueba: ' . $e->getMessage() . '</div>';
}

echo '<h3>7. Enlaces de Navegación</h3>';
echo '<div class="btn-group" role="group">';
echo '<a href="audit.php" class="btn btn-primary">Ir a la Interfaz de Auditoría</a>';
echo '<a href="view_items.php" class="btn btn-secondary">Ir a View Items</a>';
echo '<a href="' . $CFG->wwwroot . '/blocks/exaport/" class="btn btn-info">Ir al Plugin</a>';
echo '</div>';

echo '</div>';
echo '</div>';
echo '</div>';

echo $OUTPUT->footer();
?>