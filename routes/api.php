<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API HEALTH CHECK
|--------------------------------------------------------------------------
*/
Route::get('/health', function (\Illuminate\Http\Request $request) {
    $convertUris = [];
    $vendorPricingUris = [];
    foreach (app('router')->getRoutes() as $route) {
        $uri = (string) $route->uri();
        if (str_contains($uri, 'convert-to-category')
            || str_contains($uri, 'convert-to-service')
            || str_contains($uri, 'catalog/convert/')) {
            $convertUris[] = implode('|', $route->methods()).' /'.$uri;
        }
        if (str_contains($uri, 'vendor/settings/tree-palm-pricing')) {
            $vendorPricingUris[] = implode('|', $route->methods()).' /'.$uri;
        }
    }

    $payload = [
        'success' => true,
        'status' => 'API is working',
        'app_url' => rtrim((string) config('app.url'), '/'),
        'login_endpoint' => '/api/auth/login',
        'catalog_convert_routes' => array_values(array_unique($convertUris)),
        'vendor_tree_palm_pricing_routes' => array_values(array_unique($vendorPricingUris)),
        'catalog_convert_hint' => count($convertUris) > 0
            ? 'Use POST with Bearer admin token. base_url should be site root WITHOUT /api (URL already includes /api/...).'
            : 'Deploy latest code and run: php artisan route:clear && php artisan route:cache',
        'vendor_pricing_hint' => count($vendorPricingUris) > 0
            ? 'POST /api/vendor/settings/tree-palm-pricing with vendor Bearer token.'
            : 'Missing vendor pricing routes — deploy latest code and run: php artisan optimize:clear && php artisan route:cache',
    ];

    if ($request->boolean('db')) {
        $dbStart = microtime(true);
        try {
            \Illuminate\Support\Facades\DB::select('SELECT 1');
            $payload['database'] = 'ok';
            $payload['database_ms'] = round((microtime(true) - $dbStart) * 1000, 2);
        } catch (\Throwable $e) {
            $payload['database'] = 'failed';
            $payload['database_error'] = config('app.debug') ? $e->getMessage() : 'Connection failed';
            $payload['database_ms'] = round((microtime(true) - $dbStart) * 1000, 2);
        }
    }

    return $payload;
});

/*
| Stripe Dashboard endpoint URL (often configured as /api/stripe/webhook).
| Same handler as /api/shop/stripe/webhook and /api/shop/webhooks/stripe.
| Public — no auth / CSRF (api middleware group only).
*/
Route::post('/stripe/webhook', [\App\Http\Controllers\Shop\ShopPaymentController::class, 'stripeWebhook']);

/*
|--------------------------------------------------------------------------
| MULTILINGUAL EXAMPLES (Spatie translatable + JSON columns)
|--------------------------------------------------------------------------
*/
Route::get('/localized-articles', [\App\Http\Controllers\Api\LocalizedArticleController::class, 'index']);
Route::get('/localized-articles/{slug}', [\App\Http\Controllers\Api\LocalizedArticleController::class, 'show'])
    ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*');

$legalContentPages = ['contact-us', 'terms-and-conditions', 'privacy-policy', 'support'];

foreach (['client', 'vendor'] as $audience) {
    Route::prefix($audience)->group(function () use ($audience, $legalContentPages) {
        foreach ($legalContentPages as $page) {
            Route::get('/'.$page, [\App\Http\Controllers\Api\LegalContentApiController::class, 'showPublic'])
                ->defaults('audience', $audience)
                ->defaults('page', $page);
        }
    });
}

// Alias (same handler as api/auth/technician-signup-areas) — useful if proxies/docs use a shorter path; always deploy latest routes.
Route::get('/technician-signup-areas', [\App\Http\Controllers\Auth\AuthController::class, 'technicianSignupAreas'])
    ->name('api.technician-signup-areas');

// Performance diagnostic endpoint - helps identify server slowness
Route::get('/debug/performance', function () {
    $start = microtime(true);
    $results = [];

    // 1. Basic PHP speed
    $phpStart = microtime(true);
    for ($i = 0; $i < 10000; $i++) {
        $x = $i * $i;
    }
    $results['php_loop_10k'] = round((microtime(true) - $phpStart) * 1000, 2).'ms';

    // 2. Database connection
    $dbStart = microtime(true);
    try {
        \DB::select('SELECT 1');
        $results['db_connection'] = round((microtime(true) - $dbStart) * 1000, 2).'ms';
    } catch (\Exception $e) {
        $results['db_connection'] = 'FAILED: '.$e->getMessage();
    }

    // 3. Simple query
    $queryStart = microtime(true);
    try {
        $count = \App\Models\Product::count();
        $results['product_count_query'] = round((microtime(true) - $queryStart) * 1000, 2).'ms ('.$count.' products)';
    } catch (\Exception $e) {
        $results['product_count_query'] = 'FAILED: '.$e->getMessage();
    }

    // 4. Eager loading test
    $eagerStart = microtime(true);
    try {
        $products = \App\Models\Product::with(['category', 'images', 'primaryImage'])->limit(10)->get();
        $results['eager_load_10_products'] = round((microtime(true) - $eagerStart) * 1000, 2).'ms';
    } catch (\Exception $e) {
        $results['eager_load_10_products'] = 'FAILED: '.$e->getMessage();
    }

    // 5. OPcache status
    $results['opcache_enabled'] = function_exists('opcache_get_status') && opcache_get_status() ? 'YES' : 'NO';

    // 6. PHP version
    $results['php_version'] = PHP_VERSION;

    // 7. Total time
    $results['total_time'] = round((microtime(true) - $start) * 1000, 2).'ms';

    return response()->json([
        'success' => true,
        'message' => 'Performance diagnostics',
        'data' => $results,
        'recommendation' => $results['opcache_enabled'] === 'NO'
            ? 'Enable OPcache on your server for 3-10x faster PHP performance'
            : 'OPcache is enabled - good!',
    ]);
});

/*
|--------------------------------------------------------------------------
| AUTHENTICATION (PUBLIC)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    // No database — use from mobile to verify base_url / network before login (avoids 30s login timeout confusion).
    Route::get('/ping', fn () => response()->json([
        'success' => true,
        'message' => 'Auth API reachable.',
        'data' => [
            'login' => 'POST /api/auth/login',
            'body' => ['email' => 'string', 'password' => 'string', 'roles' => 'admin'],
        ],
    ]));
    Route::get('/app-roles', [\App\Http\Controllers\Auth\AuthController::class, 'appLoginRoles']);
    Route::get('/technician-signup-areas', [\App\Http\Controllers\Auth\AuthController::class, 'technicianSignupAreas']);
    Route::post('/register', [\App\Http\Controllers\Auth\AuthController::class, 'register']);
    Route::post('/register-technician', [\App\Http\Controllers\Auth\AuthController::class, 'registerTechnician']);
    Route::post('/login', [\App\Http\Controllers\Auth\AuthController::class, 'login']);
    Route::post('/google', [\App\Http\Controllers\Auth\AuthController::class, 'google']);
    Route::post('/apple', [\App\Http\Controllers\Auth\AuthController::class, 'apple']);
    Route::post('/forgot-password', [\App\Http\Controllers\Auth\AuthController::class, 'forgotPassword']);
    Route::post('/verify-otp', [\App\Http\Controllers\Auth\AuthController::class, 'verifyOtp']);
    Route::post('/reset-password', [\App\Http\Controllers\Auth\AuthController::class, 'resetPassword']);
});

/*
| Mobile forgot-password aliases (same handlers as /api/auth/* above).
*/
Route::prefix('forgot-password')->group(function () {
    Route::post('/send-otp', [\App\Http\Controllers\Auth\AuthController::class, 'forgotPassword']);
    Route::post('/verify-otp', [\App\Http\Controllers\Auth\AuthController::class, 'verifyOtp']);
    Route::post('/reset', [\App\Http\Controllers\Auth\AuthController::class, 'resetPassword']);
});

/*
|--------------------------------------------------------------------------
| AUTHENTICATION (PROTECTED)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('auth')->group(function () {
    Route::post('/logout', [\App\Http\Controllers\Auth\AuthController::class, 'logout']);
    Route::get('/profile', [\App\Http\Controllers\Auth\AuthController::class, 'profile']);
    Route::get('/user', [\App\Http\Controllers\Auth\AuthController::class, 'profile']); // Alias for /profile
    Route::delete('/account', [\App\Http\Controllers\Api\UserController::class, 'deleteAccount']);
    Route::post('/delete-account', [\App\Http\Controllers\Api\UserController::class, 'deleteAccount']);

    // FCM device tokens (all roles) — required for background/closed-app push
    Route::post('/device-token', [\App\Http\Controllers\Api\DevicePushTokenController::class, 'store']);
    Route::delete('/device-token', [\App\Http\Controllers\Api\DevicePushTokenController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | COMPLAINTS
    |--------------------------------------------------------------------------
    */
    Route::prefix('complaints')->group(function () {
        Route::get('/', [\App\Http\Controllers\ComplaintController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\ComplaintController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\ComplaintController::class, 'show']);
        Route::put('/{id}', [\App\Http\Controllers\ComplaintController::class, 'update']);
        Route::delete('/{id}', [\App\Http\Controllers\ComplaintController::class, 'destroy']);
    });

    /*
    |--------------------------------------------------------------------------
    | CATEGORIES (Admin Only)
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->apiResource('categories', \App\Http\Controllers\CategoryController::class);
});

/*
|--------------------------------------------------------------------------
| SUBSCRIPTIONS (PUBLIC PLANS ENDPOINT)
|--------------------------------------------------------------------------
*/
Route::get('/subscriptions/plans', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'plans']);

