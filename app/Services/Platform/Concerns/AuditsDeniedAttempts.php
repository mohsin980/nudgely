<?php

namespace App\Services\Platform\Concerns;

use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Permission check for a sensitive platform action that leaves a trace when it is refused: the attempt is written to
 * the audit log with outcome "denied" (who tried what on which target) before the refusal is rethrown. No values the
 * person tried to set are recorded.
 */
trait AuditsDeniedAttempts
{
    /**
     * @param  array<string, mixed>|Model  $arguments  gate arguments
     * @param  array{type?: string, id?: string|int, organization_id?: ?int, subject_user_id?: ?int}  $target
     *
     * @throws AuthorizationException
     */
    protected function authorizeAudited(User $actor, string $ability, mixed $arguments, string $action, array $target = []): void
    {
        try {
            Gate::forUser($actor)->authorize($ability, $arguments);
        } catch (AuthorizationException $e) {
            PlatformAuditLog::record($action, $actor, $target['organization_id'] ?? null, null, $target['subject_user_id'] ?? null, null, $target['type'] ?? null, $target['id'] ?? null, PlatformAuditLog::DENIED);

            throw $e;
        }
    }
}
