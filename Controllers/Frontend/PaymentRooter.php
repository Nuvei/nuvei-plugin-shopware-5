<?php

/**
 * Frontend Controller
 * 
 * @author SafeCharge
 * @year 2019
 */

use SwagSafeCharge\Components\SafeCharge\PaymentResponse;

require_once dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'sc_config.php';
require_once dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'SC_CLASS.php';

class Shopware_Controllers_Frontend_PaymentRooter extends Shopware_Controllers_Frontend_Payment
{
    // constants for order status, see db_name.s_core_states
    // order states
    const SC_ORDER_CANCELLED    = -1;
    const SC_ORDER_IN_PROGRESS  = 1;
    const SC_ORDER_COMPLETED    = 2;
    const SC_ORDER_REJECTED		= 4;
	
    // payment states
    const SC_ORDER_PARTIALLY_PAID	= 11;
    const SC_ORDER_PAID				= 12;
    const SC_ORDER_OPEN				= 17;
    const SC_PARTIALLY_REFUNDED		= 31;
    const SC_COMPLETE_REFUNDED		= 32;
    const SC_PAYMENT_CANCELLED		= 35;

    private $save_logs			= false;
    private $sys_config			= [];
    
	public function preDispatch()
    {
        $plugin = $this->get('kernel')->getPlugins()['SwagSafeCharge'];
        $this->get('template')->addTemplateDir($plugin->getPath() . '/Resources/views/');
		
		$this->sys_config = require 'config.php';
    }
	
    /**
     * Function indexAction
	 * 
	 * Mandatory method for front-end controller.
     */
    public function indexAction()
    {
		$params = $this->Request()->getParams();
		
		if(!empty($params['transactionType']) and !empty($params['advanceResponseChecksum'])) {
			return $this->forward('getDMN');
		}
		
		if('safecharge_payment' == $this->getPaymentShortName()) {
			return $this->forward('process');
		}
		
		return $this->redirect(['controller' => 'checkout']);
    }
    
