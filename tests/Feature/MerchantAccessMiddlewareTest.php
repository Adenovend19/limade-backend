<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Merchant;
use App\Models\User;
use Database\Seeders\BusinessTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantAccessMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BusinessTypeSeeder::class);
    }

    public function test_fails_when_x_merchant_id_header_is_missing(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/merchant/dashboard');

        $response->assertStatus(400)
            ->assertJsonPath('success', false);
    }

    public function test_fails_when_user_is_not_member_of_merchant(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $posType = BusinessType::where('code', 'pos')->first();
        $merchant = Merchant::create([
            'name' => 'Resto Rahasia',
            'slug' => 'resto-rahasia',
            'business_type_id' => $posType->id,
            'trial_ends_at' => now()->addDays(14),
            'status' => 'active',
        ]);
        $merchant->users()->attach($owner->id, ['role' => 'owner']);

        Sanctum::actingAs($otherUser);

        $response = $this->withHeader('X-Merchant-Id', (string) $merchant->id)
            ->getJson('/api/v1/merchant/dashboard');

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
