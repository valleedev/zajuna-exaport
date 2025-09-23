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

namespace block_exaport\audit\domain\events;

/**
 * Abstract base class for Domain Events
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
abstract class AbstractDomainEvent implements DomainEvent
{
    private string $eventId;
    private \DateTimeImmutable $occurredAt;
    protected array $payload;
    
    public function __construct(array $payload = [])
    {
        $this->eventId = $this->generateEventId();
        $this->occurredAt = new \DateTimeImmutable();
        $this->payload = $payload;
    }
    
    /**
     * Get unique event ID
     */
    public function getEventId(): string
    {
        return $this->eventId;
    }
    
    /**
     * Get event timestamp
     */
    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
    
    /**
     * Get event payload/data
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
    
    /**
     * Convert event to array for serialization
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->getEventName(),
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
            'payload' => $this->payload,
        ];
    }
    
    /**
     * Generate unique event ID
     */
    private function generateEventId(): string
    {
        return uniqid('evt_', true);
    }
}