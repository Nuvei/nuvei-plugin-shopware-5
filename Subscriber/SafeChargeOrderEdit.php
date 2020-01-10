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
        
    //    if($request->getActionName() == 'load' && $order_id) {
    //    if($request->getActionName() == 'loadStores' && $order_id) {
        if($request->getActionName() == 'load') {
            
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
            
            // we can not use the statement because of SW cashing
//            if (isset($sc_data_arr['relatedTransactionId']) && !empty($sc_data_arr['relatedTransactionId'])) {
                $view->extendsTemplate('backend/safecharge/order/view/detail/overview.js');
//            }
        }
    }
}
