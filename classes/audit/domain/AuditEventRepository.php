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

namespace block_exaport\audit\domain;

use block_exaport\audit\domain\valueobjects\EventType;
use block_exaport\audit\domain\valueobjects\RiskLevel;

/**
 * Repository interface for AuditEvent entities
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
interface AuditEventRepository
{
    /**
     * Save an audit event
     */
    public function save(AuditEvent $event): void;
    
    /**
     * Find audit event by ID
     */
    public function findById(int $id): ?AuditEvent;
    
    /**
     * Find events by user ID
     */
    public function findByUserId(int $userId, int $limit = 100, int $offset = 0): array;
    
    /**
     * Find events by resource
     */
    public function findByResource(string $resourceType, int $resourceId, int $limit = 100): array;
    
    /**
     * Find events by event type
     */
    public function findByEventType(EventType $eventType, int $limit = 100, int $offset = 0): array;
    
    /**
     * Find events by risk level
     */
    public function findByRiskLevel(RiskLevel $riskLevel, int $limit = 100, int $offset = 0): array;
    
    /**
     * Find events within date range
     */
    public function findByDateRange(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        int $limit = 100,
        int $offset = 0
    ): array;
    
    /**
     * Find events by course ID
     */
    public function findByCourseId(int $courseId, int $limit = 100, int $offset = 0): array;
    
    /**
     * Find events by session ID
     */
    public function findBySessionId(string $sessionId): array;
    
    /**
     * Find high-risk events
     */
    public function findHighRiskEvents(int $limit = 100, int $offset = 0): array;
    
    /**
     * Find recent events (last N minutes)
     */
    public function findRecentEvents(int $minutes = 30, int $limit = 100): array;
    
    /**
     * Search events with filters
     */
    public function search(AuditEventSearchCriteria $criteria): AuditEventSearchResult;
    
    /**
     * Count total events
     */
    public function countTotal(): int;
    
    /**
     * Count events by user
     */
    public function countByUser(int $userId): int;
    
    /**
     * Count events by event type
     */
    public function countByEventType(EventType $eventType): int;
    
    /**
     * Count events by risk level
     */
    public function countByRiskLevel(RiskLevel $riskLevel): int;
    
    /**
     * Get event statistics
     */
    public function getStatistics(): AuditEventStatistics;
    
    /**
     * Get events grouped by date
     */
    public function getEventsByDate(\DateTimeImmutable $from, \DateTimeImmutable $to): array;
    
    /**
     * Get events grouped by user
     */
    public function getEventsByUser(int $limit = 10): array;
    
    /**
     * Get events grouped by resource type
     */
    public function getEventsByResourceType(): array;
    
    /**
     * Delete events older than specified date
     */
    public function deleteOlderThan(\DateTimeImmutable $date): int;
    
    /**
     * Delete events by criteria
     */
    public function deleteByCriteria(AuditEventSearchCriteria $criteria): int;
}