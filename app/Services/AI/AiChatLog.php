<?php

namespace App\Services\AI;

use App\Enums\Platform\PlatformPermission;
use App\Jobs\EmailAiChatLogJob;
use App\Models\AssistantChat;
use App\Models\PlatformAuditLog;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A plain-text log of every AI assistant conversation on the platform (customer messages, AI replies, handovers).
 *
 * Lines are appended to one file. When it reaches the size set in Admin → AI Models (5 MB by default), the file is
 * set aside, emailed to the platform's log address as an attachment, and deleted once sent; a new file starts.
 * The log holds customers' messages, so it is kept in private storage and only ever emailed to that address.
 */
class AiChatLog
{
    public const SETTING = 'ai_chat_log';

    public const FILE = 'current.log';

    /**
     * @return array{enabled: bool, email: ?string, max_mb: int}
     */
    public function settings(): array
    {
        $settings = PlatformSetting::get(self::SETTING, [
            'enabled' => true,
            'email' => config('ai.chat_log.email'),
            'max_mb' => (int) config('ai.chat_log.max_mb', 5),
        ]);

        return ['enabled' => (bool) $settings['enabled'], 'email' => filled($settings['email']) ? (string) $settings['email'] : null, 'max_mb' => max(1, (int) $settings['max_mb'])];
    }

    /**
     * @param  array{enabled?: bool, email?: ?string, max_mb?: int|string}  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function updateSettings(array $input, User $actor): void
    {
        if (! $actor->can(PlatformPermission::ManageSystemSettings->value)) {
            throw new AuthorizationException('Only a super admin can change the AI chat log.');
        }

        $data = Validator::make($input, [
            'enabled' => ['boolean'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'max_mb' => ['required', 'integer', 'min:1', 'max:20'],
        ], [], ['max_mb' => 'file size'])->validate();

        PlatformSetting::put(self::SETTING, ['enabled' => (bool) ($data['enabled'] ?? true), 'email' => $data['email'] ?? null, 'max_mb' => (int) $data['max_mb']], $actor);
        PlatformAuditLog::record('ai_chat_log_settings_updated', $actor, changes: ['enabled' => (bool) ($data['enabled'] ?? true), 'max_mb' => (int) $data['max_mb']], targetType: 'platform_setting', targetId: self::SETTING);
    }

    /**
     * Add one exchange to the log. Never throws: the log must not stop the assistant.
     *
     * @param  list<string>  $customerMessages
     */
    public function record(AssistantChat $chat, string $assistantName, array $customerMessages, ?string $reply, ?string $event = null): void
    {
        try {
            if (! $this->settings()['enabled']) {
                return;
            }

            $chat->loadMissing(['customer:id,name,phone_e164', 'conversation.organization:id,name']);
            $organization = $chat->conversation?->organization;
            $entry = '['.now()->toDateTimeString().' UTC] '.($organization?->name ?? 'Business').' (#'.$chat->organization_id.')'
                .' · '.($chat->customer?->name ?? 'Customer').' '.($chat->customer?->phone_e164 ?? '')
                .' · conversation #'.$chat->conversation_id."\n";

            foreach ($customerMessages as $text) {
                $entry .= 'Customer: '.$this->oneBlock($text)."\n";
            }

            if ($reply !== null) {
                $entry .= $assistantName.' (AI): '.$this->oneBlock($reply)."\n";
            }

            if ($event !== null) {
                $entry .= '» '.$event."\n";
            }

            $this->append($entry."\n");
        } catch (Throwable $e) {
            Log::warning('AI chat log entry not written.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Current log size in bytes.
     */
    public function size(): int
    {
        clearstatcache(true, $this->path(self::FILE));

        return is_file($this->path(self::FILE)) ? (int) filesize($this->path(self::FILE)) : 0;
    }

    /**
     * Set the current file aside and email it now (the admin's "Email log now"). Returns false when there is nothing to send.
     */
    public function sendNow(): bool
    {
        // Full files kept earlier (e.g. while no address was set) go too.
        $waiting = glob($this->path('ai-chat-log-*.txt')) ?: [];

        foreach ($waiting as $file) {
            EmailAiChatLogJob::dispatch(basename($file));
        }

        return $this->rotate(force: true) || $waiting !== [];
    }

    public function path(string $file = ''): string
    {
        return storage_path('app/private/ai-chat-log'.($file === '' ? '' : '/'.$file));
    }

    private function append(string $entry): void
    {
        File::ensureDirectoryExists($this->path(), 0700);
        $handle = fopen($this->path(self::FILE), 'ab');

        if ($handle === false) {
            return;
        }

        try {
            flock($handle, LOCK_EX);
            fwrite($handle, $entry);
            fflush($handle);
            $full = fstat($handle)['size'] >= $this->settings()['max_mb'] * 1024 * 1024;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if ($full) {
            $this->rotate();
        }
    }

    /**
     * Move the current file aside (under the same lock writers use) and queue its email. A new file starts with the
     * next entry.
     */
    private function rotate(bool $force = false): bool
    {
        $current = $this->path(self::FILE);

        if (! is_file($current)) {
            return false;
        }

        $handle = fopen($current, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            flock($handle, LOCK_EX);
            clearstatcache(true, $current);
            $size = (int) filesize($current);

            if ($size === 0 || (! $force && $size < $this->settings()['max_mb'] * 1024 * 1024)) {
                return false; // another writer already rotated it
            }

            $name = 'ai-chat-log-'.now()->format('Y-m-d-His').'-'.bin2hex(random_bytes(3)).'.txt';
            rename($current, $this->path($name));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        EmailAiChatLogJob::dispatch($name);

        return true;
    }

    private function oneBlock(string $text): string
    {
        return str_replace("\n", "\n    ", trim($text));
    }
}
