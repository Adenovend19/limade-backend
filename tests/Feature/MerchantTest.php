<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Merchant;
use App\Models\User;
use Database\Seeders\BusinessTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessTypeSeeder::class);
    }

    public function test_user_can_create_merchant_with_14_day_trial_and_owner_role(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $posType = BusinessType::where('code', 'pos')->first();

        $response = $this->postJson('/api/v1/merchants', [
            'name' => 'Toko Sejahtera',
            'business_type_id' => $posType->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Toko Sejahtera')
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.has_active_access', true);

        $merchant = Merchant::first();
        $this->assertNotNull($merchant->trial_ends_at);
        $this->assertTrue($merchant->trial_ends_at->isFuture());
        $this->assertEquals('owner', $user->merchants()->first()->pivot->role);
    }

    public function test_user_can_list_only_their_merchants(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $posType = BusinessType::where('code', 'pos')->first();

        $merchant1 = Merchant::create([
            'name' => 'Merchant User 1',
            'slug' => 'merchant-user-1',
            'business_type_id' => $posType->id,
            'status' => 'active',
        ]);
        $merchant1->users()->attach($user1->id, ['role' => 'owner']);

        $merchant2 = Merchant::create([
            'name' => 'Merchant User 2',
            'slug' => 'merchant-user-2',
            'business_type_id' => $posType->id,
            'status' => 'active',
        ]);
        $merchant2->users()->attach($user2->id, ['role' => 'owner']);

        Sanctum::actingAs($user1);

        $response = $this->getJson('/api/v1/merchants');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $merchant1->id);
    }

    public function test_merchant_owner_can_add_staff_member(): void
    {
        $owner = User::factory()->create();
        $staffUser = User::factory()->create(['email' => 'kasir@example.com']);

        $posType = BusinessType::where('code', 'pos')->first();
        $merchant = Merchant::create([
            'name' => 'Resto Berkah',
            'slug' => 'resto-berkah',
            'business_type_id' => $posType->id,
            'status' => 'active',
        ]);
        $merchant->users()->attach($owner->id, ['role' => 'owner']);

        Sanctum::actingAs($owner);

        $response = $this->postJson("/api/v1/merchants/{$merchant->id}/members", [
            'email' => 'kasir@example.com',
            'role' => 'staff',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertEquals(
            'staff',
            $staffUser->merchants()->where('merchants.id', $merchant->id)->first()->pivot->role
        );
    }
}
