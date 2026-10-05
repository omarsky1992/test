<?php

namespace App\WhatsApp;

use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappNumber;
use App\Services\Audit;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Handles the messages of one webhook delivery. Order matters: each message is stored once by
 * its WhatsApp ID (a repeated delivery does nothing), then the sender's number is checked before
 * anything in the message is read; only an authorized number gets its voice note transcribed,
 * its text understood and its command executed.
 */
class Inbox
{
    /** A reply within this many minutes to a clarifying question continues the same command. */
    private const FOLLOW_UP_MINUTES = 15;

    public function __construct(
        private Gateway $gateway,
        private Transcriber $transcriber,
        private Interpreter $interpreter,
        private CommandExecutor $executor,
        private Audit $audit,
        private Settings $settings,
    ) {
    }

    public function handle(array $payload): void
    {
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['messages'] ?? [] as $message) {
                    if (is_array($message) && filled($message['id'] ?? null) && filled($message['from'] ?? null)) {
                        $this->receive($message);
                    }
                }
            }
        }
    }

    /**
     * Messages whose processing was cut off (the server restarted, a crash) are closed after ten
     * minutes and the sender is told, so nobody waits for an answer that never comes.
     */
    public function closeStuck(): int
    {
        $stuck = WhatsappMessage::where('status', 'received')->where('received_at', '<', now()->subMinutes(10))->get();
        foreach ($stuck as $record) {
            $authorized = $record->whatsapp_number_id !== null;
            $record->update(['status' => 'failed', 'error' => trim(($record->error ? $record->error."\n" : '').'انقطعت المعالجة قبل اكتمالها'), 'processed_at' => now()]);
            if ($authorized) {
                $reply = $record->type === 'audio'
                    ? '🎤 تعذّر إكمال معالجة رسالتك الصوتية. أعد إرسالها أو اكتب الأمر.'
                    : '❌ تعذّر إكمال طلبك. أعد إرساله.';
                app(Outbox::class)->queue($record->from_phone, $reply, 'alert_stuck_reply', "stuck:{$record->id}");
                $record->update(['reply' => $reply]);
            }
        }

        return $stuck->count();
    }

    /**
     * One message in the Cloud API shape: id, from, timestamp, type (text|audio), text.body, audio.id.
     */
    public function receive(array $message): void
    {
        $from = WhatsappNumber::normalize((string) $message['from']);
        try {
            // Its own savepoint, so a duplicate does not break an enclosing transaction.
            $record = \Illuminate\Support\Facades\DB::transaction(fn () => WhatsappMessage::create([
                'wa_message_id' => mb_substr((string) $message['id'], 0, 150),
                'from_phone' => mb_substr($from, 0, 20),
                'type' => mb_substr((string) ($message['type'] ?? 'unknown'), 0, 15),
                'status' => 'received',
                'received_at' => isset($message['timestamp']) && is_numeric($message['timestamp'])
                    ? CarbonImmutable::createFromTimestamp((int) $message['timestamp'], config('app.timezone')) : now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return; // Already received: Meta delivers again when it does not get its 200 fast enough.
        }

        if (! $this->settings->get('whatsapp.enabled')) {
            $record->update(['status' => 'ignored', 'error' => 'لوحة تحكم واتساب متوقفة من الإعدادات', 'processed_at' => now()]);

            return;
        }

        $number = WhatsappNumber::with('user')->where('phone', $from)->first();
        if ($number === null || ! $number->is_active || ! $number->user?->is_active) {
            $reply = (string) $this->settings->get('whatsapp.unauthorized_message');
            $record->update(['status' => 'unauthorized', 'reply' => $reply, 'whatsapp_number_id' => $number?->id, 'processed_at' => now()]);
            $this->audit->log('whatsapp.unauthorized', $record, null, ['phone' => $from, 'number_disabled' => $number !== null], null, null, 'whatsapp');
            $this->reply($record, $from, $reply);

            return;
        }

        $user = $number->user;
        $record->update(['whatsapp_number_id' => $number->id, 'user_id' => $user->id]);
        $number->forceFill(['last_used_at' => now()])->saveQuietly();

        try {
            $result = $this->run($record, $message, $user);
        } catch (\Throwable $e) {
            Log::error('WhatsApp command failed', ['message' => $record->id, 'error' => $e->getMessage()]);
            $record->update(['error' => mb_substr($e->getMessage(), 0, 2000)]);
            $result = Result::failed('❌ صار خطأ وما تنفذ الطلب. جرّب مرة ثانية أو سجّله من البرنامج.');
        }

        $record->update(['status' => $result->status, 'result' => $result->changes ?: null, 'reply' => $result->reply, 'processed_at' => now()]);
        $this->reply($record, $from, $result->reply);
    }

    private function run(WhatsappMessage $record, array $message, User $user): Result
    {
        $text = match ($message['type'] ?? null) {
            'text' => trim((string) ($message['text']['body'] ?? '')),
            'audio' => null,
            default => '',
        };
        if ($text === null) {
            if (! $this->settings->get('whatsapp.voice_enabled')) {
                return Result::failed('الرسائل الصوتية متوقفة، اكتب الأمر كتابة.');
            }
            if ($this->transcriber instanceof HttpTranscriber && ! HttpTranscriber::configured()) {
                $record->update(['error' => 'OPENAI_API_KEY غير مضبوط: لا يمكن تحويل الصوت إلى نص']);

                return Result::failed('🎤 وصلت رسالتك الصوتية، لكن تحويل الصوت إلى نص غير مفعّل بعد (خدمة التحويل على السيرفر غير مضبوطة، أو ضع مفتاح OPENAI_API_KEY). اكتب الأمر كتابة حالياً.');
            }
            if (blank($message['audio']['id'] ?? null)) {
                return Result::clarify('🎤 ما وصل الصوت كاملاً، أعد إرسال الرسالة الصوتية أو اكتبها.');
            }
            [$audio, $mime] = $this->gateway->downloadMedia((string) $message['audio']['id']);
            try {
                $text = $this->transcriber->transcribe($audio, $mime);
            } catch (\Throwable $e) {
                $record->update(['error' => 'تحويل الصوت: '.mb_substr($e->getMessage(), 0, 500)]);

                return Result::failed('🎤 تعذّر تحويل الرسالة الصوتية إلى نص الآن. أعد إرسالها بعد دقيقة أو اكتب الأمر.');
            }
            $record->update(['transcript' => $text]);
            if ($text === '') {
                return Result::clarify('ما فهمت الرسالة الصوتية، عيدها أو اكتبها.');
            }
        } else {
            $record->update(['body' => $text]);
        }
        if ($text === '') {
            return Result::clarify(CommandExecutor::help());
        }

        $previous = WhatsappMessage::where('from_phone', $record->from_phone)->where('id', '<', $record->id)
            ->where('status', 'clarify')->where('received_at', '>=', now()->subMinutes(self::FOLLOW_UP_MINUTES))
            ->latest('id')->first();
        // A previous unclear message that was already answered does not count again.
        if ($previous && WhatsappMessage::where('from_phone', $record->from_phone)->where('id', '>', $previous->id)->where('id', '<', $record->id)->exists()) {
            $previous = null;
        }

        $command = $this->complete($text, $previous) ?? $this->interpreter->interpret($text, $previous ? [
            'text' => (string) ($previous->transcript ?? $previous->body),
            'question' => (string) $previous->reply,
        ] : null) ?? new Command('unknown');
        $record->update(['intent' => $command->intent, 'command' => $command->toArray()]);

        return $this->asUser($user, function () use ($command, $user, $record, $text) {
            $result = $this->executor->execute($command, $user, $record->wa_message_id);
            $this->audit->log('whatsapp.command', $record, null, [
                'phone' => $record->from_phone,
                'command' => $text,
                'voice' => $record->transcript !== null,
                'intent' => $command->intent,
                'status' => $result->status,
                'changes' => $result->changes,
                'reply' => $result->reply,
            ], null, $this->subscriberOf($result));

            return $result;
        });
    }

    /**
     * Fills the one missing detail of the previous unclear command from a short answer such as
     * «سبع أيام» or «35 الف», without the AI.
     */
    private function complete(string $text, ?WhatsappMessage $previous): ?Command
    {
        $before = $previous?->command;
        if (! is_array($before) || empty($before['subscriber'])) {
            return null;
        }
        $command = Command::fromArray($before);
        if ($command->intent === 'activate' && $command->days === null && ($days = RuleInterpreter::days($text)) !== null) {
            $command->days = $days;

            return $command;
        }
        if (in_array($command->intent, ['payment', 'void_debt'], true) && $command->amount === null && ($amount = RuleInterpreter::amount($text)) !== null) {
            $command->amount = $amount;

            return $command;
        }

        return null;
    }

    private function asUser(User $user, callable $callback): mixed
    {
        $previous = Auth::user();
        Auth::setUser($user);
        try {
            // One transaction per command: a failure leaves no half-done change behind.
            return \Illuminate\Support\Facades\DB::transaction(fn () => $this->audit->withSource('whatsapp', $callback));
        } finally {
            $previous ? Auth::setUser($previous) : Auth::forgetUser();
        }
    }

    private function subscriberOf(Result $result): ?int
    {
        $accountId = $result->changes['account_id'] ?? null;

        return $accountId ? \App\Models\Account::whereKey($accountId)->value('subscriber_id') : null;
    }

    private function reply(WhatsappMessage $record, string $to, string $text): void
    {
        try {
            $this->gateway->send($to, $text);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp reply not sent', ['message' => $record->id, 'error' => $e->getMessage()]);
            $record->update(['error' => trim(($record->error ? $record->error."\n" : '').'الرد لم يُرسل: '.mb_substr($e->getMessage(), 0, 500))]);
        }
    }
}
