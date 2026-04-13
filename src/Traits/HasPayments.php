<?php
namespace Safepay\Traits;

use Safepay\Models\Transaction;
use Safepay\Services\EscrowService;

trait HasPayments {
    public function chargeEscrow($transactionId, $prestataireId){
        return app(EscrowService::class)->handleTransaction($transactionId, $prestataireId);
    }

    public function releaseEscrow($transactionId){
        return app(EscrowService::class)->release($transactionId);
    }

    public function escrowTransactions(){
        return Transaction::where('client_id', $this->id);
    }
}