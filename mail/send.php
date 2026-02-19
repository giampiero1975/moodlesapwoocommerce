<?php
// Manteniamo i tuoi riferimenti e carichiamo PHPMailer
require_once PROJECT_ROOT_PATH . "/vendor/autoload.php";
require_once PROJECT_ROOT_PATH . "/inc/config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class send
{
    public function sendFattura(array $array)
    {
        $mail = new PHPMailer(true);
        try {
            // --- Configurazione Server SMTP (Tua originale) ---
            $mail->isSMTP();
            $mail->Host       = 'mail.metmi.it';
            $mail->SMTPAuth   = true;
            $mail->Username   = $array['mdl_emailLogin'];
            $mail->Password   = $array['mdl_emailPass'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            // Impostiamo UTF-8 per le accentate ma NON tocchiamo il testo
            $mail->CharSet = 'UTF-8';
            
            // --- Mittente e Destinatario (Logica originale) ---
            $mail->setFrom($array['mdl_emailLogin'], $array['mdl_nomecorso']);
            $mail->addAddress($array['destinatario']);
            
            // Il tuo BCC fisso
            $mail->addBcc('giampiero.digregorio@metmi.it');
            
            // --- Allegato (Logica originale) ---
            if (!empty($array['pdf'])) {
                $mail->addAttachment(PROJECT_ROOT_PATH . $array['pdf']);
            }
            
            // --- CONTENUTO (Passaggio Diretto dei testi originali) ---
            $mail->isHTML(true);
            $mail->Subject = $array['oggetto'];
            
            // NON usiamo conversioni: passiamo il messaggio così come arriva
            $mail->Body = $array['messaggio'];
            $mail->AltBody = strip_tags($array['messaggio']);
            
            return $mail->send();
            
        } catch (Exception $e) {
            $logger = Logger::get_logger();
            $logger->log("ERRORE SMTP FATTURA: " . $mail->ErrorInfo);
            return false;
        }
    }
    
    public function sendEmail(array $array)
    {
        $mail = new PHPMailer(true);
        try {
            $config = new costanti();
            $mail->isSMTP();
            $mail->Host = 'mail.metmi.it';
            $mail->SMTPAuth = true;
            $mail->Username = 'giampiero.digregorio@metmi.it';
            $mail->Password = '20ero$rio14';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->CharSet = 'UTF-8';
            
            $mail->setFrom('giampiero.digregorio@metmi.it', 'API SAP');
            $mail->addAddress($array['destinatario']);
            $mail->addCc($config::EMAIL_SYSTEM); //
            
            $mail->isHTML(true);
            $mail->Subject = $array['oggetto'];
            $mail->Body    = $array['messaggio'];
            
            return $mail->send();
        } catch (Exception $e) {
            return false;
        }
    }
}