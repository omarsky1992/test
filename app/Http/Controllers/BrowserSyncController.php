<?php

namespace App\Http\Controllers;

use App\Services\Settings;
use App\Sync\BrowserPayloadClient;
use App\Sync\BrowserScripts;
use App\Sync\CompanySync;
use App\Sync\OntReadings;
use App\Sync\WalletTransactions;
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
    public function run(Request $request, CompanySync $sync, WalletTransactions $walletLog): JsonResponse
    {
        abort_unless($request->user()->can('sync.run'), 403);
        @set_time_limit(600);
        [$customers, $subscriptions] = $this->lists($request);
        $details = array_filter((array) $request->input('details', []), 'is_array');

        $run = $sync->run('browser', new BrowserPayloadClient($customers, $subscriptions, $details));
        CompanySync::markDetailsChecked(array_map('strval', array_keys($details)));

        // The wallet log, when the extension could read it: Card or Wallet on each renewal.
        $wallet = $request->input('wallet');
        $paid = $run->status === 'success' && is_array($wallet) && is_array($wallet['items'] ?? null)
            ? $walletLog->apply(array_slice($wallet['items'], 0, 2000)) : null;

        $s = $run->stats ?? [];
        $summary = $run->status === 'success'
            ? "{$s['received']} اشتراك · جديد {$s['subscribers_created']} مشترك و{$s['accounts_created']} حساب · محدَّث {$s['accounts_updated']} · تجديدات {$s['renewals']} (ديون ثانوية {$s['debts_created']}، تفعيل كامل {$s['activations']}) · أخطاء ".count($s['errors'] ?? [])
                .($paid !== null
                    ? " · حركات المحفظة: {$paid['matched']} تجديد عُرف نوع دفعه (Card {$paid['card']}، Wallet {$paid['wallet']})"
                    : ' · لم تُقرأ حركات المحفظة: افتح صفحة المحفظة بموقع الشركة مرة وحدة والإضافة منصّبة، ثم أعد المزامنة')
            : (string) $run->error;

        return response()->json(['status' => $run->status, 'summary' => $summary, 'run_id' => $run->id], $run->status === 'success' ? 200 : 422);
    }

    /**
     * «القدرة المستلمة», its own run (never part of the activations sync): which devices to read,
     * the running accounts whose reading is oldest.
     */
    public function ontPlan(Request $request, Settings $settings): JsonResponse
    {
        abort_unless($request->user()->can('sync.run'), 403);

        $hours = max(0, (int) $settings->get('sync.rx_fresh_hours'));

        return response()->json([
            'ont' => OntReadings::needs(max(0, (int) $settings->get('sync.rx_limit')), $hours),
            'message' => "كل الأجهزة الفعّالة مقروءة خلال آخر {$hours} ساعات.",
        ]);
    }

    /** The readings arrive: the last one is kept on each account. */
    public function ont(Request $request, OntReadings $readings): JsonResponse
    {
        abort_unless($request->user()->can('sync.run'), 403);
        $ont = $request->input('ont');
        $count = is_array($ont) ? $readings->apply(array_slice($ont, 0, 20000, true)) : 0;

        return response()->json(['status' => 'success', 'summary' => "قُرئت القدرة المستلمة لـ {$count} جهاز", 'count' => $count]);
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
