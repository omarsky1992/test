<?php

namespace App\Services;

use App\Enums\FollowUpChannel;
use App\Enums\FollowUpOutcome;
use App\Models\Account;
use App\Models\Activation;
use App\Models\FollowUp;
use App\Models\Subscriber;
use App\Support\Arabic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class SubscriberService
{
    public function __construct(private Audit $audit)
    {
    }

    /**
     * @param  array<string, mixed>  $data  subscriber fields
     * @param  array<string, mixed>|null  $account  first account fields (username, secret, serial…)
     */
    public function create(array $data, ?array $account = null): Subscriber
    {
        return DB::transaction(function () use ($data, $account) {
            $branchId = $data['branch_id'] ?? Auth::user()?->branch_id;
            $subscriber = Subscriber::create([
                ...$this->subscriberFields($data),
                'branch_id' => $branchId,
                'code' => 'TMP-'.bin2hex(random_bytes(8)),
                'status' => $data['status'] ?? 'active',
                'created_by' => Auth::id(),
            ]);
            $subscriber->update(['code' => 'C-'.str_pad((string) $subscriber->id, 6, '0', STR_PAD_LEFT)]);
            $this->audit->log('subscriber.created', $subscriber, null, $subscriber->only(['code', 'full_name', 'phone']), subscriberId: $subscriber->id);

            if ($account !== null) {
                $this->createAccount($subscriber, $account);
            }

            return $subscriber;
        });
    }

    public function update(Subscriber $subscriber, array $data): Subscriber
    {
        $original = $subscriber->getAttributes();
        $subscriber->update([...$this->subscriberFields($data), 'status' => $data['status'] ?? $subscriber->status, 'updated_by' => Auth::id()]);
        $this->audit->changes('subscriber.updated', $subscriber, $original, subscriberId: $subscriber->id);

        return $subscriber;
    }

    public function createAccount(Subscriber $subscriber, array $data): Account
    {
        $account = Account::create([
            ...$this->accountFields($data),
            'subscriber_id' => $subscriber->id,
            'branch_id' => $subscriber->branch_id,
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);
        $this->audit->log('account.created', $account, null, $account->only(['username', 'serial_number', 'fat_code', 'pole_number']), subscriberId: $subscriber->id);

        return $account;
    }

    public function updateAccount(Account $account, array $data): Account
    {
        $original = $account->getAttributes();
        $fields = $this->accountFields($data);
        if (blank($data['secret'] ?? null)) {
            unset($fields['secret_encrypted']);
        }
        $account->update([...$fields, 'status' => $data['status'] ?? $account->status, 'updated_by' => Auth::id()]);
        $this->audit->changes('account.updated', $account, $original, subscriberId: $account->subscriber_id);

        return $account;
    }

    public function revealSecret(Account $account): ?string
    {
        $this->audit->log('account.secret_viewed', $account, subscriberId: $account->subscriber_id);

        return $account->secret_encrypted ? Crypt::decryptString($account->secret_encrypted) : null;
    }

    public function logFollowUp(Account $account, FollowUpChannel $channel, FollowUpOutcome $outcome, ?CarbonImmutable $promisedAt = null, ?CarbonImmutable $nextAt = null, ?string $note = null, ?Activation $activation = null): FollowUp
    {
        $followUp = FollowUp::create([
            'account_id' => $account->id,
            'subscriber_id' => $account->subscriber_id,
            'activation_id' => $activation?->id,
            'debt_id' => $activation?->debt?->id,
            'channel' => $channel,
            'outcome' => $outcome,
            'promised_at' => $promisedAt,
            'next_follow_up_at' => $nextAt,
            'note' => $note,
            'created_by' => Auth::id(),
        ]);
        $this->audit->log('follow_up.created', $followUp, null, ['outcome' => $outcome->value, 'channel' => $channel->value], subscriberId: $account->subscriber_id);

        return $followUp;
    }

    private function subscriberFields(array $data): array
    {
        return [
            'full_name' => trim($data['full_name']),
            'name_search' => Arabic::normalize($data['full_name']),
            'phone' => trim($data['phone']),
            'phone_normalized' => Arabic::phone($data['phone']),
            'alt_phone' => $data['alt_phone'] ?? null,
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function accountFields(array $data): array
    {
        return [
            'username' => trim($data['username']),
            'secret_encrypted' => filled($data['secret'] ?? null) ? Crypt::encryptString($data['secret']) : null,
            'serial_number' => filled($data['serial_number'] ?? null) ? strtoupper(trim($data['serial_number'])) : null,
            'phone' => $data['phone'] ?? null,
            'phone_normalized' => filled($data['phone'] ?? null) ? Arabic::phone($data['phone']) : null,
            'fat_code' => $data['fat_code'] ?? null,
            'pole_number' => $data['pole_number'] ?? null,
            'location_label' => $data['location_label'] ?? null,
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }
}
