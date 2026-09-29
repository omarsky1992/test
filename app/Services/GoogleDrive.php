<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Minimal Google Drive client over the REST API. The owner connects once with OAuth
 * (scope drive.file: the app sees only the files it created itself); the refresh token is
 * stored encrypted, and daily backups upload without anyone signing in again.
 */
class GoogleDrive
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const FILES_URL = 'https://www.googleapis.com/drive/v3/files';
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';
    private const SCOPES = 'openid email https://www.googleapis.com/auth/drive.file';

    public function __construct(private Settings $settings)
    {
    }

    public function isConfigured(): bool
    {
        return filled(config('backup.google.client_id')) && filled(config('backup.google.client_secret'));
    }

    public function isConnected(): bool
    {
        return filled($this->settings->get('backup.google.refresh_token'));
    }

    public function connectedEmail(): ?string
    {
        return $this->settings->get('backup.google.connected_email');
    }

    public function authorizationUrl(string $email, string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('backup.google.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'login_hint' => $email,
            'state' => $state,
        ]);
    }

    /**
     * Finishes the one-time connection: exchanges the code, checks the account is the email
     * the owner entered, and stores the refresh token.
     */
    public function connect(string $code, string $expectedEmail): string
    {
        $token = $this->tokenRequest([
            'code' => $code,
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
        ]);
        if (blank($token['refresh_token'] ?? null)) {
            throw new BusinessRuleException('لم يرسل Google إذن الوصول الدائم. أعد المحاولة.');
        }

        $email = Http::withToken($token['access_token'])->get(self::USERINFO_URL)->throw()->json('email');
        if (strcasecmp((string) $email, $expectedEmail) !== 0) {
            throw new BusinessRuleException("سجّلت الدخول بحساب {$email} وليس {$expectedEmail}. اختر الحساب الصحيح.");
        }

        $this->settings->set('backup.google.refresh_token', Crypt::encryptString($token['refresh_token']));
        $this->settings->set('backup.google.connected_email', $email);
        $this->settings->set('backup.google.folder_id', null);

        return $email;
    }

    public function disconnect(): void
    {
        $encrypted = $this->settings->get('backup.google.refresh_token');
        if ($encrypted) {
            // Best effort: tell Google to revoke the token too.
            rescue(fn () => Http::asForm()->post('https://oauth2.googleapis.com/revoke', ['token' => Crypt::decryptString($encrypted)]), report: false);
        }
        foreach (['refresh_token', 'connected_email', 'folder_id'] as $key) {
            $this->settings->set("backup.google.{$key}", null);
        }
    }

    /**
     * Uploads a file into the backup folder (resumable upload, so large dumps are fine).
     *
     * @return array{id: string, link: ?string}
     */
    public function upload(string $path, string $name): array
    {
        $token = $this->accessToken();
        $folder = $this->folderId($token);

        $session = Http::withToken($token)
            ->withHeaders(['X-Upload-Content-Type' => 'application/octet-stream', 'X-Upload-Content-Length' => (string) filesize($path)])
            ->post(self::UPLOAD_URL.'?uploadType=resumable&fields=id,webViewLink', ['name' => $name, 'parents' => [$folder]]);
        $this->ensureOk($session);

        // Streamed from disk, so a large dump never has to fit in memory.
        $file = Http::withToken($token)->timeout(600)
            ->withBody(Utils::streamFor(Utils::tryFopen($path, 'rb')), 'application/octet-stream')
            ->put($session->header('Location'));
        $this->ensureOk($file);

        return ['id' => $file->json('id'), 'link' => $file->json('webViewLink')];
    }

    /**
     * Deletes backups in the folder older than the given number of days. Returns how many were deleted.
     */
    public function deleteOlderThan(int $days): int
    {
        $token = $this->accessToken();
        $cutoff = now()->subDays($days)->utc()->format('Y-m-d\TH:i:s');
        $folder = $this->folderId($token);

        $files = Http::withToken($token)->get(self::FILES_URL, [
            'q' => "'{$folder}' in parents and trashed = false and createdTime < '{$cutoff}'",
            'fields' => 'files(id)',
            'pageSize' => 200,
        ]);
        $this->ensureOk($files);

        foreach ($files->json('files', []) as $file) {
            Http::withToken($token)->delete(self::FILES_URL.'/'.$file['id']);
        }

        return count($files->json('files', []));
    }

    public function redirectUri(): string
    {
        return route('backup.google.callback');
    }

    private function accessToken(): string
    {
        $encrypted = $this->settings->get('backup.google.refresh_token');
        if (! $encrypted) {
            throw new BusinessRuleException('Google Drive غير مربوط. اربطه من صفحة النسخ الاحتياطي.');
        }

        return $this->tokenRequest(['refresh_token' => Crypt::decryptString($encrypted), 'grant_type' => 'refresh_token'])['access_token'];
    }

    private function folderId(string $token): string
    {
        $id = $this->settings->get('backup.google.folder_id');
        if ($id && Http::withToken($token)->get(self::FILES_URL.'/'.$id, ['fields' => 'id,trashed'])->json('trashed') === false) {
            return $id;
        }

        $folder = Http::withToken($token)->post(self::FILES_URL.'?fields=id', [
            'name' => config('backup.google.folder_name'),
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);
        $this->ensureOk($folder);
        $this->settings->set('backup.google.folder_id', $folder->json('id'));

        return $folder->json('id');
    }

    private function tokenRequest(array $params): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            ...$params,
            'client_id' => config('backup.google.client_id'),
            'client_secret' => config('backup.google.client_secret'),
        ]);

        if ($response->json('error') === 'invalid_grant') {
            throw new BusinessRuleException('انتهى إذن Google Drive أو أُلغي. أعد الربط من صفحة النسخ الاحتياطي.');
        }
        $this->ensureOk($response);

        return $response->json();
    }

    private function ensureOk(Response $response): void
    {
        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->json('error_description') ?? $response->body();
            throw new BusinessRuleException('خطأ من Google Drive: '.mb_strimwidth((string) $message, 0, 200, '…'));
        }
    }
}
