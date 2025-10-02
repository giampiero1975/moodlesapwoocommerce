<?php
require_once PROJECT_ROOT_PATH . "/Model/DBSap.php";
require_once PROJECT_ROOT_PATH . "phplogger.php";

class SapModel extends dbsap
{

    public function checkInvoice($docNumber)
    {
        # $this->series = $series;
        $logger = Logger::get_logger();
        $sql = "SELECT DocEntry FROM dbo.OINV WHERE docNum='" . $docNumber . "'";

        $logger->log($sql);
        $checkDocEntry = $this->select($sql);
        $logger->log("Dump array checkDocEntry");
        $logger->dump($checkDocEntry);
        if (empty($checkDocEntry))
            return false;
        else
            return $checkDocEntry[0]['DocEntry'];
    }

    public function getNumSeries($series)
    {
        $this->series = $series;
        $logger = Logger::get_logger();
        $sql = "SELECT Series from dbo.NNM1 where seriesName='" . $this->series . "'";

        $logger->log($sql);
        $numSeries = $this->select($sql);

        return $numSeries[0]['Series'];
    }

    public function getDocNumber($series)
    {
        $this->series = $series;
        $logger = Logger::get_logger();
        $sql = "SELECT NextNumber from dbo.NNM1 where series=" . $this->series;

        $logger->log($sql);
        $nextDocNum = $this->select($sql);

        return $nextDocNum[0]['NextNumber'];
    }

    public function setCardCode()
    {
        $logger = Logger::get_logger();
        $sql = "SELECT (max(SUBSTRING ( cardcode ,3 , len(cardcode) ))+1) as 'maxCardCode' FROM OCRD WHERE cardcode LIKE 'CM%';";
        $logger->log($sql);
        $nextCardCode = $this->select($sql);
        $nCardcode = $nextCardCode['0']['maxCardCode']; //
        $lenCardcode = strlen($nCardcode);

        // CM000001
        $format = '';
        for ($i = $lenCardcode; $i < 6; $i ++) {
            $format .= "0";
        }

        $this->nCardcode = "CM" . $format . $nCardcode;
        $logger->log("nuovo CardCode generato: " . $this->nCardcode);
        return $this->nCardcode;
    }

    public function getUsers($cf)
    {
        $logger = Logger::get_logger();
        $this->cf = $cf;

        // Get anagrafica su OCRD
        $sql = "SELECT TOP 1 OCRD.cardcode, OCRD.cardname, OCRD.AddId, OCRD.LicTradNum AS 'partitaiva', IPACodePA, E_Mail, PECAddr, ShipToDef, BillToDef, pymcode FROM OCRD WHERE OCRD.AddId = '" . $this->cf . "' and cardcode like 'CM%'  ORDER BY cardcode desc;";
        $logger->log("*** GET anagrafica da OCRD: " . $sql);
        $getRegistry = $this->select($sql);
        if (empty($getRegistry)) {
            $logger->log("utente non presente in SAP");
            return false;
        }

        $logger->dump($getRegistry);

        // Get indirizzo di fatturazione
        $sql = "SELECT CRD1.Address, CRD1.Street, CRD1.ZipCode, CRD1.City, CRD1.State, CRD1.AdresType FROM CRD1 WHERE cardcode = '" . $getRegistry['0']['cardcode'] . "' AND AdresType='B';";
        $logger->log("*** GET indirizzo fatturazione CRD1: " . $sql);
        $getBillingAddress = $this->select($sql);
        $logger->dump($getBillingAddress);

        // Get indirizzo di Spedizione
        $sql = "SELECT CRD1.Address, CRD1.Street, CRD1.ZipCode, CRD1.City, CRD1.State, CRD1.AdresType FROM CRD1 WHERE cardcode = '" . $getRegistry['0']['cardcode'] . "' AND AdresType='S';";
        $logger->log("*** GET indirizzo spedizione CRD1: " . $sql);
        $getShippingAddress = $this->select($sql);

        if (empty($getShippingAddress)) {
            $logger->log("Indirizzo di spedizione mancante, procedo con la duplica indirizzo di Billing");
            $sql = "INSERT INTO CRD1 (Address, CardCode, Street, Block, ZipCode, City, County, Country, State, UserSign, LogInstanc, ObjType, LicTradNum, LineNum, TaxCode, Building, Address2, Address3, AddrType, StreetNo, AltCrdName, AltTaxId, TaxOffice, GlblLocNum, Ntnlty, DIOTNat, TaaSEnbl, GSTRegnNo, GSTType, CreateDate, CreateTS, EncryptIV, MYFType, VatResDate, VatResCode, VatResName, VatResAddr, U_RTS_Latitude, U_RTS_Longitude, AdresType) " . "SELECT Address, CardCode, Street, Block, ZipCode, City, County, Country, State, UserSign, LogInstanc, ObjType, LicTradNum, LineNum, TaxCode, Building, Address2, Address3, AddrType, StreetNo, AltCrdName, AltTaxId, TaxOffice, GlblLocNum, Ntnlty, DIOTNat, TaaSEnbl, GSTRegnNo, GSTType, CreateDate, CreateTS, EncryptIV, MYFType, VatResDate, VatResCode, VatResName, VatResAddr, U_RTS_Latitude, U_RTS_Longitude,'S' AS AdresType from CRD1 WHERE cardcode = '" . $getRegistry['0']['cardcode'] . "'";
            $logger->log($sql);
            if (! $this->updCrm($sql))
                return false;

            $sql = "SELECT CRD1.Street, CRD1.ZipCode, CRD1.City, CRD1.State, CRD1.AdresType FROM CRD1 WHERE cardcode = '" . $getRegistry['0']['cardcode'] . "' AND AdresType='S';";
            $logger->log("*** GET indirizzo spedizione CRD1: " . $sql);
            $getShippingAddress = $this->select($sql);
        }
        $logger->dump($getShippingAddress);

        /*
         * print_r($getRegistry);
         * print_r($getBillingAddress);
         * print_r($getShippingAddress);
         */

        // Array merge di dati anagrafica, indirizzo di fatturazione e spedizione
        $businesspatner = [
            'cardcode' => $getRegistry['0']['cardcode'],
            'cardname' => $getRegistry['0']['cardname'],
            'AddId' => $getRegistry['0']['AddId'],
            'partitaiva' => $getRegistry['0']['partitaiva'],
            'E_Mail' => strtolower($getRegistry['0']['E_Mail']),
            'PEC' => strtolower($getRegistry['0']['PECAddr']),
            'IPACodePA' => $getRegistry['0']['IPACodePA'],
            'ShipToDef' => $getRegistry['0']['ShipToDef'],
            'BillToDef' => $getRegistry['0']['BillToDef'],
            'pymcode' => $getRegistry['0']['pymcode'],
            // fatturazione
            'NameAddressB' => $getBillingAddress['0']['Address'],
            'Address' => $getBillingAddress['0']['Street'],
            'City' => $getBillingAddress['0']['City'],
            'ZipCode' => $getBillingAddress['0']['ZipCode'],
            'Prov' => $getBillingAddress['0']['State'],
            // Spedizione
            'NameAddressS' => $getShippingAddress['0']['Address'],
            'MailAddres' => $getShippingAddress['0']['Street'],
            'MailCity' => $getShippingAddress['0']['City'],
            'MailZipCod' => $getShippingAddress['0']['ZipCode'],
            'MailProv' => $getShippingAddress['0']['State']
        ];

        // $logger->log(">>> PEC");
        $logger->dump($businesspatner);
        return $businesspatner;
    }

