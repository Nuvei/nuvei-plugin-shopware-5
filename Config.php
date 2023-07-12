<?php

namespace SwagNuvei;

/**
 * @author Nuvei
 */
class Config
{
    const NUVEI_PLUGIN_VERSION      = '2.0.1';
    const NUVEI_GATEWAY_TITLE       = 'Nuvei';
    const NUVEI_CODE                = 'nuvei_payments';
    const NUVEI_DESCR               = 'Nuvei Payments';
    
    const NUVEI_ENDPOINT_SANDBOX    = 'https://ppp-test.nuvei.com/ppp/api/v1/';
    const NUVEI_ENDPOINT_PROD       = 'https://secure.safecharge.com/ppp/api/v1/';
    
    const NUVEI_CASHIER_SANDBOX     = 'https://ppp-test.nuvei.com/ppp/purchase.do';
    const NUVEI_CASHIER_PROD        = 'https://secure.safecharge.com/ppp/purchase.do';
    
    const NUVEI_CPANEL_SANDBOX      = 'sandbox.safecharge.com';
    const NUVEI_CPANEL_PROD         = 'cpanel.safecharge.com';
    
    const NUVEI_REFUND_PAYMETNS     = ['cc_card', 'apmgw_expresscheckout'];
    
//    const NUVEI_DEVICES     = ['iphone', 'ipad', 'android', 'silk', 'blackberry', 'touch', 'linux', 'windows', 'mac'];
//    const NUVEI_BROWSERS    = ['ucbrowser', 'firefox', 'chrome', 'opera', 'msie', 'edge', 'safari', 'blackberry', 'trident'];
//    
//    const NUVEI_DEVICES_TYPES   = ['tablet', 'mobile', 'tv', 'windows', 'linux'];
//    const NUVEI_DEVICES_OS      = ['android', 'windows', 'linux', 'mac os'];
    
    const NUVEI_WEB_MASTER_ID   = 'ShopWare ';
    
    // constants for order status, see db_name.s_core_states
    // states
    const SC_ORDER_NOT_VISIBLE      = -1; // this is hidden Order. Described as 'cancelled' into DB.
    const SC_ORDER_OPEN             = 0; // this must be our Auth
    const SC_ORDER_IN_PROGRESS      = 1;
    const SC_ORDER_COMPLETED        = 2;
    const SC_ORDER_PART_COMPLETED   = 3;
    const SC_ORDER_REJECTED         = 4;
    // payment states
    const SC_ORDER_PAID         = 12;
    const SC_PAYMENT_OPEN       = 17;
    const SC_PARTIALLY_REFUNDED = 31;
    const SC_COMPLETE_REFUNDED  = 32;
    const SC_PAYMENT_CANCELLED  = 35;
    
    const NUVEI_PARAMS_VALIDATION = [
        // deviceDetails
        'deviceType' => array(
            'length' => 10,
            'flag'    => FILTER_SANITIZE_STRING
        ),
        'deviceName' => array(
            'length' => 255,
            'flag'    => FILTER_DEFAULT
        ),
        'deviceOS' => array(
            'length' => 255,
            'flag'    => FILTER_DEFAULT
        ),
        'browser' => array(
            'length' => 255,
            'flag'    => FILTER_DEFAULT
        ),
        // deviceDetails END

        // userDetails, shippingAddress, billingAddress
        'firstName' => array(
            'length' => 30,
            'flag'    => FILTER_DEFAULT
        ),
        'lastName' => array(
            'length' => 40,
            'flag'    => FILTER_DEFAULT
        ),
        'address' => array(
            'length' => 60,
            'flag'    => FILTER_DEFAULT
        ),
        'cell' => array(
            'length' => 18,
            'flag'    => FILTER_DEFAULT
        ),
        'phone' => array(
            'length' => 18,
            'flag'    => FILTER_DEFAULT
        ),
        'zip' => array(
            'length' => 10,
            'flag'    => FILTER_DEFAULT
        ),
        'city' => array(
            'length' => 30,
            'flag'    => FILTER_DEFAULT
        ),
        'country' => array(
            'length' => 20,
            'flag'    => FILTER_SANITIZE_STRING
        ),
        'state' => array(
            'length' => 2,
            'flag'    => FILTER_SANITIZE_STRING
        ),
        'county' => array(
            'length' => 255,
            'flag'    => FILTER_DEFAULT
        ),
        // userDetails, shippingAddress, billingAddress END

        // specific for shippingAddress
        'shippingCounty' => array(
            'length' => 255,
            'flag'    => FILTER_DEFAULT
        ),
        'addressLine2' => array(
            'length' => 50,
            'flag'    => FILTER_DEFAULT
        ),
        'addressLine3' => array(
            'length' => 50,
            'flag'    => FILTER_DEFAULT
        ),
        // specific for shippingAddress END

        // urlDetails
        'successUrl' => array(
            'length' => 1000,
            'flag'    => FILTER_VALIDATE_URL
        ),
        'failureUrl' => array(
            'length' => 1000,
            'flag'    => FILTER_VALIDATE_URL
        ),
        'pendingUrl' => array(
            'length' => 1000,
            'flag'    => FILTER_VALIDATE_URL
        ),
        'notificationUrl' => array(
            'length' => 1000,
            'flag'    => FILTER_VALIDATE_URL
        ),
        // urlDetails END
    ];

    const NUVEI_PARAMS_VALIDATION_EMAIL = [
        'length'    => 79,
        'flag'      => FILTER_VALIDATE_EMAIL
    ];

    const NUVEI_BROWSERS_LIST = ['ucbrowser', 'firefox', 'chrome', 'opera', 'msie', 'edge', 'safari', 'blackberry', 'trident'];
    const NUVEI_DEVICES_LIST = ['iphone', 'ipad', 'android', 'silk', 'blackberry', 'touch', 'linux', 'windows', 'mac'];
    const NUVEI_DEVICES_TYPES_LIST = ['macintosh', 'tablet', 'mobile', 'tv', 'windows', 'linux', 'tv', 'smarttv', 'googletv', 'appletv', 'hbbtv', 'pov_tv', 'netcast.tv', 'bluray'];
    
    public function __contruct()
    {
        define('NUVEI_PLUGIN_DIR', dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR);
    }
}
