<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminProfileSettingsController;
use App\Http\Controllers\Admin\AreaController;
// Dashboard Controllers for roles
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\VideoBannerController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ComplaintController;
use App\Http\Controllers\Admin\HrController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\PackageController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ReportManagementController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\SubscriptionPlanController;
use App\Http\Controllers\Admin\SupportChatWebController;
use App\Http\Controllers\Admin\SupportTicketWebController;
use App\Http\Controllers\SupportChat\PortalSupportChatWebController;
use App\Http\Controllers\Admin\TipController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VisitController;
use App\Http\Controllers\Admin\WalletController as AdminWalletController;
use App\Http\Controllers\AppPortalWebController;
use App\Http\Controllers\AccountDeletionRequestController;
use App\Http\Controllers\LegalPageController;
use App\Http\Controllers\AreaManager\AreaManagerDashboardController;
use App\Http\Controllers\Client\ClientDashboardController;
use App\Http\Controllers\Client\WalletController as ClientWalletController;
use App\Http\Controllers\HR\HrDashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Supervisor\SupervisorDashboardController;
use App\Http\Controllers\Technician\TechnicianDashboardController;
use App\Http\Controllers\Vendor\VendorDashboardController;
use App\Http\Controllers\Vendor\VendorRegistrationController;
use App\Http\Controllers\Admin\Marketplace\MarketplaceDashboardController;
use App\Http\Controllers\Admin\Marketplace\MarketplaceInventoryController;
use App\Http\Controllers\Admin\Marketplace\MarketplaceOrderController;
use App\Http\Controllers\Admin\Marketplace\MarketplaceProductController;
use App\Http\Controllers\Admin\Marketplace\MarketplaceSettingsController;
use App\Http\Controllers\Admin\VendorController as AdminVendorController;
use App\Http\Controllers\Admin\VendorInsightsController;
use App\Http\Controllers\Admin\VendorListController;
use App\Http\Controllers\Client\VendorComparisonController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Redirect old URLs to clean /media/ path (for existing bookmarks/API responses)
Route::get('/storage/{path}', function (string $path) {
    return redirect('/media/'.$path, 301);
})->where('path', '.*');
Route::get('/app-storage/{path}', function (string $path) {
    return redirect('/media/'.$path, 301);
})->where('path', '.*');

// Serve public files at clean URL: https://your-domain.com/media/products/xxx.jpg
// Optional ?w=192 for cart/list thumbnails (cached under storage/app/public/cache/thumbs).
Route::get('/media/{path}', function (string $path) {
    $path = str_replace(['..', '\\'], ['', '/'], $path);

    // Shared analytics PDF links open formatted report in browser (legacy .csv redirects too).
    if (preg_match('#^shared/vendor-analytics/([a-z0-9]+)\.(csv|pdf)$#i', $path, $matches)) {
        return redirect()->route('shared.vendor-analytics', ['token' => $matches[1]]);
    }

    $width = request()->query('w');
    $widthInt = is_numeric($width) ? (int) $width : null;

    // Path-based thumbs: /media/cache/thumbs/w384/products/foo.jpg
    if (preg_match('#^cache/thumbs/w(\d+)/(.+)$#i', $path, $thumbMatch)) {
        $widthInt = (int) $thumbMatch[1];
        $path = $thumbMatch[2];
    }

    $resolved = \App\Support\MediaThumbCache::resolve($path, $widthInt);
    if ($resolved === null) {
        abort(404);
    }

    $fullPath = $resolved['full_path'];
    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mime = match ($extension) {
        'csv' => 'text/csv; charset=UTF-8',
        'mp4', 'm4v' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        default => mime_content_type($fullPath) ?: 'application/octet-stream',
    };

    $isMediaAsset = in_array($extension, ['mp4', 'm4v', 'webm', 'mov', 'jpg', 'jpeg', 'png', 'webp', 'gif'], true);

    $headers = [
        'Content-Type' => $mime,
        // Browsers/apps can resume; Symfony BinaryFileResponse also handles Range.
        'Accept-Ranges' => 'bytes',
    ];

    if ($isMediaAsset) {
        // Video banners / images: cache hard so home screen does not re-download every login.
        $headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        $mtime = @filemtime($fullPath) ?: time();
        $size = @filesize($fullPath) ?: 0;
        $headers['ETag'] = '"'.md5($resolved['path'].'|'.$mtime.'|'.$size).'"';
        $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $mtime).' GMT';
        if ($size > 0) {
            $headers['Content-Length'] = (string) $size;
        }
    }

    if ($extension === 'csv') {
        $headers['Content-Disposition'] = 'attachment; filename="'.basename($fullPath).'"';
    }

    return response()->file($fullPath, $headers);
})->where('path', '.*')->name('storage.serve');