/*
|--------------------------------------------------------------------------
| SUBSCRIPTIONS (PROTECTED)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:client|admin'])->prefix('subscriptions')->group(function () {
    Route::get('/', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'store']);
    Route::get('/{id}', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'update']);
    Route::post('/{id}', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'update']);
    Route::post('/{id}/mark-paid', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'markPaid']);
    Route::delete('/{id}/purge', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'purge']);
    Route::delete('/{id}', [\App\Http\Controllers\Subscription\SubscriptionController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| VISITS
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:technician|supervisor|area_manager|client|admin'])->prefix('visits')->group(function () {
    Route::get('/', [\App\Http\Controllers\Visit\VisitController::class, 'index']);
    Route::get('/areas', [\App\Http\Controllers\Visit\VisitController::class, 'areas']);
    Route::post('/resolve-area', [\App\Http\Controllers\Visit\VisitController::class, 'resolveArea']);
    Route::get('/available-slots', [\App\Http\Controllers\Visit\VisitController::class, 'availableSlots']);
    Route::post('/', [\App\Http\Controllers\Visit\VisitController::class, 'store']);
    Route::get('/{id}', [\App\Http\Controllers\Visit\VisitController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Visit\VisitController::class, 'update']);
});

/*
|--------------------------------------------------------------------------
| ADMIN JOB SCHEDULING (Working hours & capacity, Time slots, Blocked
| dates & slots, Jobs calendar)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/job-scheduling')->group(function () {
    Route::get('/working-hours', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'getWorkingHours']);
    Route::put('/working-hours', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'updateWorkingHours']);

    Route::get('/time-slots', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'listTimeSlots']);
    Route::post('/time-slots', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'addTimeSlot']);
    Route::post('/time-slots/{id}/toggle', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'toggleTimeSlot']);
    Route::delete('/time-slots/{id}', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'deleteTimeSlot']);

    Route::get('/blocked-dates', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'listBlockedDates']);
    Route::post('/blocked-dates', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'addBlockedDate']);
    Route::delete('/blocked-dates/{id}', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'deleteBlockedDate']);

    // Per-product slots / blocks (admin configures different times per product)
    Route::get('/products/{productId}/time-slots', [\App\Http\Controllers\Api\Admin\ProductJobSchedulingController::class, 'listTimeSlots']);
    Route::post('/products/{productId}/time-slots', [\App\Http\Controllers\Api\Admin\ProductJobSchedulingController::class, 'addTimeSlot']);
    Route::post('/products/{productId}/time-slots/{id}/toggle', [\App\Http\Controllers\Api\Admin\ProductJobSchedulingController::class, 'toggleTimeSlot']);
    Route::delete('/products/{productId}/time-slots/{id}', [\App\Http\Controllers\Api\Admin\ProductJobSchedulingController::class, 'deleteTimeSlot']);
    Route::get('/products/{productId}/blocked-dates', [\App\Http\Controllers\Api\Admin\ProductJobSchedulingController::class, 'listBlockedDates']);
    Route::post('/products/{productId}/blocked-dates', [\App\Http\Controllers\Api\Admin\ProductJobSchedulingController::class, 'addBlockedDate']);
    Route::delete('/products/{productId}/blocked-dates/{id}', [\App\Http\Controllers\Api\Admin\ProductJobSchedulingController::class, 'deleteBlockedDate']);

    Route::get('/calendar', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'calendar']);
    // Must be registered before /jobs/{id} so "orphans" isn't captured as {id}.
    Route::get('/jobs/orphans', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'previewOrphanJobs']);
    Route::delete('/jobs/orphans', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'deleteOrphanJobs']);
    Route::get('/jobs/{id}', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'bookingDetail']);
    Route::put('/jobs/{id}', [\App\Http\Controllers\Api\Admin\JobSchedulingController::class, 'updateBookingDetail']);
});

/*
|--------------------------------------------------------------------------
| TECHNICIAN ROUTES
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:technician'])->prefix('tech')->group(function () {
    Route::get('/visits', [\App\Http\Controllers\Technician\TechnicianController::class, 'assigned']);
    Route::post('/visits/{id}/accept', [\App\Http\Controllers\Technician\TechnicianController::class, 'accept']);
    Route::post('/visits/{id}/start', [\App\Http\Controllers\Technician\TechnicianController::class, 'start']);
    Route::post('/visits/{id}/complete', [\App\Http\Controllers\Technician\TechnicianController::class, 'complete']);
});

/*
|--------------------------------------------------------------------------
| TECHNICIAN DASHBOARD (dashboard, profile, tasks, jobs, availability with breaks & vacations & service_areas, schedule — no separate vacation routes)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:technician'])->prefix('technician')->group(function () {
    Route::get('/dashboard', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'dashboard']);
    Route::get('/profile', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'profile']);
    Route::put('/profile', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'updateProfile']);
    Route::post('/profile', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'updateProfile']); // POST so form-data (file upload) is parsed by PHP
    Route::get('/service-areas', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'getServiceAreas']);
    Route::put('/service-areas', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'updateServiceAreas']);
    Route::post('/service-areas', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'updateServiceAreas']);
    Route::get('/specializations', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'getSpecializations']);
    Route::put('/specializations', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'updateSpecializations']);
    Route::post('/specializations', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'updateSpecializations']);
    Route::get('/tasks', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'tasks']);
    Route::get('/tasks/{id}', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'taskShow']);
    Route::get('/tasks/{id}/detail', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'taskDetail']);
    Route::put('/tasks/{id}/status', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'taskUpdateStatus']);
    Route::post('/tasks/{id}/accept', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'taskAccept']);
    Route::post('/tasks/{id}/reject', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'taskReject']);
    Route::get('/jobs', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'jobs']);
    Route::get('/jobs/accepted', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'jobsAccepted']);
    Route::get('/jobs/rejected', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'jobsRejected']);
    Route::get('/jobs/status-counts', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'jobsStatusCounts']);
    Route::get('/payout-summary', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'payoutSummary']);
    Route::get('/payouts', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'payouts']);
    Route::get('/settings/payout', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'payoutSettings']);
    Route::put('/settings/payout', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'updatePayoutSettings']);
    Route::get('/bank-accounts', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'bankAccounts']);
    Route::post('/bank-accounts', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'bankAccountStore']);
    Route::put('/bank-accounts/{id}', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'bankAccountUpdate']);
    Route::delete('/bank-accounts/{id}', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'bankAccountDestroy']);
    Route::get('/leave-types', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'leaveTypes']);
    Route::get('/leave-request-types', [\App\Http\Controllers\Api\EmployeeLeaveRequestController::class, 'leaveTypes']);
    Route::get('/leave-requests', [\App\Http\Controllers\Api\EmployeeLeaveRequestController::class, 'index']);
    Route::post('/leave-requests', [\App\Http\Controllers\Api\EmployeeLeaveRequestController::class, 'store']);
    Route::get('/leave-requests/{id}', [\App\Http\Controllers\Api\EmployeeLeaveRequestController::class, 'show']);
    Route::get('/alerts', [\App\Http\Controllers\Api\AlertsController::class, 'index']);
    Route::get('/availability', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'availability']);
    Route::put('/availability', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'updateAvailability']);
    Route::get('/schedule', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'schedule']);
    // Dedicated notifications API (technician)
    Route::get('/notifications', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'index']);
    Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'destroy']);
    Route::post('/notifications/clear-all', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'clearAll']);
    // Field notes: GET list (by supervisor_id); POST submit report
    Route::get('/field-notes', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'fieldNotesIndex']);
    Route::post('/reports', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'submitReport']);
    Route::post('/report/{visit_id}', [\App\Http\Controllers\Technician\TechnicianDashboardController::class, 'submitReportForVisit']);
    // Help & Support (same as /api/support/*, under technician prefix for dashboard)
    Route::get('/support/help-center', [\App\Http\Controllers\Api\SupportController::class, 'helpCenter']);
    Route::get('/support/faqs', [\App\Http\Controllers\Api\SupportController::class, 'faqs']);
    Route::post('/support/tickets', [\App\Http\Controllers\Api\SupportController::class, 'storeTicket']);
});

/*
|--------------------------------------------------------------------------
| SUPPORT (FAQs + submit ticket – shared for all authenticated roles)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:client|technician|supervisor|area_manager|hr|admin'])->prefix('support')->group(function () {
    Route::get('/help-center', [\App\Http\Controllers\Api\SupportController::class, 'helpCenter']);
    Route::get('/faqs', [\App\Http\Controllers\Api\SupportController::class, 'faqs']);
    Route::get('/tickets', [\App\Http\Controllers\Api\SupportController::class, 'indexMyTickets']);
    Route::post('/tickets', [\App\Http\Controllers\Api\SupportController::class, 'storeTicket']);
    Route::get('/tickets/{id}', [\App\Http\Controllers\Api\SupportController::class, 'showMyTicket']);
    Route::post('/tickets/{id}/reply', [\App\Http\Controllers\Api\SupportController::class, 'replyToMyTicket']);
});

/*
|--------------------------------------------------------------------------
| SUPERVISOR ROUTES
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:supervisor'])->prefix('supervisor')->group(function () {
    // Dashboard
    Route::get('/dashboard/summary', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'dashboardSummary']);
    Route::get('/dashboard/kpis', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'dashboardKpis']);
    Route::get('/dashboard/alerts', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'dashboardAlerts']);

    // Zones list (for supervisor to set service areas in profile)
    Route::get('/areas', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'areasList']);
    // Team (list + detail by id)
    Route::get('/team', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'myTeam']);
    Route::post('/team/update', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'teamMembersBulkUpdate']);
    Route::get('/team/{id}', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'teamMemberShow']);
    Route::post('/team/{id}', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'teamMemberUpdate']);
    Route::get('/team-stats', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'teamStats']);
    Route::get('/technician-signup-requests', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'technicianSignupRequests']);
    Route::post('/technician-signup-requests/{id}/confirm', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'technicianSignupRequestConfirm']);
    Route::post('/technician-signup-requests/{id}/cancel', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'technicianSignupRequestCancel']);

    // Assignments: New Jobs (accept/reject) vs claimed list (assign technician)
    Route::get('/assignments/new', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'assignmentsNewJobs']);
    Route::get('/assignments', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'assignmentsPending']);
    Route::get('/assignments/{id}', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'assignmentsShow']);
    Route::get('/assign-tasks', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'assignTasksPage']);
    Route::post('/assignments', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'assignmentsStore']);
    Route::post('/assignments/{id}/claim', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'assignmentsClaim']);
    Route::post('/assignments/{id}/reject', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'assignmentsReject']);
    Route::post('/assignments/{id}', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'assignmentsAssignOrUpdate']);
    Route::post('/assignments/{id}/reassign', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'assignmentsReassign']);

    // Reports (field reports from technicians)
    Route::get('/reports', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'reportsIndex']);
    Route::post('/reports/{id}/accept', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'reportAccept']);
    Route::post('/reports/{id}/reject', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'reportReject']);
    Route::post('/reports/generate', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'reportsGenerate']);
    Route::get('/reports/{id}', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'reportsShow']);
    Route::get('/reports/{id}/download', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'reportsDownload']);

    // Profile (single API: GET profile, PUT/POST profile with form-data: name, email, phone, profile_picture, password, password_confirmation; no current_password)
    Route::get('/profile', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'profile']);
    Route::put('/profile', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'updateProfile']);
    Route::post('/profile', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'updateProfile']);
    Route::get('/profile/preferences', [\App\Http\Controllers\Api\SupervisorDashboardApiController::class, 'profilePreferences']);

    Route::get('/leave-request-types', [\App\Http\Controllers\Api\EmployeeLeaveRequestController::class, 'leaveTypes']);
    Route::get('/leave-requests', [\App\Http\Controllers\Api\EmployeeLeaveRequestController::class, 'index']);
    Route::post('/leave-requests', [\App\Http\Controllers\Api\EmployeeLeaveRequestController::class, 'store']);
    Route::get('/leave-requests/{id}', [\App\Http\Controllers\Api\EmployeeLeaveRequestController::class, 'show']);
    Route::get('/alerts', [\App\Http\Controllers\Api\AlertsController::class, 'index']);

    Route::get('/visits', [\App\Http\Controllers\Supervisor\SupervisorController::class, 'listVisits']);
    Route::get('/visits/{id}', [\App\Http\Controllers\Supervisor\SupervisorController::class, 'reviewVisit']);
    Route::post('/visits/{id}/recommend', [\App\Http\Controllers\Supervisor\SupervisorController::class, 'recommendProducts']);
    Route::post('/visits/{id}/finalize', [\App\Http\Controllers\Supervisor\SupervisorController::class, 'finalizeReport']);
    Route::post('/visits/{id}/status', [\App\Http\Controllers\Supervisor\SupervisorController::class, 'updateVisitStatus']);
    Route::get('/notifications', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'index']);
    Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'destroy']);
    Route::post('/notifications/clear-all', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'clearAll']);
    Route::get('/areas', [\App\Http\Controllers\Supervisor\SupervisorController::class, 'listAreas']);
    Route::get('/complaints', [\App\Http\Controllers\Supervisor\SupervisorController::class, 'listComplaints']);
    Route::post('/complaints/{id}/escalate', [\App\Http\Controllers\Supervisor\SupervisorController::class, 'escalateComplaint']);
});

/*
|--------------------------------------------------------------------------
| AREA MANAGER – Report download/view (works with ID + Bearer OR signed URL)
| GET .../generated-reports/{id}/download and .../view
| - With Bearer token (area_manager): allow if report created_by = user.
| - With valid signed URL (signature + expires): allow without auth.
|--------------------------------------------------------------------------
*/
Route::get('/area-manager/generated-reports/{id}/download', [\App\Http\Controllers\Api\GeneratedReportPublicController::class, 'download'])
    ->name('api.area-manager.generated-reports.download.public')
    ->middleware('optional.sanctum');
