<?php
// TODO: To be decided if library should handle this or not as pakettikauppa does not have this functionality at the moment
namespace Mijora\Itella;

use Mijora\Itella\ItellaException;
use Mijora\Itella\Client;
use Mijora\Itella\ItellaPickupsApi;

class CallCourier
{
  private $itella_email;
  private $sender_email;
  private $attachment = false; // attachement
  private $isTest = false;
  private $pickupAddress = array(
    'sender' => '',
    'address_1' => '',
    'postcode' => '',
    'city' => '',
    'country' => '',
    'pickup_time' => '',
    'contact_phone' => '',
  );
  private $pickupParams = array(
    'date' => '',
    'time_from' => '08:00',
    'time_to' => '17:00',
    'info_general' => '',
    'id_sender' => '',
    'id_customer' => '',
    'id_invoice' => '',
  );
  private $subject = 'Call Itella Courier';
  private $items = array();
  private $translates = array(
    'mail_title' => 'Pickup information',
    'mail_sender' => 'Sender',
    'mail_address' => 'Address (pickup from)',
    'mail_phone' => 'Contact Phone',
    'mail_pickup_time' => 'Pickup time',
    'mail_attachment' => 'See attachment for manifest PDF.',
  );

  public function __construct($itella_email = '', $isTest = false)
  {
    $this->itella_email = $itella_email;
    $this->isTest = $isTest;
  }

  public function callCourier($user = '', $pass = '', $show_prefix = false)
  {
    $active_methods = array(
      //'api',
      'postra',
      'email'
    );

    $messages = array(
      'errors' => array(),
      'success' => array()
    );
    
    try {
      foreach ($active_methods as $method) {
        switch ($method) {
          case 'api':
            $result = $this->callApiCourier();
            $prefix = ($show_prefix) ? 'API' : '';
            break;
          case 'postra':
            $result = $this->callPostraCourier($user, $pass);
            $prefix = ($show_prefix) ? 'POSTRA' : '';
            break;
          case 'email':
            $result = $this->callMailCourier();
            $prefix = ($show_prefix) ? 'EMAIL' : '';
            break;
          default:
            continue 2;
        }
        if ($this->getCallError($result)) {
          $messages['errors'][] = $this->getCallError($result, $prefix);
        } else {
          $messages['success'][] = $this->getCallSuccess($result, $prefix);
        }
      }
    } catch (\Exception $e) {
      $prefix = ($show_prefix) ? 'ERROR: ' : '';
      $messages['errors'][] = $prefix . $e->getMessage();
    }
    
    return $messages;
  }

  private function getCallError($result, $prefix = '')
  {
    if (!empty($prefix)) {
      $prefix .= ': ';
    }
    if (!is_array($result)) {
      return $prefix . $result;
    }
    if (!array_key_exists('message', $result)) {
      $result['message'] = 'Unknown error';
    }
    if (!array_key_exists('status', $result)) {
      return $prefix . 'Unknown result status';
    }
    if ($result['status'] != '200') {
      return $prefix . $result['message'];
    }
    return false;
  }

  private function getCallSuccess($result, $prefix = '')
  {
    if (!empty($prefix)) {
      $prefix .= ': ';
    }
    $message = (array_key_exists('message', $result)) ? $result['message'] : 'Success';
    return $prefix . $message;
  }

  /**
   * Sends email using mail() even if successfull does not mean mail will reach recipient
   * 
   * @throws Exception when mail fails to register for sending
   */
  public function callMailCourier()
  {
    // Force PHP to use the UTF-8 charset
    header('Content-Type: text/html; charset=utf-8');

    $uid = md5(uniqid(time()));
    // Define and Base64 encode the subject line
    $subject_text = ($this->isTest ? 'TEST CALL - ' : '') . $this->subject;
    $subject = '=?UTF-8?B?' . base64_encode($subject_text) . '?=';

    $eol = PHP_EOL;

    $headers = '';
    $message = '';
    // Add custom headers
    $headers .= "From: " . $this->sender_email . "$eol";
    $headers .= "MIME-Version: 1.0$eol";
    $headers .= "Content-Type: multipart/mixed; boundary=\"$uid\"$eol";
    $message .= "--" . $uid . "$eol";
    $message .= "Content-Type: text/html; charset=utf-8$eol";
    $message .= "Content-Transfer-Encoding: base64" . $eol . $eol;
    // Base64 the email message
    $message .= rtrim(chunk_split(base64_encode($this->buildMailBody()))) . "$eol";
    if ($this->attachment) {
      $message .= "--" . $uid . "$eol";
      $message .= "Content-Type: application/octet-stream; name=\"manifest.pdf\"$eol";
      $message .= "Content-Transfer-Encoding: base64$eol";
      $message .= "Content-Disposition: attachment; filename=\"manifest.pdf\"" . $eol . $eol;
      $message .= rtrim(chunk_split($this->attachment)) . "$eol";
    } else {
      return array(
        'status' => '500',
        'message' => 'No manifest attached to courier call email'
      );
    }
    $message .= "--" . $uid . "--";
    // Send mail with custom headers
    if (!mail($this->itella_email, $subject, $message, $headers)) {
      return array(
        'status' => '500',
        'message' => 'Failed to send email via mail() function'
      );
    }

    return array(
      'status' => '200',
      'message' => 'The call email has been successfully sent to the courier'
    );
  }

