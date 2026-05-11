<?php

declare(strict_types=1);

use App\Middleware\HostnameRoutingMiddleware;
use App\Services\ConfigService;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
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
$app->add($container->get(HostnameRoutingMiddleware::class));

/** @var ConfigService $config */
$config = $container->get(ConfigService::class);
$displayErrorDetails = (bool) $config::get('app.debug', false);

$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, true, true);
$defaultErrorHandler = $errorMiddleware->getDefaultErrorHandler();
$errorMiddleware->setErrorHandler(
    HttpNotFoundException::class,
    function (
        ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetailsFlag,
        bool $logErrors,
        bool $logErrorDetails
    ) use ($app, $defaultErrorHandler): ResponseInterface {
        if ($request->getAttribute('portal_face') === 'guest') {
            $path = $request->getUri()->getPath();
            if (str_starts_with($path, '/api/')) {
                $response = $app->getResponseFactory()->createResponse(404);
                $response->getBody()->write((string) json_encode(['error' => 'Not found']));
                return $response->withHeader('Content-Type', 'application/json');
            }

            return $app->getResponseFactory()
                ->createResponse(302)
                ->withHeader('Location', '/');
        }

        return $defaultErrorHandler(
            $request,
            $exception,
            $displayErrorDetailsFlag,
            $logErrors,
            $logErrorDetails
        );
    }
);

$routes = require BASE_DIR . '/config/routes.php';
$routes($app);

$app->run();

