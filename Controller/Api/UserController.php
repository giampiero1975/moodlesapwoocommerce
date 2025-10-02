<?php
use http\Url;

// include the logger file
require_once PROJECT_ROOT_PATH . "phplogger.php";
require_once PROJECT_ROOT_PATH . "inc/config.php";
require_once PROJECT_ROOT_PATH . 'curl/sendXml.php';
require_once PROJECT_ROOT_PATH . 'mail/send.php';
require_once PROJECT_ROOT_PATH . 'pdf/invoice.php';
require_once PROJECT_ROOT_PATH . 'Model/MoodleModel.php';
// Inclusione per il nuovo connettore WooCommerce
require_once PROJECT_ROOT_PATH . 'Model/WooCommerceModel.php';

class UserController extends BaseController
{
    // ===================================================================
    // METODI NUOVI O MODIFICATI PER LA VERSIONE 2.0
    // ===================================================================
    
    /**
     * V2.0: Metodo principale per inserire la fattura in SAP.
     * Recupera i dati da WooCommerce invece che da Moodle.
     */
    public $tipodoc;
    
    public function insInvoice()
    {
        $logger = Logger::get_logger();
        $this->nome_log = $logger->logname;
        $logger->do_write("\nmethod: " . __METHOD__);
        $config = new costanti();
        
        // 1. Recupera i dati base dalla tabella 'moodle_payments'.
        $this->arrQueryStringParams = $this->getQueryStringParams();
        $paymentDetails = $this->getMoodlePayments($this->arrQueryStringParams["id"]);
        $this->arrQueryStringParams = $paymentDetails; // Mantiene la compatibilità
        $logger->dump($this->arrQueryStringParams);
        
        try {
            // 2. GET dei dati da WooCommerce tramite API
            $wcModel = new WooCommerceModel($paymentDetails['mdl']);
            $orderData = $wcModel->getOrderById($paymentDetails['payment_id']);
            
            if (!$orderData) {
                $logger->log("Errore: Impossibile recuperare i dati dell'ordine " . $paymentDetails['payment_id'] . " da WooCommerce.");
                $this->emailMessagge(['oggetto' => 'Errore API WooCommerce', 'destinatario' => 'system', 'messaggio' => "Impossibile recuperare dati per ordine WC " . $paymentDetails['payment_id']]);
                exit();
            }
            
            // 3. Mappa i dati di WooCommerce nella struttura dati attesa ($this->userMoodle)
            $this->userMoodle = $this->mapWooCommerceDataToMoodleStructure($orderData, $paymentDetails);
            $logger->log("Dati mappati da WooCommerce:");
            $logger->dump($this->userMoodle);
            
        } catch (Exception $e) {
            $logger->log("Errore critico durante la comunicazione con WooCommerce: " . $e->getMessage());
            $this->emailMessagge(['oggetto' => 'Errore Configurazione WooCommerce', 'destinatario' => 'system', 'messaggio' => "Errore WooCommerceModel: " . $e->getMessage()]);
            exit();
        }
        
        // Da qui il flusso prosegue utilizzando la logica esistente
        
        # GET sap
        if (! $this->BPSAP = $this->getSapUser())
            exit();
        
        # Verifico allineamento utente SAP/Cliente
        if (! $this->checkAlignUser = $this->alignUser())
            exit();
            
        # GET sap e verifico articolo
        if (! $this->sapArticle = $this->getSapArticle())
            exit();
            // ---- AGGIUNGI QUESTO LOG ----
            $logger->log("FASE: Articolo SAP recuperato con successo.");
            // -----------------------------
        
        # Configurazione costi e bollo
        $this->getCostInv();
                    
        # Genero fattura
        $this->tipo = $this->arrQueryStringParams['tipo'];
        $this->tipodoc = 'invoice';
        if (! $this->createXMLInv()) {
            $array = [
                'oggetto' => $config::WOOCOMMERCE_INSTANCES[$this->arrQueryStringParams['mdl']]['url'], // Usa un nome appropriato
                'destinatario' => 'system',
                'messaggio' => "Problemi inserimento la fattura per ".$this->userMoodle['0']['nome']
                ];
            $this->emailMessagge($array);
            return false;
        } else {
            $this->mdl = $this->arrQueryStringParams['mdl'];
            $this->userid = $this->arrQueryStringParams['userid'];
            $this->courseid = $this->arrQueryStringParams['courseid'];
            
            $userModel = new dbmoodle('mdlapps_moodleadmin');
            $sql = "INSERT INTO `invoice` (`mdl`, `userid`, `courseid`, `cardcode`, `cardname`, `codicefiscale`, `partitaiva`,`nfattura`)" . " VALUES ('" . $this->arrQueryStringParams['mdl'] . "'," . $this->arrQueryStringParams['userid'] . ", " . $this->arrQueryStringParams['courseid'] . ",'" . $this->BPSAP['cardcode'] . "','" . $this->BPSAP['cardname'] . "','" . $this->BPSAP['AddId'] . "','" . $this->BPSAP['partitaiva'] . "','" . $this->datiInvoice['docnum'] . "');";
            
            if (! $userModel->create($sql)) {
                $logger->log("problemi inserendo la fattura: " . $sql);
                return false;
            }
            
            $sql = "UPDATE `moodle_payments` set sales='1' WHERE id='" . $this->arrQueryStringParams['id'] . "';";
            if (! $userModel->create($sql)) {
                $logger->log("problemi aggiornando i pagamenti paypal: " . $sql);
                return false;
            }
            
               }
                    
               // genero fattura pdf
               if (! $this->invoicePdf()) {
                   $logger->log("problemi generando la fattura PDF");
                   $array = [
                       'destinatario' => 'system',
                       'messaggio' => "problemi generando la fattura PDF"
                   ];
                   
                   $this->emailMessagge($array);
                   return false;
               }
                    
               // inserisco incasso
               $this->tipodoc = 'profit';
               if (! $this->incasso()) {
                   $logger->log("problemi inserendo l'incasso");
                   $array = [
                       'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                       'destinatario' => 'system',
                       'messaggio' => "problemi inserendo l'incasso"
                   ];
                   
                   $this->emailMessagge($array);
                   return false;
               }
                    return TRUE;
    }
    
