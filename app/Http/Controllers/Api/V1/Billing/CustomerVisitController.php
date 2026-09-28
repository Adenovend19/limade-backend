<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\CustomerVisit;
use App\Models\Merchant;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerVisitController extends Controller
{
    /**
     * List customer visits with filters.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        $query = CustomerVisit::where('merchant_id', $merchant->id)
            ->with([
                'customer:id,name,company_name,phone,email,address,stage',
                'assignedStaff:id,name,email',
                'creator:id,name,email',
            ]);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        if ($request->filled('visit_type') && $request->visit_type !== 'all') {
            $query->where('visit_type', $request->visit_type);
        }

        if ($request->boolean('my_tasks')) {
            $query->where('assigned_staff_id', $request->user()->id);
        } elseif ($request->filled('staff_id') && $request->staff_id !== 'all') {
            $query->where('assigned_staff_id', $request->staff_id);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('scheduled_at', [
                Carbon::parse($request->start_date)->startOfDay(),
                Carbon::parse($request->end_date)->endOfDay(),
            ]);
        }

        $visits = $query->orderBy('scheduled_at', 'asc')->get();

        $metrics = [
            'total' => $visits->count(),
            'scheduled' => $visits->where('status', 'scheduled')->count(),
            'in_progress' => $visits->where('status', 'in_progress')->count(),
            'completed' => $visits->where('status', 'completed')->count(),
            'cancelled' => $visits->where('status', 'cancelled')->count(),
            'rescheduled' => $visits->where('status', 'rescheduled')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'visits' => $visits,
                'metrics' => $metrics,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Create a new customer visit schedule.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'title' => ['required', 'string', 'max:255'],
            'visit_type' => ['required', 'string', 'in:collection,negotiation,survey_installation,maintenance'],
            'scheduled_at' => ['required', 'date'],
            'assigned_staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $customer = Customer::where('id', $validated['customer_id'])
            ->where('merchant_id', $merchant->id)
            ->firstOrFail();

        $visit = CustomerVisit::create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'assigned_staff_id' => $validated['assigned_staff_id'] ?? $request->user()->id,
            'created_by' => $request->user()->id,
            'title' => $validated['title'],
            'visit_type' => $validated['visit_type'],
            'scheduled_at' => Carbon::parse($validated['scheduled_at']),
            'status' => 'scheduled',
            'address' => $validated['address'] ?? $customer->address,
            'notes' => $validated['notes'] ?? null,
        ]);

        $visit->load([
            'customer:id,name,company_name,phone,email,address,stage',
            'assignedStaff:id,name,email',
            'creator:id,name,email',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Jadwal kunjungan ke {$customer->name} berhasil dibuat.",
            'data' => $visit,
        ], Response::HTTP_CREATED);
    }

    /**
     * Show visit details.
     */
    public function show(Request $request, CustomerVisit $visit): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($visit->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal kunjungan tidak ditemukan pada toko ini.',
            ], Response::HTTP_NOT_FOUND);
        }

        $visit->load([
            'customer:id,name,company_name,phone,email,address,stage',
            'assignedStaff:id,name,email',
            'creator:id,name,email',
        ]);

        return response()->json([
            'success' => true,
            'data' => $visit,
        ], Response::HTTP_OK);
    }

    /**
     * Update visit schedule.
     */
    public function update(Request $request, CustomerVisit $visit): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($visit->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal kunjungan tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'visit_type' => ['required', 'string', 'in:collection,negotiation,survey_installation,maintenance'],
            'scheduled_at' => ['required', 'date'],
            'assigned_staff_id' => ['nullable', 'integer', 'exists:users,id'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', 'string', 'in:scheduled,in_progress,completed,rescheduled,cancelled'],
            'departed_at' => ['nullable', 'date'],
            'result_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($validated['scheduled_at'])) {
            $validated['scheduled_at'] = Carbon::parse($validated['scheduled_at']);
        }
        if (isset($validated['departed_at'])) {
            $validated['departed_at'] = Carbon::parse($validated['departed_at']);
        }

        $visit->update($validated);
        $visit->load([
            'customer:id,name,company_name,phone,email,address,stage',
            'assignedStaff:id,name,email',
            'creator:id,name,email',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Jadwal kunjungan berhasil diperbarui.',
            'data' => $visit,
        ], Response::HTTP_OK);
    }

    /**
     * Update visit status (Start journey, Complete with report, Reschedule, or Cancel).
     */
    public function updateStatus(Request $request, CustomerVisit $visit): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($visit->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal kunjungan tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:scheduled,in_progress,completed,rescheduled,cancelled'],
            'departed_at' => ['nullable', 'date'],
            'result_notes' => ['nullable', 'string', 'max:2000'],
            'new_scheduled_at' => ['nullable', 'date'],
        ]);

        $updates = ['status' => $validated['status']];

        if ($validated['status'] === 'in_progress') {
            $updates['departed_at'] = !empty($validated['departed_at'])
                ? Carbon::parse($validated['departed_at'])
                : now();
        } elseif ($validated['status'] === 'completed') {
            $updates['result_notes'] = $validated['result_notes'] ?? $visit->result_notes;
            $updates['completed_at'] = now();
            if (!$visit->departed_at) {
                // If staff directly completed without pressing start, anchor departed_at so duration exists
                $updates['departed_at'] = ($visit->scheduled_at && $visit->scheduled_at->isPast())
                    ? $visit->scheduled_at
                    : now();
            }
        } elseif ($validated['status'] === 'rescheduled') {
            if (!empty($validated['new_scheduled_at'])) {
                $updates['scheduled_at'] = Carbon::parse($validated['new_scheduled_at']);
            }
            if (!empty($validated['result_notes'])) {
                $updates['result_notes'] = $validated['result_notes'];
            }
        } elseif ($validated['status'] === 'cancelled') {
            if (!empty($validated['result_notes'])) {
                $updates['result_notes'] = $validated['result_notes'];
            }
        }

        $visit->update($updates);
        $visit->load([
            'customer:id,name,company_name,phone,email,address,stage',
            'assignedStaff:id,name,email',
            'creator:id,name,email',
        ]);

        $statusLabels = [
            'scheduled' => 'dijadwalkan',
            'in_progress' => 'dimulai (staf dalam perjalanan)',
            'completed' => 'diselesaikan dengan laporan tercatat',
            'rescheduled' => 'dijadwalkan ulang',
            'cancelled' => 'dibatalkan',
        ];

        return response()->json([
            'success' => true,
            'message' => "Kunjungan berhasil {$statusLabels[$visit->status]}.",
            'data' => $visit,
        ], Response::HTTP_OK);
    }

    /**
     * Delete visit schedule.
     */
    public function destroy(Request $request, CustomerVisit $visit): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($visit->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal kunjungan tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $visit->delete();

        return response()->json([
            'success' => true,
            'message' => 'Jadwal kunjungan berhasil dihapus.',
        ], Response::HTTP_OK);
    }

    /**
     * Collect payment on site during a visit:
     * Marks invoices as paid, updates visit as completed with payment details.
     */
    public function collectPayment(Request $request, CustomerVisit $visit): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($visit->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal kunjungan tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'invoice_ids' => ['nullable', 'array'],
            'invoice_ids.*' => ['integer', 'exists:billing_invoices,id'],
            'custom_amount' => ['nullable', 'numeric', 'min:1'],
            'invoice_description' => ['nullable', 'string', 'max:255'],
            'payment_method' => ['required', 'string', 'in:cash,transfer,qris'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (empty($validated['invoice_ids']) && empty($validated['custom_amount'])) {
            return response()->json([
                'success' => false,
                'message' => 'Pilih minimal satu faktur atau masukkan nominal pembayaran yang diterima.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $totalPaidAmount = 0;
        $invoiceNumbers = [];
        $invoices = collect();

        // 1. If custom amount entered (no existing invoice or direct collection)
        if (!empty($validated['custom_amount'])) {
            $customer = $visit->customer;
            $invNumber = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
            $newInv = BillingInvoice::create([
                'merchant_id' => $merchant->id,
                'customer_id' => $visit->customer_id,
                'created_by' => $visit->assigned_staff_id ?? $request->user()->id,
                'invoice_number' => $invNumber,
                'client_name' => $customer?->name ?? 'Pelanggan',
                'client_email' => $customer?->email ?? null,
                'description' => $validated['invoice_description'] ?? ("Penagihan Langsung: " . $visit->title),
                'amount' => (int) $validated['custom_amount'],
                'due_date' => now()->toDateString(),
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $totalPaidAmount = (int) $validated['custom_amount'];
            $invoiceNumbers[] = $newInv->invoice_number;
            $invoices->push($newInv);
        } else {
            // 2. Existing invoices
            $existingInvoices = BillingInvoice::where('merchant_id', $merchant->id)
                ->whereIn('id', $validated['invoice_ids'])
                ->get();

            if ($existingInvoices->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada faktur yang valid untuk diproses.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            foreach ($existingInvoices as $inv) {
                $inv->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                ]);
                $totalPaidAmount += $inv->amount;
                $invoiceNumbers[] = $inv->invoice_number;
                $invoices->push($inv);
            }
        }

        $methodLabels = [
            'cash' => 'Tunai (Cash)',
            'transfer' => 'Transfer Bank',
            'qris' => 'QRIS / E-Wallet',
        ];
        $methodStr = $methodLabels[$validated['payment_method']] ?? $validated['payment_method'];

        $formattedTotal = 'Rp ' . number_format($totalPaidAmount, 0, ',', '.');
        $invoiceListStr = implode(', ', $invoiceNumbers);

        $resultNotes = "Pembayaran DITERIMA LUNAS ({$methodStr})\n";
        $resultNotes .= "Total: {$formattedTotal} (Faktur: {$invoiceListStr})\n";
        if (!empty($validated['notes'])) {
            $resultNotes .= "Catatan: " . $validated['notes'];
        }

        $visit->update([
            'status' => 'completed',
            'completed_at' => now(),
            'result_notes' => $resultNotes,
        ]);

        if ($visit->customer_id) {
            $cust = Customer::find($visit->customer_id);
            if ($cust && in_array($cust->stage, ['lead', 'prospect'])) {
                $cust->update(['stage' => 'customer']);
            }
        }

        $receiptNumber = 'KW-' . date('Ymd') . '-' . str_pad($visit->id, 4, '0', STR_PAD_LEFT);
        $paymentTimeStr = now()->format('d/m/Y H:i') . ' WIB';

        return response()->json([
            'success' => true,
            'message' => 'Pembayaran berhasil dicatat & faktur telah dilunaskan.',
            'data' => [
                'visit' => $visit->fresh(['customer']),
                'receipt_number' => $receiptNumber,
                'payment_time' => $paymentTimeStr,
                'staff_name' => $request->user()->name,
                'merchant_name' => $merchant->name,
                'customer_name' => $visit->customer?->name,
                'customer_phone' => $visit->customer?->phone,
                'customer_company' => $visit->customer?->company_name,
                'customer_address' => $visit->address ?? $visit->customer?->address,
                'total_paid' => $totalPaidAmount,
                'formatted_total' => $formattedTotal,
                'invoices_count' => $invoices->count(),
                'invoice_numbers' => $invoiceNumbers,
                'items' => $invoices->map(function ($inv) {
                    return [
                        'invoice_number' => $inv->invoice_number,
                        'description' => $inv->description ?: 'Tagihan Layanan',
                        'amount' => $inv->amount,
                        'formatted_amount' => 'Rp ' . number_format($inv->amount, 0, ',', '.'),
                    ];
                })->values(),
                'payment_method' => $methodStr,
                'notes' => $validated['notes'] ?? null,
                'receipt_message' => "Halo {$visit->customer?->name}, terima kasih! Pembayaran tagihan {$invoiceListStr} sebesar {$formattedTotal} telah kami terima secara {$methodStr} oleh petugas kami ({$request->user()->name}) pada {$paymentTimeStr}. No Kuitansi: {$receiptNumber}. Bukti pembayaran ini sah dari {$merchant->name}.",
            ],
        ], Response::HTTP_OK);
    }
}