    /**
     * Function processAction
     * We came here after the checkout, collect all data for the order,
     * set it the session and continue.
     */
    public function processAction()
    {
        $router = $this->Front()->Router();
		
        // prepare get parameters for the redirect URL
        $url_parameters = [
            'signature' => $this->persistBasket(),
        //    'userid'    => $user['additional']['user']['id'],
            'currency'    => $this->getCurrencyShortName(),
        ];
        
		$get_parameters = '?' . http_build_query($url_parameters);
        
        $providerUrl = $router->assemble(['controller' => 'PaymentProvider', 'action' => 'pay']);
        $this->redirect($providerUrl . $get_parameters);
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
		
		if(empty($params['TransactionID']) or ! is_numeric($params['TransactionID'])) {
			SC_CLASS::create_log($params['TransactionID'], 'DMN Error - not valid TransactionID:');
			
			echo 'DMN Error - not valid transactionType!';
            exit;
		}
        
        if(!$this->checkAdvRespChecksum()) {
            SC_CLASS::create_log('DMN report: You receive DMN from not trusted source. The process ends here.');
            
            echo 'You receive DMN from not trusted source. The process ends here.';
            exit;
        }
        
        $connection = $this->container->get('dbal_connection');
        $order_data = [];
		
        # Sale and Auth
        if(in_array($params['transactionType'], array('Sale', 'Auth'))) {
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
            SC_CLASS::create_log('DMN: for ' . $params['transactionType']);
            
            try {
                if($order_data['status'] != self::SC_ORDER_COMPLETED) {
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
        if(
			in_array($params['transactionType'], array('Credit', 'Refund'))
			and !empty($params['relatedTransactionId'])
		) {
			try {
				$order_data = current(
                    $connection->fetchAll(
                        'SELECT id, ordernumber, status FROM s_order WHERE transactionID = :trID',
                        ['trID' => $params['relatedTransactionId']]
                    )
                );
				
				$this->change_order_status($order_data);
			}
			catch (Exception $ex) {
				SC_CLASS::create_log($ex->getMessage(), 'DMN Refund Exception: ');
			}
			
			echo 'DMN received.';
            exit;
		}
        
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
                
                $this->change_order_status($order_data);
            }
            catch (Exception $ex) {
                SC_CLASS::create_log($ex->getMessage(), 'getDMNAction() Void/Settle Exception: ');
            }
			
			echo 'DMN received.';
            exit;
        }
        
        SC_CLASS::create_log('getDMNAction end.');
        
        echo 'DMN received, but not recognized.';
        exit;
    }
	
    /**
     * Function change_order_status
	 * 
     * @param array $order_info
     */
    private function change_order_status($order_info)
    {
        $order_module	= Shopware()->Modules()->Order();
		$status			= $this->get_request_status();
		$params			= $this->Request()->getParams();
		$message		= '';
//        $ord_status		= self::SC_ORDER_IN_PROGRESS;
        $ord_status		= $order_info['status'];
//        $payment_status = self::SC_ORDER_OPEN;
        $payment_status = '';
		$send_message	= true;
        
        SC_CLASS::create_log(
            'Order ' . $order_info['id'] .' has Status: ' . $status,
            'Change_order_status(): '
        );
        
        if(@$this->sys_config['mail']['disabled'] == 1) {
            $send_message = false;
        }
		
		// remove waitigForDMN flag
		$conn = $this->container->get('dbal_connection');
		$safecharge_order_field = $conn
			->fetchColumn("SELECT safecharge_order_field FROM s_order_attributes WHERE orderID = " . $order_info['id']);
		
		if($safecharge_order_field) {
			$sc_order_field_arr = json_decode($safecharge_order_field, true);
			
			if(isset($sc_order_field_arr['waitigForDMN']) and intval($sc_order_field_arr['waitigForDMN']) == 1) {
				$sc_order_field_arr['waitigForDMN'] = 0;
				
				$res = $conn->update(
					's_order_attributes',
					['safecharge_order_field'	=> json_encode($sc_order_field_arr)],
					['orderID'					=> $order_info['id']]
				);
			}
		}
		// remove waitigForDMN flag END
        
        switch($status) {
            case 'CANCELED':
                $message = 'Your request was CANCELED: <b>' . @$params['transactionType'] . '</b>.'
					. '<br/>PPP_TransactionID = ' . @$params['PPP_TransactionID']
                    . ",<br/>Status = " . $status 
					. ',<br/>TransactionID = ' . @$params['TransactionID'];
				
				$ord_status = $status;

				if(in_array(@$params['transactionType'], ['Auth', 'Settle', 'Sale'])) {
					$ord_status		= self::SC_ORDER_REJECTED;
					$payment_status	= self::SC_PAYMENT_CANCELLED;
				}
                break;
            
            case 'APPROVED':
                if(@$params['transactionType'] == 'Void') {
                    $message = 'Payment status changed to: <b>Void/Canceld</b>'
						. '.<br/>PPP_TransactionID = ' . @$params['PPP_TransactionID']
						. ",<br/>Status = " . $status
						. ',<br/>TransactionID = ' . @$params['TransactionID'];

					$ord_status		= self::SC_ORDER_REJECTED;
					$payment_status	= self::SC_PAYMENT_CANCELLED;
					break;
                }
                
                // Refund
                if(in_array(@$params['transactionType'], ['Credit', 'Refund'])) {
                    $message		= 'Payment status changed to: <b>Refunded</b>.<br/>';
					
					$ord_status		= self::SC_ORDER_COMPLETED;
					$payment_status	= self::SC_PARTIALLY_REFUNDED;
					
//					$this->update_sc_field($order_info);
					$this->save_refund($order_info, $params);
                }
                
                if(@$params['transactionType'] == 'Auth') {
                    $message = 'The amount has been authorized and wait for Settle.<br/>';
					
					$ord_status		= self::SC_ORDER_IN_PROGRESS;
					$payment_status = self::SC_ORDER_OPEN;
					
					$this->update_sc_field($order_info);
                }
                elseif(in_array(@$params['transactionType'], ['Settle', 'Sale'])) {
					$message		= 'The amount has been authorized and captured by ' . SC_GATEWAY_TITLE . '.<br/>';
                    
					$ord_status		= self::SC_ORDER_COMPLETED;
                    $payment_status	= self::SC_ORDER_PAID;
					
					if(@$params['totalAmount'] != @$params['item_amount_1']) {
						$payment_status	= self::SC_ORDER_PARTIALLY_PAID;
					}
					
					$this->update_sc_field($order_info);
                }
                
                $message .= 'PPP_TransactionID = ' . @$params['PPP_TransactionID']
					. ",<br/>Status = ". $status;
                
                if($this->Request()->getParam('transactionType')) {
                    $message .= ",<br/>TransactionType = ". @$params['transactionType'];
                }
                
                $message .= ',<br/>TransactionID = '. @$params['TransactionID'];
                break;
                
            case 'ERROR':
            case 'DECLINED':
            case 'FAIL':
                $ord_status = $status;
                
                $message = 'Your ' . @$params['transactionType'] . ' request, has status ' . $status;
				
                if(@$params['reason']) {
                    $message .= '<br/>Reason: ' . $params['reason'] . '.';
                }
                elseif(@$params['Reason']) {
                    $message .= '<br/>Reason: ' . $params['Reason'] . '.';
                }
				
                if(@$params['reasonCode']) {
                    $message .= '<br/>ReasonCode: ' . $params['reasonCode'] . '.';
                }
                elseif(@$params['ReasonCode']) {
                    $message .= '<br/>ReasonCode: ' . $params['ReasonCode'] . '.';
                }
				
                $message .= '<br/>PPP_TransactionID: ' . @$params['PPP_TransactionID']
                    . ",<br/>Error code = " . @$params['ErrCode'] . ", Message = " . @$params['message'] . $reason
					. ',<br/>TransactionID = ' . @$params['TransactionID'];
				
				$ord_status	= $order_info['status'];
				
				if(in_array(@$params['transactionType'], ['Auth', 'Settle', 'Sale'])) {
					$ord_status = self::SC_ORDER_CANCELLED;
				}
				
                break;
            
            case 'PENDING':
                if (
                    $order_info['status'] == self::SC_ORDER_COMPLETED
                    || $order_info['status'] == self::SC_ORDER_IN_PROGRESS
                ) {
                    break;
                }
				
				$ord_status		= self::SC_ORDER_IN_PROGRESS;
				$payment_status = self::SC_ORDER_OPEN;
                
                $message = 'Payment is still pending, PPP_TransactionID '
                    . @$params['PPP_TransactionID'] . ", Status = " . $status;

                if(@$params['transactionType']) {
                    $message .= ", TransactionType = " . $params['transactionType'];
                }

                $message .= ', TransactionID = ' . @$params['TransactionID'];
                
				// add one mmore message
                $order_module->setOrderStatus(
                    $order_info['id']
                    ,$ord_status
                    ,$send_message
                    ,SC_GATEWAY_TITLE .' payment status is pending<br/>Unique Id: '
                        . @$params['PPP_TransactionID']
                );
                
//                $order_module->setPaymentStatus(
//                    $order_info['id']
//                    ,$payment_status
//                    ,$send_message
//                );
                
                break;
        }
        
        SC_CLASS::create_log(
            $order_info['id'] . '; ' . $ord_status . '; ' . $payment_status . '; ' . $message
            ,'$order_id, $ord_status, $payment_status, $message: '
        );
		
        $order_module->setOrderStatus($order_info['id'], $ord_status, $send_message, $message);
		
		if(!empty($payment_status)) {
			$order_module->setPaymentStatus($order_info['id'], $payment_status, $send_message);
		}
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
//            $sc_field_arr['relatedTransactionId'] = $dmn_params['TransactionID'];
            $sc_field_arr['relatedTransactionId'] = $dmn_params['relatedTransactionId'];
        }
        if(!empty($dmn_params['transactionType'])) {
            $sc_field_arr['respTransactionType'] = $dmn_params['transactionType'];
        }
        if(!empty($dmn_params['payment_method'])) {
            $sc_field_arr['paymentMethod'] = $dmn_params['payment_method'];
        }
        
        // fill safecharge order field
        $resp = $connection->update(
            's_order_attributes',
            ['safecharge_order_field'	=> json_encode($sc_field_arr)],
            ['orderID'					=> $oder_id]
        );
		
		if(!$resp) {
            SC_CLASS::create_log(
				@$connection->getErrorMessage(),
				'Error when try to update SC Fields of the Order. Try to insert.'
			);
        }
		
		// update Order transactionID field with the last one
		if(in_array($dmn_params['transactionType'], ['Settle', 'Void'])) {
			$resp = $connection->update(
				's_order',
				['transactionID'	=> $dmn_params['TransactionID']],
				['id'				=> $oder_id]
			);
        
			if(!$resp) {
				SC_CLASS::create_log('Error when try to set new Transaction ID of the Order.');
			}
		}
    }
	
