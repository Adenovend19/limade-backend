<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\CustomerAssignedPackage;
use App\Models\CustomerVisit;
use App\Models\Merchant;
use App\Models\MerchantServicePackage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BillingCalendarController extends Controller
{
    /**
     * Get monthly billing calendar schedules and tasks.
     */
    public function calendar(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        $daysInMonth = Carbon::createFromDate($year, $month, 1)->daysInMonth;
        $startOfMonth = Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateString();
        $endOfMonth = Carbon::createFromDate($year, $month, $daysInMonth)->endOfMonth()->toDateString();

        $role = $request->attributes->get('active_merchant_role');
        $isManager = in_array(strtolower((string) $role), ['owner', 'admin', 'billing_admin']);
        $userId = $request->user()->id;

        // Query active customer assignments
        $query = CustomerAssignedPackage::where('merchant_id', $merchant->id)
            ->where('status', 'active')
            ->with([
                'customer:id,name,company_name,phone,email,stage',
                'package:id,name,code,price',
                'assignedStaff:id,name,email',
            ]);

        // Filter by staff
        if (! $isManager) {
            $query->where('assigned_staff_id', $userId);
        } elseif ($request->boolean('my_tasks')) {
            $query->where('assigned_staff_id', $userId);
        } elseif ($request->filled('staff_id') && $request->staff_id !== 'all') {
            $query->where('assigned_staff_id', $request->staff_id);
        }

        $assignments = $query->get();

        // Query customer visits for this month
        $visitQuery = CustomerVisit::where('merchant_id', $merchant->id)
            ->whereBetween('scheduled_at', [
                Carbon::createFromDate($year, $month, 1)->startOfMonth()->toDateTimeString(),
                Carbon::createFromDate($year, $month, $daysInMonth)->endOfMonth()->toDateTimeString(),
            ])
            ->with([
                'customer:id,name,company_name,phone,email,address,stage',
                'assignedStaff:id,name,email',
            ]);

        if (! $isManager) {
            $visitQuery->where('assigned_staff_id', $userId);
        } elseif ($request->boolean('my_tasks')) {
            $visitQuery->where('assigned_staff_id', $userId);
        } elseif ($request->filled('staff_id') && $request->staff_id !== 'all') {
            $visitQuery->where('assigned_staff_id', $request->staff_id);
        }

        $monthlyVisits = $visitQuery->get();

        // Fetch invoices for this merchant in this month range
        $invoiceQuery = BillingInvoice::where('merchant_id', $merchant->id)
            ->whereBetween('due_date', [$startOfMonth, $endOfMonth]);

        if (! $isManager) {
            $invoiceQuery->where(function ($q) use ($userId) {
                $q->where('created_by', $userId)
                  ->orWhereHas('customer', function ($cq) use ($userId) {
                      $cq->where('created_by', $userId)
                         ->orWhereHas('assignedPackages', function ($pq) use ($userId) {
                             $pq->where('assigned_staff_id', $userId);
                         })
                         ->orWhereHas('visits', function ($vq) use ($userId) {
                             $vq->where('assigned_staff_id', $userId);
                         });
                  });
            });
        }

        $monthlyInvoices = $invoiceQuery->get();

        $calendarEvents = [];
        $consumedVisitIds = [];
        $totalProjected = 0;
        $pendingCount = 0;
        $invoicedCount = 0;
        $paidCount = 0;

        foreach ($assignments as $assignment) {
            // Determine day of month (bound to max days in month)
            $day = min((int) $assignment->billing_cycle_day, $daysInMonth);
            $eventDate = sprintf('%04d-%02d-%02d', $year, $month, $day);

            // 1. Find official BillingInvoice if already issued for this month
            $matchingInvoice = $monthlyInvoices->first(function ($inv) use ($assignment) {
                return $inv->customer_id === $assignment->customer_id;
            });

            // 2. Find matching field visit for this customer in this month
            $matchingVisit = $monthlyVisits->first(function ($v) use ($assignment, $day) {
                return $v->customer_id === $assignment->customer_id
                    && (int) $v->scheduled_at->format('j') === $day;
            });

            if ($matchingVisit) {
                $consumedVisitIds[] = $matchingVisit->id;
            }

            if ($matchingInvoice && $matchingInvoice->status === 'paid') {
                $scheduleStatus = 'paid';
                $paidCount++;
            } elseif ($matchingInvoice) {
                $scheduleStatus = 'invoiced';
                $invoicedCount++;
            } elseif ($matchingVisit) {
                $scheduleStatus = $matchingVisit->status === 'in_progress' ? 'in_progress' : ($matchingVisit->status === 'completed' ? 'paid' : 'visit_scheduled');
                $pendingCount++;
            } else {
                $scheduleStatus = 'pending';
                $pendingCount++;
            }

            $totalProjected += $assignment->price;

            $calendarEvents[] = [
                'id' => $assignment->id,
                'item_type' => 'billing',
                'customer' => $assignment->customer,
                'package' => $assignment->package,
                'assigned_staff' => $matchingVisit?->assignedStaff ?: $assignment->assignedStaff,
                'day' => $day,
                'date' => $eventDate,
                'price' => $assignment->price,
                'billing_cycle_day' => $assignment->billing_cycle_day,
                'schedule_status' => $scheduleStatus,
                'invoice' => $matchingInvoice,
                'visit' => $matchingVisit,
                'has_active_visit' => (bool)$matchingVisit,
                'visit_id' => $matchingVisit?->id,
            ];
        }

        // Add standalone customer field visits (excluding visits already merged with billing schedules)
        foreach ($monthlyVisits as $visit) {
            if (in_array($visit->id, $consumedVisitIds)) {
                continue; // Prevent duplicate calendar entry!
            }

            $visitDay = (int) $visit->scheduled_at->format('j');
            $calendarEvents[] = [
                'id' => $visit->id,
                'item_type' => 'visit',
                'customer' => $visit->customer,
                'visit' => $visit,
                'title' => $visit->title,
                'visit_type' => $visit->visit_type,
                'assigned_staff' => $visit->assignedStaff,
                'day' => $visitDay,
                'date' => $visit->scheduled_at->toDateString(),
                'time' => $visit->scheduled_at->format('H:i'),
                'status' => $visit->status, // 'scheduled' | 'completed' | 'rescheduled' | 'cancelled'
            ];
        }

        // Sort events chronologically by day
        usort($calendarEvents, function ($a, $b) {
            return $a['day'] <=> $b['day'];
        });

        // Group by day for grid calendar
        $eventsByDay = [];
        foreach ($calendarEvents as $event) {
            $eventsByDay[$event['day']][] = $event;
        }

        // Get merchant staff list for assignment & filtering
        $staffMembersQuery = $merchant->users()->select('users.id', 'users.name', 'users.email');
        if (! $isManager) {
            $staffMembersQuery->where('users.id', $userId);
        }
        $staffMembers = $staffMembersQuery->get();

        return response()->json([
            'success' => true,
            'data' => [
                'month' => $month,
                'year' => $year,
                'days_in_month' => $daysInMonth,
                'events' => $calendarEvents,
                'events_by_day' => $eventsByDay,
                'metrics' => [
                    'total_assignments' => count($assignments),
                    'pending_count' => $pendingCount,
                    'invoiced_count' => $invoicedCount,
                    'paid_count' => $paidCount,
                    'total_projected_amount' => $totalProjected,
                    'total_visits' => $monthlyVisits->count(),
                    'scheduled_visits' => $monthlyVisits->where('status', 'scheduled')->count(),
                    'completed_visits' => $monthlyVisits->where('status', 'completed')->count(),
                ],
                'staff_members' => $staffMembers,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Assign a service package to a customer.
     */
    public function assignPackage(Request $request, Customer $customer): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($customer->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Kontak tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'package_id' => ['required', 'integer', 'exists:merchant_service_packages,id'],
            'billing_cycle_day' => ['required', 'integer', 'between:1,31'],
            'assigned_staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'price' => ['nullable', 'integer', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $package = MerchantServicePackage::where('id', $validated['package_id'])
            ->where('merchant_id', $merchant->id)
            ->firstOrFail();

        $price = isset($validated['price']) ? (int) $validated['price'] : $package->price;
        $startDate = $validated['start_date'] ?? now()->toDateString();

        $assignment = CustomerAssignedPackage::create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'package_id' => $package->id,
            'assigned_staff_id' => $validated['assigned_staff_id'] ?? null,
            'billing_cycle_day' => $validated['billing_cycle_day'],
            'price' => $price,
            'status' => 'active',
            'start_date' => $startDate,
            'notes' => $validated['notes'] ?? null,
        ]);

        // Promote customer from lead/prospect to customer
        if (in_array($customer->stage, ['lead', 'prospect'])) {
            $customer->update(['stage' => 'customer']);
        }

        $assignment->load(['package', 'assignedStaff:id,name,email']);

        return response()->json([
            'success' => true,
            'message' => "Paket {$package->name} berhasil ditugaskan ke {$customer->name}.",
            'data' => $assignment,
        ], Response::HTTP_CREATED);
    }

    /**
     * Update an assigned package.
     */
    public function updateAssignedPackage(Request $request, CustomerAssignedPackage $assignment): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($assignment->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Langganan paket tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'package_id' => ['nullable', 'integer', 'exists:merchant_service_packages,id'],
            'billing_cycle_day' => ['required', 'integer', 'between:1,31'],
            'assigned_staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'price' => ['required', 'integer', 'min:0'],
            'status' => ['required', 'string', 'in:active,paused,cancelled'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $assignment->update($validated);
        $assignment->load(['package', 'assignedStaff:id,name,email']);

        return response()->json([
            'success' => true,
            'message' => 'Pengaturan paket langganan klien berhasil diperbarui.',
            'data' => $assignment,
        ], Response::HTTP_OK);
    }

    /**
     * Delete an assigned package.
     */
    public function deleteAssignedPackage(Request $request, CustomerAssignedPackage $assignment): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($assignment->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Langganan paket tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $assignment->delete();

        return response()->json([
            'success' => true,
            'message' => 'Penugasan paket berhasil dihapus.',
        ], Response::HTTP_OK);
    }

    /**
     * Issue an invoice directly from calendar schedule item.
     */
    public function issueInvoiceFromSchedule(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        $validated = $request->validate([
            'assignment_id' => ['required', 'integer', 'exists:customer_assigned_packages,id'],
            'due_date' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $assignment = CustomerAssignedPackage::where('id', $validated['assignment_id'])
            ->where('merchant_id', $merchant->id)
            ->with(['customer', 'package'])
            ->firstOrFail();

        $count = BillingInvoice::where('merchant_id', $merchant->id)->count() + 1;
        $invoiceNumber = sprintf('INV-%s-%03d', date('Y'), $count);

        while (BillingInvoice::where('invoice_number', $invoiceNumber)->exists()) {
            $count++;
            $invoiceNumber = sprintf('INV-%s-%03d', date('Y'), $count);
        }

        $description = $validated['description'] ?? "Tagihan {$assignment->package->name} - " . Carbon::parse($validated['due_date'])->locale('id')->isoFormat('MMMM Y');

        $invoice = BillingInvoice::create([
            'merchant_id' => $merchant->id,
            'customer_id' => $assignment->customer_id,
            'created_by' => $assignment->assigned_staff_id ?? $request->user()->id,
            'invoice_number' => $invoiceNumber,
            'client_name' => $assignment->customer->name,
            'client_email' => $assignment->customer->email,
            'description' => $description,
            'amount' => $assignment->price,
            'due_date' => $validated['due_date'],
            'status' => 'unpaid',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Faktur {$invoice->invoice_number} berhasil diterbitkan untuk {$assignment->customer->name}.",
            'data' => $invoice,
        ], Response::HTTP_CREATED);
    }

    /**
     * Update customer package assignment status (active, paused, cancelled).
     */
    public function updateAssignmentStatus(Request $request, CustomerAssignedPackage $assignment): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($assignment->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Langganan paket tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,paused,cancelled'],
        ]);

        $assignment->update(['status' => $validated['status']]);

        $statusLabels = [
            'active' => 'diaktifkan kembali',
            'paused' => 'dijeda sementara',
            'cancelled' => 'dinonaktifkan / dibatalkan',
        ];

        return response()->json([
            'success' => true,
            'message' => "Langganan paket customer berhasil {$statusLabels[$assignment->status]}.",
            'data' => $assignment,
        ], Response::HTTP_OK);
    }
}
