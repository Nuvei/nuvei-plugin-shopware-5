<?php

/**
 * @author SafeCharge
 * 
 * @year 2019
 */

//use Doctrine\DBAL\Connection;
//use Doctrine\ORM\AbstractQuery;
//use Shopware\Components\Model\QueryBuilder;

use Shopware\Components\CSRFGetProtectionAware;

require_once dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'sc_config.php';
require_once dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'SC_CLASS.php';

class Shopware_Controllers_Backend_SafeChargeOrderEdit extends Shopware_Controllers_Backend_ExtJs implements CSRFGetProtectionAware
{
	private $webMasterId	= 'ShopWare ';
	private $notify_url;
    
    // constants for order status, see db_name.s_core_states
    // order states
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
    
    public function getCSRFProtectedActions()
    {
        return ['process', 'getSCOrderData', 'getSCOrderNotes'];
    }
    
    /**
     * Function getSCOrderDataAction
     * This method is called with Ajax.
     * When load order, check for SC data to decide will we show SC settings or not.
     */
    public function getSCOrderDataAction()
    {
		$settigns = $this->container->get('shopware.plugin.cached_config_reader')
            ->getByPluginName('SwagSafeCharge');
        
        $_SESSION['sc_save_logs'] = $settigns['swagSCSaveLogs'];
		
        $conn				= $this->container->get('db');
        $order_id			= intval($this->request->getParam('orderId'));
        $sc_order_field_arr	= $this->getSCOrderData();
        
        // get refunds
        $sc_order_field_arr['refunds'] = $conn
            ->fetchAll("SELECT * FROM swag_safecharge_refunds WHERE order_id = " . $order_id);
        
        $order_data			= current($conn->fetchAll("SELECT status, invoice_amount FROM s_order WHERE id = " . $order_id));
        $sc_enable_void		= false;
		$sc_enable_refund	= false;
		$sc_enable_settle	= false;
		
		// enable actions ONLY if the order does not wait DMN
		if(!isset($sc_order_field_arr['waitigForDMN']) or intval($sc_order_field_arr['waitigForDMN']) == 0) {
			// enable Refund
			$refunds_total = 0;
			
			if(!empty($sc_order_field_arr['refunds'])) {
				foreach($sc_order_field_arr['refunds'] as $refund) {
					$refunds_total += floatval($refund['amount']);
				}
			}
			
			if(
				in_array(@$sc_order_field_arr['paymentMethod'], ['cc_card', 'dc_card', 'apmgw_expresscheckout'])
				and !in_array(@$sc_order_field_arr['respTransactionType'], ['Void', 'Auth', ])
				and $order_data['invoice_amount'] > $refunds_total
			) {
				$sc_enable_refund = true;
			}
			// enable Refund END

			// enable Void
			if(
				self::SC_ORDER_COMPLETED == $order_data['status']
				and in_array(@$sc_order_field_arr['respTransactionType'], ['Settle', 'Sale'])
				and empty($sc_order_field_arr['refunds'])
			) {
				$sc_enable_void = true;
			}

			// enable Settle and Void
			if(
				'Auth' == @$sc_order_field_arr['respTransactionType']
				and self::SC_ORDER_IN_PROGRESS == $order_data['status']
			) {
				$sc_enable_settle	= true;
				$sc_enable_void		= true;
			}
		}
        
        echo json_encode([
            'status'			=> 'success',
            'scOrderData'		=> $sc_order_field_arr,
            'scEnableVoid'		=> $sc_enable_void,
            'scEnableRefund'	=> $sc_enable_refund,
            'scEnableSettle'	=> $sc_enable_settle,
        ]);
        
        exit;
    }
    
    public function getSCOrderNotesAction()
    {
        $order_notes = $this
            ->container->get('db')
            ->fetchAll(
                "SELECT h.change_date AS date, h.comment "
                . "FROM s_order_history AS h "
                . "LEFT JOIN s_order_attributes AS at "
                    . "ON h.orderID = at.orderID "
                . "WHERE h.orderID = "  . intval($this->request->getParam('orderId')) . " "
                    . "AND h.comment <> '' "
                    . "AND at.safecharge_order_field IS NOT NULL "
                . "ORDER BY h.change_date DESC");
        
        if(!$order_notes) {
            echo json_encode(['status' => 'false']);
            exit;
        }
        
        echo json_encode([
            'status' => 'success',
            'notes' => $order_notes,
        ]);
        exit;
    }

