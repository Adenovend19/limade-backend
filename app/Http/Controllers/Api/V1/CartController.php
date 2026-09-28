<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Merchant;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CartController extends Controller
{
    /**
     * Get or create current user's cart.
     */
    protected function getOrCreateUserCart(Request $request): Cart
    {
        return Cart::firstOrCreate(['user_id' => $request->user()->id]);
    }

    /**
     * View user's cart.
     */
    public function index(Request $request): JsonResponse
    {
        $cart = $this->getOrCreateUserCart($request);
        $cart->load(['items.package.businessType', 'items.merchant.businessType']);

        return response()->json([
            'success' => true,
            'data' => new CartResource($cart),
        ], Response::HTTP_OK);
    }

    /**
     * Add a package for a specific merchant into the user's cart.
     */
    public function addItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'package_id' => ['required', 'exists:packages,id'],
            'merchant_id' => ['required', 'exists:merchants,id'],
            'quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $quantity = $validated['quantity'] ?? 1;

        // Verify user has access to the target merchant
        $hasAccess = $request->user()->merchants()
            ->where('merchants.id', $validated['merchant_id'])
            ->exists();

        if (! $hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have access to purchase for this merchant.',
            ], Response::HTTP_FORBIDDEN);
        }

        $package = Package::findOrFail($validated['package_id']);
        $merchant = Merchant::findOrFail($validated['merchant_id']);

        // Verify package matches merchant's business type
        if ($package->business_type_id !== $merchant->business_type_id) {
            return response()->json([
                'success' => false,
                'message' => 'This package is not compatible with the merchant business type.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $cart = $this->getOrCreateUserCart($request);

        $cartItem = CartItem::where('cart_id', $cart->id)
            ->where('package_id', $package->id)
            ->where('merchant_id', $merchant->id)
            ->first();

        if ($cartItem) {
            $cartItem->increment('quantity', $quantity);
        } else {
            $cart->items()->create([
                'package_id' => $package->id,
                'merchant_id' => $merchant->id,
                'quantity' => $quantity,
                'price' => $package->price,
            ]);
        }

        $cart->load(['items.package.businessType', 'items.merchant.businessType']);

        return response()->json([
            'success' => true,
            'message' => 'Package added to cart successfully.',
            'data' => new CartResource($cart),
        ], Response::HTTP_OK);
    }

    /**
     * Remove an item from the cart.
     */
    public function removeItem(Request $request, CartItem $cartItem): JsonResponse
    {
        if ($cartItem->cart->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized action.',
            ], Response::HTTP_FORBIDDEN);
        }

        $cart = $cartItem->cart;
        $cartItem->delete();

        $cart->load(['items.package.businessType', 'items.merchant.businessType']);

        return response()->json([
            'success' => true,
            'message' => 'Item removed from cart.',
            'data' => new CartResource($cart),
        ], Response::HTTP_OK);
    }

    /**
     * Clear all items in the user's cart.
     */
    public function clear(Request $request): JsonResponse
    {
        $cart = $this->getOrCreateUserCart($request);
        $cart->items()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Cart cleared successfully.',
        ], Response::HTTP_OK);
    }
}