    /**
     * V2.0: Nuovo metodo "TRADUTTORE" (MAPPER)
     * Converte i dati ricevuti dall'API di WooCommerce nella vecchia struttura dati.
     */
    private function mapWooCommerceDataToMoodleStructure($orderData, $paymentDetails) {
        $billing = $orderData["billing"];
        
        $cf = ''; $piva = ''; $pec = ''; $sdi = '';
        foreach ($orderData['meta_data'] as $meta) {
            if (isset($meta['key'])) {
                switch ($meta['key']) {
                    case 'billing_cf': case '_billing_cf': $cf = $meta['value']; break;
                    case 'billing_piva': case '_billing_piva': $piva = $meta['value']; break;
                    case 'billing_pec': case '_billing_pec': $pec = $meta['value']; break;
                    case 'billing_sdi': case '_billing_sdi': $sdi = $meta['value']; break;
                }
            }
        }
        
        $cliente = [];
        $cliente[0] = [
            'nome' => $billing['first_name'] . ' ' . $billing['last_name'],
            'email' => $billing['email'],
            'Rag' => !empty($billing['company']) ? $billing['company'] : $billing['first_name'] . ' ' . $billing['last_name'],
            'CF' => strtoupper($cf),
            'partitaiva' => strtoupper($piva),
            'fattind' => $billing['address_1'],
            'fattcomune' => $billing['city'],
            'fattcap' => $billing['postcode'],
            'fattprov' => $billing['state'],
            'telefono' => $billing['phone'],
            'PEC' => $pec,
            'IPACodePA' => $sdi
        ];
        
        $config = new costanti();
        $idnumber_sap = '';
        if (isset($config::WOOCOMMERCE_INSTANCES[$paymentDetails['mdl']]['idnumber_sap'])) {
            $idnumber_sap = $config::WOOCOMMERCE_INSTANCES[$paymentDetails['mdl']]['idnumber_sap'];
        }
        
        $cliente[1] = [
            'cost' => $paymentDetails['cost'],
            'fullname' => $orderData['line_items'][0]['name'],
            'idnumber' => $idnumber_sap
        ];
        
        return $cliente;
    }
    
    // ===================================================================
    // METODI ORIGINALI (ORA RIpristinati)
    // ===================================================================
    
    public function getMoodlePayments($id){
        $this->payments_id = $id;
        $logger = Logger::get_logger();
        $logger->log("Recupero record da moodle_payments per ID: ".$this->payments_id);
        $payments = new UserModel('mdlapps_moodleadmin');
        $paymentsFields = $payments->select('select * from moodle_payments where id='.$this->payments_id);
        $logger->log("SQL: select * from moodle_payments where id=".$this->payments_id);
        $payment=array();
        
        foreach ($paymentsFields as $paymentsField => $paymentsFieldsValue){
            $payment['id']=$paymentsFieldsValue['id'];
            $payment['payment_id']=$paymentsFieldsValue['payment_id'];
            $payment['mdl']=$paymentsFieldsValue['mdl'];
            $payment['courseid']=$paymentsFieldsValue['courseid'];
            $payment['userid']=$paymentsFieldsValue['userid'];
            $payment['cost']=number_format($paymentsFieldsValue['cost'], 2, '.', ',');
            $payment['tipo']=$paymentsFieldsValue['method'];
        }
        return $payment;
    }
    
    public function getSapUser() {
        try {
            $logger = Logger::get_logger();
            $logger->log("Recupero dati utenti da SAP");
            $userSap = new SapModel();
            $config = new costanti();
            
            $logger->log("getSapUser: Cerco utente con CF: " . $this->userMoodle[0]['CF']);
            
            if (! $this->clienteSap = $userSap->getUsers($this->userMoodle[0]['CF'])) {
                $logger->log("Cliente " . $this->userMoodle[0]['Rag'] . " {" . $this->userMoodle[0]['CF'] . "} non presente su SAP. Tentativo di creazione.");
                $this->tipodoc = 'bp';
                $xml = $this->createXMLBP();
                
                $logger->log("getSapUser: XML per nuovo utente creato. Tento invio a SAP (sendWS)...");
                
                if ($this->sendWS($xml) == true) {
                    $logger->log("getSapUser: Utente creato con successo su SAP. Recupero i dati aggiornati.");
                    $this->clienteSap = $userSap->getUsers($this->userMoodle[0]['CF']);
                } else {
                    $logger->log("getSapUser: ERRORE CRITICO durante la creazione dell'utente su SAP via Web Service.");
                    $array = [
                        'oggetto' => 'Errore Creazione Utente SAP',
                        'destinatario' => 'sap',
                        'messaggio' => "Errore durante la creazione del BP in SAP per l'utente: " . $this->userMoodle[0]['nome'] . " (CF: " . $this->userMoodle[0]['CF'] . ")"
                    ];
                    $this->emailMessagge($array);
                    exit();
                }
            }
            
            $logger->log("getSapUser: Utente trovato o creato con successo. Dump dei dati SAP:");
            $logger->dump($this->clienteSap);
            return $this->clienteSap;
        } catch (Exception $e) {
            echo "err: " . $e->getMessage();
            $logger->log("Errore: " . $e->getMessage());
            $logger->log('Error on line ' . $e->getLine() . ' in ' . $e->getFile());
            return false;
        }
    }
    
    public function getSapArticle() {
        $logger = Logger::get_logger();
        $config = new costanti();
        
        $logger->log("Recupero articolo SAP con idnumber: {$this->userMoodle[1]['idnumber']}");
        $userSap = new SapModel();
        
        if (! $this->articoloSap = $userSap->getItem($this->userMoodle[1]['idnumber'])) {
            $logger->log("Problema recuperando l'articolo su SAP {$this->userMoodle[1]['idnumber']}");
            $array = [
                'oggetto' => 'Errore Articolo SAP',
                'destinatario' => 'sap',
                'messaggio' => "Problema recuperando l'articolo su SAP con codice: {$this->userMoodle[1]['idnumber']}"
                ];
            
            $this->emailMessagge($array);
            // Modificato per non uscire subito e permettere al flusso principale di gestire l'exit
            return false;
        }
        
        $logger->log("Dump array articoloSap");
        $logger->dump($this->articoloSap);
        return $this->articoloSap;
    }
    
