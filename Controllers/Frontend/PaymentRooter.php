<?php

/**
 * @author SafeCharge
 * 
 * @year 2019
 */

use SwagSafeCharge\Components\SafeCharge\PaymentResponse;

require_once dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'sc_config.php';
require_once dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'SC_CLASS.php';

class Shopware_Controllers_Frontend_PaymentRooter extends Shopware_Controllers_Frontend_Payment
{
    // constants for order status, see db_name.s_core_states
    // states
    const SC_ORDER_CANCELLED    = -1;
    const SC_ORDER_IN_PROGRESS  = 1;
    const SC_ORDER_COMPLETED    = 2;
    const SC_ORDER_REJECTED		= 4;
    // payment states
    const SC_ORDER_PAID         = 12;
    const SC_ORDER_OPEN         = 17;
    const SC_PARTIALLY_REFUNDED = 31;
    const SC_COMPLETE_REFUNDED  = 32;
    const SC_PAYMENT_CANCELLED  = 35;

    private $save_logs			= false;
    private $webMasterId		= 'ShopWare ';
    private $sys_config			= [];
    
	public function preDispatch()
    {
        $plugin = $this->get('kernel')->getPlugins()['SwagSafeCharge'];
        $this->get('template')->addTemplateDir($plugin->getPath() . '/Resources/views/');
		
		$this->sys_config = require 'config.php';
    }
	
    /**
     * Function indexAction
     * Check if user use SC payment method, if not go to default checkout
     */
    public function indexAction()
    {
		SC_CLASS::create_log($this->getPaymentShortName(), 'PaymentShortName');
		
        switch ($this->getPaymentShortName()) {
            case 'safecharge_payment':
                return $this->forward('process');
            default:
                return $this->redirect(['controller' => 'checkout']);
        }
    }
    
    /**
     * Function processAction
     * We came here after the checkout, collect all data for the order,
     * set it the session and continue.
     */
    public function processAction()
    {
        $router				= $this->Front()->Router();
		
        // prepare get parameters for the redirect URL
        $url_parameters = [
            'signature' => $this->persistBasket(),
        //    'userid'    => $user['additional']['user']['id'],
            'currency'    => $this->getCurrencyShortName(),
        ];
        $get_parameters = '?' . http_build_query($url_parameters);
        
//        echo '<pre>'.print_r($url_parameters['signature'], true).'</pre>';
//        echo '<pre>'.print_r($this->loadBasketFromSignature($url_parameters['signature']), true).'</pre>';
//        die('die');
        $providerUrl = $router->assemble(['controller' => 'PaymentProvider', 'action' => 'pay']);
        $this->redirect($providerUrl . $get_parameters);
    }
	
	public function returnAction()
	{
		die('returnAction');
	}
    
