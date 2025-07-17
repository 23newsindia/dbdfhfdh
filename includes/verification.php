<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'wns_check_verification_request');

function wns_check_verification_request() {
    if (isset($_GET['verify_email']) && isset($_GET['token'])) {
        $email = sanitize_email($_GET['verify_email']);
        $token = sanitize_text_field($_GET['token']);

        // Additional validation
        if (!is_email($email) || empty($token) || strlen($token) !== 64) {
            wp_safe_redirect(add_query_arg('verified', 'invalid', home_url()));
            exit;
        }

        // Rate limiting for verification attempts
        if (!wns_check_verification_rate_limit($email)) {
            wp_safe_redirect(add_query_arg('verified', 'rate_limited', home_url()));
            exit;
        }

        if (wns_verify_email_token($email, $token)) {
            wns_mark_email_as_verified($email);
            wp_safe_redirect(add_query_arg('verified', 'success', home_url()));
            exit;
        } else {
            wp_safe_redirect(add_query_arg('verified', 'invalid', home_url()));
            exit;
        }
    }
}

function wns_check_verification_rate_limit($email) {
    $transient_key = 'wns_verify_rate_' . md5($email);
    $attempts = get_transient($transient_key);
    
    if ($attempts === false) {
        set_transient($transient_key, 1, HOUR_IN_SECONDS);
        return true;
    }
    
    if ($attempts >= 3) { // Max 3 verification attempts per hour per email
        return false;
    }
    
    set_transient($transient_key, $attempts + 1, HOUR_IN_SECONDS);
    return true;
}

function wns_generate_verification_token($email) {
    // Use a more secure token generation with timestamp
    $salt = defined('AUTH_SALT') ? AUTH_SALT : wp_salt();
    $timestamp = time();
    $data = $email . $timestamp . wp_get_session_token();
    
    // Store token with expiration (24 hours)
    $token = hash_hmac('sha256', $data, $salt);
    set_transient('wns_verify_token_' . md5($email), array(
        'token' => $token,
        'expires' => $timestamp + DAY_IN_SECONDS
    ), DAY_IN_SECONDS);
    
    return $token;
}

function wns_verify_email_token($email, $token) {
    global $wpdb;

    // Validate inputs
    if (!is_email($email) || empty($token) || strlen($token) !== 64) {
        return false;
    }

    $table_name = WNS_TABLE_SUBSCRIBERS;
    
    // Verify table exists
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
        return false;
    }

    // Check if email exists and is not already verified
    $subscriber = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table_name` WHERE `email` = %s", $email));

    if ($wpdb->last_error) {
        error_log('WNS Plugin Error in verification: ' . $wpdb->last_error);
        return false;
    }

    if (!$subscriber || $subscriber->verified) {
        return false;
    }

    // Verify token from transient
    $stored_token_data = get_transient('wns_verify_token_' . md5($email));
    if (!$stored_token_data || !is_array($stored_token_data)) {
        return false;
    }

    // Check token match and expiration
    if (!hash_equals($stored_token_data['token'], $token)) {
        return false;
    }

    if (time() > $stored_token_data['expires']) {
        delete_transient('wns_verify_token_' . md5($email));
        return false;
    }

    // Clean up token after successful verification
    delete_transient('wns_verify_token_' . md5($email));
    return true;
}

function wns_mark_email_as_verified($email) {
    global $wpdb;
    $table_name = WNS_TABLE_SUBSCRIBERS;

    $result = $wpdb->update(
        $table_name,
        array('verified' => 1),
        array('email' => $email),
        array('%d'),
        array('%s')
    );

    if ($wpdb->last_error) {
        error_log('WNS Plugin Error in mark verified: ' . $wpdb->last_error);
        return false;
    }

    return $result !== false;
}

function wns_send_verification_email($email) {
    // Validate email
    if (!is_email($email)) {
        return false;
    }

    // Rate limiting for sending verification emails
    $transient_key = 'wns_verify_email_sent_' . md5($email);
    if (get_transient($transient_key)) {
        return false; // Already sent within the last 5 minutes
    }

    // Route verification emails through the queue system for consistency
    global $wpdb;
    $queue_table = $wpdb->prefix . 'newsletter_email_queue';
    
    // Check if queue table exists
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $queue_table)) != $queue_table) {
        // Fallback to direct sending if queue table doesn't exist
        return wns_send_verification_email_direct($email);
    }

    $token = wns_generate_verification_token($email);
    $verify_link = add_query_arg(array(
        'verify_email' => urlencode($email),
        'token' => $token
    ), home_url());

    // Use the professional email template with proper unsubscribe link
    require_once WNS_PLUGIN_DIR . 'includes/email-templates.php';
    
    $subject = sanitize_text_field(get_option('wns_template_subscribe_subject', __('Confirm Your Subscription', 'wp-newsletter-subscription')));
    $email_content = WNS_Email_Templates::get_verification_template($verify_link);
    
    // Add unsubscribe link to verification emails
    $unsubscribe_link = wns_get_unsubscribe_link($email);
    $email_content = str_replace('{unsubscribe_link}', $unsubscribe_link, $email_content);
    
    // Use consistent headers with proper unsubscribe functionality
    $headers = wns_get_standard_email_headers($email);

    // Add to email queue for consistent processing
    $result = $wpdb->insert($queue_table, array(
        'recipient' => sanitize_email($email),
        'subject'   => $subject,
        'body'      => $email_content,
        'headers'   => maybe_serialize($headers),
        'send_at'   => current_time('mysql'),
        'sent'      => 0
    ), array('%s', '%s', '%s', '%s', '%s', '%d'));

    if ($result) {
        // Set transient to prevent spam
        set_transient($transient_key, true, 5 * MINUTE_IN_SECONDS);
        return true;
    }
    
    return false;
}

function wns_send_verification_email_direct($email) {
    // Fallback direct sending method with improved headers
    $token = wns_generate_verification_token($email);
    $verify_link = add_query_arg(array(
        'verify_email' => urlencode($email),
        'token' => $token
    ), home_url());

    require_once WNS_PLUGIN_DIR . 'includes/email-templates.php';
    
    $subject = sanitize_text_field(get_option('wns_template_subscribe_subject', __('Confirm Your Subscription', 'wp-newsletter-subscription')));
    $email_content = WNS_Email_Templates::get_verification_template($verify_link);
    
    // Add unsubscribe link
    $unsubscribe_link = wns_get_unsubscribe_link($email);
    $email_content = str_replace('{unsubscribe_link}', $unsubscribe_link, $email_content);
    
    // Use standard headers
    $headers = wns_get_standard_email_headers($email);

    $sent = wp_mail($email, $subject, $email_content, $headers);
    
    if ($sent) {
        $transient_key = 'wns_verify_email_sent_' . md5($email);
        set_transient($transient_key, true, 5 * MINUTE_IN_SECONDS);
    }
    
    return $sent;
}