Route::get('/area-manager/generated-reports/{id}/view', [\App\Http\Controllers\Api\GeneratedReportPublicController::class, 'view'])
    ->name('api.area-manager.generated-reports.view.public')
    ->middleware('optional.sanctum');
Route::get('/hr/reports/{id}/download-public', [\App\Http\Controllers\Api\HrReportsApiController::class, 'downloadPublic'])
    ->name('api.hr.reports.download.public')
    ->withoutMiddleware(['auth:sanctum']);

/*
|--------------------------------------------------------------------------
| AREA MANAGER ROUTES (dashboard, alerts, teams, reports)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:area_manager'])->prefix('area-manager')->group(function () {
    Route::get('/dashboard/summary', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'dashboardSummary']);
    Route::get('/dashboard/alerts', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'dashboardAlerts']);
    Route::get('/region-map', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'regionMap']);

    /* Teams (All Teams screen → team members → member jobs). Use these to avoid confusion with "team leaders". */
    Route::get('/teams', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'teamLeaders']);
    Route::get('/teams/members/{id}/jobs', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'teamMemberJobs']);
    Route::get('/teams/{id}/members', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'teamLeaderMembers']);
    Route::get('/teams/{id}/jobs', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'teamLeaderJobs']);
    Route::get('/teams/{id}', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'teamLeaderShow']);

    /* Legacy team-leaders paths (prefer /teams for new use). */
    Route::get('/team-leaders', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'teamLeaders']);
    Route::get('/team-leaders/{id}/members', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'teamLeaderMembers']);
    Route::get('/team-leaders/{id}/jobs', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'teamLeaderJobs']);
    Route::get('/team-leaders/{id}', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'teamLeaderShow']);
    Route::get('/team-members/{id}/jobs', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'teamMemberJobs']);
    Route::get('/analytics', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'analytics']);
    Route::get('/reports', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'reportsIndex']);
    Route::post('/reports/generate', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'reportGenerate']);
    Route::get('/generated-reports', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'generatedReportsIndex']);
    /* Help & Support (talk to admin – same as /api/support/*) */
    Route::get('/support/help-center', [\App\Http\Controllers\Api\SupportController::class, 'helpCenter']);
    Route::get('/support/faqs', [\App\Http\Controllers\Api\SupportController::class, 'faqs']);
    Route::get('/support/tickets', [\App\Http\Controllers\Api\SupportController::class, 'indexMyTickets']);
    Route::post('/support/tickets', [\App\Http\Controllers\Api\SupportController::class, 'storeTicket']);
    Route::get('/support/tickets/{id}', [\App\Http\Controllers\Api\SupportController::class, 'showMyTicket']);
    Route::post('/support/tickets/{id}/reply', [\App\Http\Controllers\Api\SupportController::class, 'replyToMyTicket']);
    Route::get('/profile', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'profile']);
    Route::put('/profile', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'updateProfile']);
    Route::post('/profile', [\App\Http\Controllers\Api\AreaManagerApiController::class, 'updateProfile']);
    Route::get('/notifications', [\App\Http\Controllers\Api\AreaManagerNotificationsApiController::class, 'index']);
    Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Api\AreaManagerNotificationsApiController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Api\AreaManagerNotificationsApiController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [\App\Http\Controllers\Api\AreaManagerNotificationsApiController::class, 'destroy']);
    Route::post('/notifications/clear-all', [\App\Http\Controllers\Api\AreaManagerNotificationsApiController::class, 'clearAll']);
});

