<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\DrawerToWikiPrompt;
use App\Mcp\Prompts\FindStaleWikiPagesPrompt;
use App\Mcp\Prompts\SynthesizeWingPrompt;
use App\Mcp\Resources\DrawerResource;
use App\Mcp\Resources\WikiPageResource;
use App\Mcp\Resources\WingResource;
use App\Mcp\Tools\BrainStatusTool;
use App\Mcp\Tools\ContextGetTool;
use App\Mcp\Tools\ContextListTool;
use App\Mcp\Tools\ContextSetTool;
use App\Mcp\Tools\DrawerAddTool;
use App\Mcp\Tools\DrawerGetTool;
use App\Mcp\Tools\DrawerSearchTool;
use App\Mcp\Tools\PalaceWakeUpTool;
use App\Mcp\Tools\RecallTool;
use App\Mcp\Tools\WikiCompileTool;
use App\Mcp\Tools\WikiGraphTool;
use App\Mcp\Tools\WikiHistoryTool;
use App\Mcp\Tools\WikiLintTool;
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
        DrawerSearchTool::class,
        DrawerGetTool::class,
        DrawerAddTool::class,
        BrainStatusTool::class,
        PalaceWakeUpTool::class,
        RecallTool::class,
        ContextGetTool::class,
        ContextSetTool::class,
        ContextListTool::class,
        WikiLintTool::class,
        WikiCompileTool::class,
        WikiHistoryTool::class,
        WikiGraphTool::class,
    ];

    protected array $resources = [
        DrawerResource::class,
        WikiPageResource::class,
        WingResource::class,
    ];

    protected array $prompts = [
        SynthesizeWingPrompt::class,
        FindStaleWikiPagesPrompt::class,
        DrawerToWikiPrompt::class,
    ];
}
