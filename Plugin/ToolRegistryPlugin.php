<?php

declare(strict_types=1);

namespace Freento\McpWrite\Plugin;

use Freento\Mcp\Api\ToolInterface;
use Freento\Mcp\Model\ToolRegistry;
use Freento\McpWrite\Api\WriteToolInterface;
use Freento\McpWrite\Model\Config;

/**
 * Hides every write tool from the registry while write tools are disabled in admin config.
 *
 * The registry feeds tools/list, tools/call and the ACL role form,
 * so a single switch removes the tools from all three places.
 */
class ToolRegistryPlugin
{
    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Filter write tools out of the full list
     *
     * @param ToolRegistry $subject
     * @param ToolInterface[] $result
     * @return ToolInterface[]
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetAll(ToolRegistry $subject, array $result): array
    {
        if ($this->config->isEnabled()) {
            return $result;
        }

        return array_filter(
            $result,
            static fn (ToolInterface $tool) => !$tool instanceof WriteToolInterface
        );
    }

    /**
     * Prevent direct lookup of a write tool
     *
     * @param ToolRegistry $subject
     * @param ToolInterface|null $result
     * @return ToolInterface|null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGet(ToolRegistry $subject, ?ToolInterface $result): ?ToolInterface
    {
        if ($result === null || $this->config->isEnabled()) {
            return $result;
        }

        return $result instanceof WriteToolInterface ? null : $result;
    }
}
