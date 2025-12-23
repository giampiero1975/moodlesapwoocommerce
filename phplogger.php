<?php
define("DATESTRING_FULL", "Y-m-d D H:i:s");

/**
 * Logger Class
 *
 * This is an application logging class.
 *
 * @author
 */
#[\AllowDynamicProperties]
class Logger
{
    private static $logger          = NULL;     // Instance of the logger class when default log file is in use.
    private static $user_logger     = NULL;     // Instance of the logger class when a log file is specified by the developer.
    private $user_handle            = FALSE;    // Flag to determine which log file to use.
    private $log_handle             = NULL;     // Handle to the default log file.
    private $_user_log_handle       = NULL;     // Handle to the developer specified log file.
    /**
     *  This method checks if there is an instance available. If yes it returns its handle otherwise
     *  create a new instance and return it's handle.
     *
     *  @param  string  $file
     *
     *     @return Object  Current instance of the class.
     *
     *     @access public
     *
     *     @static
     * */
    public static function get_logger($log_path=null)
    {
        if(is_null($log_path))
        {
            if(!(isset(self::$logger)) || (is_null(self::$logger)))
            {
                self::$logger = new self();
            }
            return self::$logger;
        }
        else
        {
            if(!(isset(self::$user_logger)) || (is_null(self::$user_logger)))
            {
                self::$user_logger = new self($log_path);
            }
            self::$user_logger->user_handle = true;
            return self::$user_logger;
        }
    }
    /*
     * This constructor checks if a log file exits with current date, if yes opens it,
     * if not create it and then opens its to write logs.
     *
     * @access private
     * */
    private function __construct($log_path=null)
    {
        if(is_null($log_path))
        {
            $default_log_path = __DIR__.'/logs';
            if(!is_dir($default_log_path))
            {
                mkdir($default_log_path);
            }
            $this->logname = $default_log_path.'/'.date('Ymd_His').'.log';
            #$this->logname = $default_log_path.'/'.date('Ymd').'.log';
            
            $this->log_handle = fopen($this->logname, 'w'); // mettere "a"
        }
        else
        {
            if(!is_dir($log_path))
            {
                mkdir($log_path, 0777, true);
            }
            $log_file_name = $log_path.'/'.date('Ymd_His').'.log';
            #$log_file_name = $log_path.'/'.date('Ymd').'.log';
            $this->user_log_handle = fopen($log_file_name, 'a');
        }
    }
    
    public function getName(){
        return $this->logname;
    }
    /*
     * This method writes the log messages to the log file. This method internally calls the
     * do_write method to do the actual write operation.
     *
     * @param   string  $string    The log message to be written in the log file.
     *
     * $access public
     * */
    public function log($string)
    {
        $this->do_write("\n".'['.date(DATESTRING_FULL).'] : ' . $string);
    }
    /*
     * This method writes the array or dump with a messages to the log file. This method internally
     * calls the do_write method to do the actual write operation.
     *
     * @param   mixed   $var_name   The variable or dump to be written in the log file.
     * @param   string  $string    The log message to be written in the log file, defaults to VARDUMP.
     *
     * $access public
     * */
    public function dump($var_name, $string='VARDUMP')
    {
        $this->do_write("\n".'['.date(DATESTRING_FULL).'] : {'.strtoupper($string).'} : '.var_export($var_name, true));
    }
    /*
     * This method is always called by the log() or dump() method. It writes the passed string
     * to the appropriate log file based on the object it is called upon.
     *
     * @param   string  $log_string    The log message to be written in the log file.
     *
     * $access public
     * */
    public function do_write($log_string)
    {
        if($this->user_handle)
        {
            fwrite($this->user_log_handle, $log_string);
        }
        else
        {
            fwrite($this->log_handle, $log_string);
        }
    }
}