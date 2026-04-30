<?php

use App\Controllers\AdminCleanerController;
use App\Controllers\AdminController;
use App\Controllers\AdminPaymentController;
use App\Controllers\AdminPortalGroupController;
use App\Controllers\AdminPropertyController;
use App\Controllers\AdminPropertyLinkController;
use App\Controllers\AdminSupplyRequestController;
use App\Controllers\AdminUserController;
use App\Controllers\AuthController;
use App\Controllers\DashboardApiController;
use App\Controllers\DebugController;
use App\Controllers\GuestPortalController;
use App\Controllers\HomeController;
use App\Controllers\ICalExportController;
use App\Controllers\LaundryPaymentController;
use App\Exceptions\PortalConfigException;
use App\Exceptions\PortalNotFoundException;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\TwigGlobalMiddleware;
use App\Services\ConfigService;
use App\Services\PortalGroupResolver;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    /** @var ContainerInterface $container */
    $container = $app->getContainer();

    // Decide once at registration time which face to mount. Registering
    // both faces would conflict at "/" in Slim's router. The middleware
    // (HostnameRoutingMiddleware) has already 404'd unknown hosts before
    // routing runs, but we still need to know the face here so the right
    // route table is built for THIS request.
    $portalFace = resolvePortalFace($container);

    if ($portalFace === 'guest') {
        registerGuestRoutes($app);
        return;
    }

    registerAdminRoutes($app, $container);
};

function resolvePortalFace(ContainerInterface $container): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (!is_string($host) || $host === '') {
        return 'admin';
    }

    $hostname = PortalGroupResolver::normalizeHostname($host);
    if ($hostname === '') {
        return 'admin';
    }

    /** @var PortalGroupResolver $resolver */
    $resolver = $container->get(PortalGroupResolver::class);
    try {
        $portal = $resolver->resolveGuestByHostname($hostname);
    } catch (PortalNotFoundException | PortalConfigException $e) {
        $portal = null;
    }

    if ($portal !== null) {
        return 'guest';
    }

    // Default to admin; HostnameRoutingMiddleware will 404 unknown
    // hosts before any of these admin routes can match.
    return 'admin';
}

function registerGuestRoutes(App $app): void
{
    $app->get('/', [GuestPortalController::class, 'home'])->setName('guest.home');
    $app->get('/laundry', [GuestPortalController::class, 'laundry'])->setName('guest.laundry');
    $app->get('/supplies', [GuestPortalController::class, 'supplies'])->setName('guest.supplies');
    $app->post('/supplies', [GuestPortalController::class, 'submitSupplies'])->setName('guest.supplies.submit');
    $app->get('/supplies/thanks', [GuestPortalController::class, 'suppliesThanks'])->setName('guest.supplies.thanks');

    $app->get('/api/paypal/config', [GuestPortalController::class, 'paypalConfig'])->setName('guest.api.paypal_config');
    $app->post('/api/laundry/orders', [LaundryPaymentController::class, 'createOrder'])->setName('guest.api.laundry.create_order');
    $app->post('/api/laundry/orders/{orderId}/capture', [LaundryPaymentController::class, 'captureOrder'])->setName('guest.api.laundry.capture');
}

