<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Subscriber List Table Class
 */
class WNS_Subscriber_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => __('Subscriber', 'wp-newsletter-subscription'),
            'plural'   => __('Subscribers', 'wp-newsletter-subscription'),
            'ajax'     => false
        ]);
    }

    /**
     * Columns shown in table
     */
    public function get_columns() {
        return [
            'cb'         => '<input type="checkbox" />',
            'email'      => __('Email', 'wp-newsletter-subscription'),
            'verified'   => __('Verified', 'wp-newsletter-subscription'),
            'created_at' => __('Date Subscribed', 'wp-newsletter-subscription')
        ];
    }

    /**
     * Sortable columns
     */
    public function get_sortable_columns() {
        return [
            'email'      => ['email', false],
            'verified'   => ['verified', false],
            'created_at' => ['created_at', true]
        ];
    }

    /**
     * Default column handler
     */
    protected function column_default($item, $column_name) {
        switch ($column_name) {
            case 'email':
            case 'created_at':
                return esc_html($item->$column_name);
            default:
                return '';
        }
    }

    /**
     * Verified column
     */
    function column_verified($item) {
        return $item->verified ? 
            '<span class="dashicons dashicons-yes" style="color: green;"></span> ' . esc_html__('Yes', 'wp-newsletter-subscription') : 
            '<span class="dashicons dashicons-no-alt" style="color: red;"></span> ' . esc_html__('No', 'wp-newsletter-subscription');
    }

    /**
     * Checkbox column
     */
    function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="subscriber[]" value="%d" />',
            absint($item->id)
        );
    }

    /**
     * Bulk actions
     */
    public function get_bulk_actions() {
        return [
            'delete' => __('Delete', 'wp-newsletter-subscription'),
            'verify' => __('Mark as Verified', 'wp-newsletter-subscription'),
            'unverify' => __('Mark as Unverified', 'wp-newsletter-subscription')
        ];
    }

    /**
     * Prepare items for display
     */
    public function prepare_items() {
        // Security check
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wp-newsletter-subscription'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'newsletter_subscribers';

        // Verify table exists
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
            return;
        }

        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = [$columns, $hidden, $sortable];

        // Handle bulk actions with proper security checks
        $this->process_bulk_action();

        // Build query with proper sanitization
        $where_conditions = [];
        $where_values = [];

        // Search functionality - only search by email
        if (!empty($_REQUEST['s'])) {
            $search_term = sanitize_text_field($_REQUEST['s']);
            // Only allow email-like searches for security
            if (filter_var($search_term, FILTER_VALIDATE_EMAIL) || strpos($search_term, '@') !== false) {
                $where_conditions[] = "`email` LIKE %s";
                $where_values[] = '%' . $wpdb->esc_like($search_term) . '%';
            }
        }

        // Verified filter
        if (isset($_REQUEST['verified']) && in_array($_REQUEST['verified'], ['yes', 'no'])) {
            $verified_filter = sanitize_text_field($_REQUEST['verified']);
            $where_conditions[] = "`verified` = %d";
            $where_values[] = ($verified_filter === 'yes') ? 1 : 0;
        }

        $where_clause = '';
        if (!empty($where_conditions)) {
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        }

        // Pagination and ordering with whitelist
        $allowed_orderby = ['email', 'verified', 'created_at'];
        $orderby = 'created_at';
        if (!empty($_REQUEST['orderby']) && in_array($_REQUEST['orderby'], $allowed_orderby)) {
            $orderby = sanitize_key($_REQUEST['orderby']);
        }

        $order = 'DESC';
        if (!empty($_REQUEST['order']) && in_array(strtoupper($_REQUEST['order']), ['ASC', 'DESC'])) {
            $order = strtoupper(sanitize_text_field($_REQUEST['order']));
        }

        $per_page = 20;
        $current_page = $this->get_pagenum();

        // Count total items
        $count_query = "SELECT COUNT(*) FROM `$table_name` $where_clause";
        if (!empty($where_values)) {
            $total_items = $wpdb->get_var($wpdb->prepare($count_query, $where_values));
        } else {
            $total_items = $wpdb->get_var($count_query);
        }

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page
        ]);

        $offset = ($current_page - 1) * $per_page;

        // Get items
        $query = "SELECT * FROM `$table_name` $where_clause ORDER BY `$orderby` $order LIMIT %d OFFSET %d";
        $query_values = array_merge($where_values, [$per_page, $offset]);

        if (!empty($where_values)) {
            $this->items = $wpdb->get_results($wpdb->prepare($query, $query_values));
        } else {
            $this->items = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table_name` ORDER BY `$orderby` $order LIMIT %d OFFSET %d", $per_page, $offset));
        }
    }

    /**
     * Process bulk actions
     */
    public function process_bulk_action() {
        if (!isset($_REQUEST['action']) || !isset($_REQUEST['subscriber'])) {
            return;
        }

        // Verify nonce
        if (!wp_verify_nonce($_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'])) {
            wp_die(__('Security check failed.', 'wp-newsletter-subscription'));
        }

        $action = sanitize_text_field($_REQUEST['action']);
        $ids = array_map('absint', (array)$_REQUEST['subscriber']);
        $ids = array_filter($ids); // Remove any zero values
        
        if (empty($ids)) {
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'newsletter_subscribers';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        switch ($action) {
            case 'delete':
                $wpdb->query($wpdb->prepare("DELETE FROM `$table_name` WHERE `id` IN ($placeholders)", $ids));
                break;
            case 'verify':
                $wpdb->query($wpdb->prepare("UPDATE `$table_name` SET `verified` = 1 WHERE `id` IN ($placeholders)", $ids));
                break;
            case 'unverify':
                $wpdb->query($wpdb->prepare("UPDATE `$table_name` SET `verified` = 0 WHERE `id` IN ($placeholders)", $ids));
                break;
        }
    }

    /**
     * Extra controls above table
     */
    function extra_tablenav($which) {
        if ($which === 'top') {
            ?>
            <div class="alignleft actions">
                <?php
                $verified_filter = isset($_REQUEST['verified']) ? sanitize_text_field($_REQUEST['verified']) : '';
                ?>
                <label for="filter-by-verified"><?php esc_html_e('Show:', 'wp-newsletter-subscription'); ?></label>
                <select name="verified" id="filter-by-verified">
                    <option value=""><?php esc_html_e('All Statuses', 'wp-newsletter-subscription'); ?></option>
                    <option value="yes" <?php selected($verified_filter, 'yes'); ?>><?php esc_html_e('Verified', 'wp-newsletter-subscription'); ?></option>
                    <option value="no" <?php selected($verified_filter, 'no'); ?>><?php esc_html_e('Unverified', 'wp-newsletter-subscription'); ?></option>
                </select>

                <?php
                submit_button(__('Filter', 'wp-newsletter-subscription'), '', 'filter_action', false);

                $search_value = isset($_REQUEST['s']) ? esc_attr(sanitize_text_field($_REQUEST['s'])) : '';
                echo '<input type="text" name="s" value="' . $search_value . '" placeholder="' . esc_attr__('Search Email', 'wp-newsletter-subscription') . '" />';
                submit_button(__('Search', 'wp-newsletter-subscription'), '', '', false);
                ?>
            </div>
            <?php
        }
    }
}