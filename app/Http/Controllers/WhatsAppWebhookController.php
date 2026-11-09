<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppDelivery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $expected = config('funeral.whatsapp.verify_token');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');
        abort_unless(is_string($expected) && $expected !== '' && is_string($token) && hash_equals($expected, $token)
            && $request->query('hub_mode') === 'subscribe' && is_string($challenge)
            && ctype_digit($challenge) && strlen($challenge) <= 64, 403);

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request, WhatsAppDelivery $delivery): Response
    {
        $body = $request->getContent();
        abort_if(strlen($body) > 1024 * 1024, 413);

        $secret = config('funeral.whatsapp.app_secret');
        $signature = $request->header('X-Hub-Signature-256');
        abort_unless(is_string($secret) && $secret !== '' && is_string($signature)
            && hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature), 403);

        $payload = json_decode($body, true, 64);
        abort_unless(is_array($payload), 400);
        $phoneId = config('funeral.whatsapp.phone_id');
        if (($payload['object'] ?? null) !== 'whatsapp_business_account' || ! is_string($phoneId) || $phoneId === '') {
            return response('OK', 200);
        }

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? null) !== 'messages' || ($change['value']['metadata']['phone_number_id'] ?? null) !== $phoneId) {
                    continue;
                }
                foreach ((array) ($change['value']['statuses'] ?? []) as $status) {
                    $id = $status['id'] ?? null;
                    $state = $status['status'] ?? null;
                    if (! is_string($id) || ! is_string($state)) {
                        continue;
                    }
                    $correlation = $status['biz_opaque_callback_data'] ?? null;
                    $code = $status['errors'][0]['code'] ?? null;
                    $delivery->reconcile($id, $state, is_string($correlation) ? $correlation : null, is_int($code) ? $code : null);
                }
            }
        }

        return response('OK', 200);
    }
}