    /**
     * Function processAction
     * Catch and process SC Order buttons actions
     */
    public function processAction()
    {
        $settigns	= $this->container->get('shopware.plugin.cached_config_reader')
            ->getByPluginName('SwagSafeCharge');
		$router		= Shopware()->Front()->Router();
        
        $_SESSION['sc_create_logs'] = $settigns['swagSCSaveLogs'] ? 1 : 0;
        
        $sc_order_field_arr = $this->getSCOrderData();
		$scAction			= $this->request->getParam('scAction');
		$this->notify_url	= $router->assemble(['module'=> 'frontend', 'controller' => 'PaymentRooter', 'action' => 'getDMN'])
            . '?sc_create_logs=' . $_SESSION['sc_create_logs'];
		
		if($settings['swagSCUseHttp']) {
            $this->notify_url = str_replace('https:', 'http:', $this->notify_url);
        }
        
        if($scAction == 'refund') {
            $this->orderRefund($sc_order_field_arr, $settigns);
        }
        elseif($scAction == 'manualRefund') {
            $this->orderRefund($sc_order_field_arr, $settigns, true);
        }
        elseif($scAction == 'deleteRefund') {
            $this->orderDeleteRefund();
        }
        elseif(in_array($scAction, ['settle', 'void'])) {
            $this->orderSettleAndVoid($sc_order_field_arr, $settigns);
        }
        
        SC_CLASS::create_log($scAction, 'Unknown action: ');
        
        echo json_encode([
            'status' => 'error',
            'msg' => 'Unknown Ajax action: ' . $this->request->getParam('scAction')
        ]);
        exit;
    }
    
    /**
     * Function getSCOrderData
     * Help function to get and validate SC Order data
     * If there are errors echo json response because we call this method with ajax
     * Else return the data to the caller function.
     * 
     * @return array
     */
    private function getSCOrderData($return = false)
    {
        $order_id = intval($this->request->getParam('orderId'));
        
        $sc_order_field = $this
            ->container->get('db')
            ->fetchOne("SELECT safecharge_order_field FROM s_order_attributes WHERE orderID = " . $order_id);
        
//        SC_CLASS::create_log($sc_order_field, 'Get SC order fields response: ');
		
        if(!$sc_order_field) {
			if($return) {
				return array();
			}
			
            echo json_encode([
                'status'	=> 'error',
                'msg'		=> 'This order is not paid with SafeCharge Paygate or transactoin data is missing.',
				'data'		=> $sc_order_field,
				'query'		=> "SELECT safecharge_order_field FROM s_order_attributes WHERE orderID = " . $order_id
            ]);
            
            exit;
        }
        
        $sc_order_field_arr = json_decode($sc_order_field, true);
        
        if(!$sc_order_field_arr) {
			if($return) {
				return array();
			}
			
            echo json_encode([
                'status' => 'error',
                'msg' => 'This order is not paid with SafeCharge Paygate or the data is in wrong format.'
            ]);
            
            exit;
        }
        
        if(!isset($sc_order_field_arr['authCode']) || !isset($sc_order_field_arr['relatedTransactionId'])) {
			if($return) {
				return $sc_order_field_arr;
			}
			
            echo json_encode([
                'status' => 'error',
                'msg' => 'There are no SafeCharge TransactionID and/or AuthCode.'
            ]);
            
            exit;
        }
        
        return $sc_order_field_arr;
    }
    
