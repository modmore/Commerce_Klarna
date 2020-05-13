<?php

namespace modmore\Commerce_Klarna\Gateways;

use Commerce;
use comOrder;
use comPaymentMethod;
use comTransaction;
use modmore\Commerce\Admin\Widgets\Form\DescriptionField;
use modmore\Commerce\Admin\Widgets\Form\PasswordField;
use modmore\Commerce\Admin\Widgets\Form\SelectField;
use modmore\Commerce\Admin\Widgets\Form\TextField;
use modmore\Commerce\Gateways\Helpers\GatewayHelper;
use modmore\Commerce\Gateways\Interfaces\GatewayInterface;
use modmore\Commerce_Klarna\API\KlarnaClient;
use modmore\Commerce_Klarna\API\Objects\Session;

class Klarna implements GatewayInterface {
    private const SESSION_ID = '_klarna_session_id';

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
            $commerce->isTestMode(),
        );
    }

    /**
     * @inheritDoc
     */
    public function view(comOrder $order)
    {
        $session = null;
        $clientToken = null;
        $sessionId = $order->getProperty(self::SESSION_ID);
        $supportedMethods = [];
        $from = 'na';

        if (!empty($sessionId)) {
            $session = $this->client->request('payments/v1/sessions/' . $sessionId, [], 'GET');
            if ($session->isSuccess()) {
                $data = $session->getData();
                $clientToken = $data['client_token'];
                $supportedMethods = $data['payment_method_categories'];
                $from = 'existing';
            }
        }

        // Create a new session
        if (!$session) {
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
                'confirmation' => $this->getConfirmationUrl(),
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
                    'quantity' =>  $item->get('quantity'),
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
            
            $session = $this->client->request('payments/v1/sessions', $sessionData);
            if ($session->isSuccess()) {
                $data = $session->getData();
                $sessionId = $data['session_id'];
                $clientToken = $data['client_token'];
                $supportedMethods = $data['payment_method_categories'];
                $from = 'new';
                $order->setProperty(self::SESSION_ID, $sessionId);
                $order->save();
            }
        }

        $data = [
            'method' => $this->method->get('id'),
            'session_id' => $sessionId,
            'client_token' => $clientToken,
            'supported_methods' => $supportedMethods,
        ];

        return $this->commerce->view()->render('frontend/gateways/klarna.twig', $data);

        return "<pre>{$from} // {$sessionId} /// {$clientToken} /// " . print_r($supportedMethods, true) . ' /// ' . print_r($sessionData, true) . '</>';

        return $sessionId;
    }

    /**
     * @inheritDoc
     */
    public function submit(comTransaction $transaction, array $data)
    {
        // TODO: Implement submit() method.
    }

    /**
     * @inheritDoc
     */
    public function returned(comTransaction $transaction, array $data)
    {
        // TODO: Implement returned() method.
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
}