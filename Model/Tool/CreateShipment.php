<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Tool;

use Freento\McpWrite\Model\Helper\OrderItemResolver;
use Freento\McpWrite\Model\Helper\OrderLocator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Shipping\Model\Config as ShippingConfig;

/**
 * Create a shipment (full or partial) with tracking numbers via ShipOrderInterface
 */
class CreateShipment extends AbstractWriteTool
{
    /**
     * @param Context $context
     * @param OrderLocator $orderLocator
     * @param OrderItemResolver $itemResolver
     * @param ShipOrderInterface $shipOrder
     * @param ShipmentItemCreationInterfaceFactory $itemFactory
     * @param ShipmentTrackCreationInterfaceFactory $trackFactory
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param ShippingConfig $shippingConfig
     */
    public function __construct(
        Context $context,
        private readonly OrderLocator $orderLocator,
        private readonly OrderItemResolver $itemResolver,
        private readonly ShipOrderInterface $shipOrder,
        private readonly ShipmentItemCreationInterfaceFactory $itemFactory,
        private readonly ShipmentTrackCreationInterfaceFactory $trackFactory,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly ShippingConfig $shippingConfig
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'create_shipment';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Create a shipment for an order, fully or partially, with optional tracking numbers.

Omit items to ship everything still unshipped. carrier_code is a carrier configured in the store
(ups, usps, fedex, dhl, ...) or "custom" (default); the tracking link on the storefront depends on it.

Example prompts:
- "Ship order 000000123 with UPS tracking 1Z999AA10123456784"
- "Ship 2 of 24-MB01 from order 000000124 and notify the customer"';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->orderLocator->schemaProperties() + $this->itemResolver->schemaProperty('ship') + [
                'tracks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'track_number' => ['type' => 'string'],
                            'carrier_code' => [
                                'type' => 'string',
                                'description' => 'A carrier code configured in the store, or "custom". Default: custom',
                            ],
                            'title' => [
                                'type' => 'string',
                                'description' => 'Carrier name shown to the customer. '
                                    . 'Default: the carrier title from the store configuration, "Custom" for custom',
                            ],
                        ],
                        'required' => ['track_number'],
                    ],
                ],
                'notify_customer' => ['type' => 'boolean', 'description' => 'Send shipment email. Default: false'],
            ] + $this->dryRunProperty(),
            'examples' => [
                ['order_increment_id' => '000000123', 'tracks' => [['carrier_code' => 'ups', 'track_number' => '1Z999AA10123456784']]],
                ['order_increment_id' => '000000124', 'items' => [['sku' => '24-MB01', 'qty' => 2]], 'notify_customer' => true],
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
        if (!$order->canShip()) {
            // Canceled lines have no qty to ship either, so the order state is checked before the items.
            $isBlocked = $order->isCanceled()
                || $order->getState() === Order::STATE_HOLDED
                || $order->isPaymentReview();
            $unshipped = array_filter(
                $order->getAllItems(),
                static fn ($item) => !$item->getParentItemId()
                    && !$item->getIsVirtual()
                    && (float)$item->getQtyToShip() > 0
            );
            if ($isBlocked || $unshipped) {
                $message = __(
                    'Order #%1 cannot be shipped (status "%2").',
                    $order->getIncrementId(),
                    $order->getStatus()
                );
            } elseif ($order->getIsVirtual()) {
                $message = __(
                    'Nothing left to ship in order #%1: it contains only virtual items.',
                    $order->getIncrementId()
                );
            } else {
                $message = __('Nothing left to ship in order #%1.', $order->getIncrementId());
            }

            throw new LocalizedException($message);
        }

        $notify = $this->toBool($arguments['notify_customer'] ?? false);
        $items = [];
        $lines = [];
        $requested = $arguments['items'] ?? null;
        foreach ($this->itemResolver->resolve($order, $requested, OrderItemResolver::MODE_SHIP) as $resolved) {
            $orderItem = $resolved->getItem();
            $item = $this->itemFactory->create();
            $item->setOrderItemId((int)$orderItem->getItemId());
            $item->setQty($resolved->getQty());
            $items[] = $item;
            $lines[] = sprintf('  %s x %s (%s)', $resolved->getQty(), $orderItem->getSku(), $orderItem->getName());
        }

        $tracks = [];
        foreach ((array)($arguments['tracks'] ?? []) as $row) {
            $track = $this->buildTrack((array)$row);
            $tracks[] = $track;
            $lines[] = sprintf('  tracking: %s %s', $track->getTitle(), $track->getTrackNumber());
        }
        $lines[] = '  notify customer: ' . ($notify ? 'yes (' . $order->getCustomerEmail() . ')' : 'no');

        if ($dryRun) {
            return sprintf("Would create shipment for order #%s:\n%s", $order->getIncrementId(), implode("\n", $lines));
        }

        $shipmentId = $this->shipOrder->execute((int)$order->getId(), $items, $notify, false, null, $tracks);
        $shipment = $this->shipmentRepository->get($shipmentId);
        // Shipping moves a pending order to processing and a fully shipped, invoiced one to complete.
        $lines[] = '  order status: ' . $this->orderLocator->load($arguments)->getStatus();

        return sprintf(
            "Created shipment #%s for order #%s:\n%s",
            $shipment->getIncrementId(),
            $order->getIncrementId(),
            implode("\n", $lines)
        );
    }

    /**
     * One tracking record from a "tracks" row
     *
     * The carrier code drives the tracking link on the storefront, so it must be one the store
     * knows; the number must not be blank or padded, or that link breaks.
     *
     * @param array $row
     * @return \Magento\Sales\Api\Data\ShipmentTrackCreationInterface
     * @throws LocalizedException
     */
    private function buildTrack(array $row): \Magento\Sales\Api\Data\ShipmentTrackCreationInterface
    {
        $number = trim((string)($row['track_number'] ?? ''));
        if ($number === '') {
            throw new LocalizedException(__('Each track needs a non-empty track_number.'));
        }

        $carrierCode = strtolower(trim((string)($row['carrier_code'] ?? 'custom')));
        $carriers = $this->shippingConfig->getAllCarriers();
        if ($carrierCode !== 'custom' && !isset($carriers[$carrierCode])) {
            throw new LocalizedException(__(
                'Unknown carrier_code "%1". Carriers: custom, %2',
                $carrierCode,
                implode(', ', array_keys($carriers))
            ));
        }

        $title = trim((string)($row['title'] ?? ''));
        if ($title === '') {
            $title = $carrierCode === 'custom'
                ? 'Custom'
                : (string)($carriers[$carrierCode]->getConfigData('title') ?: $carrierCode);
        }

        $track = $this->trackFactory->create();
        $track->setCarrierCode($carrierCode);
        $track->setTitle($title);
        $track->setTrackNumber($number);

        return $track;
    }
}
