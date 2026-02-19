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

#[\AllowDynamicProperties]
class UserController extends BaseController
{
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
        $this->arrQueryStringParams = $paymentDetails;
        $logger->dump($this->arrQueryStringParams);
        
        try {
            // 2. GET dei dati da WooCommerce tramite API
            $wcModel = new WooCommerceModel($paymentDetails['mdl']);
            $orderData = $wcModel->getOrderById($paymentDetails['payment_id']);
            
            if (! $orderData) {
                $logger->log("Errore: Impossibile recuperare i dati dell'ordine " . $paymentDetails['payment_id'] . " da WooCommerce.");
                $this->emailMessagge([
                    'oggetto' => 'Errore API WooCommerce',
                    'destinatario' => 'system',
                    'messaggio' => "Impossibile recuperare dati per ordine WC " . $paymentDetails['payment_id']
                ]);
                exit();
            }
            
            // 3. Mappa i dati di WooCommerce
            $this->userMoodle = $this->mapWooCommerceDataToMoodleStructure($orderData, $paymentDetails);
            $logger->log("Dati mappati da WooCommerce:");
            $logger->dump($this->userMoodle);
            if ($this->userMoodle === false) {
                return;
            }
            
            if ($orderData) {
                $sintesi = $wcModel->getSyntheticOrder($orderData);
                $logger->log("--- VERIFICA SINTETICA ORDINE ---");
                $logger->dump($sintesi);
            }
        } catch (Exception $e) {
            $logger->log("Errore critico durante la comunicazione con WooCommerce: " . $e->getMessage());
            $this->emailMessagge([
                'oggetto' => 'Errore Configurazione WooCommerce',
                'destinatario' => 'system',
                'messaggio' => "Errore WooCommerceModel: " . $e->getMessage()
            ]);
            exit();
        }
        
        # GET sap
        if (! $this->BPSAP = $this->getSapUser())
            exit();
            
            # Verifico allineamento utente SAP/Cliente
            if (! $this->checkAlignUser = $this->alignUser())
                exit();
                
                # GET sap e verifico articolo (Ora supporta Pluricorso)
                if (! $this->sapArticle = $this->getSapArticle())
                    exit();
                    
                    $logger->log("FASE: Articolo SAP recuperato con successo.");
                    
                    # Configurazione costi e bollo
                    $this->getCostInv();
                    
                    # Genero fattura
                    $this->tipo = $this->arrQueryStringParams['tipo'];
                    $this->tipodoc = 'invoice';
                    