	private function save_refund($order_data, $params)
	{
		$conn	= $this->container->get('dbal_connection');
		$amount	= $params['totalAmount'];
		
		if(!empty($params['customData'])) {
			$custom_data = json_decode($params['customData'], true);
			
			if(is_array($custom_data) and !empty($custom_data['refund_amount'])) {
				$amount	= $custom_data['refund_amount'];
			}
		}
		
		try {
			$conn->insert(
				'swag_safecharge_refunds',
				[
					'order_id'          => $order_data['id'],
					'client_unique_id'  => @$params['clientUniqueId'],
					'amount'            => $amount,
					'transaction_id'    => @$params['TransactionID'],
					'auth_code'			=> @$params['AuthCode'],
				]
			);
		}
		catch (Exception $ex) {
			SC_CLASS::create_log($ex->getMessage(), 'Save Refund exception:');
		}
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
                'merch_paym_meth'   => SC_TEST_REST_PAYMENT_METHODS_URL,
                'form_rest'         => SC_TEST_PAYMENT_URL,
            ];
		}
        else {
            $urls = [
                'merch_paym_meth'   => SC_LIVE_REST_PAYMENT_METHODS_URL,
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
		$settings			= $this->container->get('shopware.plugin.cached_config_reader')
            ->getByPluginName('SwagSafeCharge', Shopware()->Shop());
		$params				= $this->Request()->getParams();
		$status				= $this->get_request_status();
		$advRespChecksum	= $params['advanceResponseChecksum'];
        
        if(!$settings) {
            SC_CLASS::create_log($settings, 'checkAdvRespChecksum() can not get plugin settings!: ');
            return false;
        }
        
        $str = hash(
            $settings['swagSCHash'],
            $settings['swagSCSecret'] . $params['totalAmount'] . $params['currency']
                . $params['responseTimeStamp'] . $params['PPP_TransactionID']
                . $status . $params['productId']
        );
        
        if ($str == $advRespChecksum) {
            return true;
        }
		
        return false;
    }
    
    /**
     * Function get_request_status
     * We need this stupid function because the name response request variable
     * can be 'Status' or 'status'
     * 
     * @return string
     */
    private function get_request_status()
    {
		if($status = $this->Request()->getParam('Status')) {
			return $status;
		}

		if($status = $this->Request()->getParam('status')) {
			return $status;
		}
        
        return '';
    }
}
