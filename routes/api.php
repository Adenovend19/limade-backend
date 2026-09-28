<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BusinessTypeController;
use App\Http\Controllers\Api\V1\BusinessTypeRoleController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\MerchantController;
use App\Http\Controllers\Api\V1\MerchantDashboardController;
use App\Http\Controllers\Api\V1\PackageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Public Authentication
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Public Catalog (Business Types, Roles & Packages)
    Route::get('/business-types', [BusinessTypeController::class, 'index']);
    Route::get('/business-types/{businessType}', [BusinessTypeController::class, 'show']);
    Route::get('/business-types/{businessType}/roles', [BusinessTypeRoleController::class, 'index']);
    Route::get('/packages', [PackageController::class, 'index']);
    Route::get('/packages/{package}', [PackageController::class, 'show']);

    // Authenticated User Routes
    Route::middleware('auth:sanctum')->group(function () {

        // Limade Platform Staff / Admin: Manage Master Roles for Business Types
        Route::post('/business-types/{businessType}/roles', [BusinessTypeRoleController::class, 'store']);
        Route::put('/business-types/{businessType}/roles/{role}', [BusinessTypeRoleController::class, 'update']);
        Route::delete('/business-types/{businessType}/roles/{role}', [BusinessTypeRoleController::class, 'destroy']);

        // User Profile & Authentication
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/profile', [AuthController::class, 'updateProfile']);
        Route::put('/auth/password', [AuthController::class, 'updatePassword']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Merchant Management
        Route::get('/merchants', [MerchantController::class, 'index']);
        Route::post('/merchants', [MerchantController::class, 'store']);
        Route::get('/merchants/{merchant}', [MerchantController::class, 'show']);
        Route::post('/merchants/{merchant}/members', [MerchantController::class, 'addMember']);
        Route::put('/merchants/{merchant}/members/{user}', [MerchantController::class, 'updateMember']);
        Route::delete('/merchants/{merchant}/members/{user}', [MerchantController::class, 'removeMember']);
        Route::post('/merchants/{merchant}/members/{user}/restore', [MerchantController::class, 'restoreMember']);
        Route::post('/merchants/{merchant}/package', [MerchantController::class, 'selectPackage']);

        // Cart Management
        Route::get('/cart', [CartController::class, 'index']);
        Route::post('/cart/items', [CartController::class, 'addItem']);
        Route::delete('/cart/items/{cartItem}', [CartController::class, 'removeItem']);
        Route::delete('/cart', [CartController::class, 'clear']);

        // Orders & Checkout
        Route::post('/checkout', [CheckoutController::class, 'checkout']);
        Route::get('/orders', [CheckoutController::class, 'orders']);
        Route::get('/orders/{order}', [CheckoutController::class, 'showOrder']);
        Route::post('/orders/{order}/pay', [CheckoutController::class, 'pay']);
        Route::post('/orders/{order}/confirm-payment', [CheckoutController::class, 'confirmPayment']);
        Route::post('/orders/{order}/cancel', [CheckoutController::class, 'cancel']);

        // Limade Super Admin Portal
        Route::prefix('admin')->middleware('super_admin')->group(function () {
            Route::get('/dashboard', [AdminController::class, 'dashboard']);
            Route::get('/orders', [AdminController::class, 'orders']);
            Route::post('/orders/{order}/approve', [AdminController::class, 'approveOrder']);
            Route::post('/orders/{order}/reject', [AdminController::class, 'rejectOrder']);
            Route::get('/merchants', [AdminController::class, 'merchants']);
            Route::post('/merchants/{merchant}/extend', [AdminController::class, 'extendMerchant']);
            Route::post('/merchants/{merchant}/toggle-status', [AdminController::class, 'toggleMerchantStatus']);
            Route::get('/packages', [AdminController::class, 'packages']);
            Route::post('/packages', [AdminController::class, 'storePackage']);
            Route::put('/packages/{package}', [AdminController::class, 'updatePackage']);
            Route::patch('/packages/{package}/toggle', [AdminController::class, 'togglePackageStatus']);
        });

        // Owner Module (Executive multi-store summary & team)
        Route::prefix('owner')->group(function () {
            Route::get('/summary', [\App\Http\Controllers\Api\V1\Owner\OwnerController::class, 'summary']);
            Route::get('/team', [\App\Http\Controllers\Api\V1\Owner\OwnerController::class, 'teamMembers']);
        });

        // Protected Merchant Operations (Requires active subscription and merchant access via X-Merchant-Id header)
        Route::middleware(['merchant.access', 'merchant.subscription'])->group(function () {
            Route::get('/merchant/dashboard', [MerchantDashboardController::class, 'dashboard']);

            // Billing Module
            Route::prefix('billing')->group(function () {
                Route::get('/invoices', [\App\Http\Controllers\Api\V1\Billing\BillingController::class, 'index']);
                Route::post('/invoices', [\App\Http\Controllers\Api\V1\Billing\BillingController::class, 'store']);
                Route::post('/invoices/{invoice}/mark-paid', [\App\Http\Controllers\Api\V1\Billing\BillingController::class, 'markPaid']);
                Route::post('/invoices/{invoice}/send-reminder', [\App\Http\Controllers\Api\V1\Billing\BillingController::class, 'sendReminder']);

                // Customers & Leads Management
                Route::get('/customers', [\App\Http\Controllers\Api\V1\Billing\CustomerController::class, 'index']);
                Route::post('/customers', [\App\Http\Controllers\Api\V1\Billing\CustomerController::class, 'store']);
                Route::get('/customers/{customer}', [\App\Http\Controllers\Api\V1\Billing\CustomerController::class, 'show']);
                Route::put('/customers/{customer}', [\App\Http\Controllers\Api\V1\Billing\CustomerController::class, 'update']);
                Route::patch('/customers/{customer}/stage', [\App\Http\Controllers\Api\V1\Billing\CustomerController::class, 'updateStage']);
                Route::get('/customers/{customer}/unpaid-invoices', [\App\Http\Controllers\Api\V1\Billing\CustomerController::class, 'unpaidInvoices']);
                Route::delete('/customers/{customer}', [\App\Http\Controllers\Api\V1\Billing\CustomerController::class, 'destroy']);

                // Store Service Packages
                Route::get('/packages', [\App\Http\Controllers\Api\V1\Billing\MerchantPackageController::class, 'index']);
                Route::post('/packages', [\App\Http\Controllers\Api\V1\Billing\MerchantPackageController::class, 'store']);
                Route::get('/packages/{package}', [\App\Http\Controllers\Api\V1\Billing\MerchantPackageController::class, 'show']);
                Route::put('/packages/{package}', [\App\Http\Controllers\Api\V1\Billing\MerchantPackageController::class, 'update']);
                Route::patch('/packages/{package}/toggle-status', [\App\Http\Controllers\Api\V1\Billing\MerchantPackageController::class, 'toggleStatus']);
                Route::delete('/packages/{package}', [\App\Http\Controllers\Api\V1\Billing\MerchantPackageController::class, 'destroy']);

                // Customer Package Assignments
                Route::post('/customers/{customer}/assign-package', [\App\Http\Controllers\Api\V1\Billing\BillingCalendarController::class, 'assignPackage']);
                Route::put('/customer-packages/{assignment}', [\App\Http\Controllers\Api\V1\Billing\BillingCalendarController::class, 'updateAssignedPackage']);
                Route::patch('/customer-packages/{assignment}/status', [\App\Http\Controllers\Api\V1\Billing\BillingCalendarController::class, 'updateAssignmentStatus']);
                Route::delete('/customer-packages/{assignment}', [\App\Http\Controllers\Api\V1\Billing\BillingCalendarController::class, 'deleteAssignedPackage']);

                // Billing Calendar & Schedule Issue Invoice
                Route::get('/calendar', [\App\Http\Controllers\Api\V1\Billing\BillingCalendarController::class, 'calendar']);
                Route::post('/calendar/issue-invoice', [\App\Http\Controllers\Api\V1\Billing\BillingCalendarController::class, 'issueInvoiceFromSchedule']);

                // Customer Field Visits
                Route::get('/visits', [\App\Http\Controllers\Api\V1\Billing\CustomerVisitController::class, 'index']);
                Route::post('/visits', [\App\Http\Controllers\Api\V1\Billing\CustomerVisitController::class, 'store']);
                Route::get('/visits/{visit}', [\App\Http\Controllers\Api\V1\Billing\CustomerVisitController::class, 'show']);
                Route::put('/visits/{visit}', [\App\Http\Controllers\Api\V1\Billing\CustomerVisitController::class, 'update']);
                Route::patch('/visits/{visit}/status', [\App\Http\Controllers\Api\V1\Billing\CustomerVisitController::class, 'updateStatus']);
                Route::post('/visits/{visit}/collect-payment', [\App\Http\Controllers\Api\V1\Billing\CustomerVisitController::class, 'collectPayment']);
                Route::delete('/visits/{visit}', [\App\Http\Controllers\Api\V1\Billing\CustomerVisitController::class, 'destroy']);

                // User Agenda & Daily To-Dos
                Route::get('/todos', [\App\Http\Controllers\Api\V1\Billing\UserTodoController::class, 'index']);
                Route::get('/todos/badge-count', [\App\Http\Controllers\Api\V1\Billing\UserTodoController::class, 'badgeCount']);
                Route::post('/todos', [\App\Http\Controllers\Api\V1\Billing\UserTodoController::class, 'store']);
                Route::patch('/todos/{id}/toggle', [\App\Http\Controllers\Api\V1\Billing\UserTodoController::class, 'toggle']);
                Route::delete('/todos/{id}', [\App\Http\Controllers\Api\V1\Billing\UserTodoController::class, 'destroy']);
            });

            // POS Module
            Route::prefix('pos')->group(function () {
                Route::get('/status', [\App\Http\Controllers\Api\V1\Pos\PosController::class, 'status']);
            });

            // Quotation Module
            Route::prefix('quotation')->group(function () {
                Route::get('/status', [\App\Http\Controllers\Api\V1\Quotation\QuotationController::class, 'status']);
            });
        });
    });
});
