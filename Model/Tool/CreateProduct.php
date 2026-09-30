<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Tool;

use Freento\McpWrite\Model\Helper\AttributeValueResolver;
use Freento\McpWrite\Model\Helper\CategoryResolver;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Controller\Adminhtml\Product\Initialization\StockDataFilter;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Create a simple or virtual product through ProductRepositoryInterface::save
 */
class CreateProduct extends AbstractWriteTool
{
    /** Schema of the product fields that have their own argument; "attributes" must not carry these codes */
    private const PRODUCT_PROPERTIES = [
        'sku' => ['type' => 'string'],
        'name' => ['type' => 'string'],
        'price' => ['type' => 'number'],
        'type_id' => ['type' => 'string', 'enum' => ['simple', 'virtual'], 'description' => 'Default: simple'],
        'attribute_set_id' => ['type' => 'integer', 'description' => 'Default: the default attribute set'],
        'status' => ['type' => 'string', 'description' => '"Enabled" or "Disabled". Default: Enabled'],
        'visibility' => [
            'type' => 'string',
            'description' => '"Not Visible Individually", "Catalog", "Search" or "Catalog, Search". Default: "Catalog, Search"',
        ],
        'weight' => ['type' => 'number'],
        'website_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Default: all websites'],
        'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        'qty' => [
            'type' => 'number',
            'minimum' => 0,
            'maximum' => StockDataFilter::MAX_QTY_VALUE,
            'description' => 'Initial stock quantity, 0 to ' . StockDataFilter::MAX_QTY_VALUE
                . '. Larger values are capped, like in the admin product form.',
        ],
    ];

    /**
     * @param Context $context
     * @param ProductInterfaceFactory $productFactory
     * @param ProductRepositoryInterface $productRepository
     * @param AttributeValueResolver $attributeValue
     * @param StoreManagerInterface $storeManager
     * @param CategoryResolver $categoryResolver
     * @param StockRegistryInterface $stockRegistry
     */
    public function __construct(
        Context $context,
        private readonly ProductInterfaceFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly AttributeValueResolver $attributeValue,
        private readonly StoreManagerInterface $storeManager,
        private readonly CategoryResolver $categoryResolver,
        private readonly StockRegistryInterface $stockRegistry
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_product';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a new simple or virtual product.

Defaults: type simple, default attribute set, status Enabled, visibility "Catalog, Search", all websites.
Any other attribute (description, url_key, color, ...) goes into "attributes".
Configurable, bundle and grouped products are not supported.

Example prompts:
- "Create product NEW-001 \"Blue Mug\" priced 12.50 with 100 in stock"
- "Add virtual product GIFT-50 \"Gift card 50\" for 50 in category 5"';
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
                    'description' => 'Other attributes as attribute_code => value',
                    'additionalProperties' => true,
                ],
            ] + $this->dryRunProperty(),
            'required' => ['sku', 'name', 'price'],
            'examples' => [
                ['sku' => 'NEW-001', 'name' => 'Blue Mug', 'price' => 12.5, 'qty' => 100],
                ['sku' => 'NEW-002', 'name' => 'Red Mug', 'price' => 14, 'category_ids' => [5], 'attributes' => ['color' => 'Red']],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(array $arguments, bool $dryRun): string
    {
        $sku = $this->requireString($arguments, 'sku');
        $name = $this->requireString($arguments, 'name');
        if (!is_numeric($arguments['price'] ?? null)) {
            throw new LocalizedException(__('Argument "price" must be a number.'));
        }

        try {
            $this->productRepository->get($sku);
            throw new LocalizedException(__('Product with SKU "%1" already exists. Use update_product_attributes instead.', $sku));
        } catch (NoSuchEntityException $e) {
            // expected: the SKU is free
        }

        $typeId = $arguments['type_id'] ?? 'simple';
        if (!in_array($typeId, ['simple', 'virtual'], true)) {
            throw new LocalizedException(__('type_id must be "simple" or "virtual".'));
        }

        $this->attributeValue->assertNoReservedCodes(
            (array)($arguments['attributes'] ?? []),
            array_keys(self::PRODUCT_PROPERTIES)
        );

        if ((float)$arguments['price'] < 0) {
            throw new LocalizedException(__('Argument "price" must be 0 or greater.'));
        }

        $knownWebsites = array_keys($this->storeManager->getWebsites());
        $websiteIds = array_map('intval', (array)($arguments['website_ids'] ?? $knownWebsites));
        // The save only reports "unable to be saved" for an unknown website, so check it here.
        $unknownWebsites = array_diff($websiteIds, $knownWebsites);
        if ($unknownWebsites) {
            throw new LocalizedException(__(
                'No website with id: %1. Websites: %2',
                implode(', ', $unknownWebsites),
                implode(', ', $knownWebsites)
            ));
        }

        /** @var Product $product */
        $product = $this->productFactory->create();
        $product->setStoreId(0);
        $product->setSku($sku);
        $product->setName($name);
        $product->setPrice((float)$arguments['price']);
        $product->setTypeId($typeId);
        $product->setAttributeSetId((int)($arguments['attribute_set_id'] ?? $product->getDefaultAttributeSetId()));
        $this->attributeValue->assertInSet(
            array_map('trim', array_map('strval', array_keys((array)($arguments['attributes'] ?? [])))),
            (int)$product->getAttributeSetId(),
            $sku
        );
        $product->setStatus($this->attributeValue->resolve('status', $arguments['status'] ?? 'Enabled'));
        $product->setVisibility($this->attributeValue->resolve('visibility', $arguments['visibility'] ?? 'Catalog, Search'));
        $product->setWebsiteIds($websiteIds);

        if (isset($arguments['weight'])) {
            $product->setWeight((float)$arguments['weight']);
        }
        if (array_key_exists('category_ids', $arguments)) {
            // Unknown ids would be dropped by the save without a word; validate them like assign_product_categories.
            $product->setCategoryIds(array_keys($this->categoryResolver->resolve($arguments['category_ids'])));
        }
        foreach ((array)($arguments['attributes'] ?? []) as $code => $value) {
            $code = trim((string)$code);
            $product->setData($code, $this->attributeValue->resolve($code, $value));
        }
        $qty = null;
        $notes = [];
        if (isset($arguments['qty'])) {
            if (!is_numeric($arguments['qty']) || (float)$arguments['qty'] < 0) {
                throw new LocalizedException(__('Argument "qty" must be a number 0 or greater.'));
            }
            // Same cap the admin product form applies; the stock column is decimal(12,4).
            $qty = min((float)$arguments['qty'], (float)StockDataFilter::MAX_QTY_VALUE);
            if ((float)$arguments['qty'] > $qty) {
                $notes[] = sprintf('qty capped at the maximum %s', StockDataFilter::MAX_QTY_VALUE);
            }
            $product->setStockData(['qty' => $qty, 'is_in_stock' => $qty > 0 ? 1 : 0]);
        }
        if (!$dryRun) {
            /** @var Product $product */
            $product = $this->productRepository->save($product);
            if ($qty !== null) {
                // What the stock tables hold, not what was asked (the column caps large values).
                $qty = (float)$this->stockRegistry->getStockItemBySku($product->getSku())->getQty();
            }
        }

        // Every value below comes from the product object, so the report cannot drift from the entity.
        $text = sprintf(
            "%s product %s \"%s\"%s\n  type: %s, price: %s, status: %s, visibility: %s, websites: %s%s%s%s%s",
            $dryRun ? 'Would create' : 'Created',
            $product->getSku(),
            $product->getName(),
            $dryRun ? '' : ' (id ' . $product->getId() . ')',
            $product->getTypeId(),
            $product->getPrice(),
            $this->attributeValue->describe('status', $product->getStatus()),
            $this->attributeValue->describe('visibility', $product->getVisibility()),
            implode(', ', $product->getWebsiteIds()),
            isset($arguments['weight']) ? ', weight: ' . (float)$product->getWeight() : '',
            $product->getCategoryIds() ? ', categories: ' . implode(', ', $product->getCategoryIds()) : '',
            $qty !== null ? ', qty: ' . $qty : '',
            !empty($arguments['attributes']) ? ', attributes: ' . implode(', ', array_keys((array)$arguments['attributes'])) : ''
        );
        foreach ($notes as $note) {
            $text .= "\n  note: " . $note;
        }

        return $text;
    }
}
