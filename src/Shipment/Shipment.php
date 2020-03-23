<?php
// TODO: cleanup
// TODO: write docs
namespace Mijora\Itella\Shipment;

use Mijora\Itella\ItellaException;
// use Mijora\Itella\SimpleXMLElement;
use Mijora\Itella\Shipment\AdditionalService;
use Mijora\Itella\Helper;

use Pakettikauppa\Client as _Client;
use Pakettikauppa\Shipment as _Shipment;
// use Pakettikauppa\Shipment\Sender as _Sender;
// use Pakettikauppa\Shipment\Receiver as _Receiver;
use Pakettikauppa\Shipment\AdditionalService as _AdditionalService;
use Pakettikauppa\Shipment\Info as _Info;
use Pakettikauppa\Shipment\Parcel as _Parcel;

class Shipment
{
  //  * 3101 = Cash on delivery, specifiers: [amount, account, reference, codbic]
  const MULTIPARCEL_LIMIT = 10;

  // Service code (product)
  const PRODUCT_COURIER = 2317;
  const PRODUCT_PICKUP = 2711;

  public $valid_product_codes;

  public $isTest;
  // Auth object
  /** @var \Mijora\Itella\Auth */
  public $auth;

  /** @var string */
  private $user;
  /** @var string */
  private $pass;

  // Main request data
  public $senderId;
  public $receiverId;

  public $documentDateTime;
  public $sequence;

  // Shipment specific
  public $shipmentNumber;
  public $shipmentDateTime; // when shipment is ready for pickup

  /** @var int|string */
  private $product_code;

  // Party objects
  /** @var \Mijora\Itella\Shipment\Party */
  public $senderParty;
  /** @var \Mijora\Itella\Shipment\Party */
  public $receiverParty;

  // AdditionalService object storage
  /** @var \Mijora\Itella\Shipment\AdditionalService[] */
  public $additionalServices;

  // GoodsItem object storage
  /** @var \Mijora\Itella\Shipment\GoodsItem[] */
  public $goodsItems;
  /** @var int */
  public $totalItems; // counter for goods with MultiParcel service

  // // COD info (should be set if any of the goods item has extra service for COD)
  // private $isCod = false; // for simpler check if COD xml is needed to generate
  // public $codBIC;
  // public $codIBAN;
  // public $codValue; // EUR
  // public $codReference;

  /** @var \Pakettikauppa\Client */
  private $_client;

  public function __construct($user, $pass, $isTest = false)
  {
    $this->isTest = $isTest;
    $this->user = $user;
    $this->pass = $pass;
    $this->product_code = null;
    $this->documentDateTime = date('c');
    $this->sequence = number_format(microtime(true), 6, '', '');
    $this->additionalServices = array();
    $this->goodsItems = array();
    $this->totalItems = 0;

    $this->valid_product_codes = array(
      self::PRODUCT_COURIER,
      self::PRODUCT_PICKUP
    );

    $this->_client = new _Client(
      array(
        'pakettikauppa_config' => array(
          'api_key' => $this->user,
          'secret' => $this->pass,
          'base_uri' => 'https://nextshipping.posti.fi',
          'use_posti_auth' => true,
          'posti_auth_url' => 'https://oauth.posti.com',
        ),
      ),
      'pakettikauppa_config'
    );

    $this->initAuth();
  }

  private function initAuth()
  {
    // get token from cache
    // if token is not in cache, then:
    $token = $this->_client->getToken();

    // Check authorization was succesfull
    if (!isset($token->access_token)) {
      $error = [];
      if (isset($token->status)) {
        $error[] = 'Status: ' . $token->status;
      }

      if (isset($token->error)) {
        $error[] = 'Error: ' . $token->error;
      }

      if (isset($token->message)) {
        $error[] = 'Message: ' . $token->message;
      }

      throw new ItellaException(implode("\n ", $error));
    }
    // save token to cache
    $this->_client->setAccessToken($token->access_token);
  }

  public function asXML()
  {
    $shipment = $this->createPakettikauppaShipment();
    return $shipment->asXML();
  }

  public function registerShipment()
  {
    $shipment = $this->createPakettikauppaShipment();

    $this->_client->createTrackingCode($shipment, 'en');
    $track = $shipment->getTrackingCode();

    return $track;
  }

