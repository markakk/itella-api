# Itella-API

### How to use:

```php
require __PATH_TO_LIB__ . 'itella-api/vendor/autoload.php';
```

where \_\_PATH_TO_LIB__ is path to where itella-api is placed. This will load Mijora\Itella namespace

Most of values is expected to be correct - in some cases where certains values are important and not supplied it will throw Exception.


### Creating Authentication Object
---
```php
$isTest = false;
$auth = new \Mijora\Itella\Auth($user, $pass, $isTest);
$token_array = $auth->$auth->getAuth();
```
$user - Itella API user

$pass - Itella API password

$isTest - should api use test mode (boolean)

$token_array will contain either error message (if something went wrong) or [access_token, expires, expires_in, token_type] if succesfull. Token is issued for 1h - to check for that please use expires key - UNIX timestamp when token expires. It is possible to pass array into Auth object instead of using getAuth() to request new token.

Example usage
```php
$current_token = load_previously_saved_token_array();
if ($current_token['expires'] <= time()) {
  // Getging new Token
  $new_token_array = $auth->getAuth();
  file_put_contents('token.json', json_encode($new_token_array));
} else {
  // Using saved Token
  $auth->setTokenArr($current_token);
}
```

### Creating Sender
---
```php
$sender = new \Mijora\Itella\Shipment\Party(\Mijora\Itella\Shipment\Party::ROLE_SENDER);
$sender
  ->setContract('65407')
  ->setName1('TEST Web Shop')
  ->setStreet1('Raudondvario pl. 150')
  ->setPostCode('47174')
  ->setCity('Kaunas')
  ->setCountryCode('LT');
```


### Creating Receiver
---
```php
$receiver = new \Mijora\Itella\Shipment\Party(\Mijora\Itella\Shipment\Party::ROLE_RECEIVER);
$receiver
  ->setName1('Testas')
  ->setStreet1('Pavytes g. 4')
  ->setPostCode('46129')
  ->setCity('Kaunas')
  ->setCountryCode('LT')
  ->setContactName('Testas')
  ->setContactMobile('865412345')
  ->setContactEmail('testas@testutis.lt'); // optional
```

When sending to pickup point (SMARTPOST) additional info must be supplied
```php
  ->setName2('Testutis') // if SmartPost, then name of pick-up point is given
  ->setStreet1('Testutis') // if SmartPost, then street address of pick-up point is given
  ->setPostCode('Testutis') // if SmartPost, then postal code of pick-up point is given
```

### Creating Order Items
---
```php
$item = new \Mijora\Itella\Shipment\GoodsItem(\Mijora\Itella\Shipment\GoodsItem::PRODUCT_COURIER);
$item
  ->addExtraService([3102, 3101]) // Multi
  ->setTrackingNumber('Testas123');
```

- \Mijora\Itella\Shipment\GoodsItem::PRODUCT_COURIER - code when courier option is selected
- \Mijora\Itella\Shipment\GoodsItem::PRODUCT_PICKUP - code when pickup point option is selected (DOES NOT ALLOW EXTRA SERVICES)

PRODUCT_COURIER available extra services:
- 3101 - Cash On Delivery (only by credit card), COD information MUST be set in Shipment object
- 3102 - Multi Parcel
- 3104 - Fragile
- 3166 - Call before Delivery
- 3174 - Oversized

In case of multi parcels simply create multiple GoodsItem objects with set multi parcel extra service (as well any other service that is needed). It is considered as same order and can have no more than 10 parcels. Each must have different Tracking Number set.

### Create Shipment
---
```php
$isTest = false;
$shipment = new \Mijora\Itella\Shipment\Shipment($isTest);
$shipment
  ->setAuth($auth) // previously created Auth object
  ->setSenderId('ma_LT100007721311_1') // Itella API user
  ->setReceiverId('ITELLT') // Itella code for Lithuania
  ->setShipmentNumber('TESTNR231') // Shipment/waybill identifier
  ->setShipmentDateTime(date('c')) // when shipment is ready for transport. Format must be ISO 8601, e.g. 2019-10-11T10:00:00+03:00
  ->setSenderParty($sender) // previously created Sender object
  ->setReceiverParty($receiver) // previously created Receiver object
  ->addGoodsItem([$item2, $item2, $item2, $item2]) // array of previously created GoodsItem objects, can also be just GoodsItem onject
  // needed only if COD extra service is used
  ->setBIC('testBIC') // Bank BIC
  ->setIBAN('LT123425678') // Bank account
  ->setValue(100.50) // Total to pay in EUR
  ->setReference($shipment->gereateCODReference('012')); // COD reference,here using function from Shipment class to generate reference code by order ID
```

To get Shipment Document creation time and Sequence (used to identify requests)
```php
$documentDateTime = $shipment->getDocumentDateTime();
$sequence = $shipment->getSequence();
```

Once all information is supplied -  send request to Itella API server
```php
$result = $shipment->sendShipment();
if (isset($result['error'])) {
  echo '<br>Shipment Failed with error: ' . $result['error_description'];
} else {
  echo '<br>Shipment sent: ' . $result['success_description'];
}
```

$result is array [error, error_description] or [success, success_description]

Once used Tracking Number should not be reused again.

When testing it is usefull to check generated XML for that each class (except Auth) has getXML() function that return SimpleXMLElement.

```php
echo $sender->getXML()->asXML();
echo $receiver->getXML()->asXML();
echo $item->getXML()->asXML();
echo $shipment->getXML()->asXML();
```


### Locations API
---
When using Pickup Point option it is important to have correct list of pickup points
```php
// Initiate locations object
$itellaPickupPointsObj = new \Mijora\Itella\Locations\PickupPoints('https://locationservice.posti.com/api/2/location');
// it is advised to download locations for each country separately
// this will return filtered pickup points list as array
$itellaLoc = $itellaPickupPointsObj->getLocationsByCountry('LT');
// now points can be stored into file or database for future use
$itellaPickupPointsObj->saveLocationsToJSONFile('test.json', json_encode($itellaLoc));
```
