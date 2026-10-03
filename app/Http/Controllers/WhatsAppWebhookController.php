<?php

namespace App\Http\Controllers;

use App\WhatsApp\Inbox;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The WhatsApp Cloud API webhook. GET is Meta's one-time verification; POST carries messages and
 * is accepted only with a valid X-Hub-Signature-256 (HMAC-SHA256 of the raw body with the app
 * secret). The answer is sent at once and the messages are handled after it, so Meta never
 * times out and redelivers.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $token = (string) config('whatsapp.verify_token');
        if ($token !== '' && $request->query('hub_mode') === 'subscribe' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request, Inbox $inbox): Response
    {
        if (! self::signatureValid($request->getContent(), (string) $request->header('X-Hub-Signature-256'))) {
            return response('Invalid signature', 401);
        }
        $payload = json_decode($request->getContent(), true);
        if (is_array($payload)) {
            \Illuminate\Support\defer(fn () => $inbox->handle($payload));
        }

        return response('OK', 200);
    }

    public static function signatureValid(string $body, string $header): bool
    {
        $secret = (string) config('whatsapp.app_secret');
        if ($secret === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $header);
    }
}
