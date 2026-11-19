<?php

use App\Http\Controllers\Api\DevicePushTokenController;
use App\Http\Controllers\Api\Vendor\VendorAuthController;
use App\Http\Controllers\Api\Vendor\VendorComparisonController;
use App\Http\Controllers\Api\Vendor\VendorDashboardController;
use App\Http\Controllers\Api\Vendor\VendorInventoryController;
use App\Http\Controllers\Api\Vendor\VendorOrderController;
use App\Http\Controllers\Api\Vendor\VendorCategoryController;
use App\Http\Controllers\Api\Vendor\VendorPerformanceAnalyticsController;
use App\Http\Controllers\Api\Vendor\VendorProductAreaPricingApiController;
use App\Http\Controllers\Api\Vendor\VendorProductController;
use App\Http\Controllers\Api\Vendor\VendorProductOptionsController;
use App\Http\Controllers\Api\Vendor\VendorProfileController;
use App\Http\Controllers\Api\Vendor\VendorServiceController;
use App\Http\Controllers\Api\Vendor\VendorServicePricingSettingsApiController;
use App\Http\Controllers\Api\Vendor\VendorServiceTreePalmSettingsApiController;
use App\Http\Controllers\Api\Vendor\VendorSupportChatController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Vendor module — public
|--------------------------------------------------------------------------
*/
Route::prefix('vendor')->group(function () {
    Route::post('/auth/register', [VendorAuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']); // body: email, password, roles: "vendor"
    Route::get('/auth/registration-options', \App\Http\Controllers\Api\Vendor\VendorRegistrationOptionsController::class);

    Route::get('/compare/products/{productId}', [VendorComparisonController::class, 'byProduct']);
    Route::post('/compare/products', [VendorComparisonController::class, 'byProducts']);
});

// Contractor = supervisor signup (multipart only). Not marketplace vendor.
Route::prefix('contractor')->group(function () {
    Route::get('/auth/registration-options', [\App\Http\Controllers\Api\Supervisor\SupervisorRegistrationController::class, 'registrationOptions']);
    Route::post('/auth/register', [\App\Http\Controllers\Api\Supervisor\SupervisorRegistrationController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']); // body: email, password, roles: "supervisor"
});

/*
|--------------------------------------------------------------------------
| Vendor device push (pending/approved — no vendor.account gate)
|--------------------------------------------------------------------------
| Mobile apps often call POST /api/vendor/auth/device-token after vendor login.
*/
Route::middleware(['auth:sanctum', 'role:vendor'])->prefix('vendor')->group(function () {
    Route::post('/auth/device-token', [DevicePushTokenController::class, 'store']);
    Route::delete('/auth/device-token', [DevicePushTokenController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Vendor module — authenticated vendor
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:vendor', 'vendor.account'])->prefix('vendor')->group(function () {
    Route::get('/auth/me', [VendorAuthController::class, 'me']);
    Route::post('/auth/logout', [VendorAuthController::class, 'logout']);

    Route::get('/profile', [VendorProfileController::class, 'show']);
    Route::put('/profile', [VendorProfileController::class, 'update']);
    Route::post('/profile', [VendorProfileController::class, 'update']);

    Route::get('/notifications', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'index']);
    Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'destroy']);
    Route::post('/notifications/clear-all', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'clearAll']);

    Route::get('/support/chat', [VendorSupportChatController::class, 'show']);
    Route::get('/support/chat/messages', [VendorSupportChatController::class, 'messages']);
    Route::post('/support/chat/messages', [VendorSupportChatController::class, 'sendMessage']);

    Route::get('/application', [\App\Http\Controllers\Api\Vendor\VendorApplicationController::class, 'show']);
    Route::post('/application/submit', [\App\Http\Controllers\Api\Vendor\VendorApplicationController::class, 'submit']);
    Route::post('/application/resubmit', [\App\Http\Controllers\Api\Vendor\VendorApplicationController::class, 'resubmit']);

    Route::get('/documents', [\App\Http\Controllers\Api\Vendor\VendorDocumentController::class, 'index']);
    Route::post('/documents', [\App\Http\Controllers\Api\Vendor\VendorDocumentController::class, 'store']);
    Route::delete('/documents/{id}', [\App\Http\Controllers\Api\Vendor\VendorDocumentController::class, 'destroy']);

    Route::get('/settings/service-pricing', [VendorServicePricingSettingsApiController::class, 'show']);
    Route::put('/settings/service-pricing', [VendorServicePricingSettingsApiController::class, 'update']);
    Route::post('/settings/service-pricing', [VendorServicePricingSettingsApiController::class, 'update']);
    Route::get('/settings/tree-palm-pricing', [VendorServiceTreePalmSettingsApiController::class, 'show']);
    Route::put('/settings/tree-palm-pricing', [VendorServiceTreePalmSettingsApiController::class, 'update']);
    Route::post('/settings/tree-palm-pricing', [VendorServiceTreePalmSettingsApiController::class, 'update']);

    Route::middleware('vendor.approved')->group(function () {
        Route::get('/dashboard/stats', [VendorDashboardController::class, 'stats']);
        Route::get('/dashboard/summary', [VendorDashboardController::class, 'summary']);
        Route::get('/analytics/performance', [VendorPerformanceAnalyticsController::class, 'show']);
        Route::get('/analytics/performance/export', [VendorPerformanceAnalyticsController::class, 'export']);
        Route::post('/analytics/performance/share', [VendorPerformanceAnalyticsController::class, 'share']);

        Route::get('/product-options/categories', [VendorProductOptionsController::class, 'categories']);
        Route::get('/product-options/services', [VendorProductOptionsController::class, 'services']);

        Route::get('/categories', [VendorCategoryController::class, 'index']);
        Route::post('/categories', [VendorCategoryController::class, 'store']);
        Route::get('/categories/{id}', [VendorCategoryController::class, 'show']);
        Route::put('/categories/{id}', [VendorCategoryController::class, 'update']);
        Route::post('/categories/{id}', [VendorCategoryController::class, 'update']);
        Route::delete('/categories/{id}', [VendorCategoryController::class, 'destroy']);

        Route::get('/services/area-pricing', [VendorProductAreaPricingApiController::class, 'index']);
        Route::get('/services/{productId}/area-pricing', [VendorProductAreaPricingApiController::class, 'show']);
        Route::put('/services/{productId}/area-pricing', [VendorProductAreaPricingApiController::class, 'update']);
        Route::post('/services/{productId}/area-pricing', [VendorProductAreaPricingApiController::class, 'update']);

        Route::get('/services', [VendorServiceController::class, 'index']);
        Route::post('/services', [VendorServiceController::class, 'store']);
        Route::get('/services/{id}', [VendorServiceController::class, 'show']);
        Route::put('/services/{id}', [VendorServiceController::class, 'update']);
        Route::post('/services/{id}', [VendorServiceController::class, 'update']);
        Route::delete('/services/{id}', [VendorServiceController::class, 'destroy']);

        // Legacy mobile paths (pre v6.3.1) — register before /products/{id} to avoid 500 on "area-pricing" segment.
        Route::get('/products/area-pricing', [VendorProductAreaPricingApiController::class, 'index']);
        Route::get('/products/{productId}/area-pricing', [VendorProductAreaPricingApiController::class, 'show'])
            ->whereNumber('productId');
        Route::put('/products/{productId}/area-pricing', [VendorProductAreaPricingApiController::class, 'update'])
            ->whereNumber('productId');
        Route::post('/products/{productId}/area-pricing', [VendorProductAreaPricingApiController::class, 'update'])
            ->whereNumber('productId');

        Route::get('/products', [VendorProductController::class, 'index']);
        Route::post('/products', [VendorProductController::class, 'store']);
        Route::get('/products/{id}', [VendorProductController::class, 'show'])->whereNumber('id');
        Route::put('/products/{id}', [VendorProductController::class, 'update']);
        Route::post('/products/{id}', [VendorProductController::class, 'update']);
        Route::delete('/products/{id}', [VendorProductController::class, 'destroy']);

        Route::get('/inventory/{vendorProductId}', [VendorInventoryController::class, 'show']);
        Route::put('/inventory/{vendorProductId}', [VendorInventoryController::class, 'update']);
        Route::patch('/inventory/{vendorProductId}', [VendorInventoryController::class, 'update']);
        Route::post('/inventory/{vendorProductId}', [VendorInventoryController::class, 'update']);

        Route::get('/orders', [VendorOrderController::class, 'index']);
        Route::get('/orders/{id}/track', [VendorOrderController::class, 'track']);
        Route::get('/orders/{id}/contact', [VendorOrderController::class, 'contact']);
        Route::get('/orders/{id}/invoice', [VendorOrderController::class, 'invoice']);
        Route::get('/orders/{id}/download', [VendorOrderController::class, 'download']);
        Route::get('/orders/{id}', [VendorOrderController::class, 'show']);
        Route::post('/orders/{id}/status', [VendorOrderController::class, 'updateStatus']);
        Route::post('/orders/{id}/confirm-delivery', [VendorOrderController::class, 'confirmDelivery']);
        Route::post('/orders/{id}/resend-delivery-otp', [VendorOrderController::class, 'resendDeliveryOtp']);
    });
});
