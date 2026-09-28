<?php

namespace Tests\Feature;

use App\Models\BusinessType;
use App\Models\Customer;
use App\Models\CustomerVisit;
use App\Models\Merchant;
use App\Models\User;
use Database\Seeders\BusinessTypeSeeder;
use Database\Seeders\PackageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerVisitTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $staff;
    protected Merchant $merchant;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([BusinessTypeSeeder::class, PackageSeeder::class]);

        $this->owner = User::factory()->create(['name' => 'Owner Budi']);
        $this->staff = User::factory()->create(['name' => 'Staff Lapangan Andi']);
        Sanctum::actingAs($this->owner);

        $billingType = BusinessType::where('code', 'billing')->first();
        $this->merchant = Merchant::create([
            'name' => 'PT Mitra Billing Sukses',
            'slug' => 'mitra-billing-sukses',
            'business_type_id' => $billingType->id,
            'trial_ends_at' => now()->addDays(14),
            'status' => 'active',
        ]);
        $this->merchant->users()->attach($this->owner->id, ['role' => 'owner']);
        $this->merchant->users()->attach($this->staff->id, ['role' => 'staff']);

        $this->customer = Customer::create([
            'merchant_id' => $this->merchant->id,
            'created_by' => $this->owner->id,
            'name' => 'PT Makmur Gemilang',
            'phone' => '081298765432',
            'address' => 'Jl. Sudirman No. 45, Jakarta Pusat',
            'stage' => 'customer',
        ]);
    }

    public function test_can_schedule_and_list_customer_visits(): void
    {
        $scheduledAt = now()->addDays(2)->setHour(14)->setMinute(0)->toDateTimeString();

        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->postJson('/api/v1/billing/visits', [
                'customer_id' => $this->customer->id,
                'title' => 'Penagihan Faktur Cash & Konfirmasi Kontrak',
                'visit_type' => 'collection',
                'scheduled_at' => $scheduledAt,
                'assigned_staff_id' => $this->staff->id,
                'notes' => 'Bawa kuitansi fisik asli dan temui Bu Santi Bagian Keuangan',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Penagihan Faktur Cash & Konfirmasi Kontrak');
        $response->assertJsonPath('data.visit_type', 'collection');
        $response->assertJsonPath('data.status', 'scheduled');
        $response->assertJsonPath('data.assigned_staff.name', 'Staff Lapangan Andi');

        // Check index list
        $listResponse = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/visits');

        $listResponse->assertOk();
        $listResponse->assertJsonPath('data.metrics.total', 1);
        $listResponse->assertJsonPath('data.metrics.scheduled', 1);
    }

    public function test_customer_visit_appears_in_billing_calendar_with_filters(): void
    {
        // Visit on 15th of current month
        $visitDate = now()->setDay(15)->setHour(10)->setMinute(30)->toDateTimeString();

        CustomerVisit::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'assigned_staff_id' => $this->staff->id,
            'created_by' => $this->owner->id,
            'title' => 'Survei Penambahan Bandwidth',
            'visit_type' => 'survey_installation',
            'scheduled_at' => $visitDate,
            'status' => 'scheduled',
        ]);

        // Query calendar for current month
        $calResponse = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/calendar');

        $calResponse->assertOk();
        $calResponse->assertJsonPath('data.metrics.total_visits', 1);
        $calResponse->assertJsonPath('data.metrics.scheduled_visits', 1);

        $events = $calResponse->json('data.events');
        $visitEvent = collect($events)->firstWhere('item_type', 'visit');
        $this->assertNotNull($visitEvent);
        $this->assertEquals(15, $visitEvent['day']);
        $this->assertEquals('survey_installation', $visitEvent['visit_type']);

        // Check staff Andi filter
        Sanctum::actingAs($this->staff);
        $staffCalResponse = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->getJson('/api/v1/billing/calendar?my_tasks=true');

        $staffCalResponse->assertOk();
        $staffCalResponse->assertJsonPath('data.metrics.total_visits', 1);
    }

    public function test_can_complete_visit_with_report_notes(): void
    {
        $visit = CustomerVisit::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'assigned_staff_id' => $this->staff->id,
            'created_by' => $this->owner->id,
            'title' => 'Pertemuan Negosiasi Paket Enterprise',
            'visit_type' => 'negotiation',
            'scheduled_at' => now(),
            'status' => 'scheduled',
        ]);

        $response = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->patchJson("/api/v1/billing/visits/{$visit->id}/status", [
                'status' => 'completed',
                'result_notes' => 'Klien sepakat upgrade paket ke 200Mbps, invoice baru akan diproses besok.',
            ]);

        $response->assertOk();
        $this->assertEquals('completed', $visit->fresh()->status);
        $this->assertNotNull($visit->fresh()->completed_at);
        $this->assertStringContainsString('upgrade paket', $visit->fresh()->result_notes);
    }

    public function test_can_reschedule_and_cancel_visit(): void
    {
        $visit = CustomerVisit::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'assigned_staff_id' => $this->staff->id,
            'created_by' => $this->owner->id,
            'title' => 'Maintenance Rutin Router',
            'visit_type' => 'maintenance',
            'scheduled_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);

        $newDate = now()->addDays(5)->toDateTimeString();

        // Reschedule
        $res = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->patchJson("/api/v1/billing/visits/{$visit->id}/status", [
                'status' => 'rescheduled',
                'new_scheduled_at' => $newDate,
                'result_notes' => 'Customer meminta jadwal diundur karena sedang keluar kota.',
            ]);

        $res->assertOk();
        $this->assertEquals('rescheduled', $visit->fresh()->status);

        // Cancel
        $cancelRes = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->patchJson("/api/v1/billing/visits/{$visit->id}/status", [
                'status' => 'cancelled',
                'result_notes' => 'Dibatalkan karena kantor customer tutup sementara.',
            ]);

        $cancelRes->assertOk();
        $this->assertEquals('cancelled', $visit->fresh()->status);
    }

    public function test_can_start_journey_checkin_and_calculate_duration_on_completion(): void
    {
        $visit = CustomerVisit::create([
            'merchant_id' => $this->merchant->id,
            'customer_id' => $this->customer->id,
            'assigned_staff_id' => $this->staff->id,
            'created_by' => $this->owner->id,
            'title' => 'Kunjungan Lapangan Teknisi',
            'visit_type' => 'survey_installation',
            'scheduled_at' => now(),
            'status' => 'scheduled',
        ]);

        // 1. Staff clicks "Mulai Berangkat (Check-in)"
        $startResponse = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->patchJson("/api/v1/billing/visits/{$visit->id}/status", [
                'status' => 'in_progress',
            ]);

        $startResponse->assertOk();
        $this->assertEquals('in_progress', $visit->fresh()->status);
        $this->assertNotNull($visit->fresh()->departed_at);

        // Manually adjust departed_at to 45 minutes ago to test duration calculation
        $visit->update(['departed_at' => now()->subMinutes(45)]);

        // 2. Staff completes visit
        $completeResponse = $this->withHeaders(['X-Merchant-Id' => $this->merchant->id])
            ->patchJson("/api/v1/billing/visits/{$visit->id}/status", [
                'status' => 'completed',
                'result_notes' => 'Pemasangan modem selesai dan koneksi normal.',
            ]);

        $completeResponse->assertOk();
        $freshVisit = $visit->fresh();
        $this->assertEquals('completed', $freshVisit->status);
        $this->assertNotNull($freshVisit->completed_at);
        $this->assertEquals(45, $freshVisit->duration_in_minutes);
    }
}