    public function createXMLBP()
    {
        # print_r($this->userMoodle);
        $config = new costanti();
        $logger = Logger::get_logger();
        $userSap = new SapModel();
        // creo il nuovo cardcode
        $this->cardcode = $userSap->setCardCode();
        
        ((! empty($this->userMoodle['0']['IPACodePA'])) ? $this->userMoodle['0']['IPACodePA'] : $this->userMoodle['0']['IPACodePA'] = "0000000");
        
        $str = $this->userMoodle['0']['partitaiva'];
        $pattern = "/^[IT]{2}[0-9]{11}$/"; // Pattern standart
        if (preg_match($pattern, $str) == false && ! empty($this->userMoodle['0']['partitaiva'])) {
            if (preg_match("/^[0-9]{11}$/", $str) == true) {
                $this->userMoodle['0']['partitaiva'] = "IT" . $this->userMoodle['0']['partitaiva'];
                $logger->log("{Partita iva} Moodle standardizzata [" . $this->userMoodle['0']['partitaiva'] . "] " . __METHOD__);
            }
        }
        
        $logger->log("Generazione XML per nuovo BP");
        
        $this->invxmlHeader = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:loc="http://localhost/">
                                <soapenv:Header/>
                                    <soapenv:Body>
                                    <loc:BOsync>
                                    <loc:reqType>set</loc:reqType>
                                    <loc:objType></loc:objType>
                                    <loc:docXml>';
        
        $this->invxmlStart = '<BusinessPartner>';
        
        $this->invxmlAdmInfo = '<RequestInfo>
                                    <requestUser>' . $config::USER . '</requestUser>
                                    <requestDataTime>' . date('Y-m-d h:m:s') . '</requestDataTime>
                                    <requestDB>' . $config::REQUESTDB . '</requestDB>
                                </RequestInfo>';
        
        $this->invxmlBP = '<Data>
                                <codice>' . $this->cardcode . '</codice>
                                <ragsoc>' . $this->userMoodle['0']['Rag'] . '</ragsoc>
                                <conto>' . $config::CONTOCLIENTI . '</conto>
                                <tiposoll></tiposoll>
                                <tipoimp></tipoimp>
                                <codiva></codiva>
                                <cat>' . $config::GROUPMEDI . '</cat>
                                <zona></zona>
                                <valuta>' . $config::VALUTA . '</valuta>
                                <codage>' . $config::CODAGE . '</codage>
                                <piva>' . $this->userMoodle['0']['partitaiva'] . '</piva>
                                <codfis>' . $this->userMoodle['0']['CF'] . '</codfis>
                                <singlepay>' . $config::SINGLEPAY . '</singlepay>
                                <tel1>' . $this->userMoodle['0']['telefono'] . '</tel1>
                                <tel2></tel2>
                                <fax></fax>
                                <email>' . $this->userMoodle['0']['email'] . '</email>
                                <web></web>
                                <lingua>' . $config::LINGUA . '</lingua>
                                <tipobp>' . $config::TIPOBP . '</tipobp>
                                <bloccopag>' . $config::BLOCCOPAG . '</bloccopag>
                                <numlettes></numlettes>
                                <impes></impes>
                                <dtiniese></dtiniese>
                                <dtfinese></dtfinese>
                                <impfido></impfido>
                                <annullato>' . $config::ANNULLATO . '</annullato>
                                <noteana></noteana>
                                <abiint>' . $config::ABIINT . '</abiint>
                                <nazint>' . $config::NAZINT . '</nazint>
                                <cabint>' . $config::CABINT . '</cabint>
                                <ccint>' . $config::CCINT . '</ccint>
                                <codpag></codpag>
                                <sapproperty></sapproperty>
                                <intra>' . $config::INTRA . '</intra>
                                <tipoes>' . $config::TIPOES . '</tipoes>
                                <indirpec>' . $this->userMoodle['0']['PEC'] . '</indirpec>
                                <coddestsdi>' . $this->userMoodle['0']['IPACodePA'] . '</coddestsdi>
                                <CheckPA>' . $config::CHECKPA . '</CheckPA>
                                <codop347></codop347>
                                <opassic347>' . $config::OPASSIC . '</opassic347>
                                <ritacc>' . $config::RITACC . '</ritacc>
                                <Conguaglio>' . $config::CONGUAGLIO . '</Conguaglio>
                            	<settore>' . $config::SETTORE . '</settore>
                                <userfield></userfield>
                                <indirizzi>
                                  <indirizzo>
                                    <tipoind>' . $config::TIPOINDB . '</tipoind>
                                    <idind>' . $this->userMoodle['0']['Rag'] . '</idind>
                                    <viaind>' . $this->userMoodle['0']['fattind'] . '</viaind>
                                    <capind>' . $this->userMoodle['0']['fattcap'] . '</capind>
                                    <locind>' . $this->userMoodle['0']['fattcomune'] . '</locind>
                                    <statind>' . $config::STATIND . '</statind>
                                    <provind>' . $this->userMoodle['0']['fattprov'] . '</provind>
                                    <pivaind>' . $this->userMoodle['0']['partitaiva'] . '</pivaind>
                                  </indirizzo>
                                  <indirizzo>
                                    <tipoind>' . $config::TIPOINDS . '</tipoind>
                                    <idind>' . $this->userMoodle['0']['Rag'] . '</idind>
                                    <viaind>' . $this->userMoodle['0']['fattind'] . '</viaind>
                                    <capind>' . $this->userMoodle['0']['fattcap'] . '</capind>
                                    <locind>' . $this->userMoodle['0']['fattcomune'] . '</locind>
                                    <statind>' . $config::STATIND . '</statind>
                                    <provind>' . $this->userMoodle['0']['fattprov'] . '</provind>
                                    <pivaind></pivaind>
                                  </indirizzo>
                                </indirizzi>
                        </Data>';
        
        $this->invxmlEnd = '</BusinessPartner>';
        
        $this->invxmlFooter = '</loc:docXml>
                              </loc:BOsync>
                              </soapenv:Body>
                              </soapenv:Envelope>';
        
        $logger->log("XML Business Partner");
        
        $invXmlBody = $this->invxmlStart;
        $invXmlBody .= $this->invxmlAdmInfo;
        $invXmlBody .= $this->invxmlBP;
        $invXmlBody .= $this->invxmlEnd;
        
        $logger->log($invXmlBody);
        
        // sostituzione slash tag per invio xml
        $tagOpen = '/</i';
        $tagClose = '/>/i';
        $invXmlBody = preg_replace($tagOpen, '&lt;', $invXmlBody);
        $invXmlBody = preg_replace($tagClose, '&gt;', $invXmlBody);
        
        $invXml = $this->invxmlHeader;
        $invXml .= $invXmlBody;
        $invXml .= $this->invxmlFooter;
        
        $logger->log($invXml);
        return $invXml;
    }
    
    public function sendWS($xml, $docNum = null)
    {
        // passo il numero fattura in caso di check inserimento
        $this->docNum = $docNum;
        // creo istanza Soap
        $ws = new sendXml($xml);
        return $ws->sendSoap($this->tipodoc, $this->docNum);
    }
    
