<?php
/*
 * Idloom Webhook — Email Notification Module
 * Drop this file in the same folder as idloom-webhook.php
 * Sends an email on success or failure with full debug info
 */

define('IDLOOM_NOTIFY_EMAIL', 'jon@jongriffin.com'); // Change as needed

function idloom_notify_success($contact_id, $webhook_id, $contact_data) {
    $to      = IDLOOM_NOTIFY_EMAIL;
    $subject = '[Idloom] Contact saved — Webhook ' . $webhook_id;
    $body    = "A new Idloom registration was processed successfully.\n\n";
    $body   .= "Webhook ID : " . $webhook_id . "\n";
    $body   .= "Contact ID : " . $contact_id . "\n";
    $body   .= "Name       : " . $contact_data['firstname'] . ' ' . $contact_data['lastname'] . "\n";
    $body   .= "Email      : " . $contact_data['email'] . "\n";
    $body   .= "Phone      : " . $contact_data['phone'] . "\n";
    $body   .= "Address    : " . trim($contact_data['street'] . ' ' . $contact_data['street_number']) . "\n";
    $body   .= "City       : " . $contact_data['city'] . "\n";
    $body   .= "State      : " . $contact_data['state'] . "\n";
    $body   .= "Zip        : " . $contact_data['zip_code'] . "\n";
    $body   .= "Country    : " . $contact_data['country'] . "\n";
    $body   .= "Cast Year  : " . $contact_data['cast_year'] . "\n";
    $body   .= "\nTime: " . current_time('mysql') . "\n";

    wp_mail($to, $subject, $body);
}

function idloom_notify_failure($webhook_id, $error_message, $contact_data = []) {
    $to      = IDLOOM_NOTIFY_EMAIL;
    $subject = '[Idloom] ERROR — Webhook ' . $webhook_id;
    $body    = "An error occurred processing an Idloom webhook.\n\n";
    $body   .= "Webhook ID : " . $webhook_id . "\n";
    $body   .= "Error      : " . $error_message . "\n";
    $body   .= "\nContact Data Received:\n";
    $body   .= print_r($contact_data, true);
    $body   .= "\nTime: " . current_time('mysql') . "\n";

    wp_mail($to, $subject, $body);
}

function idloom_notify_duplicate($webhook_id) {
    $to      = IDLOOM_NOTIFY_EMAIL;
    $subject = '[Idloom] Duplicate skipped — Webhook ' . $webhook_id;
    $body    = "A duplicate webhook was received and skipped.\n\n";
    $body   .= "Webhook ID : " . $webhook_id . "\n";
    $body   .= "Time: " . current_time('mysql') . "\n";

    wp_mail($to, $subject, $body);
}