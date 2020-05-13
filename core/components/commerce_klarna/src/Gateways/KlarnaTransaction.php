<?php

namespace modmore\Commerce_Klarna\Gateways;

use modmore\Commerce\Gateways\Interfaces\TransactionInterface;

class KlarnaTransaction implements TransactionInterface {

    /**
     * @inheritDoc
     */
    public function isPaid()
    {
        // TODO: Implement isPaid() method.
    }

    /**
     * @inheritDoc
     */
    public function isAwaitingConfirmation()
    {
        // TODO: Implement isAwaitingConfirmation() method.
    }

    /**
     * @inheritDoc
     */
    public function isFailed()
    {
        // TODO: Implement isFailed() method.
    }

    /**
     * @inheritDoc
     */
    public function isCancelled()
    {
        // TODO: Implement isCancelled() method.
    }

    /**
     * @inheritDoc
     */
    public function getErrorMessage()
    {
        // TODO: Implement getErrorMessage() method.
    }

    /**
     * @inheritDoc
     */
    public function getPaymentReference()
    {
        // TODO: Implement getPaymentReference() method.
    }

    /**
     * @inheritDoc
     */
    public function getExtraInformation()
    {
        // TODO: Implement getExtraInformation() method.
    }

    /**
     * @inheritDoc
     */
    public function getData()
    {
        // TODO: Implement getData() method.
    }
}