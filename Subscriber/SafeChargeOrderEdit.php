<?php

/**
 * @author SafeCharge
 */

namespace SwagSafeCharge\Subscriber;

use Doctrine\DBAL\Connection;
use Enlight\Event\SubscriberInterface;

class SafeChargeOrderEdit implements SubscriberInterface
{
    /**
     * @var string
     */
    private $pluginDirectory;
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @param $pluginDirectory
     */
//    public function __construct(Connection $connection)
//    public function __construct($pluginDirectory)
    public function __construct($pluginDirectory, Connection $connection)
    {
        $this->pluginDirectory = $pluginDirectory;
        $this->connection = $connection;
        
        $this->save_logs = true;
        $this->logs_path = dirname(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
    }
    
    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
        //    'Enlight_Controller_Action_PostDispatchSecure_Backend_Order' => 'onOrderPostDispatch'
            'Enlight_Controller_Action_PreDispatch_Backend_Order' => 'onOrderPreDispatch'
        ];
    }

//    public function onOrderPostDispatch(\Enlight_Event_EventArgs $args)
    public function onOrderPreDispatch(\Enlight_Event_EventArgs $args)
    {
        /** @var \Shopware_Controllers_Backend_Customer $controller */
        $controller = $args->getSubject();
        $view = $controller->View();
        $request = $controller->Request();
        $order_id = intval($request->getParam('orderId'));
        
//        $this->createLog($order_id, '$order_id: ');
//        $this->createLog($request->getActionName(), 'getActionName(): ');
//        $this->createLog($request->getParam('table'), 'table(): ');
    //    $this->createLog($request->getActionName(), '$request->getActionName(): ');
        
//        if($request->getActionName() == 'getList' and $request->getParam('_dc') and $request->getParam('table') == 's_order_attributes') {
//            $this->createLog('attributes got!');
//        }
        
        
    //    if($request->getActionName() == 'load' && $order_id) {
    //    if($request->getActionName() == 'loadStores' && $order_id) {
        if($request->getActionName() == 'load') {
//            $this->createLog('Catch!');
            
    //    if($request->getActionName() == 'load') {
            $view->addTemplateDir($this->pluginDirectory . '/Resources/views');
            
            $query = $this->connection->createQueryBuilder();
            $query->select(['safecharge_order_field'])
                ->from('s_order_attributes')
                ->where('orderID = :orderId')
                ->setParameter('orderId', $order_id);

            $sc_data_json = $query->execute()->fetchColumn();
            
//            $sc_data_json = $this->container->get('db')->fetchOne("SELECT safecharge_order_field FROM s_order_attributes WHERE orderID = " . $order_id);
            
            $sc_data_arr = [];

            if(@$sc_data_json) {
                $sc_data_arr = json_decode($sc_data_json, true);
            }
            
//            $this->createLog($sc_data_arr, 'safecharge_order_field: ');
            
            // we can not use the statement because of SW cashing
//            if (isset($sc_data_arr['relatedTransactionId']) && !empty($sc_data_arr['relatedTransactionId'])) {
                $view->extendsTemplate('backend/safecharge/order/view/detail/overview.js');
//            }
        }
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
