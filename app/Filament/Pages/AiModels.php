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
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Forms\Components\Select;
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
        return 'Tried top to bottom, each provider\'s keys in order: when a key hits its limit, the next key or provider answers and the limited key rests. Free keys: aistudio.google.com, console.groq.com, openrouter.ai (":free" models).';
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
                TextColumn::make('keys')->label('Keys (tried in order)')->listWithLineBreaks(),
                TextColumn::make('state')->label('Status')->badge()->color(fn (string $state) => match (true) {
                    str_starts_with($state, 'Ready') => 'success',
                    str_starts_with($state, 'Resting') => 'warning',
                    $state === 'Off' => 'gray',
                    default => 'danger',
                }),
            ])
            ->recordActions([
                $this->testAction(),
                ActionGroup::make([$this->addKeyAction(), $this->editAction(), $this->moveAction('up'), $this->moveAction('down'), $this->removeKeyAction()])->tooltip('More'),
            ])
            ->emptyStateHeading('No AI models are configured');
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(): array
    {
        $chain = app(ChatModelChain::class);
        $rows = [];
        $order = 0;

        foreach (app(AiModelSettings::class)->all() as $name => $model) {
            $keys = ChatModel::forEachKey($name, $model);
            $resting = array_values(array_filter(array_map(fn (ChatModel $key) => $chain->restingUntil($key), $keys)));
            $keyless = (bool) ($model['keyless'] ?? false);

            $rows[$name] = [
                'name' => $name,
                'order' => ++$order,
                'label' => $model['label'] ?? $name,
                'model' => (string) ($model['model'] ?? '—'),
                'keys' => match (true) {
                    $keyless => [filled($model['base_url']) ? 'No key needed' : 'Needs its URL'],
                    $model['keys'] === [] => ['Not set'],
                    default => array_map(fn (array $key) => $key['hint'].($key['label'] ? ' '.$key['label'] : '').($key['source'] === 'env' ? ' (.env)' : ''), $model['keys']),
                },
                'state' => match (true) {
                    ! $model['enabled'] => 'Off',
                    $keys === [] => 'Not set up',
                    count($resting) === count($keys) => 'Resting until '.collect($resting)->min()->format('H:i:s'),
                    $resting !== [] => 'Ready ('.count($resting).' of '.count($keys).' keys resting)',
                    default => 'Ready',
                },
                'enabled' => (bool) $model['enabled'],
                'keyless' => $keyless,
                'admin_keys' => collect($model['keys'])->where('source', 'admin')->mapWithKeys(fn (array $key) => [$key['key_id'] => $key['hint'].($key['label'] ? ' '.$key['label'] : '')])->all(),
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
                TextInput::make('model')->label('Model ID')->maxLength(150)->placeholder(config('ai.models.'.$record['name'].'.model'))
                    ->helperText('Leave empty to use the default shown.'),
                TextInput::make('base_url')->label('API address')->maxLength(255)->placeholder(config('ai.models.'.$record['name'].'.base_url') ?: 'http://127.0.0.1:11434/v1')
                    ->helperText('Only change this for a self-hosted or compatible service.'),
                Text::make('Keys are managed with "Add key" and "Remove key". Changes are recorded in the audit log.'),
            ])
            ->action(function (array $record, array $data, Action $action) {
                try {
                    app(AiModelSettings::class)->update($record['name'], [
                        'enabled' => (bool) ($data['enabled'] ?? false),
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
                $results = app(ChatClient::class)->test($record['name']);
                $working = count(array_filter($results, fn (array $result) => $result['ok']));
                $title = match (true) {
                    $working === count($results) => 'It works',
                    $working > 0 => $working.' of '.count($results).' keys work',
                    default => 'It did not work',
                };

                Notification::make()->title($title)->body(implode("\n", array_column($results, 'message')))
                    ->{$working === count($results) ? 'success' : ($working > 0 ? 'warning' : 'danger')}()->persistent()->send();
            });
    }

    private function addKeyAction(): Action
    {
        return Action::make('add_key')
            ->label('Add key')
            ->icon('heroicon-o-key')
            ->color('gray')
            ->visible(fn (array $record) => ! $record['keyless'])
            ->modalHeading(fn (array $record) => 'Add a '.$record['label'].' key')
            ->modalDescription('Keys are tried in order: when one reaches its limit, the next one is used, then the next provider. Each free account\'s key has its own limit.')
            ->modalSubmitActionLabel('Add key')
            ->schema([
                TextInput::make('api_key')->label('API key')->password()->revealable(false)->autocomplete('new-password')->required(),
                TextInput::make('label')->label('Name (optional)')->maxLength(60)->placeholder('e.g. Second Google account'),
                Text::make('Stored encrypted and never shown again; only its last 4 characters are shown.'),
            ])
            ->action(function (array $record, array $data, Action $action) {
                try {
                    $key = app(AiModelSettings::class)->addKey($record['name'], (string) $data['api_key'], $data['label'] ?? null, auth()->user());
                } catch (AuthorizationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                    $action->halt();

                    return;
                } catch (ValidationException $e) {
                    Notification::make()->title('Not added')->body(collect($e->errors())->flatten()->implode(' '))->danger()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title('Key '.$key->hint().' added to '.$record['label'])->body('Press "Test" to check it.')->success()->send();
                $action->success();
            });
    }

    private function removeKeyAction(): Action
    {
        return Action::make('remove_key')
            ->label('Remove key')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (array $record) => $record['admin_keys'] !== [])
            ->modalHeading(fn (array $record) => 'Remove a '.$record['label'].' key')
            ->modalSubmitActionLabel('Remove')
            ->schema(fn (array $record) => [
                Select::make('key_id')->label('Key')->options($record['admin_keys'])->required(),
                Text::make('Keys from .env are removed in the .env file.'),
            ])
            ->action(function (array $record, array $data) {
                try {
                    app(AiModelSettings::class)->removeKey($record['name'], (int) $data['key_id'], auth()->user());
                    Notification::make()->title('Key removed')->success()->send();
                } catch (AuthorizationException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    private function moveAction(string $direction): Action
    {
        return Action::make('move_'.$direction)
            ->label($direction === 'up' ? 'Move up' : 'Move down')
            ->icon($direction === 'up' ? 'heroicon-o-arrow-up' : 'heroicon-o-arrow-down')
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
