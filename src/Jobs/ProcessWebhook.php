<?php
namespace Safepay\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Safepay\Services\EscrowService;

class ProcessWebhook implements ShouldQueue{
    use Queueable;
    use InteractsWithQueue;
    use SerializesModels;
    use Dispatchable;

    public function __construct(public string $transactionId,public string $prestataireId)
    {
        
    }

    public function handle(){
        app(EscrowService::class)->handleTransaction($this->transactionId, $this->prestataireId);
    }
}