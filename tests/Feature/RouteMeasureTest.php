<?php

namespace Tests\Feature;

use App\Filament\Pages\RouteMeasure;
use App\Models\User;
use Tests\TestCase;

/** «قياس المسار»: a field tool, gated by its permission; it touches no data. */
class RouteMeasureTest extends TestCase
{
    public function test_a_user_with_the_permission_opens_the_tool(): void
    {
        $user = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $user->givePermissionTo('routes.measure');
        $this->actingAs($user);

        $this->assertTrue(RouteMeasure::canAccess());
        $this->get(RouteMeasure::getUrl())->assertOk()->assertSee('قياس المسار')->assertSee('نقطة دقيقة من موقعي')->assertSee('تتبّع المسار مشياً')->assertSee('subsGeoDistance', false);
    }

    public function test_without_the_permission_it_is_closed(): void
    {
        $user = User::factory()->create(['branch_id' => $this->admin->branch_id]);
        $this->actingAs($user);

        $this->assertFalse(RouteMeasure::canAccess());
        $this->get(RouteMeasure::getUrl())->assertForbidden();
    }
}
