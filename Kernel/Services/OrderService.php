<?php

namespace PlugHacker\PlugCore\Kernel\Services;

use PlugHacker\PlugCore\Kernel\Abstractions\AbstractDataService;
use PlugHacker\PlugCore\Kernel\Aggregates\Order;
use PlugHacker\PlugCore\Kernel\Abstractions\AbstractModuleCoreSetup as MPSetup;
use PlugHacker\PlugCore\Kernel\Exceptions\InvalidParamException;
use PlugHacker\PlugCore\Kernel\Interfaces\PlatformOrderInterface;
use PlugHacker\PlugCore\Kernel\Repositories\OrderRepository;
use PlugHacker\PlugCore\Kernel\ValueObjects\Id\OrderId;
use PlugHacker\PlugCore\Kernel\ValueObjects\OrderState;
use PlugHacker\PlugCore\Kernel\ValueObjects\OrderStatus;
use PlugHacker\PlugCore\Payment\Aggregates\Customer;
use PlugHacker\PlugCore\Payment\Interfaces\ResponseHandlerInterface;
use PlugHacker\PlugCore\Payment\Services\ResponseHandlers\ErrorExceptionHandler;
use PlugHacker\PlugCore\Payment\ValueObjects\CustomerType;
use PlugHacker\PlugCore\Kernel\Factories\OrderFactory;
use PlugHacker\PlugCore\Kernel\Factories\ChargeFactory;
use PlugHacker\PlugCore\Payment\Aggregates\Order as PaymentOrder;
use Exception;
use PlugHacker\PlugCore\Kernel\ValueObjects\ChargeStatus;

final class OrderService
{
    private $logService;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    public function __construct()
    {
        $this->logService = new OrderLogService();
        $this->orderRepository = new OrderRepository();
    }

    /**
     *
     * @param Order $order
     * @param bool $changeStatus
     */
    public function syncPlatformWith(Order $order, $changeStatus = true)
    {
        $moneyService = new MoneyService();

        $paidAmount = 0;
        $canceledAmount = 0;
        $refundedAmount = 0;
        foreach ($order->getCharges() as $charge) {
            $paidAmount += $charge->getPaidAmount();
            $canceledAmount += $charge->getCanceledAmount();
            $refundedAmount += $charge->getRefundedAmount();
        }

        $paidAmount = $moneyService->centsToFloat($paidAmount);
        $canceledAmount = $moneyService->centsToFloat($canceledAmount);
        $refundedAmount = $moneyService->centsToFloat($refundedAmount);

        $platformOrder = $order->getPlatformOrder();

        $platformOrder->setTotalPaid($paidAmount);
        $platformOrder->setBaseTotalPaid($paidAmount);
        $platformOrder->setTotalCanceled($canceledAmount);
        $platformOrder->setBaseTotalCanceled($canceledAmount);
        $platformOrder->setTotalRefunded($refundedAmount);
        $platformOrder->setBaseTotalRefunded($refundedAmount);

        if ($changeStatus) {
            $this->changeOrderStatus($order);
        }

        $platformOrder->save();
    }

    public function changeOrderStatus(Order $order)
    {
        $platformOrder = $order->getPlatformOrder();
        $orderStatus = $order->getStatus();
        if ($orderStatus->equals(OrderStatus::paid())) {
            $orderStatus = OrderStatus::processing();
        }

        //@todo In the future create a core status machine with the platform
        if (!$order->getPlatformOrder()->getState()->equals(OrderState::closed())) {
            $platformOrder->setStatus($orderStatus);
        }
    }
    public function updateAcquirerData(Order $order)
    {
        $dataServiceClass =
            MPSetup::get(MPSetup::CONCRETE_DATA_SERVICE);

        /**
         *
         * @var AbstractDataService $dataService
         */
        $dataService = new $dataServiceClass();

        $dataService->updateAcquirerData($order);
    }

    private function chargeAlreadyCanceled($charge)
    {
        return
            $charge->getStatus()->equals(ChargeStatus::canceled()) ||
            $charge->getStatus()->equals(ChargeStatus::failed());
    }

    private function addReceivedChargeMessages($messages, $charge, $result)
    {
        if (!is_null($result)) {
            $messages[$charge->getPlugId()->getValue()] = $result;
        }

        return $messages;
    }

    private function updateChargeInOrder($order, $charge)
    {
        if (!empty($order)) {
            $order->updateCharge($charge);
        }
    }

