<?php

namespace SwagNuvei;

/**
 * @author Nuvei
 */
class Logger
{
    private static $traceId;
    
    /**
     * A function to save logs.
     * 
     * @param array $settings The plugin settings.
     * @param mixed $data Data to log.
     * @param string $message Some message about the data.
     * @param string $log_level
     */
    public static function writeLog(array $settings, $data, $message = '', $log_level = 'INFO')
    {
        if (!$settings['swagSCSaveLogs']) {
            return;
        }
        
        $logs_path = dirname(dirname(dirname(dirname(__FILE__))))
            . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
        
        if(!is_dir($logs_path)) {
            exit(json_encode([
                'error' => $logs_path . ' is not a directory.'
            ]));
        }
        
        $test_mode  = false;
        $beauty_log = false;
        $d          = $data;
        $string     = '';
        
        if(!empty($settings['swagSCTestMode']) && 1 == $settings['swagSCTestMode']) {
            $beauty_log = $test_mode = true;
        }
        
        if (is_bool($data)) {
            $d = $data ? 'true' : 'false';
        } elseif (is_string($data) || is_numeric($data)) {
            $d = $data;
        } elseif ('' === $data) {
            $d = 'Data is Empty.';
        } elseif (is_array($data)) {
            // do not log accounts if on prod
            if (!$test_mode) {
                if (isset($data['userAccountDetails']) && is_array($data['userAccountDetails'])) {
                    $data['userAccountDetails'] = 'account details';
                }
                if (isset($data['userPaymentOption']) && is_array($data['userPaymentOption'])) {
                    $data['userPaymentOption'] = 'user payment options details';
                }
                if (isset($data['paymentOption']) && is_array($data['paymentOption'])) {
                    $data['paymentOption'] = 'payment options details';
                }
            }
            // do not log accounts if on prod

            if (!empty($data['paymentMethods']) && is_array($data['paymentMethods'])) {
                $data['paymentMethods'] = json_encode($data['paymentMethods']);
            }
            if (!empty($data['Response data']['paymentMethods'])
                && is_array($data['Response data']['paymentMethods'])
            ) {
                $data['Response data']['paymentMethods'] = json_encode($data['Response data']['paymentMethods']);
            }

            if (!empty($data['plans']) && is_array($data['plans'])) {
                $data['plans'] = json_encode($data['plans']);
            }

            $d = $test_mode ? json_encode($data, JSON_PRETTY_PRINT) : json_encode($data);
        } elseif (is_object($data)) {
            $d = $test_mode ? json_encode($data, JSON_PRETTY_PRINT) : json_encode($data);
        } else {
            $d = $test_mode ? json_encode($data, JSON_PRETTY_PRINT) : json_encode($data);
        }
        
        $tab            = '    ';
        $utimestamp     = microtime(true);
        $timestamp      = floor($utimestamp);
        $milliseconds   = round(($utimestamp - $timestamp) * 1000000);
        $record_time    = date('Y-m-d') . 'T' . date('H:i:s') . '.' . $milliseconds . date('P');
        
        if (!isset(self::$traceId)) {
            self::$traceId = bin2hex(random_bytes(16));
        }
        
        $source_file_name   = '';
        $member_name        = '';
        $source_line_number = '';
        
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        
        if (!empty($backtrace)) {
            if (!empty($backtrace[0]['file'])) {
                $file_path_arr  = explode(DIRECTORY_SEPARATOR, $backtrace[0]['file']);
                
                if (!empty($file_path_arr)) {
                    $source_file_name = end($file_path_arr) . '|';
                }
            }
            
//            if(!empty($backtrace[0]['function'])) {
//                $member_name = $backtrace[0]['function'] . '|';
//            }
            
            if (!empty($backtrace[0]['line'])) {
                $source_line_number = $backtrace[0]['line'] . $tab;
            }
        }
        
        $string .= $record_time . $tab
            . $log_level . $tab
            . self::$traceId . $tab
//            . 'Checkout ' . self::config->getSourcePlatformField() . '|'
            . $source_file_name
            . $member_name
            . $source_line_number;
        
        if (!empty($message)) {
            if (is_string($message)) {
                $string .= $message . $tab;
            } else {
                if ($test_mode) {
                    $string .= "\r\n" . json_encode($message, JSON_PRETTY_PRINT) . "\r\n";
                } else {
                    $string .= json_encode($message) . $tab;
                }
            }
        }

        $string     .= $d . "\r\n\r\n";
        $file_name  = 'nuvei-' . date('Y-m-d', time()) . '.log';
        
        try {
            file_put_contents(
                $logs_path . $file_name,
                $string,
                FILE_APPEND
            );
        }
        catch (Exception $ex) {}
    }
}
