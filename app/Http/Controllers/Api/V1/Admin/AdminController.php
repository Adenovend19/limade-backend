<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdminController extends Controller
{
    /**
     * Platform Dashboard overview metrics and recent activity.
     */
    public function dashboard(): JsonResponse
    {
        $totalMerchants = Merchant::count();
        $activeMerchants = Merchant::where('status', 'active')
            ->where(function ($q) {
                $q->where('subscription_expires_at', '>', now())
                    ->orWhere('trial_ends_at', '>', now());
            })->count();

        $expiredMerchants = Merchant::where(function ($q) {
            $q->where('status', '!=', 'active')
                ->orWhere(function ($sub) {
                    $sub->where(function ($s) {
                        $s->whereNull('subscription_expires_at')
                            ->orWhere('subscription_expires_at', '<=', now());
                    })->where(function ($s) {
                        $s->whereNull('trial_ends_at')
                            ->orWhere('trial_ends_at', '<=', now());
                    });
                });
        })->count();

        $totalRevenue = (float) Order::where('status', 'paid')->sum('total_amount');
        $waitingApprovalCount = Order::where('status', 'waiting_approval')->count();
        $pendingOrdersCount = Order::where('status', 'pending')->count();
        $totalOrders = Order::count();
        $totalUsers = User::count();

        // Recent orders that need attention (waiting_approval first, then pending/paid)
        $recentOrders = Order::with(['user', 'approver', 'items.merchant', 'items.package'])
            ->orderByRaw("CASE WHEN status = 'waiting_approval' THEN 1 WHEN status = 'pending' THEN 2 ELSE 3 END")
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get();

        // Recent stores registered
        $recentMerchants = Merchant::with(['businessType', 'users'])
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => [
                    'total_revenue' => $totalRevenue,
                    'waiting_approval_count' => $waitingApprovalCount,
                    'pending_orders_count' => $pendingOrdersCount,
                    'total_merchants' => $totalMerchants,
                    'active_merchants' => $activeMerchants,
                    'expired_merchants' => $expiredMerchants,
                    'total_users' => $totalUsers,
                    'total_orders' => $totalOrders,
                ],
                'recent_orders' => OrderResource::collection($recentOrders),
                'recent_merchants' => $recentMerchants,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * List all platform orders with server-side filters, search, and tab counts.
     */
    public function orders(Request $request): JsonResponse
    {
        $status = $request->query('status', 'all');
        $search = trim($request->query('search', ''));
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));

        $baseQuery = Order::query();

        // Counts for tabs
        $counts = [
            'all' => (clone $baseQuery)->count(),
            'waiting_approval' => (clone $baseQuery)->where('status', 'waiting_approval')->count(),
            'paid' => (clone $baseQuery)->where('status', 'paid')->count(),
            'rejected' => (clone $baseQuery)->where('status', 'rejected')->count(),
            'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
            'cancelled' => (clone $baseQuery)->where('status', 'cancelled')->count(),
        ];

        $query = $baseQuery->with(['user', 'approver', 'items.merchant', 'items.package'])
            ->orderByRaw("CASE WHEN status = 'waiting_approval' THEN 1 WHEN status = 'pending' THEN 2 ELSE 3 END")
            ->orderBy('created_at', 'desc');

        if ($status && in_array($status, ['waiting_approval', 'paid', 'rejected', 'pending', 'cancelled'])) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })
                    ->orWhereHas('items.merchant', function ($mq) use ($search) {
                        $mq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $page = max(1, (int) $request->query('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => OrderResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'counts' => $counts,
        ], Response::HTTP_OK);
    }

    /**
     * Approve an order, extend target merchant subscriptions, and activate stores.
     */
    public function approveOrder(Request $request, Order $order): JsonResponse
    {
        if ($order->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan ini sudah disetujui sebelumnya.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $admin = $request->user();

        $result = DB::transaction(function () use ($order, $admin) {
            $order->update([
                'status' => 'paid',
                'approved_by' => $admin->id,
                'approved_at' => now(),
                'paid_at' => now(),
                'rejection_reason' => null,
            ]);

            $activatedStores = [];
            foreach ($order->items as $item) {
                $merchant = $item->merchant;
                if ($merchant) {
                    $oldExpiry = $merchant->subscription_expires_at?->copy();
                    $merchant->extendSubscription($item->duration_in_days);

                    $activatedStores[] = [
                        'merchant_id' => $merchant->id,
                        'name' => $merchant->name,
                        'added_days' => $item->duration_in_days,
                        'previous_expires_at' => $oldExpiry?->toIso8601String(),
                        'new_expires_at' => $merchant->subscription_expires_at?->toIso8601String(),
                        'status' => $merchant->status,
                    ];
                }
            }

            return $activatedStores;
        });

        $order->load(['user', 'approver', 'items.merchant', 'items.package']);

        return response()->json([
            'success' => true,
            'message' => 'Pesanan berhasil disetujui! Paket dan masa aktif toko telah diaktifkan.',
            'data' => [
                'order' => new OrderResource($order),
                'activated_stores' => $result,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Reject an order with a specific reason.
     */
    public function rejectOrder(Request $request, Order $order): JsonResponse
    {
        if ($order->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan yang sudah lunas/disetujui tidak dapat ditolak.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ]);

        $admin = $request->user();

        $order->update([
            'status' => 'rejected',
            'rejection_reason' => $validated['reason'],
            'approved_by' => $admin->id,
            'approved_at' => now(),
        ]);

        $order->load(['user', 'approver', 'items.merchant', 'items.package']);

        return response()->json([
            'success' => true,
            'message' => 'Pesanan berhasil ditolak dengan alasan yang tercatat.',
            'data' => new OrderResource($order),
        ], Response::HTTP_OK);
    }

    /**
     * List all merchants with subscription status, owner details, and search.
     */
    public function merchants(Request $request): JsonResponse
    {
        $status = $request->query('status', 'all');
        $search = trim($request->query('search', ''));
        $businessTypeId = $request->query('business_type_id');
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));

        $baseQuery = Merchant::query();

        // Calculate counts
        $counts = [
            'all' => (clone $baseQuery)->count(),
            'active' => (clone $baseQuery)->where('status', 'active')
                ->where(function ($q) {
                    $q->where('subscription_expires_at', '>', now())
                        ->orWhere('trial_ends_at', '>', now());
                })->count(),
            'suspended' => (clone $baseQuery)->where('status', 'suspended')->count(),
            'expired' => (clone $baseQuery)->where(function ($q) {
                $q->where('status', '!=', 'active')
                    ->orWhere(function ($sub) {
                        $sub->where(function ($s) {
                            $s->whereNull('subscription_expires_at')
                                ->orWhere('subscription_expires_at', '<=', now());
                        })->where(function ($s) {
                            $s->whereNull('trial_ends_at')
                                ->orWhere('trial_ends_at', '<=', now());
                        });
                    });
            })->count(),
        ];

        $query = $baseQuery->with(['businessType', 'users'])
            ->orderBy('created_at', 'desc');

        if ($businessTypeId) {
            $query->where('business_type_id', $businessTypeId);
        }

        if ($status === 'active') {
            $query->where('status', 'active')
                ->where(function ($q) {
                    $q->where('subscription_expires_at', '>', now())
                        ->orWhere('trial_ends_at', '>', now());
                });
        } elseif ($status === 'suspended') {
            $query->where('status', 'suspended');
        } elseif ($status === 'expired') {
            $query->where(function ($q) {
                $q->where('status', '!=', 'active')
                    ->orWhere(function ($sub) {
                        $sub->where(function ($s) {
                            $s->whereNull('subscription_expires_at')
                                ->orWhere('subscription_expires_at', '<=', now());
                        })->where(function ($s) {
                            $s->whereNull('trial_ends_at')
                                ->orWhere('trial_ends_at', '<=', now());
                        });
                    });
            });
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhereHas('users', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $page = max(1, (int) $request->query('page', 1));
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Augment items with has_active_access flag
        $transformed = collect($paginator->items())->map(function ($merchant) {
            return [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'slug' => $merchant->slug,
                'status' => $merchant->status,
                'business_type' => $merchant->businessType,
                'users' => $merchant->users->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->pivot->role ?? 'member',
                ]),
                'trial_ends_at' => $merchant->trial_ends_at?->toIso8601String(),
                'subscription_expires_at' => $merchant->subscription_expires_at?->toIso8601String(),
                'has_active_access' => $merchant->hasActiveAccess(),
                'days_remaining' => $merchant->subscription_expires_at && $merchant->subscription_expires_at->isFuture()
                    ? now()->diffInDays($merchant->subscription_expires_at, false)
                    : ($merchant->trial_ends_at && $merchant->trial_ends_at->isFuture()
                        ? now()->diffInDays($merchant->trial_ends_at, false)
                        : 0),
                'created_at' => $merchant->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $transformed,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
            'counts' => $counts,
        ], Response::HTTP_OK);
    }

    /**
     * Manually grant / extend subscription days for a merchant.
     */
    public function extendMerchant(Request $request, Merchant $merchant): JsonResponse
    {
        $validated = $request->validate([
            'days' => 'required|integer|min:1|max:3650',
            'reason' => 'nullable|string|max:500',
        ]);

        $days = (int) $validated['days'];
        $oldExpiry = $merchant->subscription_expires_at?->copy();

        $merchant->extendSubscription($days);

        return response()->json([
            'success' => true,
            'message' => "Masa aktif toko '{$merchant->name}' berhasil diperpanjang +{$days} hari.",
            'data' => [
                'merchant_id' => $merchant->id,
                'name' => $merchant->name,
                'previous_expires_at' => $oldExpiry?->toIso8601String(),
                'new_expires_at' => $merchant->subscription_expires_at?->toIso8601String(),
                'status' => $merchant->status,
                'has_active_access' => $merchant->hasActiveAccess(),
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Suspend or activate a merchant.
     */
    public function toggleMerchantStatus(Request $request, Merchant $merchant): JsonResponse
    {
        $newStatus = $merchant->status === 'active' ? 'suspended' : 'active';
        $merchant->update(['status' => $newStatus]);

        $statusLabel = $newStatus === 'active' ? 'diaktifkan kembali' : 'disuspend (dinonaktifkan)';

        return response()->json([
            'success' => true,
            'message' => "Toko '{$merchant->name}' berhasil {$statusLabel}.",
            'data' => [
                'merchant_id' => $merchant->id,
                'name' => $merchant->name,
                'status' => $merchant->status,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * List all platform SaaS packages.
     */
    public function packages(): JsonResponse
    {
        $packages = Package::with('businessType')
            ->orderBy('business_type_id')
            ->orderBy('price')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $packages,
        ], Response::HTTP_OK);
    }

    /**
     * Create a new SaaS platform package.
     */
    public function storePackage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_type_id' => 'required|exists:business_types,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:packages,code',
            'price' => 'required|numeric|min:0',
            'duration_in_days' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'features' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $package = Package::create($validated);
        $package->load('businessType');

        return response()->json([
            'success' => true,
            'message' => 'Paket SaaS berhasil ditambahkan.',
            'data' => $package,
        ], Response::HTTP_CREATED);
    }

    /**
     * Update an existing SaaS platform package.
     */
    public function updatePackage(Request $request, Package $package): JsonResponse
    {
        $validated = $request->validate([
            'business_type_id' => 'required|exists:business_types,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:packages,code,' . $package->id,
            'price' => 'required|numeric|min:0',
            'duration_in_days' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'features' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $package->update($validated);
        $package->load('businessType');

        return response()->json([
            'success' => true,
            'message' => 'Paket SaaS berhasil diperbarui.',
            'data' => $package,
        ], Response::HTTP_OK);
    }

    /**
     * Toggle package active status.
     */
    public function togglePackageStatus(Package $package): JsonResponse
    {
        $package->update([
            'is_active' => ! $package->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status paket berhasil diperbarui.',
            'data' => $package,
        ], Response::HTTP_OK);
    }
}
