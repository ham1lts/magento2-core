<?php

namespace PlugHacker\PlugCore\Webhook\Services;

use PlugHacker\PlugCore\Kernel\Aggregates\Order;
use PlugHacker\PlugCore\Kernel\Exceptions\InvalidParamException;
use PlugHacker\PlugCore\Kernel\Exceptions\NotFoundException;
use PlugHacker\PlugCore\Kernel\Services\LocalizationService;
use PlugHacker\PlugCore\Kernel\Services\LogService;
use PlugHacker\PlugCore\Kernel\ValueObjects\TransactionStatus;
use PlugHacker\PlugCore\Recurrence\Aggregates\Subscription;
use PlugHacker\PlugCore\Webhook\Aggregates\Webhook;
use PlugHacker\PlugCore\Webhook\Exceptions\WebhookHandlerNotFoundException;

abstract class AbstractHandlerService
{
    /**
     * @var Order|Subscription
     */
    protected $order;

    public function getActionHandle($action)
    {
        $baseActions = explode('_', $action);
        $action = '';
        foreach ($baseActions as $baseAction) {
            $action .= ucfirst($baseAction);
        }

        return 'handle' . $action;
    }

    public function validateWebhookHandling($entityType)
    {
        $validEntity = $this->getValidEntity();
        if ($entityType !== $validEntity) {
            throw new InvalidParamException(
                self::class . ' only supports ' . $validEntity . ' type webhook handling!',
                $entityType . '.(action)'
            );
        }
    }

    /**
     * @param Webhook $webhook
     * @return mixed
     * @throws InvalidParamException
     * @throws NotFoundException
     * @throws WebhookHandlerNotFoundException
     */
    public function handle(Webhook $webhook)
    {
        $handler = $this->getActionHandle($webhook->getType()->getAction());

        if (method_exists($this, $handler)) {
            $this->loadOrder($webhook);
            $platformOrder = $this->order->getPlatformOrder();

            if ($platformOrder->getIncrementId() !== null) {
                $this->addWebHookReceivedHistory($webhook);
                $platformOrder->save();
                return $this->$handler($webhook);
            }

            throw new NotFoundException("Order #{$webhook->getEntity()->getCode()} not found.");
        }

        $type = "{$webhook->getType()->getEntityType()}.{$webhook->getType()->getAction()}";
        $message = "Webhook {$type} not implemented";
        $this->getLogService()->info($message);

        return [
            "message" => $message,
            "code" => 200
        ];
    }

    /**
     *
     * @return string
     */
    protected function getValidEntity()
    {
        $childClassName = substr(strrchr(static::class, "\\"), 1);
        $childEntity = str_replace('HandlerService', '', $childClassName);
        return strtolower($childEntity);
    }

    protected function addWebHookReceivedHistory(Webhook $webhook)
    {
        $i18n = new LocalizationService();
        $message = $i18n->getDashboard(
            'Webhook received: %s %s.%s',
            $webhook->getPlugId()->getValue(),
            $webhook->getType()->getEntityType(),
            $webhook->getType()->getAction()
        );

        $platformOrder = $this->order->getPlatformOrder();
        $platformOrder->addHistoryComment($message);
    }

    protected function getLogService()
    {
        return new LogService('Webhook', true);
    }

    abstract protected function loadOrder(Webhook $webhook);
}
