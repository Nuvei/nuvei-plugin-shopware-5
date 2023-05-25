<?php

/**
 * @author Nuvei
 */

use Shopware\Components\CSRFGetProtectionAware;
use SwagNuvei\Config;
use SwagNuvei\Logger;
use SwagNuvei\Nuvei;

class Shopware_Controllers_Backend_NuveiOrderEdit extends Shopware_Controllers_Backend_ExtJs implements CSRFGetProtectionAware
{
    private $settings;
    
    /**
     * Return enabled public methods/action/
     * 
     * @return array
     */
    public function getCSRFProtectedActions()
    {
        return ['process', 'getSCOrderData', 'getSCOrderNotes'];
    }
    
    /**
     * This method is called with Ajax.
     * When load order, check for SC data to decide will we show Nuvei settings or not.
     */
    public function getSCOrderDataAction()
    {
        $this->getPluginSettings();
        Logger::writeLog($this->settings, 'getSCOrderDataAction()');
        
        $order_id               = (int) $this->request->getParam('orderId');
        $nuvei_data_last        = [];
        $nuvei_data_last_tr_id  = 0;
        $refunds                = [];
        $notes                  = [];
        
        $order_status = $this->container->get('db')->fetchOne(
            "SELECT status FROM s_order WHERE id = " . $order_id);
        
        if (!is_numeric($order_status)) {
            $msg = 'Problem with the Order Status.';
            
            Logger::writeLog($this->settings, $order_status, $msg, 'WARN');
            exit(json_encode([
                'status'    => 'error',
                'msg'       => $msg
            ]));
        }
        
        // get Nuvei data for the Order
        $nuvei_data_str = $this->container->get('db')->fetchOne(
            "SELECT nuvei_data FROM nuvei_orders WHERE order_id = " . $order_id);
        
        if(!$nuvei_data_str) {
            $msg = 'Missing Nuvei Order data for Order ' . $order_id;
            
            Logger::writeLog($this->settings, $nuvei_data_str, $msg, 'WARN');
            exit(json_encode([
                'status'    => 'error',
                'msg'       => $msg
            ]));
        }
        
        $nuvei_data = json_decode($nuvei_data_str, true);
        
        if (empty($nuvei_data)) {
            $msg = 'Nuvei Order data is empty for Order ' . $order_id;
            
            Logger::writeLog($this->settings, $nuvei_data, $msg);
            exit(json_encode([
                'status'    => 'error',
                'msg'       => $msg
            ]));
        }
        
        // prepare data
        foreach (array_reverse($nuvei_data, true) as $tr_id => $transaction) {
            if (empty($nuvei_data_last)) {
                $nuvei_data_last        = $transaction;
                $nuvei_data_last_tr_id  = $tr_id;
            }
            
            if (in_array($transaction['transactionType'], ['Refund', 'Credit'])) {
                $refunds[$tr_id] = $transaction;
            }
            
            $notes[$tr_id] = [
                'date'      => $transaction['responseTimeStamp'],
                'comment'   => $transaction['comment']
            ];
        }
        
        ksort($refunds);
        ksort($notes);
        
        $enable_void = 0;
        if(in_array($order_status, [Config::SC_ORDER_IN_PROGRESS, Config::SC_ORDER_COMPLETED])) {
            $enable_void = $nuvei_data_last_tr_id;
        }
        
        $enable_refund = 0;
        if ($order_status == Config::SC_ORDER_COMPLETED
            && isset($nuvei_data_last['payment_method'])
            && in_array($nuvei_data_last['payment_method'], Config::NUVEI_REFUND_PAYMETNS)
        ) {
            $enable_refund = $nuvei_data_last_tr_id;
        }
        
        $enable_settle = 0;
        if ($order_status == Config::SC_ORDER_PART_COMPLETED
            && $nuvei_data_last['transactionType'] == 'Auth'
        ) {
            $enable_settle = $nuvei_data_last_tr_id;
        }
        
        exit(json_encode([
            'status'            => 'success',
            'scEnableVoid'      => $enable_void,
            'scEnableRefund'    => $enable_refund,
            'scEnableSettle'    => $enable_settle,
            'refunds'           => $refunds,
            'notes'             => $notes,
        ]));
    }
    
