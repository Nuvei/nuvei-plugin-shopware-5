<?php

/**
 * SC_CLASS Class
 * 
 * @year 2019
 * @author SafeCharge
 */
class SC_CLASS
{
    /**
     * Function call_rest_api
     * Call REST API with cURL post and get response.
     * The URL depends from the case.
     * 
     * @param type $url - API URL
     * @param array $params - parameters
     * 
     * @return mixed
     */
    public static function call_rest_api($url, $params)
    {
        $resp = false;
        
        // get them only if we pass them empty
        if(isset($params['deviceDetails']) && empty($params['deviceDetails'])) {
            $params['deviceDetails'] = self::get_device_details();
        }
        
		self::create_log($url, 'REST API URL:');
        self::create_log($params, 'SC_REST_API, parameters for the REST API call:');
        
        $json_post = json_encode($params);
        
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
        }
        catch(Exception $e) {
            self::create_log($e->getMessage(), 'Exception ERROR when call REST API: ');
            return false;
        }
        
        if($resp === false) {
            return false;
        }
        
        $resp_arr = json_decode($resp, true);
        self::create_log($resp_arr, 'REST API response: ');

        return $resp_arr;
    }
    
    /**
     * Function get_device_details
     * Get browser and device based on HTTP_USER_AGENT.
     * The method is based on D3D payment needs.
     * 
     * @return array $device_details
     */
    public static function get_device_details()
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
     * Function create_log
     * 
     * @param mixed $data
     * @param string $title - title for the printed log
     */
    public static function create_log($data, $title = '')
    {
        $logs_path = dirname(dirname(dirname(dirname(__FILE__)))) . DIRECTORY_SEPARATOR
			. 'var' . DIRECTORY_SEPARATOR . 'log';
		
		if(is_dir($logs_path)) {
			if(
				@$_REQUEST['sc_create_logs'] == 'yes' || @$_REQUEST['sc_create_logs'] == 1
				|| @$_SESSION['sc_create_logs'] == 'yes' || @$_SESSION['sc_create_logs'] == 1
			) {
				// same for all plugins
				$d = $data;

				if(is_array($data)) {
					if(isset($data['userAccountDetails']) && is_array($data['userAccountDetails'])) {
						foreach($data['userAccountDetails'] as $k => $v) {
							$data['userAccountDetails'][$k] = 'a string';
						}
					}
					if(isset($data['userPaymentOption']) && is_array($data['userPaymentOption'])) {
						foreach($data['userPaymentOption'] as $k => $v) {
							$data['userPaymentOption'][$k] = 'a string';
						}
					}

					if(!empty($data['paymentMethods'])) {
						$data['paymentMethods'] = json_encode($data['paymentMethods']);
					}

					$d = print_r($data, true);
				}
				elseif(is_object($data)) {
					$d = print_r($data, true);
				}
				elseif(is_bool($data)) {
					$d = $data ? 'true' : 'false';
				}

				if(!empty($title)) {
					$d = $title . "\r\n" . $d;
				}

				$d .= "\r\n";
				// same for all plugins

				try {
					if(!file_put_contents(
						$logs_path . DIRECTORY_SEPARATOR . 'SafeCharge-' . date('Y-m-d', time()) . '.txt',
						date('H:i:s', time()) . ': ' . $d, FILE_APPEND
					)) {

					}
				}
				catch (Exception $exc) {}
			}
		}
    }

}
