<?php

/**
 * @author SafeCharge
 * 
 * @year 2019
 */

use SwagSafeCharge\Components\SafeCharge\PaymentResponse;

class Shopware_Controllers_Frontend_SafeCharge extends Shopware_Controllers_Frontend_Payment
{
    // constants for order status, see db_name.s_core_states
    // states
    const SC_ORDER_CANCELLED    = -1;
    const SC_ORDER_IN_PROGRESS  = 1;
    const SC_ORDER_COMPLETED    = 2;
    // payment states
    const SC_ORDER_PAID         = 12;
    const SC_ORDER_OPEN         = 17;
    const SC_PARTIALLY_REFUNDED = 31;
    const SC_COMPLETE_REFUNDED  = 32;
    const SC_PAYMENT_CANCELLED  = 35;

    private $save_logs			= false;
    private $webMasterId		= 'ShopWare ';
    private $logs_path			= '';
    private $plugin_dir			= '';
    private $sys_config			= [];
    
    /**
     * Function indexAction
     * Check if user use SC payment method, if not go to default checkout
     */
    public function indexAction()
    {
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
        $this->plugin_dir	= dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR;
        $this->logs_path	= $this->plugin_dir . 'logs' . DIRECTORY_SEPARATOR;
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
        $providerUrl = $router->assemble(['controller' => 'SafechargePayment', 'action' => 'pay']);
        $this->redirect($providerUrl . $get_parameters);
    }
    
    /**
     * Function successAction
     * On success when use Cashier customer will come here.
     * Save the order and redirect to default success page.
     */
    public function successAction()
    {
        $this->plugin_dir   = dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR;
        $this->logs_path    = $this->plugin_dir . 'logs' . DIRECTORY_SEPARATOR;
        
        $response = new PaymentResponse();
        $response->transactionId    = $this->Request()->getParam('PPP_TransactionID', null);
        $response->status           = $this->Request()->getParam('ppp_status', null);
        $response->token            = $this->Request()->getParam('advanceResponseChecksum', null);
        
        $signature = $this->Request()->getParam('signature');
        
        try {
            $basket = $this->loadBasketFromSignature($signature);
            $this->verifyBasketSignature($signature, $basket);
            
            if(!$this->checkAdvRespChecksum()) {
                $this->createLog('The checkAdvRespChecksum not mutch!');
                
                return $this->redirect(['controller' => 'SafechargePayment', 'action' => 'cancel']);
            }
        }
        catch (Exception $e) {
            $this->createLog($e->getMessage(), 'successAction exception: ');
            return $this->redirect(['controller' => 'SafechargePayment', 'action' => 'cancel']);
        }

        $order_num = $this->saveOrder(
            $this->Request()->getParam('TransactionID')
            ,$this->Request()->getParam('advanceResponseChecksum')
            ,self::SC_ORDER_IN_PROGRESS
        //    ,true // send mail, if mail server not set it will crash
        );
        
        // update only when get DMN
    //    $this->update_sc_field(['ordernumber' => $order_num]);
        
        $this->createLog('Order saved, redirect to checkout/finish');
        
        // call to DMN URL if there is a DMN file
        $dmns_file_path = dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR
            . 'dmns' . DIRECTORY_SEPARATOR . $this->Request()->getParam('TransactionID') . '.txt';
        
        if(is_readable($dmns_file_path)) {
            $this->createLog('There is DMN file, call getDMNAction()');
            
            require $dmns_file_path;
        //    $this->createLog($sc_dmn_params, 'dmn file content: ');
            $resp = $this->getDMNAction($sc_dmn_params);
            
            unlink($dmns_file_path);
        }
        // call to DMN URL if there is a DMN file END
        
        return $this->redirect(['controller' => 'checkout', 'action' => 'finish']);
    }
    
