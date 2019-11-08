<?php

/**
 * SC_REST_API Class
 * 
 * A class for work with SafeCharge REST API.
 * 
 * 2018
 *
 * @author SafeCharge
 */
class SC_REST_API
{ 
    /**
     * Function refund_order
     * Create a refund.
     * 
     * @params array $settings - the GW settings
     * @params array $refund - system last refund data
     * @params array $order_meta_data - additional meta data for the order
     * @params string $currency - used currency
     * @params string $notify_url
     */
    public static function refund_order($settings, $refund, $order_meta_data, $currency, $notify_url)
    {
        $refund_url = '';
        $cpanel_url = '';
        $ref_parameters = array();
        $other_params = array();
        
        $time = date('YmdHis', time());
        
    //    self::create_log($refund, 'Refund data: ');
    //    self::create_log($settings, 'Refund Settings data: ');
        
        try {
            $refund_url = SC_TEST_REFUND_URL;
            $cpanel_url = SC_TEST_CPANEL_URL;

            if($settings['test'] == 'no') {
                $refund_url = SC_LIVE_REFUND_URL;
                $cpanel_url = SC_LIVE_CPANEL_URL;
            }

            // order transaction ID
            $ord_tr_id = $order_meta_data['order_tr_id'];
            if(!$ord_tr_id || empty($ord_tr_id)) {
                return array(
                    'msg' => 'The Order does not have Transaction ID. Refund can not procceed.',
                    'new_order_status' => ''
                );
            }

            $ref_parameters = array(
                'merchantId'            => $settings['merchantId'],
                'merchantSiteId'        => $settings['merchantSiteId'],
                'clientRequestId'       => $time . '_' . $ord_tr_id,
                'clientUniqueId'        => $refund['id'],
                'amount'                => number_format($refund['amount'], 2, '.', ''),
                'currency'              => $currency,
                'relatedTransactionId'  => $ord_tr_id, // GW Transaction ID
                'authCode'              => $order_meta_data['auth_code'],
                'comment'               => $refund['reason'], // optional
                'url'                   => $notify_url,
                'timeStamp'             => $time,
            );

            $checksum = '';
            foreach($ref_parameters as $val) {
                $checksum .= $val;
            }
            
            $checksum = hash(
                $settings['hash_type'],
                $checksum . $settings['secret']
            );

            $other_params = array(
                'urlDetails'    => array('notificationUrl' => $notify_url),
                'webMasterId'   => $refund['webMasterId'],
            );
        }
        catch(Exception $e) {
            return array(
                'msg' => 'Exception ERROR - "' . print_r($e->getMessage()) .'".',
                'new_order_status' => ''
            );
        }
        
        self::create_log($refund_url, 'URL: ');
        self::create_log($ref_parameters, 'refund_parameters: ');
        self::create_log($other_params, 'other_params: ');
        
        $json_arr = self::call_rest_api(
            $refund_url,
            $ref_parameters,
            $checksum,
            $other_params
        );
        
        self::create_log($json_arr, 'Refund Response: ');
        return $json_arr;
    }
    
    /**
     * function void_and_settle_order
     * Settle and Void order via Settle / Void button.
     * 
     * @param array $data - all data for the void is here, pass it directly
     * @param string $action - void or settle
     * @param bool $is_ajax - is call coming via Ajax
     * 
     * TODO we must test the case when we call this method from another, NOT via Ajax
     */
    public static function void_and_settle_order($data, $action, $is_ajax = false)
    {
        self::create_log('', 'void_and_settle_order() - ' . $action . ': ');
        $resp = false;
        $status = 1;
        
        try {
            if($action == 'settle') {
                $url = $data['test'] == 'no' ? SC_LIVE_SETTLE_URL : SC_TEST_SETTLE_URL;
            }
            elseif($action == 'void') {
                $url = $data['test'] == 'no' ? SC_LIVE_VOID_URL : SC_TEST_VOID_URL;
            }
            
            // we get array
            $resp = self::call_rest_api($url, $data, $data['checksum']);
        }
        catch (Exception $e) {
            self::create_log($e->getMessage(), $action . ' order Exception ERROR when call REST API: ');
            
            if($is_ajax) {
                echo json_encode(array('status' => 0, 'data' => $e->getMessage()));
                exit;
            }
            
            return false;
        }
        
        self::create_log($resp, 'SC_REST_API void_and_settle_order() full response: ');
        
        if(
            !$resp || !is_array($resp)
            || @$resp['status'] == 'ERROR'
            || @$resp['transactionStatus'] == 'ERROR'
            || @$resp['transactionStatus'] == 'DECLINED'
        ) {
            $status = 0;
        }
        
        if($is_ajax) {
            echo json_encode(array('status' => $status, 'data' => $resp));
            exit;
        }

        return $resp;
    }

