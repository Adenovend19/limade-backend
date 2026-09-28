<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;
    private User $regularUser;
    private BusinessType $businessType;
    private Merchant $merchant;
    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::factory()->create([
            'email' => 'admin@limade.com',
            'is_super_admin' => true,
        ]);

        $this->regularUser = User::factory()->create([
            'email' => 'storeowner@example.com',
            'is_super_admin' => false,
        ]);

        $this->businessType = BusinessType::create([
            'name' => 'ISP & RT RW Net',
            'code' => 'ISP',
            'slug' => 'isp-billing',
            'description' => 'Billing management',
        ]);

        $this->merchant = Merchant::create([
            'business_type_id' => $this->businessType->id,
            'name' => 'Berkah Net',
            'slug' => 'berkah-net',
            'status' => 'pending_payment',
            'trial_ends_at' => now()->subDay(),
            'subscription_expires_at' => null,
        ]);
        $this->merchant->users()->attach($this->regularUser->id, ['role' => 'owner']);

        $this->package = Package::create([
            'business_type_id' => $this->businessType->id,
            'name' => 'Pro ISP Monthly',
            'code' => 'PRO-ISP-M',
            'price' => 150000,
            'duration_in_days' => 30,
            'description' => '30 days subscription',
            'features' => ['billing', 'calendar', 'visits'],
            'is_active' => true,
        ]);
    }

    public function test_merchant_creation_with_package_creates_pending_order_and_pending_payment_status(): void
    {
        $newUser = User::factory()->create();

        $response = $this->actingAs($newUser)
            ->postJson('/api/v1/merchants', [
                'name' => 'Waroeng Kopi Baru',
                'business_type_id' => $this->businessType->id,
                'package_id' => $this->package->id,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.merchant.name', 'Waroeng Kopi Baru')
            ->assertJsonPath('data.merchant.status', 'pending_payment')
            ->assertJsonPath('data.merchant.has_active_access', false)
            ->assertJsonPath('data.order.status', 'pending');

        $this->assertDatabaseHas('orders', [
            'user_id' => $newUser->id,
            'total_amount' => 150000,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('order_items', [
            'package_id' => $this->package->id,
            'price' => 150000,
            'duration_in_days' => 30,
        ]);
    }

    public function test_non_super_admin_is_forbidden_from_admin_portal(): void
    {
        $response = $this->actingAs($this->regularUser)
            ->getJson('/api/v1/admin/dashboard');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_regular_user_cannot_simulate_instant_payment_directly(): void
    {
        $order = Order::create([
            'user_id' => $this->regularUser->id,
            'invoice_number' => 'INV-TEST-FORBID',
            'total_amount' => 150000,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->regularUser)
            ->postJson("/api/v1/orders/{$order->id}/pay");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
            ]);
    }

    public function test_super_admin_can_access_dashboard_metrics(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'stats' => [
                        'total_revenue',
                        'waiting_approval_count',
                        'pending_orders_count',
                        'total_merchants',
                        'active_merchants',
                        'expired_merchants',
                        'total_users',
                        'total_orders',
                    ],
                    'recent_orders',
                    'recent_merchants',
                ],
            ]);
    }

    public function test_merchant_can_confirm_payment_and_submit_for_approval(): void
    {
        $order = Order::create([
            'user_id' => $this->regularUser->id,
            'invoice_number' => 'INV-TEST-001',
            'total_amount' => 150000,
            'status' => 'pending',
        ]);
        $order->items()->create([
            'package_id' => $this->package->id,
            'merchant_id' => $this->merchant->id,
            'package_name' => $this->package->name,
            'duration_in_days' => 30,
            'price' => 150000,
        ]);

        $response = $this->actingAs($this->regularUser)
            ->postJson("/api/v1/orders/{$order->id}/confirm-payment", [
                'payment_method' => 'bank_transfer',
                'payment_proof' => 'https://example.com/proof.jpg',
                'payment_notes' => 'Transfer dari BCA a/n Budi',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'waiting_approval')
            ->assertJsonPath('data.payment_method', 'bank_transfer')
            ->assertJsonPath('data.payment_notes', 'Transfer dari BCA a/n Budi');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'waiting_approval',
            'payment_method' => 'bank_transfer',
        ]);
    }

    public function test_super_admin_can_approve_order_and_activate_merchant(): void
    {
        $order = Order::create([
            'user_id' => $this->regularUser->id,
            'invoice_number' => 'INV-TEST-002',
            'total_amount' => 150000,
            'status' => 'waiting_approval',
            'payment_method' => 'qris',
            'payment_proof' => 'https://example.com/qris-paid.png',
        ]);
        $order->items()->create([
            'package_id' => $this->package->id,
            'merchant_id' => $this->merchant->id,
            'package_name' => $this->package->name,
            'duration_in_days' => 30,
            'price' => 150000,
        ]);

        $this->assertFalse($this->merchant->fresh()->hasActiveAccess());

        $response = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/orders/{$order->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order.status', 'paid')
            ->assertJsonPath('data.order.approver.id', $this->superAdmin->id);

        $order->refresh();
        $this->assertEquals('paid', $order->status);
        $this->assertEquals($this->superAdmin->id, $order->approved_by);
        $this->assertNotNull($order->approved_at);
        $this->assertNotNull($order->paid_at);

        // Merchant is now active with +30 days
        $merchant = $this->merchant->fresh();
        $this->assertEquals('active', $merchant->status);
        $this->assertNotNull($merchant->subscription_expires_at);
        $this->assertTrue($merchant->hasActiveAccess());
    }

    public function test_super_admin_can_reject_order_with_reason(): void
    {
        $order = Order::create([
            'user_id' => $this->regularUser->id,
            'invoice_number' => 'INV-TEST-003',
            'total_amount' => 150000,
            'status' => 'waiting_approval',
        ]);

        $response = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/orders/{$order->id}/reject", [
                'reason' => 'Bukti transfer buram dan nominal tidak sesuai.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'Bukti transfer buram dan nominal tidak sesuai.');

        $order->refresh();
        $this->assertEquals('rejected', $order->status);
        $this->assertEquals('Bukti transfer buram dan nominal tidak sesuai.', $order->rejection_reason);
    }

    public function test_super_admin_can_manually_extend_merchant_subscription(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->postJson("/api/v1/admin/merchants/{$this->merchant->id}/extend", [
                'days' => 14,
                'reason' => 'Bonus trial kemitraan',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->merchant->refresh();
        $this->assertEquals('active', $this->merchant->status);
        $this->assertNotNull($this->merchant->subscription_expires_at);
        $this->assertTrue($this->merchant->hasActiveAccess());
    }

    public function test_super_admin_can_manage_packages(): void
    {
        $createRes = $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/admin/packages', [
                'business_type_id' => $this->businessType->id,
                'name' => 'Enterprise ISP Year',
                'code' => 'ENT-ISP-Y',
                'price' => 1500000,
                'duration_in_days' => 365,
                'description' => '1 Year Enterprise Subscription',
                'features' => ['all_features'],
                'is_active' => true,
            ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.code', 'ENT-ISP-Y');

        $packageId = $createRes->json('data.id');

        $updateRes = $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/admin/packages/{$packageId}", [
                'business_type_id' => $this->businessType->id,
                'name' => 'Enterprise ISP Year Updated',
                'code' => 'ENT-ISP-Y',
                'price' => 1400000,
                'duration_in_days' => 365,
                'is_active' => true,
            ]);

        $updateRes->assertStatus(200);
        $this->assertEquals(1400000, (float) $updateRes->json('data.price'));
    }
}