    public function cancelChargesAtPlug(array $charges, Order $order = null)
    {
        $messages = [];
        $APIService = new APIService();

        foreach ($charges as $charge) {
            if ($this->chargeAlreadyCanceled($charge)) {
                continue;
            }

            $result = $APIService->cancelCharge($charge);

            $messages = $this->addReceivedChargeMessages($messages, $charge, $result);

            $this->updateChargeInOrder($order, $charge);
        }

        return $messages;
    }

    public function cancelAtPlug(Order $order)
    {
        $orderRepository = new OrderRepository();
        $savedOrder = $orderRepository->findByPlugId($order->getPlugId());
        if ($savedOrder !== null) {
            $order = $savedOrder;
        }

        if ($order->getStatus()->equals(OrderStatus::canceled())) {
            return;
        }

        $results = $this->cancelChargesAtPlug($order->getCharges(), $order);

        if (empty($results)) {
            $i18n = new LocalizationService();
            $order->setStatus(OrderStatus::canceled());
            $order->getPlatformOrder()->setStatus(OrderStatus::canceled());

            $orderRepository->save($order);
            $order->getPlatformOrder()->save();

            $statusOrderLabel = $order->getPlatformOrder()->getStatusLabel(
                $order->getStatus()
            );

            $messageComplementEmail = $i18n->getDashboard(
                'New order status: %s',
                $statusOrderLabel
            );

            $sender = $order->getPlatformOrder()->sendEmail($messageComplementEmail);

            $order->getPlatformOrder()->addHistoryComment(
                $i18n->getDashboard(
                    "Order '%s' canceled at Plug",
                    $order->getPlugId()->getValue()
                ),
                $sender
            );

            return;
        }

        $this->addMessagesToPlatformHistory($results, $order);
    }

    public function addMessagesToPlatformHistory($results, $order)
    {
        $i18n = new LocalizationService();
        $history = $i18n->getDashboard("Some charges couldn't be canceled at Plug. Reasons:");
        $history .= "<br /><ul>";
        foreach ($results as $chargeId => $reason) {
            $history .= "<li>$chargeId : $reason</li>";
        }
        $history .= '</ul>';
        $order->getPlatformOrder()->addHistoryComment($history);
        $order->getPlatformOrder()->save();
    }

    public function addChargeMessagesToLog($platformOrder, $orderInfo, $errorMessages)
    {

        if (!empty($errorMessages)) {
            return;
        }

        foreach ($errorMessages as $chargeId => $reason) {
            $this->logService->orderInfo(
                $platformOrder->getCode(),
                "Charge $chargeId couldn't be canceled at Plug. Reason: $reason",
                $orderInfo
            );
        }
    }

    public function cancelAtPlugByPlatformOrder(PlatformOrderInterface $platformOrder)
    {
        $orderId = $platformOrder->getPlugId();
        if (empty($orderId)) {
            return;
        }

        $APIService = new APIService();

        $order = $APIService->getOrder($orderId);
        if (is_a($order, Order::class)) {
            $this->cancelAtPlug($order);
        }
    }

    /**
     * @param PlatformOrderInterface $platformOrder
     * @return array
     * @throws \Exception
     */
    public function createOrderAtPlug(PlatformOrderInterface $platformOrder)
    {
        try {
            $orderInfo = $this->getOrderInfo($platformOrder);

            $this->logService->orderInfo(
                $platformOrder->getCode(),
                'Creating order.',
                $orderInfo
            );

            //set pending
            $platformOrder->setState(OrderState::stateNew());
            $platformOrder->setStatus(OrderStatus::pending());

            //build PaymentOrder based on platformOrder
            $paymentOrder =  $this->extractPaymentOrderFromPlatformOrder($platformOrder);

            $i18n = new LocalizationService();

            //Send through the APIService to plug
            $apiService = new APIService();
            $response = $apiService->createOrder($paymentOrder);

            $forceCreateOrder = MPSetup::getModuleConfiguration()->isCreateOrderEnabled();

            if (!$forceCreateOrder && !$this->wasOrderChargedSuccessfully($response)) {
                $this->logService->orderInfo(
                    $platformOrder->getCode(),
                    "Can't create order. - Force Create Order: {$forceCreateOrder} | Order or charge status failed",
                    $orderInfo
                );

                $charges = $this->createChargesFromResponse($response);
                $errorMessages = $this->cancelChargesAtPlug($charges);

                $this->addChargeMessagesToLog($platformOrder, $orderInfo, $errorMessages);

                $this->persistListChargeFailed($response);

                $message = $i18n->getDashboard("Can't create order.");
                throw new \Exception($message, 400);
            }

            $platformOrder->save();

            $orderFactory = new OrderFactory();
            $order = $orderFactory->createFromPostData($response);
            $order->setPlatformOrder($platformOrder);

            $handler = $this->getResponseHandler($order);
            $handler->handle($order, $paymentOrder);

            $platformOrder->save();

            if (!$this->wasOrderChargedSuccessfully($response)) {
                $this->logService->orderInfo(
                    $platformOrder->getCode(),
                    "Can't create order. - Force Create Order: {$forceCreateOrder} | Order or charge status failed",
                    $orderInfo
                );
                $message = $i18n->getDashboard("Can't create order.");
                throw new \Exception($message, 400);
            }

            return [$order];
        } catch (\Exception $e) {
            $this->logService->orderInfo(
                $platformOrder->getCode(),
                $e->getMessage(),
                $orderInfo
            );
            $exceptionHandler = new ErrorExceptionHandler();
            $paymentOrder = new PaymentOrder();
            $paymentOrder->setOrderId($platformOrder->getcode());
            $frontMessage = $exceptionHandler->handle($e, $paymentOrder);

            throw new \Exception($frontMessage, 400);
        }
    }

