<?php

declare(strict_types=1);

namespace Freento\McpWrite\Api;

use Freento\Mcp\Api\ToolInterface;

/**
 * Marker interface for MCP tools that change store data.
 *
 * Tools implementing it are hidden from tools/list and cannot be called
 * while "Enable Write Tools" is turned off in admin configuration.
 */
interface WriteToolInterface extends ToolInterface
{
}
