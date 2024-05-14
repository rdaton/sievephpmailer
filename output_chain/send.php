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

//IMPORTED VARS (servers, email addresses, credentials, etc)
include("config/".$arg1.".conf");

//CONSTANTS
$regex = "/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/";

//MAIN GLOBAL VARIABLES
$acum = 0;
$acum_error = 0;

//STATIC FUNCTIONS AND METHODS
//https://electrictoolbox.com/php-imap-message-body-attachments/
//returns an Array containing all parts from email's Multipart (basically a Danish Cookie Box, IMHO)
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

//return a particular part of the email , and in some cases, decode it
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

//used when 'part' is a file which shall be attached
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
  return $filename;
}


//CLASSESS 

//mailBuilder class, it takes what mailExtractor provided and  through PHPMailer (thanks), build a minimialistic (hopefully) email
class mailBuilder {
  //mailBuilder private attributes
  private $mail;
  private $cuerpo;
  
  //mailBuilder builder
  function __construct($cuerpo,$cuerpo_plano,$asunto,$senderaddress,$adjuntos,$ical_content,$esTextoPlano,$esCalendar) {
      //Create a new PHPMailer instance
      $this->mail = new PHPMailer();
      $this->mail->Encoding = 'base64';
      //echo 'asunto '.$asunto; //debug
      $this->mail->Subject = $asunto;
      $this->cuerpo = $cuerpo . "Sent from: " . $senderaddress;
      //if NOT plain text, convert to HTML, else use plain text ($this->cuerpo)as it is 
      if ($esTextoPlano) {
        $this->mail->Body = $this->cuerpo;
      } else {
        // assumes that $cuerpo is quoted_printable 
        $this->mail->msgHTML(imap_qprint($this->cuerpo));
      }

      //if a calendar invite, crete a calendar object (hopefully it was correctly parsed)
      //https://github.com/PHPMailer/PHPMailer/issues/175#issuecomment-636504190
      if ($esCalendar) {
        $this->mail->AltBody = $ical_content;
        $this->mail->Ical = $ical_content;
        // https://github.com/PHPMailer/PHPMailer/issues/175
        //$mail->ContentType = 'text/calendar'; //This seems to be important for Outlook
        $this->mail->addStringAttachment($ical_content, 'ical.ics', 'base64', 'text/calendar'); //This seems to be important for Gmail
      }

      foreach ($adjuntos as $key => $value) {
        $this->mail->AddStringAttachment($value, $key, 'base64', 'application/octet-stream');
        }
  } //END OF mailBuilder builder
    
  //mailBuilder getters
    //none
    
  //mailbuilder methods and functions
  
  //passing a password through a  paremeter is a very bad practice
  //TODO: insecure, ¡fix for  production!
  //returns boolean
  public function send($direcciones,$unHost,$puerto,$username,$password,$autor)
  {
    $this->mail->isSMTP();
    //used to debug SMTP issues; uncomment for more verbosity
    //$mail->SMTPDebug = SMTP::DEBUG_SERVER; 
    //Set who the message is to be sent to
    foreach ($direcciones as $value) { 
    $this->mail->AddBCC($value);
    }    
    $this->mail->Host = $unHost; 
    $this->mail->Port = $puerto; 
    $this->mail->SMTPAuth = 1;
    $this->mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $this->mail->Username = $username; 
    $this->mail->Password = $password; 
    $this->mail->setFrom($autor); 
    $this->mail->addReplyTo($autor);
    
    return $this->mail->send();
  }
}

//mailExtractor class, it extracts everything so later it can be reassambled and DKIM signed in another class
//https://electrictoolbox.com/php-imap-message-body-attachments/
//https://www.techfry.com/php-tutorial/how-to-read-emails-using-php
//https://github.com/PHPMailer/PHPMailer/issues/175#issuecomment-636504190
class mailExtractor {
  //mailExtractor private attributes
  private $structure;
  private $cuerpo;
  private $cuerpoHtml;
  private $ical_content;
  private $attachments;
  private $esTextoPlano;
  private $esCalendar;

  //mailExtractor getters
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
  

