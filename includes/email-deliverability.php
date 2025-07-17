<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email Deliverability Enhancement Class
 * Implements best practices for high-volume email sending
 */
class WNS_Email_Deliverability {
    
    /**
     * Initialize deliverability enhancements
     */
    public static function init() {
        // Hook into wp_mail to enhance headers and content
        add_filter('wp_mail', array(__CLASS__, 'enhance_email_headers'), 10, 1);
        
        // Add email authentication headers
        add_action('phpmailer_init', array(__CLASS__, 'configure_phpmailer'));
        
        // Implement sending rate limits
        add_filter('wns_email_batch_size', array(__CLASS__, 'adjust_batch_size_by_time'));
        
        // Add email content validation
        add_filter('wns_email_content', array(__CLASS__, 'optimize_email_content'), 10, 2);
        
        // Track email reputation
        add_action('wp_mail_succeeded', array(__CLASS__, 'track_email_success'));
        add_action('wp_mail_failed', array(__CLASS__, 'track_email_failure'));
    }
    
    /**
     * Enhance email headers for better deliverability
     */
    public static function enhance_email_headers($args) {
        // Skip if not a newsletter email
        if (!self::is_newsletter_email($args)) {
            return $args;
        }
        
        $site_name = get_bloginfo('name');
        $site_domain = parse_url(home_url(), PHP_URL_HOST);
        $admin_email = get_option('admin_email');
        
        // Get recipient email
        $recipient_email = is_array($args['to']) ? reset($args['to']) : $args['to'];
        
        // Enhanced headers for deliverability
        $enhanced_headers = array(
            // Authentication and identification
            'From: ' . $site_name . ' <' . $admin_email . '>',
            'Reply-To: ' . $admin_email,
            'Return-Path: ' . $admin_email,
            'Sender: ' . $admin_email,
            
            // Content type and encoding
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'MIME-Version: 1.0',
            
            // List management (RFC 2369)
            'List-ID: ' . $site_name . ' Newsletter <newsletter.' . $site_domain . '>',
            'List-Unsubscribe: <' . wns_get_unsubscribe_link($recipient_email) . '>',
            'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
            'List-Archive: <' . home_url() . '>',
            'List-Owner: <mailto:' . $admin_email . '>',
            'List-Subscribe: <' . home_url() . '>',
            
            // Email client optimization
            'X-Mailer: WordPress/' . get_bloginfo('version') . ' - Newsletter System',
            'X-Priority: 3',
            'X-MSMail-Priority: Normal',
            'Importance: Normal',
            
            // Bulk email identification
            'Precedence: bulk',
            'Auto-Submitted: auto-generated',
            
            // Message categorization for Gmail
            'X-Google-Original-From: ' . $admin_email,
            'X-Auto-Response-Suppress: All',
            
            // Feedback loop headers
            'X-Campaign-ID: newsletter-' . date('Y-m-d'),
            'X-Mailer-LID: ' . md5($site_domain . date('Y-m-d')),
            
            // Security headers
            'X-Content-Type-Options: nosniff',
            'X-Frame-Options: DENY'
        );
        
        // Merge with existing headers
        if (isset($args['headers'])) {
            if (is_array($args['headers'])) {
                $args['headers'] = array_merge($args['headers'], $enhanced_headers);
            } else {
                $args['headers'] .= "\r\n" . implode("\r\n", $enhanced_headers);
            }
        } else {
            $args['headers'] = $enhanced_headers;
        }
        
        return $args;
    }
    
    /**
     * Configure PHPMailer for optimal delivery
     */
    public static function configure_phpmailer($phpmailer) {
        // Set encoding to prevent content issues
        $phpmailer->CharSet = 'UTF-8';
        $phpmailer->Encoding = '8bit';
        
        // Set word wrap to prevent long lines
        $phpmailer->WordWrap = 78;
        
        // Enable SMTP keep-alive for better performance
        $phpmailer->SMTPKeepAlive = true;
        
        // Set timeout values
        $phpmailer->Timeout = 30;
        
        // Check if SMTPTimeout property exists before setting it
        if (property_exists($phpmailer, 'SMTPTimeout')) {
            $phpmailer->SMTPTimeout = 30;
        }
        
        // Add custom Message-ID for tracking
        $domain = parse_url(home_url(), PHP_URL_HOST);
        $phpmailer->MessageID = '<' . uniqid() . '@' . $domain . '>';
    }
    
    /**
     * Adjust batch size based on time of day and reputation
     */
    public static function adjust_batch_size_by_time($batch_size) {
        $current_hour = (int) date('H');
        $reputation_score = self::get_email_reputation_score();
        
        // Reduce batch size during peak hours (9 AM - 5 PM)
        if ($current_hour >= 9 && $current_hour <= 17) {
            $batch_size = min($batch_size, 50);
        }
        
        // Adjust based on reputation
        if ($reputation_score < 0.8) {
            $batch_size = min($batch_size, 25); // Reduce for poor reputation
        } elseif ($reputation_score > 0.95) {
            $batch_size = min($batch_size * 1.5, 200); // Increase for good reputation
        }
        
        return $batch_size;
    }
    
