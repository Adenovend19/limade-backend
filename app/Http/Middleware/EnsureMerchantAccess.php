<?php

namespace App\Http\Middleware;

use App\Models\Merchant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMerchantAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $requiredRole = null): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $merchantId = $request->header('X-Merchant-Id') ?? $request->input('merchant_id');

        if (! $merchantId) {
            return response()->json([
                'success' => false,
                'message' => 'Header X-Merchant-Id is required to access merchant context.',
            ], Response::HTTP_BAD_REQUEST);
        }

        $membership = $user->merchants()
            ->where('merchants.id', $merchantId)
            ->wherePivotNull('deleted_at')
            ->first();

        if (! $membership) {
            return response()->json([
                'success' => false,
                'message' => 'Akses Anda ke cabang toko ini tidak aktif atau telah dicabut oleh pemilik toko.',
            ], Response::HTTP_FORBIDDEN);
        }

        $role = $membership->pivot->role;

        if ($requiredRole && $role !== $requiredRole) {
            return response()->json([
                'success' => false,
                'message' => "This action requires '{$requiredRole}' role. Your role is '{$role}'.",
            ], Response::HTTP_FORBIDDEN);
        }

        // Attach merchant and role to request
        $request->attributes->set('active_merchant', $membership);
        $request->attributes->set('active_merchant_role', $role);

        return $next($request);
    }
}
