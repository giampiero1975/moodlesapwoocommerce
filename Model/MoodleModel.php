<?php
require_once PROJECT_ROOT_PATH . "/Model/DBMoodle.php";
require_once PROJECT_ROOT_PATH . "phplogger.php";

class MoodleModel extends dbmoodle
{
    public function traceLog($request,$nomeLog) {
        $filename= explode('/',$nomeLog);
        
        #if(!empty($request['mdl']) && !empty($request['payment_id'])){
        if(!empty($request['id'])){
            $sql ="update `moodle_payments` set logfile='".end($filename)."' "
                #." where payment_id='".$request['payment_id']."' and mdl='".$request['mdl']."'";
                ." where id=".$request['id'];
                #echo $sql;
                $this->create($sql);
        }
    }
}