<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Merchant;
use App\Models\Package;
use App\Models\User;
use Database\Seeders\BusinessTypeSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartAndCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([BusinessTypeSeeder::class, PackageSeeder::class]);
    }

    public function test_user_can_add_compatible_package_to_cart(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $posType = BusinessType::where('code', 'pos')->first();
        $merchant = Merchant::create([
            'name' => 'Kopi POS',
            'slug' => 'kopi-pos',
            'business_type_id' => $posType->id,
            'status' => 'active',
        ]);
        $merchant->users()->attach($user->id, ['role' => 'owner']);

        $posPackage = Package::where('code', 'pos-monthly')->first();

        $response = $this->postJson('/api/v1/cart/items', [
            'package_id' => $posPackage->id,
            'merchant_id' => $merchant->id,
            'quantity' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items_count', 1);

        $this->assertEquals(50000, (float) $response->json('data.total_amount'));
    }

    public function test_user_cannot_add_incompatible_package_to_merchant(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $posType = BusinessType::where('code', 'pos')->first();
        $merchant = Merchant::create([
            'name' => 'Kopi POS',
            'slug' => 'kopi-pos',
            'business_type_id' => $posType->id,
            'status' => 'active',
        ]);
        $merchant->users()->attach($user->id, ['role' => 'owner']);

        // Try adding billing package to POS merchant
        $billingPackage = Package::where('code', 'billing-monthly')->first();

        $response = $this->postJson('/api/v1/cart/items', [
            'package_id' => $billingPackage->id,
            'merchant_id' => $merchant->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_user_can_checkout_cart_and_order_is_created(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $posType = BusinessType::where('code', 'pos')->first();
        $merchant = Merchant::create([
            'name' => 'Toko Kelontong',
            'slug' => 'toko-kelontong',
            'business_type_id' => $posType->id,
            'status' => 'active',
        ]);
        $merchant->users()->attach($user->id, ['role' => 'owner']);

        $posPackage = Package::where('code', 'pos-yearly')->first();

        // Add to cart
        $this->postJson('/api/v1/cart/items', [
            'package_id' => $posPackage->id,
            'merchant_id' => $merchant->id,
            'quantity' => 1,
        ]);

        // Checkout
        $response = $this->postJson('/api/v1/checkout');

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order.status', 'pending');

        $this->assertEquals(500000, (float) $response->json('data.order.total_amount'));

        // Cart should now be empty
        $cartCheck = $this->getJson('/api/v1/cart');
        $cartCheck->assertOk()
            ->assertJsonPath('data.items_count', 0);
    }
}
