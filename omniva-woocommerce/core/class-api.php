<?php
class OmnivaLt_Api
{
  private $omnivalt_settings;
  private $omnivalt_configs;

  public function __construct()
  {
    $this->omnivalt_configs = OmnivaLt_Core::get_configs();
    $this->omnivalt_settings = get_option($this->omnivalt_configs['settings_key']);
  }

  public function get_tracking_number($id_order)
  {
    $order = get_post($id_order);
    $terminal_id = get_post_meta($id_order, $this->omnivalt_configs['meta_keys']['terminal_id'], true);
    $wc_order = wc_get_order((int) $id_order);
    $client = $this->get_client_data($wc_order);
    $shop = $this->get_shop_data();
  
    $weight = $this->get_order_weight($id_order);
    
    if ( ! isset($this->omnivalt_configs['shipping_params'][$client->country]) ) {
      return array('msg' => __('Shipping parameters for customer country not found', 'omnivalt'));
    }
    $shipping_params = $this->omnivalt_configs['shipping_params'][$client->country];

    $send_method = $this->get_send_method($wc_order);
    $pickup_method = $this->omnivalt_settings['send_off'];
    $is_cod = OmnivaLt_Helper::check_service_cod($id_order);

    $service = OmnivaLt_Helper::get_shipping_service_code($shop->country, $client->country, $pickup_method . ' ' . $send_method);
    if ( isset($service['status']) && $service['status'] === 'error' ) {
      return array('msg' => $service['msg']);
    }

    $other_services = OmnivaLt_Helper::get_order_services($wc_order);
    $additional_services = '';

    $client_fullname = $client->name . ' ' . $client->surname;
    if ( empty(preg_replace('/\s+/', '', $client_fullname)) ) {
      $client_fullname = $client->company;
    }

    $client_mobiles = '';
    $client_emails = '';
    $sender_mobiles = '';
    $sender_emails = '';

    foreach ( $this->omnivalt_configs['additional_services'] as $service_key => $service_values ) {
      $add_service = (in_array($service_key, $other_services)) ? true : false;
      if ( ! $add_service && $service_values['add_always'] ) {
        $add_service = true;
      }
      if ( is_array($service_values['only_for']) && ! in_array($service, $service_values['only_for']) ) {
        $add_service = false;
      }

      if ( $add_service ) {
        $additional_services .= '<option code="' . $service_values['code'] . '" />';
        if ( ! empty($service_values['required_fields']) ) {
          foreach ( $service_values['required_fields'] as $req_field ) {
            if ( $req_field === 'receiver_phone' && ! empty($client->phone) ) {
              $client_mobiles = $this->get_required_field('mobile', $client->phone, $client_mobiles);
            }
            if ( $req_field === 'receiver_email' && ! empty($client->email) ) {
              $client_emails = $this->get_required_field('email', $client->email, $client_emails);
            }
            if ( $req_field === 'sender_phone' && ! empty($shop->phone) ) {
              $sender_mobiles = $this->get_required_field('mobile', $shop->phone, $sender_mobiles);
            }
            if ( $req_field === 'sender_email' && ! empty($shop->email) ) {
              $sender_emails = $this->get_required_field('email', $shop->email, $sender_emails);
            }
          }
        }
      }
    }
    if ( $additional_services ) {
      $additional_services = '<add_service>' . $additional_services . '</add_service>';
    }

    $parcel_terminal = "";
    if ( $send_method == "pt" || $send_method == "po" ) {
      $parcel_terminal = 'offloadPostcode="' . $terminal_id . '" ';
    }

    $send_return_code = $this->get_return_code_sending();
    $return_code_sms = (! $send_return_code->sms) ? '<show_return_code_sms>false</show_return_code_sms>' : '';
    $return_code_email = (! $send_return_code->email) ? '<show_return_code_email>false</show_return_code_email>' : '';

    $client_address = '<address postcode="' . $client->postcode . '" ' . $parcel_terminal . ' deliverypoint="' . $client->city . '" country="' . $client->country . '" street="' . $client->address_1 . '" />';

    $label_comment = '';
    if ( ! empty($this->omnivalt_settings['label_note']) ) {
      $prepare_comment = esc_html($this->omnivalt_settings['label_note']);
      foreach ( $this->omnivalt_configs['text_variables'] as $key => $title ) {
        $value = '';
        
        if ( $key === 'order_id' ) $value = $wc_order->get_id();
        if ( $key === 'order_number' ) $value = $wc_order->get_order_number();
        
        $prepare_comment = str_replace('{' . $key . '}', $value, $prepare_comment);
      }
      $label_comment = '<comment>' . $prepare_comment . '</comment>';
    }

    $sender_phone = '';
    if ( ! empty($shop->phone) ) {
        $sender_phone = '<phone>' . $shop->phone . '</phone>';
    }

    $xmlRequest = $this->xml_header();
    $xmlRequest .= '<item service="' . $service . '" >
      ' . $additional_services . '
      <measures weight="' . $weight . '" />
      ' . $this->cod($order, $is_cod, get_post_meta($id_order, '_order_total', true)) . '
      ' . $label_comment . $return_code_sms . $return_code_email . '
      <receiverAddressee>
        <person_name>' . $client_fullname . '</person_name>
        ' . $client_mobiles . $client_emails . $client_address . '
      </receiverAddressee>
      <returnAddressee>
        <person_name>' . $shop->name . '</person_name>
        ' . $sender_phone . $sender_mobiles . $sender_emails . '
        <address postcode="' . $shop->postcode . '" deliverypoint="' . $shop->city . '" country="' . $shop->country . '" street="' . $shop->street . '" />
      </returnAddressee>
    </item>';
    $xmlRequest .= $this->xml_footer();

    return $this->api_request($xmlRequest);
  }