    public function alignUser()
    {
        $logger = Logger::get_logger();
        $config = new costanti();
        //$this->arrQueryStringParams = $this->getQueryStringParams();
        
        if (! $this->BPSAP) {
            $logger->log("implementazione -> SET BP SAP");
            echo "Utente SAP mancante";
            $logger->log("*** Inserimento utente in SAP");
            $this->XmlBp = $this->createXMLBP();
            # invio XMP
        } else {
            $allineamento = [];
            $logger->log("*** Utente esiste, verifico allineamento dati");
            # print_r($this->userMoodle);
            # print_r($this->BPSAP);
            
            # check verifica dati fatturazione
            $logger->log("*** Verifica allineamento dati fatturazione");
            // echo "<br>Verifica allineamento dati fatturazione<br>";
            
            /* check della piva */
            $str = $this->userMoodle['0']['partitaiva'];
            $pattern = "/^[IT]{2}[0-9]{11}$/"; // Pattern standart
            if (preg_match($pattern, $str) == false && ! empty($this->userMoodle['0']['partitaiva'])) {
                # $logger->log("{Partita iva} Moodle non standard [".$this->userMoodle['0']['partitaiva']."]");
                if (preg_match("/^[0-9]{11}$/", $str) == true) {
                    // Pattern senza nazione
                    $this->userMoodle['0']['partitaiva'] = "IT" . $this->userMoodle['0']['partitaiva'];
                    $logger->log("{Partita iva} Moodle standardizzata [" . $this->userMoodle['0']['partitaiva'] . "]");
                } else {
                    $logger->log("{Partita iva} Moodle non valida!");
                    $array = [
                        'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                        'destinatario' => 'moodle',
                        'messaggio' => "Partita IVA Moodle non valida [" . $str . "] per l'utente " . $this->userMoodle['0']['nome']
                    ];
                    
                    $this->emailMessagge($array);
                    return 0;
                }
            }
            
            if ($this->userMoodle['0']['partitaiva'] != $this->BPSAP['partitaiva']) {
                
                $logger->log("{Partita iva} disallineata: " . $this->userMoodle['0']['partitaiva']);
                $logger->log("*** Inserimento utente in SAP");
                
                $this->tipodoc = 'bp';
                $this->XmlBp = $this->createXMLBP();
                
                if ($this->sendWS($this->XmlBp) == true) {
                    // allineo modalità di pagamento CBI dopo inserimento
                    $userSap = new SapModel();
                    $logger->log("Recupero i dati SAP dopo inserimento WS");
                    $this->BPSAP = $this->getSapUser(); // GET delle modifiche fatte
                } else {
                    $logger->log("errore recuperando SAP dopo inserimento WS - 1");
                    $array = [
                        'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                        'destinatario' => 'system',
                        'messaggio' => "Errore inserimento BP {" . $this->userMoodle['0']['partitaiva'] . "} per l'utente " . $this->userMoodle['0']['nome']
                    ];
                    
                    $this->emailMessagge($array);
                    return 0;
                }
            }
            
            // verifica codice fiscale
            $str = $this->userMoodle['0']['CF'];
            $pattern = "/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/i"; // Pattern standart
            if (preg_match($pattern, $str) == false && ! empty($this->userMoodle['0']['CF'])) {
                
                $pattern = "/^[0-9]{11}$/"; // Pattern standart
                if (preg_match($pattern, $str) == false) {
                    
                    $logger->log("{Codice Fiscale} Moodle non valido!");
                    $array = [
                        'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                        'destinatario' => 'moodle',
                        'messaggio' => "Codice Fiscale Moodle non valido [" . $str . "] per l'utente " . $this->userMoodle['0']['nome']
                    ];
                    
                    $this->emailMessagge($array);
                    return false;
                }
            }
            
            // imposto a default se mancante in moodle
            if ($this->userMoodle['0']['IPACodePA'] == '') {
                # $logger->log("{IPACodePA} Moodle non presente");
                $this->userMoodle['0']['IPACodePA'] = '0000000';
            } else {
                $pattern = "/^[A-Z0-9]{7}$/i"; // IPA da 7 char alfanumerici
                if (preg_match($pattern, $this->userMoodle['0']['IPACodePA']) == false) {
                    $logger->log("{IPACodePA} Moodle non valido");
                    $array = [
                        'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                        'destinatario' => 'moodle',
                        'messaggio' => "IPACodePA Moodle non valido [" . $this->userMoodle['0']['IPACodePA'] . "] per " . $this->userMoodle['0']['nome']
                    ];
                    
                    $this->emailMessagge($array);
                    return 0;
                }
                
                $logger->log("{IPACodePA} Moodle valido");
            }
            
            # echo "{".$this->userMoodle['0']['PEC']."} {".$this->BPSAP['PEC']."}";
            
            if ($this->userMoodle['0']['PEC'] != $this->BPSAP['PEC']) {
                $logger->log("{PEC} disallineata");
                $allineamento['U']['PECAddr'] = strtolower($this->userMoodle['0']['PEC']);
            }
            
            if ($this->userMoodle['0']['Rag'] != $this->BPSAP['cardname'] && ! empty($this->userMoodle['0']['Rag'])) {
                $logger->log("{CardName} disallineato");
                $allineamento['U']['cardname'] = $this->userMoodle['0']['Rag'];
            }
            
            if ($this->userMoodle['0']['Rag'] != $this->BPSAP['ShipToDef']) {
                $logger->log("{ShipToDef} disallineato");
                $allineamento['U']['ShipToDef'] = $this->userMoodle['0']['Rag'];
            }
            
            if ($this->userMoodle['0']['Rag'] != $this->BPSAP['BillToDef']) {
                $logger->log("{BillToDef} disallineato");
                $allineamento['U']['BillToDef'] = $this->userMoodle['0']['Rag'];
            }
            
            if ($this->userMoodle['0']['IPACodePA'] != $this->BPSAP['IPACodePA'] && ! empty($this->userMoodle['0']['IPACodePA'])) {
                $logger->log("{IPACodePA} disallineata");
                $allineamento['U']['IPACodePA'] = $this->userMoodle['0']['IPACodePA'];
            }
            
            if ($this->userMoodle['0']['Rag'] != $this->BPSAP['Name']) {
                $logger->log("{Address} disallineata");
                $allineamento['B']['Address'] = $this->userMoodle['0']['Rag'];
                $allineamento['S']['Address'] = $this->userMoodle['0']['Rag'];
            }
            
            if ($this->userMoodle['0']['fattind'] != $this->BPSAP['Address'] && ! empty($this->userMoodle['0']['fattind'])) {
                $logger->log("{Street} disallineata");
                $allineamento['B']['Street'] = $this->userMoodle['0']['fattind'];
            }
            
            if ($this->userMoodle['0']['fattcomune'] != $this->BPSAP['City'] && ! empty($this->userMoodle['0']['fattcomune'])) {
                $logger->log("{City} disallineata");
                $allineamento['B']['city'] = $this->userMoodle['0']['fattcomune'];
            }
            
            if ($this->userMoodle['0']['fattcap'] != $this->BPSAP['ZipCode'] && ! empty($this->userMoodle['0']['fattcap'])) {
                $logger->log("{ZipCode} disallineata");
                $allineamento['B']['ZipCode'] = $this->userMoodle['0']['fattcap'];
            }
            
            if ($this->userMoodle['0']['fattprov'] != $this->BPSAP['Prov'] && (!empty($this->userMoodle['0']['fattprov'] || $this->userMoodle['0']['fattprov']=="SEL"))) {
                $logger->log("{Prov} disallineata");
                $allineamento['B']['State'] = $this->userMoodle['0']['fattprov'];
                $allineamento['S']['State'] = $this->userMoodle['0']['fattprov'];
            }
            
            if ($this->userMoodle['0']['email'] != $this->BPSAP['E_Mail'] && ! empty($this->userMoodle['0']['email'])) {
                $logger->log("{E_Mail} disallineata");
                $allineamento['U']['E_Mail'] = $this->userMoodle['0']['email'];
            }
            
            # check verifica dati spedizione, setting con i dati fatturazione di moodle
            $logger->log("*** Verifica allineamento dati spedizione");
            // echo "<br>Verifica allineamento dati spedizione<br>";
            
            if (empty($this->userMoodle['0']['fattind']) || empty($this->userMoodle['0']['fattcomune']) || empty($this->userMoodle['0']['fattcap']) || empty($this->userMoodle['0']['fattprov'])) {
                $logger->log("*** Dati fatturazione Moodle incompleti");
                $array = [
                    'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                    'destinatario' => 'moodle',
                    'messaggio' => "Dati fatturazione Moodle incompleti per l'utente: " . $this->userMoodle['0']['nome']
                ];
                
                $this->emailMessagge($array);
                return 0;
            }
            
            if ($this->userMoodle['0']['Rag'] != $this->BPSAP['NameAddressS']) {
                $logger->log("{Address S} disallineata");
                $allineamento['S']['Address'] = $this->userMoodle['0']['Rag'];
            }
            
            if ($this->userMoodle['0']['Rag'] != $this->BPSAP['NameAddressB']) {
                $logger->log("{Address B} disallineata");
                $allineamento['B']['Address'] = $this->userMoodle['0']['Rag'];
            }
            
            if ($this->userMoodle['0']['fattcomune'] != $this->BPSAP['MailCity']) {
                $logger->log("{MailCity} disallineata");
                $allineamento['S']['city'] = $this->userMoodle['0']['fattcomune'];
            }
            
            if ($this->userMoodle['0']['fattind'] != $this->BPSAP['MailAddres']) {
                $logger->log("{MailAddres} disallineata");
                $allineamento['S']['Street'] = $this->userMoodle['0']['fattind'];
            }
            
            if ($this->userMoodle['0']['fattcap'] != $this->BPSAP['MailZipCod']) {
                $logger->log("{MailZipCod} disallineata");
                $allineamento['S']['ZipCode'] = $this->userMoodle['0']['fattcap'];
            }
            
            if ($this->userMoodle['0']['fattprov'] != $this->BPSAP['MailProv']) {
                $logger->log("{MailProv} disallineata");
                $allineamento['S']['State'] = $this->userMoodle['0']['fattprov'];
            }
            
            if (! empty($allineamento)) {
                $logger->log("Dump array allineamento");
                $logger->dump($allineamento);
                $userSap = new SapModel();
                if (! $userSap->setAlign($this->BPSAP['cardcode'], $allineamento)) {
                    $logger->log("*** Problemi allineando utente");
                    $array = [
                        'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                        'destinatario' => 'sap',
                        'messaggio' => "Problemi allineando utente "
                    ];
                    
                    $this->emailMessagge($array);
                    return 0;
                } else {
                    $logger->log("*** Allineamento effettuato con successo, riprendo i dati aggiornati");
                    $this->BPSAP = $this->getSapUser(); // GET delle modifiche fatte
                    return 1;
                }
            } else {
                $logger->log("*** Utente allineato");
                return 2;
            }
        }
    }
    
