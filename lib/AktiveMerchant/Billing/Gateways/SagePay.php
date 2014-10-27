<?php

namespace AktiveMerchant\Billing\Gateways;

use AktiveMerchant\Billing\Gateway;
use AktiveMerchant\Billing\CreditCard;
use AktiveMerchant\Billing\Response;
use AktiveMerchant\Billing\CvvResult;
use DateTime;

/**
 * SagePay
 *
 * @package Aktive-Merchant
 */
class SagePay extends Gateway
{
    const TEST_URL = 'https://test.sagepay.com/Simulator/VSPDirectGateway.asp';
    const LIVE_URL = 'https://live.sagepay.com/gateway/service/vspdirect-register.vsp';

    public static $money_format = 'cents';
    public static $default_currency = 'GBP';
    public static $supported_countries = ['HK', 'US', 'GB', 'AU', 'AD', 'BE', 'CH', 'CY', 'CZ', 'DE', 'DK', 'ES', 'FI', 'FR', 'GI', 'GR', 'HU', 'IE', 'IL', 'IT', 'LI', 'LU', 'MC', 'MT', 'NL', 'NO', 'NZ', 'PL', 'PT', 'SE', 'SG', 'SI', 'SM', 'TR', 'UM', 'VA'];
    public static $supported_cardtypes  = ['visa', 'master', 'american_express', 'discover', 'maestro', 'diners_club', 'jcb'];
    public static $homepage_url = 'http://www.sagepay.co.uk/';
    public static $display_name = 'Sage Pay';

    public static $card_codes = [
        'VISA'      => 'visa',
        'MC'        => 'master',
        'MAESTRO'   => 'maestro',
        'AMEX'      => 'american_express',
        'DC'        => 'diners_club',
        'JCB'       => 'jcb'
    ];

    /**
     * Contructor
     *
     * @param string $options
     */
    public function __construct($options)
    {
        $this->required_options('login, password', $options);
        $this->timestamp = strftime("%Y%m%d%H%M%S");
        $this->options = $options;

        if (isset($options['currency'])) {
            self::$default_currency = $options['currency'];
        }
    }

    private function commit($params)
    {
        $query = http_build_query($params);
        $raw_response = $this->ssl_post($this->url(), $query);
        $response = $this->parse($raw_response);

        $params = $this->params_for($params, $raw_response);
        $options = $this->options_for($response);
        return new Response($response['Status'] == 'OK', $response['Status'], $params, $options);
    }

    private function params_for($request, $response)
    {
        return [
            'request' => $request,
            'response' => $response
        ];
    }

    private function options_for($response)
    {
        return [
            'test' => $this->isTest(),
            'authorization' => [
                'VPSTxId' => $response['VPSTxId'],
                'SecurityKey' => $response['SecurityKey'],
                'VendorTxCode' => $this->options['login']
            ]
        ];
    }

    private function parse($str)
    {
        $response = [];
        preg_match_all('/(\w+=.+)+/', $str, $matches);

        foreach ($matches[0] as $match) {
            $exploded = explode('=', $match);
            $response[$exploded[0]] = trim($exploded[1]);
        }

        return $response;
    }

    private function url()
    {
        return $this->isTest() ? self::TEST_URL : self::LIVE_URL;
    }

    public function purchase($amount, CreditCard $creditcard, $options=array())
    {
        $params = $this->params();
        $params = $this->add_amount($params, $amount, $options);
        $params = $this->add_invoice($params, $options);
        $params = $this->add_payment_details($params, $creditcard, $options);
        $params = $this->add_shipping_address($params, $options);
        $params = $this->add_customer_data($params, $options);
        $params = $this->add_optional_data($params, $options);

        return $this->commit($params);
    }

    private function params()
    {
        return [
            'VPSProtocol' => '2.23',
            'TxType' => 'PAYMENT',
            'Vendor' => $this->options['login']
        ];
    }

    private function add_amount($params, $amount, $options)
    {
        $params['Amount'] = $amount;
        $params['Currency'] = isset($options['currency']) ? $options['currency'] : self::$default_currency;

        return $params;
    }

    private function add_invoice($params, $options)
    {
        $params['VendorTxCode'] = $options['order_id'];
        $params['Description'] = $options['description'];

        return $params;
    }

    private function add_payment_details($params, $creditcard, $options)
    {
        $params['CardHolder'] = $creditcard->name();
        $params['CardNumber'] = $creditcard->number;
        $params['ExpiryDate'] = $this->formatDate($creditcard->month, $creditcard->year);
        $params['CV2'] = $creditcard->verification_value;
        $params['CardType'] = $creditcard->type;

        $address = $this->format_address($options['address']);

        $params['BillingSurname'] = $address['last_name'];
        $params['BillingFirstnames'] = $address['first_name'];
        $params['BillingAddress1'] = $address['address1'];
        $params['BillingAddress2'] = $address['address2'];
        $params['BillingCity'] = $address['city'];
        $params['BillingPostCode'] = $address['zip'];
        $params['BillingCountry'] = $address['country'];
        $params['BillingState'] = $address['state'];

        return $params;
    }

    private function formatDate($month, $year)
    {
        return DateTime::createFromFormat('nY', $month.$year)->format('my');
    }

    /**
     * The delivery address is mandatory for Sage Pay transactions.
     * The billing address will be used if no delivery address is provided.
     */
    private function add_shipping_address($params, $options)
    {
        $address = isset($options['delivery_address']) ? $options['delivery_address'] : $options['address'];
        $address = $this->format_address($address);

        $params['DeliverySurname'] = $address['last_name'];
        $params['DeliveryFirstnames'] = $address['first_name'];
        $params['DeliveryAddress1'] = $address['address1'];
        $params['DeliveryAddress2'] = $address['address2'];
        $params['DeliveryCity'] = $address['city'];
        $params['DeliveryPostCode'] = $address['zip'];
        $params['DeliveryCountry'] = $address['country'];
        $params['DeliveryState'] = $address['state'];

        return $params;
    }

    private function format_address($address)
    {
        $defaults = [
            'name' => null,
            'address1' => null,
            'address2' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
            'country' => null
        ];

        $formatted = array_merge($defaults, $address);

        if (preg_match('/^\s*([^\s]+)\s+(.+)$/', $formatted['name'], $matches)) {
            $formatted['first_name'] = $matches[1];
            $formatted['last_name'] = $matches[2];
        }

        return $formatted;
    }

    private function add_customer_data($params, $options)
    {
        if (isset($options['ip'])) {
            $params['ClientIPAddress'] = $options['ip'];
        }

        return $params;
    }

    private function add_optional_data($params, $options)
    {
        $params['VendorTxCode'] = $options['order_id'];
        $params['Description'] = $options['description'];

        return $params;
    }
}
