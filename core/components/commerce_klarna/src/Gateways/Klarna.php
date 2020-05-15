<?php

namespace modmore\Commerce_Klarna\Gateways;

use Commerce;
use comOrder;
use comPaymentMethod;
use comTransaction;
use modmore\Commerce\Admin\Widgets\Form\CheckboxField;
use modmore\Commerce\Admin\Widgets\Form\DescriptionField;
use modmore\Commerce\Admin\Widgets\Form\PasswordField;
use modmore\Commerce\Admin\Widgets\Form\SelectField;
use modmore\Commerce\Admin\Widgets\Form\SelectMultipleField;
use modmore\Commerce\Admin\Widgets\Form\TextField;
use modmore\Commerce\Exceptions\ViewException;
use modmore\Commerce\Gateways\Exceptions\TransactionException;
use modmore\Commerce\Gateways\Helpers\GatewayHelper;
use modmore\Commerce\Gateways\Interfaces\ConditionallyAvailableGatewayInterface;
use modmore\Commerce\Gateways\Interfaces\GatewayInterface;
use modmore\Commerce_Klarna\API\KlarnaClient;
use modmore\Commerce_Klarna\Gateways\Transactions\Order;
use modmore\Commerce_Klarna\Gateways\Transactions\SubmitAuthorization;

class Klarna implements GatewayInterface, ConditionallyAvailableGatewayInterface {
    private const SESSION_ID = 'klarna_session_id';

    /**
     * @var KlarnaClient
     */
    private $client;

    /**
     * @var Commerce
     */
    private $commerce;

    /**
     * @var comPaymentMethod
     */
    private $method;

    public function __construct(Commerce $commerce, comPaymentMethod $method)
    {
        $this->commerce = $commerce;
        $this->method = $method;
        $this->client = new KlarnaClient(
            $method->getProperty('endpoint', 'eu'),
            $method->getProperty('uid', ''),
            $method->getProperty('password', ''),
            $commerce->isTestMode()
        );
    }