    public function getCostInv()
    {
        $config = new costanti();
        // memorizzo costo corso e formato
        $this->cost = number_format(($this->userMoodle[1]['cost']), 2, '.', '');
        
        // if bollo memorizzo formato e costo bollo senno metto 0
        if ($this->userMoodle[1]['cost'] > 77) {
            # echo "<br>con bollo";
            $this->bollo = true;
            $this->costbollo = $config::TOTALBOLLO;
            $this->cost = number_format(($this->cost - $this->costbollo), 2, '.', '');
        } else {
            $this->bollo = false;
            $this->costbollo = "0.00";
        }
        /*
         * echo "<br>bollo :".$this->bollo;
         * echo "<br>costo :".$this->cost;
         * echo "<br>costbollo :".$this->costbollo;
         * echo "<br>";
         * exit;
         */
        return;
    }
    
    public function getSeries($series, $year){
        $logger = Logger::get_logger();
        $this->series=$series.$year;
        $logger->log("Numero serie per tipo [".$this->tipo."]");
        $xml = new SapModel();
        $logger->log('Nuovo documento di numerazione serie : ' . $this->series);
        $this->numSeries = $xml->getNumSeries($this->series);
        $logger->log('Numero serie : ' . $this->numSeries);
        return $this->numSeries;
    }
    
