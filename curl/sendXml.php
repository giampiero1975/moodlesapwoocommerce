<?php
// include the logger file
require_once PROJECT_ROOT_PATH . "phplogger.php";
require_once PROJECT_ROOT_PATH . "/inc/config.php";

class sendXml extends UserController{
    
    // private $soapUrl = "http://192.168.10.44/wsToSAP/B1Sync.asmx?reqType=set&objType=documenti";
    private $soapUrl = "http://192.168.10.44/wsToSAP/B1Sync.asmx";
    private $soapUser="manager";
    private $soapPassword ="manage.1";
    
    public function __construct($xml){
        $this->xml = $xml;
        $this->headers = array(
            "Content-type: text/xml;charset=\"utf-8\"",
            "Accept: text/xml",
            "Cache-Control: no-cache",
            "Pragma: no-cache",
            "SOAPAction: http://localhost/BOsync",
            "Content-length: ".strlen($this->xml),
        ); //SOAPAction: your op URL
   }
    
    public function sendSoap($tipo, $numDoc=null){
        try {
            $this->docNum = $numDoc;
            $logger = Logger::get_logger();
            $logger->log("invio xml: ".$tipo);
            // echo "lancio";
            
            // PHP cURL  for https connection with auth
            $this->ch = curl_init();
            curl_setopt($this->ch, CURLOPT_SSL_VERIFYPEER, 1);
            curl_setopt($this->ch, CURLOPT_URL, $this->soapUrl);
            curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->ch, CURLOPT_USERPWD, $this->soapUser.":".$this->soapPassword); // username and password - declared at the top of the doc
            curl_setopt($this->ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
            curl_setopt($this->ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($this->ch, CURLOPT_POST, true);
            curl_setopt($this->ch, CURLOPT_POSTFIELDS, $this->xml); // the SOAP request
            curl_setopt($this->ch, CURLOPT_HTTPHEADER, $this->headers);
            
            // converting
            $this->response = curl_exec($this->ch);
            $logger->log($this->response);
            curl_close($this->ch);
            
            $logger->log("Risposta curl".$this->response);
            
            if($this->response==''){
                $logger->log("nessuna risposta dal Web Service");
                
                if($tipo =='invoice'){
                    $logger->log("check inserimento invoice num: ". $this->docNum);
                    return 'check';
                }
                return false;
            }
                
            //lettura response WS
            $xml = simplexml_load_string(utf8_encode($this->response));
            $xml->registerXPathNamespace('test',"http://schemas.xmlsoap.org/soap/envelope/");
            
            //echo "<pre>";
            //print_r($xml->xpath('//Esito'));
            $esitoResp = [];
            foreach ($xml->xpath('//Esito') as $esito)
            {
                $esitoResp['error'] = $esito->IsError;
                $esitoResp['code'] = $esito->Code;
                $esitoResp['message'] = $esito->Message;
            }
            
            $logger->dump($esitoResp);
            
            if($esitoResp['error']=='Y'){
                $logger->log("Errore inserimento fattura: ".$esitoResp['message']);
                return false;
            }
            
            // in caso di fattura gestisco il docentry
            $logger->log("Tipo: ".$tipo);
            switch ($tipo) {
                case 'invoice':
                    if(isset($xml->xpath('//DocEntry')['0'])){
                        $docentry = $xml->xpath('//DocEntry')['0'];
                        $logger->log("Fattura inserita con docEnty: ".$docentry);
                        return $docentry;
                    }else{
                        $logger->log("DocEntry non presente!");
                        return false;
                    }
                    break;
                    
                case 'profit':
                    $docentry = $xml->xpath('//DocEntry')['0'];
                    $logger->log("Incasso inserito con docEnty: ".$docentry);
                    return true;
                    break;
                    
                case 'bp':
                    $cardcode = $xml->xpath('//ReturnKey')['0'];
                    $logger->log("Business Partner inserito con successo: " . $cardcode);
                    return true;
                    break;
                default:
                    $logger->log("Tipo non valido");
            }
            return false;
        } catch (Exception $e) {
            $logger->log("Errore:".$e->getMessage());
        }
    }
}