<?php

use App\Controllers\AdminCleanerController;
use App\Controllers\AdminController;
use App\Controllers\AdminPropertyController;
use App\Controllers\AdminPropertyLinkController;
use App\Controllers\AdminUserController;
use App\Controllers\AuthController;
use App\Controllers\DashboardApiController;
use App\Controllers\HomeController;
use App\Controllers\ICalExportController;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\TwigGlobalMiddleware;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app): void {
    /** @var ContainerInterface $container */
    $container = $app->getContainer();
    // Public iCal export route (no authentication required)
    $app->get('/ical/{guid}', [ICalExportController::class, 'export'])->setName('ical.export');

    $app->get('/login', [AuthController::class, 'showLogin'])->setName('login.form');
    $app->post('/login', [AuthController::class, 'handleLogin'])->setName('login.submit');
    $app->get('/login/verify', [AuthController::class, 'showCodeForm'])->setName('login.verify.form');
    $app->post('/login/verify', [AuthController::class, 'handleCodeVerification'])->setName('login.verify.submit');
    $app->get('/logout', [AuthController::class, 'logout'])->setName('logout');

    $app->group('', function (RouteCollectorProxy $group) use ($container): void {
        $group->get('/', [HomeController::class, 'dashboard'])->setName('home');
        $group->get('/dashboard', [HomeController::class, 'dashboard'])->setName('dashboard');

        // Dashboard API routes
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

        // Admin routes
        $group->get('/admin', [AdminController::class, 'index'])->setName('admin.index');

        // Users
        $group->get('/admin/users', [AdminUserController::class, 'index'])->setName('admin.users.index');
        $group->get('/admin/users/create', [AdminUserController::class, 'create'])->setName('admin.users.create');
        $group->post('/admin/users', [AdminUserController::class, 'store'])->setName('admin.users.store');
        $group->get('/admin/users/{id}/edit', [AdminUserController::class, 'edit'])->setName('admin.users.edit');
        $group->post('/admin/users/{id}', [AdminUserController::class, 'update'])->setName('admin.users.update');
        $group->get('/admin/users/{id}/toggle-active', [AdminUserController::class, 'toggleActive'])->setName('admin.users.toggle_active');
        $group->get('/admin/users/{id}/delete', [AdminUserController::class, 'delete'])->setName('admin.users.delete');

        // Properties
        $group->get('/admin/properties', [AdminPropertyController::class, 'index'])->setName('admin.properties.index');
        $group->get('/admin/properties/create', [AdminPropertyController::class, 'create'])->setName('admin.properties.create');
        $group->post('/admin/properties', [AdminPropertyController::class, 'store'])->setName('admin.properties.store');
        $group->get('/admin/properties/{id}/edit', [AdminPropertyController::class, 'edit'])->setName('admin.properties.edit');
        $group->post('/admin/properties/{id}', [AdminPropertyController::class, 'update'])->setName('admin.properties.update');
        $group->get('/admin/properties/{id}/delete', [AdminPropertyController::class, 'delete'])->setName('admin.properties.delete');

        // Property Links
        $group->get('/admin/property-links', [AdminPropertyLinkController::class, 'index'])->setName('admin.property_links.index');
        $group->get('/admin/property-links/create', [AdminPropertyLinkController::class, 'create'])->setName('admin.property_links.create');
        $group->post('/admin/property-links', [AdminPropertyLinkController::class, 'store'])->setName('admin.property_links.store');

        // Cleaners
        $group->get('/admin/cleaners', [AdminCleanerController::class, 'index'])->setName('admin.cleaners.index');
        $group->get('/admin/cleaners/create', [AdminCleanerController::class, 'create'])->setName('admin.cleaners.create');
        $group->post('/admin/cleaners', [AdminCleanerController::class, 'store'])->setName('admin.cleaners.store');
        $group->get('/admin/cleaners/{id}/edit', [AdminCleanerController::class, 'edit'])->setName('admin.cleaners.edit');
        $group->post('/admin/cleaners/{id}', [AdminCleanerController::class, 'update'])->setName('admin.cleaners.update');
        $group->get('/admin/cleaners/{id}/delete', [AdminCleanerController::class, 'delete'])->setName('admin.cleaners.delete');
    })
    ->add(AuthenticationMiddleware::class)
    ->add($container->get(TwigGlobalMiddleware::class));
};