/*
|--------------------------------------------------------------------------
| ADMIN ROUTES
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum,web', 'role:admin'])->prefix('admin')->group(function () {
    // Catalog convert (register first — long path; must not lose to generic POST {id} on some caches)
    Route::post('/categories/{id}/convert-to-service', [\App\Http\Controllers\CategoryController::class, 'convertToService'])
        ->name('admin.categories.convert-to-service');
    Route::post('/services/{id}/convert-to-category', [\App\Http\Controllers\Api\Admin\ServiceController::class, 'convertToCategory'])
        ->name('admin.services.convert-to-category');
    Route::post('/catalog/convert/service-to-category', [\App\Http\Controllers\Api\Admin\ServiceController::class, 'convertToCategoryFromBody'])
        ->name('admin.catalog.service-to-category');
    Route::post('/catalog/convert/category-to-service', [\App\Http\Controllers\CategoryController::class, 'convertToServiceFromBody'])
        ->name('admin.catalog.category-to-service');
    Route::post('/catalog/restore-platform-services', [\App\Http\Controllers\Api\Admin\ServiceController::class, 'restorePlatformServices'])
        ->name('admin.catalog.restore-platform-services');

    // User Management - Moved to dedicated admin/users group below to avoid route conflicts
    // Routes are now defined at lines 297-302 under 'admin/users' prefix

    // Roles
    Route::get('/roles', [\App\Http\Controllers\Admin\RoleController::class, 'index']);
    Route::post('/roles', [\App\Http\Controllers\Admin\RoleController::class, 'store']);

    // Categories Management (add / list / show / update / delete). Update: PUT or POST (use POST with multipart for image).
    Route::get('/categories', [\App\Http\Controllers\CategoryController::class, 'index']);
    Route::post('/categories', [\App\Http\Controllers\CategoryController::class, 'store']);
    Route::get('/categories/{id}', [\App\Http\Controllers\CategoryController::class, 'show']);
    Route::put('/categories/{id}', [\App\Http\Controllers\CategoryController::class, 'update']);
    Route::post('/categories/{id}/toggle-status', [\App\Http\Controllers\CategoryController::class, 'toggleStatus']);
    Route::post('/categories/{id}', [\App\Http\Controllers\CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [\App\Http\Controllers\CategoryController::class, 'destroy']);

    // Vendor types (admin-managed registration options)
    Route::get('/vendor-types', [\App\Http\Controllers\Api\Admin\VendorTypeController::class, 'index']);
    Route::post('/vendor-types', [\App\Http\Controllers\Api\Admin\VendorTypeController::class, 'store']);
    Route::get('/vendor-types/{id}', [\App\Http\Controllers\Api\Admin\VendorTypeController::class, 'show']);
    Route::put('/vendor-types/{id}', [\App\Http\Controllers\Api\Admin\VendorTypeController::class, 'update']);
    Route::post('/vendor-types/{id}', [\App\Http\Controllers\Api\Admin\VendorTypeController::class, 'update']);
    Route::post('/vendor-types/{id}/toggle-status', [\App\Http\Controllers\Api\Admin\VendorTypeController::class, 'toggleStatus']);
    Route::delete('/vendor-types/{id}', [\App\Http\Controllers\Api\Admin\VendorTypeController::class, 'destroy']);

    // Emirates (admin-managed registration options)
    Route::get('/emirates', [\App\Http\Controllers\Api\Admin\EmirateController::class, 'index']);
    Route::post('/emirates', [\App\Http\Controllers\Api\Admin\EmirateController::class, 'store']);
    Route::get('/emirates/{id}', [\App\Http\Controllers\Api\Admin\EmirateController::class, 'show']);
    Route::put('/emirates/{id}', [\App\Http\Controllers\Api\Admin\EmirateController::class, 'update']);
    Route::post('/emirates/{id}', [\App\Http\Controllers\Api\Admin\EmirateController::class, 'update']);
    Route::post('/emirates/{id}/toggle-status', [\App\Http\Controllers\Api\Admin\EmirateController::class, 'toggleStatus']);
    Route::delete('/emirates/{id}', [\App\Http\Controllers\Api\Admin\EmirateController::class, 'destroy']);

    // Services API (separate CRUD; services = categories). Same data, routes under /api/admin/services.
    Route::get('/services', [\App\Http\Controllers\Api\Admin\ServiceController::class, 'index']);
    Route::post('/services', [\App\Http\Controllers\Api\Admin\ServiceController::class, 'store']);
    Route::get('/services/{id}/settings', [\App\Http\Controllers\Api\Admin\ServicePricingSettingsController::class, 'show'])->whereNumber('id');
    Route::put('/services/{id}/settings', [\App\Http\Controllers\Api\Admin\ServicePricingSettingsController::class, 'update'])->whereNumber('id');
    Route::post('/services/{id}/settings', [\App\Http\Controllers\Api\Admin\ServicePricingSettingsController::class, 'update'])->whereNumber('id');
    Route::get('/services/{id}', [\App\Http\Controllers\Api\Admin\ServiceController::class, 'show']);
    Route::put('/services/{id}', [\App\Http\Controllers\Api\Admin\ServiceController::class, 'update']);
    Route::post('/services/{id}/toggle-status', [\App\Http\Controllers\Api\Admin\ServiceController::class, 'toggleStatus']);
    Route::post('/services/{id}', [\App\Http\Controllers\Api\Admin\ServiceController::class, 'update']);
    Route::delete('/services/{id}', [\App\Http\Controllers\Api\Admin\ServiceController::class, 'destroy']);

    // Zones (Areas): assign supervisors and technicians to zones at setup. area_supervisor, area_technician.
    Route::get('/technicians', [\App\Http\Controllers\Api\Admin\AreaController::class, 'technicians']);
    Route::get('/technicians-for-zones', [\App\Http\Controllers\Api\Admin\AreaController::class, 'techniciansForZones']);
    Route::get('/supervisors-for-zones', [\App\Http\Controllers\Api\Admin\AreaController::class, 'supervisorsForZones']);

    // Supervisors & Teams: list supervisors, get team, add/remove team member
    Route::get('/supervisors', [\App\Http\Controllers\Api\Admin\SupervisorController::class, 'index']);
    Route::get('/supervisors/{id}/team', [\App\Http\Controllers\Api\Admin\SupervisorController::class, 'team']);
    Route::post('/supervisors/{id}/team', [\App\Http\Controllers\Api\Admin\SupervisorController::class, 'addTeamMember']);
    Route::delete('/supervisors/{id}/team', [\App\Http\Controllers\Api\Admin\SupervisorController::class, 'removeTeamMember']);

    // Supervisor (contractor) registration — Contractor Management UI
    Route::get('/supervisor-registrations', [\App\Http\Controllers\Api\Admin\SupervisorRegistrationAdminController::class, 'index']);
    Route::get('/supervisor-registrations/recent', [\App\Http\Controllers\Api\Admin\SupervisorRegistrationAdminController::class, 'recent']);
    Route::get('/supervisor-registrations/{id}', [\App\Http\Controllers\Api\Admin\SupervisorRegistrationAdminController::class, 'show'])->whereNumber('id');
    Route::post('/supervisor-registrations/{id}/approve', [\App\Http\Controllers\Api\Admin\SupervisorRegistrationAdminController::class, 'approve'])->whereNumber('id');
    Route::post('/supervisor-registrations/{id}/reject', [\App\Http\Controllers\Api\Admin\SupervisorRegistrationAdminController::class, 'reject'])->whereNumber('id');
    Route::post('/supervisor-registrations/{id}/request-documents', [\App\Http\Controllers\Api\Admin\SupervisorRegistrationAdminController::class, 'requestDocuments'])->whereNumber('id');
    Route::post('/supervisor-registrations/{id}/account-status/{action}', [\App\Http\Controllers\Api\Admin\SupervisorRegistrationAdminController::class, 'accountStatus'])
        ->whereNumber('id')
        ->where('action', 'suspend|activate|inactive');
    Route::delete('/supervisor-registrations/{id}', [\App\Http\Controllers\Api\Admin\SupervisorRegistrationAdminController::class, 'destroy'])->whereNumber('id');
    Route::post('/supervisor-registrations/{id}/delete', [\App\Http\Controllers\Api\Admin\SupervisorRegistrationAdminController::class, 'destroy'])->whereNumber('id');

    // Contractor signup options (Categories / Services / Locations screen)
    Route::get('/contractor-signup-options', [\App\Http\Controllers\Api\Admin\ContractorSignupOptionsAdminController::class, 'index']);
    Route::put('/contractor-signup-options/settings', [\App\Http\Controllers\Api\Admin\ContractorSignupOptionsAdminController::class, 'updateSettings']);
    Route::post('/contractor-signup-options/settings', [\App\Http\Controllers\Api\Admin\ContractorSignupOptionsAdminController::class, 'updateSettings']);
    Route::post('/contractor-signup-options', [\App\Http\Controllers\Api\Admin\ContractorSignupOptionsAdminController::class, 'store']);
    Route::put('/contractor-signup-options/{id}', [\App\Http\Controllers\Api\Admin\ContractorSignupOptionsAdminController::class, 'update'])->whereNumber('id');
    Route::post('/contractor-signup-options/{id}', [\App\Http\Controllers\Api\Admin\ContractorSignupOptionsAdminController::class, 'update'])->whereNumber('id');
    Route::post('/contractor-signup-options/{id}/toggle', [\App\Http\Controllers\Api\Admin\ContractorSignupOptionsAdminController::class, 'toggle'])->whereNumber('id');
    Route::delete('/contractor-signup-options/{id}', [\App\Http\Controllers\Api\Admin\ContractorSignupOptionsAdminController::class, 'destroy'])->whereNumber('id');

    Route::get('/areas', [\App\Http\Controllers\Api\Admin\AreaController::class, 'index']);
    Route::get('/operational-areas', [\App\Http\Controllers\Api\Admin\AreaController::class, 'operationalAreas']);
    Route::post('/operational-areas/{id}/toggle-active', [\App\Http\Controllers\Api\Admin\AreaController::class, 'toggleOperationalArea']);
    Route::post('/areas/consolidate-duplicates', [\App\Http\Controllers\Api\Admin\AreaController::class, 'consolidateDuplicates']);
    Route::post('/areas', [\App\Http\Controllers\Api\Admin\AreaController::class, 'store']);
    Route::get('/areas/{id}', [\App\Http\Controllers\Api\Admin\AreaController::class, 'show']);
    Route::put('/areas/{id}', [\App\Http\Controllers\Api\Admin\AreaController::class, 'update']);
    Route::post('/areas/{id}', [\App\Http\Controllers\Api\Admin\AreaController::class, 'update']);
    Route::delete('/areas/{id}/supervisors/{supervisorId}', [\App\Http\Controllers\Api\Admin\AreaController::class, 'removeSupervisor']);
    Route::delete('/areas/{id}', [\App\Http\Controllers\Api\Admin\AreaController::class, 'destroy']);

    // Products Management
    Route::get('/products', [\App\Http\Controllers\Admin\ProductController::class, 'index']);
    Route::post('/products', [\App\Http\Controllers\Admin\ProductController::class, 'store']);
    // Bulk operations must come before {id} route to avoid route conflicts
    Route::post('/products/reorder', [\App\Http\Controllers\Admin\ProductController::class, 'reorder']);
    Route::post('/products/bulk-delete', [\App\Http\Controllers\Admin\ProductController::class, 'bulkDelete']);
    Route::post('/products/bulk-update-status', [\App\Http\Controllers\Admin\ProductController::class, 'bulkUpdateStatus']);
    Route::post('/products/bulk-update-stock', [\App\Http\Controllers\Admin\ProductController::class, 'bulkUpdateStock']);
    // Individual product routes
    Route::get('/products/{id}/settings', [\App\Http\Controllers\Api\Admin\ProductSettingsController::class, 'show'])->whereNumber('id');
    Route::put('/products/{id}/settings', [\App\Http\Controllers\Api\Admin\ProductSettingsController::class, 'update'])->whereNumber('id');
    Route::post('/products/{id}/settings', [\App\Http\Controllers\Api\Admin\ProductSettingsController::class, 'update'])->whereNumber('id');
    Route::get('/products/{id}', [\App\Http\Controllers\Admin\ProductController::class, 'show']);
    Route::put('/products/{id}', [\App\Http\Controllers\Admin\ProductController::class, 'update']);
    Route::post('/products/{id}', [\App\Http\Controllers\Admin\ProductController::class, 'update']);
    Route::delete('/products/{id}', [\App\Http\Controllers\Admin\ProductController::class, 'destroy']);
    Route::post('/products/{id}/toggle-status', [\App\Http\Controllers\Admin\ProductController::class, 'toggleStatus']);

    // Variable product data is handled via existing product CRUD routes above
    // using optional fields (product_type, option_groups_json, option_images[*]).

    // Support Tickets (submitted by clients)
    Route::get('/support/tickets', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'index']);
    Route::get('/support/tickets/{id}', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'show']);
    Route::post('/support/tickets/{id}/reply', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'reply']);
    Route::put('/support/tickets/{id}/status', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'updateStatus']);
    // Admin broadcast + delivery statistics (register before /notifications to avoid route clash)
    Route::get('/notifications/broadcast/options', [\App\Http\Controllers\Api\Admin\NotificationBroadcastController::class, 'options']);
    Route::get('/notifications/broadcast/recipients', [\App\Http\Controllers\Api\Admin\NotificationBroadcastController::class, 'recipients']);
    Route::post('/notifications/broadcast', [\App\Http\Controllers\Api\Admin\NotificationBroadcastController::class, 'store']);
    Route::get('/notifications/broadcasts', [\App\Http\Controllers\Api\Admin\NotificationBroadcastController::class, 'index']);
    Route::get('/notifications/broadcasts/{id}', [\App\Http\Controllers\Api\Admin\NotificationBroadcastController::class, 'show'])->whereNumber('id');
    Route::get('/notifications/delivery-stats', [\App\Http\Controllers\Api\Admin\NotificationDeliveryStatsController::class, 'index']);
    Route::get('/notifications', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'index']);
    Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'destroy']);
    Route::post('/notifications/clear-all', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'clearAll']);
    Route::get('/wallet/overview', [\App\Http\Controllers\Api\Admin\WalletController::class, 'overview']);
    Route::get('/wallet/credits', [\App\Http\Controllers\Api\Admin\WalletController::class, 'credits']);
    Route::get('/wallet/users/{user}', [\App\Http\Controllers\Api\Admin\WalletController::class, 'userDetail'])->whereNumber('user');

    // Maintenance photos (admin upload/update/delete — client app is read-only)
    Route::get('/maintenance-photos', [\App\Http\Controllers\Api\Admin\MaintenancePhotoController::class, 'index']);
    Route::get('/maintenance-photos/{id}', [\App\Http\Controllers\Api\Admin\MaintenancePhotoController::class, 'show'])->whereNumber('id');
    Route::post('/maintenance-photos', [\App\Http\Controllers\Api\Admin\MaintenancePhotoController::class, 'store']);
    Route::put('/maintenance-photos/{id}', [\App\Http\Controllers\Api\Admin\MaintenancePhotoController::class, 'update']);
    Route::post('/maintenance-photos/{id}', [\App\Http\Controllers\Api\Admin\MaintenancePhotoController::class, 'update']);
    Route::delete('/maintenance-photos/{id}', [\App\Http\Controllers\Api\Admin\MaintenancePhotoController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| HR ROUTES (HR and Admin can access)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:hr|admin'])->prefix('admin/hr')->group(function () {
    Route::get('/employees', [\App\Http\Controllers\HR\EmployeeController::class, 'index']);
    Route::post('/employees', [\App\Http\Controllers\HR\EmployeeController::class, 'store']);
    Route::get('/employees/{id}', [\App\Http\Controllers\HR\EmployeeController::class, 'show']);
    Route::put('/employees/{id}', [\App\Http\Controllers\HR\EmployeeController::class, 'update']);
    Route::delete('/employees/{id}', [\App\Http\Controllers\HR\EmployeeController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| HR DASHBOARD API (same structure as Area Manager: /api/hr/*)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:hr|admin'])->prefix('hr')->group(function () {
    Route::get('/dashboard/summary', [\App\Http\Controllers\Api\HrApiController::class, 'dashboardSummary']);
    Route::get('/dashboard/visit-assignments', [\App\Http\Controllers\Api\HrApiController::class, 'visitAssignments']);
    Route::get('/visit-assignments/summary', [\App\Http\Controllers\Api\HrApiController::class, 'visitAssignmentsSummary']);
    Route::get('/visit-assignments/assign-screen', [\App\Http\Controllers\Api\HrApiController::class, 'visitAssignmentsAssignScreen']);
    Route::get('/visit-assignments', [\App\Http\Controllers\Api\HrApiController::class, 'visitAssignmentsIndex']);
    Route::post('/visit-assignments/{visitId}', [\App\Http\Controllers\Api\HrApiController::class, 'visitAssignmentsAssign']);
    Route::get('/reports/technician-monthly', [\App\Http\Controllers\Api\HrReportsApiController::class, 'technicianMonthlyPreview']);
    Route::get('/reports', [\App\Http\Controllers\Api\HrReportsApiController::class, 'index']);
    Route::post('/reports/generate', [\App\Http\Controllers\Api\HrReportsApiController::class, 'generate']);
    Route::get('/reports/{id}/download', [\App\Http\Controllers\Api\HrReportsApiController::class, 'download'])->name('api.hr.reports.download');
    Route::delete('/reports/{id}', [\App\Http\Controllers\Api\HrReportsApiController::class, 'destroy']);
    Route::get('/positions', [\App\Http\Controllers\Api\HrApiController::class, 'positions']);
    Route::get('/leave-requests', [\App\Http\Controllers\Api\HrApiController::class, 'leaveRequestsIndex']);
    Route::post('/leave-requests', [\App\Http\Controllers\Api\HrApiController::class, 'leaveRequestStore']);
    Route::get('/leave-requests/{id}', [\App\Http\Controllers\Api\HrApiController::class, 'leaveRequestShow']);
    Route::post('/leave-requests/{id}/approve', [\App\Http\Controllers\Api\HrApiController::class, 'leaveRequestApprove']);
    Route::post('/leave-requests/{id}/reject', [\App\Http\Controllers\Api\HrApiController::class, 'leaveRequestReject']);
    Route::get('/employees', [\App\Http\Controllers\HR\EmployeeController::class, 'index']);
    Route::post('/employees', [\App\Http\Controllers\HR\EmployeeController::class, 'store']);
    Route::get('/employees/{id}', [\App\Http\Controllers\HR\EmployeeController::class, 'show']);
    Route::put('/employees/{id}', [\App\Http\Controllers\HR\EmployeeController::class, 'update']);
    Route::post('/employees/{id}', [\App\Http\Controllers\HR\EmployeeController::class, 'update']);
    Route::delete('/employees/{id}', [\App\Http\Controllers\HR\EmployeeController::class, 'destroy']);
    /* Help & Support (talk to admin – same as /api/support/*, under hr prefix for dashboard) */
    Route::get('/support/help-center', [\App\Http\Controllers\Api\SupportController::class, 'helpCenter']);
    Route::get('/support/faqs', [\App\Http\Controllers\Api\SupportController::class, 'faqs']);
    Route::get('/support/tickets', [\App\Http\Controllers\Api\SupportController::class, 'indexMyTickets']);
    Route::post('/support/tickets', [\App\Http\Controllers\Api\SupportController::class, 'storeTicket']);
    Route::get('/support/tickets/{id}', [\App\Http\Controllers\Api\SupportController::class, 'showMyTicket']);
    Route::post('/support/tickets/{id}/reply', [\App\Http\Controllers\Api\SupportController::class, 'replyToMyTicket']);
    Route::get('/notifications', [\App\Http\Controllers\Api\HrNotificationsApiController::class, 'index']);
    Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Api\HrNotificationsApiController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Api\HrNotificationsApiController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [\App\Http\Controllers\Api\HrNotificationsApiController::class, 'destroy']);
    Route::post('/notifications/clear-all', [\App\Http\Controllers\Api\HrNotificationsApiController::class, 'clearAll']);
    Route::get('/profile', [\App\Http\Controllers\Api\HrApiController::class, 'profile']);
    Route::put('/profile', [\App\Http\Controllers\Api\HrApiController::class, 'updateProfile']);
    Route::post('/profile', [\App\Http\Controllers\Api\HrApiController::class, 'updateProfile']);
});

