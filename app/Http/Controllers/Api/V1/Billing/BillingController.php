<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BillingController extends Controller
{
    /**
     * List all invoices for the active merchant.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');
        $role = $request->attributes->get('active_merchant_role');
        $isManager = in_array(strtolower((string) $role), ['owner', 'admin', 'billing_admin']);
        $userId = $request->user()->id;

        $query = BillingInvoice::where('merchant_id', $merchant->id);

        if (!$isManager) {
            $query->where(function ($q) use ($userId) {
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

        $invoices = $query->with([
            'customer' => function ($q) {
                $q->with('creator:id,name,email');
            },
            'creator:id,name,email',
        ])
        ->orderBy('created_at', 'desc')
        ->get();

        $totalInvoiced = $invoices->sum('amount');
        $totalUnpaid = $invoices->where('status', 'unpaid')->sum('amount');
        $totalPaid = $invoices->where('status', 'paid')->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'invoices' => $invoices,
                'metrics' => [
                    'total_invoiced' => $totalInvoiced,
                    'total_unpaid' => $totalUnpaid,
                    'total_paid' => $totalPaid,
                    'unpaid_count' => $invoices->where('status', 'unpaid')->count(),
                    'paid_count' => $invoices->where('status', 'paid')->count(),
                ],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Create / Issue a new billing invoice.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'client_name' => ['required', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string', 'max:1000'],
            'amount' => ['required', 'integer', 'min:1'],
            'due_date' => ['required', 'date'],
            'save_as_customer' => ['nullable', 'boolean'],
        ]);

        $customerId = $validated['customer_id'] ?? null;
        $clientName = $validated['client_name'];
        $clientEmail = $validated['client_email'] ?? null;

        // If an existing customer was selected, verify ownership and auto-promote lifecycle
        if ($customerId) {
            $customer = Customer::where('id', $customerId)
                ->where('merchant_id', $merchant->id)
                ->first();

            if ($customer) {
                $clientName = $customer->name;
                $clientEmail = $customer->email ?: $clientEmail;

                // Lifecycle Transition: Auto-promote Lead or Prospect to Active Customer upon invoice creation
                if (in_array($customer->stage, ['lead', 'prospect'])) {
                    $customer->update(['stage' => 'customer']);
                }
            } else {
                $customerId = null;
            }
        } elseif (!empty($validated['save_as_customer'])) {
            // User requested to save new contact as a customer record
            $customer = Customer::create([
                'merchant_id' => $merchant->id,
                'created_by' => $request->user()->id,
                'name' => $clientName,
                'email' => $clientEmail,
                'phone' => $validated['client_phone'] ?? null,
                'stage' => 'customer', // directly customer since we're issuing an invoice
                'source' => 'Billing Invoice',
            ]);
            $customerId = $customer->id;
        }

        $count = BillingInvoice::where('merchant_id', $merchant->id)->count() + 1;
        $invoiceNumber = sprintf('INV-%s-%03d', date('Y'), $count);

        while (BillingInvoice::where('invoice_number', $invoiceNumber)->exists()) {
            $count++;
            $invoiceNumber = sprintf('INV-%s-%03d', date('Y'), $count);
        }

        $invoice = BillingInvoice::create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customerId,
            'created_by' => $request->user()->id,
            'invoice_number' => $invoiceNumber,
            'client_name' => $clientName,
            'client_email' => $clientEmail,
            'description' => $validated['description'] ?? null,
            'amount' => $validated['amount'],
            'due_date' => $validated['due_date'],
            'status' => 'unpaid',
        ]);

        $invoice->load(['customer.creator:id,name,email', 'creator:id,name,email']);

        return response()->json([
            'success' => true,
            'message' => "Faktur {$invoice->invoice_number} berhasil diterbitkan.",
            'data' => $invoice,
        ], Response::HTTP_CREATED);
    }

    /**
     * Mark an invoice as paid.
     */
    public function markPaid(Request $request, BillingInvoice $invoice): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($invoice->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Faktur tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $invoice->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        // If associated customer is still a lead/prospect, ensure they are marked as customer
        if ($invoice->customer_id) {
            $customer = Customer::find($invoice->customer_id);
            if ($customer && in_array($customer->stage, ['lead', 'prospect'])) {
                $customer->update(['stage' => 'customer']);
            }
        }

        return response()->json([
            'success' => true,
            'message' => "Faktur {$invoice->invoice_number} berhasil ditandai Lunas.",
            'data' => $invoice,
        ], Response::HTTP_OK);
    }

    /**
     * Send payment reminder simulation and generate WhatsApp click-to-chat link.
     */
    public function sendReminder(Request $request, BillingInvoice $invoice): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($invoice->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Faktur tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $invoice->load('customer');

        // Formatted amount and date
        $formattedAmount = 'Rp ' . number_format($invoice->amount, 0, ',', '.');
        $formattedDueDate = date('d-m-Y', strtotime($invoice->due_date));

        // Format message for WhatsApp / Email reminder
        $clientName = $invoice->customer?->name ?: $invoice->client_name;
        $message = "Halo {$clientName},\n\nKami dari *{$merchant->name}* ingin menginformasikan perihal tagihan faktur berikut:\n" .
            "• *No. Faktur*: {$invoice->invoice_number}\n" .
            "• *Layanan*: " . ($invoice->description ?: '-') . "\n" .
            "• *Nominal*: {$formattedAmount}\n" .
            "• *Jatuh Tempo*: {$formattedDueDate}\n\n" .
            "Mohon kesediaannya untuk melakukan pembayaran sebelum tanggal jatuh tempo. Apabila pembayaran telah dilakukan, mohon kirimkan bukti transfer. Terima kasih!";

        $phone = $invoice->customer?->phone ?: null;
        $whatsappUrl = null;

        if ($phone) {
            // Clean phone number (replace 08... with 628...)
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            if (str_starts_with($cleanPhone, '0')) {
                $cleanPhone = '62' . substr($cleanPhone, 1);
            }
            $whatsappUrl = 'https://wa.me/' . $cleanPhone . '?text=' . urlencode($message);
        }

        return response()->json([
            'success' => true,
            'message' => "Pengingat pembayaran faktur {$invoice->invoice_number} telah disiapkan.",
            'data' => [
                'invoice' => $invoice,
                'reminder_message' => $message,
                'whatsapp_url' => $whatsappUrl,
                'phone' => $phone,
            ],
        ], Response::HTTP_OK);
    }
}
