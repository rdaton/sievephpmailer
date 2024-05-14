#!/opt/php-8.1/bin/php
<?php
//INCLUDE

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

//Import the PHPMailer class into the global namespace
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

//SMTP needs accurate times, and the PHP time zone MUST be set
//This should be done in your php.ini, but this is how to do it if you don't have access to t$
date_default_timezone_set('Etc/UTC');
//parse config  file name
//¡WARNING! UNSAFE STRING PARSE. CORRECT IF EVER THIS CODE GETS TO PRODUCTION
$arg1= $argv[1];

//variables importadas (servidores, direcciones, credenciales, etc)
include("config/".$arg1.".conf");

//CONSTANTES
/*define ("TYPETEXT", 0);
define ("TYPEMULTIPART", 1);
define ("TYPEMESSAGE", 2);
define ("TYPEAPPLICATION", 3);
define ("TYPEAUDIO", 4);
define ("TYPEIMAGE", 5);
define ("TYPEVIDEO", 6);
define ("TYPEMODEL", 7);
define ("TYPEOTHER", 8);

define ("ENC7BIT", 0);
define ("ENC8BIT", 1);
define ("ENCBINARY", 2);
define ("ENCBASE64", 3);
define ("ENCQUOTEDPRINTABLE", 4);
define ("ENCOTHER", 5);
*/
//MAIN GLOBAL VARIABLES
$acum = 0;
$acum_error = 0;
$regex = "/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/";

//funciones estáticas y clases
//https://electrictoolbox.com/php-imap-message-body-attachments/
function flattenParts($messageParts, $flattenedParts = array(), $prefix = '', $index = 1, $fullPrefix = 1) {
  foreach($messageParts as $part) {
    $flattenedParts[$prefix.$index] = $part;
    if (isset($part->parts)) {
      if ($part->type == constant("TYPEMESSAGE")) {
        $flattenedParts = flattenParts($part->parts, $flattenedParts, $prefix.$index.'.', 0, 0);
      } elseif ($fullPrefix) {
        $flattenedParts = flattenParts($part->parts, $flattenedParts, $prefix.$index.'.');
      } else {
        $flattenedParts = flattenParts($part->parts, $flattenedParts, $prefix);
      }
      unset($flattenedParts[$prefix.$index]->parts);
    }
    $index++;
  }
  return $flattenedParts;
}

//https://electrictoolbox.com/php-imap-message-body-attachments/
function getPart($connection, $messageNumber, $partNumber, $encoding) {
  $data = imap_fetchbody($connection, $messageNumber, $partNumber);
  switch(1) {
    case $encoding == constant("ENC7BIT"): return $data; // 7BIT
    case $encoding == constant("ENC8BIT"): return $data; // 8BIT
    case $encoding == constant("ENCBINARY"): return $data; // BINARY
    case $encoding == constant("ENCBASE64"): return base64_decode($data); // BASE64
    case $encoding == constant("ENCQUOTEDPRINTABLE"): return quoted_printable_decode($data); // QUOTED_PRINTABLE
    case $encoding == constant("ENCOTHER"): return $data; // OTHER
  }
}

//https://electrictoolbox.com/php-imap-message-body-attachments/
function getFilenameFromPart($part) {
  $filename = '';
  if ($part->ifdparameters) {
    foreach($part->dparameters as $object) {
      if (strtolower($object->attribute) == 'filename') {
        $filename = $object->value;
      }
    }
  }

  if (!$filename && $part->ifparameters) {
    foreach($part->parameters as $object) {
      if (strtolower($object->attribute) == 'name') {
        $filename = $object->value;
      }
    }
  }
  //debug
  //echo "sacando adjunto".PHP_EOL;
  //echo $filename.PHP_EOL;
  return $filename;
}

//https://electrictoolbox.com/php-imap-message-body-attachments/
//https://www.techfry.com/php-tutorial/how-to-read-emails-using-php
//https://github.com/PHPMailer/PHPMailer/issues/175#issuecomment-636504190
class mailExtractor {
  private $structure;
  private $cuerpo;
  private $cuerpoHtml;
  private $ical_content;
  private $attachments;
  private $esTextoPlano;
  private $esCalendar;

  private function insertaArrayAttachments($connection, $messageNumber, $partNumber, $part) {
    $filename = getFilenameFromPart($part);
    //https://www.thewebtaylor.com/articles/php-how-to-strip-all-spaces-and-special-characters-from-string
    //escape string of filename (delete all spaces,etc).
    $filename=preg_replace('/[^a-zA-Z0-9-_\.]/','', $filename);
    //echo $filename.PHP_EOL; //debug
    if($filename) {
      // it's an attachment
      //echo "it's an attachment ";
      // now do something with the attachment, e.g. save it somewhere
      $this->attachments[$filename] = getPart($connection, $messageNumber, $partNumber, $part->encoding);
    } else {
      // don't know what it is
    }
  }

