<?php

namespace App\Http\Controllers\Api\V1\Quotation;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class QuotationController extends Controller
{
    /**
     * Get Quotation module status for active merchant.
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
                'module' => 'quotation',
                'is_ready' => true,
                'features' => [
                    'proposal_builder',
                    'pipeline_tracking',
                    'approval_workflow',
                    'convert_to_invoice',
                ],
            ],
        ], Response::HTTP_OK);
    }
}