  public function get_shipment_labels($barcodes, $order_id = 0)
  {
    $errors = array();
    $barcodeXML = '';
    foreach ( $barcodes as $barcode ) {
      $barcodeXML .= '<barcode>' . $barcode . '</barcode>';
    }

    $xmlRequest = '
    <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
      xmlns:xsd="http://service.core.epmx.application.eestipost.ee/xsd">
       <soapenv:Header/>
       <soapenv:Body>
          <xsd:addrcardMsgRequest>
             <partner>' . $this->clean($this->omnivalt_settings['api_user']) . '</partner>
             <sendAddressCardTo>response</sendAddressCardTo>
             <barcodes>
                ' . $barcodeXML . '
             </barcodes>
          </xsd:addrcardMsgRequest>
       </soapenv:Body>
    </soapenv:Envelope>';

    OmnivaLt_Debug::debug_request($xmlRequest);
    try {
      $url = $this->clean(preg_replace('{/$}', '', $this->omnivalt_settings['api_url'])) . '/epmx/services/messagesService.wsdl';
      $headers = array(
        "Content-type: text/xml;charset=\"utf-8\"",
        "Accept: text/xml",
        "Cache-Control: no-cache",
        "Pragma: no-cache",
        "Content-length: " . strlen($xmlRequest),
      );
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $url);
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_HEADER, 0);
      curl_setopt($ch, CURLOPT_USERPWD, $this->clean($this->omnivalt_settings['api_user']) . ":" . $this->clean($this->omnivalt_settings['api_pass']));
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlRequest);
      curl_setopt($ch, CURLOPT_TIMEOUT, 30);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      $xmlResponse = curl_exec($ch);
      OmnivaLt_Debug::debug_response($xmlResponse);
    } catch (Exception $e) {
      $errors[] = $e->getMessage() . ' ' . $e->getCode();
      $xmlResponse = '';
    }

    $xml = $this->makeReadableXmlResponse($xmlResponse);
    if ( ! is_object($xml) ) {
      $errors[] = $this->get_xml_error_from_response($xmlResponse);
    }

    $shippingLabelContent = '';
    if ( is_object($xml) && is_object($xml->Body->addrcardMsgResponse->successAddressCards->addressCardData->barcode) ) {
      $shippingLabelContent = (string) $xml->Body->addrcardMsgResponse->successAddressCards->addressCardData->fileData;
    } else {
      $errors[] = 'No label received from webservice';
    }

    if ( empty($barcodes) && empty($errors) ) {
      $errors[] = __('No saved barcodes received', 'omnivalt');
    }

    if ( ! empty($barcodes) && empty($errors) ) {
      return array(
        'status' => true,
        'file' => $shippingLabelContent,
      );
    }

    return array(
      'status' => false,
      'msg' => implode('. ', $errors)
    );
  }

  public function call_courier($parcels_number = 0)
  {
    $is_cod = false;
    $parcel_terminal = "";
    $shop = $this->get_shop_data();
    $pickStart = OmnivaLt_Helper::get_formated_time($shop->pick_from, '8:00');
    $pickFinish = OmnivaLt_Helper::get_formated_time($shop->pick_until, '17:00');
    $parcels_number = ($parcels_number > 0) ? $parcels_number : 1;

    $service = OmnivaLt_Helper::get_shipping_service_code($shop->api_country, 'call', 'courier_call');
    if ( isset($service['status']) && $service['status'] === 'error' ) {
      return array('status' => false, 'msg' => $service['msg']);
    }

    $xmlRequest = $this->xml_header();
    for ( $i = 0; $i < $parcels_number; $i++ ) {
      $xmlRequest .= '
        <item service="' . $service . '" >
          <measures weight="1" />
          <receiverAddressee>
            <person_name>' . $shop->name . '</person_name>
            <!--Optional:-->
            <phone>' . $shop->phone . '</phone>
            <address postcode="' . $shop->postcode . '" deliverypoint="' . $shop->city . '" country="' . $shop->country . '" street="' . $shop->street . '" />
          </receiverAddressee>
          <!--Optional:-->
          <returnAddressee>
            <person_name>' . $shop->name . '</person_name>
            <!--Optional:-->
            <phone>' . $shop->phone . '</phone>
            <address postcode="' . $shop->postcode . '" deliverypoint="' . $shop->city . '" country="' . $shop->country . '" street="' . $shop->street . '" />
          </returnAddressee>
          <onloadAddressee>
            <person_name>' . $shop->name . '</person_name>
            <!--Optional:-->
            <phone>' . $shop->phone . '</phone>
            <address postcode="' . $shop->postcode . '" deliverypoint="' . $shop->city . '" country="' . $shop->country . '" street="' . $shop->street . '" />
            <pick_up_time start="' . date("c", strtotime($shop->pick_day . ' ' . $pickStart)) . '" finish="' . date("c", strtotime($shop->pick_day . ' ' . $pickFinish)) . '"/>
          </onloadAddressee>
        </item>';
    }
    $xmlRequest .= $this->xml_footer();

    return $this->api_request($xmlRequest);
  }

  private function xml_header()
  {
    return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsd="http://service.core.epmx.application.eestipost.ee/xsd">
        <soapenv:Header/>
        <soapenv:Body>
          <xsd:businessToClientMsgRequest>
            <partner>' . $this->clean($this->omnivalt_settings['api_user']) . '</partner>
            <interchange msg_type="info11">
              <header file_id="' . current_time('YmdHms') . '" sender_cd="' . $this->clean($this->omnivalt_settings['api_user']) . '" >                
              </header>
              <item_list>';
  }

  private function xml_footer()
  {
    return '  </item_list>
            </interchange>
          </xsd:businessToClientMsgRequest>
        </soapenv:Body>
      </soapenv:Envelope>';
  }

  private function get_shipping_service($shipping_params, $pickup_method, $send_method)
  {
    $method = $pickup_method . ' ' . $send_method;
    $matches = $shipping_params['services'];

    return ( isset($matches[$method]) ) ? $matches[$method] : '';
  }

  private function get_required_field($type, $value, $current_text = false) {
    $add_text = '';
    $value = trim($value);
    
    if ( $type === 'mobile' ) {
      $phone = preg_replace("/[^0-9\+]/", "", $value);
      $add_text = '<mobile>' . $phone . '</mobile>';
    }
    if ( $type === 'email' ) {
      $add_text = '<email>' . $value . '</email>';
    }

    if ( $add_text === '' || $current_text === false ) {
      return $add_text;
    }

    if ( strpos($current_text, $add_text) === false ) {
      return $current_text . $add_text;
    }

    return $current_text;
  }

  private function cod($order, $cod = 0, $amount = 0)
  {
    $company = $this->omnivalt_settings['company'];
    $bank_account = $this->omnivalt_settings['bank_account'];
    if ( $cod ) {
      return '<monetary_values>
        <cod_receiver>' . $company . '</cod_receiver>
        <values code="item_value" amount="' . $amount . '"/>
      </monetary_values>
      <account>' . $bank_account . '</account>
      <reference_number>' . $this->getReferenceNumber($order->ID) . '</reference_number>';
    }
    
    return '';
  }

  private function get_shop_data($object = true)
  {
    $data = array(
      'name' => $this->clean($this->omnivalt_settings['shop_name']),
      'street' => $this->clean($this->omnivalt_settings['shop_address']),
      'city' => $this->clean($this->omnivalt_settings['shop_city']),
      'country' => $this->clean($this->omnivalt_settings['shop_countrycode']),
      'postcode' => $this->clean($this->omnivalt_settings['shop_postcode']),
      'phone' => $this->clean($this->omnivalt_settings['shop_phone']),
      'email' => (! empty($this->omnivalt_settings['shop_email'])) ? $this->clean($this->omnivalt_settings['shop_email']) : get_bloginfo('admin_email'),
      'pick_day' => current_time('Y-m-d'),
      'pick_from' => $this->omnivalt_settings['pick_up_start'] ? $this->clean($this->omnivalt_settings['pick_up_start']) : '8:00',
      'pick_until' => $this->omnivalt_settings['pick_up_end'] ? $this->clean($this->omnivalt_settings['pick_up_end']) : '17:00',
      'api_country' => $this->clean($this->omnivalt_settings['api_country']),
    );
    if ( current_time('timestamp') > strtotime($data['pick_day'] . ' ' . $data['pick_until']) ) {
      $data['pick_day'] = date('Y-m-d', strtotime($data['pick_day'] . "+1 days"));
    }

    return ($object) ? (object) $data : $data;
  }

  private function get_client_data($order, $object = true)
  {
    $data = array(
      'name' => $this->clean($order->get_shipping_first_name()),
      'surname' => $this->clean($order->get_shipping_last_name()),
      'company' => $this->clean($order->get_shipping_company()),
      'address_1' => $this->clean($order->get_shipping_address_1()),
      'postcode' => $this->clean($order->get_shipping_postcode()),
      'city' => $this->clean($order->get_shipping_city()),
      'country' => $this->clean($order->get_shipping_country()),
      'email' => $this->clean($order->get_billing_email()),
      'phone' => get_post_meta($order->get_id(), '_shipping_phone', true),
    );

    if ( empty($data['postcode']) && empty($data['city']) && empty($data['address_1']) && empty($data['country']) ) {
      $data['postcode'] = $this->clean($order->get_billing_postcode());
      $data['city'] = $this->clean($order->get_billing_city());
      $data['address_1'] = $this->clean($order->get_billing_address_1());
      $data['country'] = $this->clean($order->get_billing_country());
    }
    if ( empty($data['name']) && empty($data['surname']) ) {
      $data['name'] = $this->clean($order->get_billing_first_name());
      $data['surname'] = $this->clean($order->get_billing_last_name());
    }
    if ( empty($data['name']) && empty($data['surname']) && empty($data['company']) ) {
      $data['company'] = $this->clean($order->get_billing_company());
    }
    if ( empty($data['country']) ) $data['country'] = $this->clean($this->omnivalt_settings['shop_countrycode']);
    if ( empty($data['country']) ) $data['country'] = 'LT';
    if ( empty($data['phone']) ) $data['phone'] = $this->clean($order->get_billing_phone());
    
    //Fix postcode
    $data['postcode'] = preg_replace("/[^0-9]/", "", $data['postcode']);
    if ($data['country'] == 'LV') {
      $data['postcode'] = 'LV-' . $data['postcode'];
    }

    return ($object) ? (object) $data : $data;
  }

  private function get_return_code_sending()
  {
    $add_to_sms = true;
    $add_to_email = true;
    
    if ( isset($this->omnivalt_settings['send_return_code']) ) {
      switch ($this->omnivalt_settings['send_return_code']) {
        case 'dont':
          $add_to_sms = false;
          $add_to_email = false;
          break;
        case 'sms':
          $add_to_email = false;
          break;
        case 'email':
          $add_to_sms = false;
          break;
      }
    }

    return (object)array(
      'sms' => $add_to_sms,
      'email' => $add_to_email,
    );
  }

  private function get_order_weight($id_order)
  {
    $weight_unit = get_option('woocommerce_weight_unit');
    $weight = get_post_meta($id_order, '_cart_weight', true);
    if ( $weight_unit != 'kg' ) {
      $weight = wc_get_weight($weight, 'kg', $weight_unit);
    }

    return $weight;
  }

  private function get_send_method($order)
  {
    $send_method = '';
    foreach ( $order->get_items('shipping') as $item_id => $shipping_item_obj ) {
      $send_method = $shipping_item_obj->get_method_id();
    }
    if ( $send_method == 'omnivalt' ) {
      $send_method = get_post_meta($order->get_id(), '_omnivalt_method', true);
    }
    if ($send_method == 'omnivalt_pt') $send_method = 'pt'; //TODO: Make dynamicaly
    if ($send_method == 'omnivalt_c') $send_method = 'c';
    if ($send_method == 'omnivalt_cp') $send_method = 'cp';
    if ($send_method == 'omnivalt_pc') $send_method = 'pc';
    if ($send_method == 'omnivalt_po') $send_method = 'po';

    return $send_method;
  }

  private function api_request($request)
  {
    OmnivaLt_Debug::debug_request($request);
    $barcodes = array();
    $errors = array();
    $url = $this->clean(preg_replace('{/$}', '', $this->omnivalt_settings['api_url'])) . '/epmx/services/messagesService.wsdl';
    $headers = array(
      "Content-type: text/xml;charset=\"utf-8\"",
      "Accept: text/xml",
      "Cache-Control: no-cache",
      "Pragma: no-cache",
      "Content-length: " . strlen($request),
    );
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_USERPWD, $this->clean($this->omnivalt_settings['api_user']) . ":" . $this->clean($this->omnivalt_settings['api_pass']));
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $xmlResponse = curl_exec($ch);
    $debug_response = OmnivaLt_Debug::debug_response($xmlResponse);

    if ( $xmlResponse === false ) {
      $errors[] = curl_error($ch);
    } else {
      $errorTitle = '';
      if ( strlen(trim($xmlResponse)) > 0 ) {
        $xml = $this->makeReadableXmlResponse($xmlResponse);
        if ( ! is_object($xml) ) {
          $errors[] = $this->get_xml_error_from_response($xmlResponse);
        }

        if ( is_object($xml) && is_object($xml->Body->businessToClientMsgResponse->faultyPacketInfo->barcodeInfo) ) {
          foreach ($xml->Body->businessToClientMsgResponse->faultyPacketInfo->barcodeInfo as $data) {
            $errors[] = $data->clientItemId . ' - ' . $data->barcode . ' - ' . $data->message;
          }
          if ( is_object($xml->Body->businessToClientMsgResponse->prompt)
            && strpos($xml->Body->businessToClientMsgResponse->prompt, 'AppException:') !== false ) {
            $errors[] = str_replace('AppException: ', '', $xml->Body->businessToClientMsgResponse->prompt);
          }
        }

        if ( empty($errors) ) {
          if ( is_object($xml) && is_object($xml->Body->businessToClientMsgResponse->savedPacketInfo->barcodeInfo) ) {
            foreach ($xml->Body->businessToClientMsgResponse->savedPacketInfo->barcodeInfo as $data) {
              $barcodes[] = (string) $data->barcode;
            }
          }
        }
      }
    }

    if ( ! empty($errors) ) {
      return array(
        'status' => false,
        'msg' => implode('. ', $errors),
        'debug' => $debug_response
      );
    } else {
      if ( ! empty($barcodes) ) return array(
        'status' => true,
        'barcodes' => $barcodes,
        'debug' => $debug_response
      );
      $errors[] = __('No saved barcodes received', 'omnivalt');
      return array(
        'status' => false,
        'msg' => implode('. ', $errors),
        'debug' => $debug_response
      );
    }
  }

  protected static function getReferenceNumber($order_number)
  {
    $order_number = (string) $order_number;
    $kaal = array(7, 3, 1);
    $sl = $st = strlen($order_number);
    $total = 0;
    while ( $sl > 0 and substr($order_number, --$sl, 1) >= '0' ) {
      $total += substr($order_number, ($st - 1) - $sl, 1) * $kaal[($sl % 3)];
    }
    $kontrollnr = ((ceil(($total / 10)) * 10) - $total);
    
    return $order_number . $kontrollnr;
  }

  private function makeReadableXmlResponse($xmlResponse)
  {
    $xmlResponse = str_ireplace(['SOAP-ENV:', 'SOAP:', 'ns3:'], '', $xmlResponse);
    $xml = simplexml_load_string($xmlResponse);

    // Another possible preparation variant
    /*$xmlWithNamespaces = simplexml_load_string($xmlResponse);
    $xml = str_replace(array_map(function($e) { return "$e:"; }, array_keys($xmlWithNamespaces->getNamespaces(true))), array(), $xmlResponse);*/

    return $xml;
  }

  private function get_xml_error_from_response($response)
  {
    if ( strpos($response, 'HTTP Status 401') !== false
      && strpos($response, 'This request requires HTTP authentication.') !== false ) {
      return __('Bad API logins', 'omnivalt');
    }
    
    return __('Response is in the wrong format', 'omnivalt');
  }

  private function clean($string) {
    return str_replace('"',"'",$string);
  }
}
