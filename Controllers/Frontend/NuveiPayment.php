<?php

/**
 * @author Nuvei
 */

use SwagNuvei\Components\Nuvei\PaymentResponse;
use SwagNuvei\Config;
use SwagNuvei\Logger;

class Shopware_Controllers_Frontend_NuveiPayment extends Shopware_Controllers_Frontend_Payment
{
    private $save_logs =    false;
    private $webMasterId =  'ShopWare ';
    private $logs_path =    '';
    private $plugin_dir =   '';
    private $sys_config =   [];
    private $params;
    private $settings;
    
    /**
     * Check if user use Nuvei payment method, if not go to default checkout.
     */
    public function indexAction()
    {
        $method_name    = $this->getPaymentShortName();
        $this->params   = $this->Request()->getParams();
        
        if (!empty($this->params['advanceResponseChecksum'])) {
            return $this->forward('getdmn');
        }
        if (Config::NUVEI_CODE == $method_name) {
            return $this->forward('process');
        }
        
        return $this->redirect(['controller' => 'checkout']);
    }
    
    /**
     * We came here after the checkout, collect all data for the order and continue.
     */
    public function processAction()
    {
        $this->getPluginSettings();
        
        Logger::writeLog($this->settings, 'Nuvei->processAction()');
        
        $locale = $this->get('shopware_storefront.context_service')
            ->getShopContext()->getShop()->getLocale()->getLocale();
        
        $fmt        = numfmt_create($locale, NumberFormatter::DECIMAL);
        $signature  = $this->persistBasket();
        $persister  = $this->get('basket_persister');
        $basket     = $persister->load($signature);
        $router     = $this->Front()->Router();
        $user       = Shopware()->Modules()->Admin()->sGetUserData();
        
        $params['merchant_id']      = $this->settings['swagSCMerchantId'];
        $params['merchant_site_id'] = $this->settings['swagSCMerchantSiteId'];
        $params['discount']         = 0;
        $params['time_stamp']       = date('Ymdhis');
        $params['handling']         = number_format($basket['sShippingcosts'], 2, '.', '');
        $params['total_tax']        = '0.00';
        $params['encoding']         = 'utf-8';
		$params['version']          = '4.0.0';
        $params['user_token']       = "auto";
        
        if($basket['sAmount'] < 0) {
            $params['total_amount'] = number_format(0, 2, '.', '');
        }
        else {
            $params['total_amount'] = number_format($basket['sAmount'], 2, '.', '');
        }
        
        $url_parameters = [
            'signature' => $signature,
            'userid'    => $this->Request()->getParam('userid'),
        ];
        
        $get_parameters = '?' . http_build_query($url_parameters);
        
        $params['success_url']  = $router->assemble(['controller' => 'NuveiPayment', 'action' => 'success']) . $get_parameters;
		$params['pending_url']  = $params['success_url'];
        $params['error_url']    = $router->assemble(['controller' => 'Nuvei', 'action' => 'cancel']) . $get_parameters;
		$params['back_url']     = $router->assemble(['controller' => 'checkout', 'action' => 'confirm']);
		$params['notify_url']   = $router->assemble(['controller' => 'Nuvei', 'action' => 'index']);
        
        // here we still do not have saved order, so we can not use order id
		$params['invoice_id']           = $url_parameters['signature'] . '_' . $params['time_stamp'];
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
        
        $params['currency']         = $this->getCurrencyShortName();
        $params['merchantLocale']   = $locale;
        $params['webMasterId']      = Config::NUVEI_WEB_MASTER_ID
            . $this->container->getParameter('shopware.release.version');
        
        $items  = $basket['sBasket']['content'];
        $i      = $items_total_sum = 0;
        
//        Logger::writeLog($this->settings, $items, '$items');
        
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
            $params['item_amount_'.$i]      = (float) str_replace(',', '.', $item['price']);

            $items_total_sum = $params['item_amount_'.$i] * $item['quantity'];
        }

