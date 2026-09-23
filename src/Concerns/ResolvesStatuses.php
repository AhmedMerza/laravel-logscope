<?php

declare(strict_types=1);

namespace LogScope\Concerns;

use LogScope\Enums\LogStatus;

/**
 * The set of status values a request is allowed to set.
 *
 * Shared by the entry and group controllers rather than copied into each:
 * the two must accept exactly the same vocabulary, or a status settable on an
 * entry would be rejected on its group.
 */
trait ResolvesStatuses
{
    /**
     * Get all valid status values (built-in + custom).
     *
     * @return array<int, string>
     */
    protected function getValidStatuses(): array
    {
        return LogStatus::allValues();
    }
}