    /**
     * Function orderRefund
     * Refund an order after click on refund button
     * 
     * @param int $order_id - secured
     * @param array $payment_custom_fields
     * @param array $settings - plugin settings
     * @param bool $is_manual - manual refund or not
     */
    private function orderRefund($payment_custom_fields, $settings, $is_manual = false)
    {
        $clientUniqueId	= uniqid();
        $conn			= $this->container->get('db');
        $order_id		= intval($this->Request()->getParam('orderId'));
        $order_data		= $conn->fetchAll("SELECT invoice_amount, currency, status, transactionID FROM s_order WHERE id = " . $order_id);
		
		if(empty($order_data) || empty($order_data[0])) {
            echo json_encode([
                'status'	=> 'error',
                'msg'		=> 'Missing Order data.'
            ]);
            exit;
        }
		
		$order_data = current($order_data);
		
        if($order_data['status'] != self::SC_ORDER_COMPLETED) {
            echo json_encode([
                'status'	=> 'error',
                'msg'		=> 'To create Refund, the Order must be Completed.'
            ]);
            exit;
        }
        
        // get refunds
        $refunded_amount = 0;
        $order_refunds = $conn->fetchAll("SELECT * FROM swag_safecharge_refunds WHERE order_id = " . $order_id);
        
        if($order_refunds) {
            foreach($order_refunds as $refund) {
                $refunded_amount += $refund['amount'];
            }
        }
        // get refunds END
        
        $refund_amount = number_format($this->request->getParam('refundAmount'), 2, '.', '');
        
        if(($refund_amount + $refunded_amount) > $order_data['invoice_amount']) {
            echo json_encode([
                'status'	=> 'error',
                'msg'		=> 'Refund request Amount is too big.'
            ]);
            exit;
        }
        
        // manual Refund stops here
        if($is_manual) {
            try {
                $conn->insert(
                    'swag_safecharge_refunds',
                    [
                        'order_id'          => $order_id,
                        'client_unique_id'  => $clientUniqueId,
                        'amount'            => $refund_amount
                    ]
                );
            }
            catch(Exception $e) {
                echo json_encode([
                    'status'	=> 'error',
                    'msg'		=> $e->getMessage()
                ]);
                exit;
            }
                
            echo json_encode([
                'status'	=> 'success',
                'msg'		=> 'The Refund was saved.'
            ]);
            exit;
        }
        
		$time = date('YmdHis', time());
		
		$ref_parameters = array(
			'merchantId'            => $settings['swagSCMerchantId'],
			'merchantSiteId'        => $settings['swagSCMerchantSiteId'],
			'clientRequestId'       => $time . '_' . $order_data['transactionID'],
			'clientUniqueId'        => $clientUniqueId,
			'amount'                => number_format($this->request->getParam('refundAmount'), 2, '.', ''),
			'currency'              => $order_data['currency'],
			'relatedTransactionId'  => $order_data['transactionID'],
			'authCode'              => $payment_custom_fields['authCode'],
			'comment'               => '',
			'url'					=> $this->notify_url,
			'timeStamp'             => $time,
			'sourceApplication'     => SC_SOURCE_APPLICATION,
		);
		
		$checksum_str = implode('', $ref_parameters);
		
		$checksum = hash(
			$settings['swagSCHash'],
			$checksum_str . $settings['swagSCSecret']
		);
		
		$ref_parameters['customData']  = json_encode(array('refund_amount' => $ref_parameters['amount']));
		$ref_parameters['checksum']    = $checksum;
		$ref_parameters['urlDetails']  = array('notificationUrl' => $this->notify_url);
		$ref_parameters['webMasterId'] = $this->webMasterId
			. $this->container->getParameter('shopware.release.version');
		
		$resp = SC_CLASS::call_rest_api(
			$settings['swagSCTestMode'] == 1 ? SC_TEST_REFUND_URL : SC_LIVE_REFUND_URL,
			$ref_parameters
		);
		
        if(!$resp) {
            echo json_encode([
                'status'	=> 'error',
                'msg'		=> 'There is an error with the request response.'
            ]);
            exit;
        }
		
		$order_module = Shopware()->Modules()->Order();
        
        // in case we have message but without status
        if(empty($resp['status'])) {
			if(!empty($resp['msg'])) {
				// save response message in the History
				$msg = 'Request Refund #' . $clientUniqueId . ' problem: ' . $resp['msg'];

				echo json_encode([
					'status'	=> 'error',
					'msg'		=> $msg
				]);
				exit;
			}
			
			echo json_encode([
				'status'	=> 'error',
				'msg'		=> 'The Response has no Status.'
			]);
			exit;
        }
        
        $cpanel_url = $settings['swagSCTestMode'] == 1 ? SC_TEST_CPANEL_URL : SC_LIVE_CPANEL_URL;
        $msg		= '';
        $error_note = 'Refund Request #' . $clientUniqueId . ' fail, if you want, login into ' . $cpanel_url
            . ' and refund Order with Transaction ID #' . $order_data['transactionID'];

        if($resp['status'] == 'ERROR') {
            $msg = 'Request ERROR - "' . $resp['reason'] .'" '. $error_note;
            
            echo json_encode([
                'status'	=> 'error',
                'msg'		=> $msg
            ]);
            exit;
        }
        
        // if request success, we will wait for DMN
        $msg = 'Request Refund #' . $clientUniqueId . ', was sent. Please, wait for DMN!';
        $order_module->setOrderStatus($order_id, $order_data['status'], false, $msg);

		$sc_order_field = $this->getSCOrderData();
		
		if(!$sc_order_field) {
			echo json_encode([
				'status'	=> 'error',
				'msg'		=> 'SafeCharge Order field is empty.'
			]);
			exit;
		}
		
		$sc_order_field['waitigForDMN']	= 1;
		
		try {
			$conn->update(
				's_order_attributes',
				['safecharge_order_field' => json_encode($sc_order_field)]
			);
		}
		catch (Exception $ex) {
			echo json_encode([
				'status'	=> 'error',
				'msg'		=> $ex->getMessage()
			]);
			exit;
		}
		
        echo json_encode([
            'status'	=> 'success',
            'msg'		=> $msg
        ]);
        exit;
    }
    
