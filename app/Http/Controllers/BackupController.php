<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\Backups;
use App\Services\Audit;
use App\Services\BackupService;
use App\Services\GoogleDrive;
use App\Services\Settings;
use Filament\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BackupController extends Controller
{
    /**
     * Sends the owner to Google to approve Drive access for the email saved on the backup page.
     */
    public function connect(Request $request, GoogleDrive $drive, Settings $settings): RedirectResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $email = $settings->get('backup.google.email');
        abort_if(blank($email) || ! $drive->isConfigured(), 422, 'Google Drive not configured.');

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away($drive->authorizationUrl($email, $state));
    }

    public function callback(Request $request, GoogleDrive $drive, Settings $settings, Audit $audit): RedirectResponse
    {
        abort_unless($request->user()->can('settings.manage'), 403);
        $expected = $request->session()->pull('google_oauth_state');

        try {
            if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
                throw new BusinessRuleException('انتهت صلاحية طلب الربط. أعد المحاولة.');
            }
            if ($request->filled('error')) {
                throw new BusinessRuleException('لم تتم الموافقة على الوصول إلى Google Drive.');
            }
            $email = $drive->connect((string) $request->query('code'), (string) $settings->get('backup.google.email'));
            $audit->log('backup.drive_connected', 'settings', null, ['email' => $email]);
            Notification::make()->success()->title("تم ربط Google Drive: {$email}")->send();
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
        }

        return redirect(Backups::getUrl());
    }

    /**
     * Daily trigger for hosts without a scheduler (called by GitHub Actions with a secret token).
     */
    public function trigger(Request $request, BackupService $backups): JsonResponse
    {
        $token = (string) config('backup.trigger_token');
        abort_if($token === '' || ! hash_equals($token, (string) $request->header('X-Backup-Token')), 403);

        try {
            $run = $backups->run('schedule');
        } catch (BusinessRuleException $e) {
            return response()->json(['status' => 'busy', 'message' => $e->getMessage()], 409);
        }

        return response()->json(
            ['status' => $run->status, 'file' => $run->file_name, 'error' => $run->error],
            $run->status === 'success' ? 200 : 500,
        );
    }
}
