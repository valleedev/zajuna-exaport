<?php
// Simple audit logging function for exaport
// This file provides a simple way to log audit events without complex class dependencies

/**
 * Simple function to record audit events directly in the database
 * 
 * @param string $event_type Type of event (folder_created, item_uploaded, etc.)
 * @param int $resource_id ID of the resource
 * @param string $resource_type Type of resource (folder, file, note, etc.)
 * @param string $resource_name Name of the resource
 * @param array $metadata Additional metadata as associative array
 * @param string $risk_level Risk level (low, medium, high)
 */
function exaport_log_audit_event($event_type, $resource_id, $resource_type, $resource_name, $metadata = [], $risk_level = 'low') {
    global $DB, $USER;
    
    try {
        // Ensure we have a user
        if (empty($USER->id)) {
            return false;
        }
        
        // Get user info for the audit record
        $user = $DB->get_record('user', array('id' => $USER->id));
        if (!$user) {
            return false;
        }
        
        // Prepare the record with all required fields
        $record = new stdClass();
        $record->event_type = $event_type;
        $record->risk_level = $risk_level;
        $record->user_id = $USER->id;
        $record->username = $user->username;
        $record->user_email = $user->email;
        $record->full_name = fullname($user);
        
        // Get user roles (simplified)
        $record->user_roles = json_encode(array('roles' => 'user'));
        
        $record->ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $record->user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $record->resource_type = $resource_type;
        $record->resource_id = (int)$resource_id;
        $record->resource_name = $resource_name;
        $record->parent_id = $metadata['parent_id'] ?? null;
        $record->resource_metadata = json_encode($metadata);
        $record->timestamp = time();
        $record->description = $metadata['description'] ?? "User {$user->username} performed {$event_type} on {$resource_type} '{$resource_name}'";
        $record->details = json_encode($metadata);
        $record->session_id = session_id() ?: 'no_session';
        $record->course_id = $metadata['course_id'] ?? 1;
        $record->change_log = json_encode(array('action' => $event_type, 'timestamp' => time()));
        
        // Insert the record
        $id = $DB->insert_record('block_exaport_audit_events', $record);
        
        return $id;
        
    } catch (Exception $e) {
        // Log error but don't break the application
        error_log("Exaport audit logging error: " . $e->getMessage());
        return false;
    }
}

/**
 * Log folder/category creation
 */
function exaport_log_folder_created($folder_id, $folder_name, $parent_id = null, $course_id = 1) {
    $metadata = [
        'course_id' => $course_id,
        'parent_id' => $parent_id,
        'action' => 'folder_created'
    ];
    
    return exaport_log_audit_event('folder_created', $folder_id, 'folder', $folder_name, $metadata, 'low');
}

/**
 * Log item upload
 */
function exaport_log_item_uploaded($item_id, $item_name, $item_type, $category_id = null, $course_id = 1) {
    $metadata = [
        'course_id' => $course_id,
        'category_id' => $category_id,
        'item_type' => $item_type,
        'action' => 'item_uploaded'
    ];
    
    return exaport_log_audit_event('item_uploaded', $item_id, $item_type, $item_name, $metadata, 'low');
}

/**
 * Log item deletion
 */
function exaport_log_item_deleted($item_id, $item_name, $item_type, $course_id = 1) {
    $metadata = [
        'course_id' => $course_id,
        'item_type' => $item_type,
        'action' => 'item_deleted'
    ];
    
    return exaport_log_audit_event('item_deleted', $item_id, $item_type, $item_name, $metadata, 'medium');
}

/**
 * Log folder deletion
 */
function exaport_log_folder_deleted($folder_id, $folder_name, $course_id = 1) {
    $metadata = [
        'course_id' => $course_id,
        'action' => 'folder_deleted'
    ];
    
    return exaport_log_audit_event('folder_deleted', $folder_id, 'folder', $folder_name, $metadata, 'medium');
}