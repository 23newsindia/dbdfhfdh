<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('transition_post_status', 'wns_send_new_post_notifications', 10, 3);

function wns_send_new_post_notifications($new_status, $old_status, $post) {
    // Only trigger for posts (not pages or other post types)
    if ($post->post_type !== 'post') return;
    
    // Only send notification when transitioning FROM non-published TO published
    // This prevents notifications on updates to already published posts
    if ($new_status !== 'publish' || $old_status === 'publish') return;
    
    // Skip autosaves and revisions
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post->ID)) return;

    // Check if auto-send is enabled for this specific post
    $post_auto_send = get_post_meta($post->ID, '_wns_auto_send_enabled', true);
    $global_enabled = get_option('wns_enable_new_post_notification', false);
    
    // If post setting exists, use it; otherwise use global setting
    $should_send = ($post_auto_send !== '') ? ($post_auto_send === '1') : $global_enabled;
    
    if (!$should_send) return;

    // Double-check: only send if this is truly a new publication
    $already_notified = get_post_meta($post->ID, '_wns_notification_sent', true);
    if ($already_notified) {
        return; // Don't send duplicate notifications
    }

    // Mark this post as notified to prevent duplicates
    update_post_meta($post->ID, '_wns_notification_sent', true);

    // Schedule sending of emails via cron
    if (!wp_next_scheduled('wns_cron_send_post_notification', array($post->ID))) {
        wp_schedule_single_event(time(), 'wns_cron_send_post_notification', array($post->ID));
    }
}

add_action('wns_cron_send_post_notification', 'wns_cron_handler_send_post_notification', 10, 1);

function wns_cron_handler_send_post_notification($post_id) {
    global $wpdb;

    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return;

    // Ensure tables exist before processing
    wns_ensure_tables_exist();

    $table_name = WNS_TABLE_SUBSCRIBERS;
    
    // Check if table exists before querying with prepared statement
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
        return; // Table doesn't exist, skip processing
    }

    // Check if sending to selected subscribers only
    $send_to_selected = get_post_meta($post_id, '_wns_send_to_selected', true);
    $selected_subscribers = get_post_meta($post_id, '_wns_selected_subscribers', true);
    
    if ($send_to_selected === '1' && is_array($selected_subscribers) && !empty($selected_subscribers)) {
        // Send to selected subscribers only
        $placeholders = implode(',', array_fill(0, count($selected_subscribers), '%s'));
        $query = "SELECT email FROM `$table_name` WHERE verified = %d AND email IN ($placeholders)";
        $params = array_merge(array(1), $selected_subscribers);
        $subscribers = $wpdb->get_results($wpdb->prepare($query, $params));
    } else {
        // Send to all verified subscribers
        $subscribers = $wpdb->get_results($wpdb->prepare("SELECT email FROM `$table_name` WHERE verified = %d", 1));
    }

    if (!$subscribers || $wpdb->last_error) {
        if ($wpdb->last_error) {
            error_log('WNS Plugin Error in new post notification: ' . $wpdb->last_error);
        }
        return;
    }

    // Use the new professional email template
    require_once WNS_PLUGIN_DIR . 'includes/email-templates.php';
    
    $subject = str_replace('{post_title}', sanitize_text_field($post->post_title), get_option('wns_template_new_post_subject', 'New Post: {post_title}'));
    $email_content = WNS_Email_Templates::get_new_post_template($post);

    $send_after = current_time('mysql');
    $queue_table = $wpdb->prefix . 'newsletter_email_queue';

    // Check if queue table exists before inserting with prepared statement
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $queue_table)) != $queue_table) {
        return; // Table doesn't exist, skip processing
    }

    $success_count = 0;
    foreach ($subscribers as $subscriber) {
        // Validate email before adding to queue
        if (!is_email($subscriber->email)) {
            continue;
        }

        // Get unsubscribe link for this subscriber
        $unsubscribe_link = wns_get_unsubscribe_link($subscriber->email);
        
        // Replace unsubscribe link placeholder
        $personalized_content = str_replace('{unsubscribe_link}', $unsubscribe_link, $email_content);

        // Use standard headers for consistency
        $headers = wns_get_standard_email_headers($subscriber->email);

        $result = $wpdb->insert($queue_table, array(
            'recipient' => sanitize_email($subscriber->email),
            'subject'   => sanitize_text_field($subject),
            'body'      => $personalized_content,
            'headers'   => maybe_serialize($headers),
            'send_at'   => $send_after,
            'sent'      => 0
        ), array('%s', '%s', '%s', '%s', '%s', '%d'));

        if ($result) {
            $success_count++;
            // Log new post notification activity
            $log_type = ($send_to_selected === '1') ? 'new_post_queued_selected' : 'new_post_queued';
            wns_log_email_activity($subscriber->email, $log_type, 'New post notification queued: ' . $post->post_title);
        }
    }

    if ($success_count > 0) {
        $send_type = ($send_to_selected === '1') ? 'selected subscribers' : 'all subscribers';
        error_log("WNS Plugin: Queued {$success_count} new post notifications to {$send_type} for: {$post->post_title}");
    }
}

