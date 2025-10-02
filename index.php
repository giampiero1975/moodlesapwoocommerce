<?php
#die("attivato");
require __DIR__ . "/inc/bootstrap.php";
require PROJECT_ROOT_PATH . "Controller/Api/UserController.php";
require PROJECT_ROOT_PATH . "Controller/Api/UserControllerEs.php";
# http://moodlesapwoocommerce.test/index.php/sap/ins?id=1
try {	
    $logger = Logger::get_logger();
    $logger->log("**************\n");
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $uri = explode( '/', $uri );

	$logger->dump($uri, "PARSE URL");

	if ((isset($uri[2]) && $uri[2] != 'sap') || !isset($uri[3]) || empty($_SERVER['QUERY_STRING'])) {
        header("HTTP/1.1 404 Not Found");
        $logger->log("prametri url non validi");
        exit();
    }
	
	#die("Arrivo -> 1");
    // discrimino le ultime 3 lettere dell' mdl per identificare il paese
    $params=[];
    parse_str($_SERVER['QUERY_STRING'], $params);
    $logger->dump($params);
     //echo '<br>strMethodName: '.$strMethodName."<br>";
    
    $strMethodName = $uri[3] . 'Invoice';
    $objFeedController = new UserController();
    $natId = $objFeedController->getMoodlePayments($params["id"]);
    
    $nat = strtoupper(substr($natId['mdl'], -3));
    #echo "<br>NAT: ".$nat;
    #die();
    switch ($nat){
        case '_ES':
            #echo "<br> > entro in spagna";
            $strMethodName = $uri[3] . 'Invoice';
            $UserController = 'UserControllerEs';
            $logger->do_write("\n > entro in spagna");
            break;
        default:
            #echo "<br> > entro in italia";
            $UserController = 'UserController';
            $logger->do_write("\n > entro in italia");
            $strMethodName = $uri[3] . 'Invoice';
            break;
    }
    
    $objFeedController = new $UserController();
    if($objFeedController->{$strMethodName}())
        echo "Fattura generata con successo, inviata e salvata";
    else
        echo "Verifica!";
        
} catch (Exception $e) {
    echo "Error: ".$e->getMessage();
    $logger->do_write("Error: ".$e->getMessage());
}