    /**
     * Function successAction
     * On success when use Cashier customer will come here.
     * Save the order and redirect to default success page.
     */
    public function successAction()
    {
		$params = $this->Request()->getParams();
		SC_CLASS::create_log($params, 'Success page params:');
		
		if(empty($params['signature'])) {
			SC_CLASS::create_log('Success page Error - empty signature!');
			return $this->redirect(['controller' => 'PaymentRooter', 'action' => 'cancel']);
		}
		
		try {
			$basket	= $this->loadBasketFromSignature($params['signature']);
			$this->verifyBasketSignature($params['signature'], $basket);
			
			$response = new PaymentResponse();

			# WebSDK
			if(!empty($params['sc_transaction_id'])) {
				$response->transactionId	= $params['sc_transaction_id'];
				$tr_id						= $params['sc_transaction_id'];
				$response->status			= 'OK';
				$response->token			= md5($params['sc_transaction_id']);
				$payment_unique_id			= $response->token;
			}
			# APMs
			elseif(!empty($params['transactionId'])) {
				$response->transactionId	= $params['transactionId'];
				$tr_id						= $params['transactionId'];
				$response->status			= 'OK';
				$response->token			= md5($params['transactionId']);
				$payment_unique_id			= $response->token;
			}
			else {
				SC_CLASS::create_log('Success page Error - empty Transaction ID!');
				return $this->redirect(['controller' => 'PaymentRooter', 'action' => 'cancel']);
			}
        }
        catch (Exception $e) {
            SC_CLASS::create_log($e->getMessage(), 'successAction exception: ');
            return $this->redirect(['controller' => 'PaymentRooter', 'action' => 'cancel']);
        }

		// this returns order number
        $order_num = $this->saveOrder($tr_id, $payment_unique_id, self::SC_ORDER_IN_PROGRESS);
		
		if(!$order_num) {
			SC_CLASS::create_log('Success page error when try to save the Order.');
            return $this->redirect(['controller' => 'PaymentRooter', 'action' => 'cancel']);
		}
        
        SC_CLASS::create_log('Order saved, redirect to checkout/finish');
        
        return $this->redirect(['controller' => 'checkout', 'action' => 'finish']);
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
    
    /**
     * Function getDMNAction
     * 
     * @param array $params - in case we call this method form inside we will pass the params
     */
    public function getDMNAction($params = array())
    {
		$params = $this->Request()->getParams();
        SC_CLASS::create_log($params, 'DMN Request params: ');
		
		if(empty($params['transactionType'])) {
			SC_CLASS::create_log('DMN Error - missing transactionType data.');
			
			echo 'DMN Error - missing transactionType data.';
            exit;
		}
        
        if(!$this->checkAdvRespChecksum()) {
            SC_CLASS::create_log('DMN report: You receive DMN from not trusted source. The process ends here.');
            
            echo 'You receive DMN from not trusted source. The process ends here.';
            exit;
        }
        
        $req_status = $this->get_request_status($params);
        $connection = $this->container->get('dbal_connection');
        $order_data = [];
		
		// get the order by transactionId
		$tries = 0;
		
		do {
			$tries++;
			
			$order_data = current(
				$connection->fetchAll(
					'SELECT id, ordernumber, status FROM s_order WHERE transactionID = :trID',
					['trID' => $params['TransactionID']]
				)
			);
			
			if(empty($order_data['id'])) {
				sleep(5);
			}
		}
		while($tries <= 10 and empty($order_data['id']));
		
		if(empty($order_data['id'])) {
			SC_CLASS::create_log('The DMN didn\'t wait for the Order creation');
			echo 'The DMN didn\'t wait for the Order creation';
			exit;
		}
		
		SC_CLASS::create_log($order_data, 'DMN: an order found:');
		
        # Sale and Auth
        if(in_array($params['transactionType'], array('Sale', 'Auth'))) {
            SC_CLASS::create_log('DMN: for ' . $params['transactionType']);
            
            try {
                if($order_data['status'] != self::SC_ORDER_COMPLETED) {
					$this->update_sc_field($order_data);
                    $this->change_order_status($order_data);
                }
            }
            catch (Exception $ex) {
                SC_CLASS::create_log($ex->getMessage(), 'DMN Exception: ');
                
                echo 'DMN Exception: ' . $ex->getMessage();
                exit;
            }
            
            echo 'DMN received.';
            exit;
        }
        
        # Refund
        // TODO
        
        # Void, Settle
        // here we have to find the order by its Transaction ID -> relatedTransactionId
        if(
			!empty($_REQUEST['relatedTransactionId'])
            && in_array($_REQUEST['transactionType'], array('Void', 'Settle'))
        ) {
            SC_CLASS::create_log($_REQUEST['transactionType'], 'Void/Settle transactionType: ');
            
            try {
                $order_data = current(
                    $connection->fetchAll(
                        'SELECT id, ordernumber, status FROM s_order WHERE transactionID = :trID',
                        ['trID' => $params['relatedTransactionId']]
                    )
                );
                
                if($_REQUEST['transactionType'] == 'Settle') {
                    $this->update_sc_field($order_data, $params);
                }
                
                $this->change_order_status($order_data, $req_status, $params);
            }
            catch (Exception $ex) {
                $this->create_log($ex->getMessage(), 'getDMNAction() Void/Settle Exception: ');
            }
        }
        
        SC_CLASS::create_log('getDMNAction end.');
        
        echo 'DMN received, but not recognized.';
        exit;
    }
	
//	protected function getProviderUrl()
//    {
//        return $this->Front()->Router()->assemble(['controller' => 'PaymentProvider', 'action' => 'pay']);
//    }
    
    /**
     * 
     * @param array $order_info
     */
    private function change_order_status($order_info)
    {
        $order_module	= Shopware()->Modules()->Order();
		$status			= $this->get_request_status($params);
		$params			= $this->Request()->getParams();
		$message		= '';
        $ord_status		= self::SC_ORDER_IN_PROGRESS;
        $payment_status = self::SC_ORDER_OPEN;
		$send_message	= true;
        
        SC_CLASS::create_log(
            'Order ' . $order_info['id'] .' has Status: ' . $status,
            'Change_order_status(): '
        );
        
        if(@$this->sys_config['mail']['disabled'] == 1) {
            $send_message = false;
        }
        
        switch($status) {
            case 'CANCELED':
                $message = 'Payment status changed to:' . @$params['transactionType']
                    . '. PPP_TransactionID = ' . @$params['PPP_TransactionID']
                    . ", Status = " . $status . ', GW_TransactionID = '
                    . @$params['TransactionID'];

                $ord_status = self::SC_ORDER_REJECTED;
                break;
            
            case 'APPROVED':
                if(@$params['transactionType'] == 'Void') {
                    $message = 'Payment status changed to:' . @$params['transactionType']
						. '. PPP_TransactionID = ' . @$params['PPP_TransactionID']
						. ", Status = " . $status . ', GW_TransactionID = '
						. @$params['TransactionID'];

					$ord_status = self::SC_ORDER_REJECTED;
					break;
                }
                
                // Refund
                if(in_array(@$params['transactionType'], ['Credit', 'Refund'])) {
                    // TODO
                }
                
                $message = 'The amount has been authorized and captured by ' . SC_GATEWAY_TITLE . '. ';
                
                if(@$params['transactionType'] == 'Auth') {
                    $message = 'The amount has been authorized and wait for Settle. ';
                }
                elseif(in_array(@$params['transactionType'], ['Settle', 'Sale'])) {
                    // add one more message
                    $order_module->setOrderStatus(
                        $order_info['id']
                        ,self::SC_ORDER_COMPLETED
                        ,$send_message
                        ,SC_GATEWAY_TITLE . ' payment is successful.<br/>'
							. 'PPP_TransactionID: ' . @$params['PPP_TransactionID'] . ',<br/>'
							. 'TransactionID: ' . @$params['TransactionID']
                    );
                    
                    $order_module->setPaymentStatus(
                        $order_info['id']
                        ,self::SC_ORDER_PAID
                        ,$send_message
                    );
                    
                    $message = 'The amount has been captured by ' . SC_GATEWAY_TITLE . '. ';
                    $ord_status = self::SC_ORDER_COMPLETED;
                    $payment_status = self::SC_ORDER_PAID;
                }
                
                $message .= 'PPP_TransactionID = ' . @$params['PPP_TransactionID'] . ", Status = ". $status;
                
                if($this->Request()->getParam('transactionType')) {
                    $message .= ", TransactionType = ". @$params['transactionType'];
                }
                
                $message .= ', GW_TransactionID = '. @$params['TransactionID'];
                
//                if(@$params['transactionType'] != 'Auth') {
//                    // add one more message
//                    $order_module->setOrderStatus(
//                        $order_info['id']
//                        ,self::SC_ORDER_COMPLETED
//                        ,$send_message
//                        ,SC_GATEWAY_TITLE . ' payment is successful<br/>Unique Id: '
//                            . @$params['PPP_TransactionID']
//                    );
//                    
//                    $order_module->setPaymentStatus(
//                        $order_info['id']
//                        ,self::SC_ORDER_PAID
//                        ,$send_message
//                    );
//                }
                
                break;
                
            case 'ERROR':
            case 'DECLINED':
            case 'FAIL':
                $ord_status     = self::SC_ORDER_CANCELLED;
                $payment_status = self::SC_PAYMENT_CANCELLED;
                
                $reason = ', Reason = ';
                if(@$params['reason']) {
                    $reason .= $params['reason'];
                }
                elseif(@$params['Reason']) {
                    $reason .= $params['Reason'];
                }
                
                $message = 'Payment failed. PPP_TransactionID =  '
                    . @$params['PPP_TransactionID']
                    . ", Status = " . $status . ", Error code = " 
                    . @$params['ErrCode']
                    . ", Message = " . @$params['message'] . $reason;
                
                if(@$params['transactionType']) {
                    $message .= ", TransactionType = " . $params['transactionType'];
                }

                $message .= ', GW_TransactionID = ' . @$params['TransactionID'];
                
                // Void, do not change status
                if(@$params['transactionType'] == 'Void') {
                    // TODO
                }
                
                // Refund
                if(@$params['transactionType'] == 'Credit') {
                    // TODO
                }
                
                break;
            
            case 'PENDING':
                if (
                    $order_info['status'] == self::SC_ORDER_COMPLETED
                    || $order_info['status'] == self::SC_ORDER_IN_PROGRESS
                ) {
                    $ord_status = $order_info['status'];
                    break;
                }
                
                $message = 'Payment is still pending, PPP_TransactionID '
                    . @$params['PPP_TransactionID'] . ", Status = " . $status;

                if(@$params['transactionType']) {
                    $message .= ", TransactionType = " . $params['transactionType'];
                }

                $message .= ', GW_TransactionID = ' . @$params['TransactionID'];
                
                $order_module->setOrderStatus(
                    $order_info['id']
                    ,$ord_status
                    ,$send_message
                    ,SC_GATEWAY_TITLE .' payment status is pending<br/>Unique Id: '
                        . @$params['PPP_TransactionID']
                );
                
                $order_module->setPaymentStatus(
                    $order_info['id']
                    ,$payment_status
                    ,$send_message
                );
                
                break;
        }
        
//        SC_CLASS::create_log(
//            $order_info['id'] . '; ' . $ord_status . '; ' . $payment_status . '; ' . $message
//            ,'$order_id, $ord_status, $payment_status, $message: '
//        );
        
        $order_module->setOrderStatus($order_info['id'], $ord_status, $send_message, $message);
        $order_module->setPaymentStatus($order_info['id'], $payment_status, $send_message);
    }
    
    /**
     * 
     * @param array $order - order data
     */
    private function update_sc_field($order)
    {
        SC_CLASS::create_log($order, 'Order data for update_sc_field: ');
        
        // create connection
        $connection = $this->container->get('dbal_connection');
		$sc_field	= [];
		$oder_id	= null;
        $dmn_params = $this->Request()->getParams();
		
        // get order id
        if(!empty($order['id'])) {
            $q = "SELECT safecharge_order_field FROM s_order_attributes WHERE orderID = " . $order['id'];
            
            $sc_field   = $connection->fetchColumn($q);
            $oder_id    = $order['id'];
        }
        elseif(!empty($order['ordernumber'])) {
            $q =
                "SELECT s_order.id AS id, s_order_attributes.safecharge_order_field AS sc_field "
                . "FROM s_order "
                . "LEFT JOIN s_order_attributes "
                    . "ON s_order.id = s_order_attributes.orderID "
                . "WHERE s_order.ordernumber = " . $order['ordernumber'];
                
            $data		= $connection->fetchAll($q);
            $sc_field   = $data['sc_field'];
            $oder_id    = $data['id'];
        }
        
//        SC_CLASS::create_log($sc_field, 'sc_field in update_sc_field(): ');
//        SC_CLASS::create_log($oder_id, '$oder_id in update_sc_field(): ');
        
        // get SC field
        $sc_field_arr = [];
        if(!empty($sc_field)) {
            $sc_field_arr = json_decode($sc_field, true);
        }
        
        // get incoming data
        if(!empty($dmn_params['AuthCode'])) {
            $sc_field_arr['authCode'] = $dmn_params['AuthCode'];
        }
        if(!empty($dmn_params['TransactionID'])) {
            $sc_field_arr['relatedTransactionId'] = $dmn_params['TransactionID'];
        }
        if(!empty($dmn_params['transactionType'])) {
            $sc_field_arr['respTransactionType'] = $dmn_params['transactionType'];
        }
        
//        SC_CLASS::create_log($sc_field_arr, '$sc_field_arr: ');
        
        // fill safecharge order field
        $resp = $connection->update(
            's_order_attributes',
            ['safecharge_order_field' => json_encode($sc_field_arr)],
            ['orderID' => $oder_id]
        );
        
        if(!$resp) {
            SC_CLASS::create_log($resp, 'update sc field fail: ');
        }
    }
    
    private function add_order_msg($msg)
    {
        
    }
    
    private function add_order_refund($msg)
    {
        
    }

    /**
     * Function getURLs
     * Get a URL we need
     * 
     * @param array $settings
     */
    private function getURLs($settings)
    {
        $urls = [];
        
		if ($settings['swagSCTestMode']) {
            $urls = [
            //    'session_token'     => SC_TEST_SESSION_TOKEN_URL,
                'merch_paym_meth'   => SC_TEST_REST_PAYMENT_METHODS_URL,
            //    'form_cashier'      => SC_TEST_CASHIER_URL,
                'form_rest'         => SC_TEST_PAYMENT_URL,
            ];
		}
        else {
            $urls = [
            //    'session_token'     => SC_LIVE_SESSION_TOKEN_URL,
                'merch_paym_meth'   => SC_LIVE_REST_PAYMENT_METHODS_URL,
            //    'form_cashier'      => SC_LIVE_CASHIER_URL,
                'form_rest'         => SC_LIVE_PAYMENT_URL,
            ];
		}
	}
    
    /**
     * 
     * @return boolean
     */
    private function checkAdvRespChecksum()
    {
		$settings					= $this->getPluginSettings();
		$params						= $this->Request()->getParams();
		$status						= $this->get_request_status();
		$advanceResponseChecksum	= $params['advanceResponseChecksum'];
        
        if(!$settings) {
            SC_CLASS::create_log($settings, 'checkAdvRespChecksum() can not get plugin settings!: ');
            return false;
        }
        
        $str = hash(
            $settings['hash'],
            $settings['secret'] . $params['totalAmount'] . $params['currency']
                . $params['responseTimeStamp'] . $params['PPP_TransactionID']
                . $status . $params['productId']
        );
        
        if ($str == $advanceResponseChecksum) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Function get_request_status
     * We need this stupid function because the name response request variable
     * can be 'Status' or 'status'
     * 
     * @param array $params - response parameters
     * @return string
     */
    private function get_request_status($params = array())
    {
        if(empty($params)) {
            if($this->Request()->getParam('Status')) {
                return $this->Request()->getParam('Status');
            }

            if($this->Request()->getParam('status')) {
                return $this->Request()->getParam('status');
            }
        }
        else {
            if(isset($params['Status'])) {
                return $params['Status'];
            }

            if(isset($params['status'])) {
                return $params['status'];
            }
        }
        
        return '';
    }
    
    private function getPluginSettings()
    {
        $settigns = $this->container->get('shopware.plugin.cached_config_reader')
            ->getByPluginName('SwagSafeCharge', Shopware()->Shop());
        
        return [
            'hash'		=> $settigns['swagSCHash'],
            'secret'	=> $settigns['swagSCSecret'],
            'test_mode' => $settigns['swagSCTestMode'],
            'save_logs'	=> $settigns['swagSCSaveLogs'],
        ];
    }
}