    /**
     * Function call_rest_api
     * Call REST API with cURL post and get response.
     * The URL depends from the case.
     * 
     * @param type $url - API URL
     * @param array $checksum_params - parameters we use for checksum
     * @param string $checksum - the checksum
     * @param array $other_params - other parameters we use
     * 
     * @return mixed
     */
    public static function call_rest_api($url, $checksum_params, $checksum, $other_params = array())
    {
        $resp = false;
        
        $checksum_params['checksum'] = $checksum;
        
        if(!empty($other_params) and is_array($other_params)) {
            $params = array_merge($checksum_params, $other_params);
        }
        else {
            $params = $checksum_params;
        }
        
        // get them only if we pass them empty
        if(isset($params['deviceDetails']) && empty($params['deviceDetails'])) {
            $params['deviceDetails'] = self::get_device_details();
        }
        
        self::create_log($params, 'SC_REST_API, parameters for the REST API call: ');
        
        $json_post = json_encode($params);
    //    self::create_log($json_post, 'params as json: ');
        
        try {
            $header =  array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json_post),
            );
            
            // create cURL post
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_post);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

            $resp = curl_exec($ch);
            curl_close ($ch);
            
            self::create_log($url, 'REST API URL: ');
        //    self::create_log($resp, 'REST API response: ');
        }
        catch(Exception $e) {
            self::create_log($e->getMessage(), 'Exception ERROR when call REST API: ');
            return false;
        }
        
        if($resp === false) {
            return false;
        }

        return json_decode($resp, true);
    }
    
    /**
     * Function get_rest_apms
     * Get REST API APMs by passed data.
     * 
     * @param array $data - session data, or other passed data
     * @param bool $is_ajax - is ajax call, after country changed
     * 
     * @return string - json
     */
    public static function get_rest_apms($data = array(), $is_ajax = false)
    {
        $checksum_params = array();
        $other_params = array();
        $resp_arr = array();
        
        // getSessionToken
        $session_token_data = self::get_session_token($data);
        
        if(
            !isset($session_token_data['sessionToken'])
            || empty($session_token_data['sessionToken'])
            || !is_string($session_token_data['sessionToken'])
        ) {
            self::create_log($session_token_data, 'Session Token is FALSE.');
            
            $resp = array(
                'status' => 0,
                'msg' => 'No Session Token',
                'ses_t_data' => json_encode($session_token_data),
            );
            
            if($is_ajax) {
                echo json_encode($resp);
                exit;
            }
            
            return json_encode($resp);
        }
        
        $session_token = $session_token_data['sessionToken'];
        
        try {
            $checksum_params = array(
                'merchantId'        => $data['merchantId'],
                'merchantSiteId'    => $data['merchantSiteId'],
                'clientRequestId'   => $data['cri2'],
                'timeStamp'         => current(explode('_', $data['cri2'])),
            );

            $other_params = array(
                'sessionToken'      => $session_token,
                'currencyCode'      => $data['currencyCode'],
                'countryCode'       => $data['sc_country'],
                'languageCode'      => $data['languageCode'],
                'type'              => '', // optional
            );
            
            self::create_log('', 'Call REST API to get REST APMs: ');

            $resp_arr = self::call_rest_api(
                $data['test'] == 'yes' ? SC_TEST_REST_PAYMENT_METHODS_URL : SC_LIVE_REST_PAYMENT_METHODS_URL,
                $checksum_params,
                $data['cs2'],
                $other_params
            );
        }
        catch(Exception $e) {
            if($is_ajax) {
                echo json_encode(array('status' => 0, 'data' => print_r($e->getMessage()), true));
                exit;
            }
            
            return false;
        }
        
        if($is_ajax) {
            echo json_encode(array(
                'status' => 1,
                'testEnv' => $data['test'],
                'merchantSiteId' => $data['merchantSiteId'],
                'langCode' => $data['languageCode'],
                'data' => $resp_arr,
            ));
            exit;
        }
        
        return $resp_arr;
    }
    
    /**
     * Function get_user_upos
     * Get users UPOs
     * 
     * @param array $params - array with parameters
     * @param array $data - other parameters not used for the UPOs call
     * @param bool $is_ajax
     */
    public static function get_user_upos($params, $data, $is_ajax = false)
    {
        try {
            if(isset($data['secret'])) {
                $params['merchantSecretKey'] = $data['secret'];
            }
            
            if(isset($data['checksum'])) {
                $checksum = $data['checksum'];
            }
            else {
                $checksum = hash(
                    $data['hash_type'],
                    $params['merchantId'] . $params['merchantSiteId'] . $params['userTokenId']
                        . $params['clientRequestId'] . $params['timeStamp'] . $data['secret']
                );
            }
            
            $upos = self::call_rest_api(
                $data['test'] == 'yes' ? SC_TEST_USER_UPOS_URL : SC_LIVE_USER_UPOS_URL,
                $checksum_params,
                $checksum
            );
        }
        catch (Exception $ex) {
            $this->create_log($ex->getMessage(), 'get_user_upos() Exception: ');
            
            if($is_ajax) {
                echo json_encode(array('status' => 0, 'data' => print_r($ex->getMessage()), true));
                exit;
            }
            
            return false;
        }
        
        if($is_ajax) {
            echo json_encode(array(
                'status' => 1,
                'data' => $upos,
            ));
            exit;
        }
        
        return $upos;
    }

    /**
     * Function process_payment
     * Here are the different payment methods
     * 
     * @param array $data - contains the checksum
     * @param array $sc_variables
     * @param string $order_id
     * @param string $payment_method - apm|d3d
     * 
     * @return array|bool
     */
    public static function process_payment($data, $sc_variables, $order_id, $payment_method)
    {
        $resp = false;
        
        try {
            // common parameters for the methods
            $params = array(
                'merchantId'        => $sc_variables['merchantId'],
                'merchantSiteId'    => $sc_variables['merchantSiteId'],
                'userTokenId'       => $data['email'], // the email of the logged user or user who did the payment
                'clientUniqueId'    => $order_id,
                'clientRequestId'   => $data['client_request_id'],
                'currency'          => $data['currency'],
                'amount'            => (string) $data['total_amount'],
                'amountDetails'     => array(
                    'totalShipping'     => '0.00',
                    'totalHandling'     => $data['handling'], // this is actually shipping
                    'totalDiscount'     => @$data['discount'] ? $data['discount'] : '0.00',
                    'totalTax'          => @$data['total_tax'] ? $data['total_tax'] : '0.00',
                ),
                'items'             => $data['items'],
                'userDetails'       => array(
                    'firstName'         => $data['first_name'],
                    'lastName'          => $data['last_name'],
                    'address'           => $data['address1'],
                    'phone'             => $data['phone1'],
                    'zip'               => $data['zip'],
                    'city'              => $data['city'],
                    'country'           => $data['country'],
                    'state'             => '',
                    'email'             => $data['email'],
                    'county'            => '',
                ),
                'shippingAddress'   => array(
                    'firstName'         => $data['shippingFirstName'],
                    'lastName'          => $data['shippingLastName'],
                    'address'           => $data['shippingAddress'],
                    'cell'              => '',
                    'phone'             => '',
                    'zip'               => $data['shippingZip'],
                    'city'              => $data['shippingCity'],
                    'country'           => $data['shippingCountry'],
                    'state'             => '',
                    'email'             => '',
                    'shippingCounty'    => $data['shippingCountry'],
                ),
                'billingAddress'   => array(
                    'firstName'         => $data['first_name'],
                    'lastName'          => $data['last_name'],
                    'address'           => $data['address1'],
                    'cell'              => '',
                    'phone'             => $data['phone1'],
                    'zip'               => $data['zip'],
                    'city'              => $data['city'],
                    'country'           => $data['country'],
                    'state'             => $data['state'],
                    'email'             => $data['email'],
                    'county'            => '',
                ),
                'urlDetails'        => $data['urlDetails'],
                'timeStamp'         => $data['time_stamp'],
                'checksum'          => $data['checksum'],
                'webMasterId'       => @$data['webMasterId'],
                'deviceDetails'     => self::get_device_details(),
            );

            // set parameters specific for the payment method
            switch ($payment_method) {
                case 'apm':
                    // for D3D we use other token
                    $session_token_data = self::get_session_token($sc_variables);
                    $session_token = @$session_token_data['sessionToken'];
                    
                    self::create_log($session_token_data, 'session_token_data: ');
                    
                    if(!$session_token) {
                        return false;
                    }

                    $params['paymentMethod'] = $sc_variables['APM_data']['payment_method'];
                    
                    // append payment method credentionals
                    if(isset($sc_variables['APM_data']['apm_fields'])) {
                        $params['userAccountDetails'] = $sc_variables['APM_data']['apm_fields'];
                    }
                    
                    $params['sessionToken'] = $session_token;

                    $endpoint_url = $sc_variables['test'] == 'no' ? SC_LIVE_PAYMENT_URL : SC_TEST_PAYMENT_URL;
                    break;

                case 'd3d':
                    // in D3D use the session token from card tokenization
                    if(!isset($sc_variables['lst']) || empty($sc_variables['lst']) || !$sc_variables['lst']) {
                        self::create_log(@$sc_variables['lst'], 'Missing Last Session Token: ');
                        return false;
                    }

                    $params['sessionToken'] = $sc_variables['lst'];
                    $params['isDynamic3D'] = 1;
                    
                    if(isset($sc_variables['APM_data']['apm_fields']['ccTempToken'])) {
                        $params['cardData']['ccTempToken'] = $sc_variables['APM_data']['apm_fields']['ccTempToken'];
                    }
                    elseif(isset($sc_variables['APM_data']['apm_fields']['ccCardNumber'])) {
                        $params['cardData']['ccTempToken'] = $sc_variables['APM_data']['apm_fields']['ccCardNumber'];
                    }
                    
                    if(isset($sc_variables['APM_data']['apm_fields']['CVV'])) {
                        $params['cardData']['CVV'] = $sc_variables['APM_data']['apm_fields']['CVV'];
                    }
                    if(isset($sc_variables['APM_data']['apm_fields']['ccNameOnCard'])) {
                        $params['cardData']['cardHolderName'] = $sc_variables['APM_data']['apm_fields']['ccNameOnCard'];
                    }
                    
                    $endpoint_url = $sc_variables['test'] == 'no' ? SC_LIVE_D3D_URL : SC_TEST_D3D_URL;
                    break;

                // if we can't set $endpoint_url stop here
                default:
                    self::create_log($payment_method, 'Not supported payment method: ');
                    return false;
            }

            $resp = self::call_rest_api(
                $endpoint_url,
                $params,
                $data['checksum']
            );
            
            self::create_log($resp, 'REST API Response when Process Payment: ');
        }
        catch(Exception $e) {
            self::create_log($e->getMessage(), 'Process Payment Exception ERROR: ');
            return false;
        }
        
        if(!$resp || !is_array($resp)) {
            self::create_log($resp, 'Process Payment response: ');
            return false;
        }
        
        return $resp;
    }
    
    /**
     * Function get_session_token
     * Get session tokens for different actions with the REST API.
     * We can call this method with Ajax when need tokenization.
     * 
     * @param array $data
     * @param bool $is_ajax
     * 
     * @return array|bool
     */
    public static function get_session_token($data, $is_ajax = false)
    {
        if(!isset($data['merchantId'], $data['merchantSiteId'])) {
            self::create_log($data, 'Missing mandatory session variables: ');
            return false;
        }
        
        $time = date('YmdHis', time());
        $resp_arr = array();
        
        try {
            $params = array(
                'merchantId'        => $data['merchantId'],
                'merchantSiteId'    => $data['merchantSiteId'],
                'clientRequestId'   => $data['cri1'],
                'timeStamp'         => current(explode('_', $data['cri1'])),
            );

            self::create_log(
                $data['test'] == 'yes' ? SC_TEST_SESSION_TOKEN_URL : SC_LIVE_SESSION_TOKEN_URL,
                'Call REST API for Session Token with URL: '
            );
            self::create_log('', 'Call REST API for Session Token. ');

            $resp_arr = self::call_rest_api(
                $data['test'] == 'yes' ? SC_TEST_SESSION_TOKEN_URL : SC_LIVE_SESSION_TOKEN_URL,
                $params,
                $data['cs1']
            );
        }
        catch(Exception $e) {
            self::create_log($e->getMessage(), 'Getting SessionToken Exception ERROR: ');
            
            if($is_ajax) {
                echo json_encode(array('status' => 0, 'msg' => $e->getMessage()));
                exit;
            }
            
            return false;
        }
        
        if(
            !$resp_arr
            || !is_array($resp_arr)
            || !isset($resp_arr['status'])
            || $resp_arr['status'] != 'SUCCESS'
        ) {
            self::create_log($resp_arr, 'getting getSessionToken error: ');
            
            if($is_ajax) {
                echo json_encode(array('status' => 0));
                exit;
            }
            
            return false;
        }
        
        if($is_ajax) {
            $resp_arr['test'] = @$_SESSION['SC_Variables']['test'];
            echo json_encode(array('status' => 1, 'data' => $resp_arr));
            exit;
        }
        
        return $resp_arr;
    }
    
    /**
     * Function get_device_details
     * Get browser and device based on HTTP_USER_AGENT.
     * The method is based on D3D payment needs.
     * 
     * @return array $device_details
     */
    private static function get_device_details()
    {
        $device_details = array(
            'deviceType'    => 'UNKNOWN', // DESKTOP, SMARTPHONE, TABLET, TV, and UNKNOWN
            'deviceName'    => '',
            'deviceOS'      => '',
            'browser'       => '',
            'ipAddress'     => '',
        );
        
        if(!isset($_SERVER['HTTP_USER_AGENT']) || empty(isset($_SERVER['HTTP_USER_AGENT']))) {
            return $device_details;
        }
        
        $user_agent = strtolower($_SERVER['HTTP_USER_AGENT']);
        
        $device_details['deviceName'] = $_SERVER['HTTP_USER_AGENT'];

        if(defined('SC_DEVICES_TYPES')) {
            $devs_tps = json_decode(SC_DEVICES_TYPES, true);

            if(is_array($devs_tps) && !empty($devs_tps)) {
                foreach ($devs_tps as $d) {
                    if (strstr($user_agent, $d) !== false) {
                        if($d == 'linux' || $d == 'windows') {
                            $device_details['deviceType'] = 'DESKTOP';
                        }
                        else {
                            $device_details['deviceType'] = $d;
                        }

                        break;
                    }
                }
            }
        }

        if(defined('SC_DEVICES')) {
            $devs = json_decode(SC_DEVICES, true);

            if(is_array($devs) && !empty($devs)) {
                foreach ($devs as $d) {
                    if (strstr($user_agent, $d) !== false) {
                        $device_details['deviceOS'] = $d;
                        break;
                    }
                }
            }
        }

        if(defined('SC_BROWSERS')) {
            $brs = json_decode(SC_BROWSERS, true);

            if(is_array($brs) && !empty($brs)) {
                foreach ($brs as $b) {
                    if (strstr($user_agent, $b) !== false) {
                        $device_details['browser'] = $b;
                        break;
                    }
                }
            }
        }

        // get ip
        $ip_address = '';

        if (isset($_SERVER["REMOTE_ADDR"])) {
            $ip_address = $_SERVER["REMOTE_ADDR"];
        }
        elseif (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $ip_address = $_SERVER["HTTP_X_FORWARDED_FOR"];
        }
        elseif (isset($_SERVER["HTTP_CLIENT_IP"])) {
            $ip_address = $_SERVER["HTTP_CLIENT_IP"];
        }

        $device_details['ipAddress'] = (string) $ip_address;
            
        return $device_details;
    }
    
    /**
     * Function return_response
     * Help us to return the expected response, when have $is_ajax option
     * for the method.
     * 
     * @param array $data
     * @param bool $is_ajax
     */
    private static function return_response($data, $is_ajax = false)
    {
        if(!is_array($data)) {
            self::create_log($data, 'The data passed to return_response() is not array: ');
            return false;
        }
        
        if($is_ajax) {
            echo json_encode($data);
            exit;
        }
        
        return $data;
    }
    
    /**
     * Function create_log
     * Create logs. You MUST have defined SC_LOG_FILE_PATH const,
     * holding the full path to the log file.
     * 
     * @param mixed $data
     * @param string $title - title of the printed log
     */
    private static function create_log($data, $title = '')
    {
        if($_REQUEST['save_logs'] == 'yes' || $_SESSION['sc_save_logs']) {
            $d = '';

            if(is_array($data)) {
                if(isset($data['cardData']) && is_array($data['cardData'])) {
                    foreach($data['cardData'] as $k => $v) {
                        $data['cardData'][$k] = md5($v);
                    }
                }
                if(isset($data['userAccountDetails']) && is_array($data['userAccountDetails'])) {
                    foreach($data['userAccountDetails'] as $k => $v) {
                        $data['userAccountDetails'][$k] = md5($v);
                    }
                }
                if(isset($data['paResponse']) && !empty($data['paResponse'])) {
                    $data['paResponse'] = 'a long string';
                }

                $d = print_r($data, true);
            }
            elseif(is_object($data)) {
                $d = print_r($data, true);
            }
            elseif(is_bool($data)) {
                $d = $data ? 'true' : 'false';
            }
            else {
                $d = $data;
            }

            if(!empty($title)) {
                $d = $title . "\r\n" . $d;
            }

            $logs_path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
            
            if(is_dir($logs_path)) {
                try {
                    $time = time();
                    file_put_contents(
                        $logs_path . date('Y-m-d', $time) . '.txt',
                        date('H:i:s') . ': ' . $d . "\r\n", FILE_APPEND
                    );
                }
                catch (Exception $exc) {
                    echo
                        '<script>'
                            .'error.log("Log file was not created, by reason: '.$exc->getMessage().'");'
                        .'</script>';
                }
            }
        }
    }
}
