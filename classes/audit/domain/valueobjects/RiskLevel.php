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
 * Value Object for Risk Levels in the audit system
 * 
 * @package    block_exaport
 * @subpackage audit
 * @author     ExaPort Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3
 * @since      Moodle 4.0
 */
final class RiskLevel
{
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';
    public const CRITICAL = 'critical';
    
    private string $value;
    
    private function __construct(string $value)
    {
        $this->guardIsValidRiskLevel($value);
        $this->value = $value;
    }
    
    /**
     * Create RiskLevel from string
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }
    
    /**
     * Factory methods for risk levels
     */
    public static function low(): self
    {
        return new self(self::LOW);
    }
    
    public static function medium(): self
    {
        return new self(self::MEDIUM);
    }
    
    public static function high(): self
    {
        return new self(self::HIGH);
    }
    
    public static function critical(): self
    {
        return new self(self::CRITICAL);
    }
    
    /**
     * Create RiskLevel based on EventType
     */
    public static function fromEventType(EventType $eventType): self
    {
        if ($eventType->isHighRisk()) {
            return self::high();
        }
        
        if ($eventType->isCreationEvent()) {
            return self::medium();
        }
        
        return self::low();
    }
    
    /**
     * Get the string value
     */
    public function value(): string
    {
        return $this->value;
    }
    
    /**
     * Get numeric value for comparison
     */
    public function getNumericValue(): int
    {
        $values = [
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::CRITICAL => 4,
        ];
        
        return $values[$this->value];
    }
    
    /**
     * Check if this risk level is higher than another
     */
    public function isHigherThan(RiskLevel $other): bool
    {
        return $this->getNumericValue() > $other->getNumericValue();
    }
    
    /**
     * Check if this risk level is lower than another
     */
    public function isLowerThan(RiskLevel $other): bool
    {
        return $this->getNumericValue() < $other->getNumericValue();
    }
    
    /**
     * Check if this is a high risk level (HIGH or CRITICAL)
     */
    public function isHighRisk(): bool
    {
        return in_array($this->value, [self::HIGH, self::CRITICAL], true);
    }
    
    /**
     * Get human-readable description
     */
    public function getDescription(): string
    {
        $descriptions = [
            self::LOW => 'Low risk - routine operations',
            self::MEDIUM => 'Medium risk - content creation/modification',
            self::HIGH => 'High risk - deletion or permission changes',
            self::CRITICAL => 'Critical risk - system-level changes',
        ];
        
        return $descriptions[$this->value];
    }
    
    /**
     * Get CSS class for UI styling
     */
    public function getCssClass(): string
    {
        $classes = [
            self::LOW => 'risk-low',
            self::MEDIUM => 'risk-medium',
            self::HIGH => 'risk-high',
            self::CRITICAL => 'risk-critical',
        ];
        
        return $classes[$this->value];
    }
    
    /**
     * Get color code for visualization
     */
    public function getColorCode(): string
    {
        $colors = [
            self::LOW => '#28a745',     // Green
            self::MEDIUM => '#ffc107',  // Yellow
            self::HIGH => '#fd7e14',    // Orange
            self::CRITICAL => '#dc3545', // Red
        ];
        
        return $colors[$this->value];
    }
    
    /**
     * Get all valid risk levels
     */
    public static function getAllValidLevels(): array
    {
        return [
            self::LOW,
            self::MEDIUM,
            self::HIGH,
            self::CRITICAL,
        ];
    }
    
    /**
     * Check equality with another RiskLevel
     */
    public function equals(RiskLevel $other): bool
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
     * Guard clause to ensure valid risk level
     */
    private function guardIsValidRiskLevel(string $value): void
    {
        if (!in_array($value, self::getAllValidLevels(), true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid risk level: %s', $value)
            );
        }
    }
}