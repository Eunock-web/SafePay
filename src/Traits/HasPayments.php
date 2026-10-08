<?php
namespace Safepay\Traits;

use Safepay\Models\Transaction;
use Safepay\Services\EscrowService;

trait HasPayments {
    public function chargeEscrow(string $transactionId, string $prestataireId){
        return app(EscrowService::class)->handleTransaction($transactionId, $prestataireId, $this->getKey());
    }

    public function releaseEscrow(string $transactionId){
        // Seul le client qui a payé peut libérer les fonds.
        $owned = Transaction::where('transaction_id', $transactionId)
            ->where('client_id', $this->getKey())
            ->exists();

        if (!$owned) {
            return ['success' => false, 'message' => 'Transaction not found'];
        }

        return app(EscrowService::class)->release($transactionId);
    }

    public function escrowTransactions(){
        return Transaction::where('client_id', $this->getKey());
    }
}
