<?php

/**
 * @author Nuvei
 */

use Shopware\Components\CSRFWhitelistAware;
use SwagNuvei\Config;
use SwagNuvei\Logger;
use SwagNuvei\Nuvei;

class Shopware_Controllers_Frontend_Nuvei extends Enlight_Controller_Action implements CSRFWhitelistAware
{
    private $logs_path      = '';
    private $plugin_dir     = '';
    private $curr_dmn_note  = '';
    private $nuvei_data     = [];
    private $nuvei_notes    = [];
    private $totalCurrAlert = false;
    private $order_data;
    private $settings;
    private $params;
    private $sys_config;

    public function getWhitelistedCSRFActions(): array {
        return ['cancelAction', 'indexAction'];
    }
    
    public function indexAction()
    {
        $this->getPluginSettings();
        
        $this->params       = $this->Request()->getParams();
        $this->sys_config   = require 'config.php';
        
        if (!empty($this->params['advanceResponseChecksum'])) {
            $this->getDmn();
        }
        
        return $this->redirect(['controller' => 'checkout']);
    }
    
    // set template here
    public function preDispatch()
    {
        $plugin = $this->get('kernel')->getPlugins()['SwagNuvei'];
        
        $this->get('template')->addTemplateDir($plugin->getPath() . '/Resources/views/');
        
//        $this->plugin_dir = dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR;
        
//        require Config::NUVEI_PLUGIN_DIR . 'sc_config.php'; // load SC config file
    }
    
    /**
     * Executes when customer is redirected to error url.
     */
    public function cancelAction()
    {
        $this->getPluginSettings();
        
        Logger::writeLog($this->settings, $this->Request()->getParams(), 'cancelAction');
        
        if($this->settings['swagSCTestMode']) {
            $this->View()->assign(['message' => $this->Request()->getParam('message')]);
        }
    }

    /**
     * Process DMN
     */
    private function getDmn()
    {
        Logger::writeLog($this->settings, $this->params, 'getDMNAction');
        
        // exit
        if (!empty($this->params['type']) 
            && 'CARD_TOKENIZATION' == $this->params['type']
        ) {
            $msg = 'DMN report - this is Card Tokenization DMN.';
            Logger::writeLog($this->settings, $msg);
            exit($msg);
        }
        
        $req_status = $this->get_request_status($this->params);
        
        // exit
        if ('pending' == strtolower($req_status)) {
            $msg = 'Pending DMN, waiting for the next.';

            Logger::writeLog($this->settings, $msg);
            exit($msg);
        }
        
        // exit
        if(!$this->checkAdvRespChecksum()) {
            $msg = 'DMN report: You receive DMN from not trusted source. The process ends here.';
            
            Logger::writeLog($this->settings, $msg);
            exit($msg);
        }
        
        $connection     = $this->container->get('dbal_connection');
        $trId           = (int) $this->params['TransactionID'];
        $tryouts        = 0;
        $max_tryouts    = 1 == $this->settings['swagSCTestMode'] ? 10 : 4;
        $wait_type      = 3; // seconds
        
        // primary transactions
        if (in_array($this->params['transactionType'], ['Sale', 'Auth'])) {
            $query =
                'SELECT id, ordernumber, status, cleared, invoice_amount, currency '
                . 'FROM s_order '
                . 'WHERE transactionID = ' . (int) $trId;
        }
        // secondary transaction
        else {
            $clientRequestId_arr = explode('_', $this->params['clientRequestId']);
            
            $query =
                'SELECT id, ordernumber, status, cleared, invoice_amount, currency, transactionID '
                . 'FROM s_order '
                . 'WHERE ordernumber = ' . (int) $clientRequestId_arr[1];
        }
        
        do {
            $tryouts++;
            
            // cleared is the payment status
            $res = $connection->fetchAll($query);
            
            if (!empty($res) && is_array($res)) {
                $this->order_data = current($res);
            }
            
            if (empty($this->order_data)) {
                Logger::writeLog($this->settings, 'Can not find Order data. Try ' . $tryouts);
                sleep($wait_type);
            }
        }
        while(empty($this->order_data) && $tryouts < $max_tryouts);
        
        // exit
        if (empty($this->order_data)) {
            $msg = 'Order data was not found.';
            http_response_code(400);
            
            $this->createAutoVoid();
            
            Logger::writeLog($this->settings, [$tryouts, $this->order_data], $msg);
            exit($msg);
        }
        
        Logger::writeLog($this->settings, $this->order_data, 'order_data');
        
        // keep from repeating DMNs
        $res = $connection->fetchAll('SELECT nuvei_data FROM nuvei_orders WHERE order_id = ' 
            . (int) $this->order_data['id']);
        
        if (!empty($res)) {
            $row                = current($res);
            $this->nuvei_data   = json_decode($row['nuvei_data'], true);
            
            // exit
            if (array_key_exists($this->params['TransactionID'], $this->nuvei_data)
                && $this->nuvei_data[$this->params['TransactionID']]['Status'] == $req_status
            ) {
                $msg = 'Information for same DMN already exists. Stop the proccess.';
            
                Logger::writeLog($this->settings, $msg);
                exit($msg);
            }
        }
        // /keep from repeating DMNs
        
        // call DMN type functions
        $this->authDmn();
        $this->settleDmn();
        $this->saleDmn();
        $this->refundDmn();
        $this->voidDmn();
        
        $msg = 'Not Recognized DMN.';
            
        Logger::writeLog($this->settings, $msg);
        exit($msg);
    }
    