    /**
     * Catch and process SC Order buttons actions.
     * In the JS there is a problem to create dynamic methods names.
     */
    public function processAction()
    {
        $this->getPluginSettings();
        Logger::writeLog($this->settings, $this->request->getParams(), 'processAction()');
        
        $order_id = (int) $this->request->getParam('orderId');
        
        if(empty($order_id)) {
            $msg = 'The Order ID is empty.';
            
            Logger::writeLog($this->settings, $msg);
            exit(json_encode([
                'status'    => 'error',
                'msg'       => $msg
            ]));
        }
        
        // get Nuvei data for the Order
        $nuvei_data_str = $this->container->get('db')->fetchOne(
            "SELECT nuvei_data FROM nuvei_orders WHERE order_id = " . $order_id);
        
        if(!$nuvei_data_str) {
            $msg = 'Missing Nuvei Order data for Order ' . $order_id;
            
            Logger::writeLog($this->settings, $nuvei_data_str, $msg, 'WARN');
            exit(json_encode([
                'status'    => 'error',
                'msg'       => $msg
            ]));
        }
        
        $nuvei_data = json_decode($nuvei_data_str, true);
        
        if (empty($nuvei_data)) {
            $msg = 'Nuvei Order data is empty for Order ' . $order_id;
            
            Logger::writeLog($this->settings, $nuvei_data, $msg);
            exit(json_encode([
                'status'    => 'error',
                'msg'       => $msg
            ]));
        }
        // /get Nuvei data for the Order
        
        // get Order data
        $resp = $this
            ->container->get('db')
            ->fetchAll("SELECT ordernumber, invoice_amount, currency, status FROM s_order WHERE id = " . $order_id);
        
        if(!$resp) {
            $msg = 'There is no Order data.';
            
            Logger::writeLog($this->settings, $msg);
            echo json_encode([
                'status'    => 'error',
                'msg'       => $msg
            ]);
            
            exit;
        }
        
        $order_info = $resp[0];
        // /get Order data
        
        if($this->request->getParam('scAction') == 'refund') {
            $this->orderRefund($nuvei_data, $order_info, $this->request->getParam('refundAmount', 0));
        }
        
        if(in_array($this->request->getParam('scAction'), ['settle', 'void'])) {
            $this->orderSettleAndVoid($nuvei_data, $order_info, $this->request->getParam('scAction'));
        }
        
        Logger::writeLog($this->settings, 'Unknown action');
        
        exit(json_encode([
            'status'    => 'error',
            'msg'       => 'Unknown Ajax action.'
        ]));
    }
    