    /**
     * Optimize email content for deliverability
     */
    public static function optimize_email_content($content, $type = 'newsletter') {
        // Remove potentially spammy elements
        $content = self::remove_spam_triggers($content);
        
        // Add proper text/HTML ratio
        $content = self::ensure_text_html_balance($content);
        
        // Add proper unsubscribe link
        $content = self::ensure_unsubscribe_compliance($content);
        
        // Add view in browser link
        $content = self::add_view_in_browser_link($content);
        
        return $content;
    }
    
    /**
     * Remove common spam triggers from content
     */
    private static function remove_spam_triggers($content) {
        $spam_words = array(
            'FREE!', 'URGENT!', 'ACT NOW!', 'LIMITED TIME!',
            'CLICK HERE NOW', 'MAKE MONEY FAST', 'GUARANTEED',
            'NO OBLIGATION', 'RISK FREE', 'CASH BONUS'
        );
        
        foreach ($spam_words as $word) {
            $content = str_ireplace($word, ucwords(strtolower($word)), $content);
        }
        
        // Remove excessive punctuation
        $content = preg_replace('/!{2,}/', '!', $content);
        $content = preg_replace('/\?{2,}/', '?', $content);
        
        // Remove excessive capitalization
        $content = preg_replace_callback('/[A-Z]{4,}/', function($matches) {
            return ucfirst(strtolower($matches[0]));
        }, $content);
        
        return $content;
    }
    
    /**
     * Ensure proper text/HTML balance
     */
    private static function ensure_text_html_balance($content) {
        // Add alt text to images
        $content = preg_replace('/<img([^>]*?)(?:alt=["\'][^"\']*["\'])?([^>]*?)>/i', 
            '<img$1 alt="Newsletter Image"$2>', $content);
        
        // Ensure links have descriptive text
        $content = preg_replace('/<a[^>]*>(?:click here|here|link)<\/a>/i', 
            '<a href="#">Read More</a>', $content);
        
        return $content;
    }
    
    /**
     * Ensure unsubscribe compliance
     */
    private static function ensure_unsubscribe_compliance($content) {
        if (strpos($content, '{unsubscribe_link}') === false) {
            $content .= '<br><br><small><a href="{unsubscribe_link}">Unsubscribe from this newsletter</a></small>';
        }
        
        return $content;
    }
    
    /**
     * Add view in browser link
     */
    private static function add_view_in_browser_link($content) {
        $browser_link = '<p style="text-align: center; font-size: 12px; color: #666;">
            Having trouble viewing this email? <a href="' . home_url() . '">View it in your browser</a>
        </p>';
        
        return $browser_link . $content;
    }
    
    /**
     * Check if email is a newsletter email
     */
    private static function is_newsletter_email($args) {
        // Check if it's from our plugin
        if (isset($args['headers'])) {
            $headers_string = is_array($args['headers']) ? implode(' ', $args['headers']) : $args['headers'];
            return strpos($headers_string, 'WP Newsletter Plugin') !== false ||
                   strpos($headers_string, 'newsletter') !== false;
        }
        
        return false;
    }
    
    /**
     * Track email success for reputation scoring
     */
    public static function track_email_success($mail_data) {
        $stats = get_option('wns_email_stats', array(
            'sent' => 0,
            'failed' => 0,
            'last_reset' => time()
        ));
        
        $stats['sent']++;
        update_option('wns_email_stats', $stats);
    }
    
    /**
     * Track email failures for reputation scoring
     */
    public static function track_email_failure($wp_error) {
        $stats = get_option('wns_email_stats', array(
            'sent' => 0,
            'failed' => 0,
            'last_reset' => time()
        ));
        
        $stats['failed']++;
        update_option('wns_email_stats', $stats);
        
        // Log failure for analysis
        error_log('WNS Email Failure: ' . $wp_error->get_error_message());
    }
    
    /**
     * Get email reputation score (0-1)
     */
    private static function get_email_reputation_score() {
        $stats = get_option('wns_email_stats', array(
            'sent' => 0,
            'failed' => 0,
            'last_reset' => time()
        ));
        
        $total = $stats['sent'] + $stats['failed'];
        if ($total === 0) return 1.0;
        
        return $stats['sent'] / $total;
    }
    
    /**
     * Implement progressive sending delays
     */
    public static function get_progressive_delay($batch_number) {
        // Start with 1 minute, increase gradually
        $base_delay = 60; // 1 minute
        $progressive_delay = $base_delay * (1 + ($batch_number * 0.1));
        
        // Cap at 10 minutes
        return min($progressive_delay, 600);
    }
}

// Initialize the deliverability enhancements
WNS_Email_Deliverability::init();