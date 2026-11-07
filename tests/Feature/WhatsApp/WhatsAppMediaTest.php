<?php

use App\Jobs\AssistantReplyJob;
use App\Jobs\SendEmailJob;
use App\Jobs\TranscribeVoiceNoteJob;
use App\Livewire\Inbox\ShowConversation;
use App\Models\AssistantProfile;
use App\Models\Message;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppMediaStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

const MEDIA_PHONE_ID = '106540352242555';
const MEDIA_SECRET = 'app-secret-media-0123456789';

beforeEach(function () {
    Storage::fake('local');
    Queue::fake([SendEmailJob::class, AssistantReplyJob::class]);
    [$this->admin, $this->customer, $this->conversation] = followUpBusiness();
    $this->organization = $this->admin->organization;
    config(['ai.chain' => ['gemini', 'groq'], 'ai.models.gemini.api_key' => 'gemini-key', 'ai.models.groq.api_key' => 'groq-key', 'ai.classification.enabled' => false]);

    (new WhatsAppConnection)->forceFill([
        'organization_id' => $this->organization->id, 'phone_number_id' => MEDIA_PHONE_ID, 'business_account_id' => '1022', 'display_phone_number' => '+1 214-555-0100',
        'verified_name' => 'Dallas HVAC', 'access_token' => 'EAAG-media-token-123456', 'app_secret' => MEDIA_SECRET, 'webhook_verify_token' => 'v',
        'status' => WhatsAppConnection::STATUS_ACTIVE, 'verified_at' => now(),
    ])->save();
    $this->customer->forceFill(['phone' => '(214) 555-0142', 'whatsapp_opt_in_at' => now(), 'whatsapp_opt_in_source' => 'staff'])->save();
    (new AssistantProfile)->forceFill(['organization_id' => $this->organization->id, 'enabled' => true, 'assistant_name' => 'Sara', 'languages' => 'en,ur', 'about' => 'HVAC'])->save();
});

/** A customer sends media, as Meta delivers it. */
function sendsMedia(string $type, array $media, string $id = 'wamid.M1'): TestResponse
{
    $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
        'metadata' => ['phone_number_id' => MEDIA_PHONE_ID],
        'contacts' => [['profile' => ['name' => 'John'], 'wa_id' => '12145550142']],
        'messages' => [['from' => '12145550142', 'id' => $id, 'timestamp' => (string) time(), 'type' => $type, $type => $media]],
    ]]]]]]);

    return test()->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, MEDIA_SECRET)], $payload);
}

function metaHasFile(string $mime, string $body, int $size = 100, array $extra = []): void
{
    Http::fake([
        'graph.facebook.com/*/MEDIA1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', 'mime_type' => $mime, 'file_size' => $size, 'id' => 'MEDIA1']),
        'lookaside.fbsbx.com/*' => Http::response($body, 200, ['Content-Type' => $mime]),
        ...$extra,
    ]);
}

// ─── Saving and showing ─────────────────────────────────────────────────────────────────

test('a photo a customer sends is saved and shown in the conversation, only to the business', function () {
    metaHasFile('image/jpeg', 'JPEG-BYTES');

    sendsMedia('image', ['id' => 'MEDIA1', 'mime_type' => 'image/jpeg', 'caption' => 'This is the broken unit'])->assertOk();

    $message = Message::where('direction', 'inbound')->sole();
    expect($message->body_text)->toBe('[Image] This is the broken unit')
        ->and($message->metadata)->toMatchArray(['media_mime' => 'image/jpeg', 'media_size' => 10]);
    Storage::disk('local')->assertExists($message->metadata['media_path']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'lookaside.fbsbx.com') && $request->hasHeader('Authorization', 'Bearer EAAG-media-token-123456'));

    Livewire::actingAs($this->admin)->test(ShowConversation::class, ['conversationId' => $message->conversation_id])
        ->assertSeeHtml('src="'.route('whatsapp.media', $message->id).'"');

    $this->actingAs($this->admin)->get(route('whatsapp.media', $message->id))
        ->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('X-Content-Type-Options', 'nosniff');

    [$other] = followUpBusiness('Someone Else');
    $this->actingAs($other)->get(route('whatsapp.media', $message->id))->assertForbidden();
    auth()->logout();
    $this->get(route('whatsapp.media', $message->id))->assertRedirect();
});

test('documents download instead of opening in the browser', function () {
    metaHasFile('application/pdf', '%PDF-1.4');

    sendsMedia('document', ['id' => 'MEDIA1', 'mime_type' => 'application/pdf', 'filename' => 'site-plan.pdf']);
    $message = Message::where('direction', 'inbound')->sole();

    $this->actingAs($this->admin)->get(route('whatsapp.media', $message->id))
        ->assertOk()->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Disposition', 'attachment; filename=site-plan.pdf');
});

