<?php

use App\Mcp\Servers\MnemonServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp', MnemonServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);
