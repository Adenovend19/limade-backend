<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\OrderResource;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class MerchantController extends Controller
{
    /**
     * List all merchants the authenticated user belongs to.
     */
    public function index(Request $request): JsonResponse
    {
        $merchants = $request->user()->merchants()->with('businessType')->get();

        return response()->json([
            'success' => true,
            'data' => MerchantResource::collection($merchants),
        ], Response::HTTP_OK);
    }

    /**
     * Store a newly created merchant.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_type_id' => ['required', 'exists:business_types,id'],
            'package_id' => ['required', 'exists:packages,id'],
        ]);

        $user = $request->user();

        // Check if user already owns 10+ merchants
        if ($user->merchants()->wherePivot('role', 'owner')->count() >= 10) {
            return response()->json([
                'success' => false,
                'message' => 'Anda telah mencapai batas maksimal pendaftaran toko (10 toko per akun pemilik).',
            ], Response::HTTP_FORBIDDEN);
        }

        $package = Package::findOrFail($validated['package_id']);
        if ($package->business_type_id !== (int) $validated['business_type_id']) {
            return response()->json([
                'success' => false,
                'message' => 'Paket langganan tidak sesuai dengan tipe bisnis yang dipilih.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $merchant = DB::transaction(function () use ($validated, $user) {
            $baseSlug = Str::slug($validated['name']);
            $slug = $baseSlug;
            $counter = 1;
            while (Merchant::where('slug', $slug)->exists()) {
                $slug = "{$baseSlug}-{$counter}";
                $counter++;
            }

            $m = Merchant::create([
                'name' => $validated['name'],
                'slug' => $slug,
                'business_type_id' => $validated['business_type_id'],
                'status' => 'pending_payment',
            ]);

            $m->users()->attach($user->id, ['role' => 'owner']);

            return $m;
        });

        // Create pending order for selected package
        $order = Order::create([
            'user_id' => $user->id,
            'invoice_number' => 'INV-'.date('YmdHis').'-'.strtoupper(Str::random(4)),
            'total_amount' => $package->price,
            'status' => 'pending',
        ]);

        $order->items()->create([
            'merchant_id' => $merchant->id,
            'package_id' => $package->id,
            'package_name' => $package->name,
            'duration_in_days' => $package->duration_in_days,
            'price' => $package->price,
        ]);

        $merchant->load(['businessType', 'users']);

        return response()->json([
            'success' => true,
            'message' => "Toko '{$merchant->name}' berhasil didaftarkan. Silakan selesaikan pembayaran tagihan untuk mengaktifkan akses operasional.",
            'data' => [
                'merchant' => new MerchantResource($merchant),
                'order' => new OrderResource($order->load('items.package')),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Display the specified merchant.
     */
    public function show(Request $request, Merchant $merchant): JsonResponse
    {
        $membership = $request->user()->merchants()
            ->where('merchants.id', $merchant->id)
            ->first();

        if (! $membership) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses ke toko ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        $merchant->load(['businessType', 'users']);

        return response()->json([
            'success' => true,
            'data' => new MerchantResource($merchant),
        ], Response::HTTP_OK);
    }

    /**
     * Add a member or staff to the merchant.
     */
    public function addMember(Request $request, Merchant $merchant): JsonResponse
    {
        $currentMembership = $request->user()->merchants()
            ->where('merchants.id', $merchant->id)
            ->first();

        $allowedManagerRoles = ['owner', 'admin', 'pos_manager', 'billing_admin', 'quotation_admin'];
        if (! $currentMembership || ! in_array($currentMembership->pivot->role, $allowedManagerRoles)) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pemilik toko atau administrator yang dapat menambahkan staf.',
            ], Response::HTTP_FORBIDDEN);
        }

        $availableRoleCodes = \App\Models\BusinessTypeRole::where('business_type_id', $merchant->business_type_id)
            ->pluck('code')
            ->toArray();
        $availableRoleCodes[] = 'owner';

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:25'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'string', \Illuminate\Validation\Rule::in($availableRoleCodes)],
        ], [
            'name.required' => 'Nama lengkap staf wajib diisi.',
            'email.required' => 'Alamat email staf wajib diisi.',
            'email.email' => 'Format email tidak valid.',
            'password.required' => 'Password akun staf wajib diisi.',
            'password.min' => 'Password minimal terdiri dari 6 karakter.',
            'role.in' => 'Peran yang dipilih tidak valid untuk tipe bisnis cabang ini.',
        ]);

        $targetUser = User::firstOrCreate(
            ['email' => $validated['email']],
            [
                'name' => $validated['name'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
            ]
        );

        // Update name and phone if provided and not newly created
        if (! $targetUser->wasRecentlyCreated && ! empty($validated['name'])) {
            $updateData = ['name' => $validated['name']];
            if (! empty($validated['phone'])) {
                $updateData['phone'] = $validated['phone'];
            }
            $targetUser->update($updateData);
        }

        // Sync or attach
        $merchant->users()->syncWithoutDetaching([
            $targetUser->id => ['role' => $validated['role']],
        ]);

        $statusMsg = $targetUser->wasRecentlyCreated
            ? "Akun baru untuk {$targetUser->name} ({$targetUser->email}) berhasil dibuat dan ditugaskan sebagai {$validated['role']} di {$merchant->name}."
            : "Staf {$targetUser->name} ({$targetUser->email}) berhasil ditugaskan sebagai {$validated['role']} di {$merchant->name}.";

        return response()->json([
            'success' => true,
            'message' => $statusMsg,
            'data' => [
                'user_id' => $targetUser->id,
                'name' => $targetUser->name,
                'email' => $targetUser->email,
                'phone' => $targetUser->phone,
                'role' => $validated['role'],
                'was_created' => $targetUser->wasRecentlyCreated,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Update member profile, role, or transfer to another merchant.
     */
    public function updateMember(Request $request, Merchant $merchant, User $user): JsonResponse
    {
        $currentMembership = $request->user()->merchants()
            ->where('merchants.id', $merchant->id)
            ->first();

        $allowedManagerRoles = ['owner', 'admin', 'pos_manager', 'billing_admin', 'quotation_admin'];
        if (! $currentMembership || ! in_array($currentMembership->pivot->role, $allowedManagerRoles)) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pemilik toko atau administrator yang dapat mengubah data staf.',
            ], Response::HTTP_FORBIDDEN);
        }

        // Prevent modifying owner themselves via member route
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Gunakan halaman profil untuk memperbarui akun Owner Anda sendiri.',
            ], Response::HTTP_FORBIDDEN);
        }

        $targetMerchantId = $request->input('target_merchant_id', $merchant->id);
        $targetMerchant = Merchant::findOrFail($targetMerchantId);

        // Verify owner has access to target merchant
        $hasTargetAccess = $request->user()->merchants()
            ->where('merchants.id', $targetMerchant->id)
            ->whereIn('merchant_user.role', ['owner', 'admin'])
            ->exists();

        if (! $hasTargetAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki hak akses pemilik pada cabang toko tujuan.',
            ], Response::HTTP_FORBIDDEN);
        }

        $availableRoleCodes = \App\Models\BusinessTypeRole::where('business_type_id', $targetMerchant->business_type_id)
            ->pluck('code')
            ->toArray();
        $availableRoleCodes[] = 'owner';

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:25'],
            'role' => ['required', 'string', \Illuminate\Validation\Rule::in($availableRoleCodes)],
            'password' => ['nullable', 'string', 'min:6'],
            'target_merchant_id' => ['nullable', 'exists:merchants,id'],
        ], [
            'name.required' => 'Nama lengkap staf wajib diisi.',
            'role.in' => 'Peran yang dipilih tidak sesuai dengan tipe bisnis cabang tujuan.',
            'password.min' => 'Password baru minimal 6 karakter.',
        ]);

        // Update user profile
        $userUpdate = ['name' => $validated['name']];
        if (array_key_exists('phone', $validated)) {
            $userUpdate['phone'] = $validated['phone'];
        }
        if (! empty($validated['password'])) {
            $userUpdate['password'] = Hash::make($validated['password']);
        }
        $user->update($userUpdate);

        // If transferring branch
        if ($targetMerchant->id !== $merchant->id) {
            // Remove from source merchant
            DB::table('merchant_user')
                ->where('merchant_id', $merchant->id)
                ->where('user_id', $user->id)
                ->delete();

            // Attach to target merchant
            DB::table('merchant_user')->updateOrInsert(
                ['merchant_id' => $targetMerchant->id, 'user_id' => $user->id],
                [
                    'role' => $validated['role'],
                    'deleted_at' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $msg = "Staf {$user->name} berhasil dipindahkan ke toko {$targetMerchant->name} dengan peran {$validated['role']}.";
        } else {
            // Update role in same merchant & ensure active
            DB::table('merchant_user')
                ->where('merchant_id', $merchant->id)
                ->where('user_id', $user->id)
                ->update([
                    'role' => $validated['role'],
                    'deleted_at' => null,
                    'updated_at' => now(),
                ]);

            $msg = "Data dan peran staf {$user->name} berhasil diperbarui.";
        }

        return response()->json([
            'success' => true,
            'message' => $msg,
        ], Response::HTTP_OK);
    }

    /**
     * Revoke staff access from merchant (soft delete).
     */
    public function removeMember(Request $request, Merchant $merchant, User $user): JsonResponse
    {
        $currentMembership = $request->user()->merchants()
            ->where('merchants.id', $merchant->id)
            ->first();

        if (! $currentMembership || ! in_array($currentMembership->pivot->role, ['owner', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pemilik toko yang dapat mencabut akses staf.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak dapat mencabut akses akun Owner Anda sendiri.',
            ], Response::HTTP_FORBIDDEN);
        }

        DB::table('merchant_user')
            ->where('merchant_id', $merchant->id)
            ->where('user_id', $user->id)
            ->update([
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => "Akses staf {$user->name} ke toko {$merchant->name} berhasil dicabut.",
        ], Response::HTTP_OK);
    }

    /**
     * Restore revoked staff access to merchant.
     */
    public function restoreMember(Request $request, Merchant $merchant, User $user): JsonResponse
    {
        $currentMembership = $request->user()->merchants()
            ->where('merchants.id', $merchant->id)
            ->first();

        if (! $currentMembership || ! in_array($currentMembership->pivot->role, ['owner', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Hanya pemilik toko yang dapat memulihkan akses staf.',
            ], Response::HTTP_FORBIDDEN);
        }

        DB::table('merchant_user')
            ->where('merchant_id', $merchant->id)
            ->where('user_id', $user->id)
            ->update([
                'deleted_at' => null,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => "Akses staf {$user->name} di toko {$merchant->name} berhasil diaktifkan kembali.",
        ], Response::HTTP_OK);
    }

    /**
     * Select a new package for a merchant (e.g. after cancelling a previous pending order or renewing).
     */
    public function selectPackage(Request $request, Merchant $merchant): JsonResponse
    {
        $membership = $request->user()->merchants()
            ->where('merchants.id', $merchant->id)
            ->first();

        if (! $membership || ! in_array($membership->pivot->role, ['owner', 'admin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Only merchant owner or admin can select subscription packages.',
            ], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'package_id' => ['required', 'exists:packages,id'],
        ]);

        $package = Package::findOrFail($validated['package_id']);
        if ($package->business_type_id !== $merchant->business_type_id) {
            return response()->json([
                'success' => false,
                'message' => 'The selected package does not match the merchant business type.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Cancel any existing pending orders for this merchant
        $existingPendingOrders = Order::where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->whereHas('items', function ($query) use ($merchant) {
                $query->where('merchant_id', $merchant->id);
            })
            ->get();

        foreach ($existingPendingOrders as $oldOrder) {
            $oldOrder->status = 'cancelled';
            $oldOrder->save();
        }

        // Bonus trial is added if the merchant has never had active access / trial before
        $bonusDays = ($merchant->status === 'pending_payment' && ! $merchant->trial_ends_at) ? 14 : 0;
        $totalDuration = $package->duration_in_days + $bonusDays;
        $invoiceNumber = 'INV-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));

        $order = Order::create([
            'user_id' => $request->user()->id,
            'invoice_number' => $invoiceNumber,
            'total_amount' => $package->price,
            'status' => 'pending',
        ]);

        $order->items()->create([
            'package_id' => $package->id,
            'merchant_id' => $merchant->id,
            'package_name' => $package->name,
            'duration_in_days' => $totalDuration,
            'price' => $package->price,
        ]);

        $merchant->load('businessType');
        $order->load(['items.merchant', 'items.package']);

        return response()->json([
            'success' => true,
            'message' => 'New package order created successfully.',
            'data' => [
                'merchant' => new MerchantResource($merchant),
                'order' => new OrderResource($order),
                'payment_action' => [
                    'method' => 'POST',
                    'url' => url("/api/v1/orders/{$order->id}/pay"),
                ],
            ],
        ], Response::HTTP_CREATED);
    }
}