    private function createAutoVoid()
    {
        $order_request_time	= $this->params['customField1']; // time of create/update order
        $curr_time          = time();
        $transactionType    = $this->params['transactionType'];
        
        Logger::writeLog(
            $this->settings,
            [
                $order_request_time,
                $transactionType,
                $curr_time
            ],
            'create_auto_void'
        );
        
        // not allowed Auto-Void
        if (empty($order_request_time)) {
            Logger::writeLog(
                $this->settings,
                $order_request_time,
                'There is problem with $order_request_time. End process.',
                'WARINING'
            );
            return;
        }
        
        if (!in_array($transactionType, array('Auth', 'Sale'), true)) {
            Logger::writeLog($this->settings, $transactionType, 'The transacion is not in allowed range.');
            return;
        }
        
        if ($curr_time - $order_request_time <= 1800) {
            Logger::writeLog($this->settings, "Let's wait one more DMN try.");
            return;
        }
        // /not allowed Auto-Void
        
        $notify_url     = $this->Front()->Router()->assemble(['controller' => 'Nuvei', 'action' => 'index']);
        $void_params    = [
            'clientUniqueId'        => date('YmdHis') . '_' . uniqid(),
            'amount'                => (string) $this->params['totalAmount'],
            'currency'              => $this->params['currency'],
            'relatedTransactionId'  => $this->params['TransactionID'],
            'url'                   => $notify_url,
            'urlDetails'            => ['notificationUrl' => $notify_url],
            'customData'            => 'This is Auto-Void transaction',
        ];

        $resp = Nuvei::call_rest_api(
            'voidTransaction',
            $void_params,
            ['merchantId', 'merchantSiteId', 'clientRequestId', 'clientUniqueId', 'amount', 'currency', 'relatedTransactionId', 'url', 'timeStamp'],
            $this->settings
        );

        // Void Success
        if (!empty($resp['transactionStatus'])
            && 'APPROVED' == $resp['transactionStatus']
            && !empty($resp['transactionId'])
        ) {
            $msg = 'The searched Order does not exists, a Void request was made for this Transacrion.';
            Logger::writeLog($this->settings, $msg);
            
            http_response_code(200);
            exit($msg);
        }
        
        return;
    }
    
