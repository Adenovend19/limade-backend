<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantServicePackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantPackageController extends Controller
{
    /**
     * List all service packages for active merchant.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        $packages = MerchantServicePackage::where('merchant_id', $merchant->id)
            ->withCount(['customerAssignments as active_customers_count' => function ($q) {
                $q->where('status', 'active');
            }])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'packages' => $packages,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Store a new service package.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');
        $role = $request->attributes->get('active_merchant_role');
        if (! in_array(strtolower((string) $role), ['owner', 'admin', 'billing_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang dapat mengelola paket layanan toko.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'price' => ['required', 'integer', 'min:0'],
            'billing_period' => ['nullable', 'string', 'in:monthly,quarterly,yearly'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $package = MerchantServicePackage::create([
            'merchant_id' => $merchant->id,
            'name' => $validated['name'],
            'code' => $validated['code'] ?? null,
            'price' => $validated['price'],
            'billing_period' => $validated['billing_period'] ?? 'monthly',
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return response()->json([
            'success' => true,
            'message' => "Paket layanan {$package->name} berhasil ditambahkan.",
            'data' => $package,
        ], Response::HTTP_CREATED);
    }

    /**
     * Show package details.
     */
    public function show(Request $request, MerchantServicePackage $package): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if ($package->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Paket layanan tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $package->loadCount(['customerAssignments as active_customers_count' => function ($q) {
            $q->where('status', 'active');
        }]);

        return response()->json([
            'success' => true,
            'data' => [
                'package' => $package,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Update package details.
     */
    public function update(Request $request, MerchantServicePackage $package): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');
        $role = $request->attributes->get('active_merchant_role');
        if (! in_array(strtolower((string) $role), ['owner', 'admin', 'billing_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang dapat mengelola paket layanan toko.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($package->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Paket layanan tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50'],
            'price' => ['required', 'integer', 'min:0'],
            'billing_period' => ['nullable', 'string', 'in:monthly,quarterly,yearly'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $package->update($validated);

        return response()->json([
            'success' => true,
            'message' => "Paket layanan {$package->name} berhasil diperbarui.",
            'data' => $package,
        ], Response::HTTP_OK);
    }

    /**
     * Delete package.
     */
    public function destroy(Request $request, MerchantServicePackage $package): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');
        $role = $request->attributes->get('active_merchant_role');
        if (! in_array(strtolower((string) $role), ['owner', 'admin', 'billing_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang dapat mengelola paket layanan toko.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($package->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Paket layanan tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $packageName = $package->name;
        $package->delete();

        return response()->json([
            'success' => true,
            'message' => "Paket layanan {$packageName} berhasil dihapus.",
        ], Response::HTTP_OK);
    }

    /**
     * Toggle active/inactive status of package.
     */
    public function toggleStatus(Request $request, MerchantServicePackage $package): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');
        $role = $request->attributes->get('active_merchant_role');
        if (! in_array(strtolower((string) $role), ['owner', 'admin', 'billing_admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya Owner atau Admin yang dapat mengelola paket layanan toko.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($package->merchant_id !== $merchant->id) {
            return response()->json([
                'success' => false,
                'message' => 'Paket layanan tidak ditemukan pada toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $package->update([
            'is_active' => !$package->is_active,
        ]);

        $statusText = $package->is_active ? 'diaktifkan' : 'dinonaktifkan';

        return response()->json([
            'success' => true,
            'message' => "Paket layanan {$package->name} berhasil {$statusText}.",
            'data' => $package,
        ], Response::HTTP_OK);
    }
}
