<?php
namespace Safepay\Services;

use InvalidArgumentException;
use FedaPay\FedaPay;
use FedaPay\Payout as FedapayPayout;
use FedaPay\Transaction as FedaPayTransaction;
use Illuminate\Support\Facades\Log;

class FedapayService{
        public function __construct(){
            $secretKey = config('safepay.secret_key');
            $environment = config('safepay.enviroment');

            if (empty($secretKey) || empty($environment)) {
                throw new InvalidArgumentException('Configuration FedaPay incomplète. Vérifiez votre .env');
            }

            FedaPay::setApiKey($secretKey);
            FedaPay::setEnvironment($environment);

        }

        public function verifyCollect($transactionId){
            try {
                // Verify the status of the transaction in question
                $transaction = FedaPayTransaction::retrieve($transactionId);

                // Check transaction status
                if ($transaction->status !== 'approved') {
                    return [
                        'success' => false,
                        'message' => 'Transaction denied',
                        'data' => $transaction
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Transaction successfully',
                    'data' => $transaction
                ];
            } catch (\Exception $e) {
                Log::error('FedaPay verifyCollect exception: ' . $e->getMessage(), ['transaction_id' => $transactionId]);
                return [
                    'success' => false,
                    'error' => 'Une erreur est survenue lors de la vérification de la transaction.',
                ];
            }
        }

        public function payout($data){
            try{
                //create payout
                $createPayout = FedapayPayout::create($data);

                if($createPayout){
                    return [
                        'success' => true,
                        'message' => 'Payout created',
                        'data' => $createPayout
                    ];
                }

                return [
                    'success' => false,
                    'message' => 'Payout creation failure',
                ];
                
            }catch(\Exception $e){
                // Nettoyage des données PII pour les logs
                $safeData = [
                    'amount' => $data['amount'] ?? null,
                    'currency' => $data['currency'] ?? null,
                ];

                Log::error('FedaPay payout exception: ' . $e->getMessage(), ['safe_data' => $safeData]);
                return [
                    'success' => false,
                    'error' => 'Une erreur est survenue lors de la création du paiement.',
                ];
            }
        }
}
