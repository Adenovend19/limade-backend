<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Merchant|null $merchant */
        $merchant = $request->attributes->get('active_merchant');

        if (! $merchant) {
            $merchantId = $request->header('X-Merchant-Id') ?? $request->input('merchant_id');
            if ($merchantId) {
                $merchant = Merchant::find($merchantId);
            }
        }

        if (! $merchant) {
            return response()->json([
                'success' => false,
                'message' => 'Active merchant not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        if (! $merchant->hasActiveAccess()) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant subscription or trial has expired. Please purchase a package to continue using this service.',
                'data' => [
                    'merchant_id' => $merchant->id,
                    'merchant_name' => $merchant->name,
                    'status' => $merchant->status,
                    'trial_ends_at' => $merchant->trial_ends_at?->toIso8601String(),
                    'subscription_expires_at' => $merchant->subscription_expires_at?->toIso8601String(),
                ],
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        return $next($request);
    }
}
