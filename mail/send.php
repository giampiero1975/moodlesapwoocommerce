<?php
include 'Email.php';
require_once PROJECT_ROOT_PATH . "/inc/config.php";

class send
{
    public function sendFattura(array $array)
    {
        try {
            $mail = new Email('mail.metmi.it', 587);
            $mail->setProtocol(Email::TLS)
            ->setLogin($array['mdl_emailLogin'], $array['mdl_emailPass'])
            ->setFrom($array['mdl_emailLogin'], iconv("ISO-8859-1//TRANSLIT", "UTF-8", $array['mdl_nomecorso']))
            ->setSubject($array['oggetto'])
            ->addAttachment($array['pdf']) // <--- RIGA CORRETTA QUI
            ->setHtmlMessage($array['messaggio'])
            ->addBcc('giampiero.digregorio@metmi.it')
            ->addTo($array['destinatario']);
            
            return $mail->send();
            
        } catch (Exception $e) {
            $logger = Logger::get_logger();
            $logger->log("ERRORE SMTP FATTURA: " . $e->getMessage());
            return false;
        }
    }
    
    public function sendEmail(array $array)
    {
        try {
            $config = new costanti();
            $mail = new Email('mail.metmi.it', 587);
            $mail->setProtocol(Email::TLS)
            ->setLogin('giampiero.digregorio@metmi.it', '20ero$rio14')
            ->setFrom('giampiero.digregorio@metmi.it', 'API SAP')
            ->setSubject($array['oggetto'])
            ->setHtmlMessage($array['messaggio'])
            ->addTo($array['destinatario'])
            ->addCc($config::EMAIL_SYSTEM);
            
            return $mail->send();
        } catch (Exception $e) {
            $logger = Logger::get_logger();
            $logger->log("ERRORE SMTP NOTIFICA: " . $e->getMessage());
            return false;
        }
    }
}