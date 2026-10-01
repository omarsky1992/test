<?php

namespace App\Sync;

use App\Services\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Reads the subscriber list from the company panel (admin.ftth.iq) the way its own pages do.
 * Read-only: it never calls an endpoint that changes anything.
 *
 * Sign-in is EarthLink's Keycloak. The access token lasts an hour; it is renewed with the stored
 * refresh token, or with the username and password when the refresh token has expired.
 */
class FtthApiClient implements CompanyClient
{
    private const PAGE_SIZE = 100;
    private const TOKEN_CACHE = 'sync.company.access_token';

    public function __construct(private Settings $settings)
    {
    }

    public function records(array $options = []): iterable
    {
        $needsDetails = $options['needs_details'] ?? fn () => false;
        $detailBudget = $options['detail_limit'] ?? (int) $this->settings->get('sync.detail_limit');
        $limit = $options['limit'] ?? null;

        $customers = $limit === null ? $this->customerIndex() : [];
        $details = [];
        $seen = 0;

        foreach ($this->pages('/api/subscriptions', array_filter(['hierarchyLevel' => $this->settings->get('sync.hierarchy_level')], 'filled')) as $item) {
            $record = FtthMapper::fromSubscription($item, $customers);

            if ($detailBudget > 0 && $record['customer_id'] !== null && $needsDetails($record)) {
                if (! array_key_exists($record['customer_id'], $details)) {
                    $details[$record['customer_id']] = $this->customerDetails($record['customer_id']);
                    $detailBudget--;
                }
                $record = FtthMapper::mergeDetails($record, $details[$record['customer_id']]);
            }

            yield $record;

            if ($limit !== null && ++$seen >= $limit) {
                return;
            }
        }
    }

    /**
     * @return array<string, array{name: ?string, phone: ?string}>
     */
    private function customerIndex(): array
    {
        return FtthMapper::customerIndex($this->pages('/api/customers'));
    }

    private function customerDetails(string $customerId): array
    {
        $customer = $this->get("/api/customers/{$customerId}")['model'] ?? [];
        $subscriptions = $this->get('/api/customers/subscriptions', ['customerId' => $customerId])['items'] ?? [];

        return ['customer' => $customer, 'subscriptions' => $subscriptions];
    }

    /**
     * Walks a paged list endpoint ({totalCount, items}) to the end.
     */
    private function pages(string $path, array $query = []): \Generator
    {
        $page = 1;
        $seen = 0;
        do {
            $body = $this->get($path, [...$query, 'pageSize' => self::PAGE_SIZE, 'pageNumber' => $page]);
            $items = $body['items'] ?? [];
            foreach ($items as $item) {
                yield $item;
            }
            $seen += count($items);
            $page++;
        } while ($items !== [] && $seen < (int) ($body['totalCount'] ?? 0) && $page <= 1000);
    }

