<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppCommandHandler;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * يستقبل رسائل واتساب من Meta Cloud API عبر الـ Webhook.
 *
 * الإعداد في Meta for Developers:
 *   Callback URL: https://your-domain.com/api/whatsapp/webhook
 *   Verify Token: نفس قيمة WHATSAPP_VERIFY_TOKEN في .env
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private WhatsAppCommandHandler $handler,
    ) {
    }

    /** التحقق من الـ Webhook عند إعداده في Meta */
    public function verify(Request $request): JsonResponse
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            return response()->json($challenge);
        }

        return response()->json(['error' => 'Verification failed'], 403);
    }

    /** استقبال الرسائل الواردة */
    public function receive(Request $request): JsonResponse
    {
        // التحقق من التوقيع (اختياري لكن يُنصح به)
        $signature = $request->header('X-Hub-Signature-256');
        if ($signature && ! $this->whatsapp->verifySignature($signature, $request->getContent())) {
            Log::warning('توقيع واتساب غير صحيح');

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();

        // رسائل واردة
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                foreach ($change['value']['messages'] ?? [] as $message) {
                    $this->handleIncomingMessage($message);
                }
            }
        }

        // يجب الرد بـ 200 دائماً حتى لا تعيد Meta إرسال الرسالة
        return response()->json(['status' => 'ok']);
    }

    private function handleIncomingMessage(array $message): void
    {
        $from = $message['from'] ?? null;
        $type = $message['type'] ?? null;

        if (! $from) {
            return;
        }

        // رسالة نصية
        if ($type === 'text') {
            $text = $message['text']['body'] ?? '';
            $this->handler->handle($from, $text);

            return;
        }

        // رسالة صوتية — نحولها لنص ثم نعالجها
        if ($type === 'audio') {
            $this->handleAudio($from, $message['audio'] ?? []);

            return;
        }

        // رسائل غير مدعومة (صور، مستندات، فيديو...) — نرد برسالة إرشادية
        $this->whatsapp->sendText($from, 'عذراً، أقبل الرسائل النصية والصوتية فقط. اكتب «مساعدة» لعرض الأوامر.');
    }

    private function handleAudio(string $from, array $audio): void
    {
        $mediaId = $audio['id'] ?? null;
        if (! $mediaId) {
            $this->whatsapp->sendText($from, 'تعذر قراءة الرسالة الصوتية. حاول مرة أخرى.');

            return;
        }

        // 1) تحميل ملف الصوت
        $binary = $this->whatsapp->downloadMedia($mediaId);
        if ($binary === null) {
            $this->whatsapp->sendText($from, 'تعذر تحميل الرسالة الصوتية. حاول مرة أخرى.');

            return;
        }

        // 2) تحويل الصوت لنص
        $text = $this->whatsapp->transcribeAudio($binary);
        if ($text === null || trim($text) === '') {
            $this->whatsapp->sendText($from, 'لم أستطع فهم الرسالة الصوتية. أرسلها نصياً أو حاول مرة أخرى.');

            return;
        }

        Log::info('تم تحويل رسالة صوتية لنص', ['from' => $from, 'text' => $text]);

        // 3) معالجة النص كأمر
        $this->handler->handle($from, trim($text));
    }
}