/*
|--------------------------------------------------------------------------
| REPORTS
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:client|technician|supervisor|area_manager|admin'])->prefix('reports')->group(function () {
    Route::get('/', [\App\Http\Controllers\Report\ReportController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Report\ReportController::class, 'store']);
    Route::get('/{id}', [\App\Http\Controllers\Report\ReportController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| SHOP MODULE
|--------------------------------------------------------------------------
*/
Route::prefix('shop')->group(function () {
    // Shop settings: GET public, PUT requires client auth
    Route::get('/settings', [\App\Http\Controllers\Shop\ShopSettingsController::class, 'index']);
    Route::middleware(['auth:sanctum', 'role:client'])->put('/settings', [\App\Http\Controllers\Shop\ShopSettingsController::class, 'update']);

    // Public product routes (single canonical API for public products)
    Route::get('/products/featured', [\App\Http\Controllers\Shop\ProductController::class, 'featured']);
    Route::get('/products/categories', [\App\Http\Controllers\Shop\ProductController::class, 'getCategories']);
    Route::get('/products/category/{id}', [\App\Http\Controllers\Shop\ProductController::class, 'getByCategory']);
    Route::get('/products', [\App\Http\Controllers\Shop\ProductController::class, 'index']);
    Route::get('/products/{id}/compare-vendors', [\App\Http\Controllers\Shop\ProductController::class, 'compareVendors']);
    Route::get('/products/{id}', [\App\Http\Controllers\Shop\ProductController::class, 'show']);

    // Public vendor store (client app — after "Select this vendor")
    Route::get('/vendors/{id}', [\App\Http\Controllers\Shop\VendorStoreController::class, 'show']);

    // Public category routes
    Route::get('/categories', [\App\Http\Controllers\Shop\CategoryController::class, 'index']);
    Route::get('/categories/{id}', [\App\Http\Controllers\Shop\CategoryController::class, 'show']);

    // Shop checkout: Stripe Checkout or PayPal (optional auth = guest or logged-in). Webhooks = no auth.
    Route::get('/payment-gateways', [\App\Http\Controllers\Shop\CheckoutController::class, 'publicPaymentGateways']);
    Route::get('/refund-policy', [\App\Http\Controllers\Shop\CheckoutController::class, 'refundPolicy']);
    Route::middleware('optional.sanctum')->post('/checkout/start', [\App\Http\Controllers\Shop\ShopPaymentController::class, 'startCheckout']);
    Route::post('/webhooks/stripe', [\App\Http\Controllers\Shop\ShopPaymentController::class, 'stripeWebhook']);
    // Alias for Stripe Dashboard "endpoint URL" (mobile contract): same handler as /webhooks/stripe
    Route::post('/stripe/webhook', [\App\Http\Controllers\Shop\ShopPaymentController::class, 'stripeWebhook']);
    Route::middleware('optional.sanctum')->post('/paypal/capture', [\App\Http\Controllers\Shop\ShopPaymentController::class, 'capturePayPal']);

    // Guest order lookup (no auth): order_number + email
    Route::get('/orders/guest', [\App\Http\Controllers\Shop\OrderController::class, 'guestShow']);
    Route::get('/orders/guest/track', [\App\Http\Controllers\Shop\OrderController::class, 'guestTrack']);

    // Protected cart and order routes
    Route::middleware(['auth:sanctum', 'role:client|admin|supervisor|area_manager'])->group(function () {
        Route::post('/cart/add', [\App\Http\Controllers\Shop\CartController::class, 'add']);
        Route::get('/cart', [\App\Http\Controllers\Shop\CartController::class, 'view']);
        Route::get('/order-summary', [\App\Http\Controllers\Shop\CartController::class, 'orderSummary']);
        Route::post('/buy-now/summary', [\App\Http\Controllers\Shop\CartController::class, 'buyNowSummary']);
        Route::get('/coupons/browse', [\App\Http\Controllers\Shop\ShopCouponController::class, 'browse']);
        Route::post('/coupons/checkout-offers', [\App\Http\Controllers\Shop\ShopCouponController::class, 'checkoutOffers']);
        Route::post('/coupons/validate', [\App\Http\Controllers\Shop\ShopCouponController::class, 'validateCode']);
        Route::post('/coupons/apply', [\App\Http\Controllers\Shop\ShopCouponController::class, 'applyCode']);
        Route::post('/coupons/remove', [\App\Http\Controllers\Shop\ShopCouponController::class, 'removeCode']);
        Route::put('/cart/{id}', [\App\Http\Controllers\Shop\CartController::class, 'update']);
        Route::patch('/cart/{id}', [\App\Http\Controllers\Shop\CartController::class, 'update']);
        Route::delete('/cart/{id}', [\App\Http\Controllers\Shop\CartController::class, 'remove']);

        Route::get('/checkout/payment-methods', [\App\Http\Controllers\Shop\CheckoutController::class, 'paymentMethods']);
        Route::post('/checkout/stripe/payment-intent', [\App\Http\Controllers\Shop\ShopStripeMobileCheckoutController::class, 'paymentIntent']);
        Route::get('/checkout/stripe/config', [\App\Http\Controllers\Shop\ShopStripeMobileCheckoutController::class, 'stripeConfig']);
        Route::post('/checkout/confirm', [\App\Http\Controllers\Shop\ShopStripeMobileCheckoutController::class, 'confirm']);
        Route::get('/checkout/review', [\App\Http\Controllers\Shop\CheckoutController::class, 'review']);
        Route::get('/orders', [\App\Http\Controllers\Shop\OrderController::class, 'index']);
        Route::get('/orders/{id}', [\App\Http\Controllers\Shop\OrderController::class, 'show']);
        Route::post('/orders/{id}/mark-paid', [\App\Http\Controllers\Shop\OrderController::class, 'markPaid']);

        // Payment/Transaction routes
        Route::get('/payments', [\App\Http\Controllers\Shop\PaymentController::class, 'index']);
        Route::get('/payments/{id}', [\App\Http\Controllers\Shop\PaymentController::class, 'show']);
        Route::get('/transactions', [\App\Http\Controllers\Shop\PaymentController::class, 'index']); // Alias
        Route::get('/transactions/{id}', [\App\Http\Controllers\Shop\PaymentController::class, 'show']); // Alias
    });
});

