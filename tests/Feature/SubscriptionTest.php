<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Database\Seeders\BusinessTypeSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([BusinessTypeSeeder::class, PackageSeeder::class]);
    }

    public function test_payment_cumulatively_extends_active_subscription(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $posType = BusinessType::where('code', 'pos')->first();
        $existingExpiry = now()->addDays(10);

        $merchant = Merchant::create([
            'name' => 'Resto Sedap',
            'slug' => 'resto-sedap',
            'business_type_id' => $posType->id,
            'trial_ends_at' => now()->subDay(),
            'subscription_expires_at' => $existingExpiry,
            'status' => 'active',
        ]);
        $merchant->users()->attach($user->id, ['role' => 'owner']);

        $package = Package::where('code', 'pos-monthly')->first(); // 30 days

        $order = Order::create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-TEST-001',
            'total_amount' => $package->price,
            'status' => 'pending',
        ]);

        $order->items()->create([
            'package_id' => $package->id,
            'merchant_id' => $merchant->id,
            'package_name' => $package->name,
            'duration_in_days' => 30,
            'price' => $package->price,
        ]);

        $response = $this->postJson("/api/v1/orders/{$order->id}/pay");

        $response->assertOk()
            ->assertJsonPath('success', true);

        $merchant->refresh();

        // 10 days remaining + 30 days = 40 days from now
        $this->assertEquals(40, (int) round(now()->diffInDays($merchant->subscription_expires_at)));
        $this->assertEquals('paid', $order->fresh()->status);
    }

    public function test_merchant_dashboard_accessible_with_active_access_and_header(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $posType = BusinessType::where('code', 'pos')->first();
        $merchant = Merchant::create([
            'name' => 'Mart Berjaya',
            'slug' => 'mart-berjaya',
            'business_type_id' => $posType->id,
            'trial_ends_at' => now()->addDays(7),
            'status' => 'active',
        ]);
        $merchant->users()->attach($user->id, ['role' => 'owner']);

        $response = $this->withHeader('X-Merchant-Id', (string) $merchant->id)
            ->getJson('/api/v1/merchant/dashboard');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.merchant.id', $merchant->id)
            ->assertJsonPath('data.merchant.user_role', 'owner');
    }

    public function test_merchant_dashboard_blocked_when_subscription_and_trial_expired(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $posType = BusinessType::where('code', 'pos')->first();
        $merchant = Merchant::create([
            'name' => 'Mart Habis Masa Aktif',
            'slug' => 'mart-habis',
            'business_type_id' => $posType->id,
            'trial_ends_at' => now()->subDays(5),
            'subscription_expires_at' => now()->subDays(2),
            'status' => 'active',
        ]);
        $merchant->users()->attach($user->id, ['role' => 'owner']);

        $response = $this->withHeader('X-Merchant-Id', (string) $merchant->id)
            ->getJson('/api/v1/merchant/dashboard');

        $response->assertStatus(402)
            ->assertJsonPath('success', false);
    }
}
