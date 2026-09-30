<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;

/**
 * Maps the "items" argument (sku + qty) of shipment and credit memo tools to order lines.
 *
 * Only top-level lines are used: for a configurable product the parent line carries
 * the quantity and Magento ships or refunds the child together with it.
 */
class OrderItemResolver
{
    public const MODE_SHIP = 'ship';
    public const MODE_REFUND = 'refund';

    /**
     * @param ResolvedOrderItemFactory $resolvedOrderItemFactory
     */
    public function __construct(
        private readonly ResolvedOrderItemFactory $resolvedOrderItemFactory
    ) {
    }

    /**
     * JSON schema fragment for the items argument
     *
     * @param string $mode
     * @return array
     */
    public function schemaProperty(string $mode): array
    {
        return [
            'items' => [
                'type' => 'array',
                'description' => "Order lines to $mode. Omit to $mode every remaining quantity.",
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'sku' => ['type' => 'string', 'description' => 'SKU of the order line'],
                        'qty' => ['type' => 'number', 'description' => 'Quantity. Omit for the full remaining quantity.'],
                    ],
                    'required' => ['sku'],
                ],
            ],
        ];
    }

    /**
     * Order lines to process, each with the quantity for this action
     *
     * @param Order $order
     * @param array|null $requested Null means every remaining quantity; an empty list is refused
     * @param string $mode
     * @return ResolvedOrderItem[]
     * @throws LocalizedException
     */
    public function resolve(Order $order, ?array $requested, string $mode): array
    {
        // [] is falsy, so without this check an emptied-out selection would process the whole order.
        if ($requested === []) {
            throw new LocalizedException(__('"items" is empty. Omit it to %1 every remaining quantity.', $mode));
        }

        // Keyed by item id: the same SKU can sit on several lines (custom options, re-added items).
        $lines = [];
        /** @var Item $item */
        foreach ($order->getAllItems() as $item) {
            if (!$item->getParentItemId() && !($mode === self::MODE_SHIP && $item->getIsVirtual())) {
                $lines[(int)$item->getItemId()] = $item;
            }
        }

        if ($requested === null) {
            $result = [];
            foreach ($lines as $item) {
                $remaining = $this->remaining($item, $mode);
                if ($remaining > 0) {
                    $result[] = $this->resolvedOrderItemFactory->create(['item' => $item, 'qty' => $remaining]);
                }
            }
            if (!$result) {
                throw new LocalizedException(__('Nothing left to %1 in order #%2.', $mode, $order->getIncrementId()));
            }

            return $result;
        }

        $result = [];
        foreach ($this->mergeRequest($requested) as $sku => $want) {
            $matching = array_filter($lines, static fn (Item $line) => strtolower((string)$line->getSku()) === $sku);
            if (!$matching) {
                throw new LocalizedException(__(
                    'Order #%1 has no line "%2". Lines: %3',
                    $order->getIncrementId(),
                    $want['label'],
                    implode(', ', array_unique(array_map(static fn (Item $line) => $line->getSku(), $lines)))
                ));
            }

            $available = array_sum(array_map(fn (Item $line) => $this->remaining($line, $mode), $matching));
            $qty = $want['qty'] ?? $available;
            if ($qty <= 0 || $qty > $available) {
                throw new LocalizedException(__(
                    'Cannot %1 %2 x "%3": %4 left of %5 ordered.',
                    $mode,
                    $qty,
                    reset($matching)->getSku(),
                    $available,
                    array_sum(array_map(static fn (Item $line) => (float)$line->getQtyOrdered(), $matching))
                ));
            }

            // Spread the quantity over the lines in order until it is used up.
            foreach ($matching as $item) {
                $take = min($qty, $this->remaining($item, $mode));
                if ($take <= 0) {
                    continue;
                }
                $result[] = $this->resolvedOrderItemFactory->create(['item' => $item, 'qty' => $take]);
                $qty -= $take;
                if ($qty <= 0) {
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Requested quantity per SKU, duplicates summed. A null qty means "everything left" for that SKU.
     *
     * Magento keys shipment and credit memo items by order item id, so two rows for one
     * SKU would otherwise overwrite each other and only the last one would be processed.
     *
     * @param array $requested
     * @return array<string, array{label: string, qty: float|null}> Keyed by lower-cased SKU
     */
    private function mergeRequest(array $requested): array
    {
        $wanted = [];
        foreach ($requested as $row) {
            $label = trim((string)($row['sku'] ?? ''));
            $sku = strtolower($label);
            $explicit = isset($row['qty']) && $row['qty'] !== '';
            if (!isset($wanted[$sku])) {
                $wanted[$sku] = ['label' => $label, 'qty' => $explicit ? (float)$row['qty'] : null];
            } elseif ($explicit && $wanted[$sku]['qty'] !== null) {
                $wanted[$sku]['qty'] += (float)$row['qty'];
            } else {
                $wanted[$sku]['qty'] = null;
            }
        }

        return $wanted;
    }

    /**
     * Quantity still available for the action
     *
     * @param Item $item
     * @param string $mode
     * @return float
     */
    private function remaining(Item $item, string $mode): float
    {
        return $mode === self::MODE_SHIP ? (float)$item->getQtyToShip() : (float)$item->getQtyToRefund();
    }
}
