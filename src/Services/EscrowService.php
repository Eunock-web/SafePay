<?php
namespace Safepay\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Safepay\Enums\TransactionStatus;
use Safepay\Jobs\ReleaseEscrowJob;
use Safepay\Models\Transaction;
use Safepay\Models\TransactionLog;
use Exception;

class EscrowService
{
    protected FedapayService $fedapayService;

    public function __construct(FedapayService $fedapayService)
    {
        $this->fedapayService = $fedapayService;
    }

    /**
     * Save a verified FedaPay transaction in the Transaction and TransactionLog tables.
     *
     * Idempotent : a transaction already locked, being released or released is never overwritten,
     * so a replayed webhook cannot put released funds back in escrow.
     *
     * $clientId / $prestataireId fall back on the `client_id` / `prestataire_id` keys of the FedaPay
     * transaction's custom_metadata (and Auth::id() for the client when called from a request).
     * A result with `retryable => true` is a transient failure the caller should retry.
     */
    public function handleTransaction($transactionId, $prestataireId = null, $clientId = null)
    {
        $verification = $this->fedapayService->verifyCollect($transactionId);
        $txData = $verification['data'] ?? null;

        if (!$txData) {
            // FedaPay unreachable or transaction not retrievable: this is not a declined payment.
            return [
                'success' => false,
                'error' => $verification['error'] ?? 'Unable to verify the transaction',
                'retryable' => true,
            ];
        }

        $metadata = json_decode(json_encode($txData->custom_metadata ?? null), true) ?: [];
        $clientId = $clientId ?? $metadata['client_id'] ?? Auth::id();
        $prestataireId = $prestataireId ?? $metadata['prestataire_id'] ?? null;
        $fedapayId = (string) ($txData->id ?? $transactionId);
        $approved = ($verification['success'] ?? false) === true;

        if ($approved && (in_array($clientId, [null, ''], true) || in_array($prestataireId, [null, ''], true) || !isset($txData->amount))) {
            Log::error('Safepay: client, prestataire or amount missing for transaction', ['transaction_id' => $fedapayId]);
            return ['success' => false, 'error' => 'Missing client_id, prestataire_id or amount'];
        }

        try {
            $result = DB::transaction(function () use ($txData, $metadata, $clientId, $prestataireId, $fedapayId, $approved) {
                $existing = Transaction::where('transaction_id', $fedapayId)->lockForUpdate()->first();

                // Replay protection: only a missing or failed transaction can be (re)written.
                if ($existing && $existing->status !== TransactionStatus::Failed->value) {
                    return ['success' => true, 'message' => 'Transaction already processed'];
                }

                $status = $approved ? TransactionStatus::EscrowLock : TransactionStatus::Failed;

                TransactionLog::create([
                    'transaction_id' => $fedapayId,
                    'client_id' => $clientId,
                    'prestataire_id' => $prestataireId,
                    'description' => $txData->description ?? ($approved ? 'Success transaction' : 'Failed transaction'),
                    'status' => $txData->status ?? $status->value,
                    'metadata' => $metadata,
                ]);

                Transaction::updateOrCreate(
                    ['transaction_id' => $fedapayId],
                    [
                        'client_id' => $clientId,
                        'prestataire_id' => $prestataireId,
                        'amount' => $txData->amount ?? 0,
                        'currency' => data_get($txData, 'currency.iso', 'XOF'),
                        'payment_method' => $txData->mode ?? 'unknown',
                        'description' => $txData->description ?? null,
                        'status' => $status->value,
                    ]
                );

                return $approved
                    ? ['success' => true, 'message' => 'Transaction created successfully and locked', 'locked' => true]
                    : ['success' => false, 'message' => 'Transaction declined or failed'];
            });

            if ($result['locked'] ?? false) {
                $this->scheduleAutoRelease($fedapayId);
            }
            unset($result['locked']);

            return $result;
        } catch (Exception $e) {
            Log::error('Safepay handleTransaction failed: ' . $e->getMessage(), ['transaction_id' => $fedapayId]);
            return ['success' => false, 'error' => 'Database error while saving the transaction', 'retryable' => true];
        }
    }