        Logger::writeLog($this->settings, $items_total_sum, '$items_total_sum');
        
        $params['numberofitems'] = $i;

        // last check for correct calculations
        $test_diff = $params['total_amount'] - $params['handling'] - $items_total_sum;
        
        Logger::writeLog($this->settings, $test_diff, '$test_diff');

        if($test_diff != 0) {
            if($test_diff < 0 && $params['handling'] + $test_diff >= 0) {
                $params['handling'] += $test_diff;
            }
            else {
                $params['discount'] += $test_diff;
            }

            Logger::writeLog($this->settings, $test_diff, 'Total diff, added to handling');
        }

//        $params['handling'] = number_format($basket['sShippingcosts'], 2, '.', '');
        $params['discount'] = number_format($params['discount'], 2, '.', '');

        // be sure there are no array elements in $params !!!
        $params['checksum'] = hash(
            $this->settings['swagSCHash']
            ,stripslashes($this->settings['swagSCSecret'] . implode('', $params))
        );

        $params_inputs = '';
        foreach($params as $key => $value) {
            if(!is_array($value)) {
                $params_inputs .= "<input type='hidden' name='{$key}' value='{$value}' />";
            }
        }
        
        $endpoint = $this->settings['swagSCTestMode'] ? Config::NUVEI_CASHIER_SANDBOX : Config::NUVEI_CASHIER_PROD;
        
        Logger::writeLog($this->settings, [$endpoint, $params]);
        
        header('Location: ' . $endpoint . '?' . http_build_query($params));
        exit;
    }
    
    /**
     * On success when use Cashier customer will come here.
     * Save the order and redirect to default success page.
     */
    public function successAction()
    {
        $this->getPluginSettings();
        
        Logger::writeLog($this->settings, $this->Request()->getParams(), 'successAction');
        
        $this->sys_config           = require 'config.php';
        $response                   = new PaymentResponse();
        $response->transactionId    = $this->Request()->getParam('PPP_TransactionID', null);
        $response->status           = $this->Request()->getParam('ppp_status', null);
        $response->token            = $this->Request()->getParam('advanceResponseChecksum', null);
        
//        $signature  = $this->persistBasket();
        $signature = $this->Request()->getParam('signature');
        
        try {
            $basket = $this->loadBasketFromSignature($signature);
            $this->verifyBasketSignature($signature, $basket);
//            
//            if(!$this->checkAdvRespChecksum()) {
//                Logger::writeLog($this->settings, 'The checkAdvRespChecksum not mutch!');
//                return $this->redirect(['controller' => 'Nuvei', 'action' => 'cancel']);
//            }
        }
        catch (Exception $e) {
            Logger::writeLog($this->settings, $e->getMessage(), 'successAction exception');
            return $this->redirect([
                'controller'    => 'Nuvei', 
                'action'        => 'cancel'
            ])
                . '/?message=' . $e->getMessage();
        }
        
        $send_message = true;
        
        if(isset($this->sys_config['mail']['disabled']) 
            && $this->sys_config['mail']['disabled'] == 1
        ) {
            $send_message = false;
        }

        $this->saveOrder(
            $this->Request()->getParam('TransactionID')
            ,$this->Request()->getParam('advanceResponseChecksum')
            ,Config::SC_PAYMENT_OPEN
            ,$send_message // send mail, if mail server not set it will crash
        );
        
        Logger::writeLog($this->settings, 'Order saved, redirect to checkout/finish');
        
        return $this->redirect([
            'controller'    => 'checkout', 
            'action'        => 'finish'
        ]);
    }
    
    private function add_order_msg($msg)
    {
        
    }
    
    private function add_order_refund($msg)
    {
        
    }

    private function getPluginSettings()
    {
        if(isset($this->settings)) {
            return $this->settings;
        }
        
        $this->settings = $this->container->get('shopware.plugin.cached_config_reader')
            ->getByPluginName('SwagNuvei', Shopware()->Shop());
    }
    
}
