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

/**
 * Search result for audit events
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
final class AuditEventSearchResult
{
    private array $events;
    private int $totalCount;
    private int $offset;
    private int $limit;
    private AuditEventSearchCriteria $criteria;
    
    public function __construct(
        array $events,
        int $totalCount,
        AuditEventSearchCriteria $criteria
    ) {
        $this->events = $events;
        $this->totalCount = $totalCount;
        $this->offset = $criteria->getOffset();
        $this->limit = $criteria->getLimit();
        $this->criteria = $criteria;
    }
    
    /**
     * Get the events
     */
    public function getEvents(): array
    {
        return $this->events;
    }
    
    /**
     * Get total count (without limit/offset)
     */
    public function getTotalCount(): int
    {
        return $this->totalCount;
    }
    
    /**
     * Get current page offset
     */
    public function getOffset(): int
    {
        return $this->offset;
    }
    
    /**
     * Get page limit
     */
    public function getLimit(): int
    {
        return $this->limit;
    }
    
    /**
     * Get search criteria
     */
    public function getCriteria(): AuditEventSearchCriteria
    {
        return $this->criteria;
    }
    
    /**
     * Get count of events in current page
     */
    public function getCount(): int
    {
        return count($this->events);
    }
    
    /**
     * Check if there are more pages
     */
    public function hasNextPage(): bool
    {
        return ($this->offset + $this->limit) < $this->totalCount;
    }
    
    /**
     * Check if there are previous pages
     */
    public function hasPreviousPage(): bool
    {
        return $this->offset > 0;
    }
    
    /**
     * Get next page offset
     */
    public function getNextPageOffset(): ?int
    {
        return $this->hasNextPage() ? ($this->offset + $this->limit) : null;
    }
    
    /**
     * Get previous page offset
     */
    public function getPreviousPageOffset(): ?int
    {
        if (!$this->hasPreviousPage()) {
            return null;
        }
        
        return max(0, $this->offset - $this->limit);
    }
    
    /**
     * Get total number of pages
     */
    public function getTotalPages(): int
    {
        return (int) ceil($this->totalCount / $this->limit);
    }
    
    /**
     * Get current page number (1-based)
     */
    public function getCurrentPage(): int
    {
        return (int) floor($this->offset / $this->limit) + 1;
    }
    
    /**
     * Check if result is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->events);
    }
    
    /**
     * Convert to array for API responses
     */
    public function toArray(): array
    {
        return [
            'events' => array_map(function(AuditEvent $event) {
                return $event->toArray();
            }, $this->events),
            'pagination' => [
                'total_count' => $this->totalCount,
                'current_count' => $this->getCount(),
                'offset' => $this->offset,
                'limit' => $this->limit,
                'current_page' => $this->getCurrentPage(),
                'total_pages' => $this->getTotalPages(),
                'has_next_page' => $this->hasNextPage(),
                'has_previous_page' => $this->hasPreviousPage(),
                'next_page_offset' => $this->getNextPageOffset(),
                'previous_page_offset' => $this->getPreviousPageOffset(),
            ]
        ];
    }
}