// Function to manually send newsletter for a post
function wns_send_post_newsletter_manual($post_id, $send_to_selected = '0', $selected_subscribers = array()) {
    global $wpdb;
    
    error_log('WNS Debug: Manual newsletter send started - Post ID: ' . $post_id . ', Send to selected: ' . $send_to_selected . ', Selected count: ' . count($selected_subscribers));
    
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') {
        error_log('WNS Debug: Post not found or not published');
        return array('success' => false, 'message' => 'Post not found or not published');
    }
    
    // Ensure tables exist
    wns_ensure_tables_exist();
    
    $table_name = WNS_TABLE_SUBSCRIBERS;
    
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
        error_log('WNS Debug: Subscriber table not found');
        return array('success' => false, 'message' => 'Subscriber table not found');
    }
    
    // Get subscribers based on selection
    if ($send_to_selected === '1' && is_array($selected_subscribers) && !empty($selected_subscribers)) {
        error_log('WNS Debug: Sending to selected subscribers: ' . implode(', ', $selected_subscribers));
        
        // Validate selected subscribers are valid emails
        $selected_subscribers = array_filter($selected_subscribers, 'is_email');
        
        if (empty($selected_subscribers)) {
            error_log('WNS Debug: No valid email addresses in selection');
            return array('success' => false, 'message' => 'No valid email addresses in selection');
        }
        
        $placeholders = implode(',', array_fill(0, count($selected_subscribers), '%s'));
        $query = "SELECT email FROM `$table_name` WHERE verified = %d AND email IN ($placeholders)";
        $params = array_merge(array(1), $selected_subscribers);
        $subscribers = $wpdb->get_results($wpdb->prepare($query, $params));
        
        error_log('WNS Debug: Selected subscribers query result: ' . count($subscribers) . ' found');
        
        // Debug: Show which subscribers were found
        if ($subscribers) {
            $found_emails = array_map(function($s) { return $s->email; }, $subscribers);
            error_log('WNS Debug: Found subscribers: ' . implode(', ', $found_emails));
        }
    } else {
        $subscribers = $wpdb->get_results($wpdb->prepare("SELECT email FROM `$table_name` WHERE verified = %d", 1));
        error_log('WNS Debug: All subscribers query result: ' . count($subscribers) . ' found');
    }
    
    if (empty($subscribers)) {
        $message = ($send_to_selected === '1') ? 'No verified subscribers found in your selection' : 'No verified subscribers found';
        error_log('WNS Debug: ' . $message);
        return array('success' => false, 'message' => $message);
    }
    
    // Generate email content
    require_once WNS_PLUGIN_DIR . 'includes/email-templates.php';
    
    // Get custom title or use post title
    $custom_title = get_post_meta($post_id, '_wns_custom_email_title', true);
    $email_title = !empty($custom_title) ? $custom_title : $post->post_title;
    
    $subject = str_replace('{post_title}', sanitize_text_field($email_title), get_option('wns_template_new_post_subject', '📰 New Article: {post_title}'));
    $email_content = WNS_Email_Templates::get_new_post_template($post);
    
    error_log('WNS Debug: Email subject: ' . $subject);
    
    $queue_table = $wpdb->prefix . 'newsletter_email_queue';
    
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $queue_table)) != $queue_table) {
        error_log('WNS Debug: Email queue table not found');
        return array('success' => false, 'message' => 'Email queue table not found');
    }
    
    $success_count = 0;
    $send_after = current_time('mysql'); // Send immediately
    
    error_log('WNS Debug: Starting to queue emails for ' . count($subscribers) . ' subscribers');
    
    foreach ($subscribers as $subscriber) {
        if (!is_email($subscriber->email)) {
            error_log('WNS Debug: Invalid email address: ' . $subscriber->email);
            continue;
        }
        
        // Get unsubscribe link for this subscriber
        $unsubscribe_link = wns_get_unsubscribe_link($subscriber->email);
        $personalized_content = str_replace('{unsubscribe_link}', $unsubscribe_link, $email_content);
        
        // Use standard headers
        $headers = wns_get_standard_email_headers($subscriber->email);
        
        $result = $wpdb->insert($queue_table, array(
            'recipient' => sanitize_email($subscriber->email),
            'subject'   => sanitize_text_field($subject),
            'body'      => $personalized_content,
            'headers'   => maybe_serialize($headers),
            'send_at'   => $send_after,
            'sent'      => 0
        ), array('%s', '%s', '%s', '%s', '%s', '%d'));
        
        if ($result) {
            $success_count++;
            wns_log_email_activity($subscriber->email, 'manual_post_newsletter', 'Manual post newsletter queued: ' . $post->post_title);
            error_log('WNS Debug: Email queued for: ' . $subscriber->email);
        } else {
            error_log('WNS Debug: Failed to queue email for: ' . $subscriber->email . ' - Error: ' . $wpdb->last_error);
        }
    }
    
    error_log('WNS Debug: Total emails queued: ' . $success_count . ' out of ' . count($subscribers) . ' subscribers');
    
    // Force immediate processing by clearing the rate limit
    delete_transient('wns_last_batch_time');
    error_log('WNS Debug: Cleared rate limit transient to allow immediate processing');
    
    // Also trigger immediate processing
    if ($success_count > 0) {
        error_log('WNS Debug: Triggering immediate email queue processing');
        wp_schedule_single_event(time(), 'wns_cron_process_email_queue');
    }
    
    if ($success_count > 0) {
        $send_type = ($send_to_selected === '1') ? 'selected subscribers' : 'all subscribers';
        error_log('WNS Debug: Newsletter queued successfully for ' . $success_count . ' ' . $send_type);
        return array(
            'success' => true, 
            'message' => "Newsletter queued for {$success_count} {$send_type}"
        );
    } else {
        error_log('WNS Debug: Failed to queue any newsletter emails');
        return array('success' => false, 'message' => 'Failed to queue newsletter emails');
    }
}