    public function isAvailableFor(\comOrder $order): bool
    {
        try {
            $session = $this->_getOpenSession($order);
            $categories = $this->filterCategories($session['payment_method_categories']);
            return count($categories) > 0;
        }
        catch (TransactionException $e) {
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function view(comOrder $order)
    {
        $session = $this->_getOpenSession($order);

        $data = [
            'method' => $this->method->get('id'),
            'session_id' => $session['session_id'],
            'client_token' => $session['client_token'],
            'supported_methods' => $this->filterCategories($session['payment_method_categories']),
        ];

        try {
            return $this->commerce->view()->render('frontend/gateways/klarna.twig', $data);
        } catch (ViewException $e) {
            throw new TransactionException('Could not render Klarna checkout template, please use a different payment method.');
        }
    }

    /**
     * @param comOrder $order
     * @return array
     * @throws TransactionException
     */
    private function _getOpenSession(comOrder $order): array
    {
        $session = null;
        $clientToken = null;
        $sessionId = $order->getProperty(self::SESSION_ID);

        if (!empty($sessionId)) {
            $checkSession = $this->client->request('payments/v1/sessions/' . $sessionId, [], 'GET');
            $data = $checkSession->getData();
            // check for a successful request and making sure the session was not already confirmed before
            // If the session was already complete, unset the sessionId so a new session is created instead.
            if ($checkSession->isSuccess() && ($data['status'] !== 'complete')) {

                // Update the session to make sure it's fresh
                $updateSession = $this->client->request("payments/v1/sessions/{$sessionId}", $this->getSessionData($order));

                // and reload the session info
                if ($updateSession->isSuccess()) {
                    $checkSession = $this->client->request('payments/v1/sessions/' . $sessionId, [], 'GET');
                    if ($checkSession->isSuccess()) {
                        $data = $checkSession->getData();
                        $supported = implode(', ', array_map(function ($v) { return $v['identifier']; }, $data['payment_method_categories']));
                        $order->log("Updated Klarna session {$sessionId}, allowed methods: {$supported}");
                    }
                }
                
                return [
                    'session_id' => $sessionId,
                    'client_token' => $data['client_token'],
                    'payment_method_categories' => $data['payment_method_categories']
                ];
            }
        }

        // Create a new session
        $sessionData = $this->getSessionData($order);
        $session = $this->client->request('payments/v1/sessions', $sessionData);
        if ($session->isSuccess()) {
            $data = $session->getData();
            $sessionId = $data['session_id'];
            $order->setProperty(self::SESSION_ID, $sessionId);

            $supported = implode(', ', array_map(function ($v) { return $v['identifier']; }, $data['payment_method_categories']));
            $order->log("Created Klarna session {$data['session_id']} | Allowed methods: {$supported}");
            $order->save();

            return [
                'session_id' => $sessionId,
                'client_token' => $data['client_token'],
                'payment_method_categories' => $data['payment_method_categories']
            ];
        }

        throw new TransactionException('Could not prepare Klarna session');
    }

    /**
     * @inheritDoc
     */
    public function submit(comTransaction $transaction, array $data)
    {
        $order = $transaction->getOrder();
        if (!$order) {
            throw new TransactionException('Missing order on transaction.');
        }

        // Make sure the session id is stored on the transaction
        $sessionId = $transaction->getProperty(self::SESSION_ID);
        if (empty($sessionId)) {
            $sessionId = $order->getProperty(self::SESSION_ID);
            $transaction->setProperty(self::SESSION_ID, $sessionId);
            $transaction->log("Checkout submit received, assigning Klarna session ID {$sessionId} to transaction from order", \comTransactionLog::SOURCE_GATEWAY);
            $transaction->save();
        }


        // Grab the auth token to place an order
        $token = array_key_exists('authorization_token', $data) ? (string)$data['authorization_token'] : null;
        if (empty($token)) {
            throw new TransactionException("Missing Klarna authorization token in submit (session {$sessionId})");
        }

        $transaction->log("Received authorization token {$token}, placing order (session {$sessionId})", \comTransactionLog::SOURCE_GATEWAY);

        $response = $this->client->request(
            "/payments/v1/authorizations/{$token}/order",
            $this->getSessionData($order, $transaction),
            'POST'
        );

        if ($response->isSuccess()) {
            $data = $response->getData();
            $transaction->log("Created Klarna order {$data['order_id']}", \comTransactionLog::SOURCE_GATEWAY);
            
            // Generate the order reference, so we can assign it in Klarna and the customer can see it.
            $order->setReference();
            $transaction->log("Set Commerce order reference to {$order->get('reference')}", \comTransactionLog::SOURCE_GATEWAY);
            $updatedRefs = $this->client->request("/ordermanagement/v1/orders/{$data['order_id']}/merchant-references", [
                'merchant_reference1' => $order->get('reference'),
                'merchant_reference2' => $order->get('id') . ' | ' . $this->commerce->adapter->lexicon('commerce.transaction') . ' ' . $transaction->get('id'),
            ], 'PATCH');
            if (!$updatedRefs->isSuccess()) {
                $transaction->log('Could not update Klarna merchant references with order reference: ' . print_r($updatedRefs->getData(), true), \comTransactionLog::SOURCE_GATEWAY);
            }
        }

        return new SubmitAuthorization($response);
    }

    /**
     * @inheritDoc
     */
    public function returned(comTransaction $transaction, array $data)
    {
        $id = $transaction->get('reference');
        if (empty($id)) {
            throw new TransactionException('Klarna reference not set');
        }
        
        $order = $this->client->request("ordermanagement/v1/orders/{$id}", [], 'GET');
        if ($order->isSuccess()) {
            return new Order($order);
        }

        throw new TransactionException('Order not found');
    }

    /**
     * Called by the CaptureKlarnaOrder status change or a manual action, this updates and captures an order
     *
     * @param comOrder $order
     * @param comTransaction $transaction
     * @return bool
     */
    public function captureOrder(comOrder $order, comTransaction $transaction): bool
    {
        $klarnaOrder = $transaction->get('reference');
        $sessionData = $this->getSessionData($order, $transaction);
        $captureData = [
            'captured_amount' => $order->get('total'),
            'description' => 'Order ' . $order->get('reference') . ' at ' . $this->commerce->adapter->getOption('site_name'), // @todo i18n
            'reference' => "Order {$order->get('reference')} // #{$order->get('id')} // Transaction {$transaction->get('id')}",
            'order_lines' => $sessionData['order_lines'],
            'shipping_info' => [],
            'shipping_delay' => 0, // @todo consider a gateway/status change action configuration for this? not supported by klarna by default
        ];

        foreach ($order->getShipments() as $shipment) {
            $shippingInfo = array_filter([
                'shipping_company' => $this->commerce->adapter->getOption('commerce_klarna.shipping_company'), // @todo create settings in build
                'shipping_method' => $this->commerce->adapter->getOption('commerce_klarna.shipping_method'),
                'tracking_number' => $shipment->get('tracking_reference'),
                'tracking_uri' => $shipment->getTrackingURL(),
            ]);
            if (!empty($shippingInfo)) {
                $captureData['shipping_info'][] = $shippingInfo;
            }
        }

        $amt = $order->getCurrency()->format($captureData['captured_amount']);
        $attempt = $this->client->request("/ordermanagement/v1/orders/{$klarnaOrder}/captures", $captureData, 'POST');
        if ($attempt->isSuccess()) {
            $transaction->log("Captured {$amt} on Klarna order {$klarnaOrder}", \comTransactionLog::SOURCE_DASHBOARD);
            $this->_updateTransaction($order, $transaction);
            return true;
        }

        $transaction->log("Failed capturing {$amt} from Klarna order {$klarnaOrder}: " . print_r($attempt->getData(), true), \comTransactionLog::SOURCE_GATEWAY);
        return false;
    }

    /**
     * Called by the ReleaseKlarnaOrder status change or a manual action, this releases a remaining authorization amount
     * or cancels the order entirely if it has no captures.
     *
     * @param comOrder $order
     * @param comTransaction $transaction
     * @return void
     */
    public function releaseOrder(comOrder $order, comTransaction $transaction): void
    {
        $orderId = $transaction->get('reference');
        $status = $transaction->getProperty('klarna_status');
        if ($status === 'PART_CAPTURED') {
            $response = $this->client->request("ordermanagement/v1/orders/{$orderId}/release-remaining-authorization", []);
            if ($response->isSuccess()) {
                $transaction->log("Released remaining authorization for Klarna order {$orderId}", \comTransactionLog::SOURCE_GATEWAY);
            }
            else {
                $transaction->log("Could not release authorization for order {$orderId}: " . print_r($response->getData(), true), \comTransactionLog::SOURCE_GATEWAY);
            }
        }

        elseif ($status === 'AUTHORIZED') {
            $response = $this->client->request("ordermanagement/v1/orders/{$transaction->get('reference')}/cancel", []);
            if ($response->isSuccess()) {
                $transaction->log("Cancelled authorization for Klarna order {$transaction->get('reference')}", \comTransactionLog::SOURCE_GATEWAY);
            }
            else {
                $transaction->log("Could not cancel authorization for order {$orderId}: " . print_r($response->getData(), true), \comTransactionLog::SOURCE_GATEWAY);
            }
        }
        else {
            $transaction->log("Can not release authorization for an order with status {$status}", \comTransactionLog::SOURCE_GATEWAY);
        }

        $this->_updateTransaction($order, $transaction);
    }

    /**
     * @inheritDoc
     */
    public function getGatewayProperties(comPaymentMethod $method)
    {
        $fields = [];

        $fields[] = new DescriptionField($this->commerce, [
            'description' => $this->commerce->adapter->lexicon('commerce_klarna.credentials'),
        ]);

        $fields[] = new TextField($this->commerce, [
            'name' => 'properties[uid]',
            'label' => $this->commerce->adapter->lexicon('commerce_klarna.uid'),
            'value' => $method->getProperty('uid', ''),
        ]);

        $fields[] = new PasswordField($this->commerce, [
            'name' => 'properties[password]',
            'label' => $this->commerce->adapter->lexicon('commerce_klarna.password'),
            'value' => $method->getProperty('password', ''),
        ]);

        $fields[] = new SelectField($this->commerce, [
            'name' => 'properties[endpoint]',
            'label' => $this->commerce->adapter->lexicon('commerce_klarna.endpoint'),
            'description' => $this->commerce->adapter->lexicon('commerce_klarna.endpoint.desc'),
            'value' => $method->getProperty('endpoint', ''),
            'options' => [
                ['value' => 'EU', 'label' => $this->commerce->adapter->lexicon('commerce_klarna.endpoint.eu')],
                ['value' => 'NA', 'label' => $this->commerce->adapter->lexicon('commerce_klarna.endpoint.na')],
                ['value' => 'OC', 'label' => $this->commerce->adapter->lexicon('commerce_klarna.endpoint.oc')],
            ]
        ]);

        $fields[] = new SelectMultipleField($this->commerce, [
            'name' => 'properties[allowed_options]',
            'label' => $this->commerce->adapter->lexicon('commerce_klarna.allowed_options'),
            'description' => $this->commerce->adapter->lexicon('commerce_klarna.allowed_options.desc'),
            'value' => $method->getProperty('allowed_options', ['pay_now', 'pay_later', 'pay_over_time']),
            'options' => [
                ['value' => 'pay_now', 'label' => $this->commerce->adapter->lexicon('commerce_klarna.allowed_options.pay_now')],
                ['value' => 'pay_later', 'label' => $this->commerce->adapter->lexicon('commerce_klarna.allowed_options.pay_later')],
                ['value' => 'pay_over_time', 'label' => $this->commerce->adapter->lexicon('commerce_klarna.allowed_options.pay_over_time')],
            ]
        ]);

        $fields[] = new CheckboxField($this->commerce, [
            'name' => 'properties[auto_capture]',
            'label' => $this->commerce->adapter->lexicon('commerce_klarna.auto_capture'),
            'description' => $this->commerce->adapter->lexicon('commerce_klarna.auto_capture.desc'),
            'value' => $method->getProperty('auto_capture', false)
        ]);

        return $fields;
    }

    private function getConfirmationUrl(?comTransaction $transaction = null)
    {
        $checkoutResource = $this->commerce->getOption('commerce.checkout_resource', null, 3);
        $data = [];
        if ($transaction) {
            $data['transaction'] = $transaction->get('id');
        }
        return $this->commerce->adapter->makeResourceUrl($checkoutResource, '', $data, 'full');
    }

    /**
     * @param comOrder $order
     * @param comTransaction|null $transaction
     * @return array
     */
    public function getSessionData(comOrder $order, ?comTransaction $transaction = null): array
    {
        $sessionData = [];
        $defaultLocale = function_exists('locale_accept_from_http') ? locale_accept_from_http($_SERVER['HTTP_ACCEPT_LANGUAGE']) : 'en-US';
        $locale = $this->commerce->adapter->getOption('locale', null, $defaultLocale, true);
        $sessionData['locale'] = str_replace('_', '-', $locale);
        $sessionData['merchant_data'] = json_encode(['order_id' => $order->get('id')]);
        if ($ref = $order->get('reference')) {
            $sessionData['merchant_reference1'] = $ref;
        }
        $sessionData['merchant_reference2'] = $order->get('id');
        if ($transaction) {
            $sessionData['merchant_reference2'] .= ' | ' . $this->commerce->adapter->lexicon('commerce.transaction') . ' ' . $transaction->get('id');
        }
        $sessionData['merchant_urls'] = [
            'confirmation' => $this->getConfirmationUrl($transaction),
            // 'notification'
            // 'push'
        ];

        // @todo provide colors to $sessionData['options']

        $sessionData['purchase_currency'] = $order->get('currency');
        $sessionData['order_amount'] = $order->get('total_due');
        $sessionData['order_tax_amount'] = $order->get('tax');

        $sessionData['order_lines'] = [];
        foreach ($order->getItems() as $item) {
            $taxRate = 0;
            $taxRows = $item->getAppliedItemTaxRows();
            foreach ($taxRows as $row) {
                $taxRate += (int)($row->get('percentage') * 100);
            }
            $_d = [
                'name' => $item->get('name'),
//                    'product_identifiers' => [...]
                'quantity' => $item->get('quantity'),
                'reference' => $item->get('sku'),
                'total_amount' => $item->get('total'),
                'tax_rate' => $taxRate,
                'total_tax_amount' => $item->get('tax'),
                'unit_price' => (int)round($item->get('total') / $item->get('quantity')),
            ];
            $link = $item->get('link');
            if (!empty($link)) {
                $_d['product_url'] = $link;
            }
            if ($item->get('total') < 0) {
                $_d['type'] = 'discount';
            }
            $sessionData['order_lines'][] = $_d;
        }
        foreach ($order->getShipments() as $shipment) {
            $method = $shipment->getShippingMethod();
            $sessionData['order_lines'][] = [
                'type' => 'shipping_fee',
                'name' => $method ? $method->get('name') : $this->commerce->adapter->lexicon('commerce.shipping'),
                'quantity' => 1,
                'reference' => $shipment->get('id'),
                'total_amount' => $shipment->get('fee_incl_tax'),
                'tax_rate' => (int)round($shipment->get('tax_percentage') * 100),
                'total_tax_amount' => $shipment->get('tax_amount'),
                'unit_price' => $shipment->get('fee_incl_tax'),
            ];
        }

        if ($billingAddress = $order->getBillingAddress()) {
            $sessionData['purchase_country'] = $billingAddress->get('country');
            $firstName = $billingAddress->get('firstname');
            $lastName = $billingAddress->get('lastname');
            $fullName = $billingAddress->get('fullname');
            GatewayHelper::normalizeNames($firstName, $lastName, $fullName);
            $address = [
//                    'attention' => 'n/a in commerce by default',
                'city' => $billingAddress->get('city'),
                'country' => $billingAddress->get('country'),
                'email' => $billingAddress->get('email'),
                'family_name' => $lastName,
                'given_name' => $firstName,
                'organization_name' => $billingAddress->get('email'),
                'phone' => $billingAddress->get('phone'),
                'postal_code' => $billingAddress->get('zip'),
                'region' => $billingAddress->get('state'),
                'street_address' => $billingAddress->get('address1'),
                'street_address2' => $billingAddress->get('address2'),
//                    'title' => 'n/a in commerce by default',
            ];

            // @todo toggle b2b mode if company/vat id is set?
            $sessionData['billing_address'] = $address;
        }

        if ($shippingAddress = $order->getShippingAddress()) {
            $firstName = $shippingAddress->get('firstname');
            $lastName = $shippingAddress->get('lastname');
            $fullName = $shippingAddress->get('fullname');
            GatewayHelper::normalizeNames($firstName, $lastName, $fullName);
            $address = [
//                    'attention' => 'n/a in commerce by default',
                'city' => $shippingAddress->get('city'),
                'country' => $shippingAddress->get('country'),
                'email' => $shippingAddress->get('email'),
                'family_name' => $lastName,
                'given_name' => $firstName,
                'organization_name' => $shippingAddress->get('email'),
                'phone' => $shippingAddress->get('phone'),
                'postal_code' => $shippingAddress->get('zip'),
                'region' => $shippingAddress->get('state'),
                'street_address' => $shippingAddress->get('address1'),
                'street_address2' => $shippingAddress->get('address2'),
//                    'title' => 'n/a in commerce by default',
            ];

            // @todo toggle b2b mode if company/vat id is set?
            $sessionData['shipping_address'] = $address;
        }

        // Optionally support auto capture
        if ($this->method->getProperty('auto_capture')) {
            $sessionData['auto_capture'] = true;
        }

        return $sessionData;
    }

    private function _updateTransaction(comOrder $order, comTransaction $transaction)
    {
        $getUpdatedOrder = $this->client->request("ordermanagement/v1/orders/{$transaction->get('reference')}", [], 'GET');
        if ($getUpdatedOrder->isSuccess()) {
            $updatedData = $getUpdatedOrder->getData();
            $updatedOrder = new Order($getUpdatedOrder);

            $transaction->set('amount', $updatedData['captured_amount']);
            $transaction->setProperties($updatedOrder->getExtraInformation(), true);
            $transaction->log('Transaction details updated from Klarna.', \comTransactionLog::SOURCE_GATEWAY);
            $transaction->save();

            $order->calculate();
        }
    }

    /**
     * Filters provided by categories by gateway properties
     *
     * @param array $categories
     * @return array
     */
    private function filterCategories($categories)
    {
        $allowed = $this->method->getProperty('allowed_options', []);

        $filtered = [];
        foreach ($categories as $category) {
            if (in_array($category['identifier'], $allowed, true)) {
                $filtered[] = $category;
            }
        }

        return $filtered;
    }
}