/*
|--------------------------------------------------------------------------
| SERVICES (Frontend-compatible routes)
|--------------------------------------------------------------------------
*/
Route::prefix('services')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\ServiceController::class, 'index']);
    Route::get('/categories', [\App\Http\Controllers\Api\ServiceController::class, 'getCategories']);
    Route::get('/category/{id}', [\App\Http\Controllers\Api\ServiceController::class, 'getByCategory']);
    Route::get('/products', [\App\Http\Controllers\Api\ServiceController::class, 'allProductsOfService']);
    Route::get('/{id}/products', [\App\Http\Controllers\Api\ServiceController::class, 'productsByService']);
    Route::get('/{id}', [\App\Http\Controllers\Api\ServiceController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| MAINTENANCE PHOTOS (Client app – home screen "Maintenance Photos" section)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:client'])->prefix('maintenance-photos')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\MaintenancePhotosController::class, 'index']);
});

/*
|--------------------------------------------------------------------------
| ORDERS (Frontend-compatible routes)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:client|admin|supervisor|area_manager'])->prefix('orders')->group(function () {
    Route::get('/cancelled', [\App\Http\Controllers\Shop\OrderController::class, 'cancelledList']);
    Route::get('/', [\App\Http\Controllers\Shop\OrderController::class, 'index']);
    Route::get('/{id}', [\App\Http\Controllers\Shop\OrderController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Shop\OrderController::class, 'update']);
    Route::post('/{id}/cancel', [\App\Http\Controllers\Shop\OrderController::class, 'cancel']);
    Route::get('/{id}/track', [\App\Http\Controllers\Shop\OrderController::class, 'track']);
    Route::get('/{id}/report', [\App\Http\Controllers\Shop\OrderController::class, 'report']);
    Route::post('/{id}/mark-delivered', [\App\Http\Controllers\Shop\OrderController::class, 'markDelivered']);
    Route::get('/{id}/cancel-track', [\App\Http\Controllers\Shop\OrderController::class, 'cancelTrack']);
    Route::get('/{id}/rating', [\App\Http\Controllers\Shop\OrderController::class, 'rating']);
    Route::post('/{id}/rate', [\App\Http\Controllers\Shop\OrderController::class, 'rate']);
});

/*
|--------------------------------------------------------------------------
| USER PROFILE & SETTINGS (Frontend-compatible routes)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('user')->group(function () {
    Route::get('/profile', [\App\Http\Controllers\Api\UserController::class, 'getProfile']);
    Route::get('/language', [\App\Http\Controllers\Api\UserController::class, 'getLanguage']);
    Route::match(['put', 'patch', 'post'], '/language', [\App\Http\Controllers\Api\UserController::class, 'updateLanguage']);
    Route::get('/wallet', [\App\Http\Controllers\Api\UserController::class, 'walletSummary']);
    Route::get('/wallet/credits', [\App\Http\Controllers\Api\UserController::class, 'walletCredits']);
    Route::match(['put', 'post', 'patch'], '/profile', [\App\Http\Controllers\Api\UserController::class, 'updateProfile']);
    Route::match(['put', 'post', 'patch'], '/phone', [\App\Http\Controllers\Api\UserController::class, 'updatePhone']);
    Route::delete('/account', [\App\Http\Controllers\Api\UserController::class, 'deleteAccount']);
    Route::post('/delete-account', [\App\Http\Controllers\Api\UserController::class, 'deleteAccount']);
    Route::get('/addresses', [\App\Http\Controllers\Api\UserController::class, 'getAddresses']);
    Route::post('/addresses', [\App\Http\Controllers\Api\UserController::class, 'createAddress']);
    Route::put('/addresses/{id}', [\App\Http\Controllers\Api\UserController::class, 'updateAddress']);
    Route::patch('/addresses/{id}', [\App\Http\Controllers\Api\UserController::class, 'updateAddress']);
    Route::post('/addresses/{id}', [\App\Http\Controllers\Api\UserController::class, 'updateAddress']); // POST for form-data
    Route::delete('/addresses/{id}', [\App\Http\Controllers\Api\UserController::class, 'deleteAddress']);
    Route::get('/payment-methods', [\App\Http\Controllers\Api\UserController::class, 'getPaymentMethods']);
    Route::post('/payment-methods/{id}/default', [\App\Http\Controllers\Api\UserController::class, 'setDefaultPaymentMethod']);
    Route::delete('/payment-methods/{id}', [\App\Http\Controllers\Api\UserController::class, 'deletePaymentMethod']);
    Route::get('/notifications', [\App\Http\Controllers\Api\UserController::class, 'getNotifications']);
    Route::get('/loyalty', [\App\Http\Controllers\Api\LoyaltyApiController::class, 'index']);
    Route::post('/notifications/{id}/read', [\App\Http\Controllers\Api\UserController::class, 'markNotificationAsRead']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\UserController::class, 'markAllNotificationsAsRead']);
    Route::post('/notifications/clear-all', [\App\Http\Controllers\Api\UserController::class, 'clearAllNotifications']);
    Route::post('/device-token', [\App\Http\Controllers\Api\DevicePushTokenController::class, 'store']);
    Route::delete('/device-token', [\App\Http\Controllers\Api\DevicePushTokenController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| CLIENT DASHBOARD SETTINGS (for client app – title, subtitle, section toggles)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:client'])->prefix('client')->group(function () {
    Route::get('/settings/dashboard', [\App\Http\Controllers\Api\ClientSettingsController::class, 'dashboard']);
    Route::get('/settings/sections', [\App\Http\Controllers\Api\ClientSettingsController::class, 'sections']);
    Route::get('/memberships', [\App\Http\Controllers\Api\ClientSettingsController::class, 'memberships']);
    Route::get('/loyalty', [\App\Http\Controllers\Api\LoyaltyApiController::class, 'index']);
    Route::get('/loyalty/campaigns', [\App\Http\Controllers\Api\LoyaltyApiController::class, 'campaigns']);
    Route::post('/loyalty/rewards/{id}/redeem', [\App\Http\Controllers\Api\LoyaltyApiController::class, 'redeem']);
    Route::get('/notifications', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'index']);
    Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'destroy']);
    Route::post('/notifications/clear-all', [\App\Http\Controllers\Api\RoleNotificationsApiController::class, 'clearAll']);

    /*
    |--------------------------------------------------------------------------
    | Client Wallet Add Money (SEPARATE from shop checkout)
    |--------------------------------------------------------------------------
    */
    Route::get('/wallet/add-money', [\App\Http\Controllers\Api\Client\WalletTopUpController::class, 'options']);
    Route::post('/wallet/add-money/payment-intent', [\App\Http\Controllers\Api\Client\WalletTopUpController::class, 'paymentIntent']);
    Route::post('/wallet/add-money/confirm', [\App\Http\Controllers\Api\Client\WalletTopUpController::class, 'confirm']);
});

