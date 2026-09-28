<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MerchantDashboardController extends Controller
{
    /**
     * Protected merchant operational dashboard / status.
     * Only accessible if user is a member of the merchant and subscription/trial is active.
     */
    public function dashboard(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('active_merchant');
        $role = $request->attributes->get('active_merchant_role');

        $merchant->load('businessType');

        return response()->json([
            'success' => true,
            'message' => "Welcome to {$merchant->name} operational workspace.",
            'data' => [
                'merchant' => [
                    'id' => $merchant->id,
                    'name' => $merchant->name,
                    'slug' => $merchant->slug,
                    'status' => $merchant->status,
                    'business_type' => [
                        'id' => $merchant->businessType->id,
                        'code' => $merchant->businessType->code,
                        'name' => $merchant->businessType->name,
                    ],
                    'user_role' => $role,
                    'trial_ends_at' => $merchant->trial_ends_at?->toIso8601String(),
                    'subscription_expires_at' => $merchant->subscription_expires_at?->toIso8601String(),
                    'has_active_access' => $merchant->hasActiveAccess(),
                ],
                'features_enabled' => match ($merchant->businessType->code) {
                    'pos' => ['cashier_terminal', 'inventory_management', 'daily_sales_report', 'receipt_printer'],
                    'billing' => ['create_invoice', 'client_ledger', 'payment_reminders', 'bank_reconciliation'],
                    'quotation' => ['create_quotation', 'pdf_export', 'client_signature', 'convert_to_invoice'],
                    default => ['general_reports'],
                },
            ],
        ], Response::HTTP_OK);
    }
}