    /**
     * Refund an order after click on refund button
     * 
     * @param array $nuvei_data
     * @param array $order_info
     * @param float $amount
     */
    private function orderRefund($nuvei_data, $order_info, $amount)
    {
        Logger::writeLog($this->settings, $nuvei_data, 'orderSettleAndVoid');
        
        if (empty($amount)) {
            $msg = 'The Refund amount can not be 0/empty.';
            
            Logger::writeLog($this->settings, $amount, $msg);
            exit(json_encode([
                'status'    => 'error',
                'msg'       => $msg
            ]));
        }
        
        $order_data_to_use = [];
        
        // start from last transaction
        foreach (array_reverse($nuvei_data, true) as $trId => $data) {
            if (in_array($data['transactionType'], ['Sale', 'Settle'])
            ) {
                $order_data_to_use                  = $data;
                $order_data_to_use['TransactionID'] = $trId;
                break;
            }
        }
        
        $time       = date('YmdHis', time());
        $notify_url = Shopware()->Front()->Router()->assemble([
            'module'        => 'frontend', 
            'controller'    => 'Nuvei', 
            'action'        => 'index'
        ]);
        
        $params = array(
            'clientRequestId'       => $time . '_' . $order_info['ordernumber'] . '_' . uniqid(),
//            'clientUniqueId'        => $refund['id'],
            'amount'                => $amount,
//            'currency'              => $order_info['currency'],
            'relatedTransactionId'  => $order_data_to_use['TransactionID'],
//            'authCode'              => $order_data_to_use['AuthCode'],
            'urlDetails'            => array('notificationUrl' => $notify_url),
            'url'                   => $notify_url, // custom for auto checksum calculation
            'webMasterId'           => Config::NUVEI_WEB_MASTER_ID
                . $this->container->getParameter('shopware.release.version')
                . '; Plugin v' . Config::NUVEI_PLUGIN_VERSION,
        );
        
        $checksum_params = ['merchantId', 'merchantSiteId', 'clientRequestId', 'clientUniqueId', 'amount', 
//            'currency', 
            'relatedTransactionId', 
//            'authCode', 
            'url', 'timeStamp'];
        
        $resp = Nuvei::call_rest_api('refundTransaction', $params, $checksum_params, $this->settings);
        exit(json_encode($resp));
        
        
        
//        $clientUniqueId = uniqid();
//        $router         = Shopware()->Front()->Router();
//        $conn           = $this->container->get('db');
//        $order_id       = (int) $this->Request()->getParam('orderId');
//        $notify_url     = $router->assemble(['controller' => 'Nuvei', 'action' => 'getDmn'])
//            . '?save_logs=' . $settings['swagSCSaveLogs'];
//            
//        $order_data = current($conn->fetchAll("SELECT invoice_amount, currency, status FROM s_order WHERE id = " . $order_id));
//        
//        if($order_data['status'] != Config::SC_ORDER_COMPLETED) {
//            echo json_encode([
//                'status' => 'error',
//                'msg' => 'To create Refund, the Order must be Completed.'
//            ]);
//            exit;
//        }
//        
//        // get refunds
//        $refunded_amount    = 0;
//        $order_refunds      = $conn->fetchAll("SELECT * FROM swag_safecharge_refunds WHERE order_id = " . $order_id);
//        
//        if($order_refunds) {
//            foreach($order_refunds as $refund) {
//                $refunded_amount += $refund['amount'];
//            }
//        }
//        // get refunds END
//        
//        $refund_amount = number_format($this->request->getParam('refundAmount'), 2, '.', '');
//        
//        if(($refund_amount + $refunded_amount) > $order_data['invoice_amount']) {
//            echo json_encode([
//                'status' => 'error',
//                'msg' => 'Refund request Amount is too big.'
//            ]);
//            exit;
//        }
//        
//        $json_arr = Nuvei::refund_order(
//            $settings
//            ,array(
//                'id' => $clientUniqueId,
//                'amount' => $this->request->getParam('refundAmount'),
//                'reason' => '' // no reason field
//            )
//            ,array(
//                'order_tr_id' => $payment_custom_fields['relatedTransactionId'],
//                'auth_code' => $payment_custom_fields['authCode'],
//            )
//            ,$order_data['currency']
//            ,$notify_url
//        );
//        
//        if(!$json_arr) {
//            echo json_encode([
//                'status' => 'error',
//                'msg' => 'There is an error with the request response.'
//            ]);
//            exit;
//        }
//        
//        // in case we have message but without status
//        if(!isset($json_arr['status']) && isset($json_arr['msg'])) {
//            // save response message in the History
//            $msg = 'Request Refund #' . $clientUniqueId . ' problem: ' . $json_arr['msg'];
//            
//            // to try refund the Order must be completed
//            $order_module->setOrderStatus($order_id, Config::SC_ORDER_COMPLETED, false, $msg);
//            $order_module->setPaymentStatus($order_id, Config::SC_ORDER_COMPLETED, false, $msg);
//            
//            echo json_encode([
//                'status' => 'error',
//                'msg' => $msg
//            ]);
//            exit;
//        }
//        
//        $refund_url = SC_TEST_REFUND_URL;
//        $cpanel_url = SC_TEST_CPANEL_URL;
//
//        if($settings['test'] == 'no') {
//            $refund_url = SC_LIVE_REFUND_URL;
//            $cpanel_url = SC_LIVE_CPANEL_URL;
//        }
//        
//        $msg = '';
//        $error_note = 'Request Refund #' . $clientUniqueId . ' fail, if you want login into <i>' . $cpanel_url
//            . '</i> and refund Transaction ID ' . $payment_custom_fields[SC_GW_TRANS_ID_KEY];
//
//        if(!is_array($json_arr)) {
//            parse_str($resp, $json_arr);
//        }
//
//        if(!is_array($json_arr)) {
//            $msg = 'Invalid API response. ' . $error_note;
//
//            $order_module->setOrderStatus($order_id, Config::SC_ORDER_COMPLETED, false, $msg);
//            $order_module->setPaymentStatus($order_id, Config::SC_ORDER_COMPLETED, false, $msg);
//            
//            echo json_encode([
//                'status' => 'error',
//                'msg' => $msg
//            ]);
//            exit;
//        }
//        
//        // the status of the request is ERROR
//        if(@$json_arr['status'] == 'ERROR') {
//            $msg = 'Request ERROR - "' . $json_arr['reason'] .'" '. $error_note;
//            
//            $order_module->setOrderStatus($order_id, Config::SC_ORDER_COMPLETED, false, $msg);
//            $order_module->setPaymentStatus($order_id, Config::SC_ORDER_COMPLETED, false, $msg);
//            
//            echo json_encode([
//                'status' => 'error',
//                'msg' => $msg
//            ]);
//            exit;
//        }
//        
//        // if request success, we will wait for DMN
//        $msg = 'Request Refund #' . $clientUniqueId . ', was sent. Please, wait for DMN!';
//        
//        $order_module->setOrderStatus($order_id, Config::SC_ORDER_COMPLETED, false, $msg);
//        $order_module->setPaymentStatus($order_id, Config::SC_ORDER_COMPLETED, false, $msg);
//
//        echo json_encode([
//            'status' => 'success',
//            'msg' => $msg
//        ]);
//        exit;
    }
    