// Public shared vendor analytics (no login) — Share Analytics button
Route::get('/shared/analytics/{token}', [\App\Http\Controllers\SharedVendorAnalyticsController::class, 'show'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('shared.vendor-analytics');
Route::get('/shared/analytics/{token}/download', [\App\Http\Controllers\SharedVendorAnalyticsController::class, 'download'])
    ->where('token', '[A-Za-z0-9]+')
    ->name('shared.vendor-analytics.download');

if (! function_exists('registerPortalSupportChatRoutes')) {
    function registerPortalSupportChatRoutes(): void
    {
        Route::get('/support-chat/widget-data', [PortalSupportChatWebController::class, 'widgetData'])->name('support-chat.widget-data');
        Route::get('/support-chat/messages', [PortalSupportChatWebController::class, 'messages'])->name('support-chat.messages');
        Route::post('/support-chat/messages', [PortalSupportChatWebController::class, 'send'])->name('support-chat.send');
    }
}

// Public legal pages (no authentication — App Store / Play Console / website)
Route::get('/privacy-policy', [LegalPageController::class, 'privacyPolicy'])->name('legal.privacy-policy');
Route::get('/terms-and-conditions', [LegalPageController::class, 'terms'])->name('legal.terms');
Route::get('/contact-us', [LegalPageController::class, 'contact'])->name('legal.contact');
Route::get('/support', [LegalPageController::class, 'support'])->name('legal.support');
Route::get('/delete-account', [AccountDeletionRequestController::class, 'show'])
    ->name('legal.delete-account');
Route::post('/delete-account', [AccountDeletionRequestController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('legal.delete-account.store');
Route::redirect('/privacy', '/privacy-policy', 301);

// Redirect root '/' to app portal (role picker) or dashboard redirect
Route::get('/', function () {
    try {
        if (auth()->check()) {
            return redirect()->route('dashboard.redirect');
        }

        return redirect()->route('app-portal.roles');
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Root route error', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return redirect('/login');
    }
});

// Alias route 'dashboard' that redirects to 'dashboard.redirect' (fix missing route errors)
Route::middleware('auth')->get('/dashboard', function () {
    return redirect()->route('dashboard.redirect');
})->name('dashboard');

Route::middleware('auth')->post('/locale', function (\Illuminate\Http\Request $request) {
    $locale = $request->validate(['locale' => 'required|string|in:en,ar,ur'])['locale'];
    session(['app_locale' => $locale]);

    $user = $request->user();
    if ($user && \Illuminate\Support\Facades\Schema::hasColumn('users', 'preferred_locale')) {
        $user->preferred_locale = $locale;
        $user->save();
    }

    return back();
})->name('locale');

// Role-based dashboard redirect route
Route::middleware('auth')->get('/dashboard-redirect', function () {
    try {
        $user = auth()->user();
        $role = null;

        $ordered = ['admin', 'hr', 'area_manager', 'supervisor', 'technician', 'vendor', 'client'];
        if (method_exists($user, 'getRoleNames')) {
            try {
                $names = $user->getRoleNames();
                if ($names->isNotEmpty()) {
                    foreach ($ordered as $candidate) {
                        if ($names->contains($candidate)) {
                            $role = $candidate;
                            break;
                        }
                    }
                    if ($role === null) {
                        $role = $names->first();
                    }
                }
            } catch (\Throwable $e) {
                $role = null;
            }
        }
        $role ??= $user->role ?? null;
        switch ($role) {
            case 'admin':
                return redirect()->route('admin.dashboard');
            case 'supervisor':
                return redirect()->route('supervisor.dashboard');
            case 'technician':
                return redirect()->route('technician.dashboard');
            case 'client':
                return redirect()->route('client.dashboard');
            case 'area_manager':
                return redirect()->route('areamanager.dashboard');
            case 'hr':
                return redirect()->route('hr.dashboard');
            case 'vendor':
                $vendor = $user->vendor;
                if ($vendor && $vendor->isApproved()) {
                    return redirect()->route('vendor.dashboard');
                }

                return redirect()->route('vendor.application.status');
            default:
                auth()->logout();

                return redirect()->route('login');
        }
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Dashboard redirect error', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return redirect('/login');
    }
})->name('dashboard.redirect');

// Profile routes
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Admin routes (locale for dashboard translations, prevent.admin.cache so theme/settings changes show immediately)
Route::middleware(['auth', 'role:admin', 'set.admin.locale', 'prevent.admin.cache'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::post('/locale', function (\Illuminate\Http\Request $request) {
            $locale = $request->validate(['locale' => 'required|string|in:en,ar,ur'])['locale'];
            session([
                'admin_locale' => $locale,
                'app_locale' => $locale,
            ]);

            $user = $request->user();
            if ($user && \Illuminate\Support\Facades\Schema::hasColumn('users', 'preferred_locale')) {
                $user->preferred_locale = $locale;
                $user->save();
            }

            return redirect()->back();
        })->name('locale');
        Route::get('/dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');
        Route::get('/recent-activities', [AdminDashboardController::class, 'recentActivitiesPage'])->name('recent-activities.index');

        // Resource routes
        Route::resource('users', UserController::class);
        Route::post('users/{id}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('users/{id}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');

        Route::resource('roles', RoleController::class);
        Route::resource('products', ProductController::class)->whereNumber('product');
        Route::post('products/{product}', [ProductController::class, 'update'])->name('products.update.post')->whereNumber('product');
        Route::get('products/import', [ProductController::class, 'showImport'])->name('products.import');
        Route::post('products/import', [ProductController::class, 'import'])->name('products.import.store');
        Route::get('products/export', [ProductController::class, 'export'])->name('products.export');
        Route::post('products/bulk-delete', [ProductController::class, 'bulkDelete'])->name('products.bulk-delete');
        Route::post('products/bulk-update-status', [ProductController::class, 'bulkUpdateStatus'])->name('products.bulk-update-status');
        Route::post('products/bulk-update-stock', [ProductController::class, 'bulkUpdateStock'])->name('products.bulk-update-stock');
        Route::post('products/{id}/toggle-status', [ProductController::class, 'toggleStatus'])->name('products.toggle-status');

        Route::resource('categories', CategoryController::class);
        Route::get('services', [\App\Http\Controllers\Admin\ServicesController::class, 'index'])->name('services.index');
        Route::get('services/create', [\App\Http\Controllers\Admin\ServicesController::class, 'create'])->name('services.create');
        Route::post('services', [\App\Http\Controllers\Admin\ServicesController::class, 'store'])->name('services.store');
        Route::get('services/category/{id}', [\App\Http\Controllers\Admin\ServicesController::class, 'showCategory'])->name('services.category');
        Route::resource('subscriptions', SubscriptionController::class)->only(['index', 'show', 'edit', 'update']);
        Route::post('subscriptions/{id}/extend', [SubscriptionController::class, 'extend'])->name('subscriptions.extend');
        Route::post('subscriptions/{id}/activate', [SubscriptionController::class, 'activate'])->name('subscriptions.activate');
        Route::post('subscriptions/{id}/deactivate', [SubscriptionController::class, 'deactivate'])->name('subscriptions.deactivate');

        Route::resource('subscription-plans', SubscriptionPlanController::class)->except(['create', 'store', 'destroy']);

        Route::resource('visits', VisitController::class)->only(['index', 'show', 'create', 'store'])->whereNumber('visit');
        Route::post('visits/{id}/assign-technician', [VisitController::class, 'assignTechnician'])->name('visits.assign-technician');
        Route::post('visits/{id}/assign-supervisor', [VisitController::class, 'assignSupervisor'])->name('visits.assign-supervisor');

        Route::resource('reports', ReportController::class)->only(['index', 'show']);
        Route::post('reports/{id}/approve', [ReportController::class, 'approve'])->name('reports.approve');
        Route::post('reports/{id}/send-to-client', [ReportController::class, 'sendToClient'])->name('reports.send-to-client');

        // Generated reports (AdminReport) – generate, schedule, download, share
        Route::get('report-management', [ReportManagementController::class, 'index'])->name('report-management.index');
        Route::get('report-management/create', [ReportManagementController::class, 'create'])->name('report-management.create');
        Route::post('report-management', [ReportManagementController::class, 'store'])->name('report-management.store');
        Route::get('report-management/schedule', [ReportManagementController::class, 'createSchedule'])->name('report-management.schedule.create');
        Route::post('report-management/schedule', [ReportManagementController::class, 'storeSchedule'])->name('report-management.schedule.store');
        Route::get('report-management/{id}', [ReportManagementController::class, 'show'])->name('report-management.show');
        Route::get('report-management/{id}/download', [ReportManagementController::class, 'download'])->name('report-management.download');
        Route::post('report-management/{id}/cancel', [ReportManagementController::class, 'cancel'])->name('report-management.cancel');
        Route::delete('report-management/{id}', [ReportManagementController::class, 'destroy'])->name('report-management.destroy');

        Route::get('zone-assignment', [AreaController::class, 'zoneAssignment'])->name('zone-assignment.index');
        Route::post('areas/{id}/toggle-active', [AreaController::class, 'toggleActive'])->name('areas.toggle-active');
        Route::resource('areas', AreaController::class);
        Route::get('orders/cancelled', [OrderController::class, 'cancelled'])->name('orders.cancelled');
        Route::resource('orders', OrderController::class)->only(['index', 'show'])->whereNumber('order');
        Route::delete('orders/{id}', [OrderController::class, 'destroy'])->name('orders.destroy');
        Route::post('orders/bulk-delete', [OrderController::class, 'bulkDelete'])->name('orders.bulk-delete');
        Route::get('orders/export', [OrderController::class, 'export'])->name('orders.export');
        Route::post('orders/send-to-supplier', [OrderController::class, 'sendToSupplier'])->name('orders.send-to-supplier');
        Route::post('orders/{id}/update-status', [OrderController::class, 'updateStatus'])->name('orders.update-status');
        Route::post('orders/{id}/mark-paid', [OrderController::class, 'markPaid'])->name('orders.mark-paid');
        Route::post('orders/{id}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
        Route::post('orders/{id}/refund', [OrderController::class, 'refund'])->name('orders.refund');

        Route::get('payments/settings', [PaymentController::class, 'settings'])->name('payments.settings');
        Route::get('shop-settings', [\App\Http\Controllers\Admin\ShopSettingsController::class, 'index'])->name('shop-settings.index');
        Route::post('shop-settings/global', [\App\Http\Controllers\Admin\ShopSettingsController::class, 'updateGlobal'])->name('shop-settings.update-global');
        Route::post('shop-settings/instant-order-fee', [\App\Http\Controllers\Admin\ShopSettingsController::class, 'updateInstantOrderFee'])->name('shop-settings.update-instant-order-fee');
        Route::post('shop-settings/category-shipping', [\App\Http\Controllers\Admin\ShopSettingsController::class, 'updateCategoryShipping'])->name('shop-settings.update-category-shipping');
        Route::get('wallet/users/{user}', [AdminWalletController::class, 'show'])->name('wallet.user');
        Route::get('wallet', [AdminWalletController::class, 'index'])->name('wallet.index');
        Route::get('payments/order/{order}', [PaymentController::class, 'showOrderPayment'])->name('payments.order');
        Route::get('payments/mobile-checkout/{checkout}', [PaymentController::class, 'showMobileCheckout'])->name('payments.mobile-checkout');
        Route::get('payments/transaction/{id}', [PaymentController::class, 'showTransaction'])->name('payments.transaction');
        Route::post('payments/gateway/{gateway}', [PaymentController::class, 'updateGateway'])->name('payments.update-gateway');
        Route::post('payments/refund-policy', [PaymentController::class, 'updateRefundPolicy'])->name('payments.update-refund-policy');
        Route::get('payments', [PaymentController::class, 'transactions'])->name('payments.index');

        Route::resource('complaints', ComplaintController::class)->only(['index', 'show']);
        Route::post('complaints/{id}/update-status', [ComplaintController::class, 'updateStatus'])->name('complaints.update-status');
        Route::post('complaints/{id}/assign-supervisor', [ComplaintController::class, 'assignSupervisor'])->name('complaints.assign-supervisor');

        Route::resource('hr', HrController::class);
        Route::get('settings', [AdminProfileSettingsController::class, 'index'])->name('settings.index');
        Route::post('settings', [AdminProfileSettingsController::class, 'update'])->name('settings.update');

        Route::resource('audit-logs', AuditLogController::class)->only(['index', 'show']);

        // Banner routes - define specific routes before resource to avoid conflicts
        Route::post('banners/update-order', [BannerController::class, 'updateOrder'])->name('banners.update-order');
        Route::post('banners/{id}/toggle-status', [BannerController::class, 'toggleStatus'])->name('banners.toggle-status');
        Route::resource('banners', BannerController::class)->except(['show']);

        // Video banners (Featured Video on customer app)
        Route::post('video-banners/{id}/toggle-status', [VideoBannerController::class, 'toggleStatus'])->name('video-banners.toggle-status');
        Route::resource('video-banners', VideoBannerController::class)->except(['show']);

        // Loyalty Points (admin control center)
        Route::get('loyalty', [\App\Http\Controllers\Admin\LoyaltyController::class, 'index'])->name('loyalty.index');
        Route::post('loyalty/toggle', [\App\Http\Controllers\Admin\LoyaltyController::class, 'toggle'])->name('loyalty.toggle');
        Route::get('loyalty/settings', [\App\Http\Controllers\Admin\LoyaltyController::class, 'settings'])->name('loyalty.settings');
        Route::post('loyalty/settings', [\App\Http\Controllers\Admin\LoyaltyController::class, 'saveSettings'])->name('loyalty.settings.save');
        Route::get('loyalty/reports', [\App\Http\Controllers\Admin\LoyaltyController::class, 'reports'])->name('loyalty.reports');
        Route::get('loyalty/export', [\App\Http\Controllers\Admin\LoyaltyController::class, 'export'])->name('loyalty.export');
        Route::get('loyalty/rewards', [\App\Http\Controllers\Admin\LoyaltyController::class, 'rewards'])->name('loyalty.rewards');
        Route::get('loyalty/rewards/create', [\App\Http\Controllers\Admin\LoyaltyController::class, 'createReward'])->name('loyalty.rewards.create');
        Route::post('loyalty/rewards', [\App\Http\Controllers\Admin\LoyaltyController::class, 'storeReward'])->name('loyalty.rewards.store');
        Route::get('loyalty/rewards/{id}/edit', [\App\Http\Controllers\Admin\LoyaltyController::class, 'editReward'])->name('loyalty.rewards.edit');
        Route::put('loyalty/rewards/{id}', [\App\Http\Controllers\Admin\LoyaltyController::class, 'updateReward'])->name('loyalty.rewards.update');
        Route::post('loyalty/rewards/{id}/toggle', [\App\Http\Controllers\Admin\LoyaltyController::class, 'toggleReward'])->name('loyalty.rewards.toggle');
        Route::delete('loyalty/rewards/{id}', [\App\Http\Controllers\Admin\LoyaltyController::class, 'destroyReward'])->name('loyalty.rewards.destroy');
        Route::get('loyalty/customers', [\App\Http\Controllers\Admin\LoyaltyController::class, 'customers'])->name('loyalty.customers');
        Route::get('loyalty/customers/{id}', [\App\Http\Controllers\Admin\LoyaltyController::class, 'customerShow'])->name('loyalty.customers.show');
        Route::post('loyalty/customers/{id}/adjust', [\App\Http\Controllers\Admin\LoyaltyController::class, 'customerAdjust'])->name('loyalty.customers.adjust');
        Route::get('loyalty/campaigns', [\App\Http\Controllers\Admin\LoyaltyController::class, 'campaigns'])->name('loyalty.campaigns');
        Route::get('loyalty/campaigns/create', [\App\Http\Controllers\Admin\LoyaltyController::class, 'createCampaign'])->name('loyalty.campaigns.create');
        Route::post('loyalty/campaigns', [\App\Http\Controllers\Admin\LoyaltyController::class, 'storeCampaign'])->name('loyalty.campaigns.store');
        Route::get('loyalty/campaigns/{id}/edit', [\App\Http\Controllers\Admin\LoyaltyController::class, 'editCampaign'])->name('loyalty.campaigns.edit');
        Route::put('loyalty/campaigns/{id}', [\App\Http\Controllers\Admin\LoyaltyController::class, 'updateCampaign'])->name('loyalty.campaigns.update');
        Route::post('loyalty/campaigns/{id}/toggle', [\App\Http\Controllers\Admin\LoyaltyController::class, 'toggleCampaign'])->name('loyalty.campaigns.toggle');
        Route::delete('loyalty/campaigns/{id}', [\App\Http\Controllers\Admin\LoyaltyController::class, 'destroyCampaign'])->name('loyalty.campaigns.destroy');

        Route::resource('maintenance-photos', \App\Http\Controllers\Admin\MaintenancePhotoController::class)->except(['show']);
        Route::get('cms-pages', [\App\Http\Controllers\Admin\CmsPageController::class, 'index'])->name('cms-pages.index');
        Route::get('cms-pages/{slug}/edit', [\App\Http\Controllers\Admin\CmsPageController::class, 'edit'])->name('cms-pages.edit');
        Route::put('cms-pages/{slug}', [\App\Http\Controllers\Admin\CmsPageController::class, 'update'])->name('cms-pages.update');
        Route::post('coupons/{id}/toggle-status', [CouponController::class, 'toggleStatus'])->name('coupons.toggle-status');
        Route::resource('coupons', CouponController::class)->except(['show']);
        Route::get('packages', [PackageController::class, 'index'])->name('packages.index');
        Route::get('packages/{id}/edit', [PackageController::class, 'edit'])->name('packages.edit');
        Route::put('packages/{id}', [PackageController::class, 'update'])->name('packages.update');
        Route::resource('tips', TipController::class);
        Route::post('tips/{id}/toggle-status', [TipController::class, 'toggleStatus'])->name('tips.toggle-status');

        Route::prefix('marketplace')->name('marketplace.')->group(function () {
            Route::get('/', [MarketplaceDashboardController::class, 'index'])->name('dashboard');
            Route::get('products', [MarketplaceProductController::class, 'index'])->name('products.index');
            Route::get('products/{vendorProduct}', [MarketplaceProductController::class, 'show'])->name('products.show');
            Route::get('products/{vendorProduct}/edit', [MarketplaceProductController::class, 'edit'])->name('products.edit');
            Route::put('products/{vendorProduct}', [MarketplaceProductController::class, 'update'])->name('products.update');
            Route::post('products/{vendorProduct}/approve', [MarketplaceProductController::class, 'approve'])->name('products.approve');
            Route::post('products/{vendorProduct}/reject', [MarketplaceProductController::class, 'reject'])->name('products.reject');
            Route::delete('products/{vendorProduct}', [MarketplaceProductController::class, 'destroy'])->name('products.destroy');
            Route::get('orders', [MarketplaceOrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{vendorOrder}', [MarketplaceOrderController::class, 'show'])->name('orders.show');
            Route::get('orders/{vendorOrder}/invoice', [MarketplaceOrderController::class, 'downloadInvoice'])->name('orders.invoice');
            Route::post('orders/{vendorOrder}/status', [MarketplaceOrderController::class, 'updateStatus'])->name('orders.status');
            Route::post('orders/{vendorOrder}/cancel', [MarketplaceOrderController::class, 'cancel'])->name('orders.cancel');
            Route::post('orders/{vendorOrder}/dispute', [MarketplaceOrderController::class, 'updateDispute'])->name('orders.dispute');
            Route::get('inventory', [MarketplaceInventoryController::class, 'index'])->name('inventory.index');
            Route::get('settings', [MarketplaceSettingsController::class, 'index'])->name('settings');
            Route::post('settings', [MarketplaceSettingsController::class, 'update'])->name('settings.update');
            Route::post('settings/vendors/{vendor}/commission', [MarketplaceSettingsController::class, 'updateVendorCommission'])->name('settings.vendor-commission');
        });

        Route::get('vendors/export', [VendorListController::class, 'export'])->name('vendors.export');
        Route::post('vendors/bulk', [VendorListController::class, 'bulk'])->name('vendors.bulk');
        Route::get('vendors/all', [VendorListController::class, 'index'])->name('vendors.index');
        Route::get('vendors/pending', [VendorListController::class, 'pending'])->name('vendors.pending');
        Route::get('vendors/active', [VendorListController::class, 'active'])->name('vendors.active');
        Route::get('vendors/suspended', [VendorListController::class, 'suspended'])->name('vendors.suspended');
        Route::get('vendors/reports', [VendorInsightsController::class, 'reports'])->name('vendors.reports');
        Route::get('vendors/insights', [VendorInsightsController::class, 'index'])->name('vendors.insights');
        Route::get('vendors/revenue', [VendorInsightsController::class, 'revenue'])->name('vendors.revenue');
        Route::get('vendors', [VendorInsightsController::class, 'overview'])->name('vendors.overview');
        Route::get('vendors/{vendor}/products', [AdminVendorController::class, 'products'])->name('vendors.products');
        Route::post('vendors/{vendor}/products/bulk', [AdminVendorController::class, 'bulkProducts'])->name('vendors.products.bulk');
        Route::get('vendors/{vendor}/products/{vendorProduct}', [AdminVendorController::class, 'showProduct'])->name('vendors.products.show');
        Route::get('vendors/{vendor}/orders', [AdminVendorController::class, 'orders'])->name('vendors.orders');
        Route::get('vendors/{vendor}/orders/export', [AdminVendorController::class, 'exportOrders'])->name('vendors.orders.export');
        Route::get('vendors/{vendor}/orders/{vendorOrder}', [AdminVendorController::class, 'showOrder'])->name('vendors.orders.show');
        Route::post('vendors/{vendor}/orders/{vendorOrder}/status', [AdminVendorController::class, 'updateOrderStatus'])->name('vendors.orders.status');
        Route::post('vendors/{vendor}/orders/{vendorOrder}/cancel', [AdminVendorController::class, 'cancelOrder'])->name('vendors.orders.cancel');
        Route::post('vendors/{vendor}/orders/{vendorOrder}/payment', [AdminVendorController::class, 'updateOrderPayment'])->name('vendors.orders.payment');
        Route::post('vendors/{vendor}/orders/{vendorOrder}/refund', [AdminVendorController::class, 'refundOrder'])->name('vendors.orders.refund');
        Route::get('vendors/{vendor}/orders/{vendorOrder}/invoice', [AdminVendorController::class, 'downloadOrderInvoice'])->name('vendors.orders.invoice');
        Route::get('vendors/{vendor}/revenue', [AdminVendorController::class, 'vendorRevenue'])->name('vendors.vendor-revenue');
        Route::get('vendors/{vendor}/activity', [AdminVendorController::class, 'activity'])->name('vendors.activity');
        Route::post('vendors/{vendor}/reset-password', [AdminVendorController::class, 'resetPassword'])->name('vendors.reset-password');
        Route::post('vendors/{vendor}/notify', [AdminVendorController::class, 'notify'])->name('vendors.notify');
        Route::post('vendors/{vendor}/verify', [AdminVendorController::class, 'verifyVendor'])->name('vendors.verify');
        Route::post('vendors/{vendor}/products/{vendorProduct}/enable', [AdminVendorController::class, 'enableProduct'])->name('vendors.products.enable');
        Route::post('vendors/{vendor}/products/{vendorProduct}/disable', [AdminVendorController::class, 'disableProduct'])->name('vendors.products.disable');
        Route::post('vendors/{vendor}/products/{vendorProduct}/toggle', [AdminVendorController::class, 'toggleProduct'])->name('vendors.products.toggle');
        Route::post('vendors/{vendor}/products/{vendorProduct}/feature', [AdminVendorController::class, 'featureProduct'])->name('vendors.products.feature');
        Route::delete('vendors/{vendor}/products/{vendorProduct}', [AdminVendorController::class, 'destroyProduct'])->name('vendors.products.destroy');
        Route::get('vendors/{vendor}', [AdminVendorController::class, 'show'])->name('vendors.show');
        Route::get('vendors/{vendor}/edit', [AdminVendorController::class, 'edit'])->name('vendors.edit');
        Route::put('vendors/{vendor}', [AdminVendorController::class, 'update'])->name('vendors.update');
        Route::delete('vendors/{vendor}', [AdminVendorController::class, 'destroy'])->name('vendors.destroy');
        Route::post('vendors/{vendor}/approve', [AdminVendorController::class, 'approve'])->name('vendors.approve');
        Route::post('vendors/{vendor}/reject', [AdminVendorController::class, 'reject'])->name('vendors.reject');
        Route::post('vendors/{vendor}/suspend', [AdminVendorController::class, 'suspend'])->name('vendors.suspend');
        Route::post('vendors/{vendor}/activate', [AdminVendorController::class, 'activate'])->name('vendors.activate');
        Route::post('vendors/{vendor}/under-review', [AdminVendorController::class, 'underReview'])->name('vendors.under-review');
        Route::post('vendors/{vendor}/disable', [AdminVendorController::class, 'disable'])->name('vendors.disable');
        Route::post('vendors/{vendor}/documents/{document}/verify', [AdminVendorController::class, 'verifyDocument'])->name('vendors.documents.verify');
        Route::get('vendors/{vendor}/analytics', [AdminVendorController::class, 'analytics'])->name('vendors.analytics');

        // Vendor live chat (Support & Help → Live Chat)
        Route::get('support-chat', [SupportChatWebController::class, 'index'])->name('support-chat.index');
        Route::get('support-chat/widget-data', [SupportChatWebController::class, 'widgetData'])->name('support-chat.widget-data');
        Route::get('support-chat/{session}/messages', [SupportChatWebController::class, 'messages'])->name('support-chat.messages');
        Route::get('support-chat/{session}', [SupportChatWebController::class, 'show'])->name('support-chat.show');
        Route::post('support-chat/{session}/accept', [SupportChatWebController::class, 'accept'])->name('support-chat.accept');
        Route::post('support-chat/{session}/reply', [SupportChatWebController::class, 'reply'])->name('support-chat.reply');
        Route::put('support-chat/{session}/status', [SupportChatWebController::class, 'updateStatus'])->name('support-chat.update-status');

        // Notifications routes (static paths before {id})
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('notifications/statistics', [NotificationController::class, 'statistics'])->name('notifications.statistics');
        Route::get('notifications/broadcasts', [NotificationController::class, 'broadcastsIndex'])->name('notifications.broadcasts.index');
        Route::get('notifications/broadcasts/{broadcast}', [NotificationController::class, 'broadcastsShow'])->name('notifications.broadcasts.show');
        Route::get('notifications/delivery-stats', [NotificationController::class, 'deliveryStats'])->name('notifications.delivery-stats');
        Route::get('notifications/create', [NotificationController::class, 'create'])->name('notifications.create');
        Route::post('notifications/send', [NotificationController::class, 'send'])->name('notifications.send');
        Route::get('notifications/{id}', [NotificationController::class, 'show'])->whereUuid('id')->name('notifications.show');
        Route::get('notifications/{id}/read-and-redirect', [NotificationController::class, 'readAndRedirect'])->whereUuid('id')->name('notifications.read-and-redirect');
        Route::post('notifications/{id}/mark-as-read', [NotificationController::class, 'markAsRead'])->name('notifications.mark-as-read');
        Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
        Route::post('notifications/delete-selected', [NotificationController::class, 'destroyBulk'])->name('notifications.destroy-bulk');
        Route::post('notifications/delete-all', [NotificationController::class, 'destroyAll'])->name('notifications.destroy-all');
        Route::get('notifications/unread-count', [NotificationController::class, 'getUnreadCount'])->name('notifications.unread-count');

        // Support tickets (client submitted tickets with admin reply thread)
        Route::get('support-tickets', [SupportTicketWebController::class, 'index'])->name('support-tickets.index');
        Route::get('support-tickets/attachment/{id}', [SupportTicketWebController::class, 'downloadAttachment'])->name('support-tickets.attachment');
        Route::get('support-tickets/{id}', [SupportTicketWebController::class, 'show'])->name('support-tickets.show');
        Route::post('support-tickets/{id}/reply', [SupportTicketWebController::class, 'reply'])->name('support-tickets.reply');
        Route::put('support-tickets/{id}/status', [SupportTicketWebController::class, 'updateStatus'])->name('support-tickets.update-status');
        Route::delete('support-tickets/{id}', [SupportTicketWebController::class, 'destroy'])->name('support-tickets.destroy');

        // Analytics API routes
        Route::prefix('analytics')->name('analytics.')->group(function () {
            Route::get('revenue', [\App\Http\Controllers\Admin\AnalyticsController::class, 'revenue'])->name('revenue');
            Route::get('visits', [\App\Http\Controllers\Admin\AnalyticsController::class, 'visits'])->name('visits');
            Route::get('subscriptions', [\App\Http\Controllers\Admin\AnalyticsController::class, 'subscriptions'])->name('subscriptions');
            Route::get('visit-status', [\App\Http\Controllers\Admin\AnalyticsController::class, 'visitStatus'])->name('visit-status');
            Route::get('technician-performance', [\App\Http\Controllers\Admin\AnalyticsController::class, 'technicianPerformance'])->name('technician-performance');
            Route::get('area-performance', [\App\Http\Controllers\Admin\AnalyticsController::class, 'areaPerformance'])->name('area-performance');
            Route::get('order-status', [\App\Http\Controllers\Admin\AnalyticsController::class, 'orderStatus'])->name('order-status');
            Route::get('payment-status', [\App\Http\Controllers\Admin\AnalyticsController::class, 'paymentStatus'])->name('payment-status');
        });
    });

// Supervisor routes
Route::middleware(['auth', 'role:supervisor'])
    ->prefix('supervisor')
    ->name('supervisor.')
    ->group(function () {
        Route::get('/dashboard', [SupervisorDashboardController::class, 'index'])->name('dashboard');

        // My Team & Assign Jobs
        Route::get('/team', [\App\Http\Controllers\Supervisor\TeamController::class, 'index'])->name('team.index');
        Route::get('/team/{id}', [\App\Http\Controllers\Supervisor\TeamController::class, 'show'])->name('team.show');
        Route::get('/assign-jobs', [\App\Http\Controllers\Supervisor\TeamController::class, 'assignJobs'])->name('assign-jobs.index');
        Route::post('/assign-jobs', [\App\Http\Controllers\Supervisor\TeamController::class, 'assignJobStore'])->name('assign-jobs.store');

        // Visits
        Route::get('/visits', [\App\Http\Controllers\Supervisor\VisitController::class, 'index'])->name('visits.index');
        Route::get('/visits/{id}', [\App\Http\Controllers\Supervisor\VisitController::class, 'show'])->name('visits.show');
        Route::post('/visits/{id}/approve', [\App\Http\Controllers\Supervisor\VisitController::class, 'approve'])->name('visits.approve');
        Route::post('/visits/{id}/reject', [\App\Http\Controllers\Supervisor\VisitController::class, 'reject'])->name('visits.reject');

        // Reports
        Route::get('/reports', [\App\Http\Controllers\Supervisor\ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/{id}', [\App\Http\Controllers\Supervisor\ReportController::class, 'show'])->name('reports.show');
        Route::get('/reports/{id}/review', [\App\Http\Controllers\Supervisor\ReportController::class, 'review'])->name('reports.review');
        Route::post('/reports/{id}/finalize', [\App\Http\Controllers\Supervisor\ReportController::class, 'finalize'])->name('reports.finalize');

        // Complaints
        Route::get('/complaints', [\App\Http\Controllers\Supervisor\ComplaintController::class, 'index'])->name('complaints.index');
        Route::get('/complaints/{id}', [\App\Http\Controllers\Supervisor\ComplaintController::class, 'show'])->name('complaints.show');
        Route::post('/complaints/{id}/update', [\App\Http\Controllers\Supervisor\ComplaintController::class, 'update'])->name('complaints.update');

        // Areas
        Route::get('/areas', [\App\Http\Controllers\Supervisor\AreaController::class, 'index'])->name('areas.index');

        // Leave requests (apply & view my requests – HR approves)
        Route::get('/leave-requests', [\App\Http\Controllers\Supervisor\LeaveRequestController::class, 'index'])->name('leave-requests.index');
        Route::get('/leave-requests/create', [\App\Http\Controllers\Supervisor\LeaveRequestController::class, 'create'])->name('leave-requests.create');
        Route::post('/leave-requests', [\App\Http\Controllers\Supervisor\LeaveRequestController::class, 'store'])->name('leave-requests.store');

        // Tips
        Route::get('/tips', [\App\Http\Controllers\Tips\TipWebController::class, 'index'])->name('tips.index');
        Route::get('/tips/{id}', [\App\Http\Controllers\Tips\TipWebController::class, 'show'])->name('tips.show');

        // Notifications
        Route::get('/notifications', [\App\Http\Controllers\Supervisor\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/{id}', [\App\Http\Controllers\Supervisor\NotificationController::class, 'show'])->whereUuid('id')->name('notifications.show');
        Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Supervisor\NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
        Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Supervisor\NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
        Route::delete('/notifications/{id}', [\App\Http\Controllers\Supervisor\NotificationController::class, 'destroy'])->name('notifications.destroy');
        Route::post('/notifications/delete-selected', [\App\Http\Controllers\Supervisor\NotificationController::class, 'destroyBulk'])->name('notifications.destroy-bulk');
        Route::post('/notifications/delete-all', [\App\Http\Controllers\Supervisor\NotificationController::class, 'destroyAll'])->name('notifications.destroy-all');

        // Help & Support (tickets + chat with admin)
        Route::get('/help-support', [\App\Http\Controllers\HelpSupportWebController::class, 'index'])->name('help-support.index');
        Route::post('/help-support', [\App\Http\Controllers\HelpSupportWebController::class, 'store'])->name('help-support.store');
        Route::get('/help-support/{id}', [\App\Http\Controllers\HelpSupportWebController::class, 'show'])->name('help-support.show');
        Route::post('/help-support/{id}/reply', [\App\Http\Controllers\HelpSupportWebController::class, 'reply'])->name('help-support.reply');
        registerPortalSupportChatRoutes();
    });

// Technician routes
Route::middleware(['auth', 'role:technician'])
    ->prefix('technician')
    ->name('technician.')
    ->group(function () {
        Route::get('/dashboard', [TechnicianDashboardController::class, 'index'])->name('dashboard');

        // Visits
        Route::get('/visits', [\App\Http\Controllers\Technician\VisitController::class, 'index'])->name('visits.index');
        Route::get('/visits/{id}', [\App\Http\Controllers\Technician\VisitController::class, 'show'])->name('visits.show');
        Route::post('/visits/{id}/accept', [\App\Http\Controllers\Technician\VisitController::class, 'accept'])->name('visits.accept');
        Route::post('/visits/{id}/start', [\App\Http\Controllers\Technician\VisitController::class, 'start'])->name('visits.start');
        Route::post('/visits/{id}/complete', [\App\Http\Controllers\Technician\VisitController::class, 'complete'])->name('visits.complete');
        Route::post('/visits/{id}/update-notes', [\App\Http\Controllers\Technician\VisitController::class, 'updateNotes'])->name('visits.update-notes');

        // Reports
        Route::get('/reports', [\App\Http\Controllers\Technician\ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/create', [\App\Http\Controllers\Technician\ReportController::class, 'create'])->name('reports.create');
        Route::post('/reports', [\App\Http\Controllers\Technician\ReportController::class, 'store'])->name('reports.store');
        Route::get('/reports/{id}', [\App\Http\Controllers\Technician\ReportController::class, 'show'])->name('reports.show');

        // Complaints
        Route::get('/complaints', [\App\Http\Controllers\Technician\ComplaintController::class, 'index'])->name('complaints.index');
        Route::get('/complaints/{id}', [\App\Http\Controllers\Technician\ComplaintController::class, 'show'])->name('complaints.show');

        // Tips
        Route::get('/tips', [\App\Http\Controllers\Tips\TipWebController::class, 'index'])->name('tips.index');
        Route::get('/tips/{id}', [\App\Http\Controllers\Tips\TipWebController::class, 'show'])->name('tips.show');

        // Notifications
        Route::get('/notifications', [\App\Http\Controllers\Technician\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/{id}', [\App\Http\Controllers\Technician\NotificationController::class, 'show'])->whereUuid('id')->name('notifications.show');
        Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Technician\NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
        Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Technician\NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
        Route::delete('/notifications/{id}', [\App\Http\Controllers\Technician\NotificationController::class, 'destroy'])->name('notifications.destroy');
        Route::post('/notifications/delete-selected', [\App\Http\Controllers\Technician\NotificationController::class, 'destroyBulk'])->name('notifications.destroy-bulk');
        Route::post('/notifications/delete-all', [\App\Http\Controllers\Technician\NotificationController::class, 'destroyAll'])->name('notifications.destroy-all');

        // Help & Support (tickets + chat with admin)
        Route::get('/help-support', [\App\Http\Controllers\HelpSupportWebController::class, 'index'])->name('help-support.index');
        Route::post('/help-support', [\App\Http\Controllers\HelpSupportWebController::class, 'store'])->name('help-support.store');
        Route::get('/help-support/{id}', [\App\Http\Controllers\HelpSupportWebController::class, 'show'])->name('help-support.show');
        Route::post('/help-support/{id}/reply', [\App\Http\Controllers\HelpSupportWebController::class, 'reply'])->name('help-support.reply');
        registerPortalSupportChatRoutes();
    });

// Client routes
Route::middleware(['auth', 'role:client'])
    ->prefix('client')
    ->name('client.')
    ->group(function () {
        Route::get('/dashboard', [ClientDashboardController::class, 'index'])->name('dashboard');

        // Subscriptions
        Route::get('/subscriptions', [\App\Http\Controllers\Client\SubscriptionController::class, 'index'])->name('subscriptions.index');
        Route::get('/subscriptions/{id}', [\App\Http\Controllers\Client\SubscriptionController::class, 'show'])->name('subscriptions.show');

        // Visits
        Route::get('/visits', [\App\Http\Controllers\Client\VisitController::class, 'index'])->name('visits.index');
        Route::get('/visits/create', [\App\Http\Controllers\Client\VisitController::class, 'create'])->name('visits.create');
        Route::post('/visits', [\App\Http\Controllers\Client\VisitController::class, 'store'])->name('visits.store');
        Route::get('/visits/{id}', [\App\Http\Controllers\Client\VisitController::class, 'show'])->name('visits.show');

        // Reports
        Route::get('/reports', [\App\Http\Controllers\Client\ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/{id}', [\App\Http\Controllers\Client\ReportController::class, 'show'])->name('reports.show');

        // Orders
        Route::get('/orders', [\App\Http\Controllers\Client\OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{id}', [\App\Http\Controllers\Client\OrderController::class, 'show'])->name('orders.show');
        Route::get('/orders/{id}/report', [\App\Http\Controllers\Client\OrderController::class, 'report'])->name('orders.report');
        Route::post('/orders/{id}/mark-delivered', [\App\Http\Controllers\Client\OrderController::class, 'markDelivered'])->name('orders.mark-delivered');
        Route::post('/orders/{id}/rate', [\App\Http\Controllers\Client\OrderController::class, 'rate'])->name('orders.rate');

        // Shop (cart & checkout – Stripe / PayPal only)
        Route::get('/shop', [\App\Http\Controllers\Client\ShopController::class, 'index'])->name('shop.index');
        Route::get('/shop/{id}', [\App\Http\Controllers\Client\ShopController::class, 'show'])->name('shop.show');
        Route::get('/cart', [\App\Http\Controllers\Client\CartController::class, 'index'])->name('cart.index');
        Route::post('/cart/add', [\App\Http\Controllers\Client\CartController::class, 'add'])->name('cart.add');
        Route::put('/cart/{id}', [\App\Http\Controllers\Client\CartController::class, 'update'])->name('cart.update');
        Route::delete('/cart/{id}', [\App\Http\Controllers\Client\CartController::class, 'remove'])->name('cart.remove');
        Route::post('/cart/clear', [\App\Http\Controllers\Client\CartController::class, 'clear'])->name('cart.clear');
        Route::get('/checkout', [\App\Http\Controllers\Client\CheckoutController::class, 'index'])->name('checkout.index');
        Route::post('/checkout/process', [\App\Http\Controllers\Client\CheckoutController::class, 'process'])->name('checkout.process');
        Route::get('/checkout/success/{order_id}', [\App\Http\Controllers\Client\CheckoutController::class, 'success'])->name('checkout.success');
        Route::get('/checkout/cancel/{order_id}', [\App\Http\Controllers\Client\CheckoutController::class, 'cancel'])->name('checkout.cancel');

        // Services (place service orders – categories with products)
        Route::get('/services', [\App\Http\Controllers\Client\ServiceController::class, 'index'])->name('services.index');
        Route::get('/services/category/{id}', [\App\Http\Controllers\Client\ServiceController::class, 'showCategory'])->name('services.category');

        // Complaints
        Route::get('/complaints', [\App\Http\Controllers\Client\ComplaintController::class, 'index'])->name('complaints.index');
        Route::get('/complaints/create', [\App\Http\Controllers\Client\ComplaintController::class, 'create'])->name('complaints.create');
        Route::post('/complaints', [\App\Http\Controllers\Client\ComplaintController::class, 'store'])->name('complaints.store');
        Route::get('/complaints/{id}', [\App\Http\Controllers\Client\ComplaintController::class, 'show'])->name('complaints.show');

        // Tips
        Route::get('/tips', [\App\Http\Controllers\Tips\TipWebController::class, 'index'])->name('tips.index');
        Route::get('/tips/{id}', [\App\Http\Controllers\Tips\TipWebController::class, 'show'])->name('tips.show');

        // Profile / Account (aligned with API: memberships, addresses, payment methods, loyalty, help & support)
        Route::get('/memberships', [\App\Http\Controllers\Client\MembershipController::class, 'index'])->name('memberships.index');
        Route::get('/addresses', [\App\Http\Controllers\Client\AddressController::class, 'index'])->name('addresses.index');
        Route::get('/addresses/create', [\App\Http\Controllers\Client\AddressController::class, 'create'])->name('addresses.create');
        Route::post('/addresses', [\App\Http\Controllers\Client\AddressController::class, 'store'])->name('addresses.store');
        Route::get('/addresses/{id}/edit', [\App\Http\Controllers\Client\AddressController::class, 'edit'])->name('addresses.edit');
        Route::put('/addresses/{id}', [\App\Http\Controllers\Client\AddressController::class, 'update'])->name('addresses.update');
        Route::delete('/addresses/{id}', [\App\Http\Controllers\Client\AddressController::class, 'destroy'])->name('addresses.destroy');
        Route::get('/payment-methods', [\App\Http\Controllers\Client\PaymentMethodController::class, 'index'])->name('payment-methods.index');
        Route::get('/phone', [\App\Http\Controllers\Client\PhoneController::class, 'edit'])->name('phone.edit');
        Route::put('/phone', [\App\Http\Controllers\Client\PhoneController::class, 'update'])->name('phone.update');
        Route::get('/wallet', [ClientWalletController::class, 'index'])->name('wallet.index');
        Route::get('/wallet/add-money', [ClientWalletController::class, 'addMoney'])->name('wallet.add-money');
        Route::post('/wallet/add-money/payment-intent', [ClientWalletController::class, 'paymentIntent'])->name('wallet.add-money.payment-intent');
        Route::post('/wallet/add-money/confirm', [ClientWalletController::class, 'confirm'])->name('wallet.add-money.confirm');
        Route::get('/loyalty', [\App\Http\Controllers\Client\LoyaltyController::class, 'index'])->name('loyalty.index');
        Route::get('/help-support', [\App\Http\Controllers\Client\HelpSupportController::class, 'index'])->name('help-support.index');
        Route::post('/help-support', [\App\Http\Controllers\Client\HelpSupportController::class, 'store'])->name('help-support.store');
        Route::get('/help-support/{id}', [\App\Http\Controllers\Client\HelpSupportController::class, 'show'])->name('help-support.show');
        Route::post('/help-support/{id}/reply', [\App\Http\Controllers\Client\HelpSupportController::class, 'reply'])->name('help-support.reply');
        registerPortalSupportChatRoutes();

        // Notifications
        Route::get('/notifications', [\App\Http\Controllers\Client\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/{id}', [\App\Http\Controllers\Client\NotificationController::class, 'show'])->whereUuid('id')->name('notifications.show');
        Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Client\NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
        Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Client\NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
        Route::delete('/notifications/{id}', [\App\Http\Controllers\Client\NotificationController::class, 'destroy'])->name('notifications.destroy');
        Route::post('/notifications/delete-selected', [\App\Http\Controllers\Client\NotificationController::class, 'destroyBulk'])->name('notifications.destroy-bulk');
        Route::post('/notifications/delete-all', [\App\Http\Controllers\Client\NotificationController::class, 'destroyAll'])->name('notifications.destroy-all');
    });

// Area Manager routes
Route::middleware(['auth', 'role:area_manager'])
    ->prefix('areamanager')
    ->name('areamanager.')
    ->group(function () {
        Route::get('/dashboard', [AreaManagerDashboardController::class, 'index'])->name('dashboard');

        // Areas
        Route::get('/areas', [\App\Http\Controllers\AreaManager\AreaController::class, 'index'])->name('areas.index');
        Route::get('/areas/{id}', [\App\Http\Controllers\AreaManager\AreaController::class, 'show'])->name('areas.show');

        // Visits
        Route::get('/visits', [\App\Http\Controllers\AreaManager\VisitController::class, 'index'])->name('visits.index');
        Route::get('/visits/{id}', [\App\Http\Controllers\AreaManager\VisitController::class, 'show'])->name('visits.show');

        // Reports
        Route::get('/reports', [\App\Http\Controllers\AreaManager\ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/{id}', [\App\Http\Controllers\AreaManager\ReportController::class, 'show'])->name('reports.show');

        // Generated Reports (Generate PDF)
        Route::get('/generated-reports', [\App\Http\Controllers\AreaManager\GeneratedReportController::class, 'index'])->name('generated-reports.index');
        Route::post('/generated-reports', [\App\Http\Controllers\AreaManager\GeneratedReportController::class, 'store'])->name('generated-reports.store');
        Route::get('/generated-reports/{id}/download', [\App\Http\Controllers\AreaManager\GeneratedReportController::class, 'download'])->name('generated-reports.download');
        Route::get('/generated-reports/{id}/view', [\App\Http\Controllers\AreaManager\GeneratedReportController::class, 'view'])->name('generated-reports.view');
        Route::post('/generated-reports/{id}/destroy', [\App\Http\Controllers\AreaManager\GeneratedReportController::class, 'destroy'])->name('generated-reports.destroy');

        // Tips
        Route::get('/tips', [\App\Http\Controllers\Tips\TipWebController::class, 'index'])->name('tips.index');
        Route::get('/tips/{id}', [\App\Http\Controllers\Tips\TipWebController::class, 'show'])->name('tips.show');

        // Notifications
        Route::get('/notifications', [\App\Http\Controllers\AreaManager\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/{id}', [\App\Http\Controllers\AreaManager\NotificationController::class, 'show'])->name('notifications.show');
        Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\AreaManager\NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
        Route::post('/notifications/mark-all-read', [\App\Http\Controllers\AreaManager\NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
        Route::delete('/notifications/{id}', [\App\Http\Controllers\AreaManager\NotificationController::class, 'destroy'])->name('notifications.destroy');
        Route::post('/notifications/delete-selected', [\App\Http\Controllers\AreaManager\NotificationController::class, 'destroyBulk'])->name('notifications.destroy-bulk');
        Route::post('/notifications/delete-all', [\App\Http\Controllers\AreaManager\NotificationController::class, 'destroyAll'])->name('notifications.destroy-all');

        // Help & Support (tickets + chat with admin)
        Route::get('/help-support', [\App\Http\Controllers\HelpSupportWebController::class, 'index'])->name('help-support.index');
        Route::post('/help-support', [\App\Http\Controllers\HelpSupportWebController::class, 'store'])->name('help-support.store');
        Route::get('/help-support/{id}', [\App\Http\Controllers\HelpSupportWebController::class, 'show'])->name('help-support.show');
        Route::post('/help-support/{id}/reply', [\App\Http\Controllers\HelpSupportWebController::class, 'reply'])->name('help-support.reply');
        registerPortalSupportChatRoutes();
    });

// HR routes
Route::middleware(['auth', 'role:hr'])
    ->prefix('hr')
    ->name('hr.')
    ->group(function () {
        Route::get('/dashboard', [HrDashboardController::class, 'index'])->name('dashboard');

        Route::get('/visit-assignments', [\App\Http\Controllers\HR\HrVisitAssignmentWebController::class, 'index'])->name('visit-assignments.index');
        Route::post('/visit-assignments/{visit}/assign', [\App\Http\Controllers\HR\HrVisitAssignmentWebController::class, 'assign'])->name('visit-assignments.assign');

        Route::get('/reports/technician-monthly', [\App\Http\Controllers\HR\HrReportWebController::class, 'technicianMonthlyForm'])->name('reports.technician-monthly');
        Route::post('/reports/technician-monthly/preview', [\App\Http\Controllers\HR\HrReportWebController::class, 'technicianMonthlyPreview'])->name('reports.technician-monthly.preview');
        Route::post('/reports/technician-monthly/generate', [\App\Http\Controllers\HR\HrReportWebController::class, 'technicianMonthlyGenerate'])->name('reports.technician-monthly.generate');
        Route::get('/reports/generated/{id}/download', [\App\Http\Controllers\HR\HrReportWebController::class, 'downloadGenerated'])->name('reports.generated.download');
        Route::delete('/reports/generated/{id}', [\App\Http\Controllers\HR\HrReportWebController::class, 'destroyGenerated'])->name('reports.generated.destroy');

        // Employees
        Route::get('/employees', [\App\Http\Controllers\HR\EmployeeController::class, 'index'])->name('employees.index');
        Route::get('/employees/create', [\App\Http\Controllers\HR\EmployeeController::class, 'create'])->name('employees.create');
        Route::post('/employees', [\App\Http\Controllers\HR\EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/employees/{id}', [\App\Http\Controllers\HR\EmployeeController::class, 'show'])->name('employees.show');
        Route::get('/employees/{id}/edit', [\App\Http\Controllers\HR\EmployeeController::class, 'edit'])->name('employees.edit');
        Route::put('/employees/{id}', [\App\Http\Controllers\HR\EmployeeController::class, 'update'])->name('employees.update');
        Route::delete('/employees/{id}', [\App\Http\Controllers\HR\EmployeeController::class, 'destroy'])->name('employees.destroy');
        Route::post('/employees/{id}/create-user', [\App\Http\Controllers\HR\EmployeeController::class, 'createUser'])->name('employees.create-user');
        Route::post('/employees/{id}/update-user-status', [\App\Http\Controllers\HR\EmployeeController::class, 'updateUserStatus'])->name('employees.update-user-status');

        // Leave requests (manage leaves – list, approve, reject)
        Route::get('/leave-requests', [\App\Http\Controllers\HR\LeaveRequestController::class, 'index'])->name('leave-requests.index');
        Route::get('/leave-requests/{id}', [\App\Http\Controllers\HR\LeaveRequestController::class, 'show'])->name('leave-requests.show');
        Route::post('/leave-requests/{id}/approve', [\App\Http\Controllers\HR\LeaveRequestController::class, 'approve'])->name('leave-requests.approve');
        Route::post('/leave-requests/{id}/reject', [\App\Http\Controllers\HR\LeaveRequestController::class, 'reject'])->name('leave-requests.reject');

        // Tips
        Route::get('/tips', [\App\Http\Controllers\Tips\TipWebController::class, 'index'])->name('tips.index');
        Route::get('/tips/{id}', [\App\Http\Controllers\Tips\TipWebController::class, 'show'])->name('tips.show');

        // Notifications
        Route::get('/notifications', [\App\Http\Controllers\HR\NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/{id}', [\App\Http\Controllers\HR\NotificationController::class, 'show'])->whereUuid('id')->name('notifications.show');
        Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\HR\NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
        Route::post('/notifications/mark-all-read', [\App\Http\Controllers\HR\NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
        Route::delete('/notifications/{id}', [\App\Http\Controllers\HR\NotificationController::class, 'destroy'])->name('notifications.destroy');
        Route::post('/notifications/delete-selected', [\App\Http\Controllers\HR\NotificationController::class, 'destroyBulk'])->name('notifications.destroy-bulk');
        Route::post('/notifications/delete-all', [\App\Http\Controllers\HR\NotificationController::class, 'destroyAll'])->name('notifications.destroy-all');

        // Help & Support (tickets + chat with admin)
        Route::get('/help-support', [\App\Http\Controllers\HelpSupportWebController::class, 'index'])->name('help-support.index');
        Route::post('/help-support', [\App\Http\Controllers\HelpSupportWebController::class, 'store'])->name('help-support.store');
        Route::get('/help-support/{id}', [\App\Http\Controllers\HelpSupportWebController::class, 'show'])->name('help-support.show');
        Route::post('/help-support/{id}/reply', [\App\Http\Controllers\HelpSupportWebController::class, 'reply'])->name('help-support.reply');
        registerPortalSupportChatRoutes();
    });

// Vendor registration (public)
Route::get('/vendor/register', [VendorRegistrationController::class, 'create'])->name('vendor.register');
Route::post('/vendor/register', [VendorRegistrationController::class, 'store'])->name('vendor.register.store');

Route::middleware(['auth', 'role:vendor', 'vendor.account'])->prefix('vendor')->name('vendor.')->group(function () {
    Route::get('/pending', fn () => redirect()->route('vendor.application.status'))->name('pending');
    Route::get('/application', [\App\Http\Controllers\Vendor\ApplicationController::class, 'status'])->name('application.status');
    Route::post('/application/resubmit', [\App\Http\Controllers\Vendor\ApplicationController::class, 'resubmit'])->name('application.resubmit');
    Route::post('/application/submit', [\App\Http\Controllers\Vendor\ApplicationController::class, 'submit'])->name('application.submit');

    Route::get('/onboarding', [\App\Http\Controllers\Vendor\OnboardingController::class, 'index'])->name('onboarding.index');
    Route::get('/onboarding/profile', [\App\Http\Controllers\Vendor\OnboardingController::class, 'profile'])->name('onboarding.profile');
    Route::put('/onboarding/profile', [\App\Http\Controllers\Vendor\OnboardingController::class, 'updateProfile'])->name('onboarding.profile.update');
    Route::get('/onboarding/categories', [\App\Http\Controllers\Vendor\OnboardingController::class, 'categories'])->name('onboarding.categories');
    Route::put('/onboarding/categories', [\App\Http\Controllers\Vendor\OnboardingController::class, 'updateCategories'])->name('onboarding.categories.update');

    Route::get('/documents', [\App\Http\Controllers\Vendor\DocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [\App\Http\Controllers\Vendor\DocumentController::class, 'store'])->name('documents.store');
    Route::delete('/documents/{document}', [\App\Http\Controllers\Vendor\DocumentController::class, 'destroy'])->name('documents.destroy');

    Route::get('/profile', [\App\Http\Controllers\Vendor\ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [\App\Http\Controllers\Vendor\ProfileController::class, 'update'])->name('profile.update');

    Route::get('/notifications', [\App\Http\Controllers\Vendor\NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/{id}', [\App\Http\Controllers\Vendor\NotificationController::class, 'show'])->whereUuid('id')->name('notifications.show');
    Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Vendor\NotificationController::class, 'markAsRead'])->name('notifications.mark-read');
    Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Vendor\NotificationController::class, 'markAllAsRead'])->name('notifications.mark-all-read');
    Route::delete('/notifications/{id}', [\App\Http\Controllers\Vendor\NotificationController::class, 'destroy'])->name('notifications.destroy');
    Route::post('/notifications/delete-selected', [\App\Http\Controllers\Vendor\NotificationController::class, 'destroyBulk'])->name('notifications.destroy-bulk');
    Route::post('/notifications/delete-all', [\App\Http\Controllers\Vendor\NotificationController::class, 'destroyAll'])->name('notifications.destroy-all');

    Route::get('/help-support', [\App\Http\Controllers\HelpSupportWebController::class, 'index'])->name('help-support.index');
    Route::post('/help-support', [\App\Http\Controllers\HelpSupportWebController::class, 'store'])->name('help-support.store');
    Route::get('/help-support/{id}', [\App\Http\Controllers\HelpSupportWebController::class, 'show'])->name('help-support.show');
    Route::post('/help-support/{id}/reply', [\App\Http\Controllers\HelpSupportWebController::class, 'reply'])->name('help-support.reply');

    Route::get('/support-chat', [\App\Http\Controllers\Vendor\VendorSupportChatWebController::class, 'index'])->name('support-chat.index');
    registerPortalSupportChatRoutes();

    Route::middleware('vendor.approved')->group(function () {
        Route::get('/dashboard', [VendorDashboardController::class, 'index'])->name('dashboard');

        Route::get('/products', [\App\Http\Controllers\Vendor\ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [\App\Http\Controllers\Vendor\ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [\App\Http\Controllers\Vendor\ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [\App\Http\Controllers\Vendor\ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [\App\Http\Controllers\Vendor\ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [\App\Http\Controllers\Vendor\ProductController::class, 'destroy'])->name('products.destroy');

        Route::get('/categories', [\App\Http\Controllers\Vendor\CategoryController::class, 'index'])->name('categories.index');
        Route::get('/categories/create', [\App\Http\Controllers\Vendor\CategoryController::class, 'create'])->name('categories.create');
        Route::post('/categories', [\App\Http\Controllers\Vendor\CategoryController::class, 'store'])->name('categories.store');
        Route::get('/categories/{category}/edit', [\App\Http\Controllers\Vendor\CategoryController::class, 'edit'])->name('categories.edit');
        Route::put('/categories/{category}', [\App\Http\Controllers\Vendor\CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [\App\Http\Controllers\Vendor\CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::get('/services', [\App\Http\Controllers\Vendor\ServiceController::class, 'index'])->name('services.index');
        Route::get('/services/create', [\App\Http\Controllers\Vendor\ServiceController::class, 'create'])->name('services.create');
        Route::post('/services', [\App\Http\Controllers\Vendor\ServiceController::class, 'store'])->name('services.store');
        Route::get('/services/{service}/edit', [\App\Http\Controllers\Vendor\ServiceController::class, 'edit'])->name('services.edit');
        Route::put('/services/{service}', [\App\Http\Controllers\Vendor\ServiceController::class, 'update'])->name('services.update');
        Route::delete('/services/{service}', [\App\Http\Controllers\Vendor\ServiceController::class, 'destroy'])->name('services.destroy');

        Route::get('/orders', [\App\Http\Controllers\Vendor\OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{mapping}', [\App\Http\Controllers\Vendor\OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{mapping}/status', [\App\Http\Controllers\Vendor\OrderController::class, 'updateStatus'])->name('orders.update-status');

        Route::get('/inventory', [\App\Http\Controllers\Vendor\InventoryController::class, 'index'])->name('inventory.index');
        Route::put('/inventory/{vendorProduct}', [\App\Http\Controllers\Vendor\InventoryController::class, 'update'])->name('inventory.update');
    });
});

// Customer vendor comparison (public)
Route::get('/shop/compare/{productId}', [VendorComparisonController::class, 'show'])->name('shop.vendor-compare');

// App portal: role selection → login (same flow as mobile; session + web auth after success)
Route::prefix('app-portal')->name('app-portal.')->group(function () {
    Route::get('/', [AppPortalWebController::class, 'selectRole'])->name('roles');
    Route::get('/login', [AppPortalWebController::class, 'loginForm'])->name('login');
    Route::post('/login', [AppPortalWebController::class, 'loginSubmit'])->name('login.submit');
});

Route::redirect('/portal-login-demo', '/app-portal', 301)->name('portal-login-demo');

// Direct vendor sign-in (same app-portal flow with vendor role pre-selected)
Route::redirect('/vendor/login', '/app-portal/login?portal=vendor', 302)->name('vendor.login');

// Breeze auth routes (login/logout/password/reset)
require __DIR__.'/auth.php';
