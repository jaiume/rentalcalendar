<?php

use App\Controllers\AdminCleanerController;
use App\Controllers\AdminPropertyController;
use App\Controllers\AdminPropertyLinkController;
use App\Controllers\AdminUserController;
use App\Controllers\AuthController;
use App\Controllers\DashboardApiController;
use App\Controllers\HomeController;
use App\Controllers\ICalExportController;
use App\DAO\AuthTokenDAO;
use App\DAO\CleanerDAO;
use App\DAO\CleaningDAO;
use App\DAO\LoginCodeDAO;
use App\DAO\MaintenanceDAO;
use App\DAO\PropertyCalendarImportLinkDAO;
use App\DAO\PropertyDAO;
use App\DAO\ReservationDAO;
use App\DAO\UserDAO;
use App\DAO\UserPropertyPermissionDAO;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\TwigGlobalMiddleware;
use App\Services\AuthenticationService;
use App\Services\ConfigService;
use App\Services\ICalParser;
use App\Services\SyncPartners\AirBNBHandler;
use App\Services\SyncService;
use App\Services\UtilityService;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;
use function DI\create;

return [
    ConfigService::class => create(ConfigService::class),
    
    // DAOs
    PropertyDAO::class => static function (ContainerInterface $container): PropertyDAO {
        return new PropertyDAO($container->get(PDO::class));
    },
    
    CleanerDAO::class => static function (ContainerInterface $container): CleanerDAO {
        return new CleanerDAO($container->get(PDO::class));
    },
    
    UserDAO::class => static function (ContainerInterface $container): UserDAO {
        return new UserDAO($container->get(PDO::class));
    },
    
    ReservationDAO::class => static function (ContainerInterface $container): ReservationDAO {
        return new ReservationDAO($container->get(PDO::class));
    },
    
    CleaningDAO::class => static function (ContainerInterface $container): CleaningDAO {
        return new CleaningDAO($container->get(PDO::class));
    },
    
    MaintenanceDAO::class => static function (ContainerInterface $container): MaintenanceDAO {
        return new MaintenanceDAO($container->get(PDO::class));
    },
    
    PropertyCalendarImportLinkDAO::class => static function (ContainerInterface $container): PropertyCalendarImportLinkDAO {
        return new PropertyCalendarImportLinkDAO($container->get(PDO::class));
    },
    
    LoginCodeDAO::class => static function (ContainerInterface $container): LoginCodeDAO {
        return new LoginCodeDAO($container->get(PDO::class));
    },
    
    AuthTokenDAO::class => static function (ContainerInterface $container): AuthTokenDAO {
        return new AuthTokenDAO($container->get(PDO::class));
    },
    
    UserPropertyPermissionDAO::class => static function (ContainerInterface $container): UserPropertyPermissionDAO {
        return new UserPropertyPermissionDAO($container->get(PDO::class));
    },

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
            $container->get(UserDAO::class),
            $container->get(LoginCodeDAO::class),
            $container->get(AuthTokenDAO::class),
            $container->get(ConfigService::class)
        );
    },

    ICalParser::class => create(ICalParser::class),

    AirBNBHandler::class => static function (ContainerInterface $container): AirBNBHandler {
        return new AirBNBHandler(
            $container->get(ReservationDAO::class),
            $container->get(PropertyCalendarImportLinkDAO::class),
            $container->get(ConfigService::class),
            $container->get(ICalParser::class)
        );
    },

    SyncService::class => static function (ContainerInterface $container): SyncService {
        return new SyncService(
            $container->get(PropertyCalendarImportLinkDAO::class),
            $container->get(ConfigService::class),
            $container->get(AirBNBHandler::class)
        );
    },

    AuthenticationMiddleware::class => static function (ContainerInterface $container): AuthenticationMiddleware {
        return new AuthenticationMiddleware(
            $container->get(AuthenticationService::class),
            $container->get(ConfigService::class)
        );
    },

    TwigGlobalMiddleware::class => static function (ContainerInterface $container): TwigGlobalMiddleware {
        return new TwigGlobalMiddleware(
            $container->get(Twig::class)
        );
    },

    DashboardApiController::class => static function (ContainerInterface $container): DashboardApiController {
        return new DashboardApiController(
            $container->get(PropertyDAO::class),
            $container->get(CleanerDAO::class),
            $container->get(ReservationDAO::class),
            $container->get(CleaningDAO::class),
            $container->get(MaintenanceDAO::class),
            $container->get(UserPropertyPermissionDAO::class),
            $container->get(SyncService::class)
        );
    },

    ICalExportController::class => static function (ContainerInterface $container): ICalExportController {
        return new ICalExportController(
            $container->get(PropertyDAO::class),
            $container->get(ReservationDAO::class),
            $container->get(MaintenanceDAO::class),
            $container->get(ConfigService::class)
        );
    },
    
    // Controllers
    AdminPropertyController::class => static function (ContainerInterface $container): AdminPropertyController {
        return new AdminPropertyController(
            $container->get(Twig::class),
            $container->get(PropertyDAO::class),
            $container->get(UtilityService::class)
        );
    },
    
    AdminCleanerController::class => static function (ContainerInterface $container): AdminCleanerController {
        return new AdminCleanerController(
            $container->get(Twig::class),
            $container->get(CleanerDAO::class)
        );
    },
    
    AdminUserController::class => static function (ContainerInterface $container): AdminUserController {
        return new AdminUserController(
            $container->get(Twig::class),
            $container->get(UserDAO::class),
            $container->get(PropertyDAO::class),
            $container->get(UserPropertyPermissionDAO::class)
        );
    },
    
    AdminPropertyLinkController::class => static function (ContainerInterface $container): AdminPropertyLinkController {
        return new AdminPropertyLinkController(
            $container->get(Twig::class),
            $container->get(PropertyCalendarImportLinkDAO::class),
            $container->get(PropertyDAO::class)
        );
    },
    
    HomeController::class => static function (ContainerInterface $container): HomeController {
        return new HomeController(
            $container->get(Twig::class),
            $container->get(UtilityService::class),
            $container->get(ConfigService::class),
            $container->get(PropertyDAO::class),
            $container->get(UserPropertyPermissionDAO::class)
        );
    },
];
