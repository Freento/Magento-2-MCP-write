<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Helper;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Finds an order by increment ID (the number customers see) or by entity ID
 */
class OrderLocator
{
    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * JSON schema fragment for the order identification arguments
     *
     * @return array
     */
    public function schemaProperties(): array
    {
        return [
            'order_increment_id' => [
                'type' => 'string',
                'description' => 'Order number as shown to the customer and in admin, e.g. "000000123". Use this OR order_id.',
            ],
            'order_id' => ['type' => 'integer', 'description' => 'Internal order entity ID. Use this OR order_increment_id.'],
        ];
    }

    /**
     * Load the order
     *
     * @param array $arguments
     * @return Order
     * @throws LocalizedException
     */
    public function load(array $arguments): Order
    {
        $incrementId = trim((string)($arguments['order_increment_id'] ?? ''));
        $orderId = (int)($arguments['order_id'] ?? 0);

        if ($incrementId !== '' && $orderId !== 0) {
            throw new LocalizedException(__('Give either order_increment_id or order_id, not both.'));
        }

        if ($incrementId === '' && $orderId === 0) {
            throw new LocalizedException(__('Specify order_increment_id or order_id.'));
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter($incrementId !== '' ? 'increment_id' : 'entity_id', $incrementId !== '' ? $incrementId : $orderId)
            ->setPageSize(1)
            ->create();
        $orders = $this->orderRepository->getList($criteria)->getItems();
        $order = reset($orders);

        if (!$order instanceof Order) {
            throw new LocalizedException(__('Order %1 not found.', $incrementId !== '' ? '#' . $incrementId : 'with ID ' . $orderId));
        }

        return $order;
    }
}
