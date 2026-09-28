<?php

namespace Tests\Feature;

use App\Models\BillingInvoice;
use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\User;
use Database\Seeders\BusinessTypeSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerBillingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([BusinessTypeSeeder::class, PackageSeeder::class]);

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $billingType = BusinessType::where('code', 'billing')->first();
        $this->merchant = Merchant::create([
            'name' => 'Konsultan Maju Bersama',
            'slug' => 'konsultan-maju-bersama',
            'business_type_id' => $billingType->id,
            'trial_ends_at' => now()->addDays(14),
            'status' => 'active',
        ]);
        $this->merchant->users()->attach($this->user->id, ['role' => 'owner']);
    }

    public function test_can_create_customer_lead_and_track_creator(): void
    {
        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->postJson('/api/v1/billing/customers', [
                'name' => 'PT Surya Digital',
                'company_name' => 'Surya Group',
                'email' => 'sales@suryadigital.com',
                'phone' => '081234567890',
                'stage' => 'lead',
                'source' => 'Website',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'PT Surya Digital');
        $response->assertJsonPath('data.stage', 'lead');
        $response->assertJsonPath('data.creator.name', $this->user->name);

        $this->assertDatabaseHas('customers', [
            'merchant_id' => $this->merchant->id,
            'created_by' => $this->user->id,
            'name' => 'PT Surya Digital',
            'stage' => 'lead',
        ]);
    }

    public function test_invoice_creation_auto_promotes_lead_to_customer(): void
    {
        // 1. Create a Lead
        $lead = Customer::create([
            'merchant_id' => $this->merchant->id,
            'created_by' => $this->user->id,
            'name' => 'CV Mega Bintang',
            'email' => 'info@megabintang.id',
            'phone' => '085712345678',
            'stage' => 'lead',
        ]);

        $this->assertEquals('lead', $lead->stage);

        // 2. Issue Invoice for this Lead
        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->postJson('/api/v1/billing/invoices', [
                'customer_id' => $lead->id,
                'client_name' => $lead->name,
                'description' => 'Jasa Setup Server Cloud',
                'amount' => 3500000,
                'due_date' => now()->addDays(7)->toDateString(),
            ]);

        $response->assertCreated();

        // 3. Lead must now be promoted to active Customer
        $lead->refresh();
        $this->assertEquals('customer', $lead->stage);

        // 4. Invoice is linked to customer
        $this->assertDatabaseHas('billing_invoices', [
            'merchant_id' => $this->merchant->id,
            'customer_id' => $lead->id,
            'amount' => 3500000,
        ]);
    }

    public function test_can_send_reminder_with_whatsapp_url(): void
    {
        $customer = Customer::create([
            'merchant_id' => $this->merchant->id,
            'created_by' => $this->user->id,
            'name' => 'Ahmad Fauzi',
            'phone' => '081234567890',
            'stage' => 'customer',
        ]);

        $invoice = BillingInvoice::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-2026-001',
            'client_name' => $customer->name,
            'amount' => 2000000,
            'due_date' => now()->addDays(3)->toDateString(),
            'status' => 'unpaid',
        ]);

        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->postJson("/api/v1/billing/invoices/{$invoice->id}/send-reminder");

        $response->assertOk();
        $response->assertJsonPath('data.phone', '081234567890');
        $this->assertStringContainsString('wa.me/6281234567890', $response->json('data.whatsapp_url'));
        $this->assertStringContainsString('INV-2026-001', $response->json('data.reminder_message'));
    }

    public function test_can_update_customer_stage_manually(): void
    {
        $customer = Customer::create([
            'merchant_id' => $this->merchant->id,
            'created_by' => $this->user->id,
            'name' => 'Mitra Sukses',
            'stage' => 'lead',
        ]);

        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->patchJson("/api/v1/billing/customers/{$customer->id}/stage", [
                'stage' => 'prospect',
            ]);

        $response->assertOk();
        $this->assertEquals('prospect', $customer->fresh()->stage);
    }
}
