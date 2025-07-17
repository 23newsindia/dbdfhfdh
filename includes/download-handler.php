<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Download Handler for Email Verification System
 */

// Create download tokens table
add_action('init', 'wns_create_download_tokens_table');

function wns_create_download_tokens_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'newsletter_download_tokens';
    
    // Check if table exists
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            token varchar(64) NOT NULL,
            file_url text NOT NULL,
            post_id bigint(20) NOT NULL,
            block_id varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            used tinyint(1) DEFAULT 0,
            verified tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY email (email),
            KEY token (token),
            KEY expires_at (expires_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Generate secure download token
function wns_generate_download_token($email, $file_url, $post_id, $block_id, $requires_verification = false) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'newsletter_download_tokens';
    $token = wp_generate_password(32, false);
    $expires_at = date('Y-m-d H:i:s', time() + (24 * HOUR_IN_SECONDS)); // 24 hours
    
    $result = $wpdb->insert(
        $table_name,
        array(
            'email' => sanitize_email($email),
            'token' => $token,
            'file_url' => esc_url_raw($file_url),
            'post_id' => intval($post_id),
            'block_id' => sanitize_text_field($block_id),
            'expires_at' => $expires_at,
            'used' => 0,
            'verified' => $requires_verification ? 0 : 1
        ),
        array('%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d')
    );
    
    if ($result) {
        return $token;
    }
    
    return false;
}

// Send download email with secure link - FIXED LOGIC
function wns_send_download_email($email, $file_url, $post_id, $block_id) {
    // Check if email verification is required for downloads
    $require_verification = get_option('wns_require_email_verification_for_downloads', false);
    $skip_verification_for_verified = get_option('wns_skip_verification_for_verified_users', true);
    
    // Check if user is already verified subscriber
    $is_verified = wns_is_subscriber_verified($email);
    
    // FIXED: If verification is required but user is verified and skip setting is enabled, 
    // DON'T send email - return the direct download URL instead
    if ($require_verification && $is_verified && $skip_verification_for_verified) {
        // User is verified and should get instant access - NO EMAIL SENT
        // This should not happen in the current flow, but adding as safeguard
        return false; // Don't send email for verified users who should get instant access
    }
    
    // Generate secure token
    $token = wns_generate_download_token($email, $file_url, $post_id, $block_id, $require_verification);
    
    if (!$token) {
        return false;
    }
    
    if ($require_verification) {
        if (!$is_verified) {
            // Send verification email first
            return wns_send_download_verification_email($email, $token, $file_url);
        } else {
            // User is verified, mark token as verified and send download link
            wns_mark_download_token_verified($token);
        }
    }
    
    // Create secure download link
    $download_link = add_query_arg(array(
        'wns_download' => '1',
        'token' => $token,
        'email' => urlencode($email)
    ), home_url());
    
    // Get email template settings
    $subject = get_option('wns_download_email_subject', __('Your Download Link is Ready!', 'wp-newsletter-subscription'));
    $body = get_option('wns_download_email_body', __("Hi there,\n\nThank you for subscribing! Your download is ready.\n\nClick the link below to download your file:\n{download_link}\n\nThis link will expire in 24 hours for security reasons.\n\nBest regards,\nThe Team", 'wp-newsletter-subscription'));
    
    // Replace placeholder with actual download link
    $body = str_replace('{download_link}', $download_link, $body);
    
    // Use professional email template
    require_once WNS_PLUGIN_DIR . 'includes/email-templates.php';
    $email_content = WNS_Email_Templates::get_download_template($subject, $body, $download_link);
    
    // Add unsubscribe link
    $unsubscribe_link = wns_get_unsubscribe_link($email);
    $email_content = str_replace('{unsubscribe_link}', $unsubscribe_link, $email_content);
    
    // Send email
    $headers = wns_get_standard_email_headers($email);
    
    return wp_mail($email, $subject, $email_content, $headers);
}

// Send verification email for download
function wns_send_download_verification_email($email, $download_token, $file_url) {
    // Create verification link that will verify email and then provide download
    $verify_link = add_query_arg(array(
        'wns_verify_download' => '1',
        'token' => $download_token,
        'email' => urlencode($email)
    ), home_url());
    
    // Get file name for better UX
    $file_name = basename(parse_url($file_url, PHP_URL_PATH));
    
    // Use professional email template
    require_once WNS_PLUGIN_DIR . 'includes/email-templates.php';
    $subject = __('Verify Your Email to Download File', 'wp-newsletter-subscription');
    $email_content = WNS_Email_Templates::get_download_verification_template($verify_link, $file_name);
    
    // Add unsubscribe link
    $unsubscribe_link = wns_get_unsubscribe_link($email);
    $email_content = str_replace('{unsubscribe_link}', $unsubscribe_link, $email_content);
    
    // Send email
    $headers = wns_get_standard_email_headers($email);
    
    return wp_mail($email, $subject, $email_content, $headers);
}

// Handle download verification
add_action('init', 'wns_handle_download_verification');