  public function buildMailBody()
  {
    $body = '<h2>' . $this->translates['mail_title'] . '</h2><br/>' . PHP_EOL;
    $body .= '<table>' . PHP_EOL;
    if (!empty($this->pickupAddress['sender'])) {
      $body .= "<tr><td>" . $this->translates['mail_sender'] . ":</td><td>" . $this->pickupAddress['sender'] . "</td></tr>" . PHP_EOL;
    }
    if (!empty($this->pickupAddress['address_1'])) {
      $address = $this->pickupAddress['address_1'];
      $address .= (!empty($this->pickupAddress['postcode'])) ? ', ' . $this->pickupAddress['postcode'] : '';
      $address .= (!empty($this->pickupAddress['city'])) ? ', ' . $this->pickupAddress['city'] : '';
      $address .= (!empty($this->pickupAddress['country'])) ? ', ' . $this->pickupAddress['country'] : '';
      $body .= "<tr><td>" . $this->translates['mail_address'] . ":</td><td>" . $address . "</td></tr>" . PHP_EOL;
    }
    if (!empty($this->pickupAddress['contact_phone'])) {
      $body .= "<tr><td>" . $this->translates['mail_phone'] . ":</td><td>" . $this->pickupAddress['contact_phone'] . "</td></tr>" . PHP_EOL;
    }
    if (!empty($this->pickupAddress['pickup_time'])) {
      $body .= "<tr><td>" . $this->translates['mail_pickup_time'] . ":</td><td>" . $this->pickupAddress['pickup_time'] . "</td></tr>" . PHP_EOL;
    }
    $body .= '</table>' . PHP_EOL;
    if ($this->attachment) {
      $body .= "<br/>" . $this->translates['mail_attachment'] . PHP_EOL;
    }
    return $body;
  }

  public function callApiCourier()
  {
    $ItellaPickupsApi = new ItellaPickupsApi();
    $response = $ItellaPickupsApi->request('pickups', $this->buildApiRequest());

    return $response ? $response : false;
  }

  public function callPostraCourier($user, $pass)
  {
    $url = 'https://connect.posti.fi/transportation/v1/';
    if ($this->isTest) {
      $url = 'https://connect.ja.posti.fi/kasipallo/transportation/v1/';
    }

    $client = new Client($user, $pass, $this->isTest);
    $token = $client->getAccessToken();

    $ItellaPickupsApi = new ItellaPickupsApi($url);
    $ItellaPickupsApi->setToken($token);
    return $ItellaPickupsApi->requestXml('orders', $this->buildPostraXml());
  }

  public function buildApiRequest()
  {
    $data = array();
    
    $data['sender']['name'] = (!empty($this->pickupAddress['sender'])) ? $this->pickupAddress['sender'] : '';
    $data['sender']['email'] = (!empty($this->sender_email)) ? $this->sender_email : '';
    $data['sender']['address_1'] = (!empty($this->pickupAddress['address_1'])) ? $this->pickupAddress['address_1'] : '';
    $data['sender']['postcode'] = (!empty($this->pickupAddress['postcode'])) ? $this->pickupAddress['postcode'] : '';
    $data['sender']['city'] = (!empty($this->pickupAddress['city'])) ? $this->pickupAddress['city'] : '';
    $data['sender']['country'] = (!empty($this->pickupAddress['country'])) ? $this->pickupAddress['country'] : '';
    $data['sender']['phone'] = (!empty($this->pickupAddress['contact_phone'])) ? $this->pickupAddress['contact_phone'] : '';
    $data['pickup_time'] = (!empty($this->pickupAddress['pickup_time'])) ? $this->pickupAddress['pickup_time'] : '';
    $data['items'] = (!empty($this->items)) ? $this->items : array();

    return $data;
  }

