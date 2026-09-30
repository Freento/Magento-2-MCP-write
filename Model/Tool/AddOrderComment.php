<?php

declare(strict_types=1);

namespace Freento\McpWrite\Model\Tool;

use Freento\McpWrite\Model\Helper\OrderLocator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderStatusHistoryInterfaceFactory;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;

/**
 * Add a comment to an order, optionally change its status and notify the customer
 */
class AddOrderComment extends AbstractWriteTool
{
    /** Characters of the comment echoed back; the caller already has the full text */
    private const ECHO_LIMIT = 120;

    /**
     * @param Context $context
     * @param OrderLocator $orderLocator
     * @param OrderManagementInterface $orderManagement
     * @param OrderStatusHistoryInterfaceFactory $historyFactory
     * @param OrderConfig $orderConfig
     */
    public function __construct(
        Context $context,
        private readonly OrderLocator $orderLocator,
        private readonly OrderManagementInterface $orderManagement,
        private readonly OrderStatusHistoryInterfaceFactory $historyFactory,
        private readonly OrderConfig $orderConfig
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'add_order_comment';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Add a comment to an order, optionally change its status and email the customer.

The new status must belong to the order\'s current state (Magento rule). Shipping, refunding
and cancelling are separate tools. Use get_orders to find the order.

Example prompts:
- "Add note to order 000000123: customer called, ships Monday"
- "Tell the customer of order 000000124 that the item is back-ordered"';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->orderLocator->schemaProperties() + [
                'comment' => ['type' => 'string'],
                'status' => ['type' => 'string', 'description' => 'New status code within the current state. Optional.'],
                'notify_customer' => ['type' => 'boolean', 'description' => 'Email the comment to the customer. Default: false'],
                'visible_on_front' => ['type' => 'boolean', 'description' => 'Show in the customer account. Default: false'],
            ] + $this->dryRunProperty(),
            'required' => ['comment'],
            'examples' => [
                ['order_increment_id' => '000000123', 'comment' => 'Customer called, ships Monday'],
                ['order_increment_id' => '000000124', 'comment' => 'Your item is back-ordered', 'notify_customer' => true],
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
        $comment = $this->requireString($arguments, 'comment');
        $notify = $this->toBool($arguments['notify_customer'] ?? false);
        $visible = $this->toBool($arguments['visible_on_front'] ?? false);
        $status = trim((string)($arguments['status'] ?? ''));

        $allowed = $this->orderConfig->getStateStatuses((string)$order->getState());
        if ($status !== '' && !isset($allowed[$status])) {
            throw new LocalizedException(__(
                'Status "%1" is not allowed for order #%2 in state "%3". Allowed: %4',
                $status,
                $order->getIncrementId(),
                $order->getState(),
                implode(', ', array_keys($allowed))
            ));
        }

        // Order::addStatusHistory() copies the history status onto the order unconditionally,
        // so an empty one would wipe the order status. Keep the current status when none is given.
        $current = (string)$order->getStatus();
        $newStatus = $status !== '' ? $status : $current;
        if ($newStatus === '') {
            $newStatus = (string)$this->orderConfig->getStateDefaultStatus((string)$order->getState());
        }

        if (!$dryRun) {
            $history = $this->historyFactory->create();
            $history->setComment($comment);
            $history->setIsCustomerNotified((int)$notify);
            $history->setIsVisibleOnFront((int)$visible);
            $history->setStatus($newStatus);
            $history->setEntityName(Order::ENTITY);
            $this->orderManagement->addComment((int)$order->getId(), $history);
        }

        return sprintf(
            "%s comment to order #%s (status %s):\n  \"%s\"\n  visible to customer: %s\n  notify customer: %s",
            $dryRun ? 'Would add' : 'Added',
            $order->getIncrementId(),
            $newStatus === $current ? $current : $current . ' -> ' . $newStatus,
            mb_strlen($comment) > self::ECHO_LIMIT ? mb_substr($comment, 0, self::ECHO_LIMIT) . '…' : $comment,
            $visible ? 'yes' : 'no',
            $notify ? 'yes (' . $order->getCustomerEmail() . ')' : 'no'
        );
    }
}