function wns_handle_download_verification() {
    if (!isset($_GET['wns_verify_download']) || !isset($_GET['token']) || !isset($_GET['email'])) {
        return;
    }
    
    $token = sanitize_text_field($_GET['token']);
    $email = sanitize_email($_GET['email']);
    
    if (empty($token) || empty($email)) {
        wp_die(__('Invalid verification request.', 'wp-newsletter-subscription'));
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'newsletter_download_tokens';
    
    // Get token data
    $token_data = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE token = %s AND email = %s AND used = 0 AND expires_at > NOW()",
        $token,
        $email
    ));
    
    if (!$token_data) {
        wp_die(__('Verification link has expired or is invalid.', 'wp-newsletter-subscription'));
    }
    
    // Add user to subscribers if not already there
    wns_add_or_update_subscriber($email, true); // Force verification
    
    // Mark token as verified
    wns_mark_download_token_verified($token);
    
    // Create download link
    $download_link = add_query_arg(array(
        'wns_download' => '1',
        'token' => $token,
        'email' => urlencode($email)
    ), home_url());
    
    // Send download email
    $subject = get_option('wns_download_email_subject', __('Your Download Link is Ready!', 'wp-newsletter-subscription'));
    $body = get_option('wns_download_email_body', __("Hi there,\n\nThank you for verifying your email! Your download is ready.\n\nClick the link below to download your file:\n{download_link}\n\nThis link will expire in 24 hours for security reasons.\n\nBest regards,\nThe Team", 'wp-newsletter-subscription'));
    
    // Replace placeholder with actual download link
    $body = str_replace('{download_link}', $download_link, $body);
    
    // Use professional email template
    require_once WNS_PLUGIN_DIR . 'includes/email-templates.php';
    $email_content = WNS_Email_Templates::get_download_template($subject, $body, $download_link);
    
    // Add unsubscribe link
    $unsubscribe_link = wns_get_unsubscribe_link($email);
    $email_content = str_replace('{unsubscribe_link}', $unsubscribe_link, $email_content);
    
    // Send email
    $headers = wns_get_standard_email_headers($email);
    wp_mail($email, $subject, $email_content, $headers);
    
    // Log verification activity
    wns_log_email_activity($email, 'download_verified', 'Email verified for download: ' . $token_data->file_url);
    
    // Redirect to success page
    wp_redirect(add_query_arg('download_verified', 'success', home_url()));
    exit;
}

// Mark download token as verified
function wns_mark_download_token_verified($token) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'newsletter_download_tokens';
    
    $wpdb->update(
        $table_name,
        array('verified' => 1),
        array('token' => $token),
        array('%d'),
        array('%s')
    );
}

// Handle secure download requests
add_action('init', 'wns_handle_secure_download');

function wns_handle_secure_download() {
    if (!isset($_GET['wns_download']) || !isset($_GET['token']) || !isset($_GET['email'])) {
        return;
    }
    
    $token = sanitize_text_field($_GET['token']);
    $email = sanitize_email($_GET['email']);
    
    if (empty($token) || empty($email)) {
        wp_die(__('Invalid download request.', 'wp-newsletter-subscription'));
    }
    
    global $wpdb;
    $table_name = $wpdb->prefix . 'newsletter_download_tokens';
    
    // Get token data - must be verified if verification is required
    $require_verification = get_option('wns_require_email_verification_for_downloads', false);
    $verification_condition = $require_verification ? 'AND verified = 1' : '';
    
    $token_data = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_name WHERE token = %s AND email = %s AND used = 0 AND expires_at > NOW() $verification_condition",
        $token,
        $email
    ));
    
    if (!$token_data) {
        if ($require_verification) {
            wp_die(__('Download link has expired, is invalid, or email verification is required.', 'wp-newsletter-subscription'));
        } else {
            wp_die(__('Download link has expired or is invalid.', 'wp-newsletter-subscription'));
        }
    }
    
    // Mark token as used
    $wpdb->update(
        $table_name,
        array('used' => 1),
        array('id' => $token_data->id),
        array('%d'),
        array('%d')
    );
    
    // Log download activity
    wns_log_email_activity($email, 'file_downloaded', 'File downloaded via secure link: ' . $token_data->file_url);
    
    // Redirect to file download
    wp_redirect($token_data->file_url);
    exit;
}

// Clean up expired tokens (run daily)
add_action('wp', 'wns_schedule_token_cleanup');

function wns_schedule_token_cleanup() {
    if (!wp_next_scheduled('wns_cleanup_expired_tokens')) {
        wp_schedule_event(time(), 'daily', 'wns_cleanup_expired_tokens');
    }
}

add_action('wns_cleanup_expired_tokens', 'wns_cleanup_expired_download_tokens');

function wns_cleanup_expired_download_tokens() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'newsletter_download_tokens';
    
    // Delete tokens older than 48 hours
    $wpdb->query("DELETE FROM $table_name WHERE expires_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)");
}