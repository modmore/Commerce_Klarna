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
class CaptureKlarnaOrder extends comStatusChangeAction
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

            // Capture the full order. This wont work with multiple klarna transactions per order, but that's fine
            return $gateway->captureOrder($order, $transaction);
        }

        return true;
    }
}
