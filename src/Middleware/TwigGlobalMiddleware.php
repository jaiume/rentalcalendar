<?php

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Views\Twig;

class TwigGlobalMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Twig $view
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $user = $request->getAttribute('user');
        
        // Add user to Twig globals if available
        if ($user) {
            $this->view->getEnvironment()->addGlobal('user', $user);
        }

        return $handler->handle($request);
    }
}



