<?php

namespace App\Http\Controllers;

use App\Mcp\McpException;
use App\Mcp\McpToolRegistry;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class McpController extends Controller
{
    /**
     * Handle an MCP JSON-RPC tool call.
     */
    public function call(Request $request): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        $body = $request->json()->all();

        $tool = $body['tool'] ?? null;
        $params = $body['params'] ?? [];

        if (empty($tool)) {
            return response()->json([
                'error' => 'Missing required field: tool',
            ], 422);
        }

        if (! is_array($params)) {
            return response()->json([
                'error' => 'Field "params" must be an object.',
            ], 422);
        }

        try {
            $handler = McpToolRegistry::resolve($tool);
            $result = $handler->execute($params, $apiKey);

            return response()->json(['result' => $result]);
        } catch (McpException $e) {
            return response()->json([
                'error' => $e->getMessage(),
                'code' => $e->getMcpCode(),
            ], $e->getCode());
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Internal server error.',
                'code' => -32000,
            ], 500);
        }
    }

    /**
     * List available tools.
     */
    public function tools(): JsonResponse
    {
        return response()->json([
            'tools' => McpToolRegistry::toolNames(),
        ]);
    }
}
