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
            self::Open => 'O',
            self::Investigating => 'I',
            self::Resolved => 'R',
            self::Ignored => 'X',
        };
    }

    /**
     * Every status value the install accepts — the four built in, plus any
     * added through the 'statuses' config block.
     *
     * The single source of the vocabulary. Validation, the cast and the
     * controllers all read it from here, so a status that config allows
     * cannot be rejected somewhere further down.
     *
     * @return array<int, string>
     */
    public static function allValues(): array
    {
        $values = array_column(self::cases(), 'value');

        foreach (array_keys((array) config('logscope.statuses', [])) as $value) {
            if (! in_array($value, $values, true)) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * The storable string for a status, rejecting anything the install does
     * not define.
     *
     * Replaces a bare `LogStatus::from()`, which threw on every custom status
     * the 'statuses' config block adds — so a value the package documents and
     * the controllers accept could not actually be written.
     *
     * @throws \InvalidArgumentException when the status is not built in and
     *                                   not declared in config
     */
    public static function valueOf(self|string $status): string
    {
        if ($status instanceof self) {
            return $status->value;
        }

        if (! in_array($status, self::allValues(), true)) {
            throw new \InvalidArgumentException(
                "Unknown log status [{$status}]. Add it to the 'statuses' config block to use it."
            );
        }

        return $status;
    }

    /**
     * Whether a status value means "no action needed", for built-in and
     * custom statuses alike.
     *
     * A custom status carries its own `closed` flag in config; one that
     * doesn't declare it is treated as open, which is the safer default —
     * a status wrongly counted as closed hides logs from the default filter.
     */
    public static function isClosedValue(self|string|null $status): bool
    {
        if ($status instanceof self) {
            return $status->isClosed();
        }

        if ($status === null) {
            return false;
        }

        if ($enum = self::tryFrom($status)) {
            return $enum->isClosed();
        }

        $configured = (array) config('logscope.statuses', []);

        return (bool) ($configured[$status]['closed'] ?? false);
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
