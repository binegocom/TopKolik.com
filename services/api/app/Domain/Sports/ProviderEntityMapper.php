<?php

declare(strict_types=1);

namespace App\Domain\Sports;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ProviderEntityMapper
{
    private const ALLOWED_ENTITY_TYPES = ['sport', 'competition', 'team', 'athlete', 'venue', 'fixture'];

    public function resolveEntityId(string $providerName, string $entityType, string $providerEntityId): ?int
    {
        $this->assertValidEntityType($entityType);

        $row = DB::table('provider_entity_mappings')
            ->where('provider_name', $providerName)
            ->where('entity_type', $entityType)
            ->where('provider_entity_id', $providerEntityId)
            ->select('entity_id')
            ->first();

        return $row ? (int) $row->entity_id : null;
    }

    public function linkEntity(
        string $providerName,
        string $entityType,
        string $providerEntityId,
        int $entityId,
        ?string $checksum = null,
    ): void {
        $this->assertValidEntityType($entityType);

        DB::table('provider_entity_mappings')->updateOrInsert(
            [
                'provider_name' => $providerName,
                'entity_type' => $entityType,
                'provider_entity_id' => $providerEntityId,
            ],
            [
                'entity_id' => $entityId,
                'raw_payload_checksum' => $checksum,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function recordTransfer(
        int $athleteId,
        int $toTeamId,
        ?int $fromTeamId = null,
        ?string $transferDate = null,
        string $type = 'permanent',
        ?float $fee = null,
        string $currency = 'EUR',
    ): int {
        return (int) DB::table('athlete_team_transfers')->insertGetId([
            'athlete_id' => $athleteId,
            'from_team_id' => $fromTeamId,
            'to_team_id' => $toTeamId,
            'transfer_date' => $transferDate ?? now()->format('Y-m-d'),
            'transfer_type' => $type,
            'fee_amount' => $fee,
            'currency' => $currency,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function getAthleteTransfers(int $athleteId): array
    {
        return DB::table('athlete_team_transfers as att')
            ->leftJoin('teams as from_t', 'from_t.id', '=', 'att.from_team_id')
            ->join('teams as to_t', 'to_t.id', '=', 'att.to_team_id')
            ->where('att.athlete_id', $athleteId)
            ->orderByDesc('att.transfer_date')
            ->select([
                'att.id',
                'att.transfer_date',
                'att.transfer_type',
                'att.fee_amount',
                'att.currency',
                'from_t.id as from_team_id',
                'from_t.name as from_team_name',
                'to_t.id as to_team_id',
                'to_t.name as to_team_name',
            ])
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    private function assertValidEntityType(string $type): void
    {
        if (! in_array($type, self::ALLOWED_ENTITY_TYPES, true)) {
            throw new InvalidArgumentException("Invalid entity type for provider mapping: {$type}");
        }
    }
}
