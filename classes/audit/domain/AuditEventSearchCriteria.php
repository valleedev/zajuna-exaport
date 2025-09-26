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
 * Search criteria for audit events
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
final class AuditEventSearchCriteria
{
    private ?int $userId = null;
    private ?array $eventTypes = null;
    private ?array $riskLevels = null;
    private ?string $resourceType = null;
    private ?int $resourceId = null;
    private ?\DateTimeImmutable $fromDate = null;
    private ?\DateTimeImmutable $toDate = null;
    private ?int $courseId = null;
    private ?string $sessionId = null;
    private ?string $searchText = null;
    private int $limit = 100;
    private int $offset = 0;
    private string $sortBy = 'timestamp';
    private string $sortOrder = 'DESC';
    
    public function withUserId(int $userId): self
    {
        $criteria = clone $this;
        $criteria->userId = $userId;
        return $criteria;
    }
    
    public function withEventTypes(array $eventTypes): self
    {
        $criteria = clone $this;
        $criteria->eventTypes = $eventTypes;
        return $criteria;
    }
    
    public function withEventType(EventType $eventType): self
    {
        return $this->withEventTypes([$eventType]);
    }
    
    public function withRiskLevels(array $riskLevels): self
    {
        $criteria = clone $this;
        $criteria->riskLevels = $riskLevels;
        return $criteria;
    }
    
    public function withRiskLevel(RiskLevel $riskLevel): self
    {
        return $this->withRiskLevels([$riskLevel]);
    }
    
    public function withResource(string $resourceType, ?int $resourceId = null): self
    {
        $criteria = clone $this;
        $criteria->resourceType = $resourceType;
        $criteria->resourceId = $resourceId;
        return $criteria;
    }
    
    public function withDateRange(\DateTimeImmutable $from, \DateTimeImmutable $to): self
    {
        $criteria = clone $this;
        $criteria->fromDate = $from;
        $criteria->toDate = $to;
        return $criteria;
    }
    
    public function withCourseId(int $courseId): self
    {
        $criteria = clone $this;
        $criteria->courseId = $courseId;
        return $criteria;
    }
    
    public function withSessionId(string $sessionId): self
    {
        $criteria = clone $this;
        $criteria->sessionId = $sessionId;
        return $criteria;
    }
    
    public function withSearchText(string $searchText): self
    {
        $criteria = clone $this;
        $criteria->searchText = $searchText;
        return $criteria;
    }
    
    public function withLimit(int $limit): self
    {
        $criteria = clone $this;
        $criteria->limit = max(1, min($limit, 1000)); // Limit between 1 and 1000
        return $criteria;
    }
    
    public function withOffset(int $offset): self
    {
        $criteria = clone $this;
        $criteria->offset = max(0, $offset);
        return $criteria;
    }
    
    public function withSorting(string $sortBy, string $sortOrder = 'DESC'): self
    {
        $criteria = clone $this;
        $criteria->sortBy = $sortBy;
        $criteria->sortOrder = strtoupper($sortOrder);
        return $criteria;
    }
    
    public function onlyHighRisk(): self
    {
        return $this->withRiskLevels([
            RiskLevel::high(),
            RiskLevel::critical()
        ]);
    }
    
    public function onlyRecentEvents(int $hours = 24): self
    {
        $from = new \DateTimeImmutable("-{$hours} hours");
        $to = new \DateTimeImmutable();
        return $this->withDateRange($from, $to);
    }
    
    // Getters
    public function getUserId(): ?int
    {
        return $this->userId;
    }
    
    public function getEventTypes(): ?array
    {
        return $this->eventTypes;
    }
    
    public function getRiskLevels(): ?array
    {
        return $this->riskLevels;
    }
    
    public function getResourceType(): ?string
    {
        return $this->resourceType;
    }
    
    public function getResourceId(): ?int
    {
        return $this->resourceId;
    }
    
    public function getFromDate(): ?\DateTimeImmutable
    {
        return $this->fromDate;
    }
    
    public function getToDate(): ?\DateTimeImmutable
    {
        return $this->toDate;
    }
    
    public function getCourseId(): ?int
    {
        return $this->courseId;
    }
    
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }
    
    public function getSearchText(): ?string
    {
        return $this->searchText;
    }
    
    public function getLimit(): int
    {
        return $this->limit;
    }
    
    public function getOffset(): int
    {
        return $this->offset;
    }
    
    public function getSortBy(): string
    {
        return $this->sortBy;
    }
    
    public function getSortOrder(): string
    {
        return $this->sortOrder;
    }
    
    public function hasFilters(): bool
    {
        return $this->userId !== null
            || $this->eventTypes !== null
            || $this->riskLevels !== null
            || $this->resourceType !== null
            || $this->resourceId !== null
            || $this->fromDate !== null
            || $this->toDate !== null
            || $this->courseId !== null
            || $this->sessionId !== null
            || $this->searchText !== null;
    }
}