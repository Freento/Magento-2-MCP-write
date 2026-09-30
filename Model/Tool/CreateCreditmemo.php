<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Tool;

use Freento\McpWrite\Model\Helper\OrderItemResolver;
use Freento\McpWrite\Model\Helper\OrderLocator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsExtensionFactory;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Model\Order\CreditmemoDocumentFactory;

/**
 * Create an offline credit memo (refund) for an order via RefundOrderInterface
 */
class CreateCreditmemo extends AbstractWriteTool
{
    /**
     * @param Context $context
     * @param OrderLocator $orderLocator
     * @param OrderItemResolver $itemResolver
     * @param RefundOrderInterface $refundOrder
     * @param CreditmemoItemCreationInterfaceFactory $itemFactory
     * @param CreditmemoCreationArgumentsInterfaceFactory $argumentsFactory
     * @param CreditmemoCreationArgumentsExtensionFactory $extensionFactory
     * @param CreditmemoRepositoryInterface $creditmemoRepository
     * @param CreditmemoDocumentFactory $creditmemoDocumentFactory
     */
    public function __construct(
        Context $context,
        private readonly OrderLocator $orderLocator,
        private readonly OrderItemResolver $itemResolver,
        private readonly RefundOrderInterface $refundOrder,
        private readonly CreditmemoItemCreationInterfaceFactory $itemFactory,
        private readonly CreditmemoCreationArgumentsInterfaceFactory $argumentsFactory,
        private readonly CreditmemoCreationArgumentsExtensionFactory $extensionFactory,
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly CreditmemoDocumentFactory $creditmemoDocumentFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_creditmemo';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a credit memo (offline refund) for an order, fully or partially.

Offline means the order is marked refunded but no money is sent back through the payment gateway.
Omit items to refund all remaining quantities. Shipping is refunded in full by default only when
items are omitted. Always confirm the amount with the user before calling this tool.

Example prompts:
- "Refund order 000000123 completely and return items to stock"
- "Refund 1 x 24-MB01 from order 000000124, no shipping"';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->orderLocator->schemaProperties() + $this->itemResolver->schemaProperty('refund') + [
                'shipping_amount' => ['type' => 'number', 'description' => 'Shipping to refund. Default: full remaining shipping when items are omitted, otherwise 0'],
                'adjustment_positive' => ['type' => 'number', 'description' => 'Extra amount to refund'],
                'adjustment_negative' => ['type' => 'number', 'description' => 'Fee to subtract from the refund'],
                'return_to_stock' => ['type' => 'boolean', 'description' => 'Return refunded items to stock. Default: false'],
                'notify_customer' => ['type' => 'boolean', 'description' => 'Send credit memo email. Default: false'],
            ] + $this->dryRunProperty(),
            'examples' => [
                ['order_increment_id' => '000000123', 'return_to_stock' => true],
                ['order_increment_id' => '000000124', 'items' => [['sku' => '24-MB01', 'qty' => 1]]],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function getAreaCode(): ?string
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(array $arguments, bool $dryRun): string
    {
        $order = $this->orderLocator->load($arguments);
        if (!$order->canCreditmemo()) {
            throw new LocalizedException(__('Order #%1 cannot be refunded (status "%2").', $order->getIncrementId(), $order->getStatus()));
        }

        $requested = $arguments['items'] ?? null;
        $shipping = isset($arguments['shipping_amount'])
            ? (float)$arguments['shipping_amount']
            : ($requested === null ? (float)$order->getShippingAmount() - (float)$order->getShippingRefunded() : 0.0);
        $notify = $this->toBool($arguments['notify_customer'] ?? false);
        $returnToStock = $this->toBool($arguments['return_to_stock'] ?? false);

        $items = [];
        $itemIds = [];
        $lines = [];
        foreach ($this->itemResolver->resolve($order, $requested, OrderItemResolver::MODE_REFUND) as $resolved) {
            $orderItem = $resolved->getItem();
            $item = $this->itemFactory->create();
            $item->setOrderItemId((int)$orderItem->getItemId());
            $item->setQty($resolved->getQty());
            $items[] = $item;
            // Stock lives on the child line of a configurable/bundle/grouped item: the MSI return
            // plugin matches return_to_stock_items by order_item_id and skips composite types.
            $itemIds[] = (int)$orderItem->getItemId();
            foreach ($orderItem->getChildrenItems() as $child) {
                $itemIds[] = (int)$child->getItemId();
            }
            $lines[] = sprintf('  %s x %s (%s)', $resolved->getQty(), $orderItem->getSku(), $orderItem->getName());
        }
        $currency = $order->getOrderCurrencyCode();
        $lines[] = sprintf('  shipping refund: %s %s', $shipping, $currency);
        $lines[] = sprintf(
            '  adjustments: +%s / -%s %s',
            (float)($arguments['adjustment_positive'] ?? 0),
            (float)($arguments['adjustment_negative'] ?? 0),
            $currency
        );
        $lines[] = '  return to stock: ' . ($returnToStock ? 'yes' : 'no');
        $lines[] = '  notify customer: ' . ($notify ? 'yes (' . $order->getCustomerEmail() . ')' : 'no');

        $creationArguments = $this->argumentsFactory->create();
        $creationArguments->setShippingAmount($shipping);
        $creationArguments->setAdjustmentPositive((float)($arguments['adjustment_positive'] ?? 0));
        $creationArguments->setAdjustmentNegative((float)($arguments['adjustment_negative'] ?? 0));
        if ($returnToStock) {
            $extension = $creationArguments->getExtensionAttributes() ?: $this->extensionFactory->create();
            $extension->setReturnToStockItems($itemIds);
            $creationArguments->setExtensionAttributes($extension);
        }

        // Build the memo in memory the way RefundOrder does, so the total includes tax and discounts.
        // The API only rejects refunds that are too large; a negative total from adjustment_negative
        // would be saved as is, and only the admin form guards against it.
        $preview = $this->creditmemoDocumentFactory->createFromOrder($order, $items, null, false, $creationArguments);
        $total = (float)$preview->getGrandTotal();
        if ($total <= 0) {
            throw new LocalizedException(__(
                'Refund total would be %1 %2. Lower adjustment_negative or shipping_amount.',
                $total,
                $order->getOrderCurrencyCode()
            ));
        }
        $lines[] = sprintf('  refund total: %s %s', $total, $order->getOrderCurrencyCode());

        if ($dryRun) {
            return sprintf("Would create credit memo for order #%s:\n%s", $order->getIncrementId(), implode("\n", $lines));
        }

        $creditmemoId = $this->refundOrder->execute((int)$order->getId(), $items, $notify, false, null, $creationArguments);
        $creditmemo = $this->creditmemoRepository->get($creditmemoId);

        return sprintf(
            "Created credit memo #%s for order #%s, refunded %s %s:\n%s",
            $creditmemo->getIncrementId(),
            $order->getIncrementId(),
            $creditmemo->getGrandTotal(),
            $creditmemo->getOrderCurrencyCode(),
            implode("\n", $lines)
        );
    }
}
