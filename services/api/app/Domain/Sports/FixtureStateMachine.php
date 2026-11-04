<?php

declare(strict_types=1);

namespace App\Domain\Sports;

use App\Models\Fixture;
use InvalidArgumentException;

final class FixtureStateMachine
{
    private const ALLOWED_TRANSITIONS = [
        'scheduled' => ['live', 'postponed', 'cancelled'],
        'live' => ['finished', 'interrupted', 'cancelled'],
        'interrupted' => ['live', 'cancelled', 'finished'],
        'postponed' => ['scheduled', 'cancelled'],
        'finished' => ['finished'], // idempotent updates
        'cancelled' => ['cancelled'],
    ];

    public function canTransition(string $currentStatus, string $newStatus): bool
    {
        if ($currentStatus === $newStatus) {
            return true;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
        return in_array($newStatus, $allowed, true);
    }

    public function transition(Fixture $fixture, string $newStatus): void
    {
        if (! $this->canTransition($fixture->status, $newStatus)) {
            throw new InvalidArgumentException(
                "Illegal fixture status transition from '{$fixture->status}' to '{$newStatus}'."
            );
        }

        $fixture->status = $newStatus;
        $fixture->source_revision += 1;
        $fixture->save();
    }
}
