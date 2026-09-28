<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\CustomerAssignedPackage;
use App\Models\CustomerVisit;
use App\Models\Merchant;
use App\Models\UserTodo;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UserTodoController extends Controller
{
    /**
     * Get unified daily to-dos (Personal Todos + Field Visits + Billing Schedules) for current user.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');
        $userId = $request->user()->id;
        $filter = $request->query('filter', 'today'); // 'today', 'overdue', 'completed', 'all'
        $today = Carbon::today();

        // 1. Query Manual Todos for this user & merchant (active OR completed within last 14 days)
        $allTodos = UserTodo::where('merchant_id', $merchant->id)
            ->where('user_id', $userId)
            ->where(function ($q) use ($today) {
                $q->where('is_completed', false)
                  ->orWhere(function ($sub) use ($today) {
                      $sub->where('is_completed', true)
                          ->where('updated_at', '>=', $today->copy()->subDays(14));
                  });
            })
            ->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        // 2. Query Customer Visits: only pending visits OR recently completed (within last 14 days)
        $allVisits = CustomerVisit::where('merchant_id', $merchant->id)
            ->where(function ($q) use ($userId) {
                $q->where('assigned_staff_id', $userId)
                  ->orWhereNull('assigned_staff_id');
            })
            ->where(function ($q) use ($today) {
                $q->whereIn('status', ['scheduled', 'in_progress'])
                  ->orWhere(function ($sub) use ($today) {
                      $sub->where('status', 'completed')
                          ->where('completed_at', '>=', $today->copy()->subDays(14));
                  });
            })
            ->with(['customer:id,name,company_name,phone,email,address,stage'])
            ->orderBy('scheduled_at', 'asc')
            ->get();

        // 3. Query Subscription Billing Schedules assigned to this user (or unassigned/all staff)
        $allAssignments = CustomerAssignedPackage::where('merchant_id', $merchant->id)
            ->where(function ($q) use ($userId) {
                $q->where('assigned_staff_id', $userId)
                  ->orWhereNull('assigned_staff_id');
            })
            ->where('status', 'active')
            ->with([
                'customer:id,name,company_name,phone,email,address,stage',
                'package:id,name,code,price',
            ])
            ->get();

        // Invoices for matching schedule payment status
        $startOfMonth = $today->copy()->startOfMonth()->toDateString();
        $endOfMonth = $today->copy()->endOfMonth()->toDateString();
        $monthlyInvoices = BillingInvoice::where('merchant_id', $merchant->id)
            ->whereBetween('due_date', [$startOfMonth, $endOfMonth])
            ->get();

        // Categorize Schedules & track visits consumed by schedules to avoid duplicate items
        $consumedVisitIds = [];
        $todaySchedules = [];
        $overdueSchedules = [];
        $completedSchedules = [];

        foreach ($allAssignments as $assignment) {
            $day = (int) $assignment->billing_cycle_day;

            // 1. Auto-ensure official BillingInvoice exists for this billing month
            $matchingInvoice = $monthlyInvoices->first(fn($inv) => $inv->customer_id === $assignment->customer_id);
            if (!$matchingInvoice) {
                $count = BillingInvoice::where('merchant_id', $merchant->id)->count() + 1;
                $invoiceNumber = sprintf('INV-%s-%03d', date('Y'), $count);
                while (BillingInvoice::where('invoice_number', $invoiceNumber)->exists()) {
                    $count++;
                    $invoiceNumber = sprintf('INV-%s-%03d', date('Y'), $count);
                }
                $dueDate = sprintf('%04d-%02d-%02d', $today->year, $today->month, min($day, $today->daysInMonth));
                $matchingInvoice = BillingInvoice::create([
                    'merchant_id' => $merchant->id,
                    'customer_id' => $assignment->customer_id,
                    'created_by' => $assignment->assigned_staff_id ?? $userId,
                    'invoice_number' => $invoiceNumber,
                    'client_name' => $assignment->customer?->name ?? 'Pelanggan',
                    'client_email' => $assignment->customer?->email,
                    'description' => "Tagihan {$assignment->package?->name} - " . Carbon::parse($dueDate)->locale('id')->isoFormat('MMMM Y'),
                    'amount' => $assignment->price,
                    'due_date' => $dueDate,
                    'status' => 'unpaid',
                ]);
                $monthlyInvoices->push($matchingInvoice);
            }

            // 2. Find matching collection visit for this customer (active or completed)
            $matchingVisit = $allVisits->first(function ($v) use ($assignment) {
                return $v->customer_id === $assignment->customer_id
                    && in_array($v->status, ['scheduled', 'in_progress', 'completed']);
            });

            if ($matchingVisit) {
                $consumedVisitIds[] = $matchingVisit->id;
            }

            $scheduleItem = $this->formatScheduleItem($assignment, $matchingInvoice, $matchingVisit);

            if ($matchingInvoice && $matchingInvoice->status === 'paid') {
                $completedSchedules[] = $scheduleItem;
            } elseif ($day == $today->day) {
                $todaySchedules[] = $scheduleItem;
            } elseif ($day < $today->day) {
                $overdueSchedules[] = $scheduleItem;
            }
        }

        // Filter out visits consumed by schedules so they do NOT appear twice
        $standaloneVisits = $allVisits->reject(fn($v) => in_array($v->id, $consumedVisitIds));

        // Metrics / Counts calculation
        $todayManual = $allTodos->filter(function ($t) use ($today) {
            return Carbon::parse($t->due_date)->isSameDay($today) && !$t->is_completed;
        });
        $todayVisits = $standaloneVisits->filter(function ($v) use ($today) {
            return Carbon::parse($v->scheduled_at)->isSameDay($today) && in_array($v->status, ['scheduled', 'in_progress']);
        });

        $overdueManual = $allTodos->filter(function ($t) use ($today) {
            return Carbon::parse($t->due_date)->lt($today) && !$t->is_completed;
        });
        $overdueVisits = $standaloneVisits->filter(function ($v) use ($today) {
            return Carbon::parse($v->scheduled_at)->lt($today) && in_array($v->status, ['scheduled', 'in_progress']);
        });

        $completedManual = $allTodos->filter(fn($t) => $t->is_completed);
        $completedVisits = $standaloneVisits->filter(fn($v) => $v->status === 'completed');

        $metrics = [
            'today_pending' => $todayManual->count() + $todayVisits->count() + count($todaySchedules),
            'overdue' => $overdueManual->count() + $overdueVisits->count() + count($overdueSchedules),
            'completed' => $completedManual->count() + $completedVisits->count() + count($completedSchedules),
            'total_today' => $todayManual->count() + $todayVisits->count() + count($todaySchedules),
        ];

        // Format items based on requested filter
        $items = [];

        if ($filter === 'today') {
            foreach ($todaySchedules as $sched) {
                $items[] = $sched;
            }
            foreach ($todayVisits as $visit) {
                $items[] = $this->formatVisitItem($visit);
            }
            foreach ($todayManual as $todo) {
                $items[] = $this->formatTodoItem($todo);
            }
        } elseif ($filter === 'overdue') {
            foreach ($overdueSchedules as $sched) {
                $items[] = $sched;
            }
            foreach ($overdueVisits as $visit) {
                $items[] = $this->formatVisitItem($visit);
            }
            foreach ($overdueManual as $todo) {
                $items[] = $this->formatTodoItem($todo);
            }
        } elseif ($filter === 'completed') {
            foreach ($completedSchedules as $sched) {
                $items[] = $sched;
            }
            foreach ($completedVisits as $visit) {
                $items[] = $this->formatVisitItem($visit);
            }
            foreach ($completedManual as $todo) {
                $items[] = $this->formatTodoItem($todo);
            }
        } else {
            // all
            foreach ($todaySchedules as $sched) {
                $items[] = $sched;
            }
            foreach ($overdueSchedules as $sched) {
                $items[] = $sched;
            }
            foreach ($standaloneVisits as $visit) {
                $items[] = $this->formatVisitItem($visit);
            }
            foreach ($allTodos as $todo) {
                $items[] = $this->formatTodoItem($todo);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $items,
                'metrics' => $metrics,
                'filter' => $filter,
                'user' => [
                    'id' => $userId,
                    'name' => $request->user()->name,
                ],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Quick summary badge counter for sidebar / navbar.
     */
    public function badgeCount(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');
        $userId = $request->user()->id;
        $today = Carbon::today();

        $manualPendingCount = UserTodo::where('merchant_id', $merchant->id)
            ->where('user_id', $userId)
            ->whereDate('due_date', $today)
            ->where('is_completed', false)
            ->count();

        // Customer assigned packages today (these are primary billing tasks)
        $schedulesToday = CustomerAssignedPackage::where('merchant_id', $merchant->id)
            ->where(function ($q) use ($userId) {
                $q->where('assigned_staff_id', $userId)
                  ->orWhereNull('assigned_staff_id');
            })
            ->where('status', 'active')
            ->where('billing_cycle_day', $today->day)
            ->pluck('customer_id');

        // Visits today: count standalone visits (excluding visits matching today's schedule customers)
        $visitPendingCount = CustomerVisit::where('merchant_id', $merchant->id)
            ->where(function ($q) use ($userId) {
                $q->where('assigned_staff_id', $userId)
                  ->orWhereNull('assigned_staff_id');
            })
            ->whereDate('scheduled_at', $today)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereNotIn('customer_id', $schedulesToday)
            ->count();

        // Overdue schedules
        $schedulesOverdue = CustomerAssignedPackage::where('merchant_id', $merchant->id)
            ->where(function ($q) use ($userId) {
                $q->where('assigned_staff_id', $userId)
                  ->orWhereNull('assigned_staff_id');
            })
            ->where('status', 'active')
            ->where('billing_cycle_day', '<', $today->day)
            ->pluck('customer_id');

        $overdueCount = UserTodo::where('merchant_id', $merchant->id)
            ->where('user_id', $userId)
            ->whereDate('due_date', '<', $today)
            ->where('is_completed', false)
            ->count() +
            CustomerVisit::where('merchant_id', $merchant->id)
            ->where(function ($q) use ($userId) {
                $q->where('assigned_staff_id', $userId)
                  ->orWhereNull('assigned_staff_id');
            })
            ->whereDate('scheduled_at', '<', $today)
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereNotIn('customer_id', $schedulesOverdue)
            ->count() +
            $schedulesOverdue->count();

        return response()->json([
            'success' => true,
            'data' => [
                'today_pending' => $manualPendingCount + $visitPendingCount + $schedulesToday->count(),
                'overdue' => $overdueCount,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Store new manual to-do for current user.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'due_date' => 'nullable|date',
            'priority' => 'nullable|in:low,medium,high',
        ]);

        $todo = UserTodo::create([
            'merchant_id' => $merchant->id,
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'due_date' => $validated['due_date'] ?? Carbon::today()->toDateString(),
            'priority' => $validated['priority'] ?? 'medium',
            'is_completed' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'To-do berhasil ditambahkan.',
            'data' => $this->formatTodoItem($todo),
        ], Response::HTTP_CREATED);
    }

    /**
     * Toggle completion status of manual to-do.
     */
    public function toggle(Request $request, int $id): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        $todo = UserTodo::where('id', $id)
            ->where('merchant_id', $merchant->id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $newCompleted = !$todo->is_completed;
        $todo->update([
            'is_completed' => $newCompleted,
            'completed_at' => $newCompleted ? Carbon::now() : null,
        ]);

        return response()->json([
            'success' => true,
            'message' => $newCompleted ? 'Tugas ditandai selesai.' : 'Tugas diaktifkan kembali.',
            'data' => $this->formatTodoItem($todo),
        ], Response::HTTP_OK);
    }

    /**
     * Delete a manual to-do.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        $todo = UserTodo::where('id', $id)
            ->where('merchant_id', $merchant->id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $todo->delete();

        return response()->json([
            'success' => true,
            'message' => 'To-do berhasil dihapus.',
        ], Response::HTTP_OK);
    }

    /**
     * Helper to format manual to-do item.
     */
    private function formatTodoItem(UserTodo $todo): array
    {
        return [
            'id' => 'todo_' . $todo->id,
            'source_id' => $todo->id,
            'type' => 'manual',
            'title' => $todo->title,
            'description' => $todo->description,
            'due_date' => Carbon::parse($todo->due_date)->format('Y-m-d'),
            'priority' => $todo->priority,
            'is_completed' => (bool)$todo->is_completed,
            'completed_at' => $todo->completed_at ? Carbon::parse($todo->completed_at)->toIso8601String() : null,
            'created_at' => $todo->created_at ? $todo->created_at->toIso8601String() : null,
        ];
    }

    /**
     * Helper to format customer visit item.
     */
    private function formatVisitItem(CustomerVisit $visit): array
    {
        return [
            'id' => 'visit_' . $visit->id,
            'source_id' => $visit->id,
            'type' => 'visit',
            'title' => $visit->title,
            'visit_type' => $visit->visit_type,
            'status' => $visit->status,
            'departed_at' => $visit->departed_at ? Carbon::parse($visit->departed_at)->toIso8601String() : null,
            'scheduled_at' => $visit->scheduled_at ? Carbon::parse($visit->scheduled_at)->toIso8601String() : null,
            'is_completed' => $visit->status === 'completed',
            'completed_at' => $visit->completed_at ? Carbon::parse($visit->completed_at)->toIso8601String() : null,
            'address' => $visit->address,
            'notes' => $visit->notes,
            'result_notes' => $visit->result_notes,
            'customer' => $visit->customer,
            'created_at' => $visit->created_at ? $visit->created_at->toIso8601String() : null,
        ];
    }

    /**
     * Helper to format customer assigned package schedule item.
     */
    private function formatScheduleItem(CustomerAssignedPackage $assignment, $matchingInvoice, ?CustomerVisit $activeVisit = null): array
    {
        $status = 'invoiced';
        if ($matchingInvoice && $matchingInvoice->status === 'paid') {
            $status = 'paid';
        } elseif ($activeVisit) {
            $status = $activeVisit->status === 'in_progress' ? 'in_progress' : ($activeVisit->status === 'completed' ? 'paid' : 'visit_scheduled');
        }

        $invNumber = $matchingInvoice?->invoice_number;
        $title = $invNumber
            ? "Faktur {$invNumber} • " . ($assignment->package?->name ?? 'Paket Layanan')
            : "Tagihan: " . ($assignment->package?->name ?? 'Paket Layanan');

        return [
            'id' => 'schedule_' . $assignment->id,
            'source_id' => $assignment->id,
            'type' => 'billing',
            'title' => $title,
            'schedule_status' => $status,
            'is_completed' => $status === 'paid',
            'completed_at' => $matchingInvoice && $matchingInvoice->paid_at ? Carbon::parse($matchingInvoice->paid_at)->toIso8601String() : null,
            'price' => $assignment->price,
            'billing_cycle_day' => $assignment->billing_cycle_day,
            'package' => $assignment->package,
            'customer' => $assignment->customer,
            'invoice' => $matchingInvoice,
            'visit' => $activeVisit ? $this->formatVisitItem($activeVisit) : null,
            'visit_id' => $activeVisit?->id,
            'has_active_visit' => (bool)$activeVisit,
            'departed_at' => $activeVisit?->departed_at ? Carbon::parse($activeVisit->departed_at)->toIso8601String() : null,
            'address' => $activeVisit?->address ?: $assignment->customer?->address,
            'notes' => $activeVisit?->notes ?: $assignment->notes,
            'created_at' => $assignment->created_at ? $assignment->created_at->toIso8601String() : null,
        ];
    }
}
