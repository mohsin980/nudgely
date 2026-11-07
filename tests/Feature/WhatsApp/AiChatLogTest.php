<?php

use App\Enums\Platform\PlatformRole;
use App\Filament\Pages\AiModels;
use App\Mail\AiChatLogMail;
use App\Models\AssistantChat;
use App\Services\AI\AiChatLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    File::deleteDirectory(app(AiChatLog::class)->path());
    Mail::fake();
    [$this->admin, $this->customer, $this->conversation] = followUpBusiness('Lahore Estates');
    $this->chat = new AssistantChat;
    $this->chat->forceFill(['organization_id' => $this->admin->organization_id, 'conversation_id' => $this->conversation->id, 'customer_id' => $this->customer->id, 'status' => 'active', 'replies' => 0])->save();
});

afterEach(fn () => File::deleteDirectory(app(AiChatLog::class)->path()));

test('each AI exchange is appended to the log as readable text', function () {
    app(AiChatLog::class)->record($this->chat, 'Sara', ['Hi', "I need a plot\nin DHA"], 'Hi! I\'m Sara, the AI assistant.', 'Lead qualified');

    $text = File::get(app(AiChatLog::class)->path(AiChatLog::FILE));
    expect($text)->toContain('Lahore Estates (#'.$this->admin->organization_id.') · John Smith')
        ->toContain("Customer: Hi\nCustomer: I need a plot\n    in DHA\nSara (AI): Hi! I'm Sara, the AI assistant.\n» Lead qualified");
});

test('when the file reaches its size it is emailed as an attachment, deleted, and a new file starts', function () {
    config(['ai.chat_log.email' => 'owner@quoteflow.test', 'ai.chat_log.max_mb' => 1]);
    $log = app(AiChatLog::class);

    $log->record($this->chat, 'Sara', [str_repeat('a', 600_000)], 'ok');
    Mail::assertNothingSent();
    $log->record($this->chat, 'Sara', [str_repeat('b', 600_000)], 'ok');

    Mail::assertSent(AiChatLogMail::class, fn (AiChatLogMail $mail) => $mail->hasTo('owner@quoteflow.test') && count($mail->attachments()) === 1);
    expect($log->size())->toBe(0)
        ->and(glob($log->path('ai-chat-log-*.txt')))->toBe([]); // emailed, then deleted

    $log->record($this->chat, 'Sara', ['Next customer'], 'Hello');
    expect($log->size())->toBeGreaterThan(0)->toBeLessThan(1000);
});

test('without an email address a full file is kept until an admin sends it', function () {
    config(['ai.chat_log.email' => null, 'ai.chat_log.max_mb' => 1]);
    $log = app(AiChatLog::class);

    $log->record($this->chat, 'Sara', [str_repeat('a', 1_100_000)], 'ok');
    Mail::assertNothingSent();
    expect(glob($log->path('ai-chat-log-*.txt')))->toHaveCount(1);

    $log->updateSettings(['enabled' => true, 'email' => 'me@quoteflow.test', 'max_mb' => 1], platformAdmin());
    expect($log->sendNow())->toBeTrue();
    Mail::assertSent(AiChatLogMail::class, fn ($mail) => $mail->hasTo('me@quoteflow.test'));
    expect(glob($log->path('ai-chat-log-*.txt')))->toBe([]);
});

test('logging can be switched off, and settings are checked', function () {
    $admin = platformAdmin();
    $log = app(AiChatLog::class);
    $log->updateSettings(['enabled' => false, 'email' => null, 'max_mb' => 5], $admin);

    $log->record($this->chat, 'Sara', ['Hi'], 'Hello');
    expect($log->size())->toBe(0)
        ->and(fn () => $log->updateSettings(['enabled' => true, 'email' => 'not-an-email', 'max_mb' => 99], $admin))->toThrow(ValidationException::class)
        ->and(fn () => $log->updateSettings(['enabled' => true, 'max_mb' => 5], platformAdmin(PlatformRole::SupportAdmin)))->toThrow(AuthorizationException::class);
});

test('the admin sets the log address and size and can email the log now', function () {
    $this->actingAs(platformAdmin());
    app(AiChatLog::class)->record($this->chat, 'Sara', ['Hi'], 'Hello');

    Livewire::test(AiModels::class)
        ->callAction('chat_log', ['enabled' => true, 'email' => 'mohsin@quoteflow.test', 'max_mb' => 3])
        ->assertNotified('Chat log settings saved')
        ->callAction('email_log_now')
        ->assertNotified('The chat log is being emailed.');

    expect(app(AiChatLog::class)->settings())->toBe(['enabled' => true, 'email' => 'mohsin@quoteflow.test', 'max_mb' => 3]);
    Mail::assertSent(AiChatLogMail::class, fn ($mail) => $mail->hasTo('mohsin@quoteflow.test'));
});
