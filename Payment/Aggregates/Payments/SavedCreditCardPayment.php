<?php

namespace PlugHacker\PlugCore\Payment\Aggregates\Payments;

use PlugHacker\PlugAPILib\Models\CreateCreditCardPaymentRequest;
use PlugHacker\PlugCore\Kernel\ValueObjects\Id\CustomerId;
use PlugHacker\PlugCore\Payment\ValueObjects\AbstractCardIdentifier;
use PlugHacker\PlugCore\Payment\ValueObjects\CardId;

final class SavedCreditCardPayment extends AbstractCreditCardPayment
{
    /** @var CustomerId */
    private $owner;

    private $cvv;

    /**
     * @return CustomerId
     */
    public function getOwner()
    {
        return $this->owner;
    }

    /**
     * @param CustomerId $owner
     */
    public function setOwner(CustomerId $owner)
    {
        $this->owner = $owner;
    }

    public function jsonSerialize(): mixed
    {
        $obj = parent::jsonSerialize();

        $obj->cardId = $this->identifier;
        $obj->owner = $this->owner;

        return $obj;
    }

    public function setIdentifier(AbstractCardIdentifier $identifier)
    {
        $this->identifier = $identifier;
    }

    /**
     * @param CardId $identifier
     */
    public function setCardId(CardId $cardId)
    {
        $this->setIdentifier($cardId);
    }

    public function setCvv($cvv)
    {
        $this->cvv = $cvv;
    }

    public function getCvv()
    {
        return $this->cvv;
    }

    /**
     * @return CreateCreditCardPaymentRequest
     */
    protected function convertToPrimitivePaymentRequest()
    {
        $paymentRequest = parent::convertToPrimitivePaymentRequest();

        $paymentRequest->cardId = $this->getIdentifier()->getValue();

        return $paymentRequest;
    }
}
