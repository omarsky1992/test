<?php

namespace Tests\Feature;

use App\Filament\Resources\Subscribers\Pages\ListSubscribers;
use Livewire\Livewire;
use Tests\TestCase;

class SubscribersByFatTest extends TestCase
{
    public function test_the_subscribers_on_a_fat_are_listed_with_their_ports(): void
    {
        $a = $this->account('علي كريم');
        $a->update(['fat_code' => 'FAT33', 'port_number' => '10', 'zone_code' => 'FBG0876-2']);
        $b = $this->account('حسن جبار');
        $b->update(['fat_code' => 'FAT33', 'port_number' => '2', 'zone_code' => 'FBG0876-2']);
        $c = $this->account('زيد علي');
        $c->update(['fat_code' => 'FAT12', 'port_number' => '5', 'zone_code' => 'FBG0900-1']);

        Livewire::test(ListSubscribers::class)
            ->assertSee('FAT33 · بورت 10')->assertSee('FAT12 · بورت 5')
            ->filterTable('fat', ['FAT33'])
            ->assertCanSeeTableRecords([$a->subscriber, $b->subscriber])
            ->assertCanNotSeeTableRecords([$c->subscriber])
            // Ordered by port within the FAT: 2 before 10.
            ->sortTable('fat_port')
            ->assertCanSeeTableRecords([$b->subscriber, $a->subscriber], inOrder: true);

        Livewire::test(ListSubscribers::class)->filterTable('zone', ['FBG0900-1'])
            ->assertCanSeeTableRecords([$c->subscriber])->assertCanNotSeeTableRecords([$a->subscriber, $b->subscriber]);
    }
}