    /**
     * Settle or Void an order.
     * 
     * @param array $nuvei_data Nuvei Order data.
     * @param array $order_info Order data.
     * @param string $action    REST method to use.
     */
    private function orderSettleAndVoid(array $nuvei_data, $order_info, $action)
    {
        Logger::writeLog($this->settings, [$action, $nuvei_data], 'orderSettleAndVoid');
        
        $order_data_to_use = [];
        
        // start from last transaction
        foreach (array_reverse($nuvei_data, true) as $trId => $data) {
            if ('void' == $action 
                && in_array($data['transactionType'], ['Sale', 'Settle', 'Auth'])
            ) {
                $order_data_to_use                  = $data;
                $order_data_to_use['TransactionID'] = $trId;
                break;
            }
            elseif ('Auth' == $data['transactionType']) {
                $order_data_to_use                  = $data;
                $order_data_to_use['TransactionID'] = $trId;
                break;
            }
        }
        
        $time       = date('YmdHis', time());
        $notify_url = Shopware()->Front()->Router()->assemble([
            'module'        => 'frontend', 
            'controller'    => 'Nuvei', 
            'action'        => 'index'
        ]);
            
        $params = [
            'clientRequestId'       => $time . '_' . $order_info['ordernumber'] . '_' . uniqid(),
            'amount'                => number_format($order_info['invoice_amount'], 2, '.', ''),
            'currency'              => $order_info['currency'],
            'relatedTransactionId'  => $order_data_to_use['TransactionID'],
            'authCode'              => $order_data_to_use['AuthCode'],
            'urlDetails'            => array('notificationUrl' => $notify_url),
            'url'                   => $notify_url, // custom for auto checksum calculation
            'webMasterId'           => Config::NUVEI_WEB_MASTER_ID
                . $this->container->getParameter('shopware.release.version')
                . '; Plugin v' . Config::NUVEI_PLUGIN_VERSION,
        ];
            
        $checksum_params = ['merchantId', 'merchantSiteId', 'clientRequestId', 'clientUniqueId', 'amount', 'currency', 'relatedTransactionId', 'authCode', 'url', 'timeStamp'];
        
        $resp = Nuvei::call_rest_api($action . 'Transaction', $params, $checksum_params, $this->settings);
        exit(json_encode($resp));
    }

    private function getPluginSettings()
    {
        if(isset($this->settings)) {
            return $this->settings;
        }
        
        $this->settings = $this->container->get('shopware.plugin.cached_config_reader')
            ->getByPluginName('SwagNuvei');
    }
}
