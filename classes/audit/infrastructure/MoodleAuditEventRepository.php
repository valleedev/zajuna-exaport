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

namespace block_exaport\audit\infrastructure;

use block_exaport\audit\domain\AuditEvent;
use block_exaport\audit\domain\AuditEventRepository;
use block_exaport\audit\domain\AuditEventSearchCriteria;
use block_exaport\audit\domain\AuditEventSearchResult;
use block_exaport\audit\domain\AuditEventStatistics;
use block_exaport\audit\domain\valueobjects\EventType;
use block_exaport\audit\domain\valueobjects\RiskLevel;
use block_exaport\audit\domain\valueobjects\UserContext;
use block_exaport\audit\domain\valueobjects\ResourceContext;

/**
 * Moodle implementation of AuditEventRepository using Moodle database
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
class MoodleAuditEventRepository implements AuditEventRepository
{
    private const TABLE_NAME = 'block_exaport_audit_events';
    
    private \moodle_database $db;
    
    public function __construct(\moodle_database $db = null)
    {
        global $DB;
        $this->db = $db ?? $DB;
    }
    
    /**
     * Save an audit event
     */
    public function save(AuditEvent $event): void
    {
        $record = $this->eventToRecord($event);
        
        if ($event->getId() === null) {
            $id = $this->db->insert_record(self::TABLE_NAME, $record);
            $event->setId($id);
        } else {
            $record->id = $event->getId();
            $this->db->update_record(self::TABLE_NAME, $record);
        }
    }
    
    /**
     * Find audit event by ID
     */
    public function findById(int $id): ?AuditEvent
    {
        $record = $this->db->get_record(self::TABLE_NAME, ['id' => $id]);
        
        if (!$record) {
            return null;
        }
        
        return $this->recordToEvent($record);
    }
    
    /**
     * Find events by user ID
     */
    public function findByUserId(int $userId, int $limit = 100, int $offset = 0): array
    {
        $records = $this->db->get_records(
            self::TABLE_NAME,
            ['user_id' => $userId],
            'timestamp DESC',
            '*',
            $offset,
            $limit
        );
        
        return array_map([$this, 'recordToEvent'], $records);
    }
    
    /**
     * Find events by resource
     */
    public function findByResource(string $resourceType, int $resourceId, int $limit = 100): array
    {
        $records = $this->db->get_records(
            self::TABLE_NAME,
            ['resource_type' => $resourceType, 'resource_id' => $resourceId],
            'timestamp DESC',
            '*',
            0,
            $limit
        );
        
        return array_map([$this, 'recordToEvent'], $records);
    }
    
    /**
     * Find events by event type
     */
    public function findByEventType(EventType $eventType, int $limit = 100, int $offset = 0): array
    {
        $records = $this->db->get_records(
            self::TABLE_NAME,
            ['event_type' => $eventType->value()],
            'timestamp DESC',
            '*',
            $offset,
            $limit
        );
        
        return array_map([$this, 'recordToEvent'], $records);
    }
    
    /**
     * Find events by risk level
     */
    public function findByRiskLevel(RiskLevel $riskLevel, int $limit = 100, int $offset = 0): array
    {
        $records = $this->db->get_records(
            self::TABLE_NAME,
            ['risk_level' => $riskLevel->value()],
            'timestamp DESC',
            '*',
            $offset,
            $limit
        );
        
        return array_map([$this, 'recordToEvent'], $records);
    }
    
    /**
     * Find events by date range
     */
    public function findByDateRange(\DateTimeImmutable $from, \DateTimeImmutable $to, int $limit = 100): array
    {
        $sql = "SELECT * FROM {" . self::TABLE_NAME . "} 
                WHERE timestamp >= ? AND timestamp <= ? 
                ORDER BY timestamp DESC";
        
        $records = $this->db->get_records_sql($sql, [
            $from->getTimestamp(),
            $to->getTimestamp()
        ], 0, $limit);
        
        return array_map([$this, 'recordToEvent'], $records);
    }
    
    /**
     * Search events with filters
     */
    public function search(AuditEventSearchCriteria $criteria): AuditEventSearchResult
    {
        $sql = $this->buildSearchSql($criteria);
        $params = $this->buildSearchParams($criteria);
        
        // Get total count
        $countSql = "SELECT COUNT(*) FROM ({$sql}) AS search_results";
        $totalCount = $this->db->count_records_sql($countSql, $params);
        
        // Get paginated results
        $records = $this->db->get_records_sql(
            $sql . " ORDER BY " . $this->buildOrderBy($criteria),
            $params,
            $criteria->getOffset(),
            $criteria->getLimit()
        );
        
        $events = array_map([$this, 'recordToEvent'], $records);
        
        return new AuditEventSearchResult($events, $totalCount, $criteria);
    }
    
    /**
     * Count total events
     */
    public function countTotal(): int
    {
        return $this->db->count_records(self::TABLE_NAME);
    }
    
    /**
     * Count events by user
     */
    public function countByUser(int $userId): int
    {
        return $this->db->count_records(self::TABLE_NAME, ['user_id' => $userId]);
    }
    
    /**
     * Count events by event type
     */
    public function countByEventType(EventType $eventType): int
    {
        return $this->db->count_records(self::TABLE_NAME, ['event_type' => $eventType->value()]);
    }
    
    /**
     * Count events by risk level
     */
    public function countByRiskLevel(RiskLevel $riskLevel): int
    {
        return $this->db->count_records(self::TABLE_NAME, ['risk_level' => $riskLevel->value()]);
    }
    
    /**
     * Get event statistics
     */
    public function getStatistics(): AuditEventStatistics
    {
        $totalEvents = $this->countTotal();
        
        // Events by type
        $eventsByType = $this->db->get_records_sql("
            SELECT event_type, COUNT(*) as count 
            FROM {" . self::TABLE_NAME . "} 
            GROUP BY event_type 
            ORDER BY count DESC
        ");
        
        // Events by risk level
        $eventsByRiskLevel = $this->db->get_records_sql("
            SELECT risk_level, COUNT(*) as count 
            FROM {" . self::TABLE_NAME . "} 
            GROUP BY risk_level 
            ORDER BY count DESC
        ");
        
        // Events by date (last 30 days)
        $thirtyDaysAgo = (new \DateTimeImmutable('-30 days'))->getTimestamp();
        $eventsByDate = $this->db->get_records_sql("
            SELECT DATE(FROM_UNIXTIME(timestamp)) as date, COUNT(*) as count 
            FROM {" . self::TABLE_NAME . "} 
            WHERE timestamp >= ? 
            GROUP BY DATE(FROM_UNIXTIME(timestamp)) 
            ORDER BY date DESC
        ", [$thirtyDaysAgo]);
        
        // Top users
        $topUsers = $this->db->get_records_sql("
            SELECT user_id, username, full_name, COUNT(*) as count 
            FROM {" . self::TABLE_NAME . "} 
            GROUP BY user_id, username, full_name 
            ORDER BY count DESC 
            LIMIT 10
        ");
        
        // Top resources
        $topResources = $this->db->get_records_sql("
            SELECT resource_type, resource_id, resource_name, COUNT(*) as count 
            FROM {" . self::TABLE_NAME . "} 
            GROUP BY resource_type, resource_id, resource_name 
            ORDER BY count DESC 
            LIMIT 10
        ");
        
        // High risk events count
        $highRiskEvents = $this->db->count_records_select(
            self::TABLE_NAME, 
            "risk_level IN ('high', 'critical')"
        );
        
        // Events today
        $todayStart = (new \DateTimeImmutable('today'))->getTimestamp();
        $eventsToday = $this->db->count_records_select(
            self::TABLE_NAME,
            "timestamp >= ?",
            [$todayStart]
        );
        
        // Events this week
        $weekStart = (new \DateTimeImmutable('monday this week'))->getTimestamp();
        $eventsThisWeek = $this->db->count_records_select(
            self::TABLE_NAME,
            "timestamp >= ?",
            [$weekStart]
        );
        
        // Events this month
        $monthStart = (new \DateTimeImmutable('first day of this month'))->getTimestamp();
        $eventsThisMonth = $this->db->count_records_select(
            self::TABLE_NAME,
            "timestamp >= ?",
            [$monthStart]
        );
        
        return new AuditEventStatistics(
            $totalEvents,
            $this->processGroupedResults($eventsByType, 'event_type'),
            $this->processGroupedResults($eventsByRiskLevel, 'risk_level'),
            $this->processGroupedResults($eventsByDate, 'date'),
            $this->processGroupedResults($topUsers, 'user_id'),
            $this->processGroupedResults($topResources, 'resource_id'),
            $highRiskEvents,
            $eventsToday,
            $eventsThisWeek,
            $eventsThisMonth
        );
    }
    
    /**
     * Get events grouped by date
     */
    public function getEventsByDate(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $sql = "SELECT DATE(FROM_UNIXTIME(timestamp)) as date, COUNT(*) as count 
                FROM {" . self::TABLE_NAME . "} 
                WHERE timestamp >= ? AND timestamp <= ? 
                GROUP BY DATE(FROM_UNIXTIME(timestamp)) 
                ORDER BY date ASC";
        
        return $this->db->get_records_sql($sql, [
            $from->getTimestamp(),
            $to->getTimestamp()
        ]);
    }
    
    /**
     * Get events grouped by user
     */
    public function getEventsByUser(int $limit = 10): array
    {
        $sql = "SELECT user_id, username, full_name, COUNT(*) as count 
                FROM {" . self::TABLE_NAME . "} 
                GROUP BY user_id, username, full_name 
                ORDER BY count DESC 
                LIMIT ?";
        
        return $this->db->get_records_sql($sql, [$limit]);
    }
    
    /**
     * Get events grouped by resource type
     */
    public function getEventsByResourceType(): array
    {
        $sql = "SELECT resource_type, COUNT(*) as count 
                FROM {" . self::TABLE_NAME . "} 
                GROUP BY resource_type 
                ORDER BY count DESC";
        
        return $this->db->get_records_sql($sql);
    }
    
    /**
     * Delete events older than specified date
     */
    public function deleteOlderThan(\DateTimeImmutable $date): int
    {
        return $this->db->delete_records_select(
            self::TABLE_NAME,
            "timestamp < ?",
            [$date->getTimestamp()]
        );
    }
    
    /**
     * Delete events by criteria
     */
    public function deleteByCriteria(AuditEventSearchCriteria $criteria): int
    {
        $sql = $this->buildSearchSql($criteria, true);
        $params = $this->buildSearchParams($criteria);
        
        return $this->db->delete_records_select(self::TABLE_NAME, $sql, $params);
    }
    
    /**
     * Convert AuditEvent to database record
     */
    private function eventToRecord(AuditEvent $event): \stdClass
    {
        $record = new \stdClass();
        
        $record->event_type = $event->getEventType()->value();
        $record->risk_level = $event->getRiskLevel()->value();
        
        // User context
        $userContext = $event->getUserContext();
        $record->user_id = $userContext->getUserId();
        $record->username = $userContext->getUsername();
        $record->user_email = $userContext->getEmail();
        $record->full_name = $userContext->getFullName();
        $record->user_roles = json_encode($userContext->getRoles());
        $record->ip_address = $userContext->getIpAddress();
        $record->user_agent = $userContext->getUserAgent();
        
        // Resource context
        $resourceContext = $event->getResourceContext();
        $record->resource_type = $resourceContext->getResourceType();
        $record->resource_id = $resourceContext->getResourceId();
        $record->resource_name = $resourceContext->getResourceName();
        $record->parent_id = $resourceContext->getParentId();
        $record->resource_metadata = json_encode($resourceContext->getMetadata());
        
        // Event data
        $record->timestamp = $event->getTimestamp()->getTimestamp();
        $record->description = $event->getDescription();
        $record->details = json_encode($event->getDetails());
        $record->session_id = $event->getSessionId();
        $record->course_id = $event->getCourseId();
        $record->change_log = json_encode($event->getChangeLog());
        
        return $record;
    }
    
    /**
     * Convert database record to AuditEvent
     */
    private function recordToEvent(\stdClass $record): AuditEvent
    {
        // Reconstruct user context
        $userContext = new UserContext(
            (int) $record->user_id,
            $record->username,
            $record->user_email,
            $record->full_name,
            json_decode($record->user_roles ?? '[]', true),
            $record->ip_address,
            $record->user_agent
        );
        
        // Reconstruct resource context
        $resourceContext = new ResourceContext(
            $record->resource_type,
            (int) $record->resource_id,
            $record->resource_name,
            $record->parent_id ? (int) $record->parent_id : null,
            json_decode($record->resource_metadata ?? '{}', true)
        );
        
        // Create event
        $event = new AuditEvent(
            EventType::fromString($record->event_type),
            $userContext,
            $resourceContext,
            $record->description,
            json_decode($record->details ?? '{}', true),
            $record->session_id,
            $record->course_id ? (int) $record->course_id : null,
            new \DateTimeImmutable('@' . $record->timestamp),
            (int) $record->id
        );
        
        // Override risk level if different from default
        if ($record->risk_level !== $event->getRiskLevel()->value()) {
            $event->overrideRiskLevel(RiskLevel::fromString($record->risk_level));
        }
        
        return $event;
    }
    
    /**
     * Build search SQL query
     */
    private function buildSearchSql(AuditEventSearchCriteria $criteria, bool $forDelete = false): string
    {
        $select = $forDelete ? '' : 'SELECT * FROM {' . self::TABLE_NAME . '}';
        $where = ['1=1'];
        
        if ($criteria->getUserId() !== null) {
            $where[] = 'user_id = :user_id';
        }
        
        if ($criteria->getEventTypes() !== null) {
            $eventTypePlaceholders = [];
            foreach ($criteria->getEventTypes() as $i => $eventType) {
                $eventTypePlaceholders[] = ":event_type_{$i}";
            }
            $where[] = 'event_type IN (' . implode(', ', $eventTypePlaceholders) . ')';
        }
        
        if ($criteria->getRiskLevels() !== null) {
            $riskLevelPlaceholders = [];
            foreach ($criteria->getRiskLevels() as $i => $riskLevel) {
                $riskLevelPlaceholders[] = ":risk_level_{$i}";
            }
            $where[] = 'risk_level IN (' . implode(', ', $riskLevelPlaceholders) . ')';
        }
        
        if ($criteria->getResourceType() !== null) {
            $where[] = 'resource_type = :resource_type';
            if ($criteria->getResourceId() !== null) {
                $where[] = 'resource_id = :resource_id';
            }
        }
        
        if ($criteria->getFromDate() !== null) {
            $where[] = 'timestamp >= :from_date';
        }
        
        if ($criteria->getToDate() !== null) {
            $where[] = 'timestamp <= :to_date';
        }
        
        if ($criteria->getCourseId() !== null) {
            $where[] = '(course_id = :course_id OR course_id IS NULL)';
        }
        
        if ($criteria->getSessionId() !== null) {
            $where[] = 'session_id = :session_id';
        }
        
        if ($criteria->getSearchText() !== null) {
            $where[] = '(description LIKE :search_text OR resource_name LIKE :search_text)';
        }
        
        return $select . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    }
    
    /**
     * Build search parameters
     */
    private function buildSearchParams(AuditEventSearchCriteria $criteria): array
    {
        $params = [];
        
        if ($criteria->getUserId() !== null) {
            $params['user_id'] = $criteria->getUserId();
        }
        
        if ($criteria->getEventTypes() !== null) {
            foreach ($criteria->getEventTypes() as $i => $eventType) {
                $params["event_type_{$i}"] = $eventType->value();
            }
        }
        
        if ($criteria->getRiskLevels() !== null) {
            foreach ($criteria->getRiskLevels() as $i => $riskLevel) {
                $params["risk_level_{$i}"] = $riskLevel->value();
            }
        }
        
        if ($criteria->getResourceType() !== null) {
            $params['resource_type'] = $criteria->getResourceType();
            if ($criteria->getResourceId() !== null) {
                $params['resource_id'] = $criteria->getResourceId();
            }
        }
        
        if ($criteria->getFromDate() !== null) {
            $params['from_date'] = $criteria->getFromDate()->getTimestamp();
        }
        
        if ($criteria->getToDate() !== null) {
            $params['to_date'] = $criteria->getToDate()->getTimestamp();
        }
        
        if ($criteria->getCourseId() !== null) {
            $params['course_id'] = $criteria->getCourseId();
        }
        
        if ($criteria->getSessionId() !== null) {
            $params['session_id'] = $criteria->getSessionId();
        }
        
        if ($criteria->getSearchText() !== null) {
            $params['search_text'] = '%' . $criteria->getSearchText() . '%';
        }
        
        return $params;
    }
    
    /**
     * Build ORDER BY clause
     */
    private function buildOrderBy(AuditEventSearchCriteria $criteria): string
    {
        $sortBy = $criteria->getSortBy() ?? 'timestamp';
        $sortOrder = $criteria->getSortOrder() ?? 'DESC';
        
        $validSortFields = ['id', 'timestamp', 'event_type', 'risk_level', 'user_id', 'resource_type'];
        if (!in_array($sortBy, $validSortFields)) {
            $sortBy = 'timestamp';
        }
        
        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
        
        return "{$sortBy} {$sortOrder}";
    }
    
    /**
     * Process grouped results for statistics
     */
    private function processGroupedResults(array $records, string $keyField): array
    {
        $result = [];
        foreach ($records as $record) {
            $key = $record->{$keyField};
            $result[$key] = (int) $record->count;
        }
        return $result;
    }
}