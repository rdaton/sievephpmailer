

# PHPMailer based forwarder (calendar invites support is experimental)
Mailing lists forwarder based on PHPMailer. Very very hacky.

it retrieves from IMAP and resend body through PHPMailer.

IMAP input chains are operated through Sieve filters (your income mail server should support it).

Additionally, my SMTP server (gandi.net) does the DKIM signing for me. But DKIM signing can be done manually inside the PHP Script (you must have control of your DNS AAA records in order to publish public key).


THIS CODEBASE IS NOT PRODUCTION READY.
I REPEAT AGAIN
THIS CODEBASE IS NOT PRODUCTION READY.

USE AT YOUR OWN RISK


PD: I apologize for the Spanglish
