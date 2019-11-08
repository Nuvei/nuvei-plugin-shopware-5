<?php

/**
 * @author SafeCharge
 * 
 * @year 2019
 */

use Shopware\Bundle\StoreFrontBundle\Struct\Payment;

class Shopware_Controllers_Frontend_SafechargePayment extends Enlight_Controller_Action
{
    private $save_logs		= false;
    private $logs_path		= '';
    private $plugin_dir		= '';
    private $webMasterId	= 'ShopWare ';
    
    // set template here
    public function preDispatch()
    {
        $plugin = $this->get('kernel')->getPlugins()['SwagSafeCharge'];
        $this->get('template')->addTemplateDir($plugin->getPath() . '/Resources/views/');
        
        $this->plugin_dir = dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR;
        $this->logs_path = $this->plugin_dir . 'logs' . DIRECTORY_SEPARATOR;
        
        require $this->plugin_dir . 'sc_config.php'; // load SC config file
    }
    
    /**
     * Function cancelAction
     * Executes when customer is redirected to error url
     */
    public function cancelAction()
    {
        $settings = $this->getPluginSettings();
        
        if($settings['test_mode'] === true) {
            $this->View()->assign(['message' => $this->Request()->getParam('message')]);
        }
        
        $this->createLog($this->Request()->getParams(), 'Order Cancel parameters: ');
    }