    /**
     * Proccess Sale DMNs.
     */
    private function saleDmn()
    {
        if('Sale' != $this->params['transactionType']) {
            return;
        }
        
        if (!in_array($this->order_data['status'], [Config::SC_ORDER_IN_PROGRESS, Config::SC_ORDER_OPEN, Config::SC_ORDER_NOT_VISIBLE])) {
            $msg = 'The current order status can not be replaced from Sale DMN.';
            
            Logger::writeLog($this->settings, $msg);
            exit($msg);
        }
        
        $this->changeOrderStatus();
        $this->saveNuveiData($this->order_data);
        
        $msg = 'DMN received.';
            
        Logger::writeLog($this->settings, $msg);
        exit($msg);
    }
    
    private function authDmn()
    {
        if('Auth' != $this->params['transactionType']) {
            return;
        }
        
         if (in_array($this->order_data['status'], [Config::SC_ORDER_COMPLETED])) {
            $msg = 'The current order status can not be replaced from Auth DMN.';
            
            Logger::writeLog($this->settings, $msg);
            exit($msg);
        }
        
        $this->changeOrderStatus();
        $this->saveNuveiData($this->order_data);
        
        $msg = 'DMN received.';
            
        Logger::writeLog($this->settings, $msg);
        exit($msg);
    }
    
    private function settleDmn()
    {
        if('Settle' != $this->params['transactionType']) {
            return;
        }
        
        if (Config::SC_ORDER_PART_COMPLETED != $this->order_data['status']
            || Config::SC_PAYMENT_OPEN != $this->order_data['cleared']
        ) {
            $msg = 'The current order status can not be replaced from Settle DMN.';
            
            Logger::writeLog($this->settings, $msg);
            exit($msg);
        }
        
        $this->changeOrderStatus();
        $this->saveNuveiData($this->order_data);
        
        $msg = 'DMN received.';
            
        Logger::writeLog($this->settings, $msg);
        exit($msg);
    }
    
    private function refundDmn()
    {
        if(!in_array($this->params['transactionType'], ['Credit', 'Refund'])) {
            return;
        }
        
        if (!in_array(
                $this->order_data['cleared'], 
//                [Config::SC_PARTIALLY_REFUNDED, Config::SC_ORDER_PAID]
                [Config::SC_REFUNDED_ACCEPTED, Config::SC_ORDER_PAID]
            )
            || Config::SC_ORDER_NOT_VISIBLE == $this->order_data['status']
        ) {
            $msg = 'The current order status can not be Refunded.';
            
            Logger::writeLog($this->settings, $msg);
            exit($msg);
        }
        
        $this->changeOrderStatus();
        $this->saveNuveiData($this->order_data);
        
        $msg = 'DMN received.';
            
        Logger::writeLog($this->settings, $msg);
        exit($msg);
    }
    
    private function voidDmn()
    {
        if('Void' != $this->params['transactionType']) {
            return;
        }
        
        if ( in_array(
                $this->order_data['cleared'], 
//                [Config::SC_COMPLETE_REFUNDED, Config::SC_PARTIALLY_REFUNDED, Config::SC_PAYMENT_CANCELLED]
                [Config::SC_REFUNDED_ACCEPTED, Config::SC_PAYMENT_CANCELLED]
            )
            || Config::SC_ORDER_NOT_VISIBLE == $this->order_data['status']
        ) {
            $msg = 'The current order status can not be replaced from Void DMN.';
            
            Logger::writeLog($this->settings, $msg);
            exit($msg);
        }
        
        $this->changeOrderStatus();
        $this->saveNuveiData($this->order_data);
        
        $msg = 'DMN received.';
            
        Logger::writeLog($this->settings, $msg);
        exit($msg);
    }
    
