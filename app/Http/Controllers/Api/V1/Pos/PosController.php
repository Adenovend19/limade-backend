<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PosController extends Controller
{
    /**
     * Get POS module status for active merchant.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');

        return response()->json([
            'success' => true,
            'data' => [
                'merchant_id' => $merchant->id,
                'merchant_name' => $merchant->name,
                'module' => 'pos',
                'is_ready' => true,
                'features' => [
                    'cashier_terminal',
                    'thermal_receipt',
                    'qris_payment',
                    'cash_payment',
                    'quick_catalog',
                ],
            ],
        ], Response::HTTP_OK);
    }
}
