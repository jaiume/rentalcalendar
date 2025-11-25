<?php

use App\Middleware\AuthenticationMiddleware;
use App\Services\AuthenticationService;
use App\Services\ConfigService;
use App\Services\UtilityService;
use PDO;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use function DI\create;

return [
    ConfigService::class => create(ConfigService::class),

    PDO::class => static function (): PDO {
        $driver = ConfigService::get('database.driver', 'mysql');
        $host = ConfigService::get('database.host', 'localhost');
        $port = ConfigService::get('database.port', 3306);
        $name = ConfigService::get('database.name', '');
        $charset = ConfigService::get('database.charset', 'utf8mb4');
        $user = ConfigService::get('database.user', '');
        $pass = ConfigService::get('database.pass', '');

        $dsn = sprintf('%s:host=%s;port=%s;dbname=%s;charset=%s', $driver, $host, $port, $name, $charset);

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        return new PDO($dsn, $user, $pass, $options);
    },

    Twig::class => static function (): Twig {
        return Twig::create(BASE_DIR . '/templates', [
            'cache' => false,
        ]);
    },

    UtilityService::class => static function (ContainerInterface $container): UtilityService {
        return new UtilityService($container->get(ConfigService::class));
    },

    AuthenticationService::class => static function (ContainerInterface $container): AuthenticationService {
        return new AuthenticationService(
            $container->get(PDO::class),
            $container->get(ConfigService::class)
        );
    },

    AuthenticationMiddleware::class => static function (ContainerInterface $container): AuthenticationMiddleware {
        return new AuthenticationMiddleware(
            $container->get(AuthenticationService::class),
            $container->get(ConfigService::class)
        );
    },
];

