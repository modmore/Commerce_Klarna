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
            return count($session['payment_method_categories']) > 0;
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
            'supported_methods' => $session['payment_method_categories'],
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
            $order->log("Created Klarna session with ID {$data['session_id']} and allowed methods: {$supported}");
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
            $transaction->log("Created order {$data['order_id']}", \comTransactionLog::SOURCE_GATEWAY);
        }

        $this->commerce->adapter->log(1, print_r($response, true));
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
}