    /**
     * Function orderDeleteRefund
     * Delete manual refund from an order.
     * 
     * @param type $payment_custom_fields
     * @param type $settings
     */
    private function orderDeleteRefund()
    {
        $conn		= $this->container->get('dbal_connection');
        $order_id	= intval($this->Request()->getParam('orderId'));
        $refund_id	= intval($this->Request()->getParam('refundId'));
        
    //    $resp = $conn->query("DELETE FROM swag_safecharge_refunds WHERE id = {$refund_id} AND order_id = $order_id");
        $resp = $conn->delete('swag_safecharge_refunds', [
            'id'        => $refund_id,
            'order_id'  => $order_id,
        ]);
        
        if($resp) {
            echo json_encode([
                'status'				=> 'success',
                'removeManualRefund'	=> 1
            ]);
            exit;
        }
        
        echo json_encode([
            'status'	=> 'error',
            'msg'		=> @$conn->getErrorMessage() ? $conn->getErrorMessage() : 'Error when try to remove the Refund.'
        ]);
        exit;
    }
    
    /**
     * orderSettleAndVoid orderRefund
     * Settle or Void an order after click on button
     * 
     * @param array $payment_custom_fields
     * @param array $settings - plugin settings
     */
    private function orderSettleAndVoid($payment_custom_fields, $settings)
    {
        $order_id = intval($this->request->getParam('orderId'));
        
        if(!$order_id) {
            echo json_encode([
                'status' => 'error',
                'msg' => 'The Order ID is missing.'
            ]);
            
            exit;
        }
        
        $order_info = $this
            ->container->get('db')
            ->fetchAll("SELECT invoice_amount, currency, status, transactionID FROM s_order WHERE id = " . $order_id);
        
        if(empty($order_info) || empty($order_info[0]['transactionID'])) {
            echo json_encode([
                'status' => 'error',
                'msg' => 'There is no Order data.'
            ]);
            
            exit;
        }
		
		$order_info		= $order_info[0];
        $time			= date('YmdHis', time());
		
        $params = array(
            'merchantId'            => $settings['swagSCMerchantId'],
            'merchantSiteId'        => $settings['swagSCMerchantSiteId'],
            'clientRequestId'       => $time . '_' . $order_info['transactionID'],
            'clientUniqueId'        => uniqid(),
            'amount'                => number_format($order_info['invoice_amount'], 2, '.', ''),
            'currency'              => $order_info['currency'],
            'relatedTransactionId'  => $order_info['transactionID'],
            'authCode'              => $payment_custom_fields['authCode'],
            'urlDetails'            => array('notificationUrl' => $this->notify_url),
            'timeStamp'             => $time,
			'sourceApplication'     => SC_SOURCE_APPLICATION,
            'test'                  => $settings['swagSCTestMode'] == 1 ? 'yes' : 'no', // need to define the endpoint
        );
        
        $checksum = hash(
            $settings['swagSCHash'],
            $settings['swagSCMerchantId'] . $settings['swagSCMerchantSiteId'] . $params['clientRequestId']
                . $params['clientUniqueId'] . $params['amount'] . $params['currency']
                . $params['relatedTransactionId'] . $params['authCode']
                . $this->notify_url . $time . $settings['swagSCSecret']
        );
        
        $params['checksum'] = $checksum;
        
		if('settle' == $this->request->getParam('scAction')) {
			$url = $settings['swagSCTestMode'] == 1 ? SC_TEST_SETTLE_URL : SC_LIVE_SETTLE_URL;
		}
		elseif('void' == $this->request->getParam('scAction')) {
			$url = $settings['swagSCTestMode'] == 1 ? SC_TEST_VOID_URL : SC_LIVE_VOID_URL;
		}
		else {
			echo json_encode([
                'status' => 'error',
                'msg' => 'Unknown action: ' . $this->request->getParam('scAction')
            ]);
            
            exit;
		}
		
		$resp = SC_CLASS::call_rest_api($url, $params);
		
		if(empty($resp) or empty($resp['transactionStatus'])) {
			
			
            echo json_encode([
				'status'	=> 'error',
				'msg'		=> 'REST API call is empty or response transactionStatus is empty'
			]);
			exit;
        }
		
		echo json_encode(['status' => 'success']);
		exit;
    }
}