    /**
     * Release escrow funds to the prestataire.
     *
     * Status flow: escrow_lock -> releasing -> released. The transaction only goes back to
     * escrow_lock when we are sure no money left. If the payout was created, a failure leaves it in
     * `releasing` (with `payout_id` set) for manual reconciliation, to rule out a double payout.
     */
    public function release($transactionId)
    {
        $payoutCreated = false;

        try {
            $txDetails = DB::transaction(function () use ($transactionId) {
                $transaction = Transaction::where('transaction_id', $transactionId)
                    ->lockForUpdate()
                    ->first();

                if (!$transaction) {
                    return ['error' => 'Transaction not found'];
                }

                if ($transaction->status !== TransactionStatus::EscrowLock->value) {
                    return ['error' => 'Transaction is not active in escrow'];
                }

                $prestataireInfo = config('auth.providers.users.model')::find($transaction->prestataire_id);

                if (!$prestataireInfo) {
                    return ['error' => 'Prestataire details not found'];
                }

                $commission = $this->calculateCommission((float) $transaction->amount);
                $payoutAmount = (int) round((float) $transaction->amount) - $commission;

                if ($payoutAmount <= 0) {
                    return ['error' => 'Amount is not enough to cover the commission'];
                }

                // Claim the transaction before talking to FedaPay: a concurrent release now fails the status check.
                $transaction->update(['status' => TransactionStatus::Releasing->value, 'commission' => $commission]);

                return [
                    'amount' => $transaction->amount,
                    'payout_amount' => $payoutAmount,
                    'currency' => $transaction->currency ?? 'XOF',
                    'description' => $transaction->description,
                    'prestataire' => $prestataireInfo,
                ];
            });

            if (isset($txDetails['error'])) {
                return ['success' => false, 'message' => $txDetails['error']];
            }

            $data = [
                'amount' => $txDetails['payout_amount'],
                'currency' => ['iso' => $txDetails['currency']],
                'description' => 'Payout for transaction: ' . $txDetails['description'],
                'customer' => [
                    'firstname' => $txDetails['prestataire']->firstname,
                    'lastname' => $txDetails['prestataire']->lastname,
                    'email' => $txDetails['prestataire']->email,
                    'phone_number' => [
                        'number' => $txDetails['prestataire']->phone ?? '',
                        'country' => $txDetails['prestataire']->country ?? 'BJ'
                    ]
                ]
            ];

            $payout = $this->fedapayService->payout($data);

            if (!($payout['success'] === true && isset($payout['data']))) {
                // Nothing was sent: safe to unlock.
                $this->setStatus($transactionId, TransactionStatus::EscrowLock);
                return ['success' => false, 'error' => 'La demande de création du paiement a échoué sur FedaPay.'];
            }

            $payoutCreated = true;
            Transaction::where('transaction_id', $transactionId)->update(['payout_id' => (string) ($payout['data']->id ?? '')]);

            try {
                $payout['data']->sendNow();
            } catch (Exception $e) {
                // Ambiguous (timeout...): FedaPay may have sent the funds. Keep `releasing`, do not retry blindly.
                Log::error('Payout execution failed, manual reconciliation required: ' . $e->getMessage(), ['transaction_id' => $transactionId]);
                return ['success' => false, 'error' => 'Une erreur est survenue lors de la finalisation du transfert.'];
            }

            $this->setStatus($transactionId, TransactionStatus::Released);

            return ['success' => true, 'message' => 'Payout successfully processed'];
        } catch (Exception $e) {
            Log::error('Release method failed: ' . $e->getMessage(), ['transaction_id' => $transactionId, 'payout_created' => $payoutCreated]);

            if (!$payoutCreated) {
                Transaction::where('transaction_id', $transactionId)
                    ->where('status', TransactionStatus::Releasing->value)
                    ->update(['status' => TransactionStatus::EscrowLock->value]);
            }

            return ['success' => false, 'error' => "Une erreur d'exécution interne est survenue."];
        }
    }

    /**
     * Platform fee (config safepay.commission, in %) withheld from the payout, rounded to the currency unit.
     */
    public function calculateCommission(float $amount): int
    {
        $rate = (float) config('safepay.commission', 0);

        return $rate > 0 ? (int) round($amount * $rate / 100) : 0;
    }

    /**
     * Auto-release the funds after config safepay.escrow_delay hours (0 / null disables it).
     * Skipped on the sync queue driver, which ignores delays and would release immediately.
     */
    protected function scheduleAutoRelease(string $transactionId): void
    {
        $hours = (float) config('safepay.escrow_delay', 0);

        if ($hours <= 0) {
            return;
        }

        $connection = config('queue.default');
        if (config("queue.connections.$connection.driver") === 'sync') {
            Log::warning('Safepay: auto-release skipped, the sync queue driver cannot delay jobs.', ['transaction_id' => $transactionId]);
            return;
        }

        ReleaseEscrowJob::dispatch($transactionId)->delay(now()->addMinutes((int) round($hours * 60)));
    }

    protected function setStatus(string $transactionId, TransactionStatus $status): void
    {
        Transaction::where('transaction_id', $transactionId)->update(['status' => $status->value]);
    }
}