    public function createXMLInv()
    {
        try {
            $logger = Logger::get_logger();
            $config = new costanti();
            
            $this->datiInvoice = [];
            //echo "<br>tipo: ".$this->tipo;
            switch ($this->tipo) {
                case 'manual':
                    $this->datiInvoice['data'] = date("dmY_His", strtotime($this->userMoodle['1']['datapagamento']));
                    $this->datiInvoice['data1'] = date("Ymd", strtotime($this->userMoodle['1']['datapagamento']));
                    $this->datiInvoice['data2'] = date("d.m.Y", strtotime($this->userMoodle['1']['datapagamento']));
                    $this->datiInvoice['yearSeries'] = date("y", strtotime($this->userMoodle['1']['datapagamento']));
                    $this->datiInvoice['series'] = $this->getSeries(costanti::SERIESBB, $this->datiInvoice['yearSeries']);
                    break;
                case 'paypal':
                case 'els_paypal':
                case 'woocommerce':
                    $this->datiInvoice['data'] = date('dmY_His');
                    $this->datiInvoice['data1'] = date('Ymd');
                    $this->datiInvoice['data2'] = date("d.m.Y");
                    $this->datiInvoice['yearSeries'] = date("y");
                    $this->datiInvoice['series'] = $this->getSeries(costanti::SERIESPP, $this->datiInvoice['yearSeries'] );
                    break;
                default:
                    $logger->log('1. Metodo di pagamento non riconosciuto :' . $this->tipo);
                    return false;
                    break;
            }
            
            # header
            # $this->invxmlHeader
            # $this->invxmlBody
            # $this->invxmlFooter
            
            $xml = new SapModel();
            $logger->log('Nuovo documento di numerazione serie : ' . $this->datiInvoice['series']);
            $this->docNum = $xml->getDocNumber($this->datiInvoice['series']);
            
            $logger->log('Numero documento : ' . $this->docNum);
            
            $this->invxmlHeader = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:loc="http://localhost/">
                                <soapenv:Header/>
                                    <soapenv:Body>
                                    <loc:BOsync>
                                    <loc:reqType>set</loc:reqType>
                                    <loc:objType>documenti</loc:objType>
                                    <loc:docXml>';
            
            $this->invxmlStart = '<BOM>
                                <BO>';
            
            $this->invxmlAdmInfo = '<AdmInfo>
                                <requestUser>' . $config::USER . '</requestUser>
                                <requestDataTime>' . date('Y-m-d h:m:s') . '</requestDataTime>
                                <requestDB>' . $config::REQUESTDB . '</requestDB>
                                <Object>' . $config::OBJ . '</Object>
                                <Version>' . $config::VERSION . '</Version>
                            </AdmInfo>';
            
            $this->invxmlDocuments = '<Documents>
                                <row>
                                    <Series>' . $this->datiInvoice['series'] . '</Series>
                                    <DocNum>' . $this->docNum . '</DocNum>
                                    <CardCode>' . $this->BPSAP['cardcode'] . '</CardCode>
                                    <ShipToCode>' . $this->BPSAP['ShipToDef'] . '</ShipToCode>
                                    <DocDate>' . $this->datiInvoice['data1'] . '</DocDate>
                                    <TaxDate>' . $this->datiInvoice['data1'] . '</TaxDate>
                                    <DocCurrency>' . $config::CURRENCY . '</DocCurrency>
                                    <PaymentMethod>' . $this->BPSAP['pymcode'] . '</PaymentMethod>
                                    <PaymentGroupCode>' . $config::PAYMENGROUP . '</PaymentGroupCode>
                                    <DocTotal>' . number_format(($this->cost + $this->costbollo), 2, '.', '') . '</DocTotal>
                                    <U_B1SYS_INV_TYPE>' . $config::INV_TYPE . '</U_B1SYS_INV_TYPE>
                                </row>
                            </Documents>';
            
            $this->datiInvoice['docnum'] = $this->docNum;
            $this->datiInvoice['cardcode'] = $this->BPSAP['cardcode'];
            
            $this->invxmlArticles = '<Document_Lines>';
            $this->invxmlArticles .= '<row>
                                    <ItemCode>' . $this->sapArticle[0]['itemcode'] . '</ItemCode>
				                    <ItemDescription>' . $this->sapArticle[0]['itemname'] . ' - ' . $this->userMoodle['0']['nome'] . '</ItemDescription>
				                    <Quantity>1</Quantity>
				                    <LineTotal>' . $this->cost . '</LineTotal>
				                    <VatGroup>' . $this->sapArticle[0]['vatgourpSa'] . '</VatGroup>
				                    <AccountCode>' . $this->sapArticle[0]['RevenuesAc'] . '</AccountCode>
                                </row>';
            
            $this->datiInvoice['cost'] = $this->cost;
            $this->datiInvoice['art1'] = $this->sapArticle[0]['itemcode'];
            $this->datiInvoice['descrart1'] = $this->sapArticle[0]['itemname'];
            
            $this->datiInvoice['artbollo'] = $config::ITEMCODEBOLLO;
            $this->datiInvoice['costbollo'] = $this->costbollo;
            $this->datiInvoice['descrbollo'] = $config::ITEMDESCRBOLLO;
            
            if ($this->bollo == true) {
                $this->invxmlArticles .= '<row>
				                        <ItemCode>' . $config::ITEMCODEBOLLO . '</ItemCode>
				                        <ItemDescription>' . $config::ITEMDESCRBOLLO . '</ItemDescription>
				                        <Quantity>1</Quantity>
				                        <LineTotal>' . $this->costbollo . '</LineTotal>
				                        <VatGroup>' . $config::VATBOLLO . '</VatGroup>
				                        <AccountCode>' . $config::ACCOUNTCODEBOLLO . '</AccountCode>
			                         </row>';
            }
            $this->invxmlArticles .= '</Document_Lines>';
            
            $this->invxmlRate = '<Document_Installments>
                                <row>
                                    <DueDate>' . $this->datiInvoice['data1'] . '</DueDate>
                                    <Total>' . number_format(($this->cost + $this->costbollo), 2, '.', '') . '</Total>
                                </row>
                            </Document_Installments>';
            
            $this->datiInvoice['costtot'] = number_format(($this->cost + $this->costbollo), 2, '.', '');
            $this->invxmlEnd = '</BO>
                                </BOM>';
            
            $this->invxmlFooter = '</loc:docXml>
                              </loc:BOsync>
                              </soapenv:Body>
                              </soapenv:Envelope>';
            
            # $logger->log("XML Admin Info");
            # $logger->log($this->invxmlAdmInfo);
            # $logger->log("XML Documents");
            # $logger->log($this->invxmlDocuments);
            # $logger->log("XML Articles");
            # $logger->log($this->invxmlArticles);
            # $logger->log("XML Rate");
            # $logger->log($this->invxmlRate);
            
            $invXml = $this->invxmlHeader;
            $invXmlBody = $this->invxmlStart;
            $invXmlBody .= $this->invxmlAdmInfo;
            $invXmlBody .= $this->invxmlDocuments;
            $invXmlBody .= $this->invxmlArticles;
            $invXmlBody .= $this->invxmlRate;
            $invXmlBody .= $this->invxmlEnd;
            
            $logger->log("XML Invoice");
            $logger->log($invXmlBody);
            
            $tagOpen = '/</i';
            $tagClose = '/>/i';
            $invXmlBody = preg_replace($tagOpen, '&lt;', $invXmlBody);
            $invXmlBody = preg_replace($tagClose, '&gt;', $invXmlBody);
            
            $invXml .= $invXmlBody;
            $invXml .= $this->invxmlFooter;
            
            $this->invXml = $invXml;
            # $logger->log("Generazione dell' XML Fattura:");
            # $logger->log($this->invXml);
            
            $resWS = $this->sendWS($this->invXml, $this->docNum);
            
            if ($resWS == 'check') {
                $resWS = $xml->checkInvoice($this->docNum);
                $logger->log("verifica inserimento fattura: " . $resWS);
            }
            
            if ($resWS == false) {
                $logger->log("errore inserimento fattura WS");
                return false;
            } else {
                echo "<br>Fattura inviata correttamente docEntry[" . $this->datiInvoice['docentry'] . "]";
                $this->datiInvoice['docentry'] = $resWS;
                $logger->log("Fattura inviata correttamente docEntry[" . $this->datiInvoice['docentry'] . "]");
                return true;
            }
        } catch (Exception $e) {
            $logger->log("Error: ".__METHOD__."\n".$e->getMessage()."\n".$e->getLine());
            return false;
        }
    }
    
    public function listAction()
    {
        $strErrorDesc = '';
        $requestMethod = $_SERVER["REQUEST_METHOD"];
        $this->arrQueryStringParams = $this->getQueryStringParams();
        
        if (strtoupper($requestMethod) == 'GET') {
            try {
                $userModel = new UserModel();
                
                $intLimit = 10;
                if (isset($this->arrQueryStringParams['limit']) && $this->arrQueryStringParams['limit']) {
                    $intLimit = $this->arrQueryStringParams['limit'];
                }
                
                $arrUsers = $userModel->getUsers($intLimit);
                $responseData = json_encode($arrUsers);
            } catch (Error $e) {
                $strErrorDesc = $e->getMessage() . 'Something went wrong! Please contact support.';
                $strErrorHeader = 'HTTP/1.1 500 Internal Server Error';
            }
        } else {
            $strErrorDesc = 'Method not supported';
            $strErrorHeader = 'HTTP/1.1 422 Unprocessable Entity';
        }
        
        // send soutput
        if (! $strErrorDesc) {
            $this->sendOutput($responseData, array(
                'Content-Type: application/json',
                'HTTP/1.1 200 OK'
            ));
        } else {
            $this->sendOutput(json_encode(array(
                'error' => $strErrorDesc
            )), array(
                'Content-Type: application/json',
                $strErrorHeader
            ));
        }
    }
    
