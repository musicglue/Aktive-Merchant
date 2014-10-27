<?php

namespace AktiveMerchant\Billing\Gateways;

use AktiveMerchant\Billing\Gateway;
use AktiveMerchant\Billing\CreditCard;
use AktiveMerchant\Billing\Response;
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
    }

    private function commit($params)
    {
        $url = $this->url();
        $query = http_build_query($params);

        $response = $this->parse($this->ssl_post($url, $query));

        $params = [];
        $options = ['test' => $this->isTest()];
        return new Response($response['Status'] == 'OK', $response['Status'], $params, $options);
    }

    private function parse($response)
    {
        preg_match_all('/(\w+=.+)+/', $response, $matches);

        $return = [];
        foreach ($matches[0] as $match) {
            $exploded = explode('=', $match);
            $return[$exploded[0]] = $exploded[1];
        }

        return $return;
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

/*     public function authorize($money, CreditCard $creditcard, $options=array()) */
/*     { */
/*         $this->required_options('order_id', $options); */
/*         $this->build_authorization_request($money, $creditcard, $options); */
/*         return $this->commit('AUTHORISED'); */
/*     } */

/*     public function capture($money, $authorization, $options = array()) */
/*     { */
/*         $this->build_capture_request($money, $authorization, $options); */
/*         return $this->commit('ok'); */
/*     } */

/*     public function build_authorization_request($money, $creditcard, $options, $testingXmlGeneration = false) */
/*     { */
/*         $this->xml = $this->createXmlBuilder(); */
        
/*         $this->xml->load(array( */
/*             'merchantCode' => $this->options['login'], */
/*             'version' => '1.4', */
/*             'submit' => array( */
/*                 'order' => $this->add_order($money, $creditcard, $options) */
/*             ) */
/*         )); */

/*         if ($testingXmlGeneration) { */
/*             return $this->xml->createXML(true); */
/*         } */
/*     } */

/*     public function build_capture_request($money, $authorization, $options, $testingXmlGeneration = false) */
/*     { */
/*         $this->xml = $this->createXmlBuilder(); */

/*         $this->xml->load(array( */
/*             'merchantCode' => $this->options['login'], */
/*             'version' => '1.4', */
/*             'modify' => $this->add_capture_modification($money, $authorization, $options) */
/*         )); */

/*         if ($testingXmlGeneration) { */
/*             return $this->xml->createXML(true); */
/*         } */
/*     } */

/*     private function createXmlBuilder() */
/*     { */
/*         $xml = new \Thapp\XmlBuilder\XmlBuilder('paymentService', new XmlNormalizer); */
/*         $xml->setDocType( */
/*             'paymentService', */ 
/*             '-//WorldPay//DTD WorldPay PaymentService v1//EN', */ 
/*             'http://dtd.worldpay.com/paymentService_v1.dtd' */
/*         ); */

/*         $xml->setRenderTypeAttributes(false); */
/*         $xml->setAttributeMapp(array('paymentService' => array('merchantCode', 'version'))); */
        
/*         return $xml; */
/*     } */

/*     private function add_order($money, $creditcard, $options) */
/*     { */
/*         $attrs = array('orderCode' => $options['order_id']); */
/*         $attrs['installationId'] = $this->options['inst_id']; */

/*         return array( */
/*             '@attributes' => $attrs, */
/*             array( */
/*                 'description' => 'Purchase', */
/*                 'amount' => $this->add_amount($money, $options), */
/*                 'paymentDetails' => $this->add_payment_method($money, $creditcard, $options) */
/*             ) */
/*         ); */
/*     } */

/*     private function add_capture_modification($money, $authorization, $options) */
/*     { */
/*         $now = new \DateTime(null, new \DateTimeZone('UTC')); */

/*         return array( */
/*             'orderModification' => array( */
/*                 '@attributes' => array('orderCode' => $authorization), */
/*                 array( */
/*                     'capture' => array( */
/*                         'date' => array( */
/*                             '@attributes' => array( */
/*                                 'dayOfMonth' => $now->format('d'), */
/*                                 'month' => $now->format('m'), */
/*                                 'year' => $now->format('Y') */
/*                             ) */
/*                         ), */
/*                         'amount' => $this->add_amount($money, $options) */
/*                     ) */
/*                 ) */
/*             ) */
/*         ); */
/*     } */

/*     private function add_payment_method($money, $creditcard, $options) */
/*     { */
/*         $cardCode = self::$card_codes[$creditcard->type]; */

/*         return array( */
/*             $cardCode => array( */
/*                 'cardNumber' => $creditcard->number, */
/*                 'expiryDate' => array( */
/*                     'date' => array( */
/*                         '@attributes' => array( */
/*                             'month' => $this->cc_format($creditcard->month, 'two_digits'), */
/*                             'year' => $this->cc_format($creditcard->year, 'four_digits') */
/*                         ) */
/*                     ) */
/*                 ), */
/*                 'cardHolderName' => $creditcard->name(), */
/*                 'cvc' => $creditcard->verification_value, */
/*                 'cardAddress' => $this->add_address($options) */
/*             ) */
/*         ); */
/*     } */

/*     private function add_amount($money, $options) */
/*     { */
/*         $currency = isset($options['currency']) ? $options['currency'] : self::$default_currency; */

/*         return array( */
/*             '@attributes' => array( */
/*                 'value' => $this->amount($money), */
/*                 'currencyCode' => $currency, */
/*                 'exponent' => 2 */
/*             ) */
/*         ); */
/*     } */

/*     private function add_address($options) */
/*     { */
/*         $address = isset($options['billing_address']) ? $options['billing_address'] : $options['address']; */

/*         $out = array(); */

/*         if (isset($address['name'])) { */
/*             if (preg_match('/^\s*([^\s]+)\s+(.+)$/', $address['name'], $matches)) { */
/*                 $out['firstName'] = $matches[1]; */
/*                 $out['lastName'] = $matches[2]; */
/*             } */
/*         } */

/*         if (isset($address['address1'])) { */
/*             if (preg_match('/^\s*(\d+)\s+(.+)$/', $address['address1'], $matches)) { */
/*                 $out['street'] = $matches[2]; */
/*                 $houseNumber = $matches[1]; */
/*             } else { */
/*                 $out['street'] = $address['address1']; */
/*             } */
/*         } */

/*         if (isset($address['address2'])) { */
/*             $out['houseName'] = $address['address2']; */
/*         } */

/*         if (isset($houseNumber)) { */
/*             $out['houseNumber'] = $houseNumber; */
/*         } */

/*         $out['postalCode'] = isset($address['zip']) ? $address['zip'] : '0000'; */

/*         if (isset($address['city'])) { */
/*             $out['city'] = $address['city']; */
/*         } */

/*         $out['state'] = isset($address['state']) ? $address['state'] : 'N/A'; */

/*         $out['countryCode'] = $address['country']; */

/*         if (isset($address['phone'])) { */
/*             $out['telephoneNumber'] = $address['phone']; */
/*         } */

/*         return array('address' => $out); */
/*     } */

/*     private function commit($successCriteria) */
/*     { */
/*         $url = $this->isTest() ? self::TEST_URL : self::LIVE_URL; */

/*         $options = array('headers' => array( */
/*             "Authorization: {$this->encoded_credentials()}" */
/*         )); */

/*         $response = $this->parse($this->ssl_post($url, $this->xml->createXML(true), $options)); */
/*         $success = $this->success_from($response, $successCriteria); */
/*         return new Response( */
/*             $success, */ 
/*             $this->message_from($success, $response, $successCriteria), */ 
/*             $this->params_from($response), */ 
/*             $this->options_from($response) */
/*         ); */
/*     } */

/*     private function parse($response_xml) */
/*     { */
/*         $xml = new \Thapp\XmlBuilder\XmlBuilder(); */
/*         $dom = $xml->loadXml($response_xml, true, false); */
/*         return $xml->toArray($dom); */
/*     } */

/*     private function success_from($response, $successCriteria) */
/*     { */
/*         if ($successCriteria == 'ok') { */
/*             return isset($response['paymentService']['reply']['ok']); */
/*         } */
            
/*         if (isset($response['paymentService']['reply']['orderStatus'])) { */
/*             return $response['paymentService']['reply']['orderStatus']['payment']['lastEvent'] == $successCriteria; */
/*         } */

/*         return false; */
/*     } */

/*     private function message_from($success, $response, $successCriteria) */
/*     { */
/*         if ($success) { */
/*             return "SUCCESS"; */
/*         } */

/*         if (isset($response['paymentService']['reply']['error'])) { */
/*             return $response['paymentService']['reply']['error']['nodevalue']; */
/*         } */

/*         return "A transaction status of $successCriteria is required."; */
/*     } */

/*     private function params_from($response) */
/*     { */
/*         return $response['paymentService']['reply']; */
/*     } */

/*     private function options_from($response) */
/*     { */
/*         $options = array('test' => $this->isTest()); */

/*         if (isset($response['paymentService']['reply']['orderStatus'])) { */
/*             foreach ($response['paymentService']['reply']['orderStatus']['@attributes'] as $key => $value) { */
/*                 if (preg_match('/orderCode$/', $key)) { */
/*                     $options['authorization'] = $value; */
/*                 } */
/*             } */
/*         } */

/*         return $options; */
/*     } */

/*     private function encoded_credentials() */
/*     { */
/*         $credentials = $this->options['login'] . ':' . $this->options['password']; */
/*         $encoded = base64_encode($credentials); */
/*         return "Basic $encoded"; */
/*     } */
}