/*
|--------------------------------------------------------------------------
| CLIENT MEMBERSHIP CHECKOUT (Renew / Upgrade a subscription via Stripe)
|--------------------------------------------------------------------------
| Separate from admin subscription management (/api/subscriptions) and
| from wallet add-money. Client or admin (acting on the client's behalf)
| may pay to renew the current plan or upgrade to a higher plan.
*/
Route::middleware(['auth:sanctum', 'role:client|admin'])->prefix('client/memberships')->group(function () {
    Route::get('/screen', [\App\Http\Controllers\Api\Client\MembershipPaymentController::class, 'screen']);
    Route::get('/{id}/upgrade-options', [\App\Http\Controllers\Api\Client\MembershipPaymentController::class, 'upgradeOptions']);
    Route::post('/{id}/renew/payment-intent', [\App\Http\Controllers\Api\Client\MembershipPaymentController::class, 'renewPaymentIntent']);
    Route::post('/{id}/upgrade/payment-intent', [\App\Http\Controllers\Api\Client\MembershipPaymentController::class, 'upgradePaymentIntent']);
    Route::post('/payment/confirm', [\App\Http\Controllers\Api\Client\MembershipPaymentController::class, 'confirm']);
});

/*
|--------------------------------------------------------------------------
| BANNERS (Public - for customer home screen)
|--------------------------------------------------------------------------
*/
Route::get('/banners', [\App\Http\Controllers\Api\BannerController::class, 'index']);

/*
|--------------------------------------------------------------------------
| VIDEO BANNER (Public - for customer home screen "Featured Video")
|--------------------------------------------------------------------------
*/
Route::get('/video-banners', [\App\Http\Controllers\Api\VideoBannerController::class, 'index']);

/*
|--------------------------------------------------------------------------
| EXCLUSIVE OFFERS (Public - for customer home screen "Exclusive Offers" section)
|--------------------------------------------------------------------------
*/
Route::prefix('exclusive-offers')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\ExclusiveOfferController::class, 'index']);
    Route::get('/{id}', [\App\Http\Controllers\Api\ExclusiveOfferController::class, 'show']);
});

/*
|--------------------------------------------------------------------------
| PACKAGES (Public - for customer home page: Combined, Fruit, Vegetable)
|--------------------------------------------------------------------------
*/
Route::get('/packages', [\App\Http\Controllers\Api\PackageController::class, 'index']);
Route::get('/shop/packages', [\App\Http\Controllers\Api\PackageController::class, 'index']);

