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
        // Récupération des données brutes
        $payload = $request->getContent();
        $signature = $request->header('X-FEDAPAY-SIGNATURE');
        $secret = config('safepay.webhook_secret');

        if (empty($secret)) {
            // Skip signature validation in testing
            $event = json_decode($request->getContent());
        } else {
            // Validation de la signature
            try {
                $event = \FedaPay\Webhook::constructEvent($payload, $signature, $secret);
            } catch (\UnexpectedValueException $e) {
                Log::error('Webhook payload invalide: ' . $e->getMessage());
                return response()->json(['success' => false, 'message' => 'Invalid payload'], 403);
            } catch (\FedaPay\Error\SignatureVerification $e) {
                Log::error('Webhook signature invalide: ' . $e->getMessage());
                return response()->json(['success' => false, 'message' => 'Invalid signature'], 403);
            }
        }

        // Traitement selon le type d'événement
        switch ($event->name) {
            case 'transaction.approved':
                ProcessWebhook::dispatch(
                    $event->entity->id,
                    $request->prestataire_id
                );
                break;

            case 'transaction.canceled':
                Log::info('Transaction annulée reçue via webhook', ['event' => $event]);
                ProcessWebhook::dispatch(
                    $event->entity->id,
                    $request->prestataire_id
                );
                break;

            default:
                return response()->json(['success' => false, 'message' => 'Unhandled event'], 400);
        }

        return response()->json(['status' => 200, 'success' => true]);
    }
}