    private function getPluginSettings()
    {
        if(isset($this->settings)) {
            return $this->settings;
        }
        
        $this->settings = $this->container->get('shopware.plugin.cached_config_reader')
            ->getByPluginName('SwagNuvei', Shopware()->Shop());
    }
    
    /**
     * @return boolean
     */
    private function checkAdvRespChecksum()
    {
        $status                     = $this->get_request_status();
        $advanceResponseChecksum    = $this->params['advanceResponseChecksum'];
        
        $str = hash(
            $this->settings['swagSCHash'],
            trim($this->settings['swagSCSecret'])
                . $this->params['totalAmount'] 
                . $this->params['currency']
                . $this->params['responseTimeStamp'] 
                . $this->params['PPP_TransactionID']
                . $status . $this->params['productId']
        );
        
        if ($str == $advanceResponseChecksum) {
            return true;
        }
        
        return false;
    }
    
    /**
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
    
    /**
     * We must call this method after changeOrderStatus,
     * because we need the DMN note.
     * 
     * @param int $order
     * @return void
     */
    private function saveNuveiData($order)
    {
        Logger::writeLog($this->settings, 'saveNuveiData');
        
        $oder_id = 0;
        
        // get order id
        if(!empty($order['id'])) {
            $oder_id    = $order['id'];
        }
        elseif(!empty($order['ordernumber'])) {
            $data = $this->container->get('dbal_connection')->fetchAll(
                "SELECT id FROM s_order WHERE s_order.ordernumber = " . (int) $order['ordernumber']);
            
            $oder_id = $data['id'];
        }
        
        if ($oder_id == 0) {
            Logger::writeLog($this->settings, 'Can not get Order ID.');
            return;
        }
        
        $this->nuvei_data[$this->params['TransactionID']] = [];
        
        if(isset($this->params['transactionType'])) {
            $this->nuvei_data[$this->params['TransactionID']]['transactionType']
                = $this->params['transactionType'];
        }
        if(isset($this->params['Status'])) {
            $this->nuvei_data[$this->params['TransactionID']]['Status']
                = strtolower($this->params['Status']);
        }
        if(isset($this->params['AuthCode'])) {
            $this->nuvei_data[$this->params['TransactionID']]['AuthCode']
                = $this->params['AuthCode'];
        }
        if(isset($this->params['relatedTransactionId'])) {
            $this->nuvei_data[$this->params['TransactionID']]['relatedTransactionId'] 
                = $this->params['relatedTransactionId'];
        }
        if(isset($this->params['payment_method'])) {
            $this->nuvei_data[$this->params['TransactionID']]['payment_method'] 
                = $this->params['payment_method'];
        }
        if(isset($this->params['currency'])) {
            $this->nuvei_data[$this->params['TransactionID']]['currency'] 
                = $this->params['currency'];
        }
        if(isset($this->params['totalAmount'])) {
            $this->nuvei_data[$this->params['TransactionID']]['totalAmount'] 
                = (float) $this->params['totalAmount'];
        }
        if(isset($this->params['responseTimeStamp'])) {
            $this->nuvei_data[$this->params['TransactionID']]['responseTimeStamp'] 
                = $this->params['responseTimeStamp'];
        }
        
        if(!empty($this->params['clientRequestId'])) {
            $this->nuvei_data[$this->params['TransactionID']]['clientRequestId'] 
                = $this->params['clientRequestId'];
        }
        elseif(!empty($this->params['merchant_unique_id'])) {
            $this->nuvei_data[$this->params['TransactionID']]['clientRequestId'] 
                = $this->params['merchant_unique_id'];
        }
        
        // save the original total and currency
        $this->nuvei_data[$this->params['TransactionID']]['originalTotal']      = $this->params['customField2'];
        $this->nuvei_data[$this->params['TransactionID']]['originalCurrency']   = $this->params['customField3'];
        $this->nuvei_data[$this->params['TransactionID']]['totalCurrAlert']     = $this->totalCurrAlert;
        
        $this->nuvei_data[$this->params['TransactionID']]['comment'] = $this->curr_dmn_note;
        
        // fill Nuvei Order data
        $db_data = json_encode($this->nuvei_data);
        
        $this->container->get('dbal_connection')->query(
            'INSERT INTO nuvei_orders (order_id, nuvei_data) '
            . 'VALUES('. (int) $oder_id .', \''. $db_data .'\') '
            . 'ON DUPLICATE KEY UPDATE nuvei_data = \''. $db_data .'\' '
        );
        
//        Logger::writeLog($this->settings, 'Update nuvei_orders result.');
    }
    
