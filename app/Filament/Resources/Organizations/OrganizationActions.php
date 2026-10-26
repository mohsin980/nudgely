<?php

namespace App\Filament\Resources\Organizations;

use App\Exceptions\Platform\OrganizationStateException;
use App\Models\Organization;
use App\Services\Platform\OrganizationSuspension;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Suspend / reactivate with a confirmation step and a mandatory reason. The button is hidden from people who may
 * not use it, and the service checks the permission again on the server, so a crafted request is refused too.
 */
final class OrganizationActions
{
    public static function suspend(): Action
    {
        return Action::make('suspend')
            ->label('Suspend')
            ->icon('heroicon-o-pause-circle')
            ->color('danger')
            ->visible(fn (Organization $record) => ! $record->isSuspended() && (auth()->user()?->can('suspend', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading('Suspend this organization?')
            ->modalDescription('Everyone in this business is signed out and cannot sign in until it is reactivated. Their data, subscription and billing are not changed or deleted.')
            ->modalSubmitActionLabel('Suspend organization')
            ->schema(self::reasonField())
            ->action(fn (Organization $record, array $data, Action $action) => self::run($action, fn () => app(OrganizationSuspension::class)->suspend($record, auth()->user(), $data['reason']), 'Organization suspended.'));
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivate')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (Organization $record) => $record->isSuspended() && (auth()->user()?->can('reactivate', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading('Reactivate this organization?')
            ->modalDescription('Members can sign in again.')
            ->modalSubmitActionLabel('Reactivate organization')
            ->schema(self::reasonField())
            ->action(fn (Organization $record, array $data, Action $action) => self::run($action, fn () => app(OrganizationSuspension::class)->reactivate($record, auth()->user(), $data['reason']), 'Organization reactivated.'));
    }

    /** @return array<int, Textarea> */
    private static function reasonField(): array
    {
        return [
            Textarea::make('reason')
                ->label('Reason (kept in the internal audit log)')
                ->required()
                ->minLength(OrganizationSuspension::MIN_REASON)
                ->maxLength(OrganizationSuspension::MAX_REASON)
                ->rows(3),
        ];
    }

    private static function run(Action $action, \Closure $change, string $done): void
    {
        try {
            $change();
        } catch (AuthorizationException) {
            Notification::make()->title('You are not allowed to do this.')->danger()->send();
            $action->halt();

            return;
        } catch (OrganizationStateException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();

            return;
        }

        Notification::make()->title($done)->success()->send();
        $action->success();
    }
}
