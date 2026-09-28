<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Cart;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class CheckoutController extends Controller
{
    /**
     * Checkout current cart items and create a pending order.
     */
    public function checkout(Request $request): JsonResponse
    {
        $cart = Cart::with(['items.package', 'items.merchant'])
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Your cart is empty.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $order = DB::transaction(function () use ($cart, $request) {
            $invoiceNumber = 'INV-'.now()->format('YmdHis').'-'.strtoupper(Str::random(4));
            $totalAmount = $cart->calculateTotal();

            $order = Order::create([
                'user_id' => $request->user()->id,
                'invoice_number' => $invoiceNumber,
                'total_amount' => $totalAmount,
                'status' => 'pending',
            ]);

            foreach ($cart->items as $item) {
                $totalDuration = $item->package->duration_in_days * $item->quantity;
                $itemTotal = $item->price * $item->quantity;

                $order->items()->create([
                    'package_id' => $item->package_id,
                    'merchant_id' => $item->merchant_id,
                    'package_name' => $item->package->name,
                    'duration_in_days' => $totalDuration,
                    'price' => $itemTotal,
                ]);
            }

            // Clear the cart after successfully creating order
            $cart->items()->delete();

            return $order;
        });

        $order->load(['items.merchant', 'items.package']);

        return response()->json([
            'success' => true,
            'message' => 'Order created successfully. Ready for payment.',
            'data' => [
                'order' => new OrderResource($order),
                'payment_action' => [
                    'method' => 'POST',
                    'url' => url("/api/v1/orders/{$order->id}/pay"),
                    'description' => 'Call this endpoint to simulate payment and automatically extend merchant active periods.',
                ],
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * List all orders of the authenticated user with server-side filtering & pagination.
     */
    public function orders(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $request->query('status', 'all');
        $perPage = min(50, max(1, (int) $request->query('per_page', 10)));

        $baseQuery = $user->orders();

        // Calculate counts for tabs
        $counts = [
            'all' => (clone $baseQuery)->count(),
            'pending' => (clone $baseQuery)->where('status', 'pending')->count(),
            'waiting_approval' => (clone $baseQuery)->where('status', 'waiting_approval')->count(),
            'paid' => (clone $baseQuery)->where('status', 'paid')->count(),
            'rejected' => (clone $baseQuery)->where('status', 'rejected')->count(),
            'cancelled' => (clone $baseQuery)->where('status', 'cancelled')->count(),
        ];

        $query = $baseQuery->with(['items.merchant', 'items.package'])
            ->orderBy('created_at', 'desc');

        if ($status && in_array($status, ['pending', 'waiting_approval', 'paid', 'rejected', 'cancelled'])) {
            $query->where('status', $status);
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
     * Show order details.
     */
    public function showOrder(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to order.',
            ], Response::HTTP_FORBIDDEN);
        }

        $order->load(['items.merchant.businessType', 'items.package']);

        return response()->json([
            'success' => true,
            'data' => new OrderResource($order),
        ], Response::HTTP_OK);
    }

    /**
     * Instant payment approval (Restricted to Super Admin Limade).
     * Regular store owners must submit confirmation via confirm-payment to await admin approval.
     */
    public function pay(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        if (! $user->is_super_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Pembayaran instan langsung oleh pemilik toko dinonaktifkan. Silakan kirim konfirmasi pembayaran untuk diverifikasi dan disetujui oleh Admin Limade.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($order->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan ini sudah disetujui sebelumnya.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $updatedMerchants = DB::transaction(function () use ($order) {
            $order->status = 'paid';
            $order->paid_at = now();
            $order->save();

            $merchantsSummary = [];

            foreach ($order->items as $item) {
                $merchant = $item->merchant;
                $oldExpiry = $merchant->subscription_expires_at?->copy();

                // Cumulatively extend subscription
                $merchant->extendSubscription($item->duration_in_days);

                $merchantsSummary[] = [
                    'merchant_id' => $merchant->id,
                    'merchant_name' => $merchant->name,
                    'added_days' => $item->duration_in_days,
                    'previous_expires_at' => $oldExpiry?->toIso8601String(),
                    'new_expires_at' => $merchant->subscription_expires_at?->toIso8601String(),
                    'status' => $merchant->status,
                ];
            }

            return $merchantsSummary;
        });

        $order->load(['items.merchant', 'items.package']);

        return response()->json([
            'success' => true,
            'message' => 'Payment successful! Merchant subscriptions have been extended.',
            'data' => [
                'order' => new OrderResource($order),
                'activated_merchants' => $updatedMerchants,
            ],
        ], Response::HTTP_OK);
    }

    /**
     * Cancel a pending order.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to order.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending orders can be cancelled.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $order->status = 'cancelled';
        $order->save();

        $order->load(['items.merchant', 'items.package']);

        return response()->json([
            'success' => true,
            'message' => 'Order has been cancelled.',
            'data' => new OrderResource($order),
        ], Response::HTTP_OK);
    }

    /**
     * Submit payment confirmation / proof for an order to await admin approval.
     */
    public function confirmPayment(Request $request, Order $order): JsonResponse
    {
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to order.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($order->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => 'Pesanan ini sudah lunas.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $validated = $request->validate([
            'payment_method' => 'required|string|max:50',
            'payment_proof' => 'nullable|string',
            'payment_notes' => 'nullable|string|max:1000',
        ]);

        if ($request->hasFile('payment_proof_file')) {
            $file = $request->file('payment_proof_file');
            $uploadDir = public_path('uploads/proofs');
            if (! file_exists($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
            $file->move($uploadDir, $filename);
            $validated['payment_proof'] = '/uploads/proofs/' . $filename;
        }

        $order->update([
            'status' => 'waiting_approval',
            'payment_method' => $validated['payment_method'],
            'payment_proof' => $validated['payment_proof'] ?? $order->payment_proof,
            'payment_notes' => $validated['payment_notes'] ?? $order->payment_notes,
            'rejection_reason' => null,
        ]);

        $order->load(['items.merchant', 'items.package']);

        return response()->json([
            'success' => true,
            'message' => 'Konfirmasi pembayaran berhasil dikirim! Menunggu persetujuan Admin Limade.',
            'data' => new OrderResource($order),
        ], Response::HTTP_OK);
    }
}

