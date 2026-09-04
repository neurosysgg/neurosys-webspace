<?php

declare(strict_types=1);

use NeuroSYS\Http\Request;
use NeuroSYS\Http\SecurityHeaders;
use NeuroSYS\Router;
use NeuroSYS\Service\Auth;
use NeuroSYS\Support\RouteInitialization;

require __DIR__ . '/../autoload.php';

SecurityHeaders::send();

$request = Request::fromGlobals();

Auth::requireSiteAuth($request);

new Router(RouteInitialization::routes())->dispatch($request)->send($request);