  public function downloadLabels($track)
  {
    if (!is_array($track)) {
      $track = array($track);
    }
    $base = $this->_client->fetchShippingLabels($track);
    return $base->{'response.file'};
  }

  private function checkForMultiParcel()
  {
    if ($this->totalItems > 1) {
      // Set multi-parcel additional service
      $multi = new AdditionalService(AdditionalService::MULTI_PARCEL, array(
        'count' => $this->totalItems
      ));
      $this->addAdditionalService($multi);
    }
  }

  private function validateProductCode()
  {
    if (!$this->product_code) {
      throw new ItellaException('Shippment must have product code');
    }

    return true;
  }

  private function validateTotalGoodsItems()
  {
    $this->totalItems = count($this->goodsItems);

    if ($this->totalItems < 1) {
      throw new ItellaException('Shipment cant be empty');
    }

    if ($this->totalItems > self::MULTIPARCEL_LIMIT) {
      throw new ItellaException('Multi-parcel shipment supports max: ' . self::MULTIPARCEL_LIMIT);
    }

    return true;
  }

  private function validateShipment()
  {
    $this->validateProductCode();

    if (!$this->senderParty) {
      throw new ItellaException("Sender is not set");
    }

    if (!$this->receiverParty) {
      throw new ItellaException("Receiver is not set");
    }

    $this->validateTotalGoodsItems();
    $this->checkForMultiParcel();
  }

  /**
   * Creates Pakettikauppa shipment object
   */
  private function createPakettikauppaShipment()
  {
    $this->validateShipment();

    $shipment = new _Shipment();
    $shipment->setShippingMethod($this->product_code);
    $shipment->setSender($this->senderParty->getPakettikauppaParty());
    $shipment->setReceiver($this->receiverParty->getPakettikauppaParty());

    $info = new _Info();
    $info->setReference($this->shipmentNumber);

    $shipment->setShipmentInfo($info);

    // add all goodsItem
    foreach ($this->goodsItems as $key => $goodsItem) {
      $parcel = new _Parcel();

      $parcel->setReference($this->shipmentNumber);
      if ($goodsItem->getGrossWeight()) {
        $parcel->setWeight($goodsItem->getGrossWeight()); // kg
      }
      if ($goodsItem->getVolume()) {
        $parcel->setVolume($goodsItem->getVolume()); // m3
      }
      if ($content_desc = $goodsItem->getContentDesc()) {
        $parcel->setContents($content_desc);
      }

      $shipment->addParcel($parcel);
    }

    // add all additional services
    foreach ($this->additionalServices as $service) {
      $_service = new _AdditionalService();

      $_service->setServiceCode($service->getCode());
      foreach ($service->getArgs() as $key => $value) {
        $_service->addSpecifier($key, $value);
      }

      $shipment->addAdditionalService($_service);
    }

    return $shipment;
  }

  /**
   * Main functions
   */
  // public function sendShipment_old()
  // {
  //   // production url
  //   $url = 'https://connect.posti.fi/transportation/v1/orders';
  //   // test url
  //   if ($this->isTest) {
  //     $url = 'https://connect.ja.posti.fi/kasipallo/transportation/v1/orders';
  //   }

  //   // check if authentication still valid and get new one if needed
  //   if (!$this->auth->isValid()) {
  //     $this->auth->getAuth();
  //   }

  //   $headers = array();
  //   $headers[] = 'Expect:';
  //   $headers[] = 'Content-type: text/xml; charset=utf-8';
  //   $headers[] = 'Authorization: Bearer ' . $this->auth->getToken();
  //   $post_data = $this->getXML()->asXML();

  //   $options = array(
  //     CURLOPT_POST            =>  1,
  //     CURLOPT_HEADER          =>  0,
  //     CURLOPT_URL             =>  $url,
  //     CURLOPT_FRESH_CONNECT   =>  1,
  //     CURLOPT_RETURNTRANSFER  =>  1,
  //     CURLOPT_FORBID_REUSE    =>  1,
  //     CURLOPT_USERAGENT       =>  'ItellaShipping-API/1.0',
  //     CURLOPT_TIMEOUT         =>  30,
  //     CURLOPT_HTTPHEADER      =>  $headers,
  //     CURLOPT_POSTFIELDS      =>  $post_data
  //   );

