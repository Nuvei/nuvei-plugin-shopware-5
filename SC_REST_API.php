<?php

namespace SwagNuvei;

use SwagNuvei\Config;
use SwagNuvei\Logger;

/**
 * A class for work with Nuvei REST API.
 * 
 * @author Nuvei
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
     * 
     * @deprecated
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
     * 
     * @deprecated
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
	 * Call REST API with cURL post and get response.
	 * The URL depends from the case.
	 *
	 * @param string $method            API endpoint.
	 * @param array $params             The parameters.
	 * @param array $checsum_params     The parameters for the checksum.
	 * @param array $plugin_settings    Need them for the Loger.
	 *
	 * @return mixed
	 */
	public static function call_rest_api($method, array $params, array $checsum_params, array $plugin_settings)
    {
		if (empty($params) || empty($plugin_settings)) {
			return array(
				'status'    => 'ERROR',
				'message'   => 'Missing request params or plugin settings.'
			);
		}
        
        $concat                 = '';
		$resp                   = false;
		$url                    = self::get_endpoint_base($plugin_settings) . $method . '.do';
        $time                   = gmdate('Ymdhis');
		$request_base_params    = array(
			'merchantId'            => $plugin_settings['swagSCMerchantId'],
			'merchantSiteId'        => $plugin_settings['swagSCMerchantSiteId'],
            'clientUniqueId'        => $time . '_' . uniqid(),
            'timeStamp'             => $time,
            'deviceDetails'         => self::get_device_details(),
//            'sourceApplication'     => '', // TODO
		);
		
		$params = self::validate_parameters($params); // validate parameters
		
		if (isset($params['status']) && 'ERROR' == $params['status']) {
			return $params;
		}
		
		$all_params = array_merge($request_base_params, $params);
		
		// add the checksum
        foreach ($checsum_params as $key) {
            if (isset($all_params[$key])) {
                $concat .= $all_params[$key];
            }
        }
		
		$all_params['checksum'] = hash(
			$plugin_settings['swagSCHash'],
			$concat . $plugin_settings['swagSCSecret']
		);
		// add the checksum END
        
        // remove the help parameter url
        if (isset($all_params['url'])) {
            unset($all_params['url']);
        }
		
		Logger::writeLog(
            $plugin_settings,
			array(
				'URL'       => $url,
				'params'    => $all_params
			),
			'Nuvei Request all params:'
		);
		
		$json_post = json_encode($all_params);
		
		try {
			$header =  array(
				'Content-Type: application/json',
				'Content-Length: ' . strlen($json_post),
			);
			
			if (!function_exists('curl_init')) {
				return array(
					'status' => 'ERROR',
					'message' => 'To use Nuvei Payment gateway you must install CURL module!'
				);
			}
			
			// create cURL post
			$ch = curl_init();

			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_POSTFIELDS, $json_post);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

			$resp = curl_exec($ch);
			curl_close($ch);
			
			if (false === $resp) {
				return array(
					'status' => 'ERROR',
					'message' => 'REST API ERROR: response is false'
				);
			}
			
			$resp_array	= json_decode($resp, true);
			
			Logger::writeLog($plugin_settings, empty($resp_array) ? $resp : $resp_array, 'Nuvei Request response:');

			return $resp_array;
		} catch (Exception $e) {
			return array(
				'status' => 'ERROR',
				'message' => 'Exception ERROR when call REST API: ' . $e->getMessage()
			);
		}
	}

    /**
     * Call REST API with cURL post and get response.
     * The URL depends from the case.
     * 
     * @param type $url                 The Endpoint
     * @param array $checksum_params    Parameters we use for checksum.
     * @param string $checksum          The checksum.
     * @param array $other_params       Other parameters we use.
     * 
     * @return mixed
     */
