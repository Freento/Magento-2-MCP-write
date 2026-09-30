<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Tool;

use Freento\McpWrite\Model\Helper\CustomerGroupResolver;
use Freento\McpWrite\Model\Helper\DateValue;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Group;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Update base, special and tier prices of one product through ProductRepositoryInterface::save
 */
class UpdateProductPrices extends AbstractWriteTool
{
    /**
     * @param Context $context
     * @param ProductRepositoryInterface $productRepository
     * @param CustomerGroupResolver $groupResolver
     * @param StoreManagerInterface $storeManager
     * @param DateValue $dateValue
     */
    public function __construct(
        Context $context,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomerGroupResolver $groupResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly DateValue $dateValue
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'update_product_prices';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Update base price, special (sale) price and tier prices of a product by SKU.

Give any combination of price, special_price, special_from_date, special_to_date, remove_special_price and tier_prices.
Dates you do not pass stay as they are; pass "" to clear one.
tier_prices REPLACES all existing tier prices of the product; pass an empty list to remove them.
customer_group accepts "ALL" (default), a group code ("Wholesale") or a group ID.
store_id selects the scope (0 = default); with website price scope Magento maps the store to its website.

Example prompts:
- "Set price of 24-MB01 to 19.99"
- "Put 24-MB01 on sale for 14.99 from 2026-10-01 to 2026-10-15"
- "Tier price for 24-MB01: 10+ pcs at 8.50 for Wholesale"';
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
                'price' => ['type' => 'number', 'description' => 'New base price'],
                'special_price' => ['type' => 'number', 'description' => 'New special (sale) price'],
                'special_from_date' => [
                    'type' => 'string',
                    'description' => 'Sale start, YYYY-MM-DD. Omit to keep the current date, pass "" to clear it.',
                ],
                'special_to_date' => [
                    'type' => 'string',
                    'description' => 'Sale end, YYYY-MM-DD. Omit to keep the current date, pass "" to clear it.',
                ],
                'remove_special_price' => ['type' => 'boolean', 'description' => 'Remove the special price and its dates'],
                'tier_prices' => [
                    'type' => 'array',
                    'description' => 'Full new list of tier prices (replaces existing ones)',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'qty' => ['type' => 'number', 'description' => 'Minimum quantity'],
                            'price' => ['type' => 'number', 'description' => 'Fixed price'],
                            'percentage' => ['type' => 'number', 'description' => 'Discount percent instead of a fixed price'],
                            'customer_group' => ['type' => 'string', 'description' => '"ALL", group code or ID. Default: ALL'],
                            'website_id' => ['type' => 'integer', 'description' => 'Default: 0 (all websites)'],
                        ],
                        'required' => ['qty'],
                    ],
                ],
                'store_id' => ['type' => 'integer', 'description' => 'Scope. Default: 0'],
            ] + $this->dryRunProperty(),
            'required' => ['sku'],
            'examples' => [
                ['sku' => '24-MB01', 'price' => 19.99],
                ['sku' => '24-MB01', 'special_price' => 14.99, 'special_from_date' => '2026-10-01', 'special_to_date' => '2026-10-15'],
                ['sku' => '24-MB01', 'tier_prices' => [['qty' => 10, 'price' => 8.5, 'customer_group' => 'Wholesale']]],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function doExecute(array $arguments, bool $dryRun): string
    {
        $sku = $this->requireString($arguments, 'sku');
        // Fresh instance every time: a dry run mutates the object, and the repository would hand the
        // same mutated object to the next call in this process, which would then save the trial values.
        /** @var Product $product */
        $product = $this->productRepository->get($sku, true, (int)($arguments['store_id'] ?? 0), true);

        $lines = [
            ...$this->applyBasePrice($product, $arguments),
            ...$this->applySpecialPrice($product, $arguments),
            ...$this->applyTierPrices($product, $arguments),
        ];
        if (!$lines) {
            throw new LocalizedException(
                __('Nothing to change: give price, special_price, remove_special_price or tier_prices.')
            );
        }

        if (!$dryRun) {
            $this->productRepository->save($product);
        }

        return sprintf(
            "%s prices of %s (id %d):\n%s",
            $dryRun ? 'Would update' : 'Updated',
            $sku,
            $product->getId(),
            implode("\n", $lines)
        );
    }

    /**
     * Base price, when given
     *
     * @param Product $product
     * @param array $arguments
     * @return string[] Report lines
     * @throws LocalizedException
     */
    private function applyBasePrice(Product $product, array $arguments): array
    {
        if (!isset($arguments['price'])) {
            return [];
        }

        $old = $product->getPrice();
        $price = $this->amount($arguments['price'], 'price');
        $product->setPrice($price);

        return [sprintf('  price: %s -> %s', $old, $price)];
    }

    /**
     * Special price and its dates, or their removal
     *
     * @param Product $product
     * @param array $arguments
     * @return string[] Report lines
     * @throws LocalizedException
     */
    private function applySpecialPrice(Product $product, array $arguments): array
    {
        $remove = $this->toBool($arguments['remove_special_price'] ?? false);
        if ($remove && isset($arguments['special_price'])) {
            throw new LocalizedException(__('Give either special_price or remove_special_price, not both.'));
        }
        if ($remove) {
            return $this->removeSpecialPrice($product);
        }

        $lines = [];
        if (isset($arguments['special_price'])) {
            // Documented as float, but null when the product has no special price.
            /** @var float|null $previousSpecialPrice */
            $previousSpecialPrice = $product->getSpecialPrice();
            $price = $this->amount($arguments['special_price'], 'special_price');
            $product->setSpecialPrice($price);
            $lines[] = sprintf('  special_price: %s -> %s', $previousSpecialPrice ?? '(empty)', $price);
        }

        $lines = [...$lines, ...$this->applySpecialDates($product, $arguments)];
        $this->assertSaleWindow($product);

        return $lines;
    }

    /**
     * Drop the special price together with its dates
     *
     * @param Product $product
     * @return string[] Report lines
     */
    private function removeSpecialPrice(Product $product): array
    {
        // Documented as float, but null when the product has no special price.
        /** @var float|null $previousSpecialPrice */
        $previousSpecialPrice = $product->getSpecialPrice();
        $product->setSpecialPrice(null);
        $product->setSpecialFromDate(null);
        $product->setSpecialToDate(null);

        return [sprintf('  special_price: %s -> (removed)', $previousSpecialPrice ?? '(empty)')];
    }

    /**
     * Sale dates. A date that is not passed stays as it is; "" clears it.
     *
     * Setting both to null when the arguments were merely absent used to wipe the sale window
     * on every price change.
     *
     * @param Product $product
     * @param array $arguments
     * @return string[] Report lines
     * @throws LocalizedException
     */
    private function applySpecialDates(Product $product, array $arguments): array
    {
        $lines = [];
        if (array_key_exists('special_from_date', $arguments)) {
            $new = $this->dateValue->normalize(
                $this->stringArgument($arguments, 'special_from_date'),
                __('Argument "%1"', 'special_from_date')
            );
            $lines[] = $this->dateLine('special_from_date', $product->getSpecialFromDate(), $new);
            $product->setSpecialFromDate($new);
        }

        if (array_key_exists('special_to_date', $arguments)) {
            $new = $this->dateValue->normalize(
                $this->stringArgument($arguments, 'special_to_date'),
                __('Argument "%1"', 'special_to_date')
            );
            $lines[] = $this->dateLine('special_to_date', $product->getSpecialToDate(), $new);
            $product->setSpecialToDate($new);
        }

        return $lines;
    }

    /**
     * "  special_to_date: 2026-12-31 -> (cleared)"
     *
     * @param string $argument
     * @param string|null $old Stored value, possibly with a time part
     * @param string|null $new
     * @return string
     */
    private function dateLine(string $argument, ?string $old, ?string $new): string
    {
        return sprintf('  %s: %s -> %s', $argument, $old ? substr($old, 0, 10) : '(empty)', $new ?? '(cleared)');
    }

    /**
     * A sale that ends before it starts is never active
     *
     * @param Product $product
     * @throws LocalizedException
     */
    private function assertSaleWindow(Product $product): void
    {
        $from = substr((string)$product->getSpecialFromDate(), 0, 10);
        $to = substr((string)$product->getSpecialToDate(), 0, 10);
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new LocalizedException(
                __('special_from_date %1 is after special_to_date %2: the sale would never start.', $from, $to)
            );
        }
    }

    /**
     * Tier prices: the given list replaces the current one
     *
     * @param Product $product
     * @param array $arguments
     * @return string[] Report lines
     * @throws LocalizedException
     */
    private function applyTierPrices(Product $product, array $arguments): array
    {
        if (!isset($arguments['tier_prices'])) {
            return [];
        }

        if (!is_array($arguments['tier_prices'])) {
            throw new LocalizedException(__('tier_prices must be a list.'));
        }

        $rows = [];
        $lines = [];
        foreach ($arguments['tier_prices'] as $tier) {
            $row = $this->buildTierRow((array)$tier);
            $rows[] = $row;
            $lines[] = sprintf(
                '  tier: qty >= %s -> %s (%s, website %d)',
                $row['price_qty'],
                $row['percentage_value'] !== null ? $row['percentage_value'] . '%' : $row['price'],
                $this->groupLabel((int)$row['cust_group']),
                $row['website_id']
            );
        }

        $lines[] = sprintf(
            '  tier prices: %d existing replaced by %d',
            count($product->getTierPrices() ?? []),
            count($rows)
        );
        $product->setData('tier_price', $rows);

        return $lines;
    }

    /**
     * Customer group code for the report, "ALL" for all groups
     *
     * @param int $groupId
     * @return string
     * @throws LocalizedException
     */
    private function groupLabel(int $groupId): string
    {
        return $groupId === Group::CUST_GROUP_ALL ? 'ALL' : $this->groupResolver->resolve($groupId)->getCode();
    }

    /**
     * One tier price row in the form the product model stores
     *
     * @param array $tier
     * @return array
     * @throws LocalizedException
     */
    private function buildTierRow(array $tier): array
    {
        // A row carrying both a fixed price and a percentage is one the admin form cannot produce.
        $isPercentage = isset($tier['percentage']);
        if ($isPercentage === isset($tier['price'])) {
            throw new LocalizedException(__('Each tier price needs exactly one of "price" or "percentage".'));
        }

        $group = (string)($tier['customer_group'] ?? 'ALL');
        $websiteId = (int)($tier['website_id'] ?? 0);
        // 0 means all websites; anything else must exist, or Magento's price backend emits a raw PHP warning.
        if ($websiteId !== 0 && !isset($this->storeManager->getWebsites()[$websiteId])) {
            throw new LocalizedException(__(
                'No website with id %1. Websites: %2',
                $websiteId,
                implode(', ', array_keys($this->storeManager->getWebsites()))
            ));
        }

        return [
            'website_id' => $websiteId,
            'cust_group' => strcasecmp($group, 'ALL') === 0
                ? Group::CUST_GROUP_ALL
                : (int)$this->groupResolver->resolve($group)->getId(),
            'price_qty' => $this->number($tier['qty'] ?? null, 'tier_prices[].qty'),
            'price' => $isPercentage ? null : $this->amount($tier['price'], 'tier_prices[].price'),
            'percentage_value' => $isPercentage ? $this->number($tier['percentage'], 'tier_prices[].percentage') : null,
        ];
    }

    /**
     * Money argument: numeric and not negative
     *
     * @param mixed $value
     * @param string $argument
     * @return float
     * @throws LocalizedException
     */
    private function amount(mixed $value, string $argument): float
    {
        $amount = $this->number($value, $argument);
        if ($amount < 0) {
            throw new LocalizedException(__('Argument "%1" must be 0 or greater.', $argument));
        }

        return $amount;
    }

    /**
     * Numeric argument as float; "abc" must not silently become a price of 0
     *
     * @param mixed $value
     * @param string $argument
     * @return float
     * @throws LocalizedException
     */
    private function number(mixed $value, string $argument): float
    {
        if (!is_numeric($value)) {
            throw new LocalizedException(__('Argument "%1" must be a number.', $argument));
        }

        return (float)$value;
    }
}