    /**
     * Change the Order statuses by the DMN data.
     */
    private function changeOrderStatus()
    {
        $status = $this->get_request_status($this->params);
        
        Logger::writeLog(
            $this->settings, 
            [
                'Order id'  => $this->order_data['id'],
                'Status'    => $status
            ], 
            'changeOrderStatus'
        );
        
        $order_module   = Shopware()->Modules()->Order();
        $ord_status     = Config::SC_ORDER_IN_PROGRESS; // default
        $payment_status = Config::SC_PAYMENT_OPEN; // default
        $send_message   = true;
        $dmn_amount     = round((float) $this->params['totalAmount'], 2);
        $order_amount   = round((float) $this->order_data['invoice_amount'], 2);
        $message        = '';
        
        if(isset($this->sys_config['mail']['disabled']) 
            && $this->sys_config['mail']['disabled'] == 1
        ) {
            $send_message = false;
        }

		$gw_data = $this->params['transactionType'] . ' request. '
			. 'Response status: ' . $status . '. '
			. 'Payment Method: ' . $this->params['payment_method'] . '. '
            . 'Transaction ID: ' . $this->params['TransactionID'] . '. '
            . 'Related Transaction ID: ' . $this->params['relatedTransactionId'] . '. '
            . 'Transaction Amount: ' . number_format($this->params['totalAmount'], 2, '.', '') 
                . ' ' . $this->params['currency'] . '. ';
        
        switch($status) {
            case 'CANCELED':
                $message = $gw_data;
                
                if (in_array($this->params['transactionType'], array('Auth', 'Settle', 'Sale'))) {
					$ord_status = Config::SC_ORDER_REJECTED;
				}
                break;
            
            case 'APPROVED':
                // Void
                if ($this->params['transactionType'] == 'Void') {
                    $message        = $gw_data;
                    $ord_status     = Config::SC_ORDER_REJECTED;
                    $payment_status = Config::SC_PAYMENT_CANCELLED;
                }
                // Refund
                elseif (in_array($this->params['transactionType'], array( 'Credit', 'Refund' ))) {
                    $message            = $gw_data;
					$ord_status         = Config::SC_ORDER_COMPLETED;
                    $refunded_amount    = 0;
                    
                    // check for partial refund
                    foreach ($this->nuvei_data['nuvei_data'] as $transaction) {
                        if (!in_array($transaction['transactionType'], ['Credit', 'Refund'])) {
                            continue;
                        }
                        
                        $refunded_amount += $transaction['totalAmount'];
                    }
                    
                    if ($refunded_amount > 0) {
                        $message .= 'Refund Amount: ' . number_format($this->params['totalAmount'], 2, '.', '') 
                            . ' ' . $this->params['currency'] . '. ';
                    }
                    
//                    $payment_status = $order_amount <= $refunded_amount 
//                        ? Config::SC_COMPLETE_REFUNDED : Config::SC_PARTIALLY_REFUNDED;
                    $payment_status = Config::SC_REFUNDED_ACCEPTED;
                    
                }
                // Settle/Sale
                elseif (in_array($this->params['transactionType'], array( 'Settle', 'Sale'))) {
                    $message        = $gw_data;
                    $ord_status     = Config::SC_ORDER_COMPLETED;
                    $payment_status = Config::SC_ORDER_PAID;
                }
                // Auth
                elseif ('Auth' == $this->params['transactionType']) {
                    $message        = $gw_data;
                    $ord_status     = Config::SC_ORDER_PART_COMPLETED;
                }
                
                // check for correct amount
                if (in_array($this->params['transactionType'], ['Auth', 'Sale'])) {
                    if ($order_amount !== $dmn_amount
                        && $order_amount != $this->params['customField2']
                    ) {
                        $this->totalCurrAlert = true;
                    }
                    
                    if ($this->order_data['currency'] !== $this->params['currency']
                        && $this->order_data['currency'] != $this->params['customField3']
                    ) {
                        $this->totalCurrAlert = true;
                    }
                    
                    if ($this->totalCurrAlert) {
                        $message .= ' Attention! - ' . $dmn_amount . ' ' . $this->params['currency']
                            . ' was paid instead of ' . $order_amount . ' ' . $this->order_data['currency'] . '.';

                        Logger::writeLog(
                            $this->settings,
                            array(
                                'order amount'      => $order_amount,
                                'dmn amount'        => $dmn_amount,
                                'original amount'   => $this->params['customField2'],
                                'order currency'    => $this->order_data['currency'],
                                'dmn currency'      => $this->params['currency'],
                                'original currency' => $this->params['customField3'],
                            ),
                            'DMN amount/currency and Order amount/currency does not much.',
                            'WARN'
                        );
                    }
                }
                
                break;
                
            case 'ERROR':
            case 'DECLINED':
            case 'FAIL':
                $reason = '';
                
                if(!empty($this->params['reason'])) {
                    $reason = ' Error Reason: ' . $this->params['reason'];
                }
                elseif(!empty($this->params['Reason'])) {
                    $reason = ' Error Reason: ' . $this->params['Reason'];
                }
                
                $message .= $reason;
                
                if(!empty($this->params['ErrCode'])) {
                    $message .= ' Error Code: ' . $this->params['ErrCode'];
                }
                if(!empty($this->params['message'])) {
                    $message .= ' Error message: ' . $this->params['message'];
                }
                
                if (in_array($this->params['transactionType'], array('Auth', 'Sale', 'Settle'), true)) {
					$ord_status     = Config::SC_ORDER_REJECTED;
                    $payment_status = Config::SC_PAYMENT_CANCELLED;
				}
                
                break;
            
            case 'PENDING':
                if ($this->order_data['status'] == Config::SC_ORDER_COMPLETED
                    || $this->order_data['status'] == Config::SC_ORDER_IN_PROGRESS
                ) {
                    $ord_status     = $this->order_data['status'];
                    $payment_status = $this->order_data['cleared'];
                    break;
                }
                
                $message = $gw_data;
                break;
        }
        
        $this->curr_dmn_note = $message;
        
        Logger::writeLog(
            $this->settings, 
            [
                'order id'              => $this->order_data['id'],
                'order status'          => $ord_status,
                'order $payment_status' => $payment_status,
                'message'               => $message,
            ],
            'Order data to update'
        );
        
        // save message as Nuvei Note
//        $this->nuvei_notes[$this->params['TransactionID']] = [
//            'date'      => $this->params['responseTimeStamp'],
//            'comment'   => $this->curr_dmn_note
//        ];
        
//        $db_data = json_encode($this->nuvei_notes);
//        
//        $resp = $this->container->get('dbal_connection')->query(
//            'INSERT INTO nuvei_orders (order_id, notes) '
//            . 'VALUES('. (int) $this->order_data['id'] .', \''. $db_data .'\') '
//            . 'ON DUPLICATE KEY UPDATE notes = \''. $db_data .'\' '
//        );
        
        $order_module->setOrderStatus($this->order_data['id'], $ord_status, $send_message, $message);
        $order_module->setPaymentStatus($this->order_data['id'], $payment_status, $send_message);
    }
    
}
