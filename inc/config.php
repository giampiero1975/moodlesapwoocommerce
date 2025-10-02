<?php
class costanti {
	#const URL ='moodlesap.metmi.lan';
    const URL ='moodlesapwoocommerce.test';
	
    const USER='manager';
    const REQUESTDB='METMI_TEST';
    #const REQUESTDB='METMI_LIVE';
    const VERSION='2';
    const OBJ = '13';
    const OBJ_INCASSO = '24';
    #const SERIESPP='94';
    #const SERIESBB='95';
    const SERIESPP='FCLPAY';
    const SERIESBB='FCLBON';
    const CURRENCY = 'EUR';
    const PAYMETHOD ='CBI';
    const PAYMENGROUP = '54';
    const INV_TYPE = 'TD01';
    
    // XML INVOICE
    const DEST_PDF= 'fatture';
    const ITEMCODEBOLLO = 'MEbollo02';
    const ITEMDESCRBOLLO = 'Marca da bollo';
    const TOTALBOLLO = '2.00';
    const ACCOUNTCODEBOLLO ='47006050999';
    const VATBOLLO = 'Vesc15'; 
    const CCPAYPAL = '27435020';
    const CCBONIFICO = '27435010';
    
    // XML BP
    const CONTOCLIENTI = "14001010";
    const VALUTA = "EUR";
    const CODAGE ='-1';
    const SINGLEPAY = 'N';
    const LINGUA = '13';
    const TIPOBP = 'CBI';
    const BLOCCOPAG = 'N';
    const ANNULLATO = 'N';
    const ABIINT = '08374';
    const NAZINT = 'IT';
    const CABINT = '32480';
    const CCINT = '000000113266';
    const INTRA = 'N';
    const TIPOES = 'I';
    const CHECKPA = 'N';
    const OPASSIC = 'N';
    const RITACC = 'N';
    const CONGUAGLIO = 'N';
    const SETTORE = '-1';
    const TIPOINDB = 'B';
    const TIPOINDS = 'S';
    const STATIND = 'IT';
    const GROUPMEDI = '104';
    
    const EMAIL_OBJECT = 'Errore elaborazione fattura WS SAP';
    const EMAIL_SAP='alessia.bucci@metmi.it';
    #const EMAIL_SAP='giampiero.digregorio@metmi.it';
    const EMAIL_MOODLE='silvia.quaroni@metmi.it';
    #const EMAIL_MOODLE='giampiero.digregorio@metmi.it';
    const EMAIL_SYSTEM='giampiero.digregorio@metmi.it';
    
    const MAILBOXES = [
        'mdl_admentafornitori' => [
            'corso' => 'Admenta Fornitori',
            'login' => 'admentafornitori@mei.it',
            'pass' => '$$DFNaa0R$'
        ],
        'mdl_admentafad' => [
            'corso' => 'Admenta FAD',
            'login' => 'admintafad@mei.it',
            'pass' => '$$DDuz78G$'
        ],
        'mdl_ati14' => [
            'corso' => 'ATI14',
            'login' => 'ati14@mei.it',
            'pass' => '$dfewfwr$%'
        ],
        'mdl_doctorline' => [
            'corso' => 'Doctorline',
            'login' => 'doctorline@mei.it',
            'pass' => '99$met116'
        ],
        'mdl_formazioneoss' => [
            'corso' => 'Formazione OSS',
            'login' => 'formazioneoss@mei.it',
            'pass' => '$rzTTP%25'
        ],
        'mdl_infermieriditerritorio' => [
            'corso' => 'Infermiere di Territorio',
            'login' => 'infermierediterritorio@mei.it',
            'pass' => '$$inrro11$$'
        ],
        'mdl_infermiereonline' => [
            'corso' => 'Infermiere On Line',
            'login' => 'infermiereonline@mei.it',
            'pass' => '$meFwri%%'
        ],
        'mdl_missioneveterinario' => [
            'corso' => 'Missione Veterinario',
            'login' => 'missioneveterinario@mei.it',
            'pass' => '$mvVekjefwer$'
        ],
        'mdl_professionefarmacia' => [
            'corso' => 'Professione Farmacia',
            'login' => 'professionefarmacia@mei.it',
            'pass' => '$$frpr21$'
        ],
        'mdl_professioneoculista' => [
            'corso' => 'Professione Oculista',
            'login' => 'professioneoculista@mei.it',
            'pass' => '$wrfrsvTG%$'
        ],
        'mdl_psicologiainformazione' => [
            'corso' => 'PsicologiaInFormazione',
            'login' => 'psicologiainformazione@mei.it',
            'pass' => '$$Met20ps$$'
        ],
        'mdl_valoresalutefad' => [
            'corso' => 'Valore salute fad',
            'login' => 'valoresalutefad@mei.it',
            'pass' => '$sVrt45%t'
        ],
		'mdl_academyfad' => [
            'corso' => 'Academi FAD',
            'login' => 'info@mei.it',
            'pass' => '20nfo$ie21'
        ],
        'mdl_formazioneoss' => [
            'corso' => 'Formazione OSS',
            'login' => 'formazioneoss@mei.it',
            'pass' => '$rzTTP%25'
        ],
        # 'mdl_ati14_es'=>['corso'=>'ATI14 España','login'=>'info@mei.it','pass'=>''],
    ];
    
    // ---- NUOVA SEZIONE DA AGGIUNGERE ----
    const WOOCOMMERCE_INSTANCES = [
        'mdl_formazioneoss' => [
            'url' => 'https://formazioneoss.it',
            'key' => 'ck_f560fc81cfc117a7e46f8c469def834b9dda3b5a',
            'secret' => 'cs_f3f0bebf009322f74965aaf24899f0ad0b924f60',
            'idnumber_sap' => 'MEcdOSS' 
        ],
        // Aggiungi qui altre istanze se necessario, usando lo stesso identificatore di MAILBOXES
        /*
         'mdl_altro_sito' => [
         'url' => 'https://altro-sito.com',
         'key' => 'ck_xxxxxxxxxxxxxxxxxxxxxxxx',
         'secret' => 'cs_xxxxxxxxxxxxxxxxxxxxxxxx'
         ]
         */
    ];
    // ---- FINE NUOVA SEZIONE ----
}

/*
$mailboxes = new costanti();
echo "corso: ". $mailboxes::MAILBOXES['mdl_psicologiainformazione']['corso'];
echo "login: ". $mailboxes::MAILBOXES['mdl_psicologiainformazione']['login'];
echo "pass: ". $mailboxes::MAILBOXES['mdl_psicologiainformazione']['pass'];
*/