/*
|--------------------------------------------------------------------------
| ADMIN BANNERS (upload, reorder, enable/disable, set link/action)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/banners')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\BannerController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\Admin\BannerController::class, 'store']);
    Route::post('/update-order', [\App\Http\Controllers\Api\Admin\BannerController::class, 'updateOrder']);
    Route::get('/{id}', [\App\Http\Controllers\Api\Admin\BannerController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Api\Admin\BannerController::class, 'update']);
    Route::post('/{id}', [\App\Http\Controllers\Api\Admin\BannerController::class, 'update']); // POST for multipart (image replace)
    Route::post('/{id}/toggle-status', [\App\Http\Controllers\Api\Admin\BannerController::class, 'toggleStatus']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\BannerController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| ADMIN VIDEO BANNER (create, edit, delete, enable/disable)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/video-banners')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\VideoBannerController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\Admin\VideoBannerController::class, 'store']);
    Route::get('/{id}', [\App\Http\Controllers\Api\Admin\VideoBannerController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Api\Admin\VideoBannerController::class, 'update']);
    Route::post('/{id}', [\App\Http\Controllers\Api\Admin\VideoBannerController::class, 'update']); // POST for multipart (file replace)
    Route::post('/{id}/toggle-status', [\App\Http\Controllers\Api\Admin\VideoBannerController::class, 'toggleStatus']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\VideoBannerController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| ADMIN LOYALTY POINTS (control center, settings, rewards, customers, campaigns)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/loyalty')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'dashboard']);
    Route::put('/toggle', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'toggle']);
    Route::get('/settings', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'settings']);
    Route::match(['put', 'post'], '/settings', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'saveSettings']);
    Route::get('/reports', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'reports']);
    Route::get('/export', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'export']);

    Route::get('/rewards', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'rewards']);
    Route::post('/rewards', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'storeReward']);
    Route::put('/rewards/{id}', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'updateReward']);
    Route::post('/rewards/{id}/toggle', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'toggleReward']);
    Route::delete('/rewards/{id}', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'destroyReward']);

    Route::get('/customers', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'customers']);
    Route::get('/customers/{id}', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'customerShow']);
    Route::match(['post', 'put'], '/customers/{id}/adjust', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'customerAdjust']);

    Route::get('/campaigns', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'campaigns']);
    Route::post('/campaigns', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'storeCampaign']);
    Route::put('/campaigns/{id}', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'updateCampaign']);
    Route::post('/campaigns/{id}/toggle', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'toggleCampaign']);
    Route::delete('/campaigns/{id}', [\App\Http\Controllers\Api\Admin\LoyaltyAdminApiController::class, 'destroyCampaign']);
});

/*
|--------------------------------------------------------------------------
| ADMIN PACKAGES (set price, image, view order count)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/packages')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\PackageController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\Admin\PackageController::class, 'store']);
    Route::get('/{id}', [\App\Http\Controllers\Api\Admin\PackageController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Api\Admin\PackageController::class, 'update']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\PackageController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| ADMIN COUPONS (shop checkout)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/coupons')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\CouponController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\Admin\CouponController::class, 'store']);
    Route::get('/{id}', [\App\Http\Controllers\Api\Admin\CouponController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Api\Admin\CouponController::class, 'update']);
    Route::patch('/{id}', [\App\Http\Controllers\Api\Admin\CouponController::class, 'update']);
    Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\CouponController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| ADMIN EXCLUSIVE OFFERS (create, update, delete – for home screen offers)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/exclusive-offers')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\ExclusiveOfferController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\Api\Admin\ExclusiveOfferController::class, 'store']);
    Route::get('/{id}', [\App\Http\Controllers\Api\Admin\ExclusiveOfferController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\Api\Admin\ExclusiveOfferController::class, 'update']);
    Route::post('/{id}', [\App\Http\Controllers\Api\Admin\ExclusiveOfferController::class, 'update']); // POST for multipart image
    Route::delete('/{id}', [\App\Http\Controllers\Api\Admin\ExclusiveOfferController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| ADMIN ORDERS EXPORT & SEND TO SUPPLIER
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/orders')->group(function () {
    Route::get('/export', [\App\Http\Controllers\Api\Admin\OrderExportController::class, 'export']);
    Route::post('/send-to-supplier', [\App\Http\Controllers\Api\Admin\OrderExportController::class, 'sendToSupplier']);
    Route::post('/bulk-delete', [\App\Http\Controllers\Api\Admin\OrderActionsController::class, 'bulkDelete']);
    Route::post('/{id}/cancel', [\App\Http\Controllers\Api\Admin\OrderActionsController::class, 'cancel']);
    Route::post('/{id}/refund', [\App\Http\Controllers\Api\Admin\OrderActionsController::class, 'refund']);
});

/*
|--------------------------------------------------------------------------
| ADMIN DASHBOARD STATISTICS
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/dashboard')->group(function () {
    Route::get('/statistics', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'statistics']);
    Route::get('/order-statistics', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'orderStatistics']);
    Route::get('/order-statistics/orders', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'orderStatisticsOrders']);
    Route::get('/recent-activities', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'recentActivities']);
    Route::get('/quick-overview', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'quickOverview']);
    Route::get('/profile', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'profile']);
    Route::put('/profile', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'updateProfile']);
    Route::post('/profile', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'updateProfile']);
    Route::get('/top-selling-products', [\App\Http\Controllers\Admin\AdminDashboardController::class, 'topSellingProducts']);
});

/*
|--------------------------------------------------------------------------
| ADMIN SUPPORT TICKETS (tickets from clients/technicians via /api/support/tickets)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/support-tickets')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'index']);
    Route::get('/{id}', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'show']);
    Route::post('/{id}/reply', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'reply']);
    Route::put('/{id}/status', [\App\Http\Controllers\Api\Admin\SupportTicketController::class, 'updateStatus']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/support-chat')->group(function () {
    Route::get('/sessions', [\App\Http\Controllers\Api\Admin\AdminSupportChatController::class, 'index']);
    Route::get('/sessions/by-token/{token}', [\App\Http\Controllers\Api\Admin\AdminSupportChatController::class, 'showByToken']);
    Route::get('/sessions/{id}', [\App\Http\Controllers\Api\Admin\AdminSupportChatController::class, 'show']);
    Route::get('/sessions/{id}/messages', [\App\Http\Controllers\Api\Admin\AdminSupportChatController::class, 'messages']);
    Route::post('/sessions/{id}/messages', [\App\Http\Controllers\Api\Admin\AdminSupportChatController::class, 'sendMessage']);
    Route::put('/sessions/{id}/status', [\App\Http\Controllers\Api\Admin\AdminSupportChatController::class, 'updateStatus']);
});

/*
|--------------------------------------------------------------------------
| ADMIN REPORTS MANAGEMENT
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/reports')->group(function () {
    Route::get('/statistics', [\App\Http\Controllers\Admin\AdminReportController::class, 'statistics']);
    Route::post('/generate', [\App\Http\Controllers\Admin\AdminReportController::class, 'generate']);
    Route::post('/schedule', [\App\Http\Controllers\Admin\AdminReportController::class, 'schedule']);
    Route::get('/', [\App\Http\Controllers\Admin\AdminReportController::class, 'index']);
    Route::get('/{id}', [\App\Http\Controllers\Admin\AdminReportController::class, 'show']);
    Route::post('/{id}/regenerate', [\App\Http\Controllers\Admin\AdminReportController::class, 'regenerate']);
    Route::delete('/{id}/cancel', [\App\Http\Controllers\Admin\AdminReportController::class, 'cancel']);
    Route::delete('/{id}', [\App\Http\Controllers\Admin\AdminReportController::class, 'destroy']);
});

// Browser-friendly admin report download (works with or without Bearer token).
Route::get('/admin/reports/{id}/download', [\App\Http\Controllers\Admin\AdminReportController::class, 'download'])
    ->name('api.admin.reports.download')
    ->middleware('optional.sanctum');

/*
|--------------------------------------------------------------------------
| ADMIN USERS MANAGEMENT
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/users')->group(function () {
    // Statistics route MUST be before {id} route to avoid route conflict
    Route::get('statistics', [\App\Http\Controllers\Admin\UserController::class, 'statistics'])->name('api.admin.users.statistics');
    Route::get('/', [\App\Http\Controllers\Admin\UserController::class, 'index'])->name('api.admin.users.index');
    Route::post('/', [\App\Http\Controllers\Admin\UserController::class, 'store'])->name('api.admin.users.store');
    // {id} route must be LAST to avoid catching 'statistics' as an ID
    Route::get('{id}', [\App\Http\Controllers\Admin\UserController::class, 'show'])->name('api.admin.users.show');
    Route::put('{id}', [\App\Http\Controllers\Admin\UserController::class, 'update'])->name('api.admin.users.update');
    Route::delete('{id}', [\App\Http\Controllers\Admin\UserController::class, 'destroy'])->name('api.admin.users.destroy');
});

/*
|--------------------------------------------------------------------------
| ADMIN SETTINGS (MOBILE / REACT NATIVE)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin/settings')->group(function () {
    Route::get('/', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'index']);
    Route::get('/system', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'getSystem']);
    Route::put('/system', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'updateSystem']);
    Route::get('/theme', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'getTheme']);
    Route::put('/theme', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'updateTheme']);
    Route::get('/language', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'getLanguage']);
    Route::put('/language', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'updateLanguage']);
    Route::get('/payment', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'getPayment']);
    Route::put('/payment', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'updatePayment']);
    Route::get('/shop', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'getShop']);
    Route::put('/shop', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'updateShop']);
    Route::get('/instant-order-fee', [\App\Http\Controllers\Admin\AdminInstantOrderFeeApiController::class, 'show']);
    Route::put('/instant-order-fee', [\App\Http\Controllers\Admin\AdminInstantOrderFeeApiController::class, 'update']);
    // UI: Show on customer + price per tree/palm + Price includes (not mixed with fixed/per_m2).
    Route::get('/tree-palm-pricing', [\App\Http\Controllers\Admin\AdminServiceTreePalmSettingsApiController::class, 'show']);
    Route::put('/tree-palm-pricing', [\App\Http\Controllers\Admin\AdminServiceTreePalmSettingsApiController::class, 'update']);
    Route::post('/tree-palm-pricing', [\App\Http\Controllers\Admin\AdminServiceTreePalmSettingsApiController::class, 'update']);
    Route::get('/shop/category-shipping', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'getCategoryShipping']);
    Route::put('/shop/category-shipping', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'updateCategoryShipping']);
    Route::get('/legal', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'getLegal']);
    Route::put('/legal', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'updateLegal']);
    Route::post('/export-data', [\App\Http\Controllers\Admin\AdminSettingsApiController::class, 'exportData']);
});

require __DIR__.'/admin_vendor_mobile_api.php';

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () use ($legalContentPages) {
    foreach (['client', 'vendor'] as $audience) {
        Route::prefix('admin/'.$audience)->group(function () use ($audience, $legalContentPages) {
            Route::get('/pages', [\App\Http\Controllers\Api\LegalContentApiController::class, 'adminPages'])
                ->defaults('audience', $audience);

            foreach ($legalContentPages as $page) {
                Route::get('/'.$page, [\App\Http\Controllers\Api\LegalContentApiController::class, 'adminShow'])
                    ->defaults('audience', $audience)
                    ->defaults('page', $page);
                Route::put('/'.$page, [\App\Http\Controllers\Api\LegalContentApiController::class, 'adminUpdate'])
                    ->defaults('audience', $audience)
                    ->defaults('page', $page);
                Route::post('/'.$page, [\App\Http\Controllers\Api\LegalContentApiController::class, 'adminUpdate'])
                    ->defaults('audience', $audience)
                    ->defaults('page', $page);
            }
        });
    }
});

/*
|--------------------------------------------------------------------------
| TIPS & NOTIFICATIONS
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:client|technician|supervisor|area_manager|hr|admin'])->group(function () {
    Route::get('/tips', [\App\Http\Controllers\Tips\TipsController::class, 'index']);
    Route::post('/tips', [\App\Http\Controllers\Tips\TipsController::class, 'store']);
    Route::get('/tips/{id}', [\App\Http\Controllers\Tips\TipsController::class, 'show']);
    Route::put('/tips/{id}', [\App\Http\Controllers\Tips\TipsController::class, 'update']);
    Route::delete('/tips/{id}', [\App\Http\Controllers\Tips\TipsController::class, 'destroy']);
    Route::get('/notifications', [\App\Http\Controllers\Notification\NotificationController::class, 'index']);
    Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Notification\NotificationController::class, 'markAsRead']);
    Route::post('/notifications/mark-all-read', [\App\Http\Controllers\Notification\NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [\App\Http\Controllers\Notification\NotificationController::class, 'destroy']);
    Route::post('/notifications/clear-all', [\App\Http\Controllers\Notification\NotificationController::class, 'clearAll']);
});

/*
|--------------------------------------------------------------------------
| AREAS (AREA MANAGER)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:area_manager'])->prefix('areas')->group(function () {
    Route::get('/', [\App\Http\Controllers\AreaController::class, 'index']);
    Route::post('/', [\App\Http\Controllers\AreaController::class, 'store']);
    Route::get('/{id}', [\App\Http\Controllers\AreaController::class, 'show']);
    Route::put('/{id}', [\App\Http\Controllers\AreaController::class, 'update']);
    Route::delete('/{id}', [\App\Http\Controllers\AreaController::class, 'destroy']);
});

require __DIR__.'/vendor_api.php';






Route::get('/debug/booking-slots', function () {
    return response()->json([
        'date' => '2026-08-13',

        'settings' => \App\Models\JobSchedulingSetting::current()->toArray(),

        'slots' => \App\Models\JobTimeSlot::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('start_time')
            ->get()
            ->toArray(),

        'available_slots' => \App\Services\JobSchedulingService::availableSlots('2026-08-13'),
    ]);
});