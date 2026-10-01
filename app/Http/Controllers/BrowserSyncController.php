<?php

namespace App\Http\Controllers;

use App\Services\Settings;
use App\Sync\BrowserPayloadClient;
use App\Sync\BrowserScripts;
use App\Sync\CompanySync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The receiving end of the browser sync button. The button runs inside the company panel
 * (admin.ftth.iq) with the user's own session, reads the subscriber lists, and passes them to
 * a small window of this system, which posts them here with the user's normal login.
 */
class BrowserSyncController extends Controller
{
    public const PANEL_ORIGIN = 'https://admin.ftth.iq';

    public function page(): View
    {
        abort_unless(auth()->user()->can('sync.run'), 403);

        return view('sync.browser', ['panelOrigin' => self::PANEL_ORIGIN]);
    }

    /**
     * The Chrome extension, built for the address this system is opened on.
     */
    public function extension(Request $request, Settings $settings)
    {
        abort_unless($request->user()->can('sync.run'), 403);
        $app = $request->getSchemeAndHttpHost();
        $path = tempnam(sys_get_temp_dir(), 'ext');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('subs-sync/manifest.json', BrowserScripts::manifest($app));
        $zip->addFromString('subs-sync/content.js', BrowserScripts::contentScript($app, (string) $settings->get('sync.client_app')));
        $zip->close();

        return response()->download($path, 'subs-sync-extension.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * Step 1: the lists arrive; answer which customers' details to fetch.
     */
    public function plan(Request $request, CompanySync $sync, Settings $settings): JsonResponse
    {
        abort_unless($request->user()->can('sync.run'), 403);
        [$customers, $subscriptions] = $this->lists($request);
        $client = new BrowserPayloadClient($customers, $subscriptions);

        return response()->json([
            'needs' => $sync->customersNeedingDetails($client, max(0, (int) $settings->get('sync.detail_limit'))),
            'subscriptions' => count($subscriptions),
        ]);
    }

    /**
     * Step 2: lists and details arrive; run the sync.
     */
    public function run(Request $request, CompanySync $sync): JsonResponse
    {
        abort_unless($request->user()->can('sync.run'), 403);
        @set_time_limit(600);
        [$customers, $subscriptions] = $this->lists($request);
        $details = array_filter((array) $request->input('details', []), 'is_array');

        $run = $sync->run('browser', new BrowserPayloadClient($customers, $subscriptions, $details));
        CompanySync::markDetailsChecked(array_map('strval', array_keys($details)));

        $s = $run->stats ?? [];
        $summary = $run->status === 'success'
            ? "{$s['received']} اشتراك · جديد {$s['subscribers_created']} مشترك و{$s['accounts_created']} حساب · محدَّث {$s['accounts_updated']} · تجديدات {$s['renewals']} (ديون ثانوية {$s['debts_created']}، تفعيل كامل {$s['activations']}) · أخطاء ".count($s['errors'] ?? [])
            : (string) $run->error;

        return response()->json(['status' => $run->status, 'summary' => $summary, 'run_id' => $run->id], $run->status === 'success' ? 200 : 422);
    }

    /**
     * @return array{0: array<int, array>, 1: array<int, array>}
     */
    private function lists(Request $request): array
    {
        $request->validate([
            'customers' => ['present', 'array', 'max:100000'],
            'subscriptions' => ['required', 'array', 'min:1', 'max:100000'],
        ], ['subscriptions.required' => 'لم يصل أي اشتراك من موقع الشركة.', 'subscriptions.min' => 'لم يصل أي اشتراك من موقع الشركة.']);

        return [
            array_values(array_filter((array) $request->input('customers', []), 'is_array')),
            array_values(array_filter((array) $request->input('subscriptions', []), 'is_array')),
        ];
    }
}
