<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Helper;

use Magento\Sales\Model\Order\Item;

/**
 * One order line picked for shipping or refunding, with the quantity to process.
 *
 * A value object: OrderItemResolver builds it, the tools only read it.
 */
class ResolvedOrderItem
{
    /**
     * @param Item $item
     * @param float $qty
     */
    public function __construct(
        private readonly Item $item,
        private readonly float $qty
    ) {
    }

    /**
     * Order line the action applies to
     *
     * @return Item
     */
    public function getItem(): Item
    {
        return $this->item;
    }

    /**
     * Quantity to ship or refund from that line
     *
     * @return float
     */
    public function getQty(): float
    {
        return $this->qty;
    }
}
