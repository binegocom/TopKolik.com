<?php

declare(strict_types=1);

namespace App\Authorization;

/**
 * The caller must construct this from a repository-scoped, persisted resource.
 * Never hydrate this value object directly from client-provided JSON.
 */
final readonly class StaffResourceContext
{
    public function __construct(
        public int $id,
        public string $kind,
        public string $state,
        public ?int $ownerStaffId = null,
        public ?int $assignedStaffId = null,
        public ?int $advertiserId = null,
    ) {
    }
}
