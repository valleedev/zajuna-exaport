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
 * Value Object for Resource Context in the audit system
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
final class ResourceContext
{
    public const TYPE_FOLDER = 'folder';
    public const TYPE_ITEM = 'item';
    public const TYPE_CATEGORY = 'category';
    public const TYPE_VIEW = 'view';
    public const TYPE_COMPETENCE = 'competence';
    public const TYPE_COMMENT = 'comment';
    public const TYPE_SHARE = 'share';
    
    private string $resourceType;
    private int $resourceId;
    private string $resourceName;
    private ?int $parentId;
    private array $metadata;
    
    public function __construct(
        string $resourceType,
        int $resourceId,
        string $resourceName,
        ?int $parentId = null,
        array $metadata = []
    ) {
        $this->guardValidResourceType($resourceType);
        $this->guardValidResourceId($resourceId);
        $this->guardValidResourceName($resourceName);
        
        $this->resourceType = $resourceType;
        $this->resourceId = $resourceId;
        $this->resourceName = $resourceName;
        $this->parentId = $parentId;
        $this->metadata = $metadata;
    }
    
    /**
     * Create ResourceContext for a folder
     */
    public static function folder(int $folderId, string $folderName, ?int $parentId = null, array $metadata = []): self
    {
        return new self(self::TYPE_FOLDER, $folderId, $folderName, $parentId, $metadata);
    }
    
    /**
     * Create ResourceContext for an item
     */
    public static function item(int $itemId, string $itemName, ?int $folderId = null, array $metadata = []): self
    {
        return new self(self::TYPE_ITEM, $itemId, $itemName, $folderId, $metadata);
    }
    
    /**
     * Create ResourceContext for a category
     */
    public static function category(int $categoryId, string $categoryName, ?int $parentId = null, array $metadata = []): self
    {
        return new self(self::TYPE_CATEGORY, $categoryId, $categoryName, $parentId, $metadata);
    }
    
    /**
     * Create ResourceContext for a view
     */
    public static function view(int $viewId, string $viewName, ?int $userId = null, array $metadata = []): self
    {
        $viewMetadata = array_merge($metadata, ['owner_id' => $userId]);
        return new self(self::TYPE_VIEW, $viewId, $viewName, $userId, $viewMetadata);
    }
    
    /**
     * Create ResourceContext for a competence
     */
    public static function competence(int $competenceId, string $competenceName, array $metadata = []): self
    {
        return new self(self::TYPE_COMPETENCE, $competenceId, $competenceName, null, $metadata);
    }
    
    /**
     * Create ResourceContext for a comment
     */
    public static function comment(int $commentId, string $commentText, int $itemId, array $metadata = []): self
    {
        $commentMetadata = array_merge($metadata, ['item_id' => $itemId]);
        return new self(self::TYPE_COMMENT, $commentId, $commentText, $itemId, $commentMetadata);
    }
    
    /**
     * Create ResourceContext for a share
     */
    public static function share(int $shareId, string $shareName, int $resourceId, array $metadata = []): self
    {
        $shareMetadata = array_merge($metadata, ['shared_resource_id' => $resourceId]);
        return new self(self::TYPE_SHARE, $shareId, $shareName, $resourceId, $shareMetadata);
    }
    
    /**
     * Get resource type
     */
    public function getResourceType(): string
    {
        return $this->resourceType;
    }
    
    /**
     * Get resource ID
     */
    public function getResourceId(): int
    {
        return $this->resourceId;
    }
    
    /**
     * Get resource name
     */
    public function getResourceName(): string
    {
        return $this->resourceName;
    }
    
    /**
     * Get parent ID
     */
    public function getParentId(): ?int
    {
        return $this->parentId;
    }
    
    /**
     * Get metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
    
    /**
     * Get specific metadata value
     */
    public function getMetadataValue(string $key, $default = null)
    {
        return $this->metadata[$key] ?? $default;
    }
    
    /**
     * Add metadata
     */
    public function withMetadata(string $key, $value): self
    {
        $newMetadata = $this->metadata;
        $newMetadata[$key] = $value;
        
        return new self(
            $this->resourceType,
            $this->resourceId,
            $this->resourceName,
            $this->parentId,
            $newMetadata
        );
    }
    
    /**
     * Check if resource is a folder
     */
    public function isFolder(): bool
    {
        return $this->resourceType === self::TYPE_FOLDER;
    }
    
    /**
     * Check if resource is an item
     */
    public function isItem(): bool
    {
        return $this->resourceType === self::TYPE_ITEM;
    }
    
    /**
     * Check if resource is a view
     */
    public function isView(): bool
    {
        return $this->resourceType === self::TYPE_VIEW;
    }
    
    /**
     * Check if resource has a parent
     */
    public function hasParent(): bool
    {
        return $this->parentId !== null;
    }
    
    /**
     * Get resource path for display
     */
    public function getResourcePath(): string
    {
        return sprintf('%s/%d', $this->resourceType, $this->resourceId);
    }
    
    /**
     * Get display name with type
     */
    public function getDisplayName(): string
    {
        return sprintf('%s: %s', ucfirst($this->resourceType), $this->resourceName);
    }
    
    /**
     * Get resource identifier for unique identification
     */
    public function getResourceIdentifier(): string
    {
        return sprintf('%s_%d', $this->resourceType, $this->resourceId);
    }
    
    /**
     * Get all valid resource types
     */
    public static function getAllValidTypes(): array
    {
        return [
            self::TYPE_FOLDER,
            self::TYPE_ITEM,
            self::TYPE_CATEGORY,
            self::TYPE_VIEW,
            self::TYPE_COMPETENCE,
            self::TYPE_COMMENT,
            self::TYPE_SHARE,
        ];
    }
    
    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'resource_name' => $this->resourceName,
            'parent_id' => $this->parentId,
            'metadata' => $this->metadata,
        ];
    }
    
    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['resource_type'],
            $data['resource_id'],
            $data['resource_name'],
            $data['parent_id'] ?? null,
            $data['metadata'] ?? []
        );
    }
    
    /**
     * Check equality with another ResourceContext
     */
    public function equals(ResourceContext $other): bool
    {
        return $this->resourceType === $other->resourceType
            && $this->resourceId === $other->resourceId;
    }
    
    /**
     * Convert to string representation
     */
    public function __toString(): string
    {
        return $this->getDisplayName();
    }
    
    /**
     * Guard clause for valid resource type
     */
    private function guardValidResourceType(string $resourceType): void
    {
        if (!in_array($resourceType, self::getAllValidTypes(), true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid resource type: %s', $resourceType)
            );
        }
    }
    
    /**
     * Guard clause for valid resource ID
     */
    private function guardValidResourceId(int $resourceId): void
    {
        if ($resourceId <= 0) {
            throw new \InvalidArgumentException('Resource ID must be positive');
        }
    }
    
    /**
     * Guard clause for valid resource name
     */
    private function guardValidResourceName(string $resourceName): void
    {
        if (empty(trim($resourceName))) {
            throw new \InvalidArgumentException('Resource name cannot be empty');
        }
    }
}