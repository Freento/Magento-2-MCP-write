<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Tool;

use Freento\McpWrite\Model\Helper\CategoryResolver;
use Magento\Catalog\Api\CategoryLinkManagementInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;

/**
 * Add a product to categories, remove it from categories, or replace its category list.
 *
 * Uses CategoryLinkManagementInterface: it takes the full list of categories the product
 * must end up in and both adds the missing links and deletes the extra ones. Setting
 * category_ids on the product and saving it only adds links, so it is not used here.
 */
class AssignProductCategories extends AbstractWriteTool
{
    /**
     * @param Context $context
     * @param ProductRepositoryInterface $productRepository
     * @param CategoryResolver $categoryResolver
     * @param CategoryLinkManagementInterface $categoryLinkManagement
     */
    public function __construct(
        Context $context,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CategoryResolver $categoryResolver,
        private readonly CategoryLinkManagementInterface $categoryLinkManagement
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'assign_product_categories';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a product to categories, remove it from categories, or set its exact category list.

action: "add" (default) keeps existing categories, "remove" unassigns the given ones,
"replace" makes the given categories the only ones. Use get_categories to find category IDs.
Re-adding a category the product was removed from resets its position in that category to 0.

Example prompts:
- "Add 24-MB01 to category 12"
- "Remove 24-MB01 from category 7"
- "Move 24-MB01 to categories 5 and 6 only"';
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
                'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'action' => ['type' => 'string', 'enum' => ['add', 'remove', 'replace'], 'description' => 'Default: add'],
            ] + $this->dryRunProperty(),
            'required' => ['sku', 'category_ids'],
            'examples' => [
                ['sku' => '24-MB01', 'category_ids' => [12]],
                ['sku' => '24-MB01', 'category_ids' => [7], 'action' => 'remove'],
                ['sku' => '24-MB01', 'category_ids' => [5, 6], 'action' => 'replace'],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(array $arguments, bool $dryRun): string
    {
        $sku = $this->requireString($arguments, 'sku');
        $action = $arguments['action'] ?? 'add';
        if (!in_array($action, ['add', 'remove', 'replace'], true)) {
            throw new LocalizedException(__('action must be add, remove or replace.'));
        }

        // Validated and loaded before the product, so a bad id costs nothing else.
        $categories = $this->categoryResolver->resolve($arguments['category_ids'] ?? null);
        $ids = array_keys($categories);
        if ($action === 'replace' && !$ids) {
            throw new LocalizedException(__(
                '"replace" with an empty category_ids would unassign every category. Use "remove" with explicit ids.'
            ));
        }

        /** @var Product $product */
        $product = $this->productRepository->get($sku, false, 0, true);
        $current = array_map('intval', $product->getCategoryIds());
        $new = array_values(match ($action) {
            'add' => array_unique(array_merge($current, $ids)),
            'remove' => array_diff($current, $ids),
            'replace' => $ids,
        });

        // The categories the product is already in, skipped when the request covers them all.
        $categories += $this->categoryResolver->load(array_diff($current, $ids));

        $sortedCurrent = $current;
        $sortedNew = $new;
        sort($sortedCurrent);
        sort($sortedNew);
        if ($sortedCurrent === $sortedNew) {
            return sprintf(
                "No changes for categories of %s: [%s]\n  no changes, nothing saved",
                $sku,
                implode(', ', $this->label($current, $categories))
            );
        }

        if (!$dryRun) {
            $this->categoryLinkManagement->assignProductToCategories($sku, $new);
        }

        return sprintf(
            '%s categories of %s: [%s] -> [%s]',
            $dryRun ? 'Would update' : 'Updated',
            $sku,
            implode(', ', $this->label($current, $categories)),
            implode(', ', $this->label($new, $categories))
        );
    }

    /**
     * "Name (id)" for each category
     *
     * @param int[] $ids
     * @param CategoryInterface[] $categories Keyed by category id
     * @return string[]
     */
    private function label(array $ids, array $categories): array
    {
        return array_map(
            fn (int $id) => (isset($categories[$id]) ? $categories[$id]->getName() : 'unknown') . ' (' . $id . ')',
            $ids
        );
    }
}
