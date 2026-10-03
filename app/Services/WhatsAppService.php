<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * يرسل ويستقبل رسائل واتساب عبر Meta Cloud API (WhatsApp Business API).
 *
 * الإعداد في .env:
 *   WHATSAPP_TOKEN=...          (Access Token من Meta for Developers)
 *   WHATSAPP_PHONE_ID=...       (Phone Number ID)
 *   WHATSAPP_VERIFY_TOKEN=...   (توكن التحقق من الـ Webhook)
 */
class WhatsAppService
{
    private const API_BASE = 'https://graph.facebook.com/v19.0';

    /** هل الميزة مفعّلة؟ */
    public function enabled(): bool
    {
        return filled(config('services.whatsapp.token'))
            && filled(config('services.whatsapp.phone_id'));
    }

    /** إرسال رسالة نصية بسيطة */
    public function sendText(string $to, string $text): bool
    {
        if (! $this->enabled()) {
            Log::warning('WhatsApp غير مفعّل، لم تُرسل الرسالة.', ['to' => $to]);

            return false;
        }

        $response = Http::withToken(config('services.whatsapp.token'))
            ->post(self::API_BASE.'/'.config('services.whatsapp.phone_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $text],
            ]);

        if ($response->failed()) {
            Log::error('فشل إرسال رسالة واتساب', [
                'to' => $to,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        }

        return true;
    }

    /** إرسال رسالة مع أزرار (اختياري) */
    public function sendButtons(string $to, string $text, array $buttons): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $rows = array_map(fn (array $b) => [
            'id' => $b['id'],
            'title' => $b['title'],
        ], $buttons);

        $response = Http::withToken(config('services.whatsapp.token'))
            ->post(self::API_BASE.'/'.config('services.whatsapp.phone_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => $text],
                    'action' => [
                        'buttons' => array_map(fn (array $r) => [
                            'type' => 'reply',
                            'reply' => $r,
                        ], $rows),
                    ],
                ],
            ]);

        if ($response->failed()) {
            Log::error('فشل إرسال أزرار واتساب', ['to' => $to, 'status' => $response->status(), 'body' => $response->body()]);

            return false;
        }

        return true;
    }

    /** التحقق من توقيع الـ Webhook (اختياري، يُنصح به) */
    public function verifySignature(string $signature, string $payload): bool
    {
        $appSecret = config('services.whatsapp.app_secret');
        if (blank($appSecret)) {
            return true; // إذا ما ضُبط السر، نتجاوز التحقق
        }

        $expected = 'sha256='.hash_hmac('sha256', $payload, $appSecret);

        return hash_equals($expected, $signature);
    }

    /**
     * تحميل ملف وسائط (صوت/صورة/مستند) من Meta Cloud API.
     * يُرجع محتوى الملف (binary) أو null عند الفشل.
     */
    public function downloadMedia(string $mediaId): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        // 1) الحصول على رابط التحميل
        $info = Http::withToken(config('services.whatsapp.token'))
            ->get(self::API_BASE.'/'.config('services.whatsapp.phone_id').'/media/'.$mediaId);

        if ($info->failed()) {
            Log::error('فشل الحصول على معلومات الوسائط', ['media_id' => $mediaId, 'status' => $info->status()]);

            return null;
        }

        $url = $info->json('url');
        if (! $url) {
            Log::error('لا يوجد رابط تحميل للوسائط', ['media_id' => $mediaId]);

            return null;
        }

        // 2) تحميل الملف
        $file = Http::withToken(config('services.whatsapp.token'))
            ->get($url);

        if ($file->failed()) {
            Log::error('فشل تحميل ملف الوسائط', ['media_id' => $mediaId, 'status' => $file->status()]);

            return null;
        }

        return $file->body();
    }

    /**
     * تحويل ملف صوتي إلى نص باستخدام OpenAI Whisper API.
     * يتطلب WHATSAPP_OPENAI_KEY في .env.
     */
    public function transcribeAudio(string $audioBinary, string $filename = 'audio.ogg'): ?string
    {
        $apiKey = config('services.whatsapp.openai_key');
        if (blank($apiKey)) {
            Log::warning('WHATSAPP_OPENAI_KEY غير مضبوط، لا يمكن تحويل الصوت لنص.');

            return null;
        }

        // حفظ الملف مؤقتاً
        $tmp = tempnam(sys_get_temp_dir(), 'wa_').'.ogg';
        file_put_contents($tmp, $audioBinary);

        try {
            $response = Http::withToken($apiKey)
                ->attach('file', file_get_contents($tmp), $filename)
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => 'whisper-1',
                    'language' => 'ar',
                ]);

            if ($response->failed()) {
                Log::error('فشل تحويل الصوت لنص', ['status' => $response->status(), 'body' => $response->body()]);

                return null;
            }

            return $response->json('text');
        } finally {
            @unlink($tmp);
        }
    }
}