test('files over the size limit are not saved, and the chat says why', function () {
    config(['services.whatsapp.media_max_mb' => 1]);
    metaHasFile('video/mp4', 'x', size: 5 * 1024 * 1024);

    sendsMedia('video', ['id' => 'MEDIA1', 'mime_type' => 'video/mp4']);
    $message = Message::where('direction', 'inbound')->sole();

    expect($message->metadata)->not->toHaveKey('media_path')->and($message->metadata['media_error'])->toContain('larger than 1 MB');
    Livewire::actingAs($this->admin)->test(ShowConversation::class, ['conversationId' => $message->conversation_id])->assertSee('larger than 1 MB');
});

test('old files are deleted after the keep period; the message stays', function () {
    metaHasFile('image/jpeg', 'JPEG');
    sendsMedia('image', ['id' => 'MEDIA1', 'mime_type' => 'image/jpeg']);
    $message = Message::where('direction', 'inbound')->sole();
    $path = $message->metadata['media_path'];

    $this->travel(181)->days();
    expect(app(WhatsAppMediaStore::class)->prune())->toBe(1);

    Storage::disk('local')->assertMissing($path);
    expect($message->fresh()->metadata['media_path'])->toBeNull();
});

// ─── Voice notes ────────────────────────────────────────────────────────────────────────

test('a voice note is transcribed, and only then sorted and answered by the assistant', function () {
    metaHasFile('audio/ogg', 'OGG-OPUS', extra: [
        'generativelanguage.googleapis.com/v1beta/models/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Mujhe DHA mein 5 marla plot chahiye']]]]]]),
    ]);

    sendsMedia('audio', ['id' => 'MEDIA1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]);

    $message = Message::where('direction', 'inbound')->sole();
    expect($message->body_text)->toBe('Mujhe DHA mein 5 marla plot chahiye')
        ->and($message->metadata['transcribed_by'])->toBe('gemini');
    Queue::assertPushed(AssistantReplyJob::class, 1);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'models/gemini-2.5-flash:generateContent')
        && $request->hasHeader('x-goog-api-key', 'gemini-key')
        && $request['contents'][0]['parts'][1]['inline_data'] === ['mime_type' => 'audio/ogg', 'data' => base64_encode('OGG-OPUS')]);

    Livewire::actingAs($this->admin)->test(ShowConversation::class, ['conversationId' => $message->conversation_id])
        ->assertSee('Transcript (by AI)')->assertSee('Mujhe DHA mein 5 marla plot chahiye')->assertSeeHtml('<audio controls');
});

test('when Gemini is at its limit, Groq\'s free Whisper transcribes the voice note', function () {
    metaHasFile('audio/ogg', 'OGG', extra: [
        'generativelanguage.googleapis.com/*' => Http::response(['error' => ['status' => 'RESOURCE_EXHAUSTED']], 429),
        'api.groq.com/openai/v1/audio/transcriptions' => Http::response(['text' => 'Price kya hai?']),
    ]);

    sendsMedia('audio', ['id' => 'MEDIA1', 'mime_type' => 'audio/ogg', 'voice' => true]);

    expect(Message::where('direction', 'inbound')->sole())->body_text->toBe('Price kya hai?')->metadata->transcribed_by->toBe('groq');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'audio/transcriptions') && $request->isMultipart()
        && collect($request->data())->firstWhere('name', 'model')['contents'] === 'whisper-large-v3');
});

test('a voice note nobody can transcribe is still answered, as a voice message', function () {
    metaHasFile('audio/ogg', 'OGG', extra: ['generativelanguage.googleapis.com/*' => Http::response([], 503), 'api.groq.com/*' => Http::response([], 503)]);

    sendsMedia('audio', ['id' => 'MEDIA1', 'mime_type' => 'audio/ogg', 'voice' => true]);

    expect(Message::where('direction', 'inbound')->sole())->body_text->toBe('[Voice message]')->metadata->transcript_error->toBe('Could not be transcribed.');
    Queue::assertPushed(AssistantReplyJob::class, 1);
});

test('the assistant waits for the transcript instead of answering "[Voice message]"', function () {
    Queue::fake([SendEmailJob::class, AssistantReplyJob::class, TranscribeVoiceNoteJob::class]);
    metaHasFile('audio/ogg', 'OGG');

    sendsMedia('audio', ['id' => 'MEDIA1', 'mime_type' => 'audio/ogg', 'voice' => true]);

    Queue::assertPushed(TranscribeVoiceNoteJob::class, 1);
    Queue::assertNotPushed(AssistantReplyJob::class);
});