    /**
     * Function getDMNAction
     * 
     * @param array $params - in case we call this method form inside we will pass the params
     */
    public function getDMNAction($params = array())
    {
        $this->plugin_dir = dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR;
        $this->logs_path = $this->plugin_dir . 'logs' . DIRECTORY_SEPARATOR;
        $is_inside_call = true;
        
        $this->sys_config = require 'config.php';
        
        if(empty($params)) {
            $is_inside_call = false;
            $params = $this->Request()->getParams();
        }
        
        // there is a strange problem - something replace '&currency=' to '¤cy=' we have to fix it
        if(isset($params['email']) && strpos($params['email'], '¤cy=') !== false) {
            $mail_parts = explode('¤', $params['email']);
            $params['email'] = $mail_parts[0];
            $params['currency'] = end(explode('=', $mail_parts[1]));
        }
        
        $this->createLog($params, 'DMN Request params: ');
        
        if(!$this->checkAdvRespChecksum($params)) {
            $this->createLog('DMN report: You receive DMN from not trusted source. The process ends here.');
            
            if($is_inside_call) {
                return 'You receive DMN from not trusted source. The process ends here.';
            }
            
            echo 'You receive DMN from not trusted source. The process ends here.';
            exit;
        }
        
        $req_status = $this->get_request_status($params);
        $connection = $this->container->get('dbal_connection');
        $order_data = [];
        
        # Sale and Auth
        if(in_array($params['transactionType'], array('Sale', 'Auth'))) {
            $this->createLog('A sale/auth.');
            
            // Cashier Sale - here we do not have order ID
            if($params['invoice_id'] && $params['TransactionID']) {
                $this->createLog('Cashier sale.');
                
                try {
                    $order_data = current(
                        $connection->fetchAll(
                            'SELECT id, ordernumber, status FROM s_order WHERE transactionID = :trID',
                            ['trID' => $params['TransactionID']]
                        )
                    );
                    
                    // In ShopWare flow it is quite possible to receive the DMN before the order is save
                    // in this case we will save the DMN in temp file and when it is time ot
                    // save the order we will check for it. From it we will generete and call the DMN URL
                    if(!$order_data) {
                        $this->createLog('The Order is not created yet. Create DMN temp file.');
                        
                        $dmns_dir_path = dirname(dirname(dirname(__FILE__)))
                            . DIRECTORY_SEPARATOR . 'dmns' . DIRECTORY_SEPARATOR;
                        
                        file_put_contents(
                            $dmns_dir_path . $params['TransactionID'] . '.txt',
                            '<?php' . "\n" . '$sc_dmn_params = ' . var_export($params, true) . ';' . "\n" . '?>'
                        );
                        
                        if($is_inside_call) {
                            return 'DMN received.';
                        }
                        
                        echo 'DMN received.';
                        exit;
                    }
                    
                    $history = current(
                        $connection->fetchAll('SELECT * FROM s_order_history WHERE orderID = ' . $order_data['id'])
                    );
                }
                catch (Exception $ex) {
                    $this->createLog($ex->getMessage(), 'Cashier DMN Exception when try to get Order ID: ');
                    
                    if($is_inside_call) {
                        return 'DMN Exception: ' . $ex->getMessage();
                    }
                    
                    echo 'DMN Exception: ' . $ex->getMessage();
                    exit;
                }
            }
            // REST
            else {
                // TODO
            }
            
            try {
                $this->update_sc_field($order_data, $params);

                if($order_data['status'] != self::SC_ORDER_COMPLETED) {
                    $this->change_order_status($order_data, $req_status, $params);
                }
            }
            catch (Exception $ex) {
                $this->createLog($ex->getMessage(), 'Sale DMN Exception: ');
                
                if($is_inside_call) {
                    return 'DMN Exception: ' . $ex->getMessage();
                }
                
                echo 'DMN Exception: ' . $ex->getMessage();
                exit;
            }
            
            if($is_inside_call) {
                return 'DMN received.';
            }
            
            echo 'DMN received.';
            exit;
        }
        
        # Refund
        // TODO
        
        # Void, Settle
        // here we have to find the order by its Transaction ID -> relatedTransactionId
        if(
            isset($_REQUEST['relatedTransactionId'], $_REQUEST['transactionType'])
            && $_REQUEST['relatedTransactionId'] != ''
            && in_array($_REQUEST['transactionType'], array('Void', 'Settle'))
        ) {
            $this->createLog($_REQUEST['transactionType'], 'Void/Settle transactionType: ');
            
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
        
        $this->createLog('getDMNAction end. ');
        
        if($is_inside_call) {
            return 'DMN received.';
        }
        
        echo 'DMN received, but not recognized.';
        exit;
    }
    
    /**
     * 
     * @param array $order_info
     * @param string $status
     * @param array $params - DMN params
     */
    private function change_order_status($order_info, $status, $params = array())
    {
        require_once dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'sc_config.php';
        
        $order_module = Shopware()->Modules()->Order();
        
        $this->createLog(
            'Order ' . $order_info['id'] .' has Status: ' . $status,
            'Change_order_status(): '
        );
        
        if(empty($params)) {
            $params = $this->Request()->getParams();
        }
        
        $message = '';
        $ord_status =       self::SC_ORDER_IN_PROGRESS;
        $payment_status =   self::SC_ORDER_OPEN;
        
        $send_message = true;
        if(@$this->sys_config['mail']['disabled'] == 1) {
            $send_message = false;
        }
        
        switch($status) {
            case 'CANCELED':
                $message = 'Payment status changed to:' . @$params['transactionType']
                    . '. PPP_TransactionID = ' . @$params['PPP_TransactionID']
                    . ", Status = " . $status . ', GW_TransactionID = '
                    . @$params['TransactionID'];

                $ord_status = self::SC_ORDER_CANCELLED;
                break;
            
            case 'APPROVED':
                if(@$params['transactionType'] == 'Void') {
                    // TODO
                }
                
                // Refund
                if(@$params['transactionType'] == 'Credit') {
                    // TODO
                }
                
                $message = 'The amount has been authorized and captured by ' . SC_GATEWAY_TITLE . '. ';
                
                if(@$params['transactionType'] == 'Auth') {
                    $message = 'The amount has been authorized and wait for Settle. ';
                }
                elseif(@$params['transactionType'] == 'Settle') {
                    $message = 'The amount has been captured by ' . SC_GATEWAY_TITLE . '. ';
                    $ord_status = self::SC_ORDER_COMPLETED;
                    $payment_status = self::SC_ORDER_PAID;
                }
                
                $message .= 'PPP_TransactionID = ' . @$params['PPP_TransactionID'] . ", Status = ". $status;
                
                if($this->Request()->getParam('transactionType')) {
                    $message .= ", TransactionType = ". @$params['transactionType'];
                }
                
                $message .= ', GW_TransactionID = '. @$params['TransactionID'];
                
                if(@$params['transactionType'] != 'Auth') {
                    // add one more message
                    $order_module->setOrderStatus(
                        $order_info['id']
                        ,self::SC_ORDER_COMPLETED
                        ,$send_message
                        ,SC_GATEWAY_TITLE . ' payment is successful<br/>Unique Id: '
                            . @$params['PPP_TransactionID']
                    );
                    
                    $order_module->setPaymentStatus(
                        $order_info['id']
                        ,self::SC_ORDER_PAID
                        ,$send_message
                    );
                }
                
                break;
                
            case 'ERROR':
            case 'DECLINED':
            case 'FAIL':
                $ord_status = self::SC_ORDER_CANCELLED;
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
        
        $this->createLog(
            $order_info['id'] . '; ' . $ord_status . '; ' . $payment_status . '; ' . $message
            ,'$order_id, $ord_status, $payment_status, $message: '
        );
        
        $order_module->setOrderStatus($order_info['id'], $ord_status, $send_message, $message);
        $order_module->setPaymentStatus($order_info['id'], $payment_status, $send_message);
    }
    
    /**
     * 
     * @param int $order - order data
     * @param array $dmn_params - the parameters from the DMN
     */
    private function update_sc_field($order, $dmn_params)
    {
        $this->createLog($order, 'order data for update_sc_field: ');
        
        // create connection
        $connection = $this->container->get('dbal_connection');
        
        // get order id
        if(isset($order['id']) && $order['id']) {
            $q = "SELECT safecharge_order_field FROM s_order_attributes WHERE orderID = " . $order['id'];
            
            $sc_field   = $connection->fetchColumn($q);
            $oder_id    = $order['id'];
        }
        elseif(isset($order['ordernumber']) && $order['ordernumber']) {
            $q =
                "SELECT s_order.id AS id, s_order_attributes.safecharge_order_field AS sc_field "
                . "FROM s_order "
                . "LEFT JOIN s_order_attributes "
                    . "ON s_order.id = s_order_attributes.orderID "
                . "WHERE s_order.ordernumber = " . $order['ordernumber'];
                
            $data = $connection->fetchAll($q);
            $this->createLog($order, 'sc_field in update_sc_field: ');
            
            $sc_field   = $data['sc_field'];
            $oder_id    = $data['id'];
            
//            $oder_id = $connection
//                ->fetchColumn('SELECT id FROM s_order WHERE ordernumber = ' . $order['ordernumber']);
        }
        
        $this->createLog($sc_field, 'sc_field in update_sc_field: ');
        $this->createLog($oder_id, '$oder_id in update_sc_field: ');
        
        // get SC field
//        $sc_field = $connection
//            ->fetchColumn('SELECT safecharge_order_field FROM s_order_attributes WHERE orderID = ' . $oder_id);
        
        $sc_field_arr = [];
        if(!empty($sc_field)) {
            $sc_field_arr = json_decode($sc_field, true);
        }
        
        // get incoming data
        if(@$dmn_params['AuthCode']) {
            $sc_field_arr['authCode'] = $dmn_params['AuthCode'];
        }
        if(@$dmn_params['TransactionID']) {
            $sc_field_arr['relatedTransactionId'] = $dmn_params['TransactionID'];
        }
        if(@$dmn_params['transactionType']) {
            $sc_field_arr['respTransactionType'] = $dmn_params['transactionType'];
        }
        
        $this->createLog($sc_field_arr, '$sc_field_arr: ');
        
        // fill safecharge order field
        $resp = $connection->update(
            's_order_attributes',
            ['safecharge_order_field' => json_encode($sc_field_arr)],
            ['orderID' => $oder_id]
        );
        
        $this->createLog($resp, 'update sc field response: ');
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
     * @param array $params - request params
     * @return boolean
     */
    private function checkAdvRespChecksum($params = [])
    {
        $settings = $this->getPluginSettings();
        
        if(empty($params)) {
            $params = $this->Request()->getParams();
            $status = $this->get_request_status();
            $advanceResponseChecksum = $this->Request()->getParam('advanceResponseChecksum');
        }
        else {
            $status = $this->get_request_status($params);
            $advanceResponseChecksum = $params['advanceResponseChecksum'];
        }
        
        if(!$settings) {
            $this->createLog($settings, 'checkAdvRespChecksum() can not get plugin settings!: ');
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
            'hash' => $settigns['swagSCHash'],
            'secret' => $settigns['swagSCSecret'],
            'test_mode' => $settigns['swagSCTestMode'],
            'save_logs' => $settigns['swagSCSaveLogs'],
        ];
    }
    
    private function createLog($data, $title = '')
    {
        if($this->save_logs === true || $this->Request()->getParam('save_logs') == 'yes') {
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
                    die('@');
                    echo
                        '<script>'
                            .'error.log("Log file was not created, by reason: '.$exc->getMessage().'");'
                            .'console.log("Log file was not created, by reason: '.$data.'");'
                        .'</script>';
                }
            }
        }
    }
}
