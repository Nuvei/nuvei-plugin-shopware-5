<?php

namespace SwagNuvei;

use SwagNuvei\Config;
use SwagNuvei\Logger;

/**
 * A class for work with Nuvei REST API.
 * 
 * @author Nuvei
 */
class Nuvei
{
    private static $plugin_settings;
    
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
        
        self::$plugin_settings = $plugin_settings;
        
        $concat                 = '';
		$resp                   = false;
		$url                    = self::get_endpoint_base() . $method . '.do';
        $time                   = gmdate('Ymdhis');
		$request_base_params    = array(
			'merchantId'            => $plugin_settings['swagSCMerchantId'],
			'merchantSiteId'        => $plugin_settings['swagSCMerchantSiteId'],
            'clientUniqueId'        => $time . '_' . uniqid(),
            'timeStamp'             => $time,
            'deviceDetails'         => self::get_device_details(),
            'sourceApplication'     => 'Shopwre_Plugin',
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

			$resp       = curl_exec($ch);
            $resp_array = json_decode($resp, true);
            
            Logger::writeLog(
                $plugin_settings,
                array(
                    'Request URL'       => $url,
                    'Request header'    => $header,
                    'Request params'    => $all_params,
                    'Response params'   => is_array($resp_array) ? $resp_array : $resp,
                    'Response info'     => curl_getinfo($ch),
                ),
                'Nuvei Requests'
            );
            
			curl_close($ch);
			
			if (false === $resp) {
				return array(
					'status' => 'ERROR',
					'message' => 'REST API ERROR: response is false'
				);
			}
			
			return $resp_array;
		} catch (Exception $e) {
			return array(
				'status' => 'ERROR',
				'message' => 'Exception ERROR when call REST API: ' . $e->getMessage()
			);
		}
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
	 * Get the request endpoint - sandbox or production.
	 * 
     * @param array $plugin_settings The plugin settings.
	 * @return string
	 */
	private static function get_endpoint_base()
    {
		if (self::$plugin_settings['swagSCTestMode']) {
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