    public function payAction()
    {
        $settigns = $this->container->get('shopware.plugin.cached_config_reader')
            ->getByPluginName('SwagSafeCharge', Shopware()->Shop());
        
        $this->save_logs = $settigns['swagSCSaveLogs'];
        
        # Cashier payment
        if($settigns['swagSCApi'] == 'cashier') {
            $params = $this->getOrderData($settings);
            if(!$params) {
                return;
            }
            
            $params['merchant_id']      = $settigns['swagSCMerchantId'];
            $params['merchant_site_id'] = $settigns['swagSCMerchantSiteId'];
            
            $params['discount'] = 0;
            
            $items = $basket['sBasket']['content'];
            $i = $items_total_sum = 0;
            $fmt = numfmt_create($locale, NumberFormatter::DECIMAL);

            // get items
            foreach ( $items as $item ) {
                // remove Vouchers and Discounts from the Items
                if(strpos($item['articlename'], 'Voucher') !== false || $item['amountNumeric'] < 0) {
                    $params['discount'] += abs($item['amountNumeric']);
                    continue;
                }
                
                $i++;

                $params['item_name_'.$i]        = $item['articlename'];
                $params['item_number_'.$i]      = $item['articleID'];
                $params['item_quantity_'.$i]    = $item['quantity'];
                $params['item_amount_'.$i]      = $item['amountNumeric'];

                $items_total_sum = $item['amountNumeric'];
            }

            $params['numberofitems']    = $i;
            
            // last check for correct calculations
            $test_diff = $params['total_amount'] - $params['handling'] - $items_total_sum;
            if($test_diff != 0) {
                if($test_diff < 0 && $params['handling'] + $test_diff >= 0) {
					$params['handling'] += $test_diff;
				}
				else {
					$params['discount'] += $test_diff;
				}
                
                $this->createLog($test_diff, 'Total diff, added to handling: ');
            }
            
            $params['handling']         = number_format($basket['sShippingcosts'], 2, '.', '');
            $params['discount']         = number_format($params['discount'], 2, '.', '');
            
            // be sure there are no array elements in $params !!!
            $params['checksum'] = hash(
                $settigns['swagSCHash']
                ,stripslashes($settigns['swagSCSecret'] . implode('', $params))
            );
            
//            echo '<pre>'.print_r($basket, true).'</pre>';
//            echo '<pre>'.print_r($params, true).'</pre>';
//            die('die');
            
            $params_inputs = '';
            foreach($params as $key => $value) {
                if(!is_array($value)) {
                    $params_inputs .= "<input type='hidden' name='{$key}' value='{$value}' />";
                }
            }
            
            $this->View()->assign(['formInputs' => $params_inputs]);
            $this->View()->assign(['formAction' => $urls['form_cashier']]);
            $this->View()->assign(['cancelUrl'  => $params['back_url']]);
            $this->View()->assign(['paymentApi' => 'cashier']);

            $this->createLog($urls['form_cashier'], 'Endpoint URL: ');
            $this->createLog($params, 'Order Params: ');
        }
        
        # Rest payment
        # On first call just get the APM, create payment on second call
        elseif($settigns['swagSCApi'] == 'rest') {
            $this->View()->assign(['paymentApi' => 'rest']);
            
            // first call - user must select payment method
            if(!$this->Request()->getParam('payment_method_sc')) {
                // get APMs and UPOs
                require $this->plugin_dir . 'SC_CLASS.php';
                
                $user = Shopware()->Modules()->Admin()->sGetUserData();

                $upos = SC_REST_API::get_user_upos(
                    array(
                        'merchantId'        => $settigns['swagSCMerchantId'],
                        'merchantSiteId'    => $settigns['swagSCMerchantSiteId'],
                        'userTokenId'       => $user['additional']['user']['email'],
                        'clientRequestId'   => $this->Request()->getParam('signature'),
                        'timeStamp'         => date('YmdHis', time()),
                    ),
                    array(
                        'hash_type' => $settigns['swagSCHash'],
                        'secret'    => $settigns['swagSCSecret'],
                        'test'      => $settigns['swagSCTestMode'] ? 'yes' : 'no',
                    )
                );
                
                if(!is_array($upos)) {
                    if(is_string($upos)) {
                        $this->createLog($upos, 'APMs result: ');
                    }
                    
                    $upos = false;
                }
                
                $apms = SC_REST_API::get_rest_apms($rest_params);
                
                if(!is_array($apms)) {
                    if(is_string($apms)) {
                        $this->createLog($apms, 'APMs result: ');
                    }
                    
                    $apms = false;
                }
                
                var_dump($apms);
                var_dump($upos);
                
                if(!$apms && !$upos) {
                    $url_parameters = [
                        'signature' => $this->Request()->getParam('signature'),
                        'userid'    => $this->Request()->getParam('userid'),
                        'save_logs' => $settigns['swagSCSaveLogs'] ? 'yes' : 'no',
                    ];
                    $get_parameters = '?' . http_build_query($url_parameters);
                    
                    $this->redirect(
                        $router->assemble(['controller' => 'SafechargePayment', 'action' => 'cancel'])
                        . $get_parameters
                    );
                }
                
                $router = $this->Front()->Router();
                
                $this->View()->assign([
                    'cancelUrl'  => $router->assemble([
                        'controller' => 'checkout',
                        'action' => 'confirm'
                    ])
                ]);
                $this->View()->assign(['apms' => @$apms['paymentMethods']]);
                $this->View()->assign(['upos' => @$upos['paymentMethods']]);
            }
            // second call - collect the data and continue with the payment
            else {
                $params = $this->getOrderData($settings);
                if(!$params) {
                    return;
                }
                
                $settings['merchantId']     = $settigns['swagSCMerchantId'];
                $settings['merchantSiteId'] = $settigns['swagSCMerchantSiteId'];
                
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

                $_SESSION['sc_create_logs'] = $settigns['swagSCSaveLogs'];

                $rest_params = array(
                    'secret_key'        => $settigns['swagSCSecret'],
                    'merchantId'        => $settigns['swagSCMerchantId'],
                    'merchantSiteId'    => $settigns['swagSCMerchantSiteId'],
                    'currencyCode'      => $this->Request()->getParam('currency'),
                    'languageCode'      => strlen($locale) > 2 ? substr($locale, 0, 2) : $locale,
                    'sc_country'        => $basket['sCountry']['countryiso'],
                    'payment_api'       => $settigns['swagSCApi'],
                    'transaction_type'  => $settigns['swagSCTransactionType'],
                    'test'              => $settigns['swagSCTestMode'] ? 'yes' : 'no',
                    'hash_type'         => $settigns['swagSCHash'],
                    'force_http'        => $settigns['swagSCUseHttp'] ? 'yes' : 'no',
                    'create_logs'       => $settigns['swagSCSaveLogs'],
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
    }
    
    /**
     * Function getOrderData()
     * Help function for payAction(), because we will no need order data every time.
     * We will want it when use Cashier and on second call of payAction() for the
     * REST API.
     * 
     * @param array $settings - plugin settings
     * @return array $params - order parameters
     */
    private function getOrderData($settings)
    {
        try {
            $persister = $this->get('basket_persister');
            $basket = $persister->load($this->Request()->getParam('signature'));
            
        //    $this->createLog($basket, 'The Basket: ');
            
            if(!$basket) {
                $this->createLog('The Basket is empty!');
                $this->forward('cancel');
            }
            
            $router = $this->Front()->Router();
            $locale = $this->get('shopware_storefront.context_service')
                ->getShopContext()->getShop()->getLocale()->getLocale();
            $user = Shopware()->Modules()->Admin()->sGetUserData();
            $this->webMasterId .= $this->container->getParameter('shopware.release.version');
        }
        catch (Exception $e) {
            $this->createLog($e->getMessage(), 'Basket problem: ');
            $this->forward('cancel');
            return false;
        }

        $TimeStamp = date('Ymdhis');
        $urls = $this->getURLs($settigns);
        
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
        
        $url_parameters = [
            'signature' => $this->Request()->getParam('signature'),
            'userid'    => $this->Request()->getParam('userid'),
            'save_logs' => $settigns['swagSCSaveLogs'] ? 'yes' : 'no',
        ];
        $get_parameters = '?' . http_build_query($url_parameters);
        
        $params['success_url']  = $router->assemble(['controller' => 'SafeCharge', 'action' => 'success']) . $get_parameters;
		$params['pending_url']  = $params['success_url'];
		$params['error_url']    = $router->assemble(['controller' => 'SafechargePayment', 'action' => 'cancel']) . $get_parameters;
		$params['back_url']     = $router->assemble(['controller' => 'checkout', 'action' => 'confirm']);
		$params['notify_url']   = $router->assemble(['controller' => 'SafeCharge', 'action' => 'getDMN'])
            . '?save_logs=' . $url_parameters['save_logs'];
        
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
        $params['user_token_id']    = $params['email'];
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
        $settigns = $this->container->get('shopware.plugin.cached_config_reader')
            ->getByPluginName('SwagSafeCharge', Shopware()->Shop());
        
        return [
            'hash' => $settigns['swagSCHash'],
            'secret' => $settigns['swagSCSecret'],
            'test_mode' => $settigns['swagSCTestMode'],
        ];
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
                'session_token'     => SC_TEST_SESSION_TOKEN_URL,
                'merch_paym_meth'   => SC_TEST_REST_PAYMENT_METHODS_URL,
                'form_cashier'      => SC_TEST_CASHIER_URL,
                'form_rest'         => SC_TEST_PAYMENT_URL,
            ];
		}
            
        return [
            'session_token'     => SC_LIVE_SESSION_TOKEN_URL,
            'merch_paym_meth'   => SC_LIVE_REST_PAYMENT_METHODS_URL,
            'form_cashier'      => SC_LIVE_CASHIER_URL,
            'form_rest'         => SC_LIVE_PAYMENT_URL,
        ];
	}
    
    private function createLog($data, $title = '')
    {
        if(
            @$this->save_logs === true || $this->save_logs == 'yes'
            || $this->Request()->getParam('save_logs') == 'yes'
        ) {
            $d = '';

            if(is_array($data)) {
                foreach($data as $k => $dd) {
                    if(is_array($dd)) {
                        if(isset($dd['cardData'], $dd['cardData']['CVV'])) {
                            $data[$k]['cardData']['CVV'] = md5($dd['cardData']['CVV']);
                        }
                        if(isset($dd['cardData'], $dd['cardData']['cardHolderName'])) {
                            $data[$k]['cardData']['cardHolderName'] = md5($dd['cardData']['cardHolderName']);
                        }
                    }
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

            if($this->logs_path && is_dir($this->logs_path)) {
                try {
                    $time = time();
                    file_put_contents(
                        $this->logs_path . date('Y-m-d', $time) . '.txt',
                        date('H:i:s') . ': ' . $d . "\r\n", FILE_APPEND
                    );
                }
                catch (Exception $exc) {
                    echo $exc->getMessage();
                    die;
                }
            }
        }
    }
}
