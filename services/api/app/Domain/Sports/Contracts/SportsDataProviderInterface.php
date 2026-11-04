<?php

declare(strict_types=1);

namespace App\Domain\Sports\Contracts;

use DateTimeInterface;

interface SportsDataProviderInterface
{
    /**
     * Unique identifier for the data provider (e.g. 'sportradar', 'opta', 'api_football').
     */
    public function getProviderName(): string;

    /**
     * Daily API quota limit agreed in provider contract.
     */
    public function getDailyQuotaLimit(): int;

    /**
     * Remaining calls available in the current quota window.
     */
    public function getRemainingQuota(): int;

    /**
     * Ingest or fetch fixtures for a competition within a date window.
     */
    public function fetchFixtures(string $competitionRef, ?DateTimeInterface $from = null, ?DateTimeInterface $to = null): array;

    /**
     * Fetch live match state (current minute, score, events).
     */
    public function fetchLiveMatchState(string $fixtureRef): array;

    /**
     * Fetch team squad / roster.
     */
    public function fetchTeamRoster(string $teamRef): array;
}