    private function get(string $path, array $query = []): array
    {
        $response = $this->send($path, $query);
        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE);
            $response = $this->send($path, $query);
        }
        if ($response->failed()) {
            throw new CompanySyncException("موقع الشركة رفض الطلب {$path} (رمز {$response->status()}).");
        }

        return $response->json() ?? [];
    }

    private function send(string $path, array $query): Response
    {
        try {
            return Http::baseUrl(rtrim((string) $this->settings->get('sync.base_url'), '/'))
                ->withToken($this->accessToken())
                ->withHeaders(['X-Client-App' => (string) $this->settings->get('sync.client_app'), 'X-User-Role' => '0'])
                ->acceptJson()
                ->timeout(30)
                ->retry(2, 1000, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get($path, $query);
        } catch (ConnectionException $e) {
            throw new CompanySyncException('تعذّر الاتصال بموقع الشركة: '.$e->getMessage(), previous: $e);
        }
    }

    private function accessToken(): string
    {
        if ($token = Cache::get(self::TOKEN_CACHE)) {
            return $token;
        }

        return $this->signIn();
    }

    /**
     * Gets a fresh access token: from the stored refresh token first, then from the username and
     * password. Each step's refusal reason from EarthLink is kept, so the message says what to fix.
     */
    public function signIn(): string
    {
        $reasons = [];
        $body = null;

        if ($refresh = $this->secret('sync.refresh_token')) {
            [$body, $error] = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
            if ($body === null) {
                $this->settings->set('sync.refresh_token', null);
                $reasons[] = 'مفتاح التجديد: '.self::explain($error, refresh: true);
            }
        }

        if ($body === null) {
            $username = (string) $this->settings->get('sync.username');
            $password = $this->secret('sync.password');
            if ($username !== '' && $password !== null) {
                [$body, $error] = $this->tokenRequest(['grant_type' => 'password', 'username' => $username, 'password' => $password, 'scope' => 'openid']);
                if ($body === null) {
                    $reasons[] = 'اليوزر والباسورد: '.self::explain($error, refresh: false);
                }
            } elseif ($reasons === []) {
                throw new CompanySyncException('أدخل يوزر وباسورد موقع الشركة، أو مفتاح التجديد، في إعدادات الاتصال.');
            }
        }

        if ($body === null) {
            throw new CompanySyncException('تعذّر تسجيل الدخول لموقع الشركة. '.implode(' · ', $reasons));
        }

        if (filled($body['refresh_token'] ?? null)) {
            $this->settings->set('sync.refresh_token', Crypt::encryptString($body['refresh_token']));
            $this->settings->set('sync.refreshed_at', now()->toIso8601String());
        }
        $ttl = max(60, (int) ($body['expires_in'] ?? 300) - 60);
        Cache::put(self::TOKEN_CACHE, $body['access_token'], $ttl);

        return $body['access_token'];
    }

    /**
     * Renews the sign-in with the refresh token so the EarthLink session never goes idle.
     * Returns false when there is no refresh token to renew.
     */
    public function keepAlive(): bool
    {
        if ($this->secret('sync.refresh_token') === null) {
            return false;
        }
        Cache::forget(self::TOKEN_CACHE);
        $this->signIn();

        return true;
    }

    /**
     * @return array{0: ?array, 1: ?array} [token response, error response]
     */
    private function tokenRequest(array $form): array
    {
        $clientId = (string) $this->settings->get('sync.client_id');
        if ($clientId === '') {
            throw new CompanySyncException('أدخل معرّف العميل (client_id) في إعدادات الاتصال.');
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(30)
                ->post($this->tokenUrl($form['refresh_token'] ?? null), [...$form, 'client_id' => $clientId]);
        } catch (ConnectionException $e) {
            throw new CompanySyncException('تعذّر الاتصال بخادم تسجيل الدخول: '.$e->getMessage(), previous: $e);
        }

        if ($response->successful() && filled($response->json('access_token'))) {
            return [$response->json(), null];
        }

        return [null, ['status' => $response->status(), 'error' => $response->json('error'), 'description' => $response->json('error_description')]];
    }

    /**
     * A refresh token names the realm that issued it (its iss claim); refreshing must go to that
     * same realm. Only the company's own sign-in hosts are trusted.
     */
    private function tokenUrl(?string $refreshToken): string
    {
        if ($refreshToken !== null && count($parts = explode('.', $refreshToken)) === 3) {
            $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            $issuer = is_array($claims) ? ($claims['iss'] ?? null) : null;
            $host = is_string($issuer) ? parse_url($issuer, PHP_URL_HOST) : null;
            if ($host && preg_match('/(^|\.)(ftth\.iq|earthlink\.iq)$/', $host) && str_starts_with($issuer, 'https://')) {
                return rtrim($issuer, '/').'/protocol/openid-connect/token';
            }
        }

        return (string) $this->settings->get('sync.token_url');
    }

    private static function explain(?array $error, bool $refresh): string
    {
        $code = $error['error'] ?? null;
        $text = match (true) {
            $code === 'unauthorized_client' => 'EarthLink لا يسمح بالدخول المباشر بهذه الطريقة. استخدم «مفتاح التجديد».',
            $code === 'invalid_grant' && $refresh => 'منتهي أو مُلغى. انسخ مفتاحاً جديداً من الموقع.',
            $code === 'invalid_grant' => 'مرفوض: اليوزر أو الباسورد غير صحيح، أو الحساب يحتاج تحققاً إضافياً.',
            $code === 'invalid_client' => 'معرّف العميل غير صحيح.',
            default => 'رفض الخادم الطلب',
        };
        $details = array_filter([$code, $error['description'] ?? null, isset($error['status']) ? "HTTP {$error['status']}" : null]);

        return $text.($details ? ' ('.implode(' – ', $details).')' : '');
    }

    private function secret(string $key): ?string
    {
        $value = $this->settings->get($key);

        return filled($value) ? rescue(fn () => Crypt::decryptString($value), null, false) : null;
    }

}