function registerAdminRoutes(App $app, ContainerInterface $container): void
{
    $app->get('/ical/{guid}', [ICalExportController::class, 'export'])->setName('ical.export');

    $app->get('/login', [AuthController::class, 'showLogin'])->setName('login.form');
    $app->post('/login', [AuthController::class, 'handleLogin'])->setName('login.submit');
    $app->get('/login/verify', [AuthController::class, 'showCodeForm'])->setName('login.verify.form');
    $app->post('/login/verify', [AuthController::class, 'handleCodeVerification'])->setName('login.verify.submit');
    $app->get('/logout', [AuthController::class, 'logout'])->setName('logout');

    $app->get('/debug/logs', [DebugController::class, 'showLogs'])->setName('debug.logs');
    $app->post('/debug/logs/clear', [DebugController::class, 'clearLogs'])->setName('debug.logs.clear');
    $app->get('/debug/info', [DebugController::class, 'showInfo'])->setName('debug.info');

    $app->group('', function (RouteCollectorProxy $group) use ($container): void {
        $group->get('/', [HomeController::class, 'dashboard'])->setName('home');
        $group->get('/dashboard', [HomeController::class, 'dashboard'])->setName('dashboard');

        $group->get('/api/dashboard/properties', [DashboardApiController::class, 'getProperties'])->setName('api.dashboard.properties');
        $group->get('/api/dashboard/events', [DashboardApiController::class, 'getEvents'])->setName('api.dashboard.events');
        $group->get('/api/dashboard/cleaners', [DashboardApiController::class, 'getCleaners'])->setName('api.dashboard.cleaners');
        $group->get('/api/dashboard/sync', [DashboardApiController::class, 'syncCalendar'])->setName('api.dashboard.sync');
        $group->get('/api/dashboard/check-sync', [DashboardApiController::class, 'checkSyncNeeded'])->setName('api.dashboard.check_sync');
        $group->post('/api/dashboard/reservations', [DashboardApiController::class, 'createReservation'])->setName('api.dashboard.create_reservation');
        $group->post('/api/dashboard/cleaning', [DashboardApiController::class, 'createCleaning'])->setName('api.dashboard.create_cleaning');
        $group->post('/api/dashboard/maintenance', [DashboardApiController::class, 'createMaintenance'])->setName('api.dashboard.create_maintenance');
        $group->delete('/api/dashboard/reservations/{id}', [DashboardApiController::class, 'deleteReservation'])->setName('api.dashboard.delete_reservation');
        $group->delete('/api/dashboard/cleaning/{id}', [DashboardApiController::class, 'deleteCleaning'])->setName('api.dashboard.delete_cleaning');
        $group->delete('/api/dashboard/maintenance/{id}', [DashboardApiController::class, 'deleteMaintenance'])->setName('api.dashboard.delete_maintenance');

        $group->get('/admin', [AdminController::class, 'index'])->setName('admin.index');

        $group->get('/admin/users', [AdminUserController::class, 'index'])->setName('admin.users.index');
        $group->get('/admin/users/create', [AdminUserController::class, 'create'])->setName('admin.users.create');
        $group->post('/admin/users', [AdminUserController::class, 'store'])->setName('admin.users.store');
        $group->get('/admin/users/{id}/edit', [AdminUserController::class, 'edit'])->setName('admin.users.edit');
        $group->post('/admin/users/{id}', [AdminUserController::class, 'update'])->setName('admin.users.update');
        $group->get('/admin/users/{id}/permissions', [AdminUserController::class, 'permissions'])->setName('admin.users.permissions');
        $group->post('/admin/users/{id}/permissions', [AdminUserController::class, 'updatePermissions'])->setName('admin.users.update_permissions');
        $group->get('/admin/users/{id}/toggle-active', [AdminUserController::class, 'toggleActive'])->setName('admin.users.toggle_active');
        $group->get('/admin/users/{id}/delete', [AdminUserController::class, 'delete'])->setName('admin.users.delete');

        $group->get('/admin/properties', [AdminPropertyController::class, 'index'])->setName('admin.properties.index');
        $group->get('/admin/properties/create', [AdminPropertyController::class, 'create'])->setName('admin.properties.create');
        $group->post('/admin/properties', [AdminPropertyController::class, 'store'])->setName('admin.properties.store');
        $group->get('/admin/properties/{id}/edit', [AdminPropertyController::class, 'edit'])->setName('admin.properties.edit');
        $group->post('/admin/properties/{id}', [AdminPropertyController::class, 'update'])->setName('admin.properties.update');
        $group->get('/admin/properties/{id}/delete', [AdminPropertyController::class, 'delete'])->setName('admin.properties.delete');

        $group->get('/admin/property-links', [AdminPropertyLinkController::class, 'index'])->setName('admin.property_links.index');
        $group->get('/admin/property-links/create', [AdminPropertyLinkController::class, 'create'])->setName('admin.property_links.create');
        $group->post('/admin/property-links', [AdminPropertyLinkController::class, 'store'])->setName('admin.property_links.store');

        $group->get('/admin/cleaners', [AdminCleanerController::class, 'index'])->setName('admin.cleaners.index');
        $group->get('/admin/cleaners/create', [AdminCleanerController::class, 'create'])->setName('admin.cleaners.create');
        $group->get('/admin/cleaners/schedule', [AdminCleanerController::class, 'schedule'])->setName('admin.cleaners.schedule');
        $group->post('/admin/cleaners', [AdminCleanerController::class, 'store'])->setName('admin.cleaners.store');
        $group->get('/admin/cleaners/{id}/edit', [AdminCleanerController::class, 'edit'])->setName('admin.cleaners.edit');
        $group->post('/admin/cleaners/{id}', [AdminCleanerController::class, 'update'])->setName('admin.cleaners.update');
        $group->get('/admin/cleaners/{id}/delete', [AdminCleanerController::class, 'delete'])->setName('admin.cleaners.delete');
        $group->get('/api/admin/cleaners/schedule', [AdminCleanerController::class, 'getScheduleData'])->setName('api.admin.cleaners.schedule');
        $group->get('/api/admin/cleaners/weeks', [AdminCleanerController::class, 'getAvailableWeeks'])->setName('api.admin.cleaners.weeks');

        $group->get('/admin/portal-groups', [AdminPortalGroupController::class, 'index'])->setName('admin.portal_groups.index');
        $group->get('/admin/portal-groups/create', [AdminPortalGroupController::class, 'create'])->setName('admin.portal_groups.create');
        $group->post('/admin/portal-groups', [AdminPortalGroupController::class, 'store'])->setName('admin.portal_groups.store');
        $group->get('/admin/portal-groups/{id}/edit', [AdminPortalGroupController::class, 'edit'])->setName('admin.portal_groups.edit');
        $group->post('/admin/portal-groups/{id}', [AdminPortalGroupController::class, 'update'])->setName('admin.portal_groups.update');
        $group->get('/admin/portal-groups/{id}/delete', [AdminPortalGroupController::class, 'delete'])->setName('admin.portal_groups.delete');

        $group->get('/admin/supply-requests', [AdminSupplyRequestController::class, 'index'])->setName('admin.supply_requests.index');
        $group->post('/admin/supply-requests/{id}/status', [AdminSupplyRequestController::class, 'updateStatus'])->setName('admin.supply_requests.update_status');

        $group->get('/admin/payments', [AdminPaymentController::class, 'index'])->setName('admin.payments.index');
    })
    ->add(AuthenticationMiddleware::class)
    ->add($container->get(TwigGlobalMiddleware::class));
}
