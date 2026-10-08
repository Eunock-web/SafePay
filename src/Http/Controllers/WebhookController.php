<?php
namespace Safepay\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Safepay\Jobs\ProcessWebhook;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        $secret = config('safepay.webhook_secret');

        // Sans secret, impossible d'authentifier l'appelant : on refuse plutôt que d'accepter n'importe quoi.
        if (empty($secret)) {
            Log::critical('Webhook FedaPay refusé : safepay.webhook_secret n\'est pas configuré.');
            return response()->json(['success' => false, 'message' => 'Webhook not configured'], 500);
        }

        try {
            $event = \FedaPay\Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('X-FEDAPAY-SIGNATURE'),
                $secret
            );
        } catch (\UnexpectedValueException $e) {
            Log::error('Webhook payload invalide: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Invalid payload'], 403);
        } catch (\FedaPay\Error\SignatureVerification $e) {
            Log::error('Webhook signature invalide: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Invalid signature'], 403);
        }

        $transactionId = data_get($event, 'entity.id');

        if (!in_array($event->name, ['transaction.approved', 'transaction.canceled'], true) || empty($transactionId)) {
            // 200 : l'événement est volontairement ignoré, inutile que FedaPay le renvoie.
            return response()->json(['success' => true, 'message' => 'Event ignored']);
        }

        // Le client et le prestataire sont lus depuis les custom_metadata de la transaction FedaPay
        // (vérifiée auprès de l'API par le job), jamais depuis la requête entrante.
        ProcessWebhook::dispatch((string) $transactionId);

        return response()->json(['status' => 200, 'success' => true]);
    }
}
