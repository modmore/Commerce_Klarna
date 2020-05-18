<?php

$_lang['commerce_klarna'] = 'Klarna';
$_lang['commerce_klarna.description'] = 'Maak gebruik van Klarna betaalopties in Commerce.';

// gateway properties
$_lang['commerce_klarna.gateway'] = 'Klarna';
$_lang['commerce_klarna.endpoint'] = 'Regio';
$_lang['commerce_klarna.endpoint.desc'] = 'Welke van de beschikbare API regio\'s (endpoints) te gebruiken; dit moet overeenkomen met je Klarna account. Commerce wisselt automatisch tussen de live en playground links per regio, op basis van de Commerce modus.';
$_lang['commerce_klarna.endpoint.eu'] = 'Europa';
$_lang['commerce_klarna.endpoint.na'] = 'Noord-Amerika';
$_lang['commerce_klarna.endpoint.oc'] = 'Oceanië';
$_lang['commerce_klarna.credentials'] = 'Om met Klarna in te loggen zijn API Credentials nodig; deze zijn anders dan je reguliere Klarna login. Log eerst in op het Klarna portal en ga dan in het menu (linksboven) naar Instellingen, kies eventueel de winkel indien je er meerdere hebt, en ga dan naar Inloggegevens. Klik daar op de knop "Genereer nieuwe API-inloggegevens", en vul de gegenereerde Gebruikersnaam (UID) en wachtwoord hieronder in.';
$_lang['commerce_klarna.uid'] = 'API Gebruikersnaam (UID)';
$_lang['commerce_klarna.password'] = 'API Wachtwoord';
$_lang['commerce_klarna.auto_capture'] = 'Auto-capture aanzetten';
$_lang['commerce_klarna.auto_capture.desc'] = 'Met deze opties worden betalingen automatisch gecaptured bij het aanmaken. Omdat dit de Klarna gebruikerservaring niet ten goede komt, raadt Klarna aan deze optie niet te gebruiken tenzij dat met Klarna is overlegd. ';
$_lang['commerce_klarna.allowed_options'] = 'Beschikbare betaalopties';
$_lang['commerce_klarna.allowed_options.desc'] = 'Welke betaalopties die Klarna biedt te gebruiken. Let er op dat Klarna bepaald welke opties (niet) beschikbaar zijn op basis van je winkel account en pre-qualification checks op de klantgegevens. Daardoor krijg je geselecteerde opties alleen te zien als Klarna bepaald heeft dat ze beschikbaar zijn. ';
$_lang['commerce_klarna.allowed_options.pay_now'] = 'Betaal nu';
$_lang['commerce_klarna.allowed_options.pay_later'] = 'Betaal later';
$_lang['commerce_klarna.allowed_options.pay_over_time'] = 'Slice it, of betaal later met credit card';

$_lang['commerce_klarna.fix_form'] = 'Corrigeer alstublieft eerst de fouten op de pagina.';
$_lang['commerce_klarna.not_available'] = 'Op dit moment is het helaas niet mogelijk om deze bestelling met Klarna te betalen. Kies alstublieft een andere betaalmethode.';
$_lang['commerce_klarna.not_approved'] = 'Sorry, Klarna heeft deze betaaloptie niet toegestaan voor deze bestelling. Kies alstublieft een andere betaalmethode.';

$_lang['commerce.add_CaptureKlarnaOrder'] = 'Capture Klarna Betaling';
$_lang['commerce.CaptureKlarnaOrder'] = 'Capture Klarna Betaling';
$_lang['commerce.add_ReleaseKlarnaOrder'] = 'Nog beschikbare Klarna authorisatie vrijgeven';
$_lang['commerce.ReleaseKlarnaOrder'] = 'Nog beschikbare Klarna authorisatie vrijgeven';

// properties assigned to transactions
$_lang['commerce.klarna_order_id'] = 'Bestel nummer';
$_lang['commerce.klarna_correlation_id'] = 'Correlatie (log) ID';
$_lang['commerce.klarna_reference'] = 'Korte referentie';
$_lang['commerce.klarna_session_id'] = 'Sessie ID';
$_lang['commerce.klarna_refunded_amount'] = 'Teruggestort bedrag';
$_lang['commerce.klarna_remaining_authorized_amount'] = 'Nog beschikbare authorisatie';
$_lang['commerce.klarna_expires_at'] = 'Verloopt op';
$_lang['commerce.klarna_captured_amount'] = 'Captured bedrag';
$_lang['commerce.klarna_captures'] = 'Captures';
$_lang['commerce.klarna_refunds'] = 'Terugstortingen';
$_lang['commerce.klarna_status'] = 'Status';
$_lang['commerce.klarna_fraud_status'] = 'Fraude status';
$_lang['commerce.klarna_redirect'] = 'Bounce (redirect) link';
$_lang['commerce.klarna_authorized_payment_method'] = 'Geauthoriseerde betaalmethode';
