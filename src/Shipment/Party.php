<?php

namespace Mijora\Itella\Shipment;

use Mijora\Itella\SimpleXMLElement;

class Party
{
  // constants for easier role assignment
  const ROLE_SENDER = 'CONSIGNOR';
  const ROLE_RECEIVER = 'CONSIGNEE';

  // CONSIGNOR, CONSIGNEE 
  private $role;

  // depending on role
  public $contract;

  // Name lines
  public $name1;
  public $name2; // if SmartPost, then name of pick-up point is given
  public $name3; // optional

  // Address lines
  public $street1;
  public $street2; // if SmartPost, then street address of pick-up point is given
  public $street3; // optional
  public $postCode; // if SmartPost, then postal code of pick-up point is given
  public $city; // SmartPost, then postal code area name of pick-up point is given ?? not all SmartPost has it
  public $countryCode; // ISO 3166-1 alpha-2 format

  // Contacts
  public $contactName; // optional
  public $contactMobile; // optional
  public $contactPhone; // optional
  public $contactEmail; // optional

  public function __construct($role)
  {
    if ($role != self::ROLE_RECEIVER && $role != self::ROLE_SENDER) {
      return false;
    }

    $this->role = $role;
  }

  /**
   * Main functions
   */
  public function getXML($root = false)
  {
    if (is_subclass_of($root, 'SimpleXMLElement')) {
      $xml = $root->addChild('Party');
    } else {
      $xml = new SimpleXMLElement('<Party/>');
    }

    $xml->addAttribute('role', $this->role);
    if ($this->role == self::ROLE_SENDER) {
      $xml->addChild('Account', $this->contract)->addAttribute('type', 'CONTRACT');
    }

    $xml->addChild('Name1', $this->name1);
    if ($this->name2 !== null)
      $xml->addChild('Name2', $this->name2);
    if ($this->name3 !== null)
      $xml->addChild('Name3', $this->name3);

    $loc = $xml->addChild('Location');

    $loc->addChild('Street1', $this->street1);
    if ($this->street2 !== null)
      $loc->addChild('Street2', $this->street2);
    if ($this->street3 !== null)
      $loc->addChild('Street3', $this->street3);

    $loc->addChild('Postcode', $this->postCode);

    $loc->addChild('City', $this->city);
  
    $loc->addChild('Country', $this->countryCode);
    // Optional Contact information
    if ($this->contactName !== null)
      $xml->addChild('ContactName', $this->contactName);
    if ($this->contactMobile !== null)
      $xml->addChild('ContactChannel', $this->contactMobile)->addAttribute('channel', 'MOBILE');
    if ($this->contactPhone !== null)
      $xml->addChild('ContactChannel', $this->contactPhone)->addAttribute('channel', 'PHONE');
    if ($this->contactEmail !== null)
      $xml->addChild('ContactChannel', $this->contactEmail)->addAttribute('channel', 'EMAIL');

    return $xml;
  }

  /**
   * Setters (returns this object for chainability)
   */
  public function setContract($contract)
  {
    if ($this->role != self::ROLE_SENDER) {
      throw new \Exception("Contract is only for ROLE_SENDER role");
    }
    $this->contract = $contract;
    return $this;
  }

  public function setName1($name1)
  {
    $this->name1 = $name1;
    return $this;
  }

  public function setName2($name2)
  {
    $this->name2 = $name2;
    return $this;
  }

  public function setName3($name3)
  {
    $this->name3 = $name3;
    return $this;
  }

  public function setStreet1($street1)
  {
    $this->street1 = $street1;
    return $this;
  }

  public function setStreet2($street2)
  {
    $this->street2 = $street2;
    return $this;
  }

  public function setStreet3($street3)
  {
    $this->street3 = $street3;
    return $this;
  }

  public function setPostCode($postCode)
  {
    $this->postCode = $postCode;
    return $this;
  }

  public function setCity($city)
  {
    $this->city = $city;
    return $this;
  }

  public function setCountryCode($countryCode)
  {
    $this->countryCode = $countryCode;
    return $this;
  }

  public function setContactName($contactName)
  {
    $this->contactName = $contactName;
    return $this;
  }

  public function setContactMobile($contactMobile)
  {
    $this->contactMobile = $contactMobile;
    return $this;
  }

  public function setContactPhone($contactPhone)
  {
    $this->contactPhone = $contactPhone;
    return $this;
  }

  public function setContactEmail($contactEmail)
  {
    $this->contactEmail = $contactEmail;
    return $this;
  }
}