  public function getAttachments() {
    return $this->attachments;
  }

  public function getCuerpo() {
    return $this->cuerpo;
  }

  public function getCuerpoHtml() {
    return $this->cuerpoHtml;
  }

  public function getIcalContent() {
   return $this->ical_content;
  }

  public function getEsTextoPlano() {
    return $this->esTextoPlano;
  }

  public function getEsCalendar() {
    return $this->esCalendar;
  }

  //constructora mailExtractor
  function __construct($connection,$messageNumber) {
    //get access to global variables  
    global $regex;
    //end of get access to global variables
    $this->esCalendar = 0;
    $this->esTextoPlano = 0;
    $this->attachments = array();
    $this->structure = imap_fetchstructure($connection,$messageNumber);
    $unasPartes =  $this->structure->parts;
    if (!(isset($unasPartes))) { //plain text message
      //echo "mensaje sólo texto".PHP_EOL; //debug
      //if plain text send, if not I really do not know what it is
      echo $this->structure->type.PHP_EOL;
      if ($this->structure->type == constant("TYPETEXT")) {
        //in plaintext email partnumber 0 is header; partnumber 1 is body
        $partNumber = 1;
        //echo 'some kind of plain text'.PHP_EOL; //debug
        // the HTML or plain text part of the email
        $miBuffer = getPart($connection, $messageNumber, $partNumber, $this->structure->encoding);
        //if vcalendar, not using as text, but attaching as ICS
        if (!(strpos($miBuffer, 'VCALENDAR') || strpos(base64_decode($miBuffer), 'VCALENDAR'))) { //base64 bug TODO
          //use as readable body
          $this->esTextoPlano = 1;
          $this->cuerpo =  imap_fetchbody($connection,$messageNumber,$partNumber);
          //if body is base64, decode source: https://stackoverflow.com/posts/475217/revisions
          //unsafe code: does not check length TODO
          //base64 bug
          if (($this->structure->encoding == constant("ENCBASE64")) || (preg_match($regex, $this->cuerpo))) {
            $this->cuerpo = base64_decode($this->cuerpo);
          }
          $this->cuerpoHtml = $this->cuerpo;
        } else { //I doubt this else shall be executed
          //attach as ICS and mark message as vcalendar and marking message as vcalendar https://github.com/PHPMailer/PHPMailer/issues/175#issuecomment-636504190
          $this->esCalendar = 1;
          $this->ical_content=$miBuffer;
          // if (preg_match($regex, $this->ical_content)) {
          $this->ical_content = base64_decode($this->ical_content);
          // }
        }
      }
    } else { //unasPartes no está vacío
      //echo "mensaje con partes".PHP_EOL; //debug
      $this->esTextoPlano = 0;
      $flattenedParts = flattenParts($this->structure->parts);

      foreach($flattenedParts as $partNumber => $part) {
        $unTipo = $part->type;
        //echo $unTipo;
        //echo PHP_EOL;

        switch(1) {
          case $unTipo == constant("TYPETEXT"):
            // the HTML or plain text part of the email
            $miBuffer=getPart($connection, $messageNumber, $partNumber, $this->structure->encoding);
            //if vcalendar, not using as text, but attaching as ICS
            if (!(strpos($miBuffer, 'VCALENDAR')||strpos(base64_decode($miBuffer), 'VCALENDAR'))) { //base64 bug TODO
              //use as readable body
              $this->cuerpo = $miBuffer;
              //if body is base64, decode source: https://stackoverflow.com/posts/475217/revisions
              // unsafe code: does not check length TODO
              // base64 bug
              if (($this->structure->encoding == constant("ENCBASE64")) || (preg_match($regex, $this->cuerpo))) {
                $this->cuerpo = base64_decode($this->cuerpo);
              }
              $this->cuerpoHtml = $this->cuerpo;
            } else {
              //attach as ICS and mark message as vcalendar and marking message as vcalendar https://github.com/PHPMailer/PHPMailer/issues/175#issuecomment-636504190
              $this->esCalendar = 1;
              $this->ical_content=$miBuffer;
              //if (preg_match($regex, $this->ical_content))
              //{
              $this->ical_content = base64_decode($this->ical_content);
              //}
            }
            break;
          case $unTipo == constant("TYPEMULTIPART"):
            // multi-part headers, can ignore
            break;
          case  $unTipo == constant("TYPEMESSAGE"):
            // attached message headers, can ignore
            break;
          case  $unTipo == constant("TYPEAPPLICATION"): // application
            $this->insertaArrayAttachments($connection, $messageNumber, $partNumber, $part);
            break;
          case  $unTipo == constant("TYPEAUDIO"): // audio
            $this->insertaArrayAttachments($connection, $messageNumber, $partNumber, $part);
            break;
          case $unTipo == constant("TYPEIMAGE"): // image
            $this->insertaArrayAttachments($connection, $messageNumber, $partNumber, $part);
            break;
          case $unTipo == constant("TYPEVIDEO"): // video
            $this->insertaArrayAttachments($connection, $messageNumber, $partNumber, $part);
            break;
          case $unTipo == constant("TYPEMODEL"): // other
            $this->insertaArrayAttachments($connection, $messageNumber, $partNumber, $part);
            break;
        }
      }
    }
  }
}//end of class mailExtractor

