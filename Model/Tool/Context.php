<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Tool;

use Freento\Mcp\Model\ToolResultFactory;
use Freento\McpWrite\Model\Config;
use Magento\Framework\App\State;

/**
 * Dependencies shared by every write tool, so a tool constructor lists only its own services
 */
class Context
{
    /**
     * @param ToolResultFactory $resultFactory
     * @param Config $config
     * @param State $appState
     */
    public function __construct(
        public readonly ToolResultFactory $resultFactory,
        public readonly Config $config,
        public readonly State $appState
    ) {
    }
}