    public function incasso()
    {
        # print_r($this->datiInvoice);
        $config = new costanti();
        $logger = Logger::get_logger();
        $logger->log('Tipo: ' . $this->tipo);
        
        # imposto il conto della fattura
        switch ($this->tipo) {
            case 'manual':
                $this->contobancario = costanti::CCBONIFICO;
                $this->datiInvoice['commissioni'] = 0;
                $this->datiInvoice['impnetto'] = $this->datiInvoice['costtot'];
                $this->datiInvoice['data1'] = date("Ymd", strtotime($this->userMoodle['1']['datapagamento']));
                break;
            case 'paypal':
            case 'els_paypal':
            case 'woocommerce':
                $this->contobancario = costanti::CCPAYPAL;
                $this->datiInvoice['commissioni'] = (($this->datiInvoice['costtot'] * 3.40) / 100) + 0.35;
                $this->datiInvoice['impnetto'] = $this->datiInvoice['costtot'] - $this->datiInvoice['commissioni'];
                break;
            default:
                $logger->log('2. Metodo di pagamento non riconosciuto :' . $this->tipo);
                return false;
                break;
        }
        
        $logger->log('Inserimento incasso: ' . $this->datiInvoice['docentry']);
        
        $this->invxmlHeader = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:loc="http://localhost/">
                                <soapenv:Header/>
                                    <soapenv:Body>
                                    <loc:BOsync>
                                    <loc:reqType>set</loc:reqType>
                                    <loc:objType>primenote</loc:objType>
                                    <loc:docXml>';
        
        $this->invxmlStart = '<BOM>
                                <BO>';
        
        $this->invxmlAdmInfo = '<AdmInfo>
                                <requestUser>' . $config::USER . '</requestUser>
                                <requestDataTime>' . date('Y-m-d h:m:s') . '</requestDataTime>
                                <requestDB>' . $config::REQUESTDB . '</requestDB>
                                <Object>' . $config::OBJ_INCASSO . '</Object>
                                <Version>' . $config::VERSION . '</Version>
                                </AdmInfo>';
        
        $this->invxmlDocuments = '<Payments>
                                    <row>
                                        <DocType>rCustomer</DocType>
                                        <DocDate>' . $this->datiInvoice['data1'] . '</DocDate>
                                        <TaxDate>' . $this->datiInvoice['data1'] . '</TaxDate>
                                        <VatDate>' . $this->datiInvoice['data1'] . '</VatDate>
                                        <CardCode>' . $this->datiInvoice['cardcode'] . '</CardCode>
                                        <TransferAccount>' . $this->contobancario . '</TransferAccount>
                                        <TransferSum>' . $this->datiInvoice['impnetto'] . '</TransferSum>
                                        <BankChargeAmount>' . $this->datiInvoice['commissioni'] . '</BankChargeAmount>
                                        <DocObjectCode>bopot_IncomingPayments</DocObjectCode>
                                    </row>
                                </Payments>';
        
        $this->invxmlArticles = '<Payments_Invoices>';
        $this->invxmlArticles .= '<row>
                                    <DocEntry>' . $this->datiInvoice['docentry'] . '</DocEntry>
                                    <DocLine>0</DocLine>
                                    <SumApplied>' . $this->datiInvoice['costtot'] . '</SumApplied>
                                    <InvoiceType>it_Invoice</InvoiceType>
                                </row>';
        $this->invxmlArticles .= '</Payments_Invoices>';
        
        $this->invxmlEnd = '</BO>
                                </BOM>';
        
        $this->invxmlFooter = '</loc:docXml>
                              </loc:BOsync>
                              </soapenv:Body>
                              </soapenv:Envelope>';
        
        $logger->log("XML Admin Info");
        $logger->log($this->invxmlAdmInfo);
        $logger->log("XML Documents");
        $logger->log($this->invxmlDocuments);
        $logger->log("XML Articles");
        $logger->log($this->invxmlArticles);
        
        $invXml = $this->invxmlHeader;
        $invXmlBody = $this->invxmlStart;
        $invXmlBody .= $this->invxmlAdmInfo;
        $invXmlBody .= $this->invxmlDocuments;
        $invXmlBody .= $this->invxmlArticles;
        $invXmlBody .= $this->invxmlEnd;
        
        $tagOpen = '/</i';
        $tagClose = '/>/i';
        $invXmlBody = preg_replace($tagOpen, '&lt;', $invXmlBody);
        $invXmlBody = preg_replace($tagClose, '&gt;', $invXmlBody);
        
        $invXml .= $invXmlBody;
        $invXml .= $this->invxmlFooter;
        
        $this->invXml = $invXml;
        $logger->log("Generazione dell' XML Incasso:");
        $logger->log($this->invXml);
        
        $resWS = $this->sendWS($this->invXml);
        if ($resWS == false) {
            $logger->log("errore inserimento incasso WS");
            return false;
        } else {
            $logger->log("Incasso inviato correttamente");
            return true;
        }
    }
    
