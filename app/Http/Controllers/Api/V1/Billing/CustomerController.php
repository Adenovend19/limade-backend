<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\Customer;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends Controller
{
    /**
     * List customers & leads for active merchant.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');
        $role = $request->attributes->get('active_merchant_role');
        $isManager = in_array(strtolower((string) $role), ['owner', 'admin', 'billing_admin']);
        $userId = $request->user()->id;

        $baseCustomerQuery = Customer::where('merchant_id', $merchant->id);

        if (!$isManager) {
            $baseCustomerQuery->where(function ($q) use ($userId) {
                $q->where('created_by', $userId)
                  ->orWhereHas('assignedPackages', function ($pq) use ($userId) {
                      $pq->where('assigned_staff_id', $userId);
                  })
                  ->orWhereHas('visits', function ($vq) use ($userId) {
                      $vq->where('assigned_staff_id', $userId);
                  });
            });
        }

        $query = (clone $baseCustomerQuery)
            ->with(['creator:id,name,email'])
            ->with(['invoices' => function ($q) {
                $q->select('id', 'customer_id', 'amount', 'status', 'due_date');
            }]);

        if ($request->filled('stage') && $request->stage !== 'all') {
            $query->where('stage', $request->stage);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('company_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderBy('created_at', 'desc')->get();

        // Calculate summary for each customer
        $customers->transform(function ($customer) {
            $invoices = $customer->invoices;
            $customer->total_billed = $invoices->sum('amount');
            $customer->total_unpaid = $invoices->where('status', 'unpaid')->sum('amount');
            $customer->total_paid = $invoices->where('status', 'paid')->sum('amount');
            $customer->invoices_count = $invoices->count();
            unset($customer->invoices); // avoid bulky response
            return $customer;
        });

        // Stage metrics
        $allMerchantCustomers = (clone $baseCustomerQuery)->get();
        $metrics = [
            'total' => $allMerchantCustomers->count(),
            'leads' => $allMerchantCustomers->where('stage', 'lead')->count(),
            'prospects' => $allMerchantCustomers->where('stage', 'prospect')->count(),
            'customers' => $allMerchantCustomers->where('stage', 'customer')->count(),
            'inactive' => $allMerchantCustomers->where('stage', 'inactive')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'customers' => $customers,
                'metrics' => $metrics,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Store a new customer / lead.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'stage' => ['nullable', 'string', 'in:lead,prospect,customer,inactive'],
            'source' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $customer = Customer::create([
            'merchant_id' => $merchant->id,
            'created_by' => $request->user()->id,
            'name' => $validated['name'],
            'company_name' => $validated['company_name'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'stage' => $validated['stage'] ?? 'lead',
            'source' => $validated['source'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        $customer->load('creator:id,name,email');

        return response()->json([
            'success' => true,
            'message' => "Kontak {$customer->name} berhasil ditambahkan.",
            'data' => $customer,
        ], Response::HTTP_CREATED);
    }

    /**
     * Show customer profile and their invoices.
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($customer->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Kontak tidak ditemukan pada toko ini.',
            ], Response::HTTP_NOT_FOUND);
        }

        $customer->load([
            'creator:id,name,email',
            'invoices' => function ($q) {
                $q->orderBy('created_at', 'desc');
            },
            'assignedPackages' => function ($q) {
                $q->with(['package', 'assignedStaff:id,name,email'])->orderBy('created_at', 'desc');
            },
            'visits' => function ($q) {
                $q->with(['assignedStaff:id,name,email', 'creator:id,name,email'])->orderBy('scheduled_at', 'desc');
            }
        ]);

        $totalBilled = $customer->invoices->sum('amount');
        $totalUnpaid = $customer->invoices->where('status', 'unpaid')->sum('amount');
        $totalPaid = $customer->invoices->where('status', 'paid')->sum('amount');

        return response()->json([
            'success' => true,
            'data' => [
                'customer' => $customer,
                'summary' => [
                    'total_billed' => $totalBilled,
                    'total_unpaid' => $totalUnpaid,
                    'total_paid' => $totalPaid,
                    'invoices_count' => $customer->invoices->count(),
                ],
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Update customer details.
     */
    public function update(Request $request, Customer $customer): JsonResponse
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
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'stage' => ['nullable', 'string', 'in:lead,prospect,customer,inactive'],
            'source' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $customer->update($validated);
        $customer->load('creator:id,name,email');

        return response()->json([
            'success' => true,
            'message' => "Data kontak {$customer->name} berhasil diperbarui.",
            'data' => $customer,
        ], Response::HTTP_OK);
    }

    /**
     * Update customer lifecycle stage directly.
     */
    public function updateStage(Request $request, Customer $customer): JsonResponse
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
            'stage' => ['required', 'string', 'in:lead,prospect,customer,inactive'],
        ]);

        $customer->update(['stage' => $validated['stage']]);

        return response()->json([
            'success' => true,
            'message' => "Status siklus hidup {$customer->name} diubah menjadi " . ucfirst($customer->stage) . ".",
            'data' => $customer,
        ], Response::HTTP_OK);
    }

    /**
     * Delete customer.
     */
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($customer->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Kontak tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $customerName = $customer->name;
        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => "Kontak {$customerName} berhasil dihapus.",
        ], Response::HTTP_OK);
    }

    /**
     * Get unpaid invoices for a specific customer.
     */
    public function unpaidInvoices(Request $request, Customer $customer): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($customer->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Pelanggan tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $invoices = BillingInvoice::where('merchant_id', $merchant->id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->orderBy('due_date', 'asc')
            ->get();

        $activePackages = CustomerAssignedPackage::where('merchant_id', $merchant->id)
            ->where('customer_id', $customer->id)
            ->where('status', 'active')
            ->with('package:id,name,service_code')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                ],
                'invoices' => $invoices,
                'active_packages' => $activePackages,
                'total_unpaid' => $invoices->sum('amount'),
                'count' => $invoices->count(),
            ],
        ], Response::HTTP_OK);
    }
}
