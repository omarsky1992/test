<?php

namespace Tests\Feature;

use App\Enums\ActivationKind;
use App\Enums\PaymentMethod;
use App\Filament\Pages\WhatsappTest;
use App\Filament\Resources\MessageTemplates\Pages\ManageMessageTemplates;
use App\Filament\Resources\Subscribers\Pages\ViewSubscriber;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsappOutbox;
use App\Services\ActivationService;
use App\Services\PaymentService;
use App\Services\ReceiptImage;
use App\Services\Settings;
use App\Services\SubscriberService;
use App\WhatsApp\Outbox;
use App\WhatsApp\TestMessages;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\FakeWhatsApp;
use Tests\TestCase;

class WhatsAppMuteAndMediaTest extends TestCase
{
    public function test_a_muted_subscriber_gets_no_message_and_the_waiting_ones_are_dropped(): void
    {
        app(Settings::class)->set('subscriber_messages.enabled', true);
        $account = $this->account('علي حسين');
        $outbox = app(Outbox::class);
        $outbox->queue('07902223141', 'رسالة منتظرة', 'sub_expiring', 'waiting', $account->id);
        $outbox->queue('07702222222', 'تنبيه للموظف', 'alert_must_activate', 'staff', $account->id);

        Livewire::test(ViewSubscriber::class, ['record' => $account->subscriber_id])
            ->assertSee('إيقاف رسائل الواتساب')
            ->callAction('whatsappMute')->assertNotified();
        $this->assertTrue($account->subscriber->fresh()->whatsapp_muted);

        // An activation edited while muted tells the subscriber nothing.
        app(ActivationService::class)->activate($account, $this->plan(), ActivationKind::Full30);
        $this->assertFalse(WhatsappOutbox::where('kind', 'sub_renewal')->exists());
        $this->assertNull($outbox->queue('07902223141', 'جماعية', 'sub_broadcast', null, $account->id));

        $fake = new FakeWhatsApp;
        $outbox->dispatch($fake, CarbonImmutable::parse('2026-09-21 12:00'));
        $this->assertSame(['تنبيه للموظف'], array_column($fake->sent, 'text'));
        $this->assertSame('skipped', WhatsappOutbox::where('reference', 'waiting')->value('status'));

        // Switched back on: messages flow again.
        Livewire::test(ViewSubscriber::class, ['record' => $account->subscriber_id])->callAction('whatsappMute');
        $this->assertFalse($account->subscriber->fresh()->whatsapp_muted);
        $this->assertNotNull($outbox->queue('07902223141', 'بعد التشغيل', 'sub_expiring', null, $account->id));
    }

    public function test_muting_needs_the_permission_to_edit_subscribers(): void
    {
        $account = $this->account();
        $employee = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $employee->forceFill(['denied_permissions' => ['subscribers.update']])->save();
        $employee->assignRole('employee');
        $this->actingAs($employee);

        $this->expectException(AuthorizationException::class);
        app(SubscriberService::class)->setWhatsappMuted($account->subscriber, true);
    }

