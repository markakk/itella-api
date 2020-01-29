<?php

namespace Mijora\Itella\Shipment;

use Mijora\Itella\SimpleXMLElement;

class Shipment
{

  const MULTIPARCEL_LIMIT = 10;

  public $isTest = false;
  // Auth object
  /** @var \Mijora\Itella\Auth */
  public $auth;

  // Main request data
  public $senderId;
  public $receiverId;

  public $documentDateTime;
  public $sequence;

  // Shipment specific
  public $shipmentNumber;
  public $shipmentDateTime; // when shipment is ready for pickup

  // Party objects
  /** @var \Mijora\Itella\Shipment\Party */
  public $senderParty;
  /** @var \Mijora\Itella\Shipment\Party */
  public $receiverParty;

  // GoodsItem object storage
  /** @var \Mijora\Itella\Shipment\GoodsItem[] */
  public $goodsItems = [];
  public $totalItems = 0; // counter for goods with MultiParcel service

  // COD info (should be set if any of the goods item has extra service for COD)
  private $isCod = false; // for simpler check if COD xml is needed to generate
  public $codBIC;
  public $codIBAN;
  public $codValue; // EUR
  public $codReference;

  public function __construct($isTest = false)
  {
    $this->isTest = $isTest;
    $this->documentDateTime = date('c');
    $this->sequence = number_format(microtime(true), 6, '', '');
  }

  /**
   * Main functions
   */
  public function sendShipment()
  {
    // production url
    $url = 'https://connect.posti.fi/transportation/v1/orders';
    // test url
    if ($this->isTest) {
      $url = 'https://connect.ja.posti.fi/kasipallo/transportation/v1/orders';
    }

    // check if authentication still valid and get new one if needed
    if (!$this->auth->isValid()) {
      $this->auth->getAuth();
    }

    $headers = array();
    $headers[] = 'Expect:';
    $headers[] = 'Content-type: text/xml; charset=utf-8';
    $headers[] = 'Authorization: Bearer ' . $this->auth->getToken();
    $post_data = $this->getXML()->asXML();

    $options = array(
      CURLOPT_POST            =>  1,
      CURLOPT_HEADER          =>  0,
      CURLOPT_URL             =>  $url,
      CURLOPT_FRESH_CONNECT   =>  1,
      CURLOPT_RETURNTRANSFER  =>  1,
      CURLOPT_FORBID_REUSE    =>  1,
      CURLOPT_USERAGENT       =>  'ItellaShipping-API/1.0',
      CURLOPT_TIMEOUT         =>  30,
      CURLOPT_HTTPHEADER      =>  $headers,
      CURLOPT_POSTFIELDS      =>  $post_data
    );

    $curl = curl_init();
    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);

    // file_put_contents('transfer.log', '=======================', FILE_APPEND);
    // file_put_contents('transfer.log', $post_data, FILE_APPEND);
    // file_put_contents('transfer.log', '==== Response ====', FILE_APPEND);
    // file_put_contents('transfer.log', $response, FILE_APPEND);
    // file_put_contents('transfer.log', '=======================', FILE_APPEND);

    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    // in case of errors return array with [error, error_description]
    if ($http_code != 200) {
      //echo $http_code;
      if ($xml = @simplexml_load_string($response)) {
        return array('error' => $xml->result, 'error_description' => $xml->resultMessage);
      }

      $response = json_decode($response, true);
      return $response;
    }

