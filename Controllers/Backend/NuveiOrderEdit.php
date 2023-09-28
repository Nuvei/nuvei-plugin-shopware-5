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
        $refunded_amount        = 0;
        $notes                  = [];
        
        $query = "SELECT o.status AS status, o.invoice_amount AS invoice_amount, o.cleared AS cleared "
                . "FROM s_order AS o "
                . "LEFT JOIN s_core_paymentmeans AS pm ON o.paymentID = pm.id "
                . "WHERE o.id = " . $order_id . " "
                    . "AND pm.name = '" . Config::NUVEI_CODE . "'"
                ;
        
        $order_data = $this->container->get('db')->fetchAll($query);
        
//        Logger::writeLog($this->settings, [$query, $order_data]);
        
        // the Order does not belongs to Nuvei
        if (empty($order_data)) {
            exit(json_encode([
                'status'    => 'success',
                'refunds'   => [],
                'notes'     => [],
            ]));
        }
        
        Logger::writeLog($this->settings, $order_data);
        
        if (!is_array($order_data)
            || empty($order_data[0]['status'])
            || !is_numeric($order_data[0]['status'])
            || empty($order_data[0]['invoice_amount'])
            || !is_numeric($order_data[0]['invoice_amount'])
        ) {
            $msg = 'Problem with the Order Status.';
            
            Logger::writeLog($this->settings, $order_data, $msg, 'WARN');
            exit(json_encode([
                'status'    => 'error',
                'msg'       => $msg
            ]));
        }
        
        $order_status   = $order_data[0]['status'];
        $payment_status = $order_data[0]['cleared'];
        $order_amount   = $order_data[0]['invoice_amount'];
        
//        Logger::writeLog($this->settings, $order_data);
//        die;
//        
//        if (!is_numeric($order_status)) {
//            $msg = 'Problem with the Order Status.';
//            
//            Logger::writeLog($this->settings, $order_status, $msg, 'WARN');
//            exit(json_encode([
//                'status'    => 'error',
//                'msg'       => $msg
//            ]));
//        }
        
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
                $refunds[$tr_id]    = $transaction;
                $refunded_amount    += $transaction['totalAmount'];
            }
            
            $notes[$tr_id] = [
                'date'      => $transaction['responseTimeStamp'],
                'comment'   => $transaction['comment']
            ];
        }
        
        ksort($refunds);
        ksort($notes);
        
        Logger::writeLog($this->settings, $refunds);
        
        $enable_void = 0;
//        if(in_array($order_status, [Config::SC_ORDER_OPEN, Config::SC_ORDER_COMPLETED]) 
        if(in_array($payment_status, [Config::SC_PAYMENT_OPEN, Config::SC_ORDER_PAID]) 
            && empty($refunds)
        ) {
            $enable_void = $nuvei_data_last_tr_id;
        }
        
        $enable_refund = 0;
        if ($order_status == Config::SC_ORDER_COMPLETED
            && isset($nuvei_data_last['payment_method'])
            && in_array($nuvei_data_last['payment_method'], Config::NUVEI_REFUND_PAYMETNS)
            && $refunded_amount < $order_amount
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
            'amount'                => $amount,
            'relatedTransactionId'  => $order_data_to_use['TransactionID'],
            'urlDetails'            => array('notificationUrl' => $notify_url),
            'url'                   => $notify_url, // custom for auto checksum calculation
            'webMasterId'           => Config::NUVEI_WEB_MASTER_ID
                . $this->container->getParameter('shopware.release.version')
                . '; Plugin v' . Config::NUVEI_PLUGIN_VERSION,
        );
        
        $checksum_params = ['merchantId', 'merchantSiteId', 'clientRequestId', 'clientUniqueId', 'amount', 'relatedTransactionId', 'url', 'timeStamp'];
        
        $resp = Nuvei::call_rest_api('refundTransaction', $params, $checksum_params, $this->settings);
        exit(json_encode($resp));
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
