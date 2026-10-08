<?php
namespace Safepay\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Safepay\Services\EscrowService;

class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(
        public string $transactionId,
        public ?string $prestataireId = null,
        public ?string $clientId = null
    ) {
    }

    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(): void
    {
        $result = app(EscrowService::class)->handleTransaction($this->transactionId, $this->prestataireId, $this->clientId);

        // Erreur transitoire (API FedaPay, base de données) : on relance le job.
        if (($result['retryable'] ?? false) === true) {
            throw new RuntimeException($result['error'] ?? 'Transient error while processing webhook');
        }
    }
}
