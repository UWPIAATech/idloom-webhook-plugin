<?php
/*
Plugin Name: Idloom Webhook Handler
Description: Handles Idloom webhooks and creates/updates CiviCRM contacts
Version: 1.3.0
Author: Jon Griffin
*/

defined('ABSPATH') || exit;

// Load notification module
require_once plugin_dir_path(__FILE__) . 'idloom-notify.php';

add_action('rest_api_init', function () {
    register_rest_route('idloom/v1', '/webhook', [
        'methods'             => 'POST',
        'callback'            => 'idloom_webhook_handler',
        'permission_callback' => 'idloom_webhook_authorized',
    ]);
});

/**
 * Idloom doesn't document webhook signing, but a webhook can be given extra
 * request headers. The idloom webhook is set to send the shared token from
 * wp-config.php (IDLOOM_WEBHOOK_TOKEN) as either:
 *   X-Idloom-Token: <token>
 *   Authorization: Bearer <token>
 */
function idloom_webhook_token_valid(WP_REST_Request $request) {
    $token = defined('IDLOOM_WEBHOOK_TOKEN') ? (string) IDLOOM_WEBHOOK_TOKEN : '';
    if ($token === '') {
        return false;
    }

    $sent = trim((string) $request->get_header('x_idloom_token'));
    if ($sent === '' && preg_match('/^Bearer\s+(\S+)$/i', trim((string) $request->get_header('authorization')), $m)) {
        $sent = $m[1];
    }

    return $sent !== '' && hash_equals($token, $sent);
}

/**
 * Reject requests without the token. While IDLOOM_WEBHOOK_ENFORCE is false
 * (rollout, before idloom sends the header) they are logged but still allowed.
 */
function idloom_webhook_authorized(WP_REST_Request $request) {
    if (idloom_webhook_token_valid($request)) {
        return true;
    }

    $enforce = !defined('IDLOOM_WEBHOOK_ENFORCE') || IDLOOM_WEBHOOK_ENFORCE;
    error_log('Idloom Webhook — Missing or invalid token' . ($enforce ? ', rejected.' : ' (not enforced yet, allowed).'));

    return $enforce ? new WP_Error('idloom_unauthorized', 'Unauthorized', ['status' => 401]) : true;
}

function idloom_webhook_handler(WP_REST_Request $request) {

    $body       = $request->get_body();
    $webhook_id = $request->get_header('webhook-id');

    error_log('Idloom Webhook — ID: ' . $webhook_id);
    error_log('Idloom Webhook — Raw Body: ' . $body);

    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'received', 'webhook_id' => $webhook_id]);
        if (ob_get_level()) ob_end_flush();
        flush();
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
        idloom_notify_duplicate($dedup_id);
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
        // Find the existing contact by email. If the email is on more than
        // one contact we can't tell which one is meant, so a new one is made.
        $contact_id = null;
        if (!empty($contact_data['email'])) {
            $existing = civicrm_api4('Email', 'get', [
                'select'           => ['contact_id'],
                'join'             => [['Contact AS c', 'INNER', ['c.id', '=', 'contact_id']]],
                'where'            => [
                    ['email', '=', $contact_data['email']],
                    ['c.is_deleted', '=', false],
                    ['c.contact_type', '=', 'Individual'],
                ],
                'groupBy'          => ['contact_id'],
                'limit'            => 2,
                'checkPermissions' => false,
            ]);
            if ($existing->count() === 1) {
                $contact_id = $existing->first()['contact_id'];
            } elseif ($existing->count() > 1) {
                error_log('Idloom Webhook — Email is on several contacts, creating a new one.');
            }
        }

        // Don't blank out existing values with empty ones.
        $record = array_filter([
            'first_name' => $contact_data['firstname'],
            'last_name'  => $contact_data['lastname'],
            'Extra_IAA_Contact_Info.Primary_Cast' => $contact_data['cast_year'],
        ], 'strlen');
        $record['contact_type'] = 'Individual';
        if ($contact_id) {
            $record['id'] = $contact_id;
        } else {
            $record['source'] = 'Idloom Registration';
        }

        $contact_result = civicrm_api4('Contact', 'save', [
            'records'          => [$record],
            'checkPermissions' => false,
        ]);

        $contact_id = $contact_result[0]['id'];
        error_log('Idloom Webhook — Contact ' . (isset($record['id']) ? 'updated' : 'created') . ', ID: ' . $contact_id);

        if (!empty($contact_data['email'])) {
            civicrm_api4('Email', 'save', [
                'records' => [[
                    'contact_id' => $contact_id,
                    'email'      => $contact_data['email'],
                    'is_primary' => true,
                ]],
                'match'            => ['contact_id', 'email'],
                'checkPermissions' => false,
            ]);
        }

        if (!empty($contact_data['phone'])) {
            civicrm_api4('Phone', 'save', [
                'records' => [[
                    'contact_id' => $contact_id,
                    'phone'      => $contact_data['phone'],
                    'is_primary' => true,
                ]],
                'match'            => ['contact_id', 'phone'],
                'checkPermissions' => false,
            ]);
        }

        if (!empty($contact_data['street']) || !empty($contact_data['city'])) {
            // idloom sends ISO codes, e.g. country "US" and state "AZ".
            $country_id = null;
            $state_id   = null;
            if (!empty($contact_data['country'])) {
                $country = civicrm_api4('Country', 'get', [
                    'select'           => ['id'],
                    'where'            => [['iso_code', '=', $contact_data['country']]],
                    'checkPermissions' => false,
                ])->first();
                $country_id = $country['id'] ?? null;
            }
            if ($country_id && !empty($contact_data['state'])) {
                $state = civicrm_api4('StateProvince', 'get', [
                    'select'           => ['id'],
                    'where'            => [['abbreviation', '=', $contact_data['state']], ['country_id', '=', $country_id]],
                    'checkPermissions' => false,
                ])->first();
                $state_id = $state['id'] ?? null;
            }

            civicrm_api4('Address', 'save', [
                'records' => [array_filter([
                    'contact_id'        => $contact_id,
                    'street_address'    => trim($contact_data['street'] . ' ' . $contact_data['street_number']),
                    'city'              => $contact_data['city'],
                    'state_province_id' => $state_id,
                    'postal_code'       => $contact_data['zip_code'],
                    'country_id'        => $country_id,
                    'is_primary'        => true,
                ], function ($v) { return $v !== null && $v !== ''; })],
                'match'            => ['contact_id', 'is_primary'],
                'checkPermissions' => false,
            ]);
        }

        error_log('Idloom Webhook — Complete. Contact ID: ' . $contact_id . ', Webhook ID: ' . $dedup_id);
        idloom_notify_success($contact_id, $dedup_id, $contact_data);

    } catch (Exception $e) {
        error_log('Idloom Webhook — CiviCRM Exception: ' . $e->getMessage());
        idloom_notify_failure($dedup_id, $e->getMessage(), $contact_data);
        delete_transient($dedup_key);
    }
}
