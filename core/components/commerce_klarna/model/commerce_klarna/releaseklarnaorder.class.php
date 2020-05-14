<?php
/**
 * Klarna for Commerce.
 *
 * Copyright 2020 by Mark Hamstra <mark@modmore.com>
 *
 * This file is meant to be used with Commerce by modmore. A valid Commerce license is required.
 *
 * @package commerce_klarna
 * @license See core/components/commerce_klarna/docs/license.txt
 */
class ReleaseKlarnaOrder extends comStatusChangeAction
{
    public function process(comOrder $order, comStatus $oldStatus, comStatus $newStatus, comStatusChange $statusChange)
    {
        foreach ($order->getTransactions() as $transaction) {
            // Only look for completed ("paid") orders. This should be updated for Commerce v1.2 where instead it should look for an authorization
            if (!$transaction->isCompleted()) {
                continue;
            }

            // Make sure we have a method
            $method = $transaction->getMethod();
            if (!$method) {
                continue;
            }

            // Make sure we have an instance of the Klarna gateway
            $gateway = $method->getGatewayInstance();
            if (!$gateway || !($gateway instanceof \modmore\Commerce_Klarna\Gateways\Klarna)) {
                continue;
            }

            // Release the order, meaning either cancel it if it hasn't been captured yet or release the remaining
            // authorization on a partially captured order.
            $gateway->releaseOrder($order, $transaction);
        }

        return true;
    }
}
