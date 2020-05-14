<?php

namespace modmore\Commerce_Klarna\Gateways\Transactions;

use modmore\Commerce\Gateways\Interfaces\RedirectTransactionInterface;
use modmore\Commerce\Gateways\Interfaces\TransactionInterface;
use modmore\Commerce_Klarna\API\Response;

class SubmitAuthorization implements TransactionInterface, RedirectTransactionInterface {
    /**
     * @var array
     */
    private $data;
    /**
     * @var bool
     */
    private $success;

    /**
     * @var Response
     */
    private $response;

    public function __construct(Response $response)
    {
        $this->response = $response;
        $this->success = $response->isSuccess();
        $this->data = $response->getData();
    }

    /**
     * @inheritDoc
     */
    public function isPaid()
    {
        return false; // we always need to redirect to bounce off klarna
    }

    /**
     * @inheritDoc
     */
    public function isAwaitingConfirmation()
    {
        return true; // waiting for the customer to get back
    }

    /**
     * @inheritDoc
     */
    public function isFailed()
    {
        return array_key_exists('error_code', $this->data) || !$this->success;
    }

    /**
     * @inheritDoc
     */
    public function isCancelled()
    {
        return false; // never cancelled
    }

    /**
     * @inheritDoc
     */
    public function getErrorMessage()
    {
        if (!array_key_exists('error_code', $this->data)) {
            return '';
        }

        $return = '[' . $this->data['error_code'] . ']';
        $msgs = array_key_exists('error_message', $this->data) ? [$this->data['error_message']] : $this->data['error_messages'];
        foreach ($msgs as $msg) {
            $return .= ' - ' . $msg;
        }
        return $return;
    }

    /**
     * @inheritDoc
     */
    public function getPaymentReference()
    {
        if (array_key_exists('order_id', $this->data)) {
            return $this->data['order_id'];
        }
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getExtraInformation()
    {
        $extra = [];

        if (array_key_exists('error_code', $this->data)) {
            $extra['error'] = '[' . $this->data['error_code'] . ']';
            $msgs = array_key_exists('error_message', $this->data) ? [$this->data['error_message']] : $this->data['error_messages'];
            foreach ($msgs as $msg) {
                $extra['error'] .= ' - ' . $msg;
            }
            $extra['klarna_correlation_id'] = $this->data['correlation_id'];
        }

        if (array_key_exists('order_id', $this->data)) {
            $extra['klarna_order_id'] = $this->data['order_id'];
        }

        if (array_key_exists('redirect_url', $this->data)) {
            $extra['klarna_redirect'] = $this->data['redirect_url'];
        }

        if (array_key_exists('fraud_status', $this->data)) {
            $extra['klarna_fraud_status'] = $this->data['fraud_status'];
        }

        if (array_key_exists('authorized_payment_method', $this->data)) {
            $extra['klarna_authorized_payment_method'] = $this->data['authorized_payment_method'];
        }

        return $extra;
    }

    /**
     * @inheritDoc
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @inheritDoc
     */
    public function isRedirect()
    {
        return array_key_exists('redirect_url', $this->data) ? true : false;
    }

    /**
     * @inheritDoc
     */
    public function getRedirectMethod()
    {
        return 'GET';
    }

    /**
     * @inheritDoc
     */
    public function getRedirectUrl()
    {
        return array_key_exists('redirect_url', $this->data) ? $this->data['redirect_url'] : '';
    }

    /**
     * @inheritDoc
     */
    public function getRedirectData()
    {
        return [];
    }
}