  //mailExtractor builder (very very messy and hacky: it is the part which gives me headaches the most)
  function __construct($connection,$messageNumber){
    //get access to global variables  (it is a PHP thing) (or a Pascal thing: I do not remember)
    global $regex;
    //end of get access to global variables
    $this->esCalendar = 0;
    $this->ical_content = NULL;
    $this->esTextoPlano = 0;
    $this->attachments = array();
    $this->structure = imap_fetchstructure($connection,$messageNumber);
    $unasPartes =  $this->structure->parts;
    if (!(isset($unasPartes))) { //it is a plain text message
      echo $this->structure->type.PHP_EOL;
      if ($this->structure->type == constant("TYPETEXT")) {
        //in plaintext email partnumber 0 is header; partnumber 1 is body
        $partNumber = 1;
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
    } else { //unasPartes is not empty: it is a multipart email
      $this->esTextoPlano = 0;
      $flattenedParts = flattenParts($this->structure->parts);
      //start extracting useful parts of the multipar
      foreach($flattenedParts as $partNumber => $part) {
        $unTipo = $part->type;

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
  //mailExtractor methods and functions
  
  //function insertaArrayAttachments: a single file comes here to be added to the Array of attahcments ($this->attachments)
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
      // if it is not an attachment, I don't know what it is
    }
  }
}//end of class mailExtractor

//BEGIN OF MAIN
//https://github.com/victorcuervo/lineadecodigo_php/blob/master/email/cuerpo-mensaje.php

//open IMAP queue, get nº of emails, order them by most recent date and start loop
//I insist, do not open an IMAP permanent folder, but rather an unimportant IMAP folder (I call it fifo) queue
//where destruction is not an issue (e.g. all emails are copies of the main mailbox)

$inbox = imap_open($hostname,$username,$password) or die('IMAP Connection failed: ' . imap_last_error());
$num_emails = imap_num_msg ($inbox);
$acum_error = 0;
$acum_ok = 0;

//MAIN: MAIN LOOP TO PROCESS EACH EMAIL IN THE EMAIL ($inbox) QUEUE
for($i=1; $i<=$num_emails; $i++) {
  //MAIN: MAIN LOOP set some loop variables to zero
  $esTextoPlano = 0 ;  
  $esCalendar = 0 ;
  $headers = NULL;
  $asunto = '';
  $senderaddress = '';
  $cuerpo_plano = '';
  $cuerpo = ''; 
  $adjuntos = NULL;
  $ical_content = NULL;

//MAIN: MAIN LOOP: EXTRACT ALL USEFUL FIELDS OF CURRENT EMAIL
  //create unMailExtractor
  $unMailExtractor = new mailExtractor($inbox,$i);
  
  //Get headers, subject, senderaddress, body and attachment
  $esTextoPlano = $unMailExtractor->getEsTextoPlano();
  $esCalendar = $unMailExtractor->getEsCalendar();
  $headers = imap_headerinfo($inbox,$i,1);
  $asunto = $headers->subject;
  $senderaddress = $headers->senderaddress;
  $cuerpo_plano = $unMailExtractor->getCuerpo();
  $cuerpo = $unMailExtractor->getCuerpoHtml();  
  $adjuntos = $unMailExtractor->getAttachments(); 
  $ical_content = $unMailExtractor->getIcalContent();
  
  //destroy unMailExtractor
  unset ($unMailExtractor);

//MAIN: MAIN LOOP: SEND CURRENT EMAIL
  //create mailBuilder
  $unEmail=new mailBuilder($cuerpo,$cuerpo_plano,$asunto,$senderaddress,$adjuntos,$ical_content,$esTextoPlano,$esCalendar);
  
  //send the message, check for errors
  //all parameters for this call (send) are imported vars
  if (!$unEmail->send($direcciones,$unHost,$puerto,$username,$password,$autor)) { 
    $acum_error++;
  } 
  else {
        //delete email from IMAP queue; be careful ¡it is destructive!
        imap_delete($inbox,$i);
        $acum_ok++;
       }
       
  //destroy mailBuilder
  unset($unEmail); 
} 
//MAIN: END OF MAIN LOOP

//close IMAP connection

imap_close($inbox,CL_EXPUNGE);

//RECORD LOG
//$cola, $output_log, $fichero_log are imported vars
$output_log=$cola.';'.date("d/m/Y/H:i:s").';'.$num_emails.' '.'queued emails'.' '.$cola.';'.$acum_ok.' '.'emails sent from'.' '.';'.$acum_error.' '.'failed emails'.PHP_EOL;
echo $output_log;
$fp = fopen($fichero_log, 'a'); //opens file in append mode
fwrite($fp, $output_log);
fclose($fp);

//END OF MAIN Method

?>
