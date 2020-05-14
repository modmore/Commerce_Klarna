<?php

namespace modmore\Commerce_Klarna\Gateways\Transactions;

use modmore\Commerce\Gateways\Interfaces\TransactionInterface;
use modmore\Commerce_Klarna\API\Response;

class Order implements TransactionInterface {
    protected $status;
    private $orderData;

    public function __construct(Response $response)
    {
        $this->orderData = $response->getData();
        $this->status = $this->orderData['status'];
    }

    /**
     * @inheritDoc
     */
    public function isPaid()
    {
        return in_array($this->status, ['AUTHORIZED', 'PART_CAPTURED', 'CAPTURED'], true);
    }

    /**
     * @inheritDoc
     */
    public function isAwaitingConfirmation()
    {
        // orders are never awaiting confirmation
        return false;
    }

    /**
     * @inheritDoc
     */
    public function isFailed()
    {
        return in_array($this->status, ['CANCELLED', 'CLOSED'], true);
    }

    /**
     * @inheritDoc
     */
    public function isCancelled()
    {
        return $this->status === 'CANCELLED';
    }

    /**
     * @inheritDoc
     */
    public function getErrorMessage()
    {
        return ''; // never have an error on an order
    }

    /**
     * @inheritDoc
     */
    public function getPaymentReference()
    {
        if (array_key_exists('order_id', $this->orderData)) {
            return $this->orderData['order_id'];
        }
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getExtraInformation()
    {
        $extra = [];

        if (array_key_exists('order_id', $this->orderData)) {
            $extra['klarna_order_id'] = $this->orderData['order_id'];
        }
        if (array_key_exists('klarna_reference', $this->orderData)) {
            $extra['klarna_reference'] = $this->orderData['klarna_reference'];
        }
        if (array_key_exists('refunded_amount', $this->orderData)) {
            $extra['klarna_refunded_amount'] = number_format($this->orderData['refunded_amount'] / 100, 2);
        }
        if (array_key_exists('remaining_authorized_amount', $this->orderData)) {
            $extra['klarna_remaining_authorized_amount'] = number_format($this->orderData['remaining_authorized_amount'] / 100, 2);
        }
        if (array_key_exists('captured_amount', $this->orderData)) {
            $extra['klarna_captured_amount'] = number_format($this->orderData['captured_amount'] / 100, 2);
        }
        if (array_key_exists('status', $this->orderData)) {
            $extra['klarna_status'] = $this->orderData['status'];
        }
        if (array_key_exists('fraud_status', $this->orderData)) {
            $extra['klarna_fraud_status'] = $this->orderData['fraud_status'];
        }
        if (array_key_exists('authorized_payment_method', $this->orderData)) {
            $extra['klarna_authorized_payment_method'] = $this->orderData['authorized_payment_method'];
        }

        return $extra;
    }

    /**
     * @inheritDoc
     */
    public function getData()
    {
        return $this->orderData;
    }
}