    public function test_a_template_goes_as_a_pdf_or_as_a_link_on_the_free_connection(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('whatsapp/templates/abc/عرض.pdf', '%PDF-1.4 test');
        MessageTemplate::where('auto_event', 'expiring')->update(['media_type' => 'pdf', 'media_path' => 'whatsapp/templates/abc/عرض.pdf']);
        MessageTemplate::where('auto_event', 'expiring')->first()->save(); // sets the file type
        $tests = app(TestMessages::class);

        $text = $tests->text('expiring', ['days' => 2]);
        $tests->send('07701234567', $text, 'main', $tests->media('expiring'));
        $tests->send('07701234568', $text, 'main', $tests->media('expiring'));
        $this->assertSame('application/pdf', WhatsappOutbox::first()->media_mime);

        // A connection that sends files (Meta, WAHA Plus): the PDF itself with the text under it.
        $plus = new FakeWhatsApp;
        $plus->images = true;
        app(Settings::class)->set('whatsapp.batch_size', 1);
        app(Outbox::class)->dispatch($plus, CarbonImmutable::parse('2026-09-21 12:00'));
        $this->assertSame(['عرض.pdf', $text], [$plus->files_sent[0]['name'], $plus->files_sent[0]['caption']]);

        // The free connection: the text with a signed link to the file.
        $free = new FakeWhatsApp;
        app(Outbox::class)->dispatch($free, CarbonImmutable::parse('2026-09-21 12:01'));
        $this->assertStringContainsString('📄 الملف: ', $free->lastReply());
        $url = trim(explode('📄 الملف: ', $free->lastReply())[1]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(explode('?', $url)[0])->assertForbidden();
    }

    public function test_the_template_form_offers_text_image_or_pdf(): void
    {
        $template = MessageTemplate::where('auto_event', 'expiring')->first();
        Livewire::test(ManageMessageTemplates::class)->assertOk()
            ->callTableAction('edit', $template, data: ['media_type' => 'pdf'])->assertHasTableActionErrors(['media_path' => 'required']);
        Livewire::test(ManageMessageTemplates::class)
            ->callTableAction('edit', $template, data: ['media_type' => 'text', 'body' => 'ينتهي {الأيام} يوم الساعة {ساعة_الانتهاء}'])->assertHasNoTableActionErrors();
        $this->assertSame('text', $template->fresh()->media_type);
    }

    public function test_the_payment_message_can_carry_the_receipt_as_an_image_or_a_pdf(): void
    {
        Storage::fake('local');
        config(['whatsapp.waha.url' => 'http://waha:3000', 'whatsapp.waha.api_key' => 'waha-key']);
        app(Settings::class)->set('subscriber_messages.enabled', true);
        $template = MessageTemplate::where('auto_event', 'payment')->sole();
        $template->update(['media_type' => 'receipt_image']);
        $account = $this->account('زينب كريم');

        $payment = app(PaymentService::class)->record($account, 25000, PaymentMethod::Cash, $this->cash());
        $message = WhatsappOutbox::where('payment_id', $payment->id)->sole();
        $this->assertSame('image/png', $message->media_mime);
        $this->assertStringStartsWith("\x89PNG", Storage::disk('local')->get($message->media_path));
        $this->assertStringContainsString($payment->receipt_number, $message->media_path);

        $template->update(['media_type' => 'receipt_pdf']);
        $second = app(PaymentService::class)->record($account, 5000, PaymentMethod::Cash, $this->cash());
        $pdf = WhatsappOutbox::where('payment_id', $second->id)->sole();
        $this->assertSame('application/pdf', $pdf->media_mime);
        $this->assertStringStartsWith('%PDF-1.4', Storage::disk('local')->get($pdf->media_path));

        $files = new FakeWhatsApp;
        $files->images = true;
        app(Outbox::class)->dispatch($files, CarbonImmutable::parse('2026-09-21 12:00'));
        $this->assertCount(1, $files->images_sent);
        $this->assertSame('application/pdf', $files->files_sent[0]['mime']);

        // On the test page a made-up receipt goes with the sample.
        Livewire::test(WhatsappTest::class)->set('event', 'payment')->set('phone', '07701234567')
            ->assertSee('الوصل PDF')->call('send');
        $test = WhatsappOutbox::where('kind', 'alert_test')->sole();
        $this->assertSame('application/pdf', $test->media_mime);
    }

    public function test_a_receipt_is_drawn_with_the_payment_details(): void
    {
        $receipts = app(ReceiptImage::class);
        $data = $receipts->sample('أحمد ضياء', 'ahmed_fiber', 35000);
        $this->assertSame([['استلمنا من', 'أحمد ضياء'], ['الحساب', 'ahmed_fiber']], [$data['rows'][1], $data['rows'][3]]);
        $image = imagecreatefromstring($receipts->png($data));
        $this->assertSame(900, imagesx($image));
        $this->assertStringEndsWith("%%EOF\n", $receipts->pdf($data));
    }

    public function test_the_expiring_message_tells_the_end_hour(): void
    {
        $template = MessageTemplate::where('auto_event', 'expiring')->first();
        $this->assertStringContainsString('{ساعة_الانتهاء}', $template->body);

        $text = app(TestMessages::class)->text('expiring', ['days' => 1]);
        $this->assertStringContainsString('الساعة '.MessageTemplate::hour(now()->addDay()), $text);
        $this->assertMatchesRegularExpression('/\d{1,2}:\d{2} (ص|م)/u', MessageTemplate::hour(now()));
    }
}
