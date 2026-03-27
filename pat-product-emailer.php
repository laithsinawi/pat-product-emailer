<?php
/**
 * Plugin Name: PAT Product Emailer
 * Description: Send product-specific customer emails from WooCommerce with preview and test-send support.
 * Version: 0.1.0
 * Author: Price Action Tools
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

final class PAT_Product_Emailer {
    const VERSION = '0.1.0';
    const OPTION_KEY = 'pat_product_emailer_settings';
    const PAGE_SLUG = 'pat-product-emailer';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_admin_page']);
    }

    public static function activate() {
        if (!get_option(self::OPTION_KEY)) {
            update_option(self::OPTION_KEY, self::default_settings());
        }
    }

    public static function register_admin_page() {
        add_submenu_page(
            'woocommerce',
            'PAT Product Emailer',
            'Product Emailer',
            'manage_woocommerce',
            self::PAGE_SLUG,
            [__CLASS__, 'render_admin_page']
        );
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        if (!self::is_woocommerce_ready()) {
            echo '<div class="wrap"><h1>PAT Product Emailer</h1><div class="notice notice-error"><p>WooCommerce must be active to use PAT Product Emailer.</p></div></div>';
            return;
        }

        $settings = self::get_settings();
        $state = self::collect_state($settings);
        $notice = null;
        $notice_type = 'success';
        $preview = '';
        $preview_subject = '';
        $sample_recipient = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['patpe_action'])) {
            check_admin_referer('pat_product_emailer_action', 'pat_product_emailer_nonce');

            $action = sanitize_key(wp_unslash($_POST['patpe_action']));
            $result = self::handle_action($action, $state);
            $notice = $result['message'] ?? null;
            $notice_type = $result['type'] ?? 'success';
            if (!empty($result['preview_html'])) {
                $preview = $result['preview_html'];
                $preview_subject = $result['preview_subject'] ?? '';
                $sample_recipient = $result['sample_recipient'] ?? null;
            }
        }

        $product_choices = self::get_product_choices();
        $selected_products = self::get_products_by_ids($state['product_ids']);
        $recipients = !empty($state['product_ids']) ? self::get_recipients_for_products($state['product_ids']) : [];
        $recipient_count = count($recipients);
        $sample_recipients = array_slice($recipients, 0, 8);

        echo '<div class="wrap">';
        echo '<h1>PAT Product Emailer</h1>';

        if ($notice) {
            $class = $notice_type === 'error' ? 'notice notice-error' : 'notice notice-success';
            echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($notice) . '</p></div>';
        }

        echo '<p>Send a targeted email to customers who purchased a specific WooCommerce product. Use the preview and test send options before sending to the live audience.</p>';
        echo '<p><strong>Use responsibly:</strong> this tool does not manage opt-in or unsubscribe logic by itself. Make sure your intended email is appropriate for your customer list and consent rules.</p>';

        echo '<form method="post">';
        wp_nonce_field('pat_product_emailer_action', 'pat_product_emailer_nonce');

        echo '<table class="form-table" role="presentation">';

        echo '<tr>';
        echo '<th scope="row"><label for="patpe_product_ids">Products</label></th>';
        echo '<td>';
        echo '<select name="product_ids[]" id="patpe_product_ids" multiple size="' . esc_attr((string) min(12, max(6, count($product_choices)))) . '" style="min-width: 360px; min-height: 240px;">';
        foreach ($product_choices as $choice) {
            $selected = in_array($choice['id'], $state['product_ids'], true) ? ' selected="selected"' : '';
            echo '<option value="' . esc_attr($choice['id']) . '"' . $selected . '>' . esc_html($choice['label']) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Hold Command or Ctrl to select multiple products. Recipients are unique billing emails from completed, processing, or on-hold orders across all selected products.</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="patpe_subject">Email Subject</label></th>';
        echo '<td><input type="text" class="regular-text" style="width: 100%; max-width: 720px;" name="subject" id="patpe_subject" value="' . esc_attr($state['subject']) . '" /></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="patpe_heading">Email Heading</label></th>';
        echo '<td><input type="text" class="regular-text" style="width: 100%; max-width: 720px;" name="heading" id="patpe_heading" value="' . esc_attr($state['heading']) . '" />';
        echo '<p class="description">Used by the WooCommerce email wrapper above the message body.</p></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Email Body</th>';
        echo '<td>';
        wp_editor(
            $state['body'],
            'patpe_body',
            [
                'textarea_name' => 'body',
                'textarea_rows' => 14,
                'media_buttons' => false,
                'teeny' => false,
            ]
        );
        echo '<p class="description">Supported placeholders: <code>{{first_name}}</code>, <code>{{last_name}}</code>, <code>{{full_name}}</code>, <code>{{email}}</code>, <code>{{product_name}}</code>, <code>{{product_url}}</code>, <code>{{product_count}}</code>, <code>{{site_name}}</code>, <code>{{site_url}}</code>. When multiple products are selected, <code>{{product_name}}</code> becomes a comma-separated list and <code>{{product_url}}</code> uses the first selected product URL.</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="patpe_test_email">Test Email Address</label></th>';
        echo '<td><input type="email" class="regular-text" style="width: 100%; max-width: 420px;" name="test_email" id="patpe_test_email" value="' . esc_attr($state['test_email']) . '" />';
        echo '<p class="description">Test emails use a sample customer context from the selected product when available.</p></td>';
        echo '</tr>';

        echo '</table>';

        echo '<p>';
        echo '<button type="submit" class="button button-secondary" name="patpe_action" value="save_template">Save Template</button> ';
        echo '<button type="submit" class="button button-secondary" name="patpe_action" value="preview_email">Preview Email</button> ';
        echo '<button type="submit" class="button button-secondary" name="patpe_action" value="send_test_email">Send Test Email</button> ';
        echo '<button type="submit" class="button button-primary" name="patpe_action" value="send_campaign" onclick="return confirm(\'Send this email to all matching customers for the selected product?\');">Send To Matching Customers</button>';
        echo '</p>';

        if (!empty($selected_products)) {
            echo '<hr />';
            echo '<h2>Audience Summary</h2>';
            echo '<p><strong>Selected products:</strong></p>';
            echo '<ul>';
            foreach ($selected_products as $selected_product) {
                echo '<li>' . esc_html($selected_product->get_name()) . '</li>';
            }
            echo '</ul>';
            echo '<p><strong>Unique recipients:</strong> ' . esc_html((string) $recipient_count) . '</p>';
            if (!empty($sample_recipients)) {
                echo '<p><strong>Sample recipients:</strong></p>';
                echo '<ul>';
                foreach ($sample_recipients as $recipient) {
                    $name = trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''));
                    $label = $name !== '' ? $name . ' <' . $recipient['email'] . '>' : $recipient['email'];
                    echo '<li>' . esc_html($label) . '</li>';
                }
                echo '</ul>';
            } else {
                echo '<p>No matching recipients were found for the selected product.</p>';
            }
        }

        if ($preview) {
            echo '<hr />';
            echo '<h2>Email Preview</h2>';
            if ($sample_recipient) {
                $sample_label = trim(($sample_recipient['first_name'] ?? '') . ' ' . ($sample_recipient['last_name'] ?? ''));
                $sample_label = $sample_label !== '' ? $sample_label . ' <' . ($sample_recipient['email'] ?? '') . '>' : ($sample_recipient['email'] ?? '');
                echo '<p><strong>Preview context:</strong> ' . esc_html($sample_label) . '</p>';
            }
            echo '<p><strong>Subject:</strong> ' . esc_html($preview_subject) . '</p>';
            echo '<div style="background: #fff; border: 1px solid #ccd0d4; max-width: 900px; padding: 16px;">' . $preview . '</div>';
        }

        echo '</form>';
        echo '</div>';
    }

    private static function handle_action($action, array $state) {
        switch ($action) {
            case 'save_template':
                self::save_settings_from_state($state);
                return [
                    'type' => 'success',
                    'message' => 'Template saved.',
                ];

            case 'preview_email':
                return self::build_preview_result($state);

            case 'send_test_email':
                self::save_settings_from_state($state);
                return self::send_test_email($state);

            case 'send_campaign':
                self::save_settings_from_state($state);
                return self::send_campaign($state);

            default:
                return [
                    'type' => 'error',
                    'message' => 'Unknown action.',
                ];
        }
    }

    private static function build_preview_result(array $state) {
        $validation = self::validate_template_state($state);
        if ($validation) {
            return $validation;
        }

        $sample = self::get_sample_recipient($state['product_ids']);
        $rendered = self::render_email($state, $sample);

        return [
            'type' => 'success',
            'message' => 'Preview generated below.',
            'preview_html' => $rendered['html'],
            'preview_subject' => $rendered['subject'],
            'sample_recipient' => $sample,
        ];
    }

    private static function send_test_email(array $state) {
        $validation = self::validate_template_state($state);
        if ($validation) {
            return $validation;
        }

        $test_email = sanitize_email($state['test_email']);
        if ($test_email === '') {
            return [
                'type' => 'error',
                'message' => 'Enter a valid test email address.',
            ];
        }

        $sample = self::get_sample_recipient($state['product_ids']);
        $rendered = self::render_email($state, $sample);
        $sent = self::send_html_email($test_email, $rendered['subject'], $rendered['html']);

        return [
            'type' => $sent ? 'success' : 'error',
            'message' => $sent ? 'Test email sent to ' . $test_email . '.' : 'Test email could not be sent.',
            'preview_html' => $rendered['html'],
            'preview_subject' => $rendered['subject'],
            'sample_recipient' => $sample,
        ];
    }

    private static function send_campaign(array $state) {
        $validation = self::validate_template_state($state);
        if ($validation) {
            return $validation;
        }

        $recipients = self::get_recipients_for_products($state['product_ids']);
        if (empty($recipients)) {
            return [
                'type' => 'error',
                'message' => 'No matching recipients were found for the selected products.',
            ];
        }

        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $sent = 0;
        $failed = 0;
        foreach ($recipients as $recipient) {
            $rendered = self::render_email($state, $recipient);
            $ok = self::send_html_email($recipient['email'], $rendered['subject'], $rendered['html']);
            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $message = sprintf(
            'Campaign finished. Sent: %1$d. Failed: %2$d.',
            $sent,
            $failed
        );

        return [
            'type' => $failed > 0 ? 'error' : 'success',
            'message' => $message,
        ];
    }

    private static function validate_template_state(array $state) {
        if (empty($state['product_ids'])) {
            return [
                'type' => 'error',
                'message' => 'Select at least one product first.',
            ];
        }

        if (trim($state['subject']) === '') {
            return [
                'type' => 'error',
                'message' => 'Enter an email subject.',
            ];
        }

        if (trim(wp_strip_all_tags($state['body'])) === '') {
            return [
                'type' => 'error',
                'message' => 'Enter email body content.',
            ];
        }

        return null;
    }

    private static function render_email(array $state, array $recipient) {
        $product_context = self::get_template_product_context($state['product_ids']);

        $context = [
            '{{first_name}}' => $recipient['first_name'] ?? '',
            '{{last_name}}' => $recipient['last_name'] ?? '',
            '{{full_name}}' => trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? '')),
            '{{email}}' => $recipient['email'] ?? '',
            '{{product_name}}' => $product_context['product_name'],
            '{{product_url}}' => $product_context['product_url'],
            '{{product_count}}' => (string) $product_context['product_count'],
            '{{site_name}}' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            '{{site_url}}' => home_url('/'),
        ];

        $subject = self::replace_tokens($state['subject'], $context);
        $heading = trim($state['heading']) !== '' ? self::replace_tokens($state['heading'], $context) : $subject;
        $body = self::replace_tokens($state['body'], $context);

        $mailer = WC()->mailer();
        $wrapped = $mailer->wrap_message($heading, wpautop(wp_kses_post($body)));
        if (method_exists($mailer, 'style_inline')) {
            $wrapped = $mailer->style_inline($wrapped);
        }

        return [
            'subject' => $subject,
            'html' => $wrapped,
        ];
    }

    private static function replace_tokens($value, array $context) {
        return strtr((string) $value, $context);
    }

    private static function send_html_email($to, $subject, $html) {
        $mailer = WC()->mailer();
        return (bool) $mailer->send(
            $to,
            wp_specialchars_decode($subject, ENT_QUOTES),
            $html,
            "Content-Type: text/html; charset=UTF-8\r\n"
        );
    }

    private static function get_sample_recipient(array $product_ids) {
        $recipients = self::get_recipients_for_products($product_ids);
        if (!empty($recipients)) {
            return $recipients[0];
        }

        $user = wp_get_current_user();
        return [
            'email' => $user && $user->exists() ? $user->user_email : get_option('admin_email'),
            'first_name' => $user && $user->exists() ? $user->first_name : '',
            'last_name' => $user && $user->exists() ? $user->last_name : '',
        ];
    }

    private static function get_recipients_for_products(array $product_ids) {
        $product_ids = self::normalize_product_ids($product_ids);
        if (empty($product_ids)) {
            return [];
        }

        $target_ids = self::get_target_product_ids($product_ids);
        if (empty($target_ids)) {
            return [];
        }

        $unique = [];
        $page = 1;
        $max_pages = 1;

        do {
            $result = wc_get_orders([
                'status' => ['processing', 'completed', 'on-hold'],
                'limit' => 200,
                'paginate' => true,
                'page' => $page,
                'orderby' => 'date',
                'order' => 'DESC',
                'return' => 'objects',
            ]);

            if (!is_object($result) || empty($result->orders)) {
                break;
            }

            foreach ($result->orders as $order) {
                if (!$order instanceof WC_Order) {
                    continue;
                }

                $matches_product = false;
                foreach ($order->get_items('line_item') as $item) {
                    $line_product_id = absint($item->get_product_id());
                    $line_variation_id = absint($item->get_variation_id());
                    if (in_array($line_product_id, $target_ids, true) || in_array($line_variation_id, $target_ids, true)) {
                        $matches_product = true;
                        break;
                    }
                }

                if (!$matches_product) {
                    continue;
                }

                $email = sanitize_email($order->get_billing_email());
                if ($email === '') {
                    continue;
                }

                $key = strtolower($email);
                if (isset($unique[$key])) {
                    continue;
                }

                $unique[$key] = [
                    'email' => $email,
                    'first_name' => sanitize_text_field($order->get_billing_first_name()),
                    'last_name' => sanitize_text_field($order->get_billing_last_name()),
                    'order_id' => $order->get_id(),
                ];
            }

            $max_pages = isset($result->max_num_pages) ? absint($result->max_num_pages) : 1;
            $page++;
        }
        while ($page <= $max_pages);

        return array_values($unique);
    }

    private static function get_target_product_ids(array $product_ids) {
        $product_ids = self::normalize_product_ids($product_ids);
        if (empty($product_ids)) {
            return [];
        }

        $ids = $product_ids;
        foreach ($product_ids as $product_id) {
            $variation_ids = get_posts([
                'post_type' => 'product_variation',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'post_parent' => $product_id,
                'post_status' => ['publish', 'private'],
            ]);

            if (!empty($variation_ids)) {
                $ids = array_merge($ids, array_map('absint', $variation_ids));
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private static function get_product_choices() {
        $posts = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'private'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $choices = [];
        foreach ($posts as $post) {
            $choices[] = [
                'id' => absint($post->ID),
                'label' => get_the_title($post),
            ];
        }

        return $choices;
    }

    private static function collect_state(array $settings) {
        $product_ids = [];
        if (isset($_POST['product_ids'])) {
            $product_ids = self::normalize_product_ids(wp_unslash($_POST['product_ids']));
        } elseif (isset($_GET['product_ids'])) {
            $product_ids = self::normalize_product_ids(wp_unslash($_GET['product_ids']));
        } elseif (isset($_POST['product_id'])) {
            $product_ids = self::normalize_product_ids([wp_unslash($_POST['product_id'])]);
        } elseif (isset($_GET['product_id'])) {
            $product_ids = self::normalize_product_ids([wp_unslash($_GET['product_id'])]);
        } elseif (isset($settings['product_ids'])) {
            $product_ids = self::normalize_product_ids($settings['product_ids']);
        }

        return [
            'product_ids' => $product_ids,
            'subject' => isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : $settings['subject'],
            'heading' => isset($_POST['heading']) ? sanitize_text_field(wp_unslash($_POST['heading'])) : $settings['heading'],
            'body' => isset($_POST['body']) ? wp_kses_post(wp_unslash($_POST['body'])) : $settings['body'],
            'test_email' => isset($_POST['test_email']) ? sanitize_email(wp_unslash($_POST['test_email'])) : $settings['test_email'],
        ];
    }

    private static function save_settings_from_state(array $state) {
        update_option(self::OPTION_KEY, [
            'product_ids' => $state['product_ids'],
            'subject' => $state['subject'],
            'heading' => $state['heading'],
            'body' => $state['body'],
            'test_email' => $state['test_email'],
        ]);
    }

    private static function get_settings() {
        return wp_parse_args(get_option(self::OPTION_KEY, []), self::default_settings());
    }

    private static function default_settings() {
        return [
            'product_ids' => [],
            'subject' => '{{product_name}} update from {{site_name}}',
            'heading' => '{{product_name}} update',
            'body' => "Hi {{first_name}},\n\nWe wanted to send you a quick update about {{product_name}}.\n\n[Add your message here]\n\nBest regards,\n{{site_name}}",
            'test_email' => get_option('admin_email'),
        ];
    }

    private static function normalize_product_ids($product_ids) {
        if (!is_array($product_ids)) {
            $product_ids = [$product_ids];
        }

        return array_values(array_unique(array_filter(array_map('absint', $product_ids))));
    }

    private static function get_products_by_ids(array $product_ids) {
        $products = [];
        foreach (self::normalize_product_ids($product_ids) as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    private static function get_template_product_context(array $product_ids) {
        $products = self::get_products_by_ids($product_ids);
        if (empty($products)) {
            return [
                'product_name' => '',
                'product_url' => '',
                'product_count' => 0,
            ];
        }

        $product_names = [];
        foreach ($products as $product) {
            $product_names[] = $product->get_name();
        }

        return [
            'product_name' => implode(', ', $product_names),
            'product_url' => get_permalink($products[0]->get_id()),
            'product_count' => count($products),
        ];
    }

    private static function is_woocommerce_ready() {
        return class_exists('WooCommerce') && function_exists('wc_get_product');
    }
}

PAT_Product_Emailer::init();
register_activation_hook(__FILE__, ['PAT_Product_Emailer', 'activate']);
