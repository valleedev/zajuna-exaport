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
 * Statistics for audit events
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
final class AuditEventStatistics
{
    private int $totalEvents;
    private array $eventsByType;
    private array $eventsByRiskLevel;
    private array $eventsByDate;
    private array $topUsers;
    private array $topResources;
    private int $highRiskEvents;
    private int $eventsToday;
    private int $eventsThisWeek;
    private int $eventsThisMonth;
    
    public function __construct(
        int $totalEvents,
        array $eventsByType = [],
        array $eventsByRiskLevel = [],
        array $eventsByDate = [],
        array $topUsers = [],
        array $topResources = [],
        int $highRiskEvents = 0,
        int $eventsToday = 0,
        int $eventsThisWeek = 0,
        int $eventsThisMonth = 0
    ) {
        $this->totalEvents = $totalEvents;
        $this->eventsByType = $eventsByType;
        $this->eventsByRiskLevel = $eventsByRiskLevel;
        $this->eventsByDate = $eventsByDate;
        $this->topUsers = $topUsers;
        $this->topResources = $topResources;
        $this->highRiskEvents = $highRiskEvents;
        $this->eventsToday = $eventsToday;
        $this->eventsThisWeek = $eventsThisWeek;
        $this->eventsThisMonth = $eventsThisMonth;
    }
    
    /**
     * Get total number of events
     */
    public function getTotalEvents(): int
    {
        return $this->totalEvents;
    }
    
    /**
     * Get events grouped by type
     */
    public function getEventsByType(): array
    {
        return $this->eventsByType;
    }
    
    /**
     * Get events grouped by risk level
     */
    public function getEventsByRiskLevel(): array
    {
        return $this->eventsByRiskLevel;
    }
    
    /**
     * Get events grouped by date
     */
    public function getEventsByDate(): array
    {
        return $this->eventsByDate;
    }
    
    /**
     * Get top users by activity
     */
    public function getTopUsers(): array
    {
        return $this->topUsers;
    }
    
    /**
     * Get top resources by activity
     */
    public function getTopResources(): array
    {
        return $this->topResources;
    }
    
    /**
     * Get number of high-risk events
     */
    public function getHighRiskEvents(): int
    {
        return $this->highRiskEvents;
    }
    
    /**
     * Get events count for today
     */
    public function getEventsToday(): int
    {
        return $this->eventsToday;
    }
    
    /**
     * Get events count for this week
     */
    public function getEventsThisWeek(): int
    {
        return $this->eventsThisWeek;
    }
    
    /**
     * Get events count for this month
     */
    public function getEventsThisMonth(): int
    {
        return $this->eventsThisMonth;
    }
    
    /**
     * Get risk percentage
     */
    public function getHighRiskPercentage(): float
    {
        if ($this->totalEvents === 0) {
            return 0.0;
        }
        
        return round(($this->highRiskEvents / $this->totalEvents) * 100, 2);
    }
    
    /**
     * Get most common event type
     */
    public function getMostCommonEventType(): ?string
    {
        if (empty($this->eventsByType)) {
            return null;
        }
        
        return array_key_first($this->eventsByType);
    }
    
    /**
     * Get most active user
     */
    public function getMostActiveUser(): ?array
    {
        if (empty($this->topUsers)) {
            return null;
        }
        
        return reset($this->topUsers);
    }
    
    /**
     * Get most accessed resource
     */
    public function getMostAccessedResource(): ?array
    {
        if (empty($this->topResources)) {
            return null;
        }
        
        return reset($this->topResources);
    }
    
    /**
     * Calculate growth percentage
     */
    public function getGrowthPercentage(): float
    {
        if ($this->eventsThisMonth === 0) {
            return 0.0;
        }
        
        // Simple growth calculation based on weekly vs monthly activity
        $weeklyAverage = $this->eventsThisWeek;
        $monthlyProjected = $weeklyAverage * 4;
        
        if ($monthlyProjected === 0) {
            return 0.0;
        }
        
        return round((($this->eventsThisMonth - $monthlyProjected) / $monthlyProjected) * 100, 2);
    }
    
    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'total_events' => $this->totalEvents,
            'events_by_type' => $this->eventsByType,
            'events_by_risk_level' => $this->eventsByRiskLevel,
            'events_by_date' => $this->eventsByDate,
            'top_users' => $this->topUsers,
            'top_resources' => $this->topResources,
            'high_risk_events' => $this->highRiskEvents,
            'events_today' => $this->eventsToday,
            'events_this_week' => $this->eventsThisWeek,
            'events_this_month' => $this->eventsThisMonth,
            'high_risk_percentage' => $this->getHighRiskPercentage(),
            'most_common_event_type' => $this->getMostCommonEventType(),
            'most_active_user' => $this->getMostActiveUser(),
            'most_accessed_resource' => $this->getMostAccessedResource(),
            'growth_percentage' => $this->getGrowthPercentage(),
        ];
    }
}