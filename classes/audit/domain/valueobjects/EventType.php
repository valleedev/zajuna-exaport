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

namespace block_exaport\audit\domain\valueobjects;

/**
 * Value Object for Event Types in the audit system
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
final class EventType
{
    // Folder Events
    public const FOLDER_CREATED = 'folder_created';
    public const FOLDER_DELETED = 'folder_deleted';
    public const FOLDER_RENAMED = 'folder_renamed';
    public const FOLDER_MOVED = 'folder_moved';
    public const FOLDER_BLOCKED = 'folder_blocked';
    public const FOLDER_UNBLOCKED = 'folder_unblocked';
    
    // Item Events
    public const ITEM_UPLOADED = 'item_uploaded';
    public const ITEM_DELETED = 'item_deleted';
    public const ITEM_UPDATED = 'item_updated';
    public const ITEM_SHARED = 'item_shared';
    public const ITEM_UNSHARED = 'item_unshared';
    public const ITEM_VIEWED = 'item_viewed';
    public const ITEM_DOWNLOADED = 'item_downloaded';
    
    // Category Events
    public const CATEGORY_CREATED = 'category_created';
    public const CATEGORY_DELETED = 'category_deleted';
    public const CATEGORY_UPDATED = 'category_updated';
    
    // View Events
    public const VIEW_CREATED = 'view_created';
    public const VIEW_DELETED = 'view_deleted';
    public const VIEW_SHARED = 'view_shared';
    public const VIEW_ACCESSED = 'view_accessed';
    
    // Permission Events
    public const PERMISSION_GRANTED = 'permission_granted';
    public const PERMISSION_REVOKED = 'permission_revoked';
    
    // Export/Import Events
    public const DATA_EXPORTED = 'data_exported';
    public const DATA_IMPORTED = 'data_imported';
    
    private string $value;
    
    private function __construct(string $value)
    {
        $this->guardIsValidEventType($value);
        $this->value = $value;
    }
    
    /**
     * Create EventType from string
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }
    
    /**
     * Factory methods for common events
     */
    public static function folderCreated(): self
    {
        return new self(self::FOLDER_CREATED);
    }
    
    public static function folderDeleted(): self
    {
        return new self(self::FOLDER_DELETED);
    }
    
    public static function folderBlocked(): self
    {
        return new self(self::FOLDER_BLOCKED);
    }
    
    public static function itemUploaded(): self
    {
        return new self(self::ITEM_UPLOADED);
    }
    
    public static function itemDeleted(): self
    {
        return new self(self::ITEM_DELETED);
    }
    
    /**
     * Get the string value
     */
    public function value(): string
    {
        return $this->value;
    }
    
    /**
     * Check if this event type represents a high-risk action
     */
    public function isHighRisk(): bool
    {
        $highRiskEvents = [
            self::FOLDER_DELETED,
            self::ITEM_DELETED,
            self::CATEGORY_DELETED,
            self::VIEW_DELETED,
            self::DATA_EXPORTED,
            self::PERMISSION_GRANTED,
            self::PERMISSION_REVOKED,
        ];
        
        return in_array($this->value, $highRiskEvents, true);
    }
    
    /**
     * Check if this event type is related to content creation
     */
    public function isCreationEvent(): bool
    {
        $creationEvents = [
            self::FOLDER_CREATED,
            self::ITEM_UPLOADED,
            self::CATEGORY_CREATED,
            self::VIEW_CREATED,
        ];
        
        return in_array($this->value, $creationEvents, true);
    }
    
    /**
     * Get human-readable description
     */
    public function getDescription(): string
    {
        $descriptions = [
            self::FOLDER_CREATED => 'Folder created',
            self::FOLDER_DELETED => 'Folder deleted',
            self::FOLDER_RENAMED => 'Folder renamed',
            self::FOLDER_MOVED => 'Folder moved',
            self::FOLDER_BLOCKED => 'Folder blocked',
            self::FOLDER_UNBLOCKED => 'Folder unblocked',
            
            self::ITEM_UPLOADED => 'Item uploaded',
            self::ITEM_DELETED => 'Item deleted',
            self::ITEM_UPDATED => 'Item updated',
            self::ITEM_SHARED => 'Item shared',
            self::ITEM_UNSHARED => 'Item unshared',
            self::ITEM_VIEWED => 'Item viewed',
            self::ITEM_DOWNLOADED => 'Item downloaded',
            
            self::CATEGORY_CREATED => 'Category created',
            self::CATEGORY_DELETED => 'Category deleted',
            self::CATEGORY_UPDATED => 'Category updated',
            
            self::VIEW_CREATED => 'View created',
            self::VIEW_DELETED => 'View deleted',
            self::VIEW_SHARED => 'View shared',
            self::VIEW_ACCESSED => 'View accessed',
            
            self::PERMISSION_GRANTED => 'Permission granted',
            self::PERMISSION_REVOKED => 'Permission revoked',
            
            self::DATA_EXPORTED => 'Data exported',
            self::DATA_IMPORTED => 'Data imported',
        ];
        
        return $descriptions[$this->value] ?? 'Unknown event';
    }
    
    /**
     * Get all valid event types
     */
    public static function getAllValidTypes(): array
    {
        return [
            self::FOLDER_CREATED,
            self::FOLDER_DELETED,
            self::FOLDER_RENAMED,
            self::FOLDER_MOVED,
            self::FOLDER_BLOCKED,
            self::FOLDER_UNBLOCKED,
            
            self::ITEM_UPLOADED,
            self::ITEM_DELETED,
            self::ITEM_UPDATED,
            self::ITEM_SHARED,
            self::ITEM_UNSHARED,
            self::ITEM_VIEWED,
            self::ITEM_DOWNLOADED,
            
            self::CATEGORY_CREATED,
            self::CATEGORY_DELETED,
            self::CATEGORY_UPDATED,
            
            self::VIEW_CREATED,
            self::VIEW_DELETED,
            self::VIEW_SHARED,
            self::VIEW_ACCESSED,
            
            self::PERMISSION_GRANTED,
            self::PERMISSION_REVOKED,
            
            self::DATA_EXPORTED,
            self::DATA_IMPORTED,
        ];
    }
    
    /**
     * Check equality with another EventType
     */
    public function equals(EventType $other): bool
    {
        return $this->value === $other->value;
    }
    
    /**
     * Convert to string representation
     */
    public function __toString(): string
    {
        return $this->value;
    }
    
    /**
     * Guard clause to ensure valid event type
     */
    private function guardIsValidEventType(string $value): void
    {
        if (!in_array($value, self::getAllValidTypes(), true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid event type: %s', $value)
            );
        }
    }
}