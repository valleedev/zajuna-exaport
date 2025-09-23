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
 * Value Object for User Context in the audit system
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
final class UserContext
{
    private int $userId;
    private string $username;
    private string $email;
    private string $fullName;
    private array $roles;
    private ?string $ipAddress;
    private ?string $userAgent;
    
    public function __construct(
        int $userId,
        string $username,
        string $email,
        string $fullName,
        array $roles = [],
        ?string $ipAddress = null,
        ?string $userAgent = null
    ) {
        $this->guardValidUserId($userId);
        $this->guardValidUsername($username);
        $this->guardValidEmail($email);
        
        $this->userId = $userId;
        $this->username = $username;
        $this->email = $email;
        $this->fullName = $fullName;
        $this->roles = $roles;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
    }
    
    /**
     * Create UserContext from Moodle user object
     */
    public static function fromMoodleUser(\stdClass $user, array $roles = []): self
    {
        global $CFG;
        
        $ipAddress = null;
        $userAgent = null;
        
        // Get IP address if available
        if (function_exists('getremoteaddr')) {
            $ipAddress = getremoteaddr();
        }
        
        // Get user agent if available
        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = $_SERVER['HTTP_USER_AGENT'];
        }
        
        return new self(
            (int) $user->id,
            $user->username,
            $user->email,
            fullname($user),
            $roles,
            $ipAddress,
            $userAgent
        );
    }
    
    /**
     * Create UserContext for current user
     */
    public static function fromCurrentUser(): self
    {
        global $USER;
        
        if (!$USER || empty($USER->id)) {
            throw new \RuntimeException('No authenticated user found');
        }
        
        // Get user roles in current context
        $roles = [];
        if (function_exists('get_user_roles')) {
            $context = \context_system::instance();
            $userRoles = get_user_roles($context, $USER->id);
            $roles = array_map(function($role) {
                return $role->shortname;
            }, $userRoles);
        }
        
        return self::fromMoodleUser($USER, $roles);
    }
    
    /**
     * Create anonymous user context for system operations
     */
    public static function system(): self
    {
        return new self(
            0,
            'system',
            'system@localhost',
            'System',
            ['system'],
            null,
            'System Process'
        );
    }
    
    /**
     * Get user ID
     */
    public function getUserId(): int
    {
        return $this->userId;
    }
    
    /**
     * Get username
     */
    public function getUsername(): string
    {
        return $this->username;
    }
    
    /**
     * Get email
     */
    public function getEmail(): string
    {
        return $this->email;
    }
    
    /**
     * Get full name
     */
    public function getFullName(): string
    {
        return $this->fullName;
    }
    
    /**
     * Get user roles
     */
    public function getRoles(): array
    {
        return $this->roles;
    }
    
    /**
     * Get IP address
     */
    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }
    
    /**
     * Get user agent
     */
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
    
    /**
     * Check if user has specific role
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
    
    /**
     * Check if user is system user
     */
    public function isSystemUser(): bool
    {
        return $this->userId === 0 || $this->hasRole('system');
    }
    
    /**
     * Check if user is administrator
     */
    public function isAdmin(): bool
    {
        return $this->hasRole('admin') || $this->hasRole('manager');
    }
    
    /**
     * Check if user is teacher
     */
    public function isTeacher(): bool
    {
        return $this->hasRole('teacher') || $this->hasRole('editingteacher');
    }
    
    /**
     * Get display name for audit logs
     */
    public function getDisplayName(): string
    {
        if ($this->isSystemUser()) {
            return 'System';
        }
        
        return $this->fullName . ' (' . $this->username . ')';
    }
    
    /**
     * Get anonymized version for privacy compliance
     */
    public function getAnonymized(): self
    {
        return new self(
            $this->userId,
            'user_' . $this->userId,
            'anonymized@example.com',
            'Anonymized User',
            $this->roles,
            null, // Remove IP address for privacy
            null  // Remove user agent for privacy
        );
    }
    
    /**
     * Convert to array for serialization
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'username' => $this->username,
            'email' => $this->email,
            'full_name' => $this->fullName,
            'roles' => $this->roles,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
        ];
    }
    
    /**
     * Create from array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['user_id'],
            $data['username'],
            $data['email'],
            $data['full_name'],
            $data['roles'] ?? [],
            $data['ip_address'] ?? null,
            $data['user_agent'] ?? null
        );
    }
    
    /**
     * Check equality with another UserContext
     */
    public function equals(UserContext $other): bool
    {
        return $this->userId === $other->userId;
    }
    
    /**
     * Convert to string representation
     */
    public function __toString(): string
    {
        return $this->getDisplayName();
    }
    
    /**
     * Guard clause for valid user ID
     */
    private function guardValidUserId(int $userId): void
    {
        if ($userId < 0) {
            throw new \InvalidArgumentException('User ID must be non-negative');
        }
    }
    
    /**
     * Guard clause for valid username
     */
    private function guardValidUsername(string $username): void
    {
        if (empty(trim($username))) {
            throw new \InvalidArgumentException('Username cannot be empty');
        }
    }
    
    /**
     * Guard clause for valid email
     */
    private function guardValidEmail(string $email): void
    {
        if (empty(trim($email))) {
            throw new \InvalidArgumentException('Email cannot be empty');
        }
    }
}