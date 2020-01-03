<?php

/**
 * @author SafeCharge
 * 
 * @year 2019
 */

use Shopware\Bundle\StoreFrontBundle\Struct\Payment;

require_once dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'sc_config.php';
require_once dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'SC_CLASS.php';

class Shopware_Controllers_Frontend_PaymentProvider extends Enlight_Controller_Action
{
    private $plugin_dir		= '';
    private $webMasterId	= 'ShopWare ';
    
    // set template here
    public function preDispatch()
    {
        $plugin = $this->get('kernel')->getPlugins()['SwagSafeCharge'];
        $this->get('template')->addTemplateDir($plugin->getPath() . '/Resources/views/');
        
        $this->plugin_dir	= dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR;
    }
    
    /**
     * Function cancelAction
     * Executes when customer is redirected to error url
     */
    public function cancelAction()
    {
        $settings = $this->container->get('shopware.plugin.cached_config_reader')
			->getByPluginName('SwagSafeCharge', Shopware()->Shop());
        
        if($settings['swagSCTestMode'] === true) {
            $this->View()->assign(['message' => $this->Request()->getParam('message')]);
        }
        
        SC_CLASS::create_log($this->Request()->getParams(), 'Order Cancel parameters: ');
    }

    public function payAction()
    {
		// common variables
		try {
			$persister	= $this->get('basket_persister');
			$basket		= $persister->load($this->Request()->getParam('signature'));
			$router		= $this->Front()->Router();
				
			if(!$basket) {
				SC_CLASS::create_log('The Basket is empty!');
				
				$this->redirect(
					$router->assemble(['controller' => 'PaymentProvider', 'action' => 'cancel'])
				);
			}

			$settings	= $this->container->get('shopware.plugin.cached_config_reader')
				->getByPluginName('SwagSafeCharge', Shopware()->Shop());

			
			$locale		= $this->get('shopware_storefront.context_service')
				->getShopContext()->getShop()->getLocale()->getLocale();
			$user		= Shopware()->Modules()->Admin()->sGetUserData();

			$this->webMasterId .= $this->container->getParameter('shopware.release.version');
		}
		catch (Exception $e) {
			SC_CLASS::create_log($e->getMessage(), 'Basket problem: ');
			
			$this->redirect(
				$router->assemble(['controller' => 'PaymentProvider', 'action' => 'cancel'])
			);
		}
		
		$_SESSION['sc_create_logs']	= $settings['swagSCSaveLogs'] ? 'yes' : 'no';
		
		$time = date('YmdHis', time());
		
		$url_parameters = [
			'signature'			=> $this->Request()->getParam('signature'),
			'userid'			=> $this->Request()->getParam('userid'),
			'sc_create_logs'	=> $settings['swagSCSaveLogs'] ? 'yes' : 'no',
		];
		
		$get_parameters = '?' . http_build_query($url_parameters);
		
		$success_url = $router->assemble([
			'controller'	=> 'PaymentRooter',
			'action'		=> 'success',
			'forceSecure'	=> true
		]) . $get_parameters;
		
		$error_url = $router->assemble([
			'controller'	=> 'PaymentRooter',
			'action'		=> 'cancel',
			'forceSecure'	=> true
		]) . $get_parameters;
		
		$back_url = $router->assemble([
			'controller'	=> 'checkout',
			'action'		=> 'confirm',
			'forceSecure'	=> true
		]);
		
		$notify_url = $router->assemble([
			'controller'	=> 'PaymentRooter',
			'action'		=> 'getDMN',
			'forceSecure'	=> true
		]) . '?sc_create_logs=' . $settings['swagSCSaveLogs'];
		
		if($basket['sAmount'] < 0) {
			$total_amount	= number_format(0, 2, '.', '');
		}
		else {
			$total_amount	= number_format($basket['sAmount'], 2, '.', '');
		}
		
		$this->View()->assign([
//			'apmPaymentURL' => true === $settings['swagSCTestMode'] ? SC_TEST_PAYMENT_URL : SC_LIVE_PAYMENT_URL,
			'apmPaymentURL' => '',
			'successURL'	=> $success_url,
			'webMasterId'	=> $this->webMasterId,
		]);
		
		// first call - user must select payment method
		if(!$this->Request()->getParam('sc_payment_method')) {
			# Open Order
			$oo_endpoint_url = true === $settings['swagSCTestMode'] ? SC_TEST_OPEN_ORDER_URL : SC_LIVE_OPEN_ORDER_URL;

			$oo_params = array(
				'merchantId'        => $settings['swagSCMerchantId'],
				'merchantSiteId'    => $settings['swagSCMerchantSiteId'],
				'clientRequestId'   => $url_parameters['signature'],
				'amount'            => $total_amount,
				'currency'          => $this->Request()->getParam('currency'),
				'timeStamp'         => $time,
				'urlDetails'        => array(
					'successUrl'        => $success_url,
					'failureUrl'        => $error_url,
					'pendingUrl'        => $success_url,
					'backUrl'			=> $back_url,
					'notificationUrl'   => $notify_url,
				),
				'deviceDetails'     => SC_CLASS::get_device_details(),
				'userTokenId'       => $user['additional']['user']['email'],
				'billingAddress'    => array(
					'country' => $user['additional']['country']['countryiso'],
				),
				'webMasterId'       => $this->webMasterId,
				'paymentOption'		=> ['card' => ['threeD' => ['isDynamic3D' => 1]]],
				'transactionType'	=> $settings['swagSCTransactionType'],
			);

			$oo_params['checksum'] = hash(
				$settings['swagSCHash'],
				$oo_params['merchantId'] . $oo_params['merchantSiteId'] . $oo_params['clientRequestId']
					. $oo_params['amount'] . $oo_params['currency'] . $time . $settings['swagSCSecret']
			);

			$resp = SC_CLASS::call_rest_api($oo_endpoint_url, $oo_params);

			if(
				empty($resp['sessionToken'])
				|| empty($resp['status'])
				|| 'SUCCESS' != $resp['status']
			) {
				SC_CLASS::create_log($resp, 'Error with the Open order: ');
				
				$this->redirect(
					$router->assemble(['controller' => 'PaymentProvider', 'action' => 'cancel'])
				);
			}

			$session_token = $resp['sessionToken'];
			# Open Order END

			 # get APMs
			$apms_params = array(
				'merchantId'        => $oo_params['merchantId'],
				'merchantSiteId'    => $oo_params['merchantSiteId'],
				'clientRequestId'   => $time. '_' .uniqid(),
				'timeStamp'         => $time,
			);

			$apms_params['checksum']        = hash($settings['swagSCHash'], implode('', $apms_params) . $settings['swagSCSecret']);

			$apms_params['sessionToken']    = $session_token;
			$apms_params['currencyCode']    = $oo_params['currency'];
			$apms_params['countryCode']     = $user['additional']['country']['countryiso'];
			$apms_params['languageCode']    = strlen($locale) > 2 ? substr($locale, 0, 2) : $locale;

			$endpoint_url = true === $settings['swagSCTestMode']
				? SC_TEST_REST_PAYMENT_METHODS_URL : SC_LIVE_REST_PAYMENT_METHODS_URL;

			$apms = SC_CLASS::call_rest_api($endpoint_url, $apms_params);

			if(empty($apms) or !is_array($apms)) {
				SC_CLASS::create_log($apms, 'There are no APMs: ');

				$this->redirect(
					$router->assemble(['controller' => 'PaymentProvider', 'action' => 'cancel'])
					. $get_parameters
				);
			}

			// set template variables
			$this->View()->assign([
				'cancelUrl'			=> $router->assemble([
					'controller' => 'checkout',
					'action'	 => 'confirm'
				]),
				'apms'				=> @$apms['paymentMethods'],
				'session_token'		=> $session_token,
				'merchantSiteId'	=> $oo_params['merchantSiteId'],
				'merchantId'		=> $oo_params['merchantId'],
				'testMode'			=> $settings['swagSCTestMode'] === true ? 'yes' : 'no',
				'langCode'			=> $apms_params['languageCode'],
				'currency'			=> $oo_params['currency'],
				'amount'			=> $oo_params['amount'],
			]);
		}
		// second call - collect the data and continue with an APM payment
		else {
			$params = array(
                'merchantId'        => $settings['swagSCMerchantId'],
                'merchantSiteId'    => $settings['swagSCMerchantSiteId'],
                'userTokenId'       => $user['additional']['user']['email'],
                'clientRequestId'   => $time . '_' . uniqid(),
                'currency'          => $this->Request()->getParam('currency'),
                'amount'            => (string) $total_amount,
                'amountDetails'     => array(
                    'totalShipping'     => '0.00',
                    'totalHandling'     => '0.00',
                    'totalDiscount'     => '0.00',
                    'totalTax'          => '0.00',
                ),
                'shippingAddress'   => array(
                    'firstName'         => urlencode(preg_replace("/[[:punct:]]/", '', $user['shippingaddress']['firstname'])),
                    'lastName'          => urlencode(preg_replace("/[[:punct:]]/", '', $user['shippingaddress']['lastname'])),
                    'address'           => urlencode(preg_replace("/[[:punct:]]/", '', $user['shippingaddress']['street'])),
                    'zip'               => urlencode(preg_replace("/[[:punct:]]/", '', $user['shippingaddress']['zipcode'])),
                    'city'              => urlencode(preg_replace("/[[:punct:]]/", '', $user['shippingaddress']['city'])),
                    'country'           => urlencode(preg_replace("/[[:punct:]]/", '', $user['additional']['countryShipping']['countryiso'])),
                    'state'             => '',
                    'email'             => '',
                    'phone'             => '',
                    'shippingCounty'    => '',
                    'cell'              => '',
                ),
                'urlDetails'        => array(
                    'successUrl'        => $success_url,
                    'failureUrl'        => $error_url,
                    'pendingUrl'        => $success_url,
                    'notificationUrl'   => $notify_url,
                ),
                'timeStamp'         => $time,
                'webMasterId'       => $this->webMasterId,
                'deviceDetails'     => SC_CLASS::get_device_details(),
                'sessionToken'      => $this->Request()->getParam('session_token'),
            );
            
            $params['userDetails'] = array(
                'firstName'         => urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['firstname'])),
                'lastName'          => urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['lastname'])),
                'address'           => urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['street'])),
                'phone'             => urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['phone'])),
                'zip'               => urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['zipcode'])),
                'city'              => urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['city'])),
                'country'           => urlencode(preg_replace("/[[:punct:]]/", '', $user['additional']['country']['countryiso'])),
                'state'             => isset($user['additional']['state']['name']) ? 
                    urlencode(preg_replace("/[[:punct:]]/", '', $user['additional']['state']['name'])) : '',
                'email'             => $user['additional']['user']['email'],
                'county'            => '',
            );
            
            $params['billingAddress']           = $params['userDetails'];
            $params['billingAddress']['cell']   = '';
            
            $params['items'][0] = array(
                'name'      => $this->Request()->getParam('signature'),
                'price'     => (string) $total_amount,
                'quantity'  => 1,
            );
            
            $params['checksum'] = hash(
                $settings['swagSCHash'],
                $params['merchantId'] . $params['merchantSiteId'] . $params['clientRequestId']
                    . $params['amount'] . $params['currency'] . $time . $settings['swagSCSecret']
            );
            
            $params['paymentMethod']	= $this->Request()->getParam('sc_payment_method');
            $post_sc_payment_fields		= $this->Request()->getParam($params['paymentMethod']);
            
			if (!empty($post_sc_payment_fields)) {
				$params['userAccountDetails'] = $post_sc_payment_fields;
			}
			
			$endpoint_url = true === $settings['swagSCTestMode'] ? SC_TEST_PAYMENT_URL : SC_LIVE_PAYMENT_URL;
		
			$resp = SC_CLASS::call_rest_api($endpoint_url, $params);
			
			// go to error page without message
			if (
				!$resp
				|| 'ERROR' === $resp['status']
				|| ( isset($resp['transactionStatus']) && 'ERROR' === $resp['transactionStatus'] )
			) {
				$this->redirect(
					$router->assemble(['controller' => 'PaymentProvider', 'action' => 'cancel'])
				);
			}
			
			// on declined order set message
			if (isset($resp['transactionStatus']) && 'DECLINED' === $resp['transactionStatus']) {
				$this->View()->assign(['message' => 'Order DECLINED. Please contact the merchant or try another payment method!']);
				
				$this->redirect(
					$router->assemble(['controller' => 'PaymentProvider', 'action' => 'cancel'])
				);
			}
			
			$this->redirect(
				$success_url . '&transactionId=' . @$resp['transactionId']
			);
		}
    }
    
    /**
     * Function getOrderData()
     * Help function for payAction(), because we will no need order data every time.
     * We will want it when use Cashier and on second call of payAction() for the
     * REST API.
     * 
     * @param array $settings - plugin settings
     * @return array $params - order parameters
	 * 
	 * @deprecated since version 1.1
     */
    private function getOrderData($settings)
    {
        // get and pass billing data
		$params['first_name'] =
            urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['firstname']));
		$params['last_name'] =
            urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['lastname']));
		$params['address1'] =
            urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['street']));
		$params['address2'] =
            urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['additionalAddressLine1']));
		$params['zip'] =
            urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['zipcode']));
		$params['city'] =
            urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['city']));
		$params['state'] = isset($user['additional']['state']['name']) ? 
            urlencode(preg_replace("/[[:punct:]]/", '', $user['additional']['state']['name'])) : '';
		$params['country'] =
            urlencode(preg_replace("/[[:punct:]]/", '', $user['additional']['country']['countryiso']));
		$params['phone1'] =
            urlencode(preg_replace("/[[:punct:]]/", '', $user['billingaddress']['phone']));
		
        $params['email']            = $user['additional']['user']['email'];
        // get and pass billing data END
        
        // get and pass shipping data
        $params['shippingFirstName'] = 
            urlencode(preg_replace("/[[:punct:]]/", '', $user['shippingaddress']['firstname']));
        $params['shippingLastName'] = 
            urlencode(preg_replace("/[[:punct:]]/", '', $user['shippingaddress']['lastname']));
        $params['shippingAddress'] = 
            urlencode(preg_replace("/[[:punct:]]/", '', $user['shippingaddress']['street']));
        $params['shippingCity'] = 
            urlencode(preg_replace("/[[:punct:]]/", '', $user['shippingaddress']['city']));
        $params['shippingCountry'] = 
            urlencode(preg_replace("/[[:punct:]]/", '', $user['additional']['countryShipping']['countryname']));
        $params['shippingZip'] = 
            urlencode(preg_replace("/[[:punct:]]/", '', $user['shippingaddress']['zipcode']));
        // get and pass shipping data END
        
        $params['user_token']       = "auto";
        $params['currency']         = $this->Request()->getParam('currency');
        $params['merchantLocale']   = $locale;
        $params['webMasterId']      = $this->webMasterId;
        # end  collecting data
    }
    
    private function getPluginSettings()
    {
        $settings = $this->container->get('shopware.plugin.cached_config_reader')
            ->getByPluginName('SwagSafeCharge', Shopware()->Shop());
        
        return $settings;
    }
    
    private function checkAdvRespChecksum()
    {
        $settings = $this->getPluginSettings();
        
        $str = hash(
            $settings['hash'],
            $settings['secret'] . $this->Request()->getParam('totalAmount')
                . $this->Request()->getParam('currency')
                . $this->Request()->getParam('responseTimeStamp')
                . $this->Request()->getParam('PPP_TransactionID')
                . $this->get_request_status()
                . $this->Request()->getParam('productId')
        );

        if ($str == $this->Request()->getParam('advanceResponseChecksum')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Function getURLs
     * Get a URL we need
     * 
     * @param array $settings
     */
    private function getURLs($settings)
    {
		if ($settings['swagSCTestMode']) {
            return [
                'merch_paym_meth'   => SC_TEST_REST_PAYMENT_METHODS_URL,
                'form_rest'         => SC_TEST_PAYMENT_URL,
                'open_order'        => SC_TEST_OPEN_ORDER_URL,
            ];
		}
            
        return [
            'merch_paym_meth'   => SC_LIVE_REST_PAYMENT_METHODS_URL,
            'form_rest'         => SC_LIVE_PAYMENT_URL,
            'open_order'        => SC_LIVE_OPEN_ORDER_URL,
        ];
	}
}
