<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\CustomerAssignedPackage;
use App\Models\Merchant;
use App\Models\MerchantServicePackage;
use App\Models\User;
use Database\Seeders\BusinessTypeSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillingRecurringCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $staff;
    protected Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([BusinessTypeSeeder::class, PackageSeeder::class]);

        $this->owner = User::factory()->create(['name' => 'Owner Budi']);
        $this->staff = User::factory()->create(['name' => 'Staff Ani']);
        Sanctum::actingAs($this->owner);

        $billingType = BusinessType::where('code', 'billing')->first();
        $this->merchant = Merchant::create([
            'name' => 'PT Solusi Tagihan Utama',
            'slug' => 'solusi-tagihan-utama',
            'business_type_id' => $billingType->id,
            'trial_ends_at' => now()->addDays(14),
            'status' => 'active',
        ]);
        $this->merchant->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->merchant->users()->attach($this->staff->id, ['role' => 'staff']);
    }

    public function test_can_create_and_list_merchant_service_packages(): void
    {
        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->postJson('/api/v1/billing/packages', [
                'name' => 'Paket Maintenance Server',
                'price' => 1500000,
                'billing_period' => 'monthly',
                'description' => 'Monitoring & backup harian server klien',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Paket Maintenance Server');
        $response->assertJsonPath('data.price', 1500000);

        $this->assertDatabaseHas('merchant_service_packages', [
            'merchant_id' => $this->merchant->id,
            'name' => 'Paket Maintenance Server',
            'price' => 1500000,
        ]);
    }

    public function test_can_assign_package_to_customer_and_view_in_calendar(): void
    {
        $package = MerchantServicePackage::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Internet Dedicated 100Mbps',
            'price' => 2500000,
            'billing_period' => 'monthly',
        ]);

        $customer = Customer::create([
            'merchant_id' => $this->merchant->id,
            'created_by' => $this->owner->id,
            'name' => 'PT Pelita Bangsa',
            'phone' => '081234567890',
            'stage' => 'lead',
        ]);

        // Assign package to customer with billing cycle on day 20 and assign to staff Ani
        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->postJson("/api/v1/billing/customers/{$customer->id}/assign-package", [
                'package_id' => $package->id,
                'billing_cycle_day' => 20,
                'assigned_staff_id' => $this->staff->id,
                'price' => 2500000,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.billing_cycle_day', 20);
        $response->assertJsonPath('data.assigned_staff.name', 'Staff Ani');

        // Customer auto-promoted to customer
        $this->assertEquals('customer', $customer->fresh()->stage);

        // Check calendar endpoint for current month
        $calResponse = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/calendar');

        $calResponse->assertOk();
        $calResponse->assertJsonPath('data.metrics.total_assignments', 1);
        $calResponse->assertJsonPath('data.metrics.pending_count', 1);
        $calResponse->assertJsonPath('data.events.0.day', 20);
        $calResponse->assertJsonPath('data.events.0.schedule_status', 'pending');

        // Check staff Ani's tasks filter
        Sanctum::actingAs($this->staff);
        $staffCalResponse = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/calendar?my_tasks=true');

        $staffCalResponse->assertOk();
        $staffCalResponse->assertJsonPath('data.metrics.total_assignments', 1);
    }

    public function test_can_issue_invoice_directly_from_calendar_schedule(): void
    {
        $package = MerchantServicePackage::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Jasa Akuntansi Bulanan',
            'price' => 3000000,
        ]);

        $customer = Customer::create([
            'merchant_id' => $this->merchant->id,
            'created_by' => $this->owner->id,
            'name' => 'CV Mandiri Sejahtera',
            'stage' => 'customer',
        ]);

        $assignment = CustomerAssignedPackage::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'billing_cycle_day' => 10,
            'price' => 3000000,
            'status' => 'active',
            'start_date' => now()->toDateString(),
        ]);

        $dueDate = now()->setDay(10)->toDateString();

        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->postJson('/api/v1/billing/calendar/issue-invoice', [
                'assignment_id' => $assignment->id,
                'due_date' => $dueDate,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.amount', 3000000);
        $response->assertJsonPath('data.status', 'unpaid');

        // Check calendar now reflects 'invoiced'
        $calResponse = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/calendar');

        $calResponse->assertOk();
        $calResponse->assertJsonPath('data.events.0.schedule_status', 'invoiced');
    }

    public function test_can_toggle_merchant_service_package_status(): void
    {
        $package = MerchantServicePackage::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Paket Hosting Dedicated',
            'price' => 500000,
            'is_active' => true,
        ]);

        // Toggle to inactive
        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->patchJson("/api/v1/billing/packages/{$package->id}/toggle-status");

        $response->assertOk();
        $this->assertFalse($package->fresh()->is_active);

        // Toggle back to active
        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->patchJson("/api/v1/billing/packages/{$package->id}/toggle-status");

        $response->assertOk();
        $this->assertTrue($package->fresh()->is_active);
    }

    public function test_can_update_customer_assigned_package_status_and_pause_or_cancel(): void
    {
        $package = MerchantServicePackage::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'SEO Monitoring',
            'price' => 1200000,
        ]);

        $customer = Customer::create([
            'merchant_id' => $this->merchant->id,
            'created_by' => $this->owner->id,
            'name' => 'CV Digital Mitra',
            'stage' => 'customer',
        ]);

        $assignment = CustomerAssignedPackage::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'billing_cycle_day' => 15,
            'price' => 1200000,
            'status' => 'active',
            'start_date' => now()->toDateString(),
        ]);

        // Pause package
        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->patchJson("/api/v1/billing/customer-packages/{$assignment->id}/status", [
                'status' => 'paused',
            ]);

        $response->assertOk();
        $this->assertEquals('paused', $assignment->fresh()->status);

        // Inactive / paused assignments should NOT appear in calendar
        $calResponse = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/calendar');
        $calResponse->assertOk();
        $calResponse->assertJsonPath('data.metrics.total_assignments', 0);

        // Cancel / Deactivate package
        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->patchJson("/api/v1/billing/customer-packages/{$assignment->id}/status", [
                'status' => 'cancelled',
            ]);

        $response->assertOk();
        $this->assertEquals('cancelled', $assignment->fresh()->status);
    }
}
