<?php

namespace OPGG\LaravelMcpServer\Server\Request;

use OPGG\LaravelMcpServer\Exceptions\Enums\JsonRpcErrorCode;
use OPGG\LaravelMcpServer\Exceptions\JsonRpcErrorException;
use OPGG\LaravelMcpServer\Protocol\Handlers\RequestHandler;
use OPGG\LaravelMcpServer\Services\ToolService\ToolRepository;

class ToolsCallHandler implements RequestHandler
{
    private ToolRepository $toolRepository;

    public function __construct(ToolRepository $toolRepository)
    {
        $this->toolRepository = $toolRepository;
    }

    public function isHandle(string $method): bool
    {
        return $method === 'tools/call' || $method === 'tools/execute';
    }

    public function execute(string $method, ?array $params = null): array
    {
        $name = $params['name'] ?? null;
        if ($name === null) {
            throw new JsonRpcErrorException(message: 'Tool name is required', code: JsonRpcErrorCode::INVALID_REQUEST);
        }

        $tool = $this->toolRepository->getTool($name);
        if (! $tool) {
            throw new JsonRpcErrorException(message: "Tool '{$name}' not found", code: JsonRpcErrorCode::METHOD_NOT_FOUND);
        }

        $arguments = $params['arguments'] ?? [];

        if ($method === 'tools/call') {
            try {
                $result = $tool->execute($arguments);

                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => is_string($result) ? $result : json_encode($result),
                        ],
                    ],
                    'isError' => false,
                ];
            } catch (\Exception $e) {
                return [
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => "Error executing tool '{$name}': " . $e->getMessage(),
                        ],
                    ],
                    'isError' => true,
                ];
            }
        } else { // Assuming 'tools/execute' or other methods might have a different structure
            // Original behavior for non-'tools/call' methods, though this path might need review
            // if 'tools/execute' also expects an MCP-compliant result.
            // For now, only modifying 'tools/call' as it's directly related to CallToolResult.
            try {
                $result = $tool->execute($arguments);
                return [
                    'result' => $result,
                ];
            } catch (\Exception $e) {
                // How 'tools/execute' errors should be handled is unclear from current context.
                // Throwing will lead to a generic JSON-RPC error response.
                // This might be desired or might also need adjustment later.
                throw new JsonRpcErrorException(message: "Error executing tool '{$name}' for method '{$method}': " . $e->getMessage(), code: JsonRpcErrorCode::INTERNAL_ERROR);
            }
        }
    }
}