  //   $curl = curl_init();
  //   curl_setopt_array($curl, $options);
  //   $response = curl_exec($curl);

  //   // file_put_contents('transfer.log', '=======================', FILE_APPEND);
  //   // file_put_contents('transfer.log', $post_data, FILE_APPEND);
  //   // file_put_contents('transfer.log', '==== Response ====', FILE_APPEND);
  //   // file_put_contents('transfer.log', $response, FILE_APPEND);
  //   // file_put_contents('transfer.log', '=======================', FILE_APPEND);

  //   $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
  //   // in case of errors return array with [error, error_description]
  //   if ($http_code != 200) {
  //     //echo $http_code;
  //     if ($xml = @simplexml_load_string($response)) {
  //       return array('error' => $xml->result, 'error_description' => $xml->resultMessage);
  //     }

  //     $response = json_decode($response, true);
  //     return $response;
  //   }

  //   curl_close($curl);
  //   // good response is in xml
  //   if ($xml = @simplexml_load_string($response)) {
  //     $response = array('success' => $xml->result, 'success_description' => $xml->resultMessage);
  //   }
  //   return $response;
  // }

  // public function validateCOD()
  // {
  //   if (!$this->isCod) {
  //     return true;
  //   }

  //   if (empty($this->codBIC)) {
  //     throw new \Exception("Missing COD BIC.", 3101);
  //   }
  //   if (empty($this->codIBAN)) {
  //     throw new \Exception("Missing COD IBAN.", 3101);
  //   }
  //   if (empty($this->codValue)) {
  //     throw new \Exception("Missing COD value.", 3101);
  //   }
  //   if (empty($this->codReference)) {
  //     throw new \Exception("Missing COD reference.", 3101);
  //   }

  //   return true;
  // }

  // public function getXML()
  // {
  //   //check if total items limit
  //   if ($this->totalItems > self::MULTIPARCEL_LIMIT) {
  //     throw new \Exception("MultiParcel support up to " . self::MULTIPARCEL_LIMIT . ", found " . $this->totalItems, 3102);
  //   }
  //   // validate cod fields
  //   $this->validateCOD();

  //   $xml = new SimpleXMLElement('<Postra xmlns="http://api.posti.fi/xml/POSTRA/1" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://api.posti.fi/xml/POSTRA/1 POSTRA.xsd"/>');
  //   // header XML
  //   $header = $xml->addChild('Header');
  //   $header->addChild('SenderId', $this->senderId);
  //   $header->addChild('ReceiverId', $this->receiverId);
  //   $header->addChild('DocumentDateTime', $this->documentDateTime);
  //   $header->addChild('Sequence', $this->sequence);
  //   $header->addChild('MessageCode', 'POSTRA');
  //   $header->addChild('MessageVersion', 1);
  //   $header->addChild('MessageRelease', 1);

  //   if ($this->isTest)
  //     $header->addChild('TestIndicator', 1);

  //   $header->addChild('MessageAction', 'PARCEL_ORDER');

  //   // shipment XML
  //   $shipment = $xml->addChild('Shipments')->addChild('Shipment');
  //   $shipment->addChild('MessageFunctionCode', 'ORIGINAL');
  //   $shipment->addChild('ShipmentNumber', $this->shipmentNumber);
  //   $shipment->addChild('ShipmentDateTime', $this->shipmentDateTime);

  //   // COD XML
  //   if ($this->isCod) {
  //     $shipment->addChild('CodBIC', $this->codBIC);
  //     $shipment->addChild('CodIBAN', $this->codIBAN);
  //     $shipment->addChild('CodValue', $this->codValue)->addAttribute('currencyCode', 'EUR');
  //     $shipment->addChild('CodReference', $this->codReference);
  //   }

  //   // Parties XML
  //   $parties = $shipment->addChild('Parties');
  //   $this->senderParty->getXML($parties);
  //   $this->receiverParty->getXML($parties);

  //   // GoodsItems XML
  //   $goods = $shipment->addChild('GoodsItems');
  //   foreach ($this->goodsItems as $goodsItem) {
  //     $goodsItem->getXML($goods);
  //   }

  //   return $xml;
  // }

  /**
   * Getters
   */
  public function getDocumentDateTime()
  {
    return $this->documentDateTime;
  }

