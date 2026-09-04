<?php

declare(strict_types=1);

use NeuroSYS\Http\Request;
use NeuroSYS\Router;
use NeuroSYS\Service\Auth;
use NeuroSYS\Support\RouteInitialization;

require __DIR__ . '/../autoload.php';

$request = Request::fromGlobals();

Auth::requireSiteAuth($request);

new Router(RouteInitialization::routes())->dispatch($request)->send($request);
