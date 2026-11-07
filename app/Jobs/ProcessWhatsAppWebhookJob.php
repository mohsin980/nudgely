<?php

namespace App\Jobs;

use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppInboundProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One verified WhatsApp webhook delivery, processed off the request so Meta gets its answer at once.
 */
class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly int $connectionId, public readonly array $payload) {}

    public function handle(WhatsAppInboundProcessor $processor): void
    {
        $connection = WhatsAppConnection::query()->find($this->connectionId);

        if ($connection !== null) {
            $processor->process($connection, $this->payload);
        }
    }
}