    curl_close($curl);
    // good response is in xml
    if ($xml = @simplexml_load_string($response)) {
      $response = array('success' => $xml->result, 'success_description' => $xml->resultMessage);
    }
    return $response;
  }

  public function validateCOD()
  {
    if (!$this->isCod) {
      return true;
    }

    if (empty($this->codBIC)) {
      throw new \Exception("Missing COD BIC.", 3101);
    } 
    if (empty($this->codIBAN)) {
      throw new \Exception("Missing COD IBAN.", 3101);
    } 
    if (empty($this->codValue)) {
      throw new \Exception("Missing COD value.", 3101);
    } 
    if (empty($this->codReference)) {
      throw new \Exception("Missing COD reference.", 3101);
    } 

    return true;
  }

  public function getXML()
  {
    //check if total items limit
    if ($this->totalItems > self::MULTIPARCEL_LIMIT) {
      throw new \Exception("MultiParcel support up to " . self::MULTIPARCEL_LIMIT . ", found " . $this->totalItems, 3102);
    }
    // validate cod fields
    $this->validateCOD();

    $xml = new SimpleXMLElement('<Postra xmlns="http://api.posti.fi/xml/POSTRA/1" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://api.posti.fi/xml/POSTRA/1 POSTRA.xsd"/>');
    // header XML
    $header = $xml->addChild('Header');
    $header->addChild('SenderId', $this->senderId);
    $header->addChild('ReceiverId', $this->receiverId);
    $header->addChild('DocumentDateTime', $this->documentDateTime);
    $header->addChild('Sequence', $this->sequence);
    $header->addChild('MessageCode', 'POSTRA');
    $header->addChild('MessageVersion', 1);
    $header->addChild('MessageRelease', 1);

    if ($this->isTest)
      $header->addChild('TestIndicator', 1);

    $header->addChild('MessageAction', 'PARCEL_ORDER');

    // shipment XML
    $shipment = $xml->addChild('Shipments')->addChild('Shipment');
    $shipment->addChild('MessageFunctionCode', 'ORIGINAL');
    $shipment->addChild('ShipmentNumber', $this->shipmentNumber);
    $shipment->addChild('ShipmentDateTime', $this->shipmentDateTime);

    // COD XML
    if ($this->isCod) {
      $shipment->addChild('CodBIC', $this->codBIC);
      $shipment->addChild('CodIBAN', $this->codIBAN);
      $shipment->addChild('CodValue', $this->codValue)->addAttribute('currencyCode', 'EUR');
      $shipment->addChild('CodReference', $this->codReference);
    }

    // Parties XML
    $parties = $shipment->addChild('Parties');
    $this->senderParty->getXML($parties);
    $this->receiverParty->getXML($parties);

    // GoodsItems XML
    $goods = $shipment->addChild('GoodsItems');
    foreach ($this->goodsItems as $goodsItem) {
      $goodsItem->getXML($goods);
    }

    return $xml;
  }

  /**
   * Generates reference code for COD using supplied ID (usualy order iD). ID must be min. 3 characters long for correct calculation
   * @param int|string $id
   */
  public function gereateCODReference($id)
  {
    $weights = array(7, 3, 1);
    $sum = 0;
    $base = str_split(strval(($id)));
    $reversed_base = array_reverse($base);
    $reversed_base_length = count($reversed_base);
    for ($i = 0; $i < $reversed_base_length; $i++) {
      $sum += $reversed_base[$i] * $weights[$i % 3];
    }
    $checksum = (10 - $sum % 10) % 10;
    return implode('', $base) . $checksum;
  }

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
   * Setters (returns this object for chainability)
   */
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

  public function setSenderParty($senderParty)
  {
    $this->senderParty = $senderParty;
    return $this;
  }

  public function setReceiverParty($receiverParty)
  {
    $this->receiverParty = $receiverParty;
    return $this;
  }

  public function addGoodsItem($goodsItem)
  {
    if (!is_array($goodsItem)) {
      $goodsItem = array($goodsItem);
    }

    foreach ($goodsItem as $item) {
      if ($item->hasExtraService('3102')) {
        $this->totalItems++;
      }
      // check if COD is needed
      if ($item->hasExtraService('3101')) {
        $this->isCod = true;
      }
    }
    $this->goodsItems = array_merge($this->goodsItems, $goodsItem);
    // update package count in all items
    foreach ($this->goodsItems as $registeredItem) {
      if ($this->totalItems > 1 && $registeredItem->hasExtraService('3102')) {
        $registeredItem = $registeredItem->setPackageQuantity($this->totalItems);
      }
    }
    return $this;
  }

  public function setAuth($auth)
  {
    $this->auth = $auth;
    return $this;
  }

  public function setBIC($BIC)
  {
    $this->codBIC = $BIC;
    return $this;
  }

  public function setIBAN($IBAN)
  {
    $this->codIBAN = $IBAN;
    return $this;
  }

  public function setValue($value)
  {
    $this->codValue = $value;
    return $this;
  }

  public function setReference($reference)
  {
    $this->codReference = $reference;
    return $this;
  }
}
