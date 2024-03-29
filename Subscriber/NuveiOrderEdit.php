<?php

/**
 * @author Nuvei
 */

namespace SwagNuvei\Subscriber;

use Doctrine\DBAL\Connection;
use Enlight\Event\SubscriberInterface;
use SwagNuvei\Logger;

class NuveiOrderEdit implements SubscriberInterface
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
     * @param string $pluginDirectory
     * @param Connection $connection
     */
    public function __construct($pluginDirectory, Connection $connection)
    {
        $this->pluginDirectory  = $pluginDirectory;
        $this->connection       = $connection;
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
        $view       = $controller->View();
        $request    = $controller->Request();
        $order_id   = (int) $request->getParam('orderId');
        
    //    if($request->getActionName() == 'load' && $order_id) {
    //    if($request->getActionName() == 'loadStores' && $order_id) {
        if($request->getActionName() == 'load') {
            $view->addTemplateDir($this->pluginDirectory . '/Resources/views');
            
            $query = $this->connection->createQueryBuilder();
            $query->select(['nuvei_data'])
                ->from('nuvei_orders')
                ->where('order_id = :orderId')
                ->setParameter('orderId', $order_id);

            $sc_data_json   = $query->execute()->fetchColumn();
            $sc_data_arr    = [];
            
            if (!empty($sc_data_json) && is_array($arr = json_decode($sc_data_json, true))) {
               foreach ($arr as $trId => $data) {
                   $sc_data_arr[] = $data['comment'];
               }
            }
            
            // we can not use the statement because of SW cashing
            $view->extendsTemplate('backend/nuvei/order/view/detail/overview.js');
        }
    }
    
}
