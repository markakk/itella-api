<?php
// TODO: TBD. Debends on pakettikauppa
if (!file_exists('env.php')) {
  copy('sample.env.php', 'env.php');
}
require('env.php');

require '../vendor/autoload.php';

use Mijora\Itella\CallCourier;
use Mijora\Itella\ItellaException;
use Mijora\Itella\Pdf\Manifest;

/**
 * DEMO MANIFEST TO BE ATTACHED
 */
$items = array(
  array(
    'track_num' => 'JJFItestnr00000000015',
    'weight' => 1,
    'delivery_address' => 'Test Tester, Example str. 6, 44320 City, LT',
  ),
  array(
    'track_num' => 'JJFItestnr00000000016',
    'weight' => 1,
    'delivery_address' => 'Test Tester, Example str. 6, 44320 City, LT',
  ),
  array(
    'track_num' => 'JJFItestnr00000000017',
    'weight' => 1,
    'delivery_address' => 'Test Tester, Example str. 6, 44320 City, LT',
  ),
  array(
    'track_num' => 'JJFItestnr00000000018',
    'weight' => 1,
    'delivery_address' => 'Test Tester, Example str. 6, 44320 City, LT',
  ),
);

$manifest = new Manifest();
$manifest_string = $manifest
  ->setSenderName('TEST Web Shop')
  ->setSenderAddress('Shop str. 150')
  ->setSenderPostCode('47174')
  ->setSenderCity('Kaunas')
  ->setSenderCountry('LT')
  ->addItem($items)
  ->setToString(true)
  ->setBase64(true)
  ->printManifest('manifest.pdf')
;


$sendTo = $email;
try {
  $caller = new CallCourier($sendTo);
  $result = $caller
    ->setSenderEmail('shop@shop.lt')
    ->setSubject('E-com order booking')
    ->setPickUpAddress(array(
      'sender' => 'Name / Company name',
      'address_1' => 'Street 1',
      'postcode' => '12345',
      'city' => 'City',
      'country' => 'LT',
      'pickup_time' => '8:00 - 17:00', // Optional if using setPickUpParams() function
      'contact_phone' => '+37060000000',
    ))
    ->setPickUpParams(array(
      'date' => '2001-12-20',
      'time_from' => '08:00',
      'time_to' => '17:00',
      'info_general' => 'Message to courier',
      'id_sender' => '123',
      'id_customer' => '456',
      'id_invoice' => '789',
    ))
    ->setAttachment($manifest_string, true)
    ->callCourier($p_user, $p_secret)
  ;
  
  if (!empty($result['errors'])) {
    echo '<b>Errors:</b><br/>';
    echo implode('<br/>', $result['errors']);
    echo '<br/><br/>';
  }
  if (!empty($result['success'])) {
    echo '<b>Success:</b><br/>';
    echo implode('<br/>', $result['success']);
  }
} catch (ItellaException $e) {
  echo 'Failed to call courier, reason: ' . $e->getMessage();
}
