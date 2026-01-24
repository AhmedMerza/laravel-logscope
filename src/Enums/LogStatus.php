<?php

declare(strict_types=1);

namespace LogScope\Enums;

enum LogStatus: string
{
    case Open = 'open';
    case Investigating = 'investigating';
    case Resolved = 'resolved';
    case Ignored = 'ignored';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Investigating => 'Investigating',
            self::Resolved => 'Resolved',
            self::Ignored => 'Ignored',
        };
    }

    /**
     * Get color for UI display.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'gray',
            self::Investigating => 'yellow',
            self::Resolved => 'green',
            self::Ignored => 'slate',
        };
    }

    /**
     * Check if this status is considered "closed" (not needing attention).
     */
    public function isClosed(): bool
    {
        return in_array($this, [self::Resolved, self::Ignored]);
    }

    /**
     * Get default keyboard shortcut for filtering.
     */
    public function shortcut(): string
    {
        return match ($this) {
            self::Open => 'o',
            self::Investigating => 'i',
            self::Resolved => 'r',
            self::Ignored => 'x',
        };
    }

    /**
     * Get all statuses as array for dropdowns.
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status) => [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
            ],
            self::cases()
        );
    }
}
