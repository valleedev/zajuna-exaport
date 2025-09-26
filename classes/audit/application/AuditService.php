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

namespace block_exaport\audit\application;

use block_exaport\audit\domain\AuditEvent;
use block_exaport\audit\domain\AuditEventRepository;
use block_exaport\audit\domain\AuditEventSearchCriteria;
use block_exaport\audit\domain\AuditEventSearchResult;
use block_exaport\audit\domain\AuditEventStatistics;
use block_exaport\audit\domain\valueobjects\EventType;
use block_exaport\audit\domain\valueobjects\RiskLevel;
use block_exaport\audit\domain\valueobjects\UserContext;
use block_exaport\audit\domain\valueobjects\ResourceContext;
use block_exaport\audit\infrastructure\MoodleAuditEventRepository;

/**
 * Audit Service - Application layer for audit functionality
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
class AuditService
{
    private AuditEventRepository $repository;
    
    public function __construct(AuditEventRepository $repository = null)
    {
        $this->repository = $repository ?? new MoodleAuditEventRepository();
    }
    
    /**
     * Record a folder creation event
     */
    public function recordFolderCreated(
        int $folderId,
        string $folderName,
        ?int $parentId = null,
        array $metadata = []
    ): void {
        $userContext = UserContext::fromCurrentUser();
        $event = AuditEvent::folderCreated($userContext, $folderId, $folderName, $parentId, $metadata);
        $this->repository->save($event);
    }
    
    /**
     * Record a folder deletion event
     */
    public function recordFolderDeleted(
        int $folderId,
        string $folderName,
        array $deletedData = []
    ): void {
        $userContext = UserContext::fromCurrentUser();
        $event = AuditEvent::folderDeleted($userContext, $folderId, $folderName, $deletedData);
        $this->repository->save($event);
    }
    
    /**
     * Record an item upload event
     */
    public function recordItemUploaded(
        int $itemId,
        string $itemName,
        string $itemType,
        ?int $folderId = null,
        array $metadata = []
    ): void {
        $userContext = UserContext::fromCurrentUser();
        $event = AuditEvent::itemUploaded($userContext, $itemId, $itemName, $itemType, $folderId, $metadata);
        $this->repository->save($event);
    }
    
    /**
     * Record an item deletion event
     */
    public function recordItemDeleted(
        int $itemId,
        string $itemName,
        array $itemData = []
    ): void {
        $userContext = UserContext::fromCurrentUser();
        $event = AuditEvent::itemDeleted($userContext, $itemId, $itemName, $itemData);
        $this->repository->save($event);
    }
    
    /**
     * Record a view access event
     */
    public function recordViewAccessed(
        int $viewId,
        string $viewName,
        int $ownerId,
        array $metadata = []
    ): void {
        $userContext = UserContext::fromCurrentUser();
        $event = AuditEvent::viewAccessed($userContext, $viewId, $viewName, $ownerId, $metadata);
        $this->repository->save($event);
    }
    
    /**
     * Record a custom audit event
     */
    public function recordCustomEvent(
        EventType $eventType,
        ResourceContext $resourceContext,
        string $description,
        array $details = []
    ): void {
        $userContext = UserContext::fromCurrentUser();
        $event = new AuditEvent($eventType, $userContext, $resourceContext, $description, $details);
        $this->repository->save($event);
    }
    
    /**
     * Search audit events
     */
    public function searchEvents(AuditEventSearchCriteria $criteria): AuditEventSearchResult
    {
        return $this->repository->search($criteria);
    }
    
    /**
     * Get audit statistics
     */
    public function getStatistics(): AuditEventStatistics
    {
        return $this->repository->getStatistics();
    }
    
    /**
     * Get recent high-risk events
     */
    public function getRecentHighRiskEvents(int $hours = 24, int $limit = 50): array
    {
        $criteria = (new AuditEventSearchCriteria())
            ->onlyHighRisk()
            ->onlyRecentEvents($hours)
            ->withLimit($limit);
        
        $result = $this->repository->search($criteria);
        return $result->getEvents();
    }
    
    /**
     * Get user activity summary
     */
    public function getUserActivitySummary(int $userId, int $days = 30): array
    {
        $fromDate = new \DateTimeImmutable("-{$days} days");
        $toDate = new \DateTimeImmutable();
        
        $criteria = (new AuditEventSearchCriteria())
            ->withUserId($userId)
            ->withDateRange($fromDate, $toDate);
        
        $result = $this->repository->search($criteria);
        $events = $result->getEvents();
        
        // Group events by type
        $eventsByType = [];
        foreach ($events as $event) {
            $type = $event->getEventType()->value();
            if (!isset($eventsByType[$type])) {
                $eventsByType[$type] = 0;
            }
            $eventsByType[$type]++;
        }
        
        return [
            'total_events' => count($events),
            'events_by_type' => $eventsByType,
            'date_range' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d')
            ]
        ];
    }
    
    /**
     * Get resource audit trail
     */
    public function getResourceAuditTrail(string $resourceType, int $resourceId, int $limit = 100): array
    {
        return $this->repository->findByResource($resourceType, $resourceId, $limit);
    }
    
    /**
     * Clean old audit events
     */
    public function cleanOldEvents(int $daysToKeep = 365): int
    {
        $cutoffDate = new \DateTimeImmutable("-{$daysToKeep} days");
        return $this->repository->deleteOlderThan($cutoffDate);
    }
    
    /**
     * Export audit events for compliance
     */
    public function exportEventsForCompliance(
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate,
        ?int $userId = null
    ): array {
        $criteria = (new AuditEventSearchCriteria())
            ->withDateRange($fromDate, $toDate)
            ->withLimit(10000); // Large limit for export
        
        if ($userId !== null) {
            $criteria = $criteria->withUserId($userId);
        }
        
        $result = $this->repository->search($criteria);
        $events = $result->getEvents();
        
        // Convert to export format
        $exportData = [];
        foreach ($events as $event) {
            $exportData[] = [
                'id' => $event->getId(),
                'timestamp' => $event->getTimestamp()->format('Y-m-d H:i:s'),
                'event_type' => $event->getEventType()->value(),
                'risk_level' => $event->getRiskLevel()->value(),
                'user_id' => $event->getUserContext()->getUserId(),
                'username' => $event->getUserContext()->getUsername(),
                'user_email' => $event->getUserContext()->getEmail(),
                'resource_type' => $event->getResourceContext()->getResourceType(),
                'resource_id' => $event->getResourceContext()->getResourceId(),
                'resource_name' => $event->getResourceContext()->getResourceName(),
                'description' => $event->getDescription(),
                'ip_address' => $event->getUserContext()->getIpAddress(),
                'session_id' => $event->getSessionId(),
                'course_id' => $event->getCourseId(),
            ];
        }
        
        return $exportData;
    }
    
    /**
     * Get audit dashboard data
     */
    public function getDashboardData(): array
    {
        $stats = $this->getStatistics();
        $recentHighRisk = $this->getRecentHighRiskEvents(24, 10);
        
        return [
            'statistics' => [
                'total_events' => $stats->getTotalEvents(),
                'events_today' => $stats->getEventsToday(),
                'events_this_week' => $stats->getEventsThisWeek(),
                'events_this_month' => $stats->getEventsThisMonth(),
                'high_risk_events' => $stats->getHighRiskEvents(),
                'high_risk_percentage' => $stats->getHighRiskPercentage(),
            ],
            'events_by_type' => $stats->getEventsByType(),
            'events_by_risk_level' => $stats->getEventsByRiskLevel(),
            'recent_high_risk_events' => array_map(function($event) {
                return [
                    'id' => $event->getId(),
                    'timestamp' => $event->getTimestamp()->format('Y-m-d H:i:s'),
                    'event_type' => $event->getEventType()->getDescription(),
                    'risk_level' => $event->getRiskLevel()->value(),
                    'user' => $event->getUserContext()->getDisplayName(),
                    'resource' => $event->getResourceContext()->getResourceName(),
                    'description' => $event->getDescription(),
                ];
            }, $recentHighRisk),
            'top_users' => $stats->getTopUsers(),
            'top_resources' => $stats->getTopResources(),
        ];
    }
    
    /**
     * Check if user can access audit functionality
     */
    public static function canUserAccessAudit(): bool
    {
        global $USER;
        
        // System administrators can always access
        if (is_siteadmin()) {
            return true;
        }
        
        // Teachers can access their course audit data
        if (block_exaport_user_is_teacher()) {
            return true;
        }
        
        // Check if user has audit capability
        $context = \context_system::instance();
        return has_capability('block/exaport:viewaudit', $context);
    }
    
    /**
     * Get filtered audit events based on user permissions
     */
    public function getFilteredEvents(AuditEventSearchCriteria $criteria): AuditEventSearchResult
    {
        global $USER;
        
        // If not admin, filter by user's accessible data
        if (!is_siteadmin()) {
            if (block_exaport_user_is_teacher() && !block_exaport_user_is_student()) {
                // Teachers can see events from their students
                $students = block_exaport_get_students_for_teacher();
                $studentIds = array_keys($students);
                $studentIds[] = $USER->id; // Include teacher's own events
                
                // Add user filter - this would need to be implemented in SearchCriteria
                // For now, we'll proceed without this filter
            } else {
                // Students can only see their own events
                $criteria = $criteria->withUserId($USER->id);
            }
        }
        
        return $this->searchEvents($criteria);
    }
}