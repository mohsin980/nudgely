<?php

namespace App\Filament\Pages;

use App\Enums\Platform\PlatformPermission;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Models\AiModelSetting;
use App\Services\AI\Chat\AiModelSettings;
use App\Services\AI\Chat\ChatClient;
use App\Services\AI\Chat\ChatModel;
use App\Services\AI\Chat\ChatModelChain;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The AI models every AI feature uses, tried top to bottom: when one is rate-limited (e.g. a free tier's daily
 * cap) or down, the next one answers. A super admin sets each model's key, model name and address, switches
 * models on or off and changes the order. Keys are stored encrypted and never shown again.
 */
class AiModels extends Page implements HasTable
{
    use InteractsWithActions;
    use InteractsWithTable;
    use RequiresPlatformPermission;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 10;

    protected static ?string $title = 'AI Models';

    protected static ?string $slug = 'ai-models';

    protected static function requiredPermission(): PlatformPermission
    {
        return PlatformPermission::ManageSystemSettings;
    }

    public function getSubheading(): ?string
    {
        return 'Tried from top to bottom. When a model hits its limit or is down, the next one answers and the limited one rests for a while. Free keys: aistudio.google.com (Gemini), console.groq.com (Groq), openrouter.ai (models ending in ":free"). Empty fields fall back to .env.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->rows())
            ->paginated(false)
            ->columns([
                TextColumn::make('order')->label('#'),
                TextColumn::make('label')->label('Provider')->weight('bold'),
                TextColumn::make('model')->label('Model')->fontFamily('mono'),
                TextColumn::make('key')->label('Key'),
                TextColumn::make('state')->label('Status')->badge()->color(fn (string $state) => match (true) {
                    $state === 'Ready' => 'success',
                    str_starts_with($state, 'Resting') => 'warning',
                    $state === 'Off' => 'gray',
                    default => 'danger',
                }),
            ])
            ->recordActions([$this->testAction(), $this->editAction(), $this->moveAction('up'), $this->moveAction('down')])
            ->emptyStateHeading('No AI models are configured');
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $chain = app(ChatModelChain::class);
        $rows = [];
        $order = 0;

        foreach (app(AiModelSettings::class)->all() as $name => $model) {
            $ready = ChatModel::fromConfig($name, $model);
            $resting = $ready === null ? null : $chain->restingUntil($ready);

            $rows[$name] = [
                'name' => $name,
                'order' => ++$order,
                'label' => $model['label'] ?? $name,
                'model' => (string) ($model['model'] ?? '—'),
                'key' => match (true) {
                    $model['keyless'] ?? false => filled($model['base_url']) ? 'No key needed ('.$model['base_url'].')' : 'Needs its URL',
                    $model['key_source'] === 'admin' => $model['key_hint'].' (set here)',
                    $model['key_source'] === 'env' => $model['key_hint'].' (from .env)',
                    default => 'Not set',
                },
                'state' => match (true) {
                    ! $model['enabled'] => 'Off',
                    $ready === null => 'Not set up',
                    $resting !== null => 'Resting until '.$resting->format('H:i:s'),
                    default => 'Ready',
                },
                'enabled' => (bool) $model['enabled'],
                'keyless' => (bool) ($model['keyless'] ?? false),
            ];
        }

        return $rows;
    }

    private function editAction(): Action
    {
        return Action::make('edit')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->modalHeading(fn (array $record) => 'Edit '.$record['label'])
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (array $record): array {
                $stored = AiModelSetting::query()->where('provider', $record['name'])->first();

                return ['enabled' => $record['enabled'], 'model' => $stored?->model, 'base_url' => $stored?->base_url];
            })
            ->schema(fn (array $record) => [
                Toggle::make('enabled')->label('Use this model'),
                TextInput::make('api_key')->label('API key')->password()->revealable(false)->autocomplete('new-password')
                    ->visible(! $record['keyless'])
                    ->helperText('Current: '.$record['key'].'. Leave empty to keep it.'),
                Checkbox::make('clear_key')->label('Remove the key saved here (the .env key is used again, if any)')->visible(! $record['keyless']),
                TextInput::make('model')->label('Model ID')->maxLength(150)->placeholder(config('ai.models.'.$record['name'].'.model'))
                    ->helperText('Leave empty to use the default shown.'),
                TextInput::make('base_url')->label('API address')->maxLength(255)->placeholder(config('ai.models.'.$record['name'].'.base_url') ?: 'http://127.0.0.1:11434/v1')
                    ->helperText('Only change this for a self-hosted or compatible service.'),
                Text::make('Keys are stored encrypted and never shown again. Changes are recorded in the audit log (without the key).'),
            ])
            ->action(function (array $record, array $data, Action $action) {
                try {
                    app(AiModelSettings::class)->update($record['name'], [
                        'enabled' => (bool) ($data['enabled'] ?? false),
                        'api_key' => $data['api_key'] ?? null,
                        'clear_key' => (bool) ($data['clear_key'] ?? false),
                        'model' => $data['model'] ?? null,
                        'base_url' => $data['base_url'] ?? null,
                    ], auth()->user());
                } catch (AuthorizationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                } catch (ValidationException $e) {
                    Notification::make()->title('Not saved')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title($record['label'].' saved')->success()->send();
                $action->success();
            });
    }

    private function testAction(): Action
    {
        return Action::make('test')
            ->label('Test')
            ->icon('heroicon-o-signal')
            ->color('gray')
            ->action(function (array $record) {
                $result = app(ChatClient::class)->test($record['name']);
                Notification::make()->title($result['ok'] ? 'It works' : 'It did not work')->body($result['message'])
                    ->{$result['ok'] ? 'success' : 'danger'}()->send();
            });
    }

    private function moveAction(string $direction): Action
    {
        return Action::make('move_'.$direction)
            ->label($direction === 'up' ? 'Move up' : 'Move down')
            ->icon($direction === 'up' ? 'heroicon-o-arrow-up' : 'heroicon-o-arrow-down')
            ->iconButton()
            ->color('gray')
            ->action(function (array $record) use ($direction) {
                try {
                    app(AiModelSettings::class)->move($record['name'], $direction === 'up' ? -1 : 1, auth()->user());
                } catch (AuthorizationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
