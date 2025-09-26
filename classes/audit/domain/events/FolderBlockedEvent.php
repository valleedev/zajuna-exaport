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
 * Domain Event for folder blocking/unblocking
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
final class FolderBlockedEvent extends AbstractDomainEvent
{
    public function __construct(
        int $folderId,
        string $folderName,
        int $userId,
        bool $blocked,
        string $reason = '',
        ?int $courseId = null
    ) {
        parent::__construct([
            'folder_id' => $folderId,
            'folder_name' => $folderName,
            'user_id' => $userId,
            'blocked' => $blocked,
            'reason' => $reason,
            'course_id' => $courseId,
        ]);
    }
    
    public function getEventName(): string
    {
        return $this->payload['blocked'] ? 'folder.blocked' : 'folder.unblocked';
    }
    
    public function getFolderId(): int
    {
        return $this->payload['folder_id'];
    }
    
    public function getFolderName(): string
    {
        return $this->payload['folder_name'];
    }
    
    public function getUserId(): int
    {
        return $this->payload['user_id'];
    }
    
    public function isBlocked(): bool
    {
        return $this->payload['blocked'];
    }
    
    public function getReason(): string
    {
        return $this->payload['reason'];
    }
    
    public function getCourseId(): ?int
    {
        return $this->payload['course_id'];
    }
}