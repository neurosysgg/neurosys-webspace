<?php
declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use NeuroSYS\Http\Request;
use NeuroSYS\Router;
use NeuroSYS\Service\Auth;

$request = Request::fromGlobals();

Auth::requireSiteAuth($request);

new Router()->dispatch($request)->send($request);
