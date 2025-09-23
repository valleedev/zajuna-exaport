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
 * Domain Event for item deletion
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
final class ItemDeletedEvent extends AbstractDomainEvent
{
    public function __construct(
        int $itemId,
        string $itemName,
        string $itemType,
        int $userId,
        array $deletedData = [],
        ?int $courseId = null
    ) {
        parent::__construct([
            'item_id' => $itemId,
            'item_name' => $itemName,
            'item_type' => $itemType,
            'user_id' => $userId,
            'deleted_data' => $deletedData,
            'course_id' => $courseId,
        ]);
    }
    
    public function getEventName(): string
    {
        return 'item.deleted';
    }
    
    public function getItemId(): int
    {
        return $this->payload['item_id'];
    }
    
    public function getItemName(): string
    {
        return $this->payload['item_name'];
    }
    
    public function getItemType(): string
    {
        return $this->payload['item_type'];
    }
    
    public function getUserId(): int
    {
        return $this->payload['user_id'];
    }
    
    public function getDeletedData(): array
    {
        return $this->payload['deleted_data'];
    }
    
    public function getCourseId(): ?int
    {
        return $this->payload['course_id'];
    }
}