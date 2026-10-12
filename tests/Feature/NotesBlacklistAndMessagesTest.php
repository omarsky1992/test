<?php

namespace Tests\Feature;

use App\Enums\DebtBucket;
use App\Enums\DebtSource;
use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\BusinessRuleException;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\EmployeeHome;
use App\Filament\Pages\WhatsappBroadcast;
use App\Filament\Resources\Blacklist\Pages\ManageBlacklist;
use App\Filament\Resources\Debts\Pages\ListDebts;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\SubscriberCards\SubscriberCardResource;
use App\Filament\Resources\Subscribers\Pages\ListSubscribers;
use App\Filament\Resources\Subscribers\Pages\ViewSubscriber;
use App\Filament\Resources\Subscribers\RelationManagers\NotesRelationManager;
use App\Filament\Resources\Subscribers\SubscriberResource;
use App\Filament\Resources\WhatsappBroadcasts\Pages\ListWhatsappBroadcasts;
use App\Filament\Widgets\BlacklistedSubscribers;
use App\Filament\Widgets\SubscribersWithNotes;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BlacklistEntry;
use App\Models\Debt;
use App\Models\Payment;
use App\Models\PaymentMethodType;
use App\Models\Subscriber;
use App\Models\SubscriberNote;
use App\Models\User;
use App\Models\WhatsappBroadcast as Broadcast;
use App\Models\WhatsappOutbox;
use App\Services\BlacklistService;
use App\Services\DebtService;
use App\Services\Ledger;
use App\Services\PaymentService;
use App\Services\Settings;
use App\Services\SubscriberNoteService;
use App\Support\SubscriberFlags;
use App\WhatsApp\BroadcastService;
use App\WhatsApp\Notifier;
use App\WhatsApp\Outbox;
use App\WhatsApp\WahaGateway;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\FakeWhatsApp;
use Tests\TestCase;

class NotesBlacklistAndMessagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['whatsapp.waha.url' => 'http://waha:3000', 'whatsapp.waha.api_key' => 'waha-key', 'whatsapp.send_delay' => [0, 0]]);
        SubscriberFlags::forget();
    }

    private function withDebts(string $name = 'علي حسين'): Account
    {
        $account = $this->account($name, phone: '0790'.random_int(1000000, 9999999));
        app(DebtService::class)->create($account, 20000, DebtBucket::Primary, DebtSource::Manual);
        app(DebtService::class)->create($account, 35000, DebtBucket::Secondary, DebtSource::Manual);

        return $account;
    }

    private function employee(array $extra = []): User
    {
        $user = User::factory()->create(['name' => 'أحمد', 'branch_id' => $this->admin->branch_id]);
        $user->assignRole('employee');
        $user->givePermissionTo($extra);

        return $user;
    }

    /** @return array{int, int, int} primary, secondary, cash */
    private function money(): array
    {
        return [$this->balanceOf(Ledger::AR_PRIMARY), $this->balanceOf(Ledger::AR_SECONDARY), $this->balanceOf($this->cash()->ledger_account_id)];
    }

    // ---- وضع المشترك ----

    public function test_a_note_is_added_shown_closed_and_kept_in_the_history(): void
    {
        $account = $this->withDebts();
        $subscriber = $account->subscriber;
        $money = $this->money();

        Livewire::test(ViewSubscriber::class, ['record' => $subscriber->id])
            ->assertActionVisible('subscriberNotes')
            ->callAction('subscriberNotes', data: ['body' => 'وعد بتسديد الدين خلال 7 أيام']);

        $note = SubscriberNote::sole();
        $this->assertSame(['active', $this->admin->id, $subscriber->id], [$note->status, $note->created_by, $note->subscriber_id]);
        $this->assertTrue($note->created_at->equalTo(now()));
        $this->assertSame('web', AuditLog::where('action', 'note.created')->sole()->source);

        Livewire::test(NotesRelationManager::class, ['ownerRecord' => $subscriber, 'pageClass' => ViewSubscriber::class])
            ->assertSee('وعد بتسديد الدين خلال 7 أيام')->assertSee($this->admin->name)
            ->callTableAction('closeNote', $note, data: ['reason' => 'سدّد']);

        $note->refresh();
        $this->assertSame(['closed', 'سدّد', $this->admin->id], [$note->status, $note->close_reason, $note->closed_by]);
        Livewire::test(NotesRelationManager::class, ['ownerRecord' => $subscriber, 'pageClass' => ViewSubscriber::class])
            ->assertSee('وعد بتسديد الدين خلال 7 أيام')->assertSee('منتهية');

        $this->assertSame($money, $this->money(), 'notes never touch money');
        $this->assertSame(2, Debt::where('account_id', $account->id)->where('status', 'open')->count());
    }

    public function test_deleting_a_note_needs_its_own_permission_and_only_hides_it(): void
    {
        $subscriber = $this->account()->subscriber;
        $note = app(SubscriberNoteService::class)->add($subscriber, 'تم التواصل معه ولم يرد');

        $employee = $this->employee();
        $this->actingAs($employee);
        app(SubscriberNoteService::class)->add($subscriber, 'ملاحظة الموظف');
        app(SubscriberNoteService::class)->close($note);
        try {
            app(SubscriberNoteService::class)->delete($note);
            $this->fail('an employee without the permission deleted a note');
        } catch (AuthorizationException) {
        }
        Livewire::test(NotesRelationManager::class, ['ownerRecord' => $subscriber, 'pageClass' => ViewSubscriber::class])
            ->assertTableActionHidden('deleteNote', $note);

        $employee->givePermissionTo('notes.delete');
        app(SubscriberNoteService::class)->delete($note->fresh(), 'خطأ');
        $this->assertSoftDeleted($note);
        $this->assertSame($employee->id, SubscriberNote::withTrashed()->find($note->id)->deleted_by);
        $this->assertSame(1, $subscriber->notes()->count());
        $this->assertSame('تم التواصل معه ولم يرد', AuditLog::where('action', 'note.deleted')->sole()->old_values['body']);
    }

    public function test_the_home_lists_each_subscriber_with_notes_once_until_all_are_closed(): void
    {
        $subscriber = $this->account('زينب كريم')->subscriber;
        $other = $this->account('حسن جبار')->subscriber;
        $service = app(SubscriberNoteService::class);
        $first = $service->add($subscriber, 'تم الاتصال');
        $second = $service->add($subscriber, 'موعد الدفع الخميس');

        Livewire::test(SubscribersWithNotes::class)
            ->assertCanSeeTableRecords([$subscriber])->assertCanNotSeeTableRecords([$other])
            ->assertCountTableRecords(1)->assertSee('موعد الدفع الخميس')->assertSee('2 ملاحظات نشطة');

        $service->close($first);
        $service->close($second);
        Livewire::test(SubscribersWithNotes::class)->assertCountTableRecords(0);
        $this->assertSame(2, $subscriber->notes()->count(), 'closed notes stay in the history');

        // Employees see it on their home too.
        $service->add($other, 'ملاحظة');
        $this->actingAs($this->employee());
        $this->get(EmployeeHome::getUrl())->assertOk()->assertSee('المشتركون الذين لديهم ملاحظات');
    }

    public function test_names_with_active_notes_are_marked_everywhere_and_the_mark_goes_with_the_last_note(): void
    {
        $account = $this->withDebts('زينب كريم');
        $subscriber = $account->subscriber;
        $this->withDebts('حسن جبار');
        $note = app(SubscriberNoteService::class)->add($subscriber, 'مهم');
        $notesUrl = SubscriberFlags::notesUrl($subscriber->id);

        Livewire::test(ListSubscribers::class)->assertSeeHtml(e($notesUrl));
        Livewire::test(ListDebts::class, ['activeTab' => 'primary'])->assertSeeHtml(e($notesUrl));
        Livewire::test(ListDebts::class, ['activeTab' => 'secondary'])->assertSeeHtml(e($notesUrl));
        $this->assertStringContainsString('📝', SubscriberResource::getGlobalSearchResults('زينب')->first()->title);
        $this->get(SubscriberResource::getUrl('view', ['record' => $subscriber, 'relation' => 'notes']))->assertOk()->assertSee('وضع المشترك');
        Livewire::withQueryParams(['relation' => 'notes'])->test(ViewSubscriber::class, ['record' => $subscriber->id])->assertSet('activeRelationManager', 'notes');
        Livewire::test(ListSubscribers::class)->filterTable('has_notes')->assertCountTableRecords(1);

        // The whole row (or card) is shaded while the note is active.
        Livewire::test(ListSubscribers::class)->assertSeeHtml('subs-has-note');
        Livewire::test(ListDebts::class, ['activeTab' => 'secondary'])->assertSeeHtml('subs-has-note');
        $this->assertSame('subs-has-note', SubscriberFlags::rowClass($subscriber->id));
        $this->get(SubscriberCardResource::getUrl())->assertOk()->assertSee('subs-has-note', false);

        app(SubscriberNoteService::class)->close($note);
        Livewire::test(ListSubscribers::class)->assertDontSeeHtml('subs-has-note');
        $this->assertNull(SubscriberFlags::rowClass($subscriber->id));
        Livewire::test(ListSubscribers::class)->assertDontSeeHtml(e($notesUrl));
        Livewire::test(ListDebts::class, ['activeTab' => 'primary'])->assertDontSeeHtml(e($notesUrl));
        $this->assertStringNotContainsString('📝', SubscriberResource::getGlobalSearchResults('زينب')->first()->title);
    }

    // ---- القائمة السوداء ----

    public function test_the_blacklist_adds_and_removes_by_id_and_changes_nothing_else(): void
    {
        $account = $this->withDebts('كرار حسن');
        $subscriber = $account->subscriber;
        $before = [$this->money(), $account->fresh()->getAttributes(), $subscriber->fresh()->getAttributes()];

        Livewire::test(ManageBlacklist::class)
            ->callAction('addToBlacklist', data: ['subscriber_id' => $subscriber->id, 'reason_type' => 'late_payments', 'reason' => 'ثلاث مرات'])
            ->assertHasNoActionErrors();
        $entry = BlacklistEntry::sole();
        $this->assertSame([$subscriber->id, 'late_payments', 'ثلاث مرات', $this->admin->id], [$entry->subscriber_id, $entry->reason_type, $entry->reason, $entry->added_by]);
        $this->assertSame(1, Subscriber::where('full_name', 'كرار حسن')->count(), 'never a copy of the subscriber');
        Livewire::test(ManageBlacklist::class)->assertSee('كرار حسن')->assertSee('تكرار عدم الالتزام بمواعيد التسديد');

        $this->expectExceptionOnce(fn () => app(BlacklistService::class)->add($subscriber, 'other', 'مرة ثانية'));

        // Nothing is blocked: a payment and an activation still work.
        app(PaymentService::class)->record($account, 20000, PaymentMethod::Cash, $this->cash());
        $this->assertSame(1, Payment::count());

        Livewire::test(ManageBlacklist::class)->callTableAction('remove', $entry, data: ['reason' => 'التزم']);
        $this->assertNotNull($entry->fresh()->removed_at);
        $this->assertSame(1, BlacklistEntry::count(), 'removal keeps the history');
        $this->assertSame(1, AuditLog::where('action', 'blacklist.added')->count());
        $this->assertSame(1, AuditLog::where('action', 'blacklist.removed')->count());

        // Only the payment moved money; the blacklist itself moved nothing.
        [$primary, $secondary] = $before[0];
        $this->assertSame([$primary - 20000, $secondary], array_slice($this->money(), 0, 2));
        $this->assertSame($before[1]['status'], $account->fresh()->status->value);
    }

    public function test_the_blacklist_is_on_the_home_pages(): void
    {
        $listed = $this->account('كرار حسن')->subscriber;
        $other = $this->account('حسن جبار')->subscriber;
        $entry = app(BlacklistService::class)->add($listed, 'broken_promises');

        Livewire::test(BlacklistedSubscribers::class)
            ->assertCanSeeTableRecords([$entry])->assertSee('كرار حسن')->assertDontSee('حسن جبار')
            ->assertSee('تكرار التأخر عن الموعد المتفق عليه')
            ->callTableAction('remove', $entry, data: ['reason' => 'التزم']);
        $this->assertNotNull($entry->fresh()->removed_at);
        Livewire::test(BlacklistedSubscribers::class)->assertCountTableRecords(0);

        app(BlacklistService::class)->add($other, 'late_payments');
        Livewire::test(Dashboard::class)->assertOk()->assertSeeLivewire(BlacklistedSubscribers::class);
        $this->actingAs($this->employee());
        $this->get(EmployeeHome::getUrl())->assertOk()->assertSee('القائمة السوداء');
        Livewire::test(BlacklistedSubscribers::class)->assertSee('حسن جبار')->assertTableActionHidden('remove', BlacklistEntry::current()->sole());
    }

    public function test_managing_the_blacklist_needs_the_permission(): void
    {
        $subscriber = $this->account()->subscriber;
        $this->actingAs($this->employee());

        Livewire::test(ManageBlacklist::class)->assertActionHidden('addToBlacklist');
        $this->expectException(AuthorizationException::class);
        app(BlacklistService::class)->add($subscriber, 'late_payments');
    }

    private function expectExceptionOnce(callable $call): void
    {
        try {
            $call();
            $this->fail('expected a refusal');
        } catch (BusinessRuleException) {
            $this->addToAssertionCount(1);
        }
    }

    // ---- Payment methods and the transaction number ----

    public function test_payments_with_or_without_a_transaction_number(): void
    {
        $account = $this->withDebts();
        $qi = PaymentMethodType::where('code', 'qi_card')->sole();
        $master = PaymentMethodType::where('code', 'mastercard')->sole();
        $this->assertSame(['كي كارد', 'ماستر كارد'], [$qi->name_ar, $master->name_ar]);
        $box = $this->cash();
        $payments = app(PaymentService::class);

        $cash = $payments->record($account, 5000, PaymentMethod::Cash, $box);
        $withRef = $payments->record($account, lines: [['method' => $qi, 'money_account' => $box, 'amount' => 6000, 'reference' => 'TXN-7788']]);
        $noRef = $payments->record($account, lines: [['method' => $master, 'money_account' => $box, 'amount' => 7000]]);

        $this->assertNull($cash->lines->sole()->reference);
        $this->assertSame('TXN-7788', $withRef->lines->sole()->reference);
        $this->assertNull($noRef->lines->sole()->reference);
        $this->assertSame(18000, $this->balanceOf($box->ledger_account_id));
        $this->get(route('receipts.show', $withRef))->assertOk()->assertSee('TXN-7788')->assertSee('كي كارد');
        Livewire::test(ListPayments::class)->assertSee('TXN-7788')->searchTable('TXN-7788')->assertCountTableRecords(1);
    }

    // ---- WhatsApp after a payment ----

    public function test_the_subscriber_is_told_after_the_payment_is_saved_once_and_never_on_rollback(): void
    {
        app(Settings::class)->set('subscriber_messages.enabled', true);
        $account = $this->withDebts('زينب كريم');
        $qi = PaymentMethodType::where('code', 'qi_card')->sole();

        try {
            DB::transaction(function () use ($account) {
                app(PaymentService::class)->record($account, 10000, PaymentMethod::Cash, $this->cash());
                throw new \RuntimeException('the save failed');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, WhatsappOutbox::count(), 'no message before the payment is saved');

        $key = (string) Str::uuid();
        $payment = app(PaymentService::class)->record($account, lines: [['method' => $qi, 'money_account' => $this->cash(), 'amount' => 25000, 'reference' => 'Q1']], idempotencyKey: $key);
        $message = WhatsappOutbox::sole();
        $this->assertSame(['sub_payment', $payment->id, 'pending'], [$message->kind, $message->payment_id, $message->status]);
        $this->assertStringContainsString('تم استلام دفعتك بنجاح', $message->body);
        $this->assertStringContainsString('زينب كريم', $message->body);
        $this->assertStringContainsString('25,000', $message->body);
        $this->assertStringContainsString('كي كارد', $message->body);
        $this->assertStringContainsString('2026/09/21', $message->body);

        // The same request again, or the notifier again: still one payment and one message.
        app(PaymentService::class)->record($account, lines: [['method' => $qi, 'money_account' => $this->cash(), 'amount' => 25000]], idempotencyKey: $key);
        app(Notifier::class)->payment($payment);
        $this->assertSame([1, 1], [Payment::count(), WhatsappOutbox::count()]);

        // Sending fails: the payment stays as it is and the message waits for another try, then fails.
        $fake = new FakeWhatsApp;
        $fake->fail = true;
        $at = CarbonImmutable::parse('2026-09-21 12:00');
        app(Outbox::class)->dispatch($fake, $at);
        $this->assertSame(['pending', 1], [$message->fresh()->status, $message->fresh()->attempts]);
        app(Outbox::class)->dispatch($fake, $at);
        app(Outbox::class)->dispatch($fake, $at);
        $this->assertSame('failed', $message->fresh()->status);
        $this->assertSame(1, Payment::count());
        $this->assertSame(DocumentStatus::Posted, $payment->fresh()->status);
        Livewire::test(ListPayments::class)->assertSee('فشلت');
    }

    public function test_no_payment_message_without_a_phone_or_a_whatsapp_connection(): void
    {
        app(Settings::class)->set('subscriber_messages.enabled', true);
        $account = $this->withDebts();
        $account->update(['phone' => null]);
        $account->subscriber->update(['phone' => null]);
        app(PaymentService::class)->record($account->fresh(), 5000, PaymentMethod::Cash, $this->cash());
        $this->assertSame(0, WhatsappOutbox::count());

        config(['whatsapp.waha.api_key' => null]);
        app(PaymentService::class)->record($this->withDebts('حسن جبار'), 5000, PaymentMethod::Cash, $this->cash());
        $this->assertSame(0, WhatsappOutbox::count());
        $this->assertSame(2, Payment::count());
    }

    // ---- Bulk messages ----

    public function test_a_bulk_message_goes_only_to_the_chosen_subscribers(): void
    {
        $a = $this->account('زينب كريم', phone: '07901110001');
        $b = $this->account('حسن جبار', phone: '07901110002');
        $c = $this->account('كرار حسن', phone: '07901110003');
        $none = $this->account('بلا رقم', phone: '07901110004');
        $none->update(['phone' => null]);
        $none->subscriber->update(['phone' => null]);
        $money = $this->money();

        Livewire::test(WhatsappBroadcast::class)
            ->set('selected', [(string) $a->id, (string) $b->id, (string) $none->id])
            ->fillForm(['body' => 'عزيزي {الاسم}، صيانة غداً الساعة 10'])
            ->assertSee('المستلمون المحددون')->assertSee('كما تصل إلى')
            ->call('send');

        $broadcast = Broadcast::sole();
        $this->assertSame([2, 1, $this->admin->id], [$broadcast->recipients, $broadcast->skipped, $broadcast->created_by]);
        $rows = WhatsappOutbox::where('broadcast_id', $broadcast->id)->get();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $rows->pluck('account_id')->all());
        $this->assertFalse(WhatsappOutbox::where('account_id', $c->id)->exists(), 'never someone who was not chosen');
        $this->assertStringContainsString('عزيزي زينب كريم', $rows->firstWhere('account_id', $a->id)->body);
        $this->assertSame(['sub_broadcast'], $rows->pluck('kind')->unique()->values()->all());
        $this->assertSame($money, $this->money());

        Livewire::test(ListWhatsappBroadcasts::class)->assertSee('صيانة غداً')->mountTableAction('recipients', $broadcast)->assertHasNoErrors();
        $log = view('filament.broadcasts.recipients', ['broadcast' => $broadcast, 'messages' => $broadcast->messages()->with('account.subscriber')->get()])->render();
        $this->assertStringContainsString('زينب كريم', $log);
        $this->assertStringContainsString('بالانتظار', $log);
    }

    public function test_bulk_messages_need_their_permission(): void
    {
        $this->actingAs($this->employee());
        $this->get(WhatsappBroadcast::getUrl())->assertForbidden();
        $this->expectException(AuthorizationException::class);
        app(BroadcastService::class)->send(collect([$this->account()]), 'نص');
    }

    public function test_an_image_goes_as_an_image_when_the_connection_can_and_as_a_link_otherwise(): void
    {
        Storage::fake('local');
        $path = UploadedFile::fake()->image('maintenance.jpg')->store('whatsapp/broadcasts', 'local');
        $broadcast = app(BroadcastService::class)->send(collect([$this->account('زينب كريم', phone: '07901110001')]), 'موعد الصيانة', $path, 'image/jpeg');
        $message = WhatsappOutbox::where('broadcast_id', $broadcast->id)->sole();
        $at = CarbonImmutable::parse('2026-09-21 12:00');

        $images = new FakeWhatsApp;
        $images->images = true;
        app(Outbox::class)->dispatch($images, $at);
        $this->assertCount(1, $images->images_sent);
        $this->assertSame(['9647901110001', 'موعد الصيانة', 'image/jpeg'], [$images->images_sent[0]['to'], $images->images_sent[0]['caption'], $images->images_sent[0]['mime']]);
        $this->assertSame('sent', $message->fresh()->status);

        // Free WAHA Core: the text with a signed link to the image.
        $message->fresh()->update(['status' => 'pending', 'sent_at' => null]);
        $text = new FakeWhatsApp;
        app(Outbox::class)->dispatch($text, $at);
        $this->assertStringContainsString('موعد الصيانة', $text->lastReply());
        preg_match('#https?://\S+#', $text->lastReply(), $m);
        $this->get($m[0])->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(route('whatsapp.broadcast.image', $broadcast))->assertForbidden();
        $this->assertFalse(app(WahaGateway::class)->supportsImages(), 'WAHA Core sends text only');
    }
}
