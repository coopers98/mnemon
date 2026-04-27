<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Mnemon')]
#[Version('1.0.0')]
#[Instructions('Mnemon is a self-hosted second brain. Use palace tools for verbatim storage, wiki tools for synthesized knowledge.')]
class MnemonServer extends Server
{
    protected array $tools = [
        \App\Mcp\Tools\DrawerSearchTool::class,
        \App\Mcp\Tools\DrawerGetTool::class,
        \App\Mcp\Tools\DrawerAddTool::class,
        \App\Mcp\Tools\BrainStatusTool::class,
    ];
    protected array $resources = [];
    protected array $prompts = [];
}