  private function buildPostraXml()
  {
    $date = $this->getPickupParamsValue('date', date('Y-m-d', strtotime('+1 day')));

    $xml = new \SimpleXMLElement('<Postra/>');
    $xml->addAttribute('xmlns', 'http://api.posti.fi/xml/POSTRA/1');

    $header = $xml->addChild('Header');
    $header->addChild('SenderId', $this->getPickupParamsValue('id_sender'));
    $header->addChild('ReceiverId', '003715318644');
    $header->addChild('DocumentDateTime', gmdate('c'));
    $header->addChild('Sequence', floor(microtime(true) * 1000));
    $header->addChild('MessageCode', 'POSTRA');
    $header->addChild('MessageVersion', 1);
    $header->addChild('MessageRelease', 2);
    $header->addChild('MessageAction', 'PICKUP_ORDER');

    $shipments = $xml->addChild('Shipments');
    foreach ( $this->items as $shipment_data ) {
      $shipment = $shipments->addChild('Shipment');
      $shipment->addChild('MessageFunctionCode', 'ORIGINAL');
      $shipment->addChild('PickupOrderType', 'PICKUP');
      $shipment->addChild('ShipmentNumber', $shipment_data['track_num']);
      $shipment->addChild('ShipmentDateTime', gmdate('c'));

      $pickup = $shipment->addChild('PickupDate', $date);
      $pickup->addAttribute('timeEarliest', gmdate('H:i:sP', strtotime($date . ' ' . $this->getPickupParamsValue('time_from'))));
      $pickup->addAttribute('timeLatest', gmdate('H:i:sP', strtotime($date . ' ' . $this->getPickupParamsValue('time_to'))));

      $instructions = $shipment->addChild('Instructions');
      $instruction = $instructions->addChild('Instruction', $this->getPickupParamsValue('info_general'));
      $instruction->addAttribute('type', 'GENERAL');

      $parties = $shipment->addChild('Parties');
      $consignor = $parties->addChild('Party');
      $consignor->addAttribute('role', 'CONSIGNOR');
      $consignor->addChild('Name1', $this->getPickupAddressValue('sender'));
      $consignor_location = $consignor->addChild('Location');
      $consignor_location->addChild('Street1', $this->getPickupAddressValue('address_1'));
      $consignor_location->addChild('Postcode', $this->getPickupAddressValue('postcode'));
      $consignor_location->addChild('City', $this->getPickupAddressValue('city'));
      $consignor_location->addChild('Country', $this->getPickupAddressValue('country'));
      $payer = $parties->addChild('Party');
      $payer->addAttribute('role', 'PAYER');
      $account1 = $payer->addChild('Account', $this->getPickupParamsValue('id_customer'));
      $account1->addAttribute('type', 'SAP_CUSTOMER');
      $account2 = $payer->addChild('Account', $this->getPickupParamsValue('id_invoice'));
      $account2->addAttribute('type', 'SAP_INVOICE');
      $payer->addChild('Name1', $this->getPickupAddressValue('sender'));

      $items = $shipment->addChild('GoodsItems');
      $item = $items->addChild('GoodsItem');
      $qty = $item->addChild('PackageQuantity', 1);
      $qty->addAttribute('type', 'CW');
    }

    return $xml->asXML();
  }

  /**
   * $pickup = array(
   *  'sender' => 'Name / Company name',
   *  'address_1' => 'Street st. 1',
   *  'postcode' => '12345',
   *  'city' => 'City name',
   *  'country' => 'LT',
   *  'pickup_time' => '8:00 - 17:00',
   *  'contact_phone' => '+37060000000',
   * );
   */
  public function setPickUpAddress($pickupAddress)
  {
    $this->pickupAddress = array_merge($this->pickupAddress, $pickupAddress);
    if (empty($this->pickupAddress['pickup_time'])) {
      $this->pickupAddress['pickup_time'] = $this->pickupParams['time_from'] . ' - ' . $this->pickupParams['time_to'];
    }
    return $this;
  }

  private function getPickupAddressValue( $value_key, $empty_value = '' )
  {
    if (isset($this->pickupAddress[$value_key])) {
      $value = $this->pickupAddress[$value_key];
      if ($value !== '' && $value !== null && $value !== false) {
        return $value;
      }
    }

    return $empty_value;
  }

  /**
   * $params = array(
   *  'date' => '2001-12-20',
   *  'time_from' => '08:00',
   *  'time_to' => '17:00',
   *  'info_general' => 'Message to courier',
   *  'id_sender' => '123',
   *  'id_customer' => '456',
   *  'id_invoice' => '789',
   * );
   */
  public function setPickUpParams($pickupParams)
  {
    $this->pickupParams = array_merge($this->pickupParams, $pickupParams);
    return $this;
  }

  private function getPickupParamsValue( $value_key, $empty_value = '' )
  {
    if (isset($this->pickupParams[$value_key])) {
      $value = $this->pickupParams[$value_key];
      if ($value !== '' && $value !== null && $value !== false) {
        return $value;
      }
    }

    return $empty_value;
  }

  public function setSenderEmail($sender_email)
  {
    $this->sender_email = $sender_email;
    return $this;
  }

  public function setSubject($subject)
  {
    $this->subject = $subject;
    return $this;
  }

  public function setAttachment($attachment, $isBase64 = false)
  {
    $this->attachment = ($isBase64 ? $attachment : base64_encode($attachment));
    return $this;
  }

  /**
   * $items = array(
   *  array(
   *    'track_num' => '01234567890123456789',
   *    'weight' => '1.0',
   *    'amount' => '1',
   *    'delivery_address' => 'Name / Company name. Street, Postcode City, Country',
   *  ),
   * );
   */
  public function setItems($items = [])
  {
    $this->items = $items;
    return $this;
  }

  public function setTranslates($translates)
  {
    $this->translates = array_merge($this->translates, $translates);
    return $this;
  }
}
