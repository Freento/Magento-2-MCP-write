<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Tool;

use Magento\Catalog\Controller\Adminhtml\Product\Initialization\StockDataFilter;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Set stock quantity and status of a product.
 *
 * Uses StockRegistryInterface (the default stock). With MSI installed Magento's own
 * plugin copies the change to the "default" source, so it works in both setups.
 */
class UpdateStock extends AbstractWriteTool
{
    /**
     * @param Context $context
     * @param StockRegistryInterface $stockRegistry
     */
    public function __construct(
        Context $context,
        private readonly StockRegistryInterface $stockRegistry
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_stock';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Set stock quantity and in-stock status of a product by SKU.

qty is an absolute value, not a delta. If is_in_stock is omitted it becomes true when qty > 0.
With Multi Source Inventory the default source is updated.

Example prompts:
- "Set stock of 24-MB01 to 25"
- "Mark 24-MB04 out of stock"';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sku' => ['type' => 'string'],
                'qty' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => StockDataFilter::MAX_QTY_VALUE,
                    'description' => 'New absolute quantity, 0 to ' . StockDataFilter::MAX_QTY_VALUE
                        . '. Larger values are capped, like in the admin product form.',
                ],
                'is_in_stock' => ['type' => 'boolean', 'description' => 'Default: qty > 0'],
            ] + $this->dryRunProperty(),
            'required' => ['sku', 'qty'],
            'examples' => [
                ['sku' => '24-MB01', 'qty' => 25],
                ['sku' => '24-MB04', 'qty' => 0, 'is_in_stock' => false],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(array $arguments, bool $dryRun): string
    {
        $sku = $this->requireString($arguments, 'sku');
        if (!is_numeric($arguments['qty'] ?? null)) {
            throw new LocalizedException(__('Argument "qty" must be a number.'));
        }
        $qty = (float)$arguments['qty'];
        if ($qty < 0) {
            throw new LocalizedException(__('Argument "qty" must be 0 or greater.'));
        }
        $notes = [];
        // Same cap the admin product form applies; the API path has none and the column is decimal(12,4).
        if ($qty > StockDataFilter::MAX_QTY_VALUE) {
            $notes[] = sprintf('qty capped at the maximum %s', StockDataFilter::MAX_QTY_VALUE);
            $qty = (float)StockDataFilter::MAX_QTY_VALUE;
        }
        $isInStock = isset($arguments['is_in_stock']) ? $this->toBool($arguments['is_in_stock']) : $qty > 0;

        $stockItem = $this->stockRegistry->getStockItemBySku($sku);
        $before = $this->describe((float)$stockItem->getQty(), (bool)$stockItem->getIsInStock());
        $wasOutAutomatically = !$stockItem->getIsInStock() && $stockItem->getStockStatusChangedAuto();

        if ($dryRun) {
            $after = $this->describe($qty, $isInStock) . ' (requested)';
        } else {
            $stockItem->setQty($qty);
            $stockItem->setIsInStock($isInStock);
            $this->stockRegistry->updateStockItemBySku($sku, $stockItem);

            // Report what Magento kept, not what was asked: it forces "out of stock" at or below the threshold.
            $saved = $this->stockRegistry->getStockItemBySku($sku);
            $after = $this->describe((float)$saved->getQty(), (bool)$saved->getIsInStock());
            if ((bool)$saved->getIsInStock() !== $isInStock) {
                $notes[] = match (true) {
                    !$saved->getIsInStock() =>
                        'Magento kept the item out of stock: qty is at or below the out-of-stock threshold',
                    $wasOutAutomatically =>
                        'Magento put the item back in stock: its out-of-stock status had been set automatically. '
                        . 'Call again with is_in_stock=false to set it out of stock manually',
                    default => 'Magento set the item in stock',
                };
            }
        }

        $text = sprintf('%s stock of %s: %s -> %s', $dryRun ? 'Would update' : 'Updated', $sku, $before, $after);
        foreach ($notes as $note) {
            $text .= "\n  note: " . $note;
        }

        return $text;
    }

    /**
     * "12 (in stock)"
     *
     * @param float $qty
     * @param bool $isInStock
     * @return string
     */
    private function describe(float $qty, bool $isInStock): string
    {
        return sprintf('%s (%s)', $qty, $isInStock ? 'in stock' : 'out of stock');
    }
}
