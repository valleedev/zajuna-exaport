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
 * Base Domain Event interface for audit system
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
interface DomainEvent
{
    /**
     * Get event name/type
     */
    public function getEventName(): string;
    
    /**
     * Get event timestamp
     */
    public function getOccurredAt(): \DateTimeImmutable;
    
    /**
     * Get event payload/data
     */
    public function getPayload(): array;
    
    /**
     * Get unique event ID
     */
    public function getEventId(): string;
    
    /**
     * Convert event to array for serialization
     */
    public function toArray(): array;
}