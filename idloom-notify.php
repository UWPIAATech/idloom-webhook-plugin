<?php
/*
 * Idloom Webhook — Email Notification Module
 * Drop this file in the same folder as idloom-webhook.php
 * Sends an email on success or failure with full debug info
 */

define('IDLOOM_NOTIFY_EMAIL', 'tech.cmte@uwpiaa.org'); // Change as needed

/**
 * The two registration checkboxes, as idloom sent them.
 */
function idloom_notify_checkboxes($data) {
    $yes = function ($key) use ($data) {
        return in_array($data[$key] ?? null, [true, 'true', 1, '1'], true) ? 'YES' : 'NO';
    };
    $body  = "Update my record in CiviCRM (free_field59) : " . $yes('free_field59') . "\n";
    $body .= "Show me on Who's Registered (free_field56) : " . $yes('free_field56') . "\n";
    return $body;
}

function idloom_notify_success($contact_id, $webhook_id, $contact_data, $data = []) {
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
    $body   .= "\n" . idloom_notify_checkboxes($data);
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

/**
 * Sent when a registration arrives but the "update my information" consent
 * (free_field59) isn't true, so CiviCRM isn't touched.
 */
function idloom_notify_skipped($webhook_id, $data) {
    $to      = IDLOOM_NOTIFY_EMAIL;
    $name    = trim(($data['firstname'] ?? '') . ' ' . ($data['lastname'] ?? ''));
    $subject = '[Idloom] Registration received, CiviCRM not updated — ' . $name;
    $body    = "A new Idloom registration was received. CiviCRM was NOT updated\n";
    $body   .= "because free_field59 (\"update my information\" consent) was not true.\n\n";
    $body   .= "Webhook ID : " . $webhook_id . "\n";
    $body   .= "Event      : " . ($data['event_name'] ?? '') . "\n";
    $body   .= "Name       : " . $name . "\n";
    $body   .= "Email      : " . ($data['email'] ?? '') . "\n";
    $body   .= "Phone      : " . ($data['phone'] ?? '') . "\n";
    $body   .= "Cast Year  : " . ($data['cpy_name'] ?? '') . "\n";
    $body   .= "Category   : " . ($data['registration_category'] ?? '') . "\n";
    $body   .= "Status     : " . ($data['registration_status'] ?? '') . "\n";
    $body   .= "\n" . idloom_notify_checkboxes($data);
    $body   .= "\nTime: " . current_time('mysql') . "\n";

    wp_mail($to, $subject, $body);
}
