<?php

namespace OPGG\LaravelMcpServer\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use OPGG\LaravelMcpServer\Data\Resources\JsonRpc\JsonRpcErrorResource as McpJsonRpcErrorResource;
use OPGG\LaravelMcpServer\Data\Resources\JsonRpc\JsonRpcResultResource;
use OPGG\LaravelMcpServer\Exceptions\Enums\JsonRpcErrorCode;
use OPGG\LaravelMcpServer\Exceptions\JsonRpcErrorException;
use OPGG\LaravelMcpServer\Server\MCPServer;
use OPGG\LaravelMcpServer\Server\Request\InitializeHandler;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseController extends Controller
{
    public function resolveSseRequest(Request $request, MCPServer $mcpServer)
    {
        if ($request->isMethod('POST')) {
            $messageJson = null; // Initialize for use in catch block
            try {
                $content = $request->getContent();
                if (empty($content)) {
                    throw new JsonRpcErrorException('Request body is empty', JsonRpcErrorCode::INVALID_REQUEST);
                }

                $messageJson = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

                if (!isset($messageJson['jsonrpc'], $messageJson['method'], $messageJson['id'])) {
                    throw new JsonRpcErrorException('Invalid JSON-RPC request structure', JsonRpcErrorCode::INVALID_REQUEST);
                }
                if ($messageJson['jsonrpc'] !== '2.0') {
                    throw new JsonRpcErrorException('Invalid JSON-RPC version', JsonRpcErrorCode::INVALID_REQUEST);
                }

                if ($messageJson['method'] === 'initialize') {
                    $initializeHandler = new InitializeHandler($mcpServer);
                    $params = $messageJson['params'] ?? [];
                    $resultPayload = $initializeHandler->execute('initialize', $params);

                    $jsonRpcResponse = new JsonRpcResultResource(id: $messageJson['id'], result: $resultPayload);
                    return response()->json($jsonRpcResponse->toResponse());
                } else {
                    throw new JsonRpcErrorException("Method '{$messageJson['method']}' not supported via POST on SSE endpoint", JsonRpcErrorCode::METHOD_NOT_FOUND);
                }
            } catch (\JsonException $e) {
                $error = new JsonRpcErrorException('Parse error: Invalid JSON. ' . $e->getMessage(), JsonRpcErrorCode::PARSE_ERROR);
                $responseResource = new McpJsonRpcErrorResource($error, $messageJson['id'] ?? null); // Use $messageJson after it might have been set
                return response()->json($responseResource->toResponse(), 400);
            } catch (JsonRpcErrorException $e) {
                $responseResource = new McpJsonRpcErrorResource($e, $messageJson['id'] ?? null);
                // Determine appropriate HTTP status code based on JSON-RPC error if desired
                $httpStatusCode = match($e->getJsonRpcErrorCode()) {
                    JsonRpcErrorCode::PARSE_ERROR->value, JsonRpcErrorCode::INVALID_REQUEST->value => 400,
                    JsonRpcErrorCode::METHOD_NOT_FOUND->value => 404,
                    default => 400, // Default to Bad Request for other client-side JSON-RPC errors
                };
                return response()->json($responseResource->toResponse(), $httpStatusCode);
            } catch (\Throwable $e) {
                Log::error('MCP SSE POST Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), ['trace' => $e->getTraceAsString()]);
                $error = new JsonRpcErrorException('Internal server error: ' . $e->getMessage(), JsonRpcErrorCode::INTERNAL_ERROR);
                $responseResource = new McpJsonRpcErrorResource($error, $messageJson['id'] ?? null);
                return response()->json($responseResource->toResponse(), 500);
            }
        } elseif ($request->isMethod('GET')) {
            // Optionally, check if server was initialized if your logic requires it before streaming
            // if (!$mcpServer->isInitialized()) {
            //     Log::warning('MCP SSE GET request: Server not initialized. Client should have POSTed initialize first or send initialize over SSE.');
            //     // Depending on strictness, you might return an error or allow connection
            // }

            return new StreamedResponse(fn () => $mcpServer->connect(), headers: [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no', // Handles issues with Nginx/proxy buffering
            ]);
        }

        // Fallback for any other HTTP methods if the route was defined more broadly
        return response('Method not supported on this endpoint', 405);
    }
}