                    if (! $this->createXMLInv()) {
                        $array = [
                            'oggetto' => $config::WOOCOMMERCE_INSTANCES[$this->arrQueryStringParams['mdl']]['url'],
                            'destinatario' => 'system',
                            'messaggio' => "Problemi inserimento la fattura per " . $this->userMoodle['0']['nome']
                        ];
                        $this->emailMessagge($array);
                        return false;
                    } else {
                        $this->mdl = $this->arrQueryStringParams['mdl'];
                        $this->userid = $this->arrQueryStringParams['userid'];
                        $this->courseid = $this->arrQueryStringParams['courseid'];
                        
                        $userModel = new dbmoodle('mdlapps_moodleadmin');
                        
                        // Assicurati che ci siano gli apici '" . ... . "' attorno a courseid
                        $sql = "INSERT INTO `invoice` (`mdl`, `userid`, `courseid`, `cardcode`, `cardname`, `codicefiscale`, `partitaiva`,`nfattura`)" .
                            " VALUES ('" . $this->arrQueryStringParams['mdl'] . "', " .
                            $this->arrQueryStringParams['userid'] . ", '" .
                            $this->arrQueryStringParams['courseid'] . "', '" . // <--- QUI GLI APICI SONO VITALI
                            $this->BPSAP['cardcode'] . "', '" .
                            $this->BPSAP['cardname'] . "', '" .
                            $this->BPSAP['AddId'] . "', '" .
                            $this->BPSAP['partitaiva'] . "', '" .
                            $this->datiInvoice['docnum'] . "');";
                        
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
    
    private function mapWooCommerceDataToMoodleStructure($orderData, $paymentDetails)
    {
        $billingRaw = $orderData["billing"];
        $billing = array_map(function ($value) {
            return is_string($value) ? strtoupper($this->sanitizeString($value)) : $value;
        }, $billingRaw);
            
            $logger = Logger::get_logger();
            
            $nome = $this->sanitizeString(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
            $indirizzo = $this->sanitizeString($billing['address_1'] ?? '');
            $citta = $this->sanitizeString($billing['city'] ?? '');
            $azienda = $this->sanitizeString($billing['company'] ?? '');
            $ragioneSociale = !empty($azienda) ? $azienda : $nome;
            
            $cf_user = ''; $cf_business = ''; $piva = ''; $pec = ''; $sdi = ''; $dataBonifico = '';
            
            foreach ($orderData['meta_data'] as $meta) {
                if (isset($meta['key']) && !empty($meta['value']) && is_string($meta['value'])) {
                    $val = trim($meta['value']);
                    switch ($meta['key']) {
                        case 'cf_user': $cf_user = strtoupper($val); break;
                        case 'billing_business_cf':
                        case 'billing_cf':
                        case '_billing_cf': $cf_business = strtoupper($val); break;
                        case 'billing_piva':
                        case '_billing_piva':
                        case 'piva_user': $piva = strtoupper($val); break;
                        case 'billing_pec':
                        case '_billing_pec': $pec = $val; break;
                        case 'billing_sdi':
                        case '_billing_sdi':
                        case 'billing_codiceunivoco': $sdi = strtoupper($val); break;
                        case 'bacs_date':
                        case '_bacs_date':
                            $dObj = DateTime::createFromFormat('d/m/Y', $val);
                            $dataBonifico = $dObj ? $dObj->format('Y-m-d') : $val;
                            break;
                    }
                }
            }
            
            if (ctype_digit($cf_business) && strlen($cf_business) === 11 && empty($piva)) {
                $logger->log("ALERT FISCALE: P.IVA [" . $cf_business . "] nel campo CF per ordine " . $orderData['id']);
                $array_error = [
                    'oggetto' => 'Errore Dati Fiscali - P.IVA mancante',
                    'destinatario' => 'moodle',
                    'messaggio' => "Disallineamento utente " . $nome . " (ID: " . $orderData['id'] . "), valorizzazione P.IVA Billing mancante"
                ];
                $this->emailMessagge($array_error);
                return false;
            }
            
            $cf_finale = !empty($cf_business) ? $cf_business : $cf_user;
            if (empty($dataBonifico)) $dataBonifico = substr($orderData['date_created'], 0, 10);
            
            // FIX PLURICORSO: Creiamo un array di tutti gli articoli
            $items = [];
            foreach ($orderData['line_items'] as $line) {
                $items[] = [
                    'sku' => $line['sku'] ?? '',
                    'fullname' => $this->sanitizeString($line['name'] ?? 'Corso'),
                    'cost' => number_format($line['total'], 2, '.', ''),
                    'qty' => $line['quantity'] ?? 1,
                    'unit_price' => $line['price'] ?? 0
                ];
            }
            
            // --- MODIFICA CORRETTIVA RICONOSCIMENTO BOLLO ---
            $feeBollo = 0;
            if (!empty($orderData['fee_lines'])) {
                foreach ($orderData['fee_lines'] as $fee) {
                    $feeName = strtolower($fee['name']);
                    // Riconosce sia "Bollo" che la dicitura "2,00 € tariffa" trovata nel log
                    if (strpos($feeName, 'bollo') !== false || strpos($feeName, 'tariffa') !== false) {
                        $feeBollo = number_format($fee['amount'], 2, '.', '');
                    }
                }
            }
            
            $cliente = [];
            $cliente[0] = [
                'nome' => $nome,
                'email' => $billing['email'] ?? '',
                'Rag' => $ragioneSociale,
                'CF' => $cf_finale,
                'partitaiva' => $piva,
                'fattind' => $indirizzo,
                'fattcomune' => $citta,
                'fattcap' => $billing['postcode'] ?? '',
                'fattprov' => $billing['state'] ?? '',
                'telefono' => $billing['phone'] ?? '',
                'PEC' => $pec,
                'IPACodePA' => $sdi
            ];
            
            $cliente[1] = [
                'items' => $items,
                'totale_pagato' => $orderData['total'] ?? 0,
                'fee_bollo' => $feeBollo,
                'datapagamento' => $dataBonifico
            ];
            
            return $cliente;
    }
    
    public function getMoodlePayments($id)
    {
        $this->payments_id = $id;
        $logger = Logger::get_logger();
        $logger->log("Recupero record da moodle_payments per ID: " . $this->payments_id);
        $payments = new UserModel('mdlapps_moodleadmin');
        $paymentsFields = $payments->select('select * from moodle_payments where id=' . $this->payments_id);
        $logger->log("SQL: select * from moodle_payments where id=" . $this->payments_id);
        $payment = array();
        
        foreach ($paymentsFields as $paymentsField => $paymentsFieldsValue) {
            $payment['id'] = $paymentsFieldsValue['id'];
            $payment['payment_id'] = $paymentsFieldsValue['payment_id'];
            $payment['mdl'] = $paymentsFieldsValue['mdl'];
            $payment['courseid'] = $paymentsFieldsValue['courseid'];
            $payment['userid'] = $paymentsFieldsValue['userid'];
            $payment['cost'] = number_format($paymentsFieldsValue['cost'], 2, '.', ',');
            $payment['tipo'] = $paymentsFieldsValue['method'];
        }
        return $payment;
    }
    
    public function getSapUser()
    {
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
            
            foreach ($this->clienteSap as $key => $value) {
                if (is_string($value) && ! empty($value)) {
                    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $value = mb_convert_encoding(mb_convert_encoding(mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8'), 'UTF-8', 'ISO-8859-1'), 'UTF-8', 'ISO-8859-1');
                    $this->clienteSap[$key] = trim($value);
                }
            }
            
            $logger->dump($this->clienteSap);
            return $this->clienteSap;
        } catch (Exception $e) {
            echo "err: " . $e->getMessage();
            $logger->log("Errore: " . $e->getMessage());
            $logger->log('Error on line ' . $e->getLine() . ' in ' . $e->getFile());
            return false;
        }
    }
    
    public function getSapArticle()
    {
        $logger = Logger::get_logger();
        $userSap = new SapModel();
        
        $this->articoloSap = [];
        
        if (!isset($this->userMoodle[1]['items']) || empty($this->userMoodle[1]['items'])) {
            $logger->log("Problema: nessun articolo trovato nell'ordine WooCommerce.");
            return false;
        }
        
        foreach ($this->userMoodle[1]['items'] as $item) {
            $logger->log("Recupero articolo SAP con idnumber: {$item['sku']}");
            
            $datiArticolo = $userSap->getItem($item['sku']);
            if (!$datiArticolo) {
                $logger->log("Problema recuperando l'articolo su SAP {$item['sku']}");
                $array = [
                    'oggetto' => 'Errore Articolo SAP',
                    'destinatario' => 'sap',
                    'messaggio' => "Problema recuperando l'articolo su SAP con codice: {$item['sku']}"
                    ];
                $this->emailMessagge($array);
                return false;
            }
            
            // --- MODIFICA QUI PER GESTIRE LE QUANTITÀ ---
            
            // 1. Salviamo la quantità reale (es. 6)
            $datiArticolo[0]['qty'] = isset($item['qty']) ? $item['qty'] : 1;
            
            // 2. Salviamo il prezzo unitario reale
            $datiArticolo[0]['unit_price'] = isset($item['unit_price']) ? $item['unit_price'] : $item['cost'];
            
            // 3. costo_wc rappresenta il totale di riga (Prezzo x Qty)
            $datiArticolo[0]['costo_wc'] = $item['cost'];
            
            $datiArticolo[0]['nome_wc'] = $item['fullname'];
            
            $this->articoloSap[] = $datiArticolo[0];
        }
        
        $logger->log("Dump array articoloSap con Quantità e Prezzi Unitari");
        $logger->dump($this->articoloSap);
        return $this->articoloSap;
    }
    
    public function createXMLBP()
    {
        $config = new costanti();
        $logger = Logger::get_logger();
        $userSap = new SapModel();
        $this->cardcode = $userSap->setCardCode();
        
        ((! empty($this->userMoodle['0']['IPACodePA'])) ? $this->userMoodle['0']['IPACodePA'] : $this->userMoodle['0']['IPACodePA'] = "0000000");
        
        $str = $this->userMoodle['0']['partitaiva'];
        $pattern = "/^[IT]{2}[0-9]{11}$/";
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
        $this->docNum = $docNum;
        $ws = new sendXml($xml);
        return $ws->sendSoap($this->tipodoc, $this->docNum);
    }
    
    public function alignUser()
    {
        $logger = Logger::get_logger();
        $config = new costanti();
        
        if (! $this->BPSAP) {
            $logger->log("implementazione -> SET BP SAP");
            echo "Utente SAP mancante";
            $logger->log("*** Inserimento utente in SAP");
            $this->XmlBp = $this->createXMLBP();
        } else {
            $allineamento = [];
            
            if (strtoupper(trim($this->userMoodle['0']['Rag'])) != strtoupper(trim($this->BPSAP['NameAddressB']))) {
                $logger->log("{Address B} disallineata");
                $allineamento['B']['Address'] = strtoupper(trim($this->userMoodle['0']['Rag']));
            }
            
            if (strtoupper(trim($this->userMoodle['0']['Rag'])) != strtoupper(trim($this->BPSAP['NameAddressS']))) {
                $logger->log("{Address S} disallineata");
                $allineamento['S']['Address'] = strtoupper(trim($this->userMoodle['0']['Rag']));
            }
            
            $logger->log("*** Utente esiste, verifico allineamento dati");
            $logger->log("*** Verifica allineamento dati fatturazione");
            
            $str = $this->userMoodle['0']['partitaiva'];
            $pattern = "/^[IT]{2}[0-9]{11}$/";
            if (preg_match($pattern, $str) == false && ! empty($this->userMoodle['0']['partitaiva'])) {
                if (preg_match("/^[0-9]{11}$/", $str) == true) {
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
                    $userSap = new SapModel();
                    $logger->log("Recupero i dati SAP dopo inserimento WS");
                    $this->BPSAP = $this->getSapUser();
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
            
            $str = $this->userMoodle['0']['CF'];
            $pattern = "/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/i";
            if (preg_match($pattern, $str) == false && ! empty($this->userMoodle['0']['CF'])) {
                $pattern = "/^[0-9]{11}$/";
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
            
            if ($this->userMoodle['0']['IPACodePA'] == '') {
                $this->userMoodle['0']['IPACodePA'] = '0000000';
            } else {
                $pattern = "/^[A-Z0-9]{7}$/i";
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
            
            if ($this->userMoodle['0']['Rag'] != $this->BPSAP['cardname']) {
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
            
            if ($this->userMoodle['0']['fattprov'] != $this->BPSAP['Prov'] && (! empty($this->userMoodle['0']['fattprov'] || $this->userMoodle['0']['fattprov'] == "SEL"))) {
                $logger->log("{Prov} disallineata");
                $allineamento['B']['State'] = $this->userMoodle['0']['fattprov'];
                $allineamento['S']['State'] = $this->userMoodle['0']['fattprov'];
            }
            
            if (strtolower($this->userMoodle['0']['email']) != strtolower($this->BPSAP['E_Mail']) && ! empty($this->userMoodle['0']['email'])) {
                $logger->log("{E_Mail} disallineata");
                $allineamento['U']['E_Mail'] = strtolower($this->userMoodle['0']['email']);
            }
            
            $logger->log("*** Verifica allineamento dati spedizione");
            
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
                    $this->BPSAP = $this->getSapUser();
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
        $logger = Logger::get_logger();
        
        // 1. Calcoliamo l'imponibile sommando solo i costi dei singoli articoli (items)
        $imponibileReale = 0;
        if (isset($this->userMoodle[1]['items'])) {
            foreach ($this->userMoodle[1]['items'] as $item) {
                $imponibileReale += (float)$item['cost'];
            }
        }
        
        // 2. Recuperiamo il bollo (fee_bollo)
        $this->costbollo = isset($this->userMoodle[1]['fee_bollo']) ? (float)$this->userMoodle[1]['fee_bollo'] : 0;
        
        // 3. Assegniamo l'imponibile corretto (es. 200.00 invece di 202.00)
        $this->cost = number_format($imponibileReale, 2, '.', '');
        
        $logger->log("FIX Mapping Costi: Articoli [" . $this->cost . "] + Bollo [" . $this->costbollo . "] = Totale [" . ($this->cost + $this->costbollo) . "]");
        
        $this->bollo = ($this->costbollo > 0) ? true : false;
        return;
    }
    
    public function getSeries($series, $year)
    {
        $logger = Logger::get_logger();
        $this->series = $series . $year;
        $logger->log("Numero serie per tipo [" . $this->tipo . "]");
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
                    $this->datiInvoice['series'] = $this->getSeries(costanti::SERIESPP, $this->datiInvoice['yearSeries']);
                    break;
                default:
                    $logger->log('1. Metodo di pagamento non riconosciuto :' . $this->tipo);
                    return false;
                    break;
            }
            
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
            
            // RIPRISTINATO IL PAYMENGROUP ORIGINALE PER EVITARE L'ERRORE SAP -2028
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
            
            // COSTRUZIONE ARTICOLI PLURICORSO
            $this->invxmlArticles = '<Document_Lines>';
            
            // Per compatibilità col PDF
            $this->datiInvoice['cost'] = $this->cost;
            $this->datiInvoice['art1'] = $this->articoloSap[0]['itemcode'] ?? '';
            $this->datiInvoice['descrart1'] = $this->articoloSap[0]['itemname'] ?? '';
            
            foreach ($this->articoloSap as $art) {
                // Pulizia descrizione
                $descPulita = html_entity_decode($this->sanitizeString($art['nome_wc']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $descPulita = mb_convert_encoding($descPulita, 'UTF-8', 'ISO-8859-1');
                
                // --- LOGICA DINAMICA QUANTITÀ E PREZZI ---
                $qty = isset($art['qty']) ? $art['qty'] : 1;
                $unit_price = isset($art['unit_price']) ? (float)$art['unit_price'] : (float)$art['costo_wc'];
                // costo_wc nel tuo sistema rappresenta il totale di riga (Qty * Price)
                $line_total = (float)$art['costo_wc'];
                
                $this->invxmlArticles .= '<row>
                                            <ItemCode>' . $art['itemcode'] . '</ItemCode>
                                            <ItemDescription>' . $descPulita . '</ItemDescription>
                                            <Quantity>' . $qty . '</Quantity>
                                            <UnitPrice>' . number_format($unit_price, 2, '.', '') . '</UnitPrice>
                                            <LineTotal>' . number_format($line_total, 2, '.', '') . '</LineTotal>
                                            <VatGroup>' . $art['vatgourpSa'] . '</VatGroup>
                                            <AccountCode>' . $art['RevenuesAc'] . '</AccountCode>
                                        </row>';
            }
            
            $this->datiInvoice['artbollo'] = $config::ITEMCODEBOLLO;
            $this->datiInvoice['costbollo'] = $this->costbollo;
            $this->datiInvoice['descrbollo'] = $config::ITEMDESCRBOLLO;
            
            if ($this->bollo == true) {
                $this->invxmlArticles .= '<row>
                    <ItemCode>' . $config::ITEMCODEBOLLO . '</ItemCode>
                    <ItemDescription>' . $config::ITEMDESCRBOLLO . '</ItemDescription>
                    <Quantity>1</Quantity>
                    <UnitPrice>' . number_format((float) $this->costbollo, 2, '.', '') . '</UnitPrice>
                    <LineTotal>' . number_format((float) $this->costbollo, 2, '.', '') . '</LineTotal>
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
            
            $invXml = $this->invxmlHeader;
            $invXmlBody = $this->invxmlStart;
            $invXmlBody .= $this->invxmlAdmInfo;
            $invXmlBody .= $this->invxmlDocuments;
            $invXmlBody .= $this->invxmlArticles;
            $invXmlBody .= $this->invxmlRate;
            $invXmlBody .= $this->invxmlEnd;
            
            $logger->log("XML Invoice Generato");
            $logger->log($invXmlBody);
            
            $tagOpen = '/</i';
            $tagClose = '/>/i';
            $invXmlBody = preg_replace($tagOpen, '&lt;', $invXmlBody);
            $invXmlBody = preg_replace($tagClose, '&gt;', $invXmlBody);
            
            $invXml .= $invXmlBody;
            $invXml .= $this->invxmlFooter;
            
            $this->invXml = $invXml;
            
            // INVIO A SAP
            $resWS = $this->sendWS($this->invXml, $this->docNum);
            
            if ($resWS == 'check') {
                $resWS = $xml->checkInvoice($this->docNum);
                $logger->log("verifica inserimento fattura: " . $resWS);
            }
            
            if ($resWS == false) {
                $logger->log("errore inserimento fattura WS");
                return false;
            } else {
                $this->datiInvoice['docentry'] = $resWS;
                $logger->log("Fattura inviata correttamente docEntry[" . $this->datiInvoice['docentry'] . "]");
                return true;
            }
        } catch (Exception $e) {
            $logger->log("Error: " . __METHOD__ . "\n" . $e->getMessage() . "\n" . $e->getLine());
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
        $config = new costanti();
        $logger = Logger::get_logger();
        $logger->log('Tipo: ' . $this->tipo);
        
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
            $logger->log("--- Inizio procedura generazione PDF V8 (Dati Dinamici Pluricorso) ---");
            
            if (ob_get_length()) ob_end_clean();
            
            $pdf = new PDF_Invoice('P', 'mm', 'A4');
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 10);
            $pdf->logo('it');
            
            // 1. INTESTAZIONE SOCIETÀ
            $pdf->addSociete("Sede Legale",
                $this->userMoodle['0']['Rag'] . "\n" .
                $this->userMoodle['0']['fattind'] . "\n" .
                $this->userMoodle['0']['fattcap'] . " " .
                $this->userMoodle['0']['fattcomune'] . " " .
                $this->userMoodle['0']['fattprov'] . "\nITALY"
                );
            
            // 2. DATI DOCUMENTO
            $pdf->fact_dev("Fattura di Vendita N.:", $this->datiInvoice['docnum'] . " ");
            
            $pdf->addShip("\nData emissione " . $this->datiInvoice['data2'] .
                "\n\nSpett.le\n" . strtoupper($this->userMoodle['0']['Rag']) . "\n" .
                $this->userMoodle['0']['fattind'] . "\n" .
                $this->userMoodle['0']['fattcap'] . " " .
                $this->userMoodle['0']['fattcomune'] . " " .
                $this->userMoodle['0']['fattprov'] . "\nITALY"
                );
            
            $pdf->datifatt(
                "Codice Cliente: " . $this->BPSAP['cardcode'],
                "Partita Iva : " . $this->BPSAP['partitaiva'],
                "Cod. Fisc. : " . $this->BPSAP['AddId']
                );
            
            // 3. CONFIGURAZIONE TABELLA
            $cols = array(
                "ART" => 30, "DESCRIZIONE" => 70, "Q.TA" => 10,
                "PREZZO UNIT." => 30, "IVA" => 20, "TOT." => 30
            );
            $colsAlign = array(
                "ART" => "L", "DESCRIZIONE" => "L", "Q.TA" => "C",
                "PREZZO UNIT." => "R", "IVA" => "C", "TOT." => "R"
            );
            
            $pdf->addCols($cols);
            $pdf->addLineFormat($colsAlign);
            
            // Forza l'header per pulizia
            $dummy = array("ART"=>" ", "DESCRIZIONE"=>" ", "Q.TA"=>" ", "PREZZO UNIT."=>" ", "IVA"=>" ", "TOT."=>" ");
            $pdf->addLine(100, $dummy);
            
            // Maschera bianca e ripristino intestazioni BOLD
            $pdf->SetFillColor(255, 255, 255);
            $pdf->SetDrawColor(255, 255, 255);
            $pdf->Rect(9, 90, 192, 190, 'DF');
            $pdf->SetDrawColor(0, 0, 0);
            
            $pdf->SetXY(10, 101);
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell(30, 8, 'ART', 1, 0, 'C');
            $pdf->Cell(70, 8, 'DESCRIZIONE', 1, 0, 'C');
            $pdf->Cell(10, 8, 'Q.TA', 1, 0, 'C');
            $pdf->Cell(30, 8, 'PREZZO UNIT.', 1, 0, 'C');
            $pdf->Cell(20, 8, 'IVA', 1, 0, 'C');
            $pdf->Cell(30, 8, 'TOT.', 1, 1, 'C');
            $pdf->SetFont('Arial', '', 10);
            
            $startY = 109;
            $y = 111;
            $limit_y = 235;
            
            // 4. CICLO ARTICOLI CON DATI DINAMICI
            foreach ($this->articoloSap as $art) {
                // Recupero quantità e prezzo unitario reali
                $qty = isset($art['qty']) ? $art['qty'] : 1;
                $unit_price = isset($art['unit_price']) ? (float)$art['unit_price'] : (float)$art['costo_wc'];
                $total_row = (float)$art['costo_wc'];
                
                $desc = $art['itemname'];
                $lines = ceil(strlen($desc) / 38);
                $estimatedHeight = ($lines * 4.5) + 2;
                
                if ($y + $estimatedHeight > $limit_y) {
                    // Chiusura griglia pagina precedente
                    $x_cols = [10, 40, 110, 120, 150, 170, 200];
                    foreach($x_cols as $x) { $pdf->Line($x, $startY, $x, $y); }
                    $pdf->Line(10, $y, 200, $y);
                    
                    $pdf->AddPage();
                    $pdf->SetFont('Arial', '', 10);
                    $pdf->logo('it');
                    $pdf->addCols($cols);
                    $pdf->addLineFormat($colsAlign);
                    $pdf->SetFillColor(255, 255, 255);
                    $pdf->SetDrawColor(255, 255, 255);
                    $pdf->Rect(9, 30, 192, 250, 'DF');
                    $pdf->SetDrawColor(0, 0, 0);
                    $pdf->SetXY(10, 40);
                    $pdf->SetFont('Arial', 'B', 9);
                    $pdf->Cell(30, 8, 'ART', 1, 0, 'C');
                    $pdf->Cell(70, 8, 'DESCRIZIONE', 1, 0, 'C');
                    $pdf->Cell(10, 8, 'Q.TA', 1, 0, 'C');
                    $pdf->Cell(30, 8, 'PREZZO UNIT.', 1, 0, 'C');
                    $pdf->Cell(20, 8, 'IVA', 1, 0, 'C');
                    $pdf->Cell(30, 8, 'TOT.', 1, 1, 'C');
                    $pdf->SetFont('Arial', '', 10);
                    $startY = 48; $y = 50;
                }
                
                $line = array(
                    "ART" => $art['itemcode'],
                    "DESCRIZIONE" => $desc,
                    "Q.TA" => $qty, // Ora stampa 6
                    "PREZZO UNIT." => number_format($unit_price, 2, '.', '') . " EUR", // Ora stampa 190.00
                    "IVA" => "0.00",
                    "TOT." => number_format($total_row, 2, '.', '') . " EUR" // Stampa 1140.00
                );
                
                $actual_size = $pdf->addLine($y, $line);
                $y += max($actual_size, $estimatedHeight);
            }
            
            // 5. GESTIONE MARCA DA BOLLO
            if ($this->bollo) {
                $line = array(
                    "ART" => $this->datiInvoice['artbollo'],
                    "DESCRIZIONE" => $this->datiInvoice['descrbollo'],
                    "Q.TA" => "1",
                    "PREZZO UNIT." => number_format((float)$this->costbollo, 2, '.', '') . " EUR",
                    "IVA" => "0.00",
                    "TOT." => number_format((float)$this->costbollo, 2, '.', '') . " EUR"
                );
                $actual_size = $pdf->addLine($y, $line);
                $y += $actual_size;
            }
            
            // Chiusura griglia finale
            $x_cols = [10, 40, 110, 120, 150, 170, 200];
            foreach($x_cols as $x) { $pdf->Line($x, $startY, $x, $y); }
            $pdf->Line(10, $y, 200, $y);
            
            // 6. RIEPILOGO IVA [cite: 1, 12]
            $y_iva = $y + 5;
            $tot_prods = array(
                array("imponibile" => $this->datiInvoice['cost'], "codiva" => 'Esente art.10 n.20 vendite', "iva" => 0)
            );
            if ($this->bollo) {
                array_push($tot_prods, array("imponibile" => $this->datiInvoice['costbollo'], "codiva" => 'Esente art.15', "iva" => 0));
            }
            $pdf->SetY($y_iva);
            $pdf->addCadreTVAs($y_iva);
            $pdf->addTVAs1($tot_prods, $y_iva);
            
            // Footer e Output [cite: 1, 13]
            $pdf->SetY(-48);
            $pdf->SetFont('Arial', 'I', 8);
            $pdf->Cell(0, 10, 'Condizione di pagamento AVVENUTO', 0, 0, 'L');
            
            // Generazione file fisico
            $this->nomepdf = 'Fattura di vendita_' . $this->datiInvoice['docnum'] . '_' . $this->datiInvoice['data'] . '.pdf';
            $fullPath = $config::DEST_PDF . '/' . $this->nomepdf;
            $pdf->Output($fullPath, 'F');
            $logger->log("PDF salvato in: " . $fullPath);
            
            $logger->log("PDF Generato Correttamente: " . $this->nomepdf);
            
            if (defined('costanti::ENABLE_EMAIL') && $config::ENABLE_EMAIL === true) {
                
                // Determiniamo il destinatario reale o di test
                $destinatario = ($config::DEBUG_EMAIL === true) ? $config::EMAIL_SYSTEM : $this->userMoodle['0']['email'];
                $tagOggetto = ($config::DEBUG_EMAIL === true) ? "[TEST SERVER] " : "";
                
                // Sostituisci il blocco try dell'invio con questo:
                try {
                    $logger->log("Tentativo invio fattura a: " . $destinatario);
                    $mail = new send();
                   
                    $arrayMail = [
                        'mdl_emailLogin' => $config::MAILBOXES[$this->mdl]['login'],
                        'mdl_emailPass'  => $config::MAILBOXES[$this->mdl]['pass'],
                        'mdl_nomecorso'  => $config::MAILBOXES[$this->mdl]['corso'],
                        'oggetto'        => $tagOggetto . 'Fattura corso Medical',
                        'messaggio'      => "Egregio Dottore/Gentile Dottoressa " . strtoupper($this->BPSAP['cardname']) . ",<br>Le inviamo in allegato la fattura di cortesia num. " . $this->datiInvoice['docnum'] . " del " . $this->datiInvoice['data2'] . " per l'iscrizione al " . $this->datiInvoice['descrart1'] . ".<br>" . "L'originale del presente documento &egrave; stato trasmesso in formato elettronico a norma di legge e sar&agrave; disponibile presso il proprio cassetto fiscale dell'Agenzie delle Entrate." . "<br>Cordiali saluti.<br>" . $config::MAILBOXES[$this->mdl]['corso'] . " - Medical Evidence div. MeTMi Srl",
                        'destinatario'   => $destinatario,
                        'pdf'            => $fullPath
                    ];
                    
                    // Verifichiamo il ritorno della funzione
                    $resMail = $mail->sendFattura($arrayMail);
                    
                    if ($resMail) {
                        $logger->log("Email inviata correttamente (SMTP OK).");
                    } else {
                        $logger->log("ERRORE: sendFattura ha restituito FALSE. Verificare mail/send.php");
                    }
                } catch (Exception $e) {
                    $logger->log("ECCEZIONE invio email fattura: " . $e->getMessage());
                }
            }
            return true;
            
        } catch (Exception $e) {
            Logger::get_logger()->log("ERRORE in invoicePdf: " . $e->getMessage());
            return false;
        }
    }
    
    public function emailMessagge(array $array)
    {
        $config = new costanti();
        $logger = Logger::get_logger();
        
        if (defined('costanti::ENABLE_EMAIL') && $config::ENABLE_EMAIL === false) {
            $logger->log("INFO: Invio email disattivato globalmente. Bloccata: " . ($array['oggetto'] ?? 'Senza oggetto'));
            return true;
        }
        
        $destinatario = null;
        switch ($array['destinatario']) {
            case 'moodle': $destinatario = $config::EMAIL_MOODLE; break;
            case 'sap': $destinatario = $config::EMAIL_SAP; break;
            case 'system': $destinatario = $config::EMAIL_SYSTEM; break;
        }
        
        $this->arrQueryStringParams = $this->getQueryStringParams();
        $local = "http://" . $config::URL . "/index.php/sap/ins?";
        
        $piece = explode('/', $this->nome_log);
        $logfilename = end($piece);
        
        $array = [
            'oggetto' => $array['oggetto'] . " - " . $config::EMAIL_OBJECT,
            'messaggio' => $array['messaggio'] . "<br><br>" . "Per rilanciare la procedura clicca " . "<a href =\"$local" . "id=" . $this->arrQueryStringParams['id'] . "\">qui</a>" . "<br>Dettaglio: <a href=\"http://" . $config::URL . "/logs/" . $logfilename . "\">" . $logfilename . "</a>",
            'destinatario' => $destinatario
        ];
        
        $mail = new send();
        $mail->sendEmail($array);
        
        $log = new MoodleModel('mdlapps_moodleadmin');
        $log->traceLog($this->arrQueryStringParams, $this->nome_log);
    }
    
    private function _sanitizeForDatabase($string)
    {
        if (! is_string($string) || empty($string)) {
            return $string;
        }
        
        $string = html_entity_decode($string, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        $search = ['à','è','é','ì','ò','ù','À','È','É','Ì','Ò','Ù','á','Á','í','Í','ó','Ó','ú','Ú','ñ','Ñ',"'",'’','‘','`','"','“','”','´','–','—','-'];
        $replace = ['a','e','e','i','o','u','A','E','E','I','O','U','a','A','i','I','o','O','u','U','n','N',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' '];
        
        $string = str_replace($search, $replace, $string);
        $string = preg_replace('/\s+/', ' ', $string);
        
        return trim($string);
    }
    
    private function sanitizeString($str)
    {
        if (empty($str) || ! is_string($str)) {
            return '';
        }
        
        $replacements = [
            "'" => " ", "\xe2\x80\x98" => " ", "\xe2\x80\x99" => " ", "\xe2\x80\x9a" => " ", "\xe2\x80\x9b" => " ",
            "à" => "a", "á" => "a", "â" => "a", "ä" => "a", "å" => "a", "è" => "e", "é" => "e", "ê" => "e", "ë" => "e",
            "ì" => "i", "í" => "i", "î" => "i", "ï" => "i", "ò" => "o", "ó" => "o", "ô" => "o", "ö" => "o",
            "ù" => "u", "ú" => "u", "û" => "u", "ü" => "u", "y" => "y", "ÿ" => "y", "ñ" => "n", "ç" => "c",
            "À" => "A", "Á" => "A", "Â" => "A", "Ä" => "A", "Å" => "A", "È" => "E", "É" => "E", "Ê" => "E", "Ë" => "E",
            "Ì" => "I", "Í" => "I", "Î" => "I", "Ï" => "I", "Ò" => "O", "Ó" => "O", "Ô" => "O", "Ö" => "O",
            "Ù" => "U", "Ú" => "U", "Û" => "U", "Ü" => "U", "Ñ" => "N", "Ç" => "C",
            "\xe2\x80\x9c" => "", "\xe2\x80\x9d" => "", '"' => "", "\xe2\x80\x93" => "-", "\xc2\xa0" => " "
        ];
        
        $str = strtr($str, $replacements);
        $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $str = preg_replace('/\s+/', ' ', $str);
        
        return trim($str);
    }
}