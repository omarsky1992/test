<?php

namespace App\WhatsApp;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A phone linked by QR code through the WAHA service (WhatsApp HTTP API, running beside the app on
 * the server). One session, «default»; incoming messages come back signed to /whatsapp/qr-webhook.
 *
 * Two lines, each its own WAHA service: «main» (the staff's commands and alerts) and «notify» (an
 * optional second phone that only sends the subscribers' messages and receives nothing).
 */
class WahaGateway implements Gateway
{
    public const LINES = ['main' => 'waha', 'notify' => 'waha_notify'];

    public function __construct(private string $config = 'waha') {}

    public static function line(string $line): self
    {
        return $line === 'notify' ? app(self::class, ['config' => self::LINES['notify']]) : app(self::class);
    }

    public const STATUS_LABELS = [
        'WORKING' => 'متصل',
        'SCAN_QR_CODE' => 'بانتظار مسح الباركود',
        'STARTING' => 'يبدأ…',
        'STOPPED' => 'متوقف',
        'FAILED' => 'فشل الاتصال',
        'MISSING' => 'غير مربوط',
        'UNREACHABLE' => 'خدمة الربط لا تعمل',
    ];

    public static function configured(string $config = 'waha'): bool
    {
        return filled(config("whatsapp.{$config}.api_key")) && filled(config("whatsapp.{$config}.url"));
    }

    /**
     * @return array{status: string, phone: ?string, name: ?string}
     */
    public function status(): array
    {
        if (! self::configured($this->config)) {
            return ['status' => 'UNREACHABLE', 'phone' => null, 'name' => null];
        }
        try {
            $response = $this->http()->get($this->url('/api/sessions/'.$this->session()));
        } catch (\Throwable) {
            return ['status' => 'UNREACHABLE', 'phone' => null, 'name' => null];
        }
        if ($response->status() === 404) {
            return ['status' => 'MISSING', 'phone' => null, 'name' => null];
        }
        if ($response->failed()) {
            return ['status' => 'UNREACHABLE', 'phone' => null, 'name' => null];
        }
        $me = $response->json('me') ?? [];

        return [
            'status' => (string) ($response->json('status') ?? 'STOPPED'),
            'phone' => isset($me['id']) ? self::digits((string) $me['id']) : null,
            'name' => $me['pushName'] ?? null,
        ];
    }

    public function isConnected(): bool
    {
        return $this->status()['status'] === 'WORKING';
    }

    /**
     * Creates the session (or updates its webhook) and starts it, so the QR code appears.
     */
    public function start(): void
    {
        // The notifications line has no webhook: what subscribers write to it is never read as a command.
        $url = config("whatsapp.{$this->config}.webhook_url");
        $config = ['webhooks' => $url ? [[
            'url' => $url,
            'events' => ['message'],
            'hmac' => ['key' => (string) config("whatsapp.{$this->config}.webhook_secret")],
            'retries' => ['policy' => 'linear', 'delaySeconds' => 3, 'attempts' => 5],
        ]] : []];
        $session = $this->session();
        $current = $this->http()->get($this->url("/api/sessions/{$session}"));

        if ($current->status() === 404) {
            $this->ensureOk($this->http()->post($this->url('/api/sessions'), ['name' => $session, 'start' => true, 'config' => $config]));

            return;
        }
        $this->ensureOk($current);
        $this->ensureOk($this->http()->put($this->url("/api/sessions/{$session}"), ['config' => $config]));
        if (in_array($current->json('status'), ['STOPPED', 'FAILED'], true)) {
            $this->ensureOk($this->http()->post($this->url("/api/sessions/{$session}/start")));
        }
    }

    public function restart(): void
    {
        $this->ensureOk($this->http()->post($this->url('/api/sessions/'.$this->session().'/restart')));
    }

    /** Unlinks the phone; a new QR code is needed to link again. */
    public function logout(): void
    {
        $this->ensureOk($this->http()->post($this->url('/api/sessions/'.$this->session().'/logout')));
    }

    /** The current QR code as a PNG image. */
    public function qrPng(): string
    {
        $response = $this->http()->accept('image/png')->get($this->url('/api/'.$this->session().'/auth/qr'), ['format' => 'image']);
        $this->ensureOk($response);

        return $response->body();
    }

    public function send(string $to, string $text): void
    {
        $this->ensureOk($this->http()->timeout(30)->post($this->url('/api/sendText'), [
            'session' => $this->session(),
            'chatId' => self::digits($to).'@c.us',
            'text' => mb_substr($text, 0, 4000),
            'linkPreview' => false,
        ]));
    }

    /** $mediaId is the media URL WAHA put in the webhook. */
    public function downloadMedia(string $mediaId): array
    {
        $base = rtrim((string) config("whatsapp.{$this->config}.url"), '/');
        // Only files served by our own WAHA service; never an address taken blindly from a payload.
        $path = parse_url($mediaId, PHP_URL_PATH);
        if (! is_string($path) || ! str_starts_with($path, '/api/files/')) {
            throw new RuntimeException('Unexpected media URL.');
        }
        $response = $this->http()->timeout(60)->get($base.$path);
        $this->ensureOk($response);

        return [$response->body(), (string) $response->header('Content-Type')];
    }

    public static function digits(string $chatId): string
    {
        return preg_replace('/\D/', '', explode('@', explode(':', $chatId)[0])[0]);
    }

    private function http(): PendingRequest
    {
        if (! self::configured($this->config)) {
            throw new RuntimeException('WAHA_API_KEY is not set.');
        }

        return Http::withHeaders(['X-Api-Key' => (string) config("whatsapp.{$this->config}.api_key")])->acceptJson()->timeout(15);
    }

    private function url(string $path): string
    {
        return rtrim((string) config("whatsapp.{$this->config}.url"), '/').$path;
    }

    private function session(): string
    {
        return (string) config("whatsapp.{$this->config}.session");
    }

    private function ensureOk(Response $response): void
    {
        if ($response->failed()) {
            $message = $response->json('message') ?? $response->json('error') ?? $response->body();
            throw new RuntimeException('WAHA '.$response->status().': '.mb_strimwidth(is_string($message) ? $message : json_encode($message), 0, 300, '…'));
        }
    }
}
