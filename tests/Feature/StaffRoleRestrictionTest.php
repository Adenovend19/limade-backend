<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\CustomerAssignedPackage;
use App\Models\CustomerVisit;
use App\Models\Merchant;
use App\Models\MerchantServicePackage;
use App\Models\User;
use Database\Seeders\BusinessTypeSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffRoleRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $staffAni;
    protected User $staffBudi;
    protected Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([BusinessTypeSeeder::class, PackageSeeder::class]);

        $this->owner = User::factory()->create(['name' => 'Owner Store']);
        $this->staffAni = User::factory()->create(['name' => 'Staff Ani']);
        $this->staffBudi = User::factory()->create(['name' => 'Staff Budi']);

        $billingType = BusinessType::where('code', 'billing')->first();
        $this->merchant = Merchant::create([
            'name' => 'PT Billing Mandiri',
            'slug' => 'billing-mandiri',
            'business_type_id' => $billingType->id,
            'trial_ends_at' => now()->addDays(14),
            'status' => 'active',
        ]);

        $this->merchant->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->merchant->users()->attach($this->staffAni->id, ['role' => 'staff']);
        $this->merchant->users()->attach($this->staffBudi->id, ['role' => 'staff']);
    }

    public function test_staff_only_views_their_invoices(): void
    {
        $pkg = MerchantServicePackage::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Wifi 50Mbps',
            'price' => 300000,
        ]);

        // Customer assigned to Ani
        $custAni = Customer::create([
            'merchant_id' => $this->merchant->id,
            'created_by' => $this->staffAni->id,
            'name' => 'Pelanggan Ani',
        ]);
        CustomerAssignedPackage::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $custAni->id,
            'package_id' => $pkg->id,
            'billing_cycle_day' => 10,
            'assigned_staff_id' => $this->staffAni->id,
            'price' => 300000,
            'status' => 'active',
            'start_date' => now()->toDateString(),
        ]);
        $invAni = BillingInvoice::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $custAni->id,
            'created_by' => $this->staffAni->id,
            'invoice_number' => 'INV-2026-001',
            'client_name' => 'Pelanggan Ani',
            'amount' => 300000,
            'due_date' => now()->toDateString(),
            'status' => 'unpaid',
        ]);

        // Customer assigned to Budi
        $custBudi = Customer::create([
            'merchant_id' => $this->merchant->id,
            'created_by' => $this->staffBudi->id,
            'name' => 'Pelanggan Budi',
        ]);
        CustomerAssignedPackage::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $custBudi->id,
            'package_id' => $pkg->id,
            'billing_cycle_day' => 15,
            'assigned_staff_id' => $this->staffBudi->id,
            'price' => 300000,
            'status' => 'active',
            'start_date' => now()->toDateString(),
        ]);
        $invBudi = BillingInvoice::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $custBudi->id,
            'created_by' => $this->staffBudi->id,
            'invoice_number' => 'INV-2026-002',
            'client_name' => 'Pelanggan Budi',
            'amount' => 300000,
            'due_date' => now()->toDateString(),
            'status' => 'unpaid',
        ]);

        // When staff Ani queries invoices -> should ONLY see invAni
        Sanctum::actingAs($this->staffAni);
        $resAni = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/invoices');

        $resAni->assertOk();
        $resAni->assertJsonCount(1, 'data.invoices');
        $resAni->assertJsonPath('data.invoices.0.invoice_number', 'INV-2026-001');
        $resAni->assertJsonPath('data.metrics.total_invoiced', 300000);

        // When staff Ani queries customers -> should ONLY see custAni
        $custResAni = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/customers');
        $custResAni->assertOk();
        $custResAni->assertJsonCount(1, 'data.customers');
        $custResAni->assertJsonPath('data.customers.0.name', 'Pelanggan Ani');
        $custResAni->assertJsonPath('data.metrics.total', 1);

        // When Owner queries invoices -> should see BOTH invoices
        Sanctum::actingAs($this->owner);
        $resOwner = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/invoices');

        $resOwner->assertOk();
        $resOwner->assertJsonCount(2, 'data.invoices');
        $resOwner->assertJsonPath('data.metrics.total_invoiced', 600000);

        // When Owner queries customers -> should see BOTH customers
        $custResOwner = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/customers');
        $custResOwner->assertOk();
        $custResOwner->assertJsonCount(2, 'data.customers');
        $custResOwner->assertJsonPath('data.metrics.total', 2);
    }

    public function test_staff_calendar_is_strictly_scoped_to_assigned_tasks(): void
    {
        $pkg = MerchantServicePackage::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'CCTV Monitoring',
            'price' => 500000,
        ]);

        $custAni = Customer::create([
            'merchant_id' => $this->merchant->id,
            'created_by' => $this->staffAni->id,
            'name' => 'Pelanggan Ani',
        ]);
        CustomerAssignedPackage::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $custAni->id,
            'package_id' => $pkg->id,
            'billing_cycle_day' => 12,
            'assigned_staff_id' => $this->staffAni->id,
            'price' => 500000,
            'status' => 'active',
            'start_date' => now()->toDateString(),
        ]);

        $custBudi = Customer::create([
            'merchant_id' => $this->merchant->id,
            'created_by' => $this->staffBudi->id,
            'name' => 'Pelanggan Budi',
        ]);
        CustomerAssignedPackage::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $custBudi->id,
            'package_id' => $pkg->id,
            'billing_cycle_day' => 18,
            'assigned_staff_id' => $this->staffBudi->id,
            'price' => 500000,
            'status' => 'active',
            'start_date' => now()->toDateString(),
        ]);

        // When staff Ani accesses calendar without parameters -> strictly Ani only
        Sanctum::actingAs($this->staffAni);
        $resAni = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/calendar');

        $resAni->assertOk();
        $resAni->assertJsonPath('data.metrics.total_assignments', 1);
        $resAni->assertJsonPath('data.events.0.customer.name', 'Pelanggan Ani');
        // Ani cannot see Budi in staff list
        $resAni->assertJsonCount(1, 'data.staff_members');
        $resAni->assertJsonPath('data.staff_members.0.name', 'Staff Ani');

        // Owner sees both
        Sanctum::actingAs($this->owner);
        $resOwner = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/calendar');

        $resOwner->assertOk();
        $resOwner->assertJsonPath('data.metrics.total_assignments', 2);
    }

    public function test_staff_cannot_create_or_modify_service_packages(): void
    {
        Sanctum::actingAs($this->staffAni);

        // Staff tries to create a package -> 403 Forbidden
        $createRes = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->postJson('/api/v1/billing/packages', [
                'name' => 'Paket Ilegal',
                'price' => 100000,
            ]);
        $createRes->assertForbidden();

        // Owner creates a package
        Sanctum::actingAs($this->owner);
        $pkg = MerchantServicePackage::create([
            'merchant_id' => $this->merchant->id,
            'name' => 'Paket Resmi',
            'price' => 200000,
            'is_active' => true,
        ]);

        // Staff tries to toggle status -> 403 Forbidden
        Sanctum::actingAs($this->staffAni);
        $toggleRes = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->patchJson("/api/v1/billing/packages/{$pkg->id}/toggle-status");
        $toggleRes->assertForbidden();

        // Staff tries to delete -> 403 Forbidden
        $deleteRes = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->deleteJson("/api/v1/billing/packages/{$pkg->id}");
        $deleteRes->assertForbidden();
    }

    public function test_billing_admin_has_full_manager_access(): void
    {
        $billingAdmin = User::factory()->create(['name' => 'Admin Billing Toko']);
        $this->merchant->users()->attach($billingAdmin->id, ['role' => 'billing_admin']);

        Sanctum::actingAs($billingAdmin);

        // Can create package
        $createRes = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->postJson('/api/v1/billing/packages', [
                'name' => 'Paket Dari Billing Admin',
                'price' => 500000,
            ]);
        $createRes->assertCreated();

        // Can view all invoices
        $invRes = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/invoices');
        $invRes->assertOk();

        // Can view all customers
        $custRes = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/customers');
        $custRes->assertOk();

        // Can view store-wide calendar
        $calRes = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/calendar');
        $calRes->assertOk();
    }
}
