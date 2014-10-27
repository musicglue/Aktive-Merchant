<?php
require_once('../../autoload.php');
require_once('../../vendor/autoload.php');
require_once('../login.php');

use AktiveMerchant\Billing\Base;
use AktiveMerchant\Billing\CreditCard;

Base::mode('test'); # Remove this on production mode

$gateway = Base::gateway('sage_pay', [
    'login' => SAGE_PAY_LOGIN,
    'password' => SAGE_PAY_PASS
]);

$creditcard = new CreditCard([
    "first_name" => "John",
    "last_name" => "Doe",
    "number" => "4929000000006",
    "month" => "01",
    "year" => "2015",
    "verification_value" => "123"
]);

$options = [
    'order_id' => 'REF' . $gateway->generateUniqueId(),
    'description' => 'Worldpay Test Transaction',
    'address' => [
        'name' => 'Cosmo Kramer',
        'address1' => '1234 Street',
        'zip' => '98004',
        'state' => 'WA',
        'city' => 'Seattle',
        'country' => 'US'
    ]
];

try {
    if ($creditcard->isValid()) {
        $response = $gateway->purchase('7.99', $creditcard, $options);
        echo $response->message()."\n";
    } else {
        var_dump($creditcard->errors());
    }
} catch (Exception $e) {
    echo $e->getMessage()."\n";
}