    public function invoicePdf()
    {
        try {
            
            $config = new costanti();
            $logger = Logger::get_logger();
            $logger->log("Dump array datiInvoice");
            $logger->dump($this->datiInvoice);
            
            ob_end_clean();
            $pdf = new PDF_Invoice('P', 'mm', 'A4');
            
            $pdf->AddPage();
            $pdf->logo('it');
            $pdf->addSociete("Sede Legale", $this->userMoodle['0']['Rag'] . "\n" . $this->userMoodle['0']['fattind'] . "\n" . $this->userMoodle['0']['fattcap'] . " " . $this->userMoodle['0']['fattcomune'] . " " . $this->userMoodle['0']['fattprov'] . "\nITALY");
            
            $pdf->fact_dev("Fattura di Vendita N°:", $this->datiInvoice['docnum'] . " ");
            
            $pdf->addShip("\nData emissione " . $this->datiInvoice['data2'] . "\n\nSpett.le\n" . strtoupper($this->userMoodle['0']['Rag']) . "\n" . $this->userMoodle['0']['fattind'] . "\n" . $this->userMoodle['0']['fattcap'] . " " . $this->userMoodle['0']['fattcomune'] . " " . $this->userMoodle['0']['fattprov'] . "\nITALY");
            
            $pdf->datifatt("Codice Cliente: " . $this->BPSAP['cardcode'], "Partita Iva : " . $this->BPSAP['partitaiva'], "Cod. Fisc. : " . $this->BPSAP['AddId']);
            
            // Griglia dettaglio
            $cols = array(
                "ART" => 30,
                "DESCRIZIONE" => 70,
                "Q.TA" => 10,
                "PREZZO UNIT." => 30,
                "IVA" => 20,
                "TOT." => 30
            );
            $pdf->addCols($cols);
            
            $cols = array(
                "ART" => "L",
                "DESCRIZIONE" => "L",
                "Q.TA" => "C",
                "PREZZO UNIT." => "R",
                "IVA" => "C",
                "TOT." => "R"
            );
            
            $pdf->addLineFormat($cols);
            $y = 109;
            
            $line = array(
                "ART" => $this->datiInvoice['art1'],
                "DESCRIZIONE" => $this->datiInvoice['descrart1'] . "\n" . strtoupper($this->userMoodle['0']['nome']),
                "Q.TA" => "1",
                "PREZZO UNIT." => $this->datiInvoice['cost'] . " EUR",
                "IVA" => "0.00",
                "TOT." => $this->datiInvoice['cost'] . " EUR"
            );
            
            $size = $pdf->addLine($y, $line);
            
            if ($this->bollo) {
                $y += $size + 2;
                $line = array(
                    "ART" => $this->datiInvoice['artbollo'],
                    "DESCRIZIONE" => $this->datiInvoice['descrbollo'],
                    "Q.TA" => "1",
                    "PREZZO UNIT." => $this->datiInvoice['costbollo'] . " EUR",
                    "IVA" => "0.00",
                    "TOT." => $this->datiInvoice['costbollo'] . " EUR"
                );
                
                $size = $pdf->addLine($y, $line);
            }
            
            $y += $size + 2;
            
            $tot_prods = array(
                array(
                    "imponibile" => $this->datiInvoice['cost'],
                    "codiva" => 'Esente art.10 n.20 vendite',
                    "iva" => 0
                )
            );
            
            if ($this->bollo == true) {
                array_push($tot_prods, array(
                    "imponibile" => $this->datiInvoice['costbollo'],
                    "codiva" => 'Esente art.15',
                    "iva" => 0
                ));
            }
            
            $tab_tva = array(
                "1" => 1,
                "2" => 1
            );
            
            $params = array(
                "RemiseGlobale" => 1,
                "remise_tva" => 100, // {lo sconto si applica a questa partita IVA}
                "remise" => 0, // {totale sconto}
                "remise_percent" => 10, // {percentuale di sconto su questo importo IVA}
                "FraisPort" => 1,
                "portTTC" => 10, // importo delle spese di spedizione tasse incluse, par defaut la IVA = 19.6 %
                "portHT" => 0, // importo delle spese di spedizione IVA esclusa
                "portTVA" => 19.6, // valore dell'IVA da applicare all'importo al netto dell'imposta
                "AccompteExige" => 1,
                "accompte" => 0, // importo del deposito (tasse incluse)
                "accompte_percent" => 15, // percentuale di acconto (tasse incluse)
                "Remarque" => "Totale fattura"
            );
            
            $pdf->addCadreTVAs(); # disegno contenitore
            $pdf->addTVAs1($tot_prods);
            
            // Position at 1.5 cm from bottom
            $pdf->SetY(- 60);
            $pdf->SetFont('Arial', 'I', 8);
            $pdf->Cell(0, 10, 'Condizione di pagamento AVVENUTO', 0, 0, 'L');
            
            $this->nomepdf = 'Fattura di vendita_' . $this->datiInvoice['docnum'] . '_' . $this->datiInvoice['data'] . '.pdf';
            # da rimettere a I/F
            $pdf->Output($config::DEST_PDF . '/' . $this->nomepdf, 'F');
            if (! file_exists($config::DEST_PDF . '/' . $this->nomepdf)) {
                $logger->log("Generazione fattura PDF [" . $config::DEST_PDF . '/' . $this->nomepdf . "] non effettuata");
                return FALSE;
            }
            
            // invio la fattura di cortesia solo se l email personale è valorizzata
            if (! empty($this->userMoodle['0']['email'])) {
                $mail = new send();
                $array = [
                    # $this->arrQueryStringParams['mdl']
                    'mdl_emailLogin' => $config::MAILBOXES[$this->mdl]['login'],
                    'mdl_emailPass' => $config::MAILBOXES[$this->mdl]['pass'],
                    'mdl_nomecorso' => $config::MAILBOXES[$this->mdl]['corso'],
                    #'oggetto' => '[SVI] Fattura corso Medical',
                    'oggetto' => 'Fattura corso Medical',
                    'messaggio' => "Egregio Dottore/Gentile Dottoressa " . strtoupper($this->BPSAP['cardname']) . ",<br>Le inviamo in allegato la fattura di cortesia num. " . $this->datiInvoice['docnum'] . " del " . $this->datiInvoice['data2'] . " per l'iscrizione al " . $this->datiInvoice['descrart1'] . ".<br>" . "L'originale del presente documento &egrave; stato trasmesso in formato elettronico a norma di legge e sar&agrave; disponibile presso il proprio cassetto fiscale dell'Agenzie delle Entrate." . "<br>Cordiali saluti.<br>" . $config::MAILBOXES[$this->mdl]['corso'] . " - Medical Evidence div. MeTMi Srl",
                    'destinatario' => 'giampiero.digregorio@metmi.it',
                    #'destinatario' => $this->userMoodle['0']['email'],
                    'pdf' => $config::DEST_PDF . '/' . $this->nomepdf
                ];
                if ($mail->sendFattura($array))
                    return true;
                    else
                        return false;
            } else {
                $logger->log("Email non presente, fattura non inviata");
                return true;
            }
        } catch (Exception $e) {
            echo "err: " . $e->getMessage();
            $logger->log("Errore: " . $e->getMessage());
            $logger->log('Error on line ' . $e->getLine() . ' in ' . $e->getFile());
            return false;
        }
    }
    
    public function emailMessagge(array $array)
    {
        $config = new costanti();
        $destinatario = null;
        switch ($array['destinatario']) {
            case 'moodle':
                $destinatario = $config::EMAIL_MOODLE;
                break;
            case 'sap':
                $destinatario = $config::EMAIL_SAP;
                break;
            case 'system':
                $destinatario = $config::EMAIL_SYSTEM;
                break;
        }
        
        $this->arrQueryStringParams = $this->getQueryStringParams();
        $local = "http://".$config::URL."/index.php/sap/ins?";
        
        $piece = explode('/',$this->nome_log);
        $logfilename = end($piece);
        
        $array = [
            'oggetto' => $array['oggetto'] . " - " . $config::EMAIL_OBJECT,
            'messaggio' => $array['messaggio']
            . "<br><br>"
            . "Per rilanciare la procedura clicca " .
            "<a href =\"$local"
            #. "mdl=" . $this->arrQueryStringParams['mdl'] . "&courseid=" . $this->arrQueryStringParams['courseid'] . "&userid=" . $this->arrQueryStringParams['userid'] . "&tipo=" . $this->arrQueryStringParams['tipo'] . "&payment_id=" . $this->arrQueryStringParams['payment_id'] ."\">qui</a>"
            . "id=" . $this->arrQueryStringParams['id'] ."\">qui</a>"
            . "<br>Dettaglio: <a href=\"http://".$config::URL."/logs/".$logfilename."\">".$logfilename."</a>",
            'destinatario' => $destinatario
        ];
        
        $mail = new send();
        $mail->sendEmail($array);
        
        # salvo il nome log in DB
        $log = new MoodleModel('mdlapps_moodleadmin');
        $log->traceLog($this->arrQueryStringParams, $this->nome_log);
    }
    // Qui incolla tutti gli altri metodi che erano presenti nel file originale:
    // - incasso()
    // - invoicePdf()
    // - emailMessagge()
       
    // - getSeries()
    // - listAction()
    // Ti consiglio di copiarli dalla versione originale del file per essere sicuro di non perderne nessuno.
}