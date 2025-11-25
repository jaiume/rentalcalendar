<?php

use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Middleware\AuthenticationMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/', [HomeController::class, 'index'])->setName('home');

    $app->get('/login', [AuthController::class, 'showLogin'])->setName('login.form');
    $app->post('/login', [AuthController::class, 'handleLogin'])->setName('login.submit');
    $app->get('/logout', [AuthController::class, 'logout'])->setName('logout');

    $app->group('', static function (RouteCollectorProxy $group): void {
        $group->get('/dashboard', [HomeController::class, 'dashboard'])->setName('dashboard');
        $group->get('/admin', [HomeController::class, 'dashboard'])->setName('admin.index');
    })->add(AuthenticationMiddleware::class);
};

