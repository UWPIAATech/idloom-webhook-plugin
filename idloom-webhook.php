<?php
/*
Plugin Name: Idloom Webhook Handler
Description: Handles Idloom webhooks and creates/updates CiviCRM contacts
Version: 1.2
Author: Jon Griffin
*/

define('IDLOOM_WEBHOOK_SECRET', '');

add_action('rest_api_init', function () {
    register_rest_route('idloom/v1', '/webhook', [
        'methods'             => 'POST',
        'callback'            => 'idloom_webhook_handler',
        'permission_callback' => '__return_true',
    ]);
});

function idloom_webhook_handler(WP_REST_Request $request) {

    $body        = $request->get_body();
    $webhook_id  = $request->get_header('webhook-id');
    $webhook_sig = $request->get_header('webhook-signature');
    $webhook_ts  = $request->get_header('webhook-timestamp');

    error_log('Idloom Webhook — ID: ' . $webhook_id);
    error_log('Idloom Webhook — Raw Body: ' . $body);

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'received', 'webhook_id' => $webhook_id]);
        if (ob_get_level()) ob_end_flush();
        flush();
    }

    if (!empty(IDLOOM_WEBHOOK_SECRET) && !empty($webhook_sig) && !empty($webhook_ts)) {
        $signed_content = $webhook_id . '.' . $webhook_ts . '.' . $body;
        $expected_sig   = 'v1,' . base64_encode(hash_hmac('sha256', $signed_content, base64_decode(IDLOOM_WEBHOOK_SECRET), true));
        $sig_valid      = false;
        foreach (explode(' ', $webhook_sig) as $sig) {
            if (hash_equals($expected_sig, trim($sig))) { $sig_valid = true; break; }
        }
        if (!$sig_valid) { error_log('Idloom Webhook — Invalid signature.'); return; }
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Idloom Webhook — JSON error: ' . json_last_error_msg());
        return;
    }

    $dedup_id  = $webhook_id ?: ($data['guest_uid'] ?? md5($body));
    $dedup_key = 'idloom_whk_' . $dedup_id;
    if (get_transient($dedup_key)) {
        error_log('Idloom Webhook — Duplicate, skipping: ' . $dedup_id);
        return;
    }
    set_transient($dedup_key, true, 300);

    if (empty($data['free_field59']) || !in_array($data['free_field59'], [true, 'true', 1], true)) {
        error_log('Idloom Webhook — free_field59 not true, skipping.');
        delete_transient($dedup_key);
        return;
    }

    $contact_data = [
        'email'         => !empty($data['email'])             ? sanitize_email($data['email'])                  : '',
        'firstname'     => !empty($data['firstname'])         ? sanitize_text_field($data['firstname'])         : '',
        'lastname'      => !empty($data['lastname'])          ? sanitize_text_field($data['lastname'])          : '',
        'phone'         => !empty($data['phone'])             ? sanitize_text_field($data['phone'])             : '',
        'street'        => !empty($data['cpy_street'])        ? sanitize_text_field($data['cpy_street'])        : '',
        'street_number' => !empty($data['cpy_street_number']) ? sanitize_text_field($data['cpy_street_number']) : '',
        'city'          => !empty($data['cpy_city'])          ? sanitize_text_field($data['cpy_city'])          : '',
        'state'         => !empty($data['cpy_state'])         ? sanitize_text_field($data['cpy_state'])         : '',
        'zip_code'      => !empty($data['cpy_zip_code'])      ? sanitize_text_field($data['cpy_zip_code'])      : '',
        'country'       => !empty($data['cpy_country'])       ? sanitize_text_field($data['cpy_country'])       : '',
        'cast_year'     => !empty($data['cpy_name'])          ? sanitize_text_field($data['cpy_name'])          : '',
    ];

    if (!function_exists('civicrm_initialize')) {
        include_once ABSPATH . '/wp-content/plugins/civicrm/civicrm.php';
    }
    civicrm_initialize();

    if (!function_exists('civicrm_api4')) {
        error_log('Idloom Webhook — CiviCRM API4 not available.');
        delete_transient($dedup_key);
        return;
    }

    try {
        $contact_result = civicrm_api4('Contact', 'save', [
            'records' => [[
                'contact_type' => 'Individual',
                'first_name'   => $contact_data['firstname'],
                'last_name'    => $contact_data['lastname'],
                'email'        => $contact_data['email'],
                'match'        => ['email'],
                'source'       => 'Idloom Registration',
                'Extra_IAA_Contact_Info.Primary_Cast' => $contact_data['cast_year'],
            ]],
            'checkPermissions' => false,
        ]);

        $contact_id = $contact_result[0]['id'];
        error_log('Idloom Webhook — Contact upserted, ID: ' . $contact_id);

        if (!empty($contact_data['email'])) {
            civicrm_api4('Email', 'save', [
                'records' => [[
                    'contact_id' => $contact_id,
                    'email'      => $contact_data['email'],
                    'is_primary' => true,
                    'match'      => ['contact_id', 'email'],
                ]],
                'checkPermissions' => false,
            ]);
        }

        if (!empty($contact_data['phone'])) {
            civicrm_api4('Phone', 'save', [
                'records' => [[
                    'contact_id' => $contact_id,
                    'phone'      => $contact_data['phone'],
                    'is_primary' => true,
                    'match'      => ['contact_id', 'phone'],
                ]],
                'checkPermissions' => false,
            ]);
        }

        if (!empty($contact_data['street']) || !empty($contact_data['city'])) {
            civicrm_api4('Address', 'save', [
                'records' => [[
                    'contact_id'     => $contact_id,
                    'street_address' => trim($contact_data['street'] . ' ' . $contact_data['street_number']),
                    'city'           => $contact_data['city'],
                    'state_province' => $contact_data['state'],
                    'postal_code'    => $contact_data['zip_code'],
                    'country'        => $contact_data['country'],
                    'is_primary'     => true,
                    'match'          => ['contact_id', 'location_type_id'],
                ]],
                'checkPermissions' => false,
            ]);
        }

        error_log('Idloom Webhook — Complete. Contact ID: ' . $contact_id . ', Webhook ID: ' . $dedup_id);

    } catch (Exception $e) {
        error_log('Idloom Webhook — CiviCRM Exception: ' . $e->getMessage());
        delete_transient($dedup_key);
    }
}