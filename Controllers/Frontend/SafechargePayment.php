<?php

/**
 * @author SafeCharge
 * 
 * @year 2019
 */

use Shopware\Bundle\StoreFrontBundle\Struct\Payment;

class Shopware_Controllers_Frontend_SafechargePayment extends Enlight_Controller_Action
{
    private $logs_path		= '';
    private $plugin_dir		= '';
    private $webMasterId	= 'ShopWare ';
    
    // set template here
    public function preDispatch()
    {
        $plugin = $this->get('kernel')->getPlugins()['SwagSafeCharge'];
        $this->get('template')->addTemplateDir($plugin->getPath() . '/Resources/views/');
        
        $this->plugin_dir	= dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR;
        $this->logs_path	= $this->plugin_dir . 'logs' . DIRECTORY_SEPARATOR;
        
        require $this->plugin_dir . 'sc_config.php'; // load SC config file
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
        
        $this->createLog($this->Request()->getParams(), 'Order Cancel parameters: ');
    }

    public function payAction()
    {
		require $this->plugin_dir . 'SC_CLASS.php';
		
		// first call - user must select payment method
		if(!$this->Request()->getParam('payment_method_sc')) {
			try {
				$persister	= $this->get('basket_persister');
				$basket		= $persister->load($this->Request()->getParam('signature'));

				if(!$basket) {
					$this->createLog('The Basket is empty!');
					$this->forward('cancel');
				}

				$settings	= $this->container->get('shopware.plugin.cached_config_reader')
					->getByPluginName('SwagSafeCharge', Shopware()->Shop());

				$router		= $this->Front()->Router();
				$locale		= $this->get('shopware_storefront.context_service')
					->getShopContext()->getShop()->getLocale()->getLocale();
				$user		= Shopware()->Modules()->Admin()->sGetUserData();

				$this->webMasterId .= $this->container->getParameter('shopware.release.version');
			}
			catch (Exception $e) {
				$this->createLog($e->getMessage(), 'Basket problem: ');
				$this->forward('cancel');
				return false;
			}

			$_SESSION['sc_create_logs']	= $settings['swagSCSaveLogs'] ? 'yes' : 'no';
			$time = date('YmdHis', time());

			$url_parameters = [
				'signature'			=> $this->Request()->getParam('signature'),
				'userid'			=> $this->Request()->getParam('userid'),
				'sc_create_logs'	=> $settings['swagSCSaveLogs'] ? 'yes' : 'no',
			];

			$get_parameters = '?' . http_build_query($url_parameters);

			$success_url	= $router->assemble(['controller' => 'SafeCharge', 'action' => 'success']) . $get_parameters;
			$error_url		= $router->assemble(['controller' => 'SafechargePayment', 'action' => 'cancel']) . $get_parameters;
			$back_url		= $router->assemble(['controller' => 'checkout', 'action' => 'confirm']);
			$notify_url		= $router->assemble(['controller' => 'SafeCharge', 'action' => 'getDMN'])
				. '?sc_create_logs=' . $settings['swagSCSaveLogs'];

			$total_amount	= number_format($basket['sAmount'], 2, '.', '');

			# Open Order
			$oo_endpoint_url = true === $settings['swagSCTestMode'] ? SC_TEST_OPEN_ORDER_URL : SC_LIVE_OPEN_ORDER_URL;

			$oo_params = array(
				'merchantId'        => $settings['swagSCMerchantId'],
				'merchantSiteId'    => $settings['swagSCMerchantSiteId'],
				'clientRequestId'   => $time . '_' . uniqid(),
	//			'clientUniqueId'	=> $url_parameters['signature'],
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
				'paymentOption'		=> ['card' => ['threeD' => ['isDynamic3D' => 1]]]
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
				$this->createLog($resp, 'Error with the Open order: ');
				$this->forward('cancel');
				return false;
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
				$this->createLog($apms, 'There are no APMs: ');
				$this->forward('cancel');
				return false;

	//			$this->redirect(
	//				$router->assemble(['controller' => 'SafechargePayment', 'action' => 'cancel'])
	//				. $get_parameters
	//			);
			}



			$this->View()->assign([
				'cancelUrl'  => $router->assemble([
					'controller' => 'checkout',
					'action' => 'confirm'
				])
			]);
			$this->View()->assign(['apms' => @$apms['paymentMethods']]);
		}
		// second call - collect the data and continue with the payment
		else {
			$params = $this->getOrderData($settings);
			if(!$params) {
				return;
			}

			$settings['merchantId']     = $settings['swagSCMerchantId'];
			$settings['merchantSiteId'] = $settings['swagSCMerchantSiteId'];

			$params['items[0][name]']       = $TimeStamp;
			$params['items[0][price]']      = $params['total_amount'];
			$params['items[0][quantity]']   = 1;
			$params['numberofitems']        = 1;
			$params['payment_method']       = $this->Request()->getParam('payment_method_sc');
			$params['discount']             = '0.00';

//                $params_inputs = '';
//                foreach($params as $key => $value) {
//                    if(!is_array($value)) {
//                        $params_inputs .= "<input type='hidden' name='{$key}' value='{$value}' />";
//                    }
//                }

			$_SESSION['sc_create_logs'] = $settings['swagSCSaveLogs'];

			$rest_params = array(
				'secret_key'        => $settings['swagSCSecret'],
				'merchantId'        => $settings['swagSCMerchantId'],
				'merchantSiteId'    => $settings['swagSCMerchantSiteId'],
				'currencyCode'      => $this->Request()->getParam('currency'),
				'languageCode'      => strlen($locale) > 2 ? substr($locale, 0, 2) : $locale,
				'sc_country'        => $basket['sCountry']['countryiso'],
				'payment_api'       => $settings['swagSCApi'],
				'transaction_type'  => $settings['swagSCTransactionType'],
				'test'              => $settings['swagSCTestMode'] ? 'yes' : 'no',
				'hash_type'         => $settings['swagSCHash'],
				'force_http'        => $settings['swagSCUseHttp'] ? 'yes' : 'no',
				'create_logs'       => $settings['swagSCSaveLogs'],
			);

			// client request id 1
			$time = date('YmdHis', time());
			$rest_params['cri1'] = $time. '_' .uniqid();

			// checksum 1 - checksum for session token
			$rest_params['cs1'] = hash(
				$rest_params['hash_type'],
				$rest_params['merchantId'] . $rest_params['merchantSiteId']
					. $rest_params['cri1'] . $time . $rest_params['secret_key']
			);

			// client request id 2
			$time = date('YmdHis', time());
			$rest_params['cri2'] = $time. '_' .uniqid();

			// checksum 2 - checksum for get apms
			$time = date('YmdHis', time());
			$rest_params['cs2'] = hash(
				$rest_params['hash_type'],
				$rest_params['merchantId'] . $rest_params['merchantSiteId']
					. $rest_params['cri2'] . $time . $rest_params['secret_key']
			);

			// TODO - call REST API and redirect !
			die('die');
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
        $params['handling']         = '0.00';
        $params['total_tax']        = '0.00';
        
        if($basket['sAmount'] < 0) {
            $params['total_amount']     = number_format(0, 2, '.', '');
        }
        else {
            $params['total_amount']     = number_format($basket['sAmount'], 2, '.', '');
        }
        
		$params['time_stamp']       = $TimeStamp;
		$params['encoding']         = 'utf-8';
		$params['version']          = '4.0.0';
        
        
        
        
        
        // here we still do not have saved order, so we can not use order id
		$params['invoice_id']           = $url_parameters['signature'] . '_' . $TimeStamp;
		$params['merchant_unique_id']   = $url_parameters['signature'];
        
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
            urlencode(preg_replace("/[[:punct:]]/", '', $user['additional']['country']['countryname']));
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