    /** @return ResponseHandlerInterface */
    private function getResponseHandler($response)
    {
        $responseClass = get_class($response);
        $responseClass = explode('\\', $responseClass);

        $responseClass =
            'PlugHacker\\PlugCore\\Payment\\Services\\ResponseHandlers\\' .
            end($responseClass) . 'Handler';

        return new $responseClass;
    }

    public function extractPaymentOrderFromPlatformOrder(PlatformOrderInterface $platformOrder)
    {
        $orderInfo = $this->getOrderInfo($platformOrder);
        $moduleConfig = MPSetup::getModuleConfiguration();

        $moneyService = new MoneyService();
        $order = new PaymentOrder();
        $order->setAmount(
            $moneyService->floatToCents(
                $platformOrder->getGrandTotal()
            )
        );

        $order->setCustomer($platformOrder->getCustomer());
        $order->setAntifraudEnabled($moduleConfig->isAntifraudEnabled());
        $order->setPaymentMethod($platformOrder->getPaymentMethod());

        $payments = $platformOrder->getPaymentMethodCollection();
        foreach ($payments as $payment) {
            $order->addPayment($payment);
        }

        if (!$order->isPaymentSumCorrect()) {
            $message = 'The sum of payments is different than the order amount!';
            $this->logService->orderInfo(
                $platformOrder->getCode(),
                $message,
                $orderInfo
            );
            throw new \Exception($message, 400);
        }

        $order->setOrderId($platformOrder->getCode());

        return $order;
    }

    /**
     * @param PlatformOrderInterface $platformOrder
     * @return \stdClass
     */
    public function getOrderInfo(PlatformOrderInterface $platformOrder)
    {
        $orderInfo = new \stdClass();
        $orderInfo->grandTotal = $platformOrder->getGrandTotal();
        return $orderInfo;
    }

    private function responseHasChargesAndFailed($response)
    {
        return !isset($response['status']) || $response['status'] == 'failed';
    }

    /**
     * @param $response
     * @return boolean
     */
    private function wasOrderChargedSuccessfully($response)
    {

        if ($this->responseHasChargesAndFailed($response)) {
            return false;
        }

        foreach ($response['transactionRequests'] as $charge) {
            if (isset($charge['requestStatus']) && $charge['requestStatus'] == 'failed') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param $response
     * @throws InvalidParamException
     * @throws Exception
     */
    private function persistListChargeFailed($response)
    {
        if (empty($response['charges'])) {
            return;
        }

        $charges = $this->createChargesFromResponse($response);
        $chargeService = new ChargeService();

        foreach ($charges as $charge) {
            $chargeService->save($charge);
        }
    }

    private function createChargesFromResponse($response)
    {
        if (empty($response['charges'])) {
            return [];
        }

        $charges = [];
        $chargeFactory = new ChargeFactory();

        foreach ($response['charges'] as $chargeResponse) {
            $order = ['order' => ['id' => $response['id']]];
            $charge = $chargeFactory->createFromPostData(
                array_merge($chargeResponse, $order)
            );

            $charges[] = $charge;
        }

        return $charges;
    }

    /**
     * @return Order|null
     * @throws InvalidParamException
     */
    public function getOrderByPlugId(OrderId $orderId)
    {
        return $this->orderRepository->findByPlugId($orderId);
    }

    /**
     * @param string $platformOrderID
     * @return Order|null
     */
    public function getOrderByPlatformId($platformOrderID)
    {
        return $this->orderRepository->findByPlatformId($platformOrderID);
    }
}
