<?php
// include the logger file
require_once PROJECT_ROOT_PATH . "phplogger.php";
require_once PROJECT_ROOT_PATH . "inc/config_es.php";
require_once PROJECT_ROOT_PATH . 'curl/sendXml.php';
require_once PROJECT_ROOT_PATH . 'mail/send.php';
require_once PROJECT_ROOT_PATH . 'pdf/invoice.php';
require_once PROJECT_ROOT_PATH . 'Model/SapModelEs.php';
require_once PROJECT_ROOT_PATH . 'Model/DBSapEs.php';
require_once PROJECT_ROOT_PATH . 'inc/utility.php';

class UserControllerEs extends BaseController
{

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

    /**
     * inserimento fattura in SAP da Moodle
     *
     * GET mooodle utente e corso
     * GET sap e verifico utente
     * GET sap e verifico articolo
     * Check allineamento utente, dati fatturazione e spedizione
     * SET xml invoice
     * CURL set WS XML
     * SAVE RESULT
     * SAVE INVOICE PDF
     */
    public function insInvoice()
    {
        $logger = Logger::get_logger();
        $this->nome_log = $logger->logname;
        $logger->do_write("\nmethod: " . __METHOD__);
        $config = new costantiEs();
        # Prendo da URL l'ID passato e riassegno l'array con i valori del record
        $this->arrQueryStringParams = $this->getQueryStringParams();
        $this->arrQueryStringParams = $this->getMoodlePayments($this->arrQueryStringParams["id"]);
        $logger->dump($this->arrQueryStringParams);

        # GET mooodle utente e corso
        if (! $this->userMoodle = $this->getMoodle()) {
            $logger->log("getMoodle error!");
            exit();
        }

        # echo "<br>GET mooodle utente e corso: ok ";

        # GET sap
        if (! $this->BPSAP = $this->getSapUser())
            exit();

        # echo "<br>GET SAP: ok ";

        # verifico allineamento utente SAP/Moodle
        if (! $this->checkAlignUser = $this->alignUser())
            exit();
        # echo "<br>verifico allineamento utente SAP/Moodle: ok ";

        # GET sap e verifico articolo
        if (! $this->sapArticle = $this->getSapArticle())
            exit();
        # echo "<br>GET sap e verifico articolo: ok";

        # FUNC di conf costi corso e bollo
        $this->getCostInv();
        # echo "<br>FUNC di conf costi corso e bollo: ok ";

        # genero fattura
        $this->arrQueryStringParams = $this->getMoodlePayments($this->arrQueryStringParams["id"]);
        $this->tipodoc = 'invoice';
        $this->tipo = $this->arrQueryStringParams['tipo'];
        if (! $this->createXMLInv()) {
            $array = [
                'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                'destinatario' => 'system',
                'messaggio' => "Problemi inserimento fattura"
            ];

            $this->emailMessagge($array);
            return false;
        } else {
            # echo "<br> > genero fattura ok";

            $this->mdl = $this->arrQueryStringParams['mdl'];
            $this->userid = $this->arrQueryStringParams['userid'];
            $this->courseid = $this->arrQueryStringParams['courseid'];

            $userModel = new dbmoodle('mdlapps_moodleadmin');
            $sql = "INSERT INTO `invoice` (`mdl`, `userid`, `courseid`, `cardcode`, `cardname`, `codicefiscale`, `partitaiva`,`nfattura`)" . " VALUES ('" . $this->arrQueryStringParams['mdl'] . "'," . $this->arrQueryStringParams['userid'] . ", " . $this->arrQueryStringParams['courseid'] . ",'" . $this->BPSAP['cardcode'] . "','" . $this->BPSAP['cardname'] . "','" . $this->BPSAP['AddId'] . "','" . $this->BPSAP['partitaiva'] . "','" . $this->datiInvoice['docnum'] . "');";

            if (! $userModel->create($sql)) {
                $logger->log("problemi inserendo la fattura: " . $sql);
                return false;
            }

            $sql = "UPDATE `moodle_payments` set sales='1' WHERE sales='0' AND courseid='" . $this->arrQueryStringParams['courseid'] . "' AND userid='" . $this->arrQueryStringParams['userid'] . "' AND mdl='" . $this->arrQueryStringParams['mdl'] . "';";
            if (! $userModel->create($sql)) {
                $logger->log("problemi aggiornando i pagamenti paypal: " . $sql);
                return false;
            }

            # echo " -> ok";
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

    public function incasso()
    {
        # print_r($this->datiInvoice);
        $config = new costantiEs();
        $logger = Logger::get_logger();
        $logger->log('Tipo: ' . $this->tipo);

        # imposto il conto della fattura
        switch ($this->tipo) {
            case 'manual':
                $this->contobancario = costantiEs::CCBONIFICO;
                $this->datiInvoice['commissioni'] = 0;
                $this->datiInvoice['impnetto'] = $this->datiInvoice['costtot'];
                $this->datiInvoice['data1'] = date("Ymd", strtotime($this->userMoodle['1']['datapagamento']));
                break;
            case 'paypal':
                $this->contobancario = costantiEs::CCPAYPAL;
                $this->datiInvoice['commissioni'] = (($this->datiInvoice['costtot'] * 3.40) / 100) + 0.35;
                $this->datiInvoice['impnetto'] = $this->datiInvoice['costtot'] - $this->datiInvoice['commissioni'];
                break;
            default:
                $logger->log('Metodo di pagamento non riconosciuto :' . $this->tipo);
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
        # $logger->log($this->invXml);

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
            $config = new costantiEs();
            $logger = Logger::get_logger();
            $logger->log("Dump array datiInvoice");
            $logger->dump($this->datiInvoice);
            ob_end_clean();

            $pdf = new PDF_Invoice('P', 'mm', 'A4');

            $pdf->AddPage();
            $pdf->logo('es');
            #$pdf->addSociete("Oficina registrada", iconv('UTF-8', 'CP1252', $this->userMoodle['0']['Rag']) . "\n" . iconv('UTF-8', 'CP1252', $this->userMoodle['0']['fattind']) . "\n" . $this->userMoodle['0']['fattcap'] . " " . iconv('UTF-8', 'CP1252', $this->userMoodle['0']['fattcomune']) . " " . $this->userMoodle['0']['fattprov'] . "\nESPAÑA");
            $pdf->addSociete("Oficina registrada", $this->userMoodle['0']['Rag'] . "\n" . $this->userMoodle['0']['fattind'] . "\n" . $this->userMoodle['0']['fattcap'] . " " . iconv('UTF-8', 'CP1252', $this->userMoodle['0']['fattcomune']) . " " . $this->userMoodle['0']['fattprov'] . "\nESPAÑA");
            $pdf->fact_dev("Factura de venta N°:", $this->datiInvoice['docnum'] . " ");

            $pdf->addShip("\nFecha de factura: " . $this->datiInvoice['data2'] . "\n\nEstimado\n" . 
            # strtoupper($this->userMoodle['0']['Rag'])."\n" .
            # iconv('UTF-8', 'CP1252', $this->userMoodle['0']['Rag']) . "\n" . iconv('UTF-8', 'CP1252', $this->userMoodle['0']['fattind']) . "\n" . $this->userMoodle['0']['fattcap'] . " " . iconv('UTF-8', 'CP1252', $this->userMoodle['0']['fattcomune']) . " " . $this->userMoodle['0']['fattprov'] . "\nESPAÑA");
            $this->userMoodle['0']['Rag'] . "\n" . $this->userMoodle['0']['fattind'] . "\n" . $this->userMoodle['0']['fattcap'] . " " . iconv('UTF-8', 'CP1252', $this->userMoodle['0']['fattcomune']) . " " . $this->userMoodle['0']['fattprov'] . "\nESPAÑA");
            
            $pdf->datifatt("Codigo del cliente: " . $this->BPSAP['cardcode'], "Número de valor agregado : " . $this->BPSAP['partitaiva'], "Cód. Fisc. : " . $this->BPSAP['AddId']);

            // Griglia dettaglio
            $cols = array(
                "ART" => 30,
                "DESCRIPCIÓN" => 70,
                "CANT." => 10,
                "PRECIO UNIT." => 30,
                "IVA" => 20,
                "TOT." => 30
            );
            $pdf->addCols($cols);

            $cols = array(
                "ART" => "L",
                "DESCRIPCIÓN" => "L",
                "CANT." => "C",
                "PRECIO UNIT." => "R",
                "IVA" => "C",
                "TOT." => "R"
            );

            $pdf->addLineFormat($cols);
            $y = 109;

            $line = array(
                "ART" => $this->datiInvoice['art1'],
                "DESCRIPCIÓN" => $this->datiInvoice['descrart1'] . "\n" .$this->userMoodle['0']['Rag'],
                "CANT." => "1",
                "PRECIO UNIT." => $this->datiInvoice['cost'] . " EUR",
                "IVA" => "0.00",
                "TOT." => $this->datiInvoice['cost'] . " EUR"
            );
            $logger->log(__LINE__);
            $logger->dump($line);
            $size = $pdf->addLine($y, $line);
            
            $logger->log(__LINE__);
            if ($this->bollo) {
                $y += $size + 2;
                $line = array(
                    "ART" => $this->datiInvoice['artbollo'],
                    "DESCRIPCIÓN" => $this->datiInvoice['descrbollo'],
                    "CANT." => "1",
                    "PRECIO UNIT." => $this->datiInvoice['costbollo'] . " EUR",
                    "IVA" => "0.00",
                    "TOT." => $this->datiInvoice['costbollo'] . " EUR"
                );
                $logger->log(__LINE__);
                $size = $pdf->addLine($y, $line);
            }
            $logger->log(__LINE__);
            $y += $size + 2;

            $tot_prods = array(
                array(
                    "imponibile" => $this->datiInvoice['cost'],
                    # "codiva"=>"Exenta de IVA Art.20 Uno 10° L.37/1992",
                    "codiva" => "Exenta Art.20 Uno 10°",
                    "iva" => 0
                )
            );
            $logger->log(__LINE__);
            if ($this->bollo == true) {
                array_push($tot_prods, array(
                    "imponibile" => $this->datiInvoice['costbollo'],
                    "codiva" => 'Exenta art.15',
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
            $logger->log(__LINE__);
            $pdf->addCadreTVAs(); # disegno contenitore
            $pdf->addTVAs1($tot_prods);

            // Position at 1.5 cm from bottom
            $pdf->SetY(- 60);
            $pdf->SetFont('Arial', 'I', 8);
            $pdf->Cell(0, 10, 'Condición de pago COMPLETADA', 0, 0, 'L');

            $this->nomepdf = 'Fattura di vendita_' . $this->datiInvoice['docnum'] . '_' . $this->datiInvoice['data'] . '.pdf';
            # da rimettere a I/F
            $pdf->Output($config::DEST_PDF . '/' . $this->nomepdf, 'F');
            if (! file_exists($config::DEST_PDF . '/' . $this->nomepdf)) {
                $logger->log("Generazione fattura PDF [" . $config::DEST_PDF . '/' . $this->nomepdf . "] non effettuata");
                return FALSE;
            }
            $logger->log(__LINE__);
            
            // invio la fattura di cortesia solo se l email personale è valorizzata
            if (! empty($this->userMoodle['0']['email'])) {
                $mail = new send();
                
                $mess = '<br>Le enviamos la factura relativa al curso ' . $config::MAILBOXES[$this->mdl]['corso'] . '.<br>Un saludo,<br>Secretaria administrativa Medical Evidence - División de Marketing & Telematica España, S.L.';
                $mess = mb_convert_encoding($mess, "UTF-8", "Windows-1252");
                $mess = 'Estimada Doctora/Estimado Doctor ' . iconv("ISO-8859-1//TRANSLIT", "UTF-8", strtoupper($this->BPSAP['cardname'])) . $mess;
                $logger->log(mb_convert_encoding($mess, "UTF-8", "Windows-1252"));
                #die();
                $array = [
                    'mdl_emailLogin' => $config::MAILBOXES[$this->mdl]['login'],
                    'mdl_emailPass' => $config::MAILBOXES[$this->mdl]['pass'],
                    'mdl_nomecorso' => $config::MAILBOXES[$this->mdl]['corso'],
                    'oggetto' => 'Fattura corso Medical',
                    'messaggio' => $mess,
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
            echo $e->getMessage();
            die($e->getLine());
            return false;
        }
        return true;
    }

    public function emailMessagge(array $array)
    {
        $config = new costantiEs();
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
        $local = "http://" . $config::URL . "/index.php/sap/ins?";

        $piece = explode('/', $this->nome_log);
        $logfilename = end($piece);

        $array = [
            'oggetto' => $array['oggetto'] . " - " . $config::EMAIL_OBJECT,
            'messaggio' => $array['messaggio'] . "<br><br>" . "Per rilanciare la procedura clicca " . "<a href =\"$local" . "mdl=" . $this->arrQueryStringParams['mdl'] . "&courseid=" . $this->arrQueryStringParams['courseid'] . "&userid=" . $this->arrQueryStringParams['userid'] . "&tipo=" . $this->arrQueryStringParams['tipo'] . "&payment_id=" . $this->arrQueryStringParams['payment_id'] . "\">qui</a>" . "<br>Dettaglio: <a href=\"http://" . $config::URL . "/logs/" . $logfilename . "\">" . $logfilename . "</a>",
            'destinatario' => $destinatario
        ];

        $mail = new send();
        $mail->sendEmail($array);

        # salvo il nome log in DB
        $log = new MoodleModel('mdlapps_moodleadmin');
        $log->traceLog($this->arrQueryStringParams, $this->nome_log);
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
        $config = new costantiEs();
        $this->arrQueryStringParams = $this->getQueryStringParams();
        // da togliere!!!
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
                    $userSap = new SapModelEs();
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
            $pattern = "/^[A-Z0-9]{1}[0-9]{7}[A-Z]{1}$/i"; // Pattern standart
            if (preg_match($pattern, $str) == false && ! empty($this->userMoodle['0']['CF'])) {
                $logger->log("{Codice Fiscale} Moodle non valido: [" . $this->userMoodle['0']['CF'] . "]");
                $array = [
                    'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                    'destinatario' => 'moodle',
                    'messaggio' => "Codice Fiscale Moodle non valido [" . $str . "] per l'utente " . $this->userMoodle['0']['nome']
                ];

                $this->emailMessagge($array);
                return false;
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

            if ($this->userMoodle['0']['fattprov'] != $this->BPSAP['Prov'] && ! empty($this->userMoodle['0']['fattprov'])) {
                $logger->log("{Prov} disallineata");
                $allineamento['B']['State'] = $this->provind;
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
                    'messaggio' => "Dati fatturazione Moodle incompleti"
                ];

                $this->emailMessagge($array);
                return 0;
            }

            if ($this->userMoodle['0']['Rag'] != $this->BPSAP['Name']) {
                $logger->log("{Address} disallineata");
                $allineamento['S']['Address'] = $this->userMoodle['0']['Rag'];
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

            if (! empty($allineamento)) {
                $logger->log("Dump array allineamento");
                $logger->dump($allineamento);
                $userSap = new SapModelEs();
                if (! $userSap->setAlign($this->BPSAP['cardcode'], $allineamento)) {
                    $logger->log("*** Problemi allineando utente");
                    $array = [
                        'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                        'destinatario' => 'system',
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

    public function createXMLBP()
    {
        try {
            # print_r($this->userMoodle);
            $config = new costantiEs();
            $logger = Logger::get_logger();
            $userSap = new SapModelEs();
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
                                <conguaglio>' . $config::CONGUAGLIO . '</conguaglio>
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

            # $logger->log($invXml);
            return $invXml;
        } catch (Exception $e) {
            echo "err: " . $e->getMessage();
            $logger->log("Errore: " . $e->getMessage());
            $logger->log('Error on line ' . $e->getLine() . ' in ' . $e->getFile());
            return false;
        }
    }

    /* creazione body soap per inserimento fatture */
    public function createXMLInv()
    {
        $util = new utility();
        $logger = Logger::get_logger();
        $config = new costantiEs();

        $this->datiInvoice = [];
        switch ($this->tipo) {
            case 'manual':
                $this->datiInvoice['data'] = date("dmY_His", strtotime($this->userMoodle['1']['datapagamento']));
                $this->datiInvoice['data1'] = date("Ymd", strtotime($this->userMoodle['1']['datapagamento']));
                $this->datiInvoice['data2'] = date("d.m.Y", strtotime($this->userMoodle['1']['datapagamento']));
                $this->datiInvoice['yearSeries'] = date("y", strtotime($this->userMoodle['1']['datapagamento']));
                $this->datiInvoice['series'] = $this->getSeries(costantiEs::SERIESBB, $this->datiInvoice['yearSeries']);
                break;
            case 'paypal':
            case 'els_paypal':
                $this->datiInvoice['data'] = date('dmY_His');
                $this->datiInvoice['data1'] = date('Ymd');
                $this->datiInvoice['data2'] = date("d.m.Y");
                $this->datiInvoice['yearSeries'] = date("y");
                $this->datiInvoice['series'] = $this->getSeries(costantiEs::SERIESPP, $this->datiInvoice['yearSeries']);
                break;
            default:
                $logger->log('Metodo di pagamento non riconosciuto :' . $this->tipo);
                return false;
                break;
        }

        # header
        # $this->invxmlHeader
        # $this->invxmlBody
        # $this->invxmlFooter

        $xml = new SapModelEs();
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
                                    <ShipToCode>' . $util->normalizzaTesto($this->BPSAP['ShipToDef']) . '</ShipToCode>
                                    <DocDate>' . $this->datiInvoice['data1'] . '</DocDate>
                                    <TaxDate>' . $this->datiInvoice['data1'] . '</TaxDate>
                                    <DocCurrency>' . $config::CURRENCY . '</DocCurrency>
                                    <PaymentMethod>' . $this->BPSAP['pymcode'] . '</PaymentMethod>
                                    <PaymentGroupCode>' . $config::PAYMENGROUP . '</PaymentGroupCode>
                                    <DocTotal>' . number_format(($this->cost), 2, '.', '') . '</DocTotal>
                                    <U_B1SYS_INV_TYPE>' . $config::INV_TYPE . '</U_B1SYS_INV_TYPE>
                                </row>
                            </Documents>';

        $this->datiInvoice['docnum'] = $this->docNum;
        $this->datiInvoice['cardcode'] = $this->BPSAP['cardcode'];

        $this->invxmlArticles = '<Document_Lines>';
        $this->invxmlArticles .= '<row>
                                    <ItemCode>' . $this->sapArticle[0]['itemcode'] . '</ItemCode>
				                    <ItemDescription>' . $util->normalizzaTesto($this->sapArticle[0]['itemname'] . ' - ' . $this->BPSAP['ShipToDef']) . '</ItemDescription>
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
            $this->datiInvoice['docentry'] = $resWS;
            echo "<br>Fattura inviata correttamente docEntry[" . $this->datiInvoice['docentry'] . "]";
            $logger->log("Fattura inviata correttamente docEntry[" . $this->datiInvoice['docentry'] . "]");
            return true;
        }
    }

    public function getSeries($series, $year)
    {
        $logger = Logger::get_logger();
        $this->series = $series . $year;
        $logger->log("Numero serie per tipo [" . $this->tipo . "]");
        $xml = new SapModelEs();
        $logger->log('Nuovo documento di numerazione serie : ' . $this->series);
        $this->numSeries = $xml->getNumSeries($this->series);
        $logger->log('Numero serie : ' . $this->numSeries);
        return $this->numSeries;
    }

    public function getSapUser()
    {
        try {
            // recupero dati da SAP
            $logger = Logger::get_logger();
            $userSap = new SapModelEs();
            $config = new costantiEs();
            $logger->log("Recupero dati utenti da SAP [" . costantiEs::STATIND . "]");
            $this->arrQueryStringParams = $this->getQueryStringParams();

            // utente non presente per CF su SAP
            if (! $this->clienteSap = $userSap->getUsers($this->clienteMoodle[0]['CF'])) {
                $logger->log("Cliente " . $this->clienteMoodle[0]['Rag'] . " {" . $this->clienteMoodle[0]['CF'] . "} non presente su SAP ");
                $this->tipodoc = 'bp';
                $xml = $this->createXMLBP($this->clienteMoodle[0]);
                // $logger->log($xml);

                if ($this->sendWS($xml) == true) {
                    // allineo modalità di pagamento CBI dopo inserimento
                    $logger->log("Recupero i dati SAP dopo inserimento WS");
                    $this->clienteSap = $userSap->getUsers($this->clienteMoodle[0]['CF']);
                } else {
                    $logger->log("errore recuperando SAP dopo inserimento WS - 2");
                    $array = [
                        'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                        'destinatario' => 'sap',
                        'messaggio' => "errore recuperando SAP dopo inserimento BP {CF: " . $this->clienteMoodle[0]['CF'] . "}"
                    ];

                    $this->emailMessagge($array);
                    exit();
                }
            }

            $logger->log("Dump array clientiSap");
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
        $config = new costantiEs();
        $this->arrQueryStringParams = $this->getQueryStringParams();

        $logger->log("Recupero corso -> articolo presente su SAP");
        $userSap = new SapModelEs();

        if (! $this->articoloSap = $userSap->getItem($this->clienteMoodle[1]['idnumber'])) {
            $logger->log("Problema resuperanto l'articolo su SAP {$this->clienteMoodle[1]['idnumber']} [" . __METHOD__ . "]");
            $array = [
                'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                'destinatario' => 'sap',
                'messaggio' => "Problema recuperando l'articolo Moodle su SAP {$this->clienteMoodle[1]['idnumber']}"
            ];

            $this->emailMessagge($array);
            return false;
        }

        $logger->log("Dump array articoloSap");
        $logger->dump($this->articoloSap);
        return $this->articoloSap;
    }

    public function getMoodle()
    {
        $config = new costantiEs();
        # $this->arrQueryStringParams = $this->getQueryStringParams();
        $logger = Logger::get_logger();
        $logger->log("Variabili QueryStringParams");

        $userModel = new UserModel($this->arrQueryStringParams['mdl']);
        
        // verifica campi setting moodle
        $errorConfigMoodle = $userModel->checkSettingMoodle();
        if (! empty($errorConfigMoodle)) {
            $array = [
                'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                'destinatario' => 'moodle',
                'messaggio' => $errorConfigMoodle
            ];

            $this->emailMessagge($array);
            return false;
        }

        // recupero dati da Moodle
        $logger->log("Recupero dati utenti e corso da Moodle [" . $this->arrQueryStringParams['mdl'] . "]");

        if (! $this->clienteMoodle = $userModel->getUsers($this->arrQueryStringParams['userid'], $this->arrQueryStringParams['courseid'], $this->arrQueryStringParams['tipo'], $this->arrQueryStringParams['payment_id'], $this->arrQueryStringParams['mdl'], $this->arrQueryStringParams['cost'])) {
            $logger->log("Problema resuperando i dati pagamento utente");

            $array = [
                'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                'destinatario' => 'system',
                'messaggio' => 'Problema resuperando i dati pagamento utente'
            ];

            $this->emailMessagge($array);
            return false;
        }

        // mappatura codice provincia spagnolo
        $userSap = new SapModelEs();

        # $this->userMoodle['0']['fattprov'] = $userSap->setState($this->clienteMoodle['0']['fattprov']);
        if (empty($this->clienteMoodle['0']['fattprov'] = $userSap->setState($this->clienteMoodle['0']['fattprov']))) {
            $logger->log("Provincia Moodle non valida: " . __METHOD__);
            $array = [
                'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                'destinatario' => 'moodle',
                'messaggio' => 'Provincia Moodle non valida [' . $this->clienteMoodle['0']['CF'] . '], impossibile inserire fattura'
            ];

            $this->emailMessagge($array);
            return false;
        }

        if (empty($this->clienteMoodle['0']['nome'])) {
            $logger->log("Nome e/o Cognome non valorizzato: " . __METHOD__);
            $array = [
                'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                'destinatario' => 'moodle',
                'messaggio' => 'Nome e/o Cognome non valirizzato per [' . $this->clienteMoodle['0']['CF'] . '], impossibile inserire fattura'
            ];

            $this->emailMessagge($array);
            return false;
        }

        if (empty($this->clienteMoodle['0']['Rag'])) {
            $logger->log("Rag. Soc. non valorizzata: " . __METHOD__);
            $array = [
                'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                'destinatario' => 'moodle',
                'messaggio' => 'Rag. Soc. non valirizzata per [' . $this->clienteMoodle['0']['CF'] . '], impossibile inserire fattura'
            ];

            $this->emailMessagge($array);
            return false;
        }

        if (empty($this->clienteMoodle['1']['cost'])) {
            $logger->log("Costo corso nullo, impossibile inserire fattura: " . __METHOD__);
            $array = [
                'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                'destinatario' => 'moodle',
                'messaggio' => 'Costo corso [' . $this->clienteMoodle['1']['fullname'] . '] nullo, impossibile inserire fattura'
            ];

            $this->emailMessagge($array);
            return false;
        }

        if (empty($this->clienteMoodle['0']['CF'])) {
            $logger->log("Codice fiscale non presente su [" . $this->arrQueryStringParams['mdl'] . "] per l'id: " . $this->arrQueryStringParams['userid']);
            $array = [
                'oggetto' => $config::MAILBOXES[$this->arrQueryStringParams['mdl']]['corso'],
                'destinatario' => 'moodle',
                'messaggio' => "Codice fiscale non presente su [" . $this->arrQueryStringParams['mdl'] . "] per " . $this->clienteMoodle['0']['nome']
            ];

            $this->emailMessagge($array);
            return false;
        }

        $logger->log("Dump array clinteMoodle");
        $logger->dump($this->clienteMoodle);

        return $this->clienteMoodle;
    }

    public function getCostInv()
    {
        // memorizzo costo corso e formato
        $this->cost = number_format(($this->clienteMoodle[1]['cost']), 2, '.', '');

        // if bollo memorizzo formato e costo bollo senno metto 0
        $this->bollo = false;
        $this->costbollo = "0.00";
        return;
    }

    /**
     * "/user/list" Endpoint - Get list of users
     */
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
}