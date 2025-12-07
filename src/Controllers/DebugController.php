<?php

namespace App\Controllers;

use App\Services\ConfigService;
use App\Services\LogService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;
use Slim\Views\Twig;

class DebugController
{
    public function __construct(
        private readonly Twig $view,
        private readonly ConfigService $config
    ) {
    }

    public function showLogs(Request $request, Response $response): Response
    {
        // Only allow in debug mode
        if (!$this->config::get('app.debug', false)) {
            $response = new SlimResponse();
            $response->getBody()->write('Debug mode is disabled');
            return $response->withStatus(403);
        }

        $params = $request->getQueryParams();
        $lines = (int) ($params['lines'] ?? 200);
        $lines = min(max($lines, 50), 1000); // Between 50 and 1000

        $logs = LogService::getRecentLogs($lines);

        return $this->view->render($response, 'debug_logs.twig', [
            'logs' => $logs,
            'lines' => $lines,
        ]);
    }

    public function clearLogs(Request $request): Response
    {
        // Only allow in debug mode
        if (!$this->config::get('app.debug', false)) {
            $response = new SlimResponse();
            $response->getBody()->write('Debug mode is disabled');
            return $response->withStatus(403);
        }

        $logFile = BASE_DIR . '/logs/app.log';
        if (file_exists($logFile)) {
            file_put_contents($logFile, '');
        }

        $response = new SlimResponse();
        return $response
            ->withHeader('Location', '/debug/logs?success=1')
            ->withStatus(302);
    }

    public function showInfo(Request $request, Response $response): Response
    {
        // Only allow in debug mode
        if (!$this->config::get('app.debug', false)) {
            $response = new SlimResponse();
            $response->getBody()->write('Debug mode is disabled');
            return $response->withStatus(403);
        }

        $cookies = $request->getCookieParams();
        $server = $_SERVER;
        
        // Hide sensitive information
        $hiddenKeys = ['HTTP_AUTHORIZATION', 'PHP_AUTH_PW', 'HTTP_COOKIE'];
        foreach ($hiddenKeys as $key) {
            if (isset($server[$key])) {
                $server[$key] = '[HIDDEN]';
            }
        }

        return $this->view->render($response, 'debug_info.twig', [
            'cookies' => $cookies,
            'server' => $server,
            'php_version' => PHP_VERSION,
        ]);
    }
}

