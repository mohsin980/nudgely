<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * One AI chat log file, attached.
 */
class AiChatLogMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly string $path) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').' AI chat log: '.basename($this->path));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.ai-chat-log', with: [
            'file' => basename($this->path),
            'size' => number_format(filesize($this->path) / 1024 / 1024, 1),
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [Attachment::fromPath($this->path)->as(basename($this->path))->withMime('text/plain')];
    }
}
