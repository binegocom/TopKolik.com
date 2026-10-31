<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Models\StaffMember;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\PersonalAccessToken;

final class StaffAuthorizationPolicy
{
    /**
     * Staff session permissions are not role privileges. Check both the
     * persisted staff identity and the current, scoped token.
     *
     * No publish, billing mutation, advertiser access, staff grant or
     * role-management permission exists until persisted resource policies
     * and dual approval gates are implemented.
     */
    public function allows(
        Authenticatable $principal,
        string $action,
        StaffResourceContext $resource,
        ?PersonalAccessToken $token,
    ): bool {
        if (! $principal instanceof StaffMember || $resource->id < 1
            || ! $token || $token->expires_at?->isPast()
            || ! $token->can('staff:session')
            || $token->tokenable_type !== $principal->getMorphClass()
            || (string) $token->tokenable_id !== (string) $principal->getKey()) {
            return false;
        }

        // A long-lived PHP process must not preserve stale grants after a
        // role change or operator deactivation.
        $staff = StaffMember::query()->find($principal->getKey());
        if (! $staff || ! $staff->is_active
            || ! $staff->mfa_enrolled_at
            || ! $staff->email_verified_at
            || $resource->advertiserId !== null) {
            return false;
        }

        if ($staff->role === 'editor' && $resource->kind === 'editorial_article') {
            if ($resource->ownerStaffId !== (int) $staff->getKey()) {
                return false;
            }

            return match ($action) {
                'editorial.draft.view' =>
                    in_array($resource->state, ['draft', 'in_review', 'held'], true),
                'editorial.draft.edit',
                'editorial.draft.submit' => $resource->state === 'draft',
                'editorial.correction.propose' =>
                    $resource->state === 'published' && $token->can('staff:step-up'),
                default => false,
            };
        }

        if ($staff->role === 'support' && $resource->kind === 'support_ticket') {
            if ($resource->assignedStaffId !== (int) $staff->getKey()) {
                return false;
            }

            return match ($action) {
                'support.ticket.view' =>
                    in_array($resource->state, ['open', 'pending', 'resolved'], true),
                'support.ticket.reply' =>
                    in_array($resource->state, ['open', 'pending'], true),
                'support.ticket.escalate' =>
                    $resource->state === 'open' && $token->can('staff:step-up'),
                default => false,
            };
        }

        // Ads and finance are deliberately not authorized by this foundation:
        // their scopes depend on P03-008 and the finance dual-approval model.
        return false;
    }

    /**
     * Creation has no persisted resource ID yet; enforce a separate exact
     * editor privilege before the first insert. No general admin grant.
     */
    public function canCreateDraft(Authenticatable $principal, ?PersonalAccessToken $token): bool
    {
        if (! $principal instanceof StaffMember || ! $token
            || $token->expires_at?->isPast()
            || ! $token->can('staff:session')
            || $token->tokenable_type !== $principal->getMorphClass()
            || (string) $token->tokenable_id !== (string) $principal->getKey()) {
            return false;
        }

        $staff = StaffMember::query()->find($principal->getKey());

        return $staff !== null
            && $staff->role === 'editor'
            && $staff->is_active
            && $staff->email_verified_at !== null
            && $staff->mfa_enrolled_at !== null;
    }
}