  public function getSequence()
  {
    return $this->sequence;
  }

  /**
   * Finds and returns registered additional service by code. Null if not found.
   * 
   * @return AdditionalService|null
   */
  public function getAdditionalServiceByCode($service_code)
  {
    if (Helper::keyExists($service_code, $this->additionalServices)) {
      return $this->additionalServices[$service_code];
    }

    return null;
  }

  public function getAdditionalServices()
  {
    return $this->additionalServices;
  }

  /**
   * Setters (returns this object for chainability)
   */
  public function setPickupPoint($pickup_point_id)
  {
    $this->pickup_point_id = $pickup_point_id;
    $service = new AdditionalService(
      AdditionalService::PICKUP_POINT,
      array('pickup_point_id' => $pickup_point_id)
    );

    return $this->addAdditionalService($service);
  }

  public function setProductCode($code)
  {
    if (!in_array($code, $this->valid_product_codes)) {
      throw new ItellaException('Unknown product code: ' . $code);
    }
    $this->product_code = $code;
    return $this;
  }

  public function setSenderId($senderId)
  {
    $this->senderId = $senderId;
    return $this;
  }

  public function setReceiverId($receiverId)
  {
    $this->receiverId = $receiverId;
    return $this;
  }

  public function setDocumentDateTime($documentDateTime)
  {
    $this->documentDateTime = $documentDateTime;
    return $this;
  }

  public function setSequence($sequence)
  {
    $this->sequence = $sequence;
    return $this;
  }

  public function setIsTest($isTest)
  {
    $this->isTest = $isTest;
    return $this;
  }

  public function setShipmentNumber($shipmentNumber)
  {
    $this->shipmentNumber = $shipmentNumber;
    return $this;
  }

  public function setShipmentDateTime($shipmentDateTime)
  {
    $this->shipmentDateTime = $shipmentDateTime;
    return $this;
  }

  public function setSenderParty(Party $senderParty)
  {
    $this->senderParty = $senderParty;
    return $this;
  }

  public function setReceiverParty(Party $receiverParty)
  {
    $this->receiverParty = $receiverParty;
    return $this;
  }

  /**
   * @param Mijora\Itella\Shipment\AdditionalService $service
   */
  public function addAdditionalService($service)
  {
    $this->validateProductCode();

    if (!is_object($service) || Helper::get_class_name($service) != 'AdditionalService') {
      throw new ItellaException('addAdditionalService accepts only AdditionalService.');
    }
    // Check this additional service code can be set for chosen product code
    if (!$service->validateCodeByProduct($this->product_code)) {
      throw new ItellaException('Product code: ' . $this->product_code . ' doesn not support additional service code ' . $service->getCode());
    }

    // if there already is additional service with that code overwrite it
    $this->additionalServices[$service->getCode()] = $service;

    return $this;
  }

  public function addAdditionalServices($services)
  {
    if (!is_array($services)) {
      throw new ItellaException('addAdditionalServices accepts array of AdditionalService only');
    }

    foreach ($services as $service) {
      $this->addAdditionalService($service);
    }
    return $this;
  }

  public function addGoodsItem($goodsItem)
  {
    if (!is_object($goodsItem) || Helper::get_class_name($goodsItem) != 'GoodsItem') {
      throw new ItellaException('addGoodsItem accepts only GoodsItem.');
    }
    $this->goodsItems[] = $goodsItem;
    return $this;
  }

  public function addGoodsItems($goodsItems)
  {
    if (!is_array($goodsItems)) {
      throw new ItellaException('addGoodsItems accepts array of GoodsItem only');
    }

    foreach ($goodsItems as $goodsItem) {
      $this->addGoodsItem($goodsItem);
    }
    return $this;
  }

  // public function setAuth($auth)
  // {
  //   $this->auth = $auth;
  //   return $this;
  // }

  // public function setBIC($BIC)
  // {
  //   $this->codBIC = $BIC;
  //   return $this;
  // }

  // public function setIBAN($IBAN)
  // {
  //   $this->codIBAN = $IBAN;
  //   return $this;
  // }

  // public function setValue($value)
  // {
  //   $this->codValue = $value;
  //   return $this;
  // }

  // public function setReference($reference)
  // {
  //   $this->codReference = $reference;
  //   return $this;
  // }
}
