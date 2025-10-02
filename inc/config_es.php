<?php
class costantiEs {
    const URL ='moodlesap.metmi.lan';
    # const URL ='moodlesap.test';
    
    const USER='manager';
    #const REQUESTDB='ESPANA_TEST';
    const REQUESTDB='ESPANA_LIVE';
    const VERSION='2'; // software
    const OBJ = '13'; // software
    const OBJ_INCASSO = '24';
    const SERIESPP='FCLPAY';
    const SERIESBB='FCLBON';
    const CURRENCY = 'EUR';
    const PAYMETHOD ='CTB';
    const PAYMENGROUP = '3';
    const INV_TYPE = 'F1';
    
    // XML INVOICE - da eliminare
    const DEST_PDF= 'fatture';
    const ITEMCODEBOLLO = 'MEbollo02';
    const ITEMDESCRBOLLO = 'Marca da bollo';
    const TOTALBOLLO = '0.00';
    const ACCOUNTCODEBOLLO ='47006050999';
    const VATBOLLO = 'Vesc15'; 
    const CCPAYPAL = '572009';
    const CCBONIFICO = '572002';
    
    // XML BP
    const CONTOCLIENTI = "430000";
    const VALUTA = "EUR";
    const CODAGE ='-1';
    const SINGLEPAY = 'N';
    const LINGUA = '23'; 
    const TIPOBP = 'CBI';
    const BLOCCOPAG = 'N';
    const ANNULLATO = 'N';
    const ABIINT = '9058';
    const NAZINT = 'ES';
    const CABINT = '0859';
    # const CCINT = '0200569058';
    const CCINT = '0200569058';
    const INTRA = 'N';
    const TIPOES = 'I';
    const CHECKPA = 'N';
    const OPASSIC = 'N';
    const RITACC = 'N';
    const CONGUAGLIO = 'N';
    const SETTORE = '-1';
    const TIPOINDB = 'B';
    const TIPOINDS = 'S';
    const STATIND = 'ES';
    const GROUPMEDI = '102';
    
    const EMAIL_OBJECT = 'Errore elaborazione fattura WS SAP';
    const EMAIL_SAP='alessia.bucci@metmi.it';
    #const EMAIL_SAP='giampiero.digregorio@metmi.it';
    const EMAIL_MOODLE='silvia.quaroni@metmi.it';
    #const EMAIL_MOODLE='giampiero.digregorio@metmi.it';
    const EMAIL_SYSTEM='giampiero.digregorio@metmi.it';
    
    const MAILBOXES = [
        'mdl_ati14_es'=>['corso'=>'ATI14 España','login'=>'info@metba.es','pass'=>'20nfotba10'],
    ];   
}