//BEGIN OF MAIN method
//https://github.com/victorcuervo/lineadecodigo_php/blob/master/email/cuerpo-mensaje.php
//RECEIVE
//abrir cola IMAP , conseguir nº de correos, ordenar por fecha (más nuevo primero), y lanzar iteración
$inbox = imap_open($hostname,$username,$password) or die('Ha fallado la conexión: ' . imap_last_error());
$num_emails = imap_num_msg ($inbox);
//BEGIN OF LOOP OF MAILS QUEUE
for($i=1; $i<=$num_emails; $i++) {
  // begin calendar set to zero for every loop

  $ical_content= '';
  // end calendar set to zero for every loop
  $cuerpo = '';
  $cuerpo_plano = '';
  $adjuntos = array();
  $headers = imap_headerinfo($inbox,$i,1);
  $asunto = $headers->subject;
  //Get body and attachment
  $unMailExtractor = new mailExtractor($inbox,$i);
  $cuerpo_plano = $unMailExtractor->getCuerpo();
  $cuerpo = $unMailExtractor->getCuerpoHtml();
  $adjuntos = $unMailExtractor->getAttachments();
  $esTextoPlano = $unMailExtractor->getEsTextoPlano();
  $esCalendar = $unMailExtractor->getEsCalendar();

  //https://github.com/PHPMailer/PHPMailer/
  //SEND

  //Create a new PHPMailer instance
  $mail = new PHPMailer();
  //$mail->CharSet = 'UTF-8';
  //$mail->CharSet = "utf-8";
  //$mail->Encoding = 'quoted-printable';
  $mail->Encoding = 'base64';
  //Tell PHPMailer to use SMTP
  $mail->isSMTP();
  //$mail->SMTPDebug = SMTP::DEBUG_SERVER;
  //Set the hostname of the mail server
  $mail->Host = $unHost;
  $mail->Port = $puerto;
  $mail->SMTPAuth = 1;
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Username = $username;
  $mail->Password = $password;
  $mail->setFrom($autor);
  $mail->addReplyTo($autor);
  //Set who the message is to be sent to
  foreach ($direcciones as $value) {
    $mail->AddBCC($value);
  }
  //echo 'asunto '.$asunto; //debug
  $mail->Subject = $asunto;
  //$mail->Body = imap_qprint($cuerpo);
  //$mail->AltBody = imap_qprint($cuerpo_plano);
  //bugfix: add sender
  $cuerpo = $cuerpo . "Sent from: " . $headers->senderaddress;
  //convert to HTML si NO es texto plano, sino asignar directamente a body
  if ($esTextoPlano) {
    $mail->Body = $cuerpo;
  } else {
    // asume que $cuerpo es quoted_printable
    $mail->msgHTML(imap_qprint($cuerpo));
  }

  //if a calendar invite, crete a calendar object (hopefully it was correctly parsed)
  //https://github.com/PHPMailer/PHPMailer/issues/175#issuecomment-636504190
  if ($esCalendar) {
    $ical_content = $unMailExtractor->getIcalContent();
    $mail->AltBody = $ical_content;
    $mail->Ical = $ical_content;
    // https://github.com/PHPMailer/PHPMailer/issues/175
    //$mail->ContentType = 'text/calendar'; //This seems to be important for Outlook
    $mail->addStringAttachment($ical_content, 'ical.ics', 'base64', 'text/calendar'); //This seems to be important for Gmail
  }

  foreach ($adjuntos as $key => $value) {
    //echo $key.PHP_EOL; //debug
    //echo $value.PHP_EOL; //debug
    $mail->AddStringAttachment($value, $key, 'base64', 'application/octet-stream');
    //$mail->AddStringAttachment($string,$filename,$encoding,$type);
    //$mail->AddAttachment($value);
  }

  //send the message, check for errors
  if (!$mail->send()) {
    $acum_error++;
  } else {
    //borrar correo
    imap_delete($inbox,$i);
    $acum++;
  }
} //END LOOP OF MAILS QUEUE

//cerrar conexión imap
imap_close($inbox,CL_EXPUNGE);

//grabar log
$output_log=$cola.';'.date("d/m/Y/H:i:s").';'.$num_emails.' '.'correos en la cola'.' '.$cola.';'.$acum.' '.'correos envíados desde'.' '.';'.$acum_error.' '.'correos fallados'.PHP_EOL;
echo $output_log;
$fp = fopen($fichero_log, 'a'); //opens file in append mode
fwrite($fp, $output_log);
fclose($fp);
//END OF MAIN Method

?>
