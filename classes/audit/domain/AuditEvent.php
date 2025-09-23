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
use block_exaport\audit\domain\valueobjects\UserContext;
use block_exaport\audit\domain\valueobjects\ResourceContext;

/**
 * AuditEvent Aggregate Root - represents a complete audit trail entry
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
final class AuditEvent
{
    private ?int $id;
    private EventType $eventType;
    private RiskLevel $riskLevel;
    private UserContext $userContext;
    private ResourceContext $resourceContext;
    private \DateTimeImmutable $timestamp;
    private string $description;
    private array $details;
    private ?string $sessionId;
    private ?int $courseId;
    private array $changeLog;
    
    public function __construct(
        EventType $eventType,
        UserContext $userContext,
        ResourceContext $resourceContext,
        string $description,
        array $details = [],
        ?string $sessionId = null,
        ?int $courseId = null,
        ?\DateTimeImmutable $timestamp = null,
        ?int $id = null
    ) {
        $this->guardValidDescription($description);
        
        $this->id = $id;
        $this->eventType = $eventType;
        $this->riskLevel = RiskLevel::fromEventType($eventType);
        $this->userContext = $userContext;
        $this->resourceContext = $resourceContext;
        $this->timestamp = $timestamp ?? new \DateTimeImmutable();
        $this->description = $description;
        $this->details = $details;
        $this->sessionId = $sessionId ?? $this->generateSessionId();
        $this->courseId = $courseId;
        $this->changeLog = [];
    }
    
    /**
     * Create a folder creation event
     */
    public static function folderCreated(
        UserContext $userContext,
        int $folderId,
        string $folderName,
        ?int $parentId = null,
        array $metadata = []
    ): self {
        $resourceContext = ResourceContext::folder($folderId, $folderName, $parentId, $metadata);
        $description = sprintf('Folder "%s" created', $folderName);
        
        return new self(
            EventType::folderCreated(),
            $userContext,
            $resourceContext,
            $description,
            ['parent_id' => $parentId]
        );
    }
    
    /**
     * Create a folder deletion event
     */
    public static function folderDeleted(
        UserContext $userContext,
        int $folderId,
        string $folderName,
        array $folderData = []
    ): self {
        $resourceContext = ResourceContext::folder($folderId, $folderName, null, $folderData);
        $description = sprintf('Folder "%s" deleted', $folderName);
        
        return new self(
            EventType::folderDeleted(),
            $userContext,
            $resourceContext,
            $description,
            ['deleted_data' => $folderData]
        );
    }
    
    /**
     * Create a folder blocked event
     */
    public static function folderBlocked(
        UserContext $userContext,
        int $folderId,
        string $folderName,
        string $reason = ''
    ): self {
        $resourceContext = ResourceContext::folder($folderId, $folderName);
        $description = sprintf('Folder "%s" blocked', $folderName);
        
        return new self(
            EventType::folderBlocked(),
            $userContext,
            $resourceContext,
            $description,
            ['reason' => $reason]
        );
    }
    
    /**
     * Create an item upload event
     */
    public static function itemUploaded(
        UserContext $userContext,
        int $itemId,
        string $itemName,
        string $itemType,
        ?int $folderId = null,
        array $metadata = []
    ): self {
        $itemMetadata = array_merge($metadata, ['type' => $itemType]);
        $resourceContext = ResourceContext::item($itemId, $itemName, $folderId, $itemMetadata);
        $description = sprintf('Item "%s" uploaded', $itemName);
        
        return new self(
            EventType::itemUploaded(),
            $userContext,
            $resourceContext,
            $description,
            [
                'item_type' => $itemType,
                'folder_id' => $folderId,
                'file_size' => $metadata['file_size'] ?? null
            ]
        );
    }
    
    /**
     * Create an item deletion event
     */
    public static function itemDeleted(
        UserContext $userContext,
        int $itemId,
        string $itemName,
        array $itemData = []
    ): self {
        $resourceContext = ResourceContext::item($itemId, $itemName, null, $itemData);
        $description = sprintf('Item "%s" deleted', $itemName);
        
        return new self(
            EventType::itemDeleted(),
            $userContext,
            $resourceContext,
            $description,
            ['deleted_data' => $itemData]
        );
    }
    
    /**
     * Create a view access event
     */
    public static function viewAccessed(
        UserContext $userContext,
        int $viewId,
        string $viewName,
        int $ownerId,
        array $metadata = []
    ): self {
        $resourceContext = ResourceContext::view($viewId, $viewName, $ownerId, $metadata);
        $description = sprintf('View "%s" accessed', $viewName);
        
        return new self(
            EventType::viewAccessed(),
            $userContext,
            $resourceContext,
            $description,
            ['view_owner_id' => $ownerId]
        );
    }
    
    /**
     * Create a permission granted event
     */
    public static function permissionGranted(
        UserContext $userContext,
        ResourceContext $resourceContext,
        string $permission,
        int $targetUserId
    ): self {
        $description = sprintf('Permission "%s" granted on %s', $permission, $resourceContext->getDisplayName());
        
        return new self(
            EventType::permissionGranted(),
            $userContext,
            $resourceContext,
            $description,
            [
                'permission' => $permission,
                'target_user_id' => $targetUserId
            ]
        );
    }
    
    /**
     * Get the event ID
     */
    public function getId(): ?int
    {
        return $this->id;
    }
    
    /**
     * Set the event ID (used by repository)
     */
    public function setId(int $id): void
    {
        if ($this->id !== null) {
            throw new \RuntimeException('Event ID can only be set once');
        }
        
        $this->id = $id;
    }
    
    /**
     * Get event type
     */
    public function getEventType(): EventType
    {
        return $this->eventType;
    }
    
    /**
     * Get risk level
     */
    public function getRiskLevel(): RiskLevel
    {
        return $this->riskLevel;
    }
    
    /**
     * Override risk level (for special cases)
     */
    public function overrideRiskLevel(RiskLevel $riskLevel): void
    {
        $this->riskLevel = $riskLevel;
        $this->addChangeLogEntry('risk_level_overridden', [
            'old_level' => $this->riskLevel->value(),
            'new_level' => $riskLevel->value()
        ]);
    }
    
    /**
     * Get user context
     */
    public function getUserContext(): UserContext
    {
        return $this->userContext;
    }
    
    /**
     * Get resource context
     */
    public function getResourceContext(): ResourceContext
    {
        return $this->resourceContext;
    }
    
    /**
     * Get timestamp
     */
    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }
    
    /**
     * Get description
     */
    public function getDescription(): string
    {
        return $this->description;
    }
    
    /**
     * Get details
     */
    public function getDetails(): array
    {
        return $this->details;
    }
    
    /**
     * Add detail
     */
    public function addDetail(string $key, $value): void
    {
        $this->details[$key] = $value;
        $this->addChangeLogEntry('detail_added', ['key' => $key, 'value' => $value]);
    }
    
    /**
     * Get session ID
     */
    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }
    
    /**
     * Get course ID
     */
    public function getCourseId(): ?int
    {
        return $this->courseId;
    }
    
    /**
     * Set course ID
     */
    public function setCourseId(int $courseId): void
    {
        $this->courseId = $courseId;
        $this->addChangeLogEntry('course_id_set', ['course_id' => $courseId]);
    }
    
    /**
     * Get change log
     */
    public function getChangeLog(): array
    {
        return $this->changeLog;
    }
    
    /**
     * Check if event is high risk
     */
    public function isHighRisk(): bool
    {
        return $this->riskLevel->isHighRisk();
    }
    
    /**
     * Check if event happened in the last N minutes
     */
    public function isRecent(int $minutes = 30): bool
    {
        $threshold = new \DateTimeImmutable("-{$minutes} minutes");
        return $this->timestamp >= $threshold;
    }
    
    /**
     * Check if event belongs to specific user
     */
    public function belongsToUser(int $userId): bool
    {
        return $this->userContext->getUserId() === $userId;
    }
    
    /**
     * Check if event is related to specific resource
     */
    public function isRelatedToResource(string $resourceType, int $resourceId): bool
    {
        return $this->resourceContext->getResourceType() === $resourceType
            && $this->resourceContext->getResourceId() === $resourceId;
    }
    
    /**
     * Get formatted timestamp
     */
    public function getFormattedTimestamp(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->timestamp->format($format);
    }
    
    /**
     * Get summary for logging
     */
    public function getSummary(): string
    {
        return sprintf(
            '[%s] %s - %s by %s',
            $this->getRiskLevel()->value(),
            $this->getEventType()->value(),
            $this->getDescription(),
            $this->getUserContext()->getDisplayName()
        );
    }
    
    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->eventType->value(),
            'risk_level' => $this->riskLevel->value(),
            'user_context' => $this->userContext->toArray(),
            'resource_context' => $this->resourceContext->toArray(),
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'description' => $this->description,
            'details' => $this->details,
            'session_id' => $this->sessionId,
            'course_id' => $this->courseId,
            'change_log' => $this->changeLog,
        ];
    }
    
    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        $userContext = UserContext::fromArray($data['user_context']);
        $resourceContext = ResourceContext::fromArray($data['resource_context']);
        $eventType = EventType::fromString($data['event_type']);
        $timestamp = new \DateTimeImmutable($data['timestamp']);
        
        $event = new self(
            $eventType,
            $userContext,
            $resourceContext,
            $data['description'],
            $data['details'] ?? [],
            $data['session_id'] ?? null,
            $data['course_id'] ?? null,
            $timestamp,
            $data['id'] ?? null
        );
        
        // Override risk level if different from default
        if (isset($data['risk_level']) && $data['risk_level'] !== $event->riskLevel->value()) {
            $event->riskLevel = RiskLevel::fromString($data['risk_level']);
        }
        
        // Restore change log
        $event->changeLog = $data['change_log'] ?? [];
        
        return $event;
    }
    
    /**
     * Add entry to change log
     */
    private function addChangeLogEntry(string $action, array $data = []): void
    {
        $this->changeLog[] = [
            'action' => $action,
            'data' => $data,
            'timestamp' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Generate session ID
     */
    private function generateSessionId(): string
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_id();
        }
        
        return 'system_' . uniqid();
    }
    
    /**
     * Guard clause for valid description
     */
    private function guardValidDescription(string $description): void
    {
        if (empty(trim($description))) {
            throw new \InvalidArgumentException('Description cannot be empty');
        }
    }
}