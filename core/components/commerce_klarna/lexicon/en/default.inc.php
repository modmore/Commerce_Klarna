<?php

$_lang['commerce_klarna'] = 'Klarna';
$_lang['commerce_klarna.description'] = 'Support Klarna payment methods in Commerce.';

// gateway properties
$_lang['commerce_klarna.gateway'] = 'Klarna (Pay Now)';
$_lang['commerce_klarna.endpoint'] = 'Region';
$_lang['commerce_klarna.endpoint.desc'] = 'Which of the available API regions (endpoints) to use; this must match your Klarna account. Commerce automatically switches between live and playground API URLs in the selected region based on the Commerce mode.';
$_lang['commerce_klarna.endpoint.eu'] = 'Europe';
$_lang['commerce_klarna.endpoint.na'] = 'North America';
$_lang['commerce_klarna.endpoint.oc'] = 'Oceania';
$_lang['commerce_klarna.credentials'] = 'To authenticate with Klarna you need to create API Credentials; these are different than your own Klarna login. First login to the Klarna portal, then navigate to Settings (top right) > Preferences > Settings, select your store if you have multiple, and choose Credentials in the menu. Click the button to Generate new API Credentials and copy the UID and password into the configuration below.';
$_lang['commerce_klarna.uid'] = 'API Username (UID)';
$_lang['commerce_klarna.password'] = 'API Password';
$_lang['commerce_klarna.auto_capture'] = 'Enable auto-capture';
$_lang['commerce_klarna.auto_capture.desc'] = 'Allows orders to be automatically captured upon creation. Due to a sub-optimal user experience, Klarna recommends against using this unless you have an agreement with Klarna to do so.';

$_lang['commerce.klarna_order_id'] = 'Order ID';
$_lang['commerce.klarna_correlation_id'] = 'Correlation (log)  ID';
$_lang['commerce.klarna_reference'] = 'Short Reference';
$_lang['commerce.klarna_session_id'] = 'Session ID';
$_lang['commerce.klarna_refunded_amount'] = 'Refunded amount';
$_lang['commerce.klarna_remaining_authorized_amount'] = 'Remaining authorized amount';
$_lang['commerce.klarna_captured_amount'] = 'Captured amount';
$_lang['commerce.klarna_status'] = 'Status';
$_lang['commerce.klarna_fraud_status'] = 'Fraud Status';
$_lang['commerce.klarna_redirect'] = 'Bounce (redirect) URL';
$_lang['commerce.klarna_authorized_payment_method'] = 'Authorized Payment Method';
