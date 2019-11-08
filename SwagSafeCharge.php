<?php

/**
 * @author SafeCharge
 * @year 2019
 */

namespace SwagSafeCharge;

use Shopware\Components\Plugin;
use Shopware\Components\Plugin\Context\ActivateContext;
use Shopware\Components\Plugin\Context\DeactivateContext;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;

class SwagSafeCharge extends Plugin
{
    public function install(InstallContext $context)
    {
        /** @var \Shopware\Components\Plugin\PaymentInstaller $installer */
        $installer = $this->container->get('shopware.plugin_payment_installer');
        $this->getPath();
        
        $options = [
            'name'					=> 'safecharge_payment',
            'description'			=> 'SafeCharge Payment',
            'action'				=> 'SafeCharge',
            'active'				=> 1,
            'position'				=> 1,
            'additionalDescription'	=>
                '<img src="{link file=\'custom/plugins/SwagSafeCharge/Resources/views/frontend/_public/img/safecharge-secure.png\' fullPath=true}"/>'
                . '<div id="payment_desc"></div>'
        ];
        
        $installer->createOrUpdate($context->getPlugin(), $options);
        
        // create custom field for the orders
        $service = $this->container->get('shopware_attribute.crud_service');
        $service->update('s_order_attributes', 'safecharge_order_field', 'text');
        
        // create refunds table
        $connection = $this->container->get('dbal_connection');
        
        $sql =
            "CREATE TABLE IF NOT EXISTS `swag_safecharge_refunds` (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `order_id` int(10) unsigned NOT NULL,
                `client_unique_id` varchar(50) NOT NULL,
                `amount` varchar(15) NOT NULL,
                `transaction_id` varchar(20) NOT NULL,
                `auth_code` varchar(10) NOT NULL,
                `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                
                PRIMARY KEY (`id`),
                KEY `orderId` (`order_id`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        
        
        $connection->query($sql);
        // create refunds table END
        
        /*
        // example - insert record in the DB
        if(!$this->container->get('db')->fetchOne("SELECT id FROM s_core_states WHERE name = 'refunded'")) {
            $this->container->get('db')->insert(
                's_core_states',
                [
                    'id' => 100,
                    'name' => 'sc_refund',
                    'description' => 'sc refunded order',
                    'position' => 100,
                    'group' => 'payment',
                    'mail' => true
                ]
            );
        }
         */
    }
    
    /**
     * @param UninstallContext $context
     */
    public function uninstall(UninstallContext $context)
    {
        $this->setActiveFlag($context->getPlugin()->getPayments(), false);
    }

    /**
     * @param DeactivateContext $context
     */
    public function deactivate(DeactivateContext $context)
    {
        $this->setActiveFlag($context->getPlugin()->getPayments(), false);
    }
    
    /**
     *  @param ActivateContext $context
     */
    public function activate(ActivateContext $context)
    {
        $this->setActiveFlag($context->getPlugin()->getPayments(), true);
    }
    
    /**
     * @param Payment[] $payments
     * @param $active bool
     */
    private function setActiveFlag($payments, $active)
    {
        $em = $this->container->get('models');

        foreach ($payments as $payment) {
            $payment->setActive($active);
        }
        $em->flush();
    }
}
