<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_super_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Akses ditolak. Tindakan ini hanya dapat dilakukan oleh Super Admin Limade.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
