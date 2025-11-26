<?php

declare(strict_types=1);

use App\Middleware\TwigGlobalMiddleware;
use App\Services\ConfigService;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

define('BASE_DIR', __DIR__);

require BASE_DIR . '/vendor/autoload.php';

if (file_exists(BASE_DIR . '/.env')) {
    Dotenv::createImmutable(BASE_DIR)->safeLoad();
}

$containerBuilder = new ContainerBuilder();
$containerBuilder->addDefinitions(require BASE_DIR . '/config/container.php');
$container = $containerBuilder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));

/** @var ConfigService $config */
$config = $container->get(ConfigService::class);
$displayErrorDetails = (bool) $config::get('app.debug', false);

$app->addErrorMiddleware($displayErrorDetails, true, true);

$routes = require BASE_DIR . '/config/routes.php';
$routes($app);

$app->run();

