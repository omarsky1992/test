<?php

namespace Tests\Feature;

use App\Models\BackupRun;
use App\Services\BackupService;
use App\Services\GoogleDrive;
use App\Services\Settings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'backup.google.client_id' => 'client-id',
            'backup.google.client_secret' => 'client-secret',
            'backup.trigger_token' => 'secret-token',
        ]);
    }

    private function connectDrive(): void
    {
        $settings = app(Settings::class);
        $settings->set('backup.google.refresh_token', Crypt::encryptString('refresh-1'));
        $settings->set('backup.google.connected_email', 'owner@gmail.com');
    }

    /**
     * Fake Google: token endpoint, folder creation, resumable upload and the cleanup listing.
     *
     * @return array<int, Request> recorded requests
     */
    private function fakeGoogle(bool $revoked = false): void
    {
        Http::fake(function (Request $request) use ($revoked) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'oauth2.googleapis.com/token') => $revoked
                    ? Http::response(['error' => 'invalid_grant'], 400)
                    : Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600]),
                str_contains($url, 'openidconnect.googleapis.com') => Http::response(['email' => 'Owner@gmail.com']),
                str_contains($url, 'upload/drive/v3/files') => Http::response('', 200, ['Location' => 'https://upload.test/session-1']),
                str_contains($url, 'upload.test/session-1') => Http::response(['id' => 'file-1', 'webViewLink' => 'https://drive.google.com/file/d/file-1']),
                str_contains($url, 'drive/v3/files') && $request->method() === 'POST' => Http::response(['id' => 'folder-1']),
                str_contains($url, 'drive/v3/files') && $request->method() === 'GET' => Http::response(['files' => [['id' => 'old-1']]]),
                str_contains($url, 'drive/v3/files') && $request->method() === 'DELETE' => Http::response('', 204),
                default => Http::response('unexpected '.$url, 500),
            };
        });
    }

    public function test_connecting_stores_an_encrypted_token_for_the_entered_email(): void
    {
        $this->fakeGoogle();
        app(Settings::class)->set('backup.google.email', 'owner@gmail.com');

        $this->get(route('backup.google.connect'))->assertRedirectContains('accounts.google.com');
        $state = session('google_oauth_state');

        $this->get(route('backup.google.callback', ['code' => 'code-1', 'state' => $state]))->assertRedirect('/backups');

        $settings = app(Settings::class);
        $this->assertSame('Owner@gmail.com', $settings->get('backup.google.connected_email'));
        $this->assertSame('refresh-1', Crypt::decryptString($settings->get('backup.google.refresh_token')));
    }

    public function test_connecting_with_a_different_google_account_is_refused(): void
    {
        $this->fakeGoogle();
        app(Settings::class)->set('backup.google.email', 'someone-else@gmail.com');
        $this->get(route('backup.google.connect'));

        $this->get(route('backup.google.callback', ['code' => 'code-1', 'state' => session('google_oauth_state')]));

        $this->assertFalse(app(GoogleDrive::class)->isConnected());
    }

    public function test_a_forged_callback_state_is_refused(): void
    {
        $this->fakeGoogle();
        app(Settings::class)->set('backup.google.email', 'owner@gmail.com');
        $this->get(route('backup.google.connect'));

        $this->get(route('backup.google.callback', ['code' => 'code-1', 'state' => 'forged']));

        $this->assertFalse(app(GoogleDrive::class)->isConnected());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token'));
    }

    public function test_backup_dumps_the_database_and_uploads_it_to_drive(): void
    {
        $this->connectDrive();
        $this->fakeGoogle();

        $run = app(BackupService::class)->run('manual');

        $this->assertSame('success', $run->status, (string) $run->error);
        $this->assertSame('file-1', $run->drive_file_id);
        $this->assertGreaterThan(1000, $run->size_bytes);
        $this->assertStringStartsWith('subs-backup-', $run->file_name);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://upload.test/session-1'
            && str_starts_with($r->body(), 'PGDMP'));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/old-1'));
        $this->assertSame('folder-1', app(Settings::class)->get('backup.google.folder_id'));
        $this->assertSame([], glob(storage_path('app/private/subs-backup-*')));
    }

    public function test_a_revoked_google_permission_is_recorded_as_a_failed_run(): void
    {
        $this->connectDrive();
        $this->fakeGoogle(revoked: true);

        $run = app(BackupService::class)->run('manual');

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('أعد الربط', $run->error);
    }

    public function test_the_daily_trigger_needs_the_secret_token(): void
    {
        $this->connectDrive();
        $this->fakeGoogle();
        auth()->logout();

        $this->postJson('/internal/backup')->assertForbidden();
        $this->postJson('/internal/backup', [], ['X-Backup-Token' => 'wrong'])->assertForbidden();
        $this->postJson('/internal/backup', [], ['X-Backup-Token' => 'secret-token'])->assertOk()->assertJson(['status' => 'success']);

        $this->assertSame('schedule', BackupRun::sole()->trigger);
    }

    public function test_the_trigger_is_disabled_without_a_configured_token(): void
    {
        config(['backup.trigger_token' => null]);

        $this->postJson('/internal/backup', [], ['X-Backup-Token' => ''])->assertForbidden();
    }

    public function test_backup_page_opens(): void
    {
        $this->get('/backups')->assertOk()->assertSee('النسخ الاحتياطي');
    }
}
