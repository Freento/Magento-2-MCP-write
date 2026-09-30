<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Tool;

use Freento\McpWrite\Model\Helper\AttributeValueResolver;
use Magento\Catalog\Api\Data\EavAttributeInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\Store;

/**
 * Update attribute values of one product through ProductRepositoryInterface::save
 */
class UpdateProductAttributes extends AbstractWriteTool
{
    /** Schema of the arguments that address the product; "attributes" must not carry these codes */
    private const PRODUCT_PROPERTIES = [
        'sku' => ['type' => 'string', 'description' => 'Product SKU'],
        'store_id' => ['type' => 'integer', 'description' => 'Store view scope. Default: 0 (all store views)'],
    ];

    /**
     * @param Context $context
     * @param ProductRepositoryInterface $productRepository
     * @param AttributeValueResolver $attributeValue
     */
    public function __construct(
        Context $context,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AttributeValueResolver $attributeValue
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_product_attributes';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Update attribute values of a product by SKU.

Use this tool when you need to:
- Enable or disable a product (status)
- Change name, description, short_description, url_key, meta fields
- Set visibility, tax_class_id, color, size, brand or any custom attribute

Select/multiselect/boolean attributes accept option labels ("Enabled", "Red", "Yes") or option IDs.
Dates are YYYY-MM-DD. store_id selects the scope (0 = default values for all store views).
Use update_product_prices for prices, update_stock for inventory, assign_product_categories for categories.

Example prompts:
- "Disable product 24-MB01"
- "Set color Red and size XL for SKU WS03"
- "Rename product 24-MB01 to \"New name\" in store view 2"';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::PRODUCT_PROPERTIES + [
                'attributes' => [
                    'type' => 'object',
                    'description' => 'Map of attribute_code => value, e.g. {"status": "Disabled", "color": "Red"}',
                    'additionalProperties' => true,
                ],
            ] + $this->dryRunProperty(),
            'required' => ['sku', 'attributes'],
            'examples' => [
                ['sku' => '24-MB01', 'attributes' => ['status' => 'Disabled']],
                ['sku' => '24-MB01', 'attributes' => ['name' => 'New name'], 'store_id' => 1],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(array $arguments, bool $dryRun): string
    {
        $sku = $this->requireString($arguments, 'sku');
        $attributes = $arguments['attributes'] ?? null;
        if (!is_array($attributes) || !$attributes || array_is_list($attributes)) {
            throw new LocalizedException(__('Argument "attributes" must be a non-empty object of attribute_code => value.'));
        }
        $this->attributeValue->assertNoReservedCodes($attributes, array_keys(self::PRODUCT_PROPERTIES));

        if (isset($arguments['store_id']) && !is_numeric($arguments['store_id'])) {
            // (int)"default" would be 0: a global write while the caller asked for one store view.
            throw new LocalizedException(
                __('Argument "store_id" must be a store view id, got "%1".', (string)$arguments['store_id'])
            );
        }

        $storeId = (int)($arguments['store_id'] ?? 0);
        /** @var Product $product */
        $product = $this->productRepository->get($sku, true, $storeId);
        $this->attributeValue->assertInSet(
            array_map('trim', array_map('strval', array_keys($attributes))),
            (int)$product->getAttributeSetId(),
            $sku
        );

        $lines = [];
        $changed = false;
        foreach ($attributes as $code => $value) {
            $code = trim((string)$code);
            $old = $product->getData($code);
            $new = $this->attributeValue->resolve($code, $value);
            $lines[] = sprintf(
                '  %s: %s -> %s%s',
                $code,
                $this->attributeValue->describe($code, $old),
                $this->attributeValue->describe($code, $new),
                $this->scopeNote($code, $storeId)
            );
            $changed = $changed || (string)$old !== (string)$new;
            $product->setData($code, $new);
        }

        // A save with nothing new still bumps updated_at, rewrites gallery rows and invalidates indexes.
        if (!$changed) {
            $lines[] = '  no changes, nothing saved';
        } elseif (!$dryRun) {
            $this->productRepository->save($product);
        }

        return sprintf(
            "%s product %s (id %d), store scope %s:\n%s",
            !$changed ? 'No changes for' : ($dryRun ? 'Would update' : 'Updated'),
            $sku,
            $product->getId(),
            $storeId === 0 ? 'default' : $storeId,
            implode("\n", $lines)
        );
    }

    /**
     * Refuse a store-scoped write of a global attribute; flag one of a website attribute.
     *
     * Magento stores both outside the store view: the report would otherwise claim "store scope N"
     * for a change that reached every store view.
     *
     * @param string $code
     * @param int $storeId
     * @return string Suffix for the report line
     * @throws LocalizedException
     */
    private function scopeNote(string $code, int $storeId): string
    {
        if ($storeId === Store::DEFAULT_STORE_ID) {
            return '';
        }

        return match ($this->attributeValue->getScope($code)) {
            EavAttributeInterface::SCOPE_GLOBAL_TEXT => throw new LocalizedException(__(
                'Attribute "%1" is global and cannot be set for one store view. Use store_id 0.',
                $code
            )),
            EavAttributeInterface::SCOPE_WEBSITE_TEXT => ' (website scope: applies to every store view of the website)',
            default => '',
        };
    }
}
