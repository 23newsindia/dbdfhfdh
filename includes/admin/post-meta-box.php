<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Post Meta Box for Newsletter Sending Options
 */

// Add meta box to post editor
add_action('add_meta_boxes', 'wns_add_post_newsletter_meta_box');

function wns_add_post_newsletter_meta_box() {
    add_meta_box(
        'wns_newsletter_options',
        __('📧 Newsletter Sending Options', 'wp-newsletter-subscription'),
        'wns_render_post_newsletter_meta_box',
        'post',
        'side',
        'high'
    );
}

function wns_render_post_newsletter_meta_box($post) {
    // Add nonce for security
    wp_nonce_field('wns_post_newsletter_meta', 'wns_post_newsletter_nonce');
    
    // Get current settings
    $auto_send_enabled = get_post_meta($post->ID, '_wns_auto_send_enabled', true);
    $send_to_selected = get_post_meta($post->ID, '_wns_send_to_selected', true);
    $selected_subscribers = get_post_meta($post->ID, '_wns_selected_subscribers', true);
    $already_sent = get_post_meta($post->ID, '_wns_notification_sent', true);
    
    // Default to enabled for new posts
    if ($auto_send_enabled === '') {
        $auto_send_enabled = get_option('wns_enable_new_post_notification', false) ? '1' : '0';
    }
    
    if (!is_array($selected_subscribers)) {
        $selected_subscribers = array();
    }
    
    // Get subscriber count
    global $wpdb;
    $table_name = WNS_TABLE_SUBSCRIBERS;
    $total_subscribers = 0;
    
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) == $table_name) {
        $total_subscribers = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table_name` WHERE verified = %d", 1));
    }
    
    ?>
    <div class="wns-newsletter-options">
        <?php if ($already_sent): ?>
            <div style="background: #d1ecf1; border: 1px solid #bee5eb; padding: 10px; margin-bottom: 15px; border-radius: 4px;">
                <strong style="color: #0c5460;">✅ Newsletter Already Sent</strong>
                <p style="margin: 5px 0 0 0; font-size: 12px; color: #0c5460;">
                    This post has already been sent to subscribers. To send again, you can manually send from the newsletter broadcast page.
                </p>
            </div>
        <?php endif; ?>
        
        <div style="margin-bottom: 15px;">
            <label style="display: flex; align-items: center; font-weight: bold;">
                <input type="checkbox" name="wns_auto_send_enabled" value="1" <?php checked($auto_send_enabled, '1'); ?> style="margin-right: 8px;" />
                <?php _e('Send Newsletter on Publish', 'wp-newsletter-subscription'); ?>
            </label>
            <p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">
                <?php _e('Automatically send this post to subscribers when published.', 'wp-newsletter-subscription'); ?>
            </p>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="display: flex; align-items: center; font-weight: bold;">
                <input type="checkbox" name="wns_send_on_save" value="1" style="margin-right: 8px;" />
                <?php _e('Send Newsletter on Save', 'wp-newsletter-subscription'); ?>
            </label>
            <p style="margin: 5px 0 0 0; font-size: 12px; color: #666;">
                <?php _e('Send newsletter immediately when you save/update this post (even if not published yet).', 'wp-newsletter-subscription'); ?>
            </p>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="font-weight: bold; display: block; margin-bottom: 8px;">
                <?php _e('Send To:', 'wp-newsletter-subscription'); ?>
            </label>
            
            <label style="display: flex; align-items: center; margin-bottom: 8px;">
                <input type="radio" name="wns_send_to_selected" value="0" <?php checked($send_to_selected, '0'); ?> style="margin-right: 8px;" />
                <?php printf(__('All Verified Subscribers (%d)', 'wp-newsletter-subscription'), $total_subscribers); ?>
            </label>
            
            <label style="display: flex; align-items: center;">
                <input type="radio" name="wns_send_to_selected" value="1" <?php checked($send_to_selected, '1'); ?> style="margin-right: 8px;" />
                <?php _e('Selected Subscribers Only', 'wp-newsletter-subscription'); ?>
            </label>
        </div>
        
        <div id="wns-subscriber-selection" style="<?php echo $send_to_selected == '1' ? '' : 'display: none;'; ?>">
            <div style="margin-bottom: 10px;">
                <button type="button" id="wns-select-subscribers-btn" class="button button-secondary" style="width: 100%;">
                    <?php _e('Select Subscribers', 'wp-newsletter-subscription'); ?>
                    <span id="wns-selected-count"><?php echo count($selected_subscribers) > 0 ? '(' . count($selected_subscribers) . ' selected)' : ''; ?></span>
                </button>
            </div>
            
            <div style="background: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 12px; color: #666;">
                <strong><?php _e('Selected subscribers:', 'wp-newsletter-subscription'); ?></strong>
                <div id="wns-selected-preview">
                    <?php if (count($selected_subscribers) > 0): ?>
                        <?php echo esc_html(implode(', ', array_slice($selected_subscribers, 0, 3))); ?>
                        <?php if (count($selected_subscribers) > 3): ?>
                            <?php printf(__('... and %d more', 'wp-newsletter-subscription'), count($selected_subscribers) - 3); ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php _e('None selected', 'wp-newsletter-subscription'); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <?php if (!$already_sent && $post->post_status === 'publish'): ?>
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                <button type="button" id="wns-send-now-btn" class="button button-primary" style="width: 100%;">
                    <?php _e('📧 Send Newsletter Now', 'wp-newsletter-subscription'); ?>
                </button>
                <p style="margin: 5px 0 0 0; font-size: 11px; color: #666; text-align: center;">
                    <?php _e('Send immediately to selected subscribers', 'wp-newsletter-subscription'); ?>
                </p>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Hidden input to store selected subscribers -->
    <input type="hidden" name="wns_selected_subscribers" id="wns-selected-subscribers-input" value="<?php echo esc_attr(json_encode($selected_subscribers)); ?>" />
    
    <style>
    .wns-newsletter-options label {
        cursor: pointer;
    }
    .wns-newsletter-options input[type="checkbox"],
    .wns-newsletter-options input[type="radio"] {
        cursor: pointer;
    }
    #wns-select-subscribers-btn {
        position: relative;
    }
    #wns-selected-count {
        font-size: 11px;
        color: #666;
    }
    </style>
    
    <script>
    (function($) {
        $(document).ready(function() {
            // Toggle subscriber selection visibility
            $('input[name="wns_send_to_selected"]').change(function() {
                if ($(this).val() === '1') {
                    $('#wns-subscriber-selection').show();
                } else {
                    $('#wns-subscriber-selection').hide();
                }
            });
            
            // Open subscriber selection modal
            $('#wns-select-subscribers-btn').click(function() {
                wns_open_subscriber_modal();
            });
            
            // Send newsletter now
            $('#wns-send-now-btn').click(function() {
                if (confirm('<?php echo esc_js(__('Send newsletter to selected subscribers now?', 'wp-newsletter-subscription')); ?>')) {
                    wns_send_newsletter_now();
                }
            });
        });
        
        // Modal functions
        window.wns_open_subscriber_modal = function() {
            // Create modal HTML
            var modal = $('<div id="wns-subscriber-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 999999; display: flex; align-items: center; justify-content: center;"></div>');
            var content = $('<div style="background: white; width: 80%; max-width: 600px; max-height: 80%; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.3);"></div>');
            var header = $('<div style="padding: 20px; border-bottom: 1px solid #ddd; background: #f8f9fa;"><h3 style="margin: 0;">Select Subscribers</h3></div>');
            var body = $('<div style="padding: 20px; max-height: 400px; overflow-y: auto;"><div class="loading">Loading subscribers...</div></div>');
            var footer = $('<div style="padding: 20px; border-top: 1px solid #ddd; background: #f8f9fa; text-align: right;"><button class="button button-secondary" onclick="wns_close_subscriber_modal()">Cancel</button> <button class="button button-primary" onclick="wns_save_selected_subscribers()">Save Selection</button></div>');
            
            content.append(header, body, footer);
            modal.append(content);
            $('body').append(modal);
            
            // Load subscribers via AJAX
            $.post(ajaxurl, {
                action: 'wns_get_subscribers_for_selection',
                nonce: '<?php echo wp_create_nonce('wns_get_subscribers'); ?>',
                selected: JSON.parse($('#wns-selected-subscribers-input').val() || '[]')
            }, function(response) {
                if (response.success) {
                    body.html(response.data.html);
                } else {
                    body.html('<p style="color: red;">Error loading subscribers.</p>');
                }
            });
        };
        
        window.wns_close_subscriber_modal = function() {
            $('#wns-subscriber-modal').remove();
        };
        
        window.wns_save_selected_subscribers = function() {
            var selected = [];
            $('#wns-subscriber-modal input[type="checkbox"]:checked').each(function() {
                selected.push($(this).val());
            });
            
            $('#wns-selected-subscribers-input').val(JSON.stringify(selected));
            
            // Update preview
            $('#wns-selected-count').text(selected.length > 0 ? '(' + selected.length + ' selected)' : '');
            
            var preview = '';
            if (selected.length > 0) {
                preview = selected.slice(0, 3).join(', ');
                if (selected.length > 3) {
                    preview += '... and ' + (selected.length - 3) + ' more';
                }
            } else {
                preview = 'None selected';
            }
            $('#wns-selected-preview').text(preview);
            
            wns_close_subscriber_modal();
        };
        
        window.wns_send_newsletter_now = function() {
            var button = $('#wns-send-now-btn');
            button.prop('disabled', true).text('Sending...');
            
            $.post(ajaxurl, {
                action: 'wns_send_post_newsletter_now',
                post_id: <?php echo $post->ID; ?>,
                nonce: '<?php echo wp_create_nonce('wns_send_now_' . $post->ID); ?>',
                send_to_selected: $('input[name="wns_send_to_selected"]:checked').val(),
                selected_subscribers: $('#wns-selected-subscribers-input').val()
            }, function(response) {
                if (response.success) {
                    button.text('✅ Sent!').css('background', '#28a745');
                    alert('Newsletter sent successfully!');
                    location.reload();
                } else {
                    button.prop('disabled', false).text('📧 Send Newsletter Now');
                    alert('Error: ' + response.data.message);
                }
            });
        };
        
    })(jQuery);
    </script>
    <?php
}

// Save meta box data
add_action('save_post', 'wns_save_post_newsletter_meta');

function wns_save_post_newsletter_meta($post_id) {
    // Verify nonce
    if (!isset($_POST['wns_post_newsletter_nonce']) || !wp_verify_nonce($_POST['wns_post_newsletter_nonce'], 'wns_post_newsletter_meta')) {
        return;
    }
    
    // Check if user has permission to edit post
    if (!current_user_can('edit_post', $post_id)) {
        return;
    }
    
    // Skip autosaves
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return;
    }
    
    // Save auto send setting
    $auto_send_enabled = isset($_POST['wns_auto_send_enabled']) ? '1' : '0';
    update_post_meta($post_id, '_wns_auto_send_enabled', $auto_send_enabled);
    
    // Save send to selected setting
    $send_to_selected = isset($_POST['wns_send_to_selected']) ? sanitize_text_field($_POST['wns_send_to_selected']) : '0';
    update_post_meta($post_id, '_wns_send_to_selected', $send_to_selected);
    
    // Save selected subscribers
    $selected_subscribers = isset($_POST['wns_selected_subscribers']) ? json_decode(stripslashes($_POST['wns_selected_subscribers']), true) : array();
    if (is_array($selected_subscribers)) {
        $selected_subscribers = array_map('sanitize_email', $selected_subscribers);
        $selected_subscribers = array_filter($selected_subscribers, 'is_email');
        update_post_meta($post_id, '_wns_selected_subscribers', $selected_subscribers);
    }
    
    // Handle "Send Newsletter on Save" option
    if (isset($_POST['wns_send_on_save']) && $_POST['wns_send_on_save'] === '1') {
        // Check if already sent to prevent duplicates
        $already_sent = get_post_meta($post_id, '_wns_notification_sent', true);
        
        if (!$already_sent) {
            // Send newsletter immediately
            $result = wns_send_post_newsletter_manual($post_id, $send_to_selected, $selected_subscribers);
            
            if ($result['success']) {
                // Mark as sent
                update_post_meta($post_id, '_wns_notification_sent', true);
                
                // Add admin notice
                add_action('admin_notices', function() use ($result) {
                    echo '<div class="notice notice-success is-dismissible">';
                    echo '<p><strong>Newsletter Sent!</strong> ' . esc_html($result['message']) . '</p>';
                    echo '</div>';
                });
            } else {
                // Add error notice
                add_action('admin_notices', function() use ($result) {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>Newsletter Error:</strong> ' . esc_html($result['message']) . '</p>';
                    echo '</div>';
                });
            }
        }
    }
}

// AJAX handler to get subscribers for selection
add_action('wp_ajax_wns_get_subscribers_for_selection', 'wns_ajax_get_subscribers_for_selection');

function wns_ajax_get_subscribers_for_selection() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'wns_get_subscribers')) {
        wp_die('Security check failed');
    }
    
    // Check permissions
    if (!current_user_can('edit_posts')) {
        wp_die('Insufficient permissions');
    }
    
    global $wpdb;
    $table_name = WNS_TABLE_SUBSCRIBERS;
    
    // Get all verified subscribers
    $subscribers = $wpdb->get_results($wpdb->prepare(
        "SELECT email FROM `$table_name` WHERE verified = %d ORDER BY email ASC",
        1
    ));
    
    $selected = isset($_POST['selected']) ? (array) $_POST['selected'] : array();
    
    if (empty($subscribers)) {
        wp_send_json_success(array(
            'html' => '<p>No verified subscribers found.</p>'
        ));
        return;
    }
    
    $html = '<div style="margin-bottom: 15px;">
        <label style="font-weight: bold;">
            <input type="checkbox" id="wns-select-all" style="margin-right: 8px;" />
            Select All (' . count($subscribers) . ' subscribers)
        </label>
    </div>';
    
    $html .= '<div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fafafa;">';
    
    foreach ($subscribers as $subscriber) {
        $checked = in_array($subscriber->email, $selected) ? 'checked' : '';
        $html .= '<label style="display: block; padding: 5px 0; cursor: pointer;">
            <input type="checkbox" value="' . esc_attr($subscriber->email) . '" ' . $checked . ' style="margin-right: 8px;" />
            ' . esc_html($subscriber->email) . '
        </label>';
    }
    
    $html .= '</div>';
    
    $html .= '<script>
        jQuery(function($) {
            $("#wns-select-all").change(function() {
                $("#wns-subscriber-modal input[type=\"checkbox\"]").not(this).prop("checked", this.checked);
            });
        });
    </script>';
    
    wp_send_json_success(array('html' => $html));
}

// AJAX handler to send newsletter now
add_action('wp_ajax_wns_send_post_newsletter_now', 'wns_ajax_send_post_newsletter_now');

function wns_ajax_send_post_newsletter_now() {
    $post_id = intval($_POST['post_id']);
    
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'wns_send_now_' . $post_id)) {
        wp_send_json_error(array('message' => 'Security check failed'));
    }
    
    // Check permissions
    if (!current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'Insufficient permissions'));
    }
    
    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') {
        wp_send_json_error(array('message' => 'Post not found or not published'));
    }
    
    // Check if already sent
    if (get_post_meta($post_id, '_wns_notification_sent', true)) {
        wp_send_json_error(array('message' => 'Newsletter already sent for this post'));
    }
    
    $send_to_selected = sanitize_text_field($_POST['send_to_selected']);
    $selected_subscribers = json_decode(stripslashes($_POST['selected_subscribers']), true);
    
    if (!is_array($selected_subscribers)) {
        $selected_subscribers = array();
    }
    
    // Send the newsletter
    $result = wns_send_post_newsletter_manual($post_id, $send_to_selected, $selected_subscribers);
    
    if ($result['success']) {
        // Mark as sent
        update_post_meta($post_id, '_wns_notification_sent', true);
        wp_send_json_success(array('message' => $result['message']));
    } else {
        wp_send_json_error(array('message' => $result['message']));
    }
}