//    public static function call_rest_api($url, $checksum_params, $checksum, $other_params = array())
//    {
//        $resp = false;
//        
//        $checksum_params['checksum'] = $checksum;
//        
//        if(!empty($other_params) and is_array($other_params)) {
//            $params = array_merge($checksum_params, $other_params);
//        }
//        else {
//            $params = $checksum_params;
//        }
//        
//        // get them only if we pass them empty
//        if(isset($params['deviceDetails']) && empty($params['deviceDetails'])) {
//            $params['deviceDetails'] = self::get_device_details();
//        }
//        
//        self::create_log($params, 'SC_REST_API, parameters for the REST API call: ');
//        
//        $json_post = json_encode($params);
//        self::create_log($json_post, 'params as json: ');
//        
//        try {
//            $header =  array(
//                'Content-Type: application/json',
//                'Content-Length: ' . strlen($json_post),
//            );
//            
//            // create cURL post
//            $ch = curl_init();
//
//            curl_setopt($ch, CURLOPT_URL, $url);
//            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
//            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_post);
//            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
//            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
//            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
//            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
//
//            $resp = curl_exec($ch);
//            curl_close ($ch);
//            
//            self::create_log(
//                [
//                    '$url' => $url,
//                    '$resp' => $resp,
//                ],
//                'REST API response: '
//            );
////            self::create_log($resp, 'REST API response: ');
//        }
//        catch(Exception $e) {
//            self::create_log($e->getMessage(), 'Exception ERROR when call REST API: ');
//            return false;
//        }
//        
//        if($resp === false) {
//            return false;
//        }
//
//        return json_decode($resp, true);
//    }
    
    /**
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
//                $data['test'] == 'yes' ? SC_TEST_SESSION_TOKEN_URL : SC_LIVE_SESSION_TOKEN_URL,
                'Call REST API for Session Token with URL: '
            );
            self::create_log('', 'Call REST API for Session Token. ');

            $resp_arr = self::call_rest_api(
//                $data['test'] == 'yes' ? SC_TEST_SESSION_TOKEN_URL : SC_LIVE_SESSION_TOKEN_URL,
                ($data['test'] == 'yes' ? NUVEI_ENDPOINT_SANDBOX : NUVEI_ENDPOINT_PROD) . 'getSessionToken.do',
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
	 *
	 * Get browser and device based on HTTP_USER_AGENT.
	 * The method is based on D3D payment needs.
	 *
	 * @return array $device_details
	 */
	private static function get_device_details()
    {
		$device_details = array(
			'deviceType'    => 'UNKNOWN', // DESKTOP, SMARTPHONE, TABLET, TV, and UNKNOWN
			'deviceName'    => 'UNKNOWN',
			'deviceOS'      => 'UNKNOWN',
			'browser'       => 'UNKNOWN',
			'ipAddress'     => '0.0.0.0',
		);
		
		if (empty($_SERVER['HTTP_USER_AGENT'])) {
			$device_details['Warning'] = 'User Agent is empty.';
			
			return $device_details;
		}
		
		$user_agent = strtolower(filter_var($_SERVER['HTTP_USER_AGENT'], FILTER_SANITIZE_STRING));
		
		if (empty($user_agent)) {
			$device_details['Warning'] = 'Probably the merchant Server has problems with PHP filter_var function!';
			
			return $device_details;
		}
		
		$device_details['deviceName'] = $user_agent;

		foreach (Config::NUVEI_DEVICES_TYPES_LIST as $d) {
			if (strstr($user_agent, $d) !== false) {
				if (in_array($d, array('linux', 'windows', 'macintosh'), true)) {
					$device_details['deviceType'] = 'DESKTOP';
				} elseif ('mobile' === $d) {
					$device_details['deviceType'] = 'SMARTPHONE';
				} elseif ('tablet' === $d) {
					$device_details['deviceType'] = 'TABLET';
				} else {
					$device_details['deviceType'] = 'TV';
				}

				break;
			}
		}

		foreach (Config::NUVEI_DEVICES_LIST as $d) {
			if (strstr($user_agent, $d) !== false) {
				$device_details['deviceOS'] = $d;
				break;
			}
		}

		foreach (Config::NUVEI_BROWSERS_LIST as $b) {
			if (strstr($user_agent, $b) !== false) {
				$device_details['browser'] = $b;
				break;
			}
		}

		// get ip
		if (!empty($_SERVER['REMOTE_ADDR'])) {
			$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP);
		}
		if (empty($ip_address) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$ip_address = filter_var($_SERVER['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP);
		}
		if (empty($ip_address) && !empty($_SERVER['HTTP_CLIENT_IP'])) {
			$ip_address = filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP);
		}
		if (!empty($ip_address)) {
			$device_details['ipAddress'] = (string) $ip_address;
		} else {
			$device_details['Warning'] = array(
				'REMOTE_ADDR'			=> empty($_SERVER['REMOTE_ADDR'])
					? '' : filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP),
				'HTTP_X_FORWARDED_FOR'	=> empty($_SERVER['HTTP_X_FORWARDED_FOR'])
					? '' : filter_var($_SERVER['HTTP_X_FORWARDED_FOR'], FILTER_VALIDATE_IP),
				'HTTP_CLIENT_IP'		=> empty($_SERVER['HTTP_CLIENT_IP'])
					? '' : filter_var($_SERVER['HTTP_CLIENT_IP'], FILTER_VALIDATE_IP),
			);
		}
		
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
        //if($_REQUEST['save_logs'] == 'yes' || $_SESSION['sc_save_logs']) {
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
        //}
    }
    
    /**
	 * Get the request endpoint - sandbox or production.
	 * 
     * @param array $plugin_settings The plugin settings.
	 * @return string
	 */
	private static function get_endpoint_base($plugin_settings)
    {
		if ($plugin_settings['swagSCTestMode']) {
			return Config::NUVEI_ENDPOINT_SANDBOX;
		}
		
		return Config::NUVEI_ENDPOINT_PROD;
	}
    
    /**
	 * Validate some of the parameters in the request by predefined criteria.
	 * 
	 * @param array $params
	 * @return array
	 */
	private static function validate_parameters($params)
    {
		// directly check the mails
		if (isset($params['billingAddress']['email'])) {
			if (!filter_var($params['billingAddress']['email'], Config::NUVEI_PARAMS_VALIDATION_EMAIL['flag'])) {
				
				return array(
					'status' => 'ERROR',
					'message' => 'REST API ERROR: The parameter Billing Address Email is not valid.'
				);
			}
			
			if (strlen($params['billingAddress']['email']) > Config::NUVEI_PARAMS_VALIDATION_EMAIL['length']) {
				return array(
					'status' => 'ERROR',
					'message' => 'REST API ERROR: The parameter Billing Address Email must be maximum '
						. Config::NUVEI_PARAMS_VALIDATION_EMAIL['length'] . ' symbols.'
				);
			}
		}
		
		if (isset($params['shippingAddress']['email'])) {
			if (!filter_var($params['shippingAddress']['email'], Config::NUVEI_PARAMS_VALIDATION_EMAIL['flag'])) {
				return array(
					'status' => 'ERROR',
					'message' => 'REST API ERROR: The parameter Shipping Address Email is not valid.'
				);
			}
			
			if (strlen($params['shippingAddress']['email']) > Config::NUVEI_PARAMS_VALIDATION_EMAIL['length']) {
				return array(
					'status' => 'ERROR',
					'message' => 'REST API ERROR: The parameter Shipping Address Email must be maximum '
						. Config::NUVEI_PARAMS_VALIDATION_EMAIL['length'] . ' symbols.'
				);
			}
		}
		// directly check the mails END
		
		foreach ($params as $key1 => $val1) {
			if (!is_array($val1) && !empty($val1) && array_key_exists($key1, Config::NUVEI_PARAMS_VALIDATION)) {
				$new_val = $val1;
				
				if (mb_strlen($val1) > Config::NUVEI_PARAMS_VALIDATION[$key1]['length']) {
					$new_val = mb_substr($val1, 0, Config::NUVEI_PARAMS_VALIDATION[$key1]['length']);
				}
				
				$params[$key1] = filter_var($new_val, Config::NUVEI_PARAMS_VALIDATION[$key1]['flag']);
				
				if (!$params[$key1]) {
					$params[$key1] = 'The value is not valid.';
				}
			} elseif (is_array($val1) && !empty($val1)) {
				foreach ($val1 as $key2 => $val2) {
					if (!is_array($val2) && !empty($val2) && array_key_exists($key2, Config::NUVEI_PARAMS_VALIDATION)) {
						$new_val = $val2;

						if (mb_strlen($val2) > Config::NUVEI_PARAMS_VALIDATION[$key2]['length']) {
							$new_val = mb_substr($val2, 0, Config::NUVEI_PARAMS_VALIDATION[$key2]['length']);
						}

						$params[$key1][$key2] = filter_var($new_val, Config::NUVEI_PARAMS_VALIDATION[$key2]['flag']);
						
						if (!$params[$key1][$key2]) {
							$params[$key1][$key2] = 'The value is not valid.';
						}
					}
				}
			}
		}
		
		return $params;
	}
}