    public function getItem($item)
    {
        $logger = Logger::get_logger();
        $this->item = $item;
        # $corso ='MEcd'.strtolower(str_replace($this->item,' ',''));

        // echo "<strong>Corso Moddle: ".$this->item."</strong><br>";
        $sql = "SELECT trim(OITM.itemcode) as itemcode, OITM.itemname, OITM.vatgourpSa, OITW.RevenuesAc  " . " FROM OITM JOIN OITW ON OITM.ItemCode = OITW.ItemCode" . " WHERE OITW.itemcode ='" . $this->item . "';";

        $logger->log($sql);
        $datiarticolo = $this->select($sql);
        # print_r($datiarticolo);
        return $datiarticolo;
    }

    public function checkstring($string)
    {
        $clean = str_replace("'", "''", $string, $i);
        return $clean;
    }

    public function setAlign($cardcode, $allineamento)
    {
        try {
            $logger = Logger::get_logger();

            // aggiornamento utenti
            if (array_key_exists('U', $allineamento)) {
                $sql = "update OCRD set ";
                foreach ($allineamento['U'] as $key => $value) {
                    $sql .= $key . " = '" . $this->checkstring($value) . "'";
                    if (array_key_last($allineamento["U"]) != $key)
                        $sql .= ", ";
                }

                $sql .= " where cardcode='" . $cardcode . "';";
                $logger->log($sql);
                if (! $this->updCrm($sql))
                    return false;
            }

            // aggiornamento indirizzo fatturazione
            if (array_key_exists('B', $allineamento)) {
                if (array_key_exists('B', $allineamento)) {
                    $sql = "update CRD1 set ";
                    foreach ($allineamento['B'] as $key => $value) {
                        $sql .= $key . " = '" . $this->checkstring($value) . "'";
                        if (array_key_last($allineamento["B"]) != $key)
                            $sql .= ", ";
                    }

                    $sql .= " where cardcode='" . $cardcode . "' and AdresType='B';";
                    $logger->log($sql);
                    if (! $this->updCrm($sql))
                        return false;
                }
            }

            // aggiornamento indirizzo spedizione
            if (array_key_exists('S', $allineamento)) {
                if (array_key_exists('S', $allineamento)) {
                    $sql = "update CRD1 set ";
                    foreach ($allineamento['S'] as $key => $value) {
                        $sql .= $key . " = '" . $this->checkstring($value) . "'";
                        if (array_key_last($allineamento["S"]) != $key)
                            $sql .= ", ";
                    }

                    $sql .= " where cardcode='" . $cardcode . "' and AdresType='S';";
                    $logger->log($sql);
                    if (! $this->updCrm($sql))
                        return false;
                }
            }
            return true;
        } catch (Exception $e) {
            $logger->log($e->getMessage());
            return false;
        }
    }
}