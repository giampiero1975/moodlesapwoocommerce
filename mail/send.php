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
                ->
            // ->setTextMessage($array['messaggio'])
            addAttachment($array['pdf'])
                ->setHtmlMessage($array['messaggio'])
                ->addBcc('giampiero.digregorio@metmi.it')
                ->addTo($array['destinatario']);

            if ($mail->send()) {
                // echo 'SMTP Email has been sent' . PHP_EOL;
                return true;
            } else {
                return false;
            }
            // echo 'An error has occurred. Please check the logs below:' . PHP_EOL;
            // print_r($mail->getLogs());
        } catch (Exception $e) {
            echo $e->getMessage();
            return false;
        }
    }

    public function sendEmail(array $array)
    {
        $config = new costanti();
        $mail = new Email('mail.metmi.it', 587);
        $mail->setProtocol(Email::TLS)
            ->setLogin('giampiero.digregorio@metmi.it', '20ero$rio14')
            ->setFrom('giampiero.digregorio@metmi.it', 'API SAP')
            ->setSubject($array['oggetto'])
            ->setHtmlMessage($array['messaggio'])
            ->addTo($array['destinatario'])
            ->addCc($config::EMAIL_SYSTEM);

        if ($mail->send()) {
            // echo 'SMTP Email has been sent' . PHP_EOL;
            return true;
        } else {
            return false;
        }
    }
}