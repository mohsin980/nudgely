<?php

namespace App\Filament\Resources\Users;

use App\Enums\Team\MemberStatus;
use App\Exceptions\Platform\UserAccountException;
use App\Models\User;
use App\Services\Platform\UserAccountService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Deactivate / reactivate with a confirmation step and a mandatory reason. Hidden from people who may not use them;
 * UserAccountService checks the permission again on the server.
 */
final class UserActions
{
    public static function deactivate(): Action
    {
        return Action::make('deactivate')
            ->label('Deactivate')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (User $record) => $record->status === MemberStatus::Active && (auth()->user()?->can('deactivate', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading('Deactivate this account?')
            ->modalDescription('They are signed out of every session now and cannot sign in until reactivated. Their data and password are not changed.')
            ->modalSubmitActionLabel('Deactivate account')
            ->schema(self::reasonField())
            ->action(fn (User $record, array $data, Action $action) => self::run($action, fn () => app(UserAccountService::class)->deactivate($record, auth()->user(), $data['reason']), 'Account deactivated.'));
    }

    public static function reactivate(): Action
    {
        return Action::make('reactivate')
            ->label('Reactivate')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (User $record) => $record->status === MemberStatus::Suspended && (auth()->user()?->can('reactivate', $record) ?? false))
            ->requiresConfirmation()
            ->modalHeading('Reactivate this account?')
            ->modalDescription('They can sign in again with their existing password.')
            ->modalSubmitActionLabel('Reactivate account')
            ->schema(self::reasonField())
            ->action(fn (User $record, array $data, Action $action) => self::run($action, fn () => app(UserAccountService::class)->reactivate($record, auth()->user(), $data['reason']), 'Account reactivated.'));
    }

    /** @return array<int, Textarea> */
    private static function reasonField(): array
    {
        return [
            Textarea::make('reason')
                ->label('Reason (kept in the internal audit log)')
                ->required()
                ->minLength(UserAccountService::MIN_REASON)
                ->maxLength(UserAccountService::MAX_REASON)
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
        } catch (UserAccountException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $action->halt();

            return;
        }

        Notification::make()->title($done)->success()->send();
        $action->success();
    }
}
