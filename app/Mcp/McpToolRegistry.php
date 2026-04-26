<?php

namespace App\Mcp;

use App\Mcp\Tools\BrainStatusTool;
use App\Mcp\Tools\ContextGetTool;
use App\Mcp\Tools\ContextListTool;
use App\Mcp\Tools\ContextSetTool;
use App\Mcp\Tools\DrawerAddTool;
use App\Mcp\Tools\DrawerGetTool;
use App\Mcp\Tools\DrawerSearchTool;
use App\Mcp\Tools\PalaceWakeUpTool;
use App\Mcp\Tools\WikiCompileTool;
use App\Mcp\Tools\WikiHistoryTool;
use App\Mcp\Tools\WikiLintTool;

class McpToolRegistry
{
    /** @var array<string, class-string<BaseTool>> */
    private static array $tools = [
        'brain_status' => BrainStatusTool::class,
        'palace_wake_up' => PalaceWakeUpTool::class,
        'drawer_add' => DrawerAddTool::class,
        'drawer_search' => DrawerSearchTool::class,
        'drawer_get' => DrawerGetTool::class,
        'context_get' => ContextGetTool::class,
        'context_set' => ContextSetTool::class,
        'context_list' => ContextListTool::class,
        'wiki_lint' => WikiLintTool::class,
        'wiki_compile' => WikiCompileTool::class,
        'wiki_history' => WikiHistoryTool::class,
    ];

    /**
     * Resolve a tool instance by name from the container.
     *
     * @throws McpException
     */
    public static function resolve(string $name): BaseTool
    {
        if (! isset(self::$tools[$name])) {
            throw new McpException("Unknown tool: {$name}", -32601, 404);
        }

        return app(self::$tools[$name]);
    }

    /**
     * @return array<string>
     */
    public static function toolNames(): array
    {
        return array_keys(self::$tools);
    }
}
