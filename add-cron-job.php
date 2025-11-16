<?php
/**
 * Plugin Name: PTI Scheduled Email
 * Description: Schedules and sends a reminder email after a WooCommerce purchase via a custom database table and a single cron job (UTC-safe).
 * Version: 1.2
 * Author: PTI
 */

if (!defined('ABSPATH')) exit;

// ✅ Configure
// Define your face-to-face product IDs here
define('FACE_2_FACE_PRODUCT_CODES', array(107,97,89,73,1748,5640,403,736,743,747,2300,2809,2190,544,545,543));

define('PHLEBOTOMY_TRAINING_OMNISEND', [107,97,89,73,1748,5640]);


// Send all emails to a test address (debug mode)
$debug_moode_emails = false;

// Omnisend API Key (prefer to store in wp-config.php as define('PTI_OMNISEND_API_KEY','xxx'))
if (!defined('PTI_OMNISEND_API_KEY')) {
    define('PTI_OMNISEND_API_KEY','674491f51d1247c5759cfa17-9p94MkrT6DY0wFw1uP6fP4JX7KrH3WyMGeszzTOZI5AnqH7mYa');
}

// --- Helper function ---
if (!function_exists('post_id_by_product_id')) {
    function post_id_by_product_id($product_id) {
        // If product ↔ post mapping differs, adjust here.
        return $product_id;
    }
}

// --- Plugin Activation/Deactivation Hooks ---
register_activation_hook(__FILE__, 'pti_create_email_schedule_table');
function pti_create_email_schedule_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'scheduled_emails';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        mailto VARCHAR(255) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        content LONGTEXT NOT NULL,
        order_id BIGINT(20) DEFAULT NULL,
        product_id BIGINT(20) DEFAULT NULL,
        variation_id BIGINT(20) DEFAULT NULL,
        scheduled_time DATETIME NOT NULL,   -- stored in UTC
        sent_time TEXT DEFAULT NULL,        -- may contain 'Deleted', 'Bin', or a datetime string
        PRIMARY KEY (id),
        KEY idx_scheduled (scheduled_time),
        KEY idx_sent (sent_time(10)),
        KEY idx_order (order_id),
        KEY idx_variation (variation_id)
    ) $charset_collate;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

register_deactivation_hook(__FILE__, 'pti_clear_email_cron_job');
function pti_clear_email_cron_job()
{
    wp_clear_scheduled_hook('pti_send_scheduled_emails');
}

// --- Refund Handling ---
add_action('woocommerce_order_status_refunded', 'pti_remove_email_by_order_id', 10, 1);
function pti_remove_email_by_order_id($order_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'scheduled_emails';
    $wpdb->delete($table_name, ['order_id' => $order_id], ['%d']);
}

// --- Core Scheduling Logic ---
add_action('woocommerce_order_status_completed', 'schedule_events_on_purchase', 10, 1);
function schedule_events_on_purchase($order_id, $return = false)
{
    error_log("PTI Scheduled Email: Attempting to schedule email for order ID: " . $order_id);

    global $wpdb, $debug_moode_emails;
    $order = wc_get_order($order_id);
    if (!$order) {
        error_log("PTI Scheduled Email: Order ID $order_id not found.");
        if ($return) wp_send_json_error(['message' => 'Order not found.']);
        return;
    }

    $table_name = $wpdb->prefix . 'scheduled_emails';
    $customer_email = $debug_moode_emails ? "ferdous935174@gmail.com" : $order->get_billing_email();

    $three_days_ids = [2809, 2190, 544, 545, 543];

    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        error_log("PTI Scheduled Email: Processing Product ID $product_id in order $order_id. In allowed list: " . (in_array($product_id, FACE_2_FACE_PRODUCT_CODES) ? 'Yes' : 'No'));

        if (!in_array($product_id, FACE_2_FACE_PRODUCT_CODES)) {
            continue;
        }

        $meta_id = post_id_by_product_id($product_id);
        if (!$meta_id) {
            error_log("PTI Scheduled Email: Could not find post_id for product_id: $product_id on order_id: $order_id");
            continue;
        }

        $variation_id   = $item->get_variation_id();
        $variation_data = $item->get_meta_data();
        $location       = get_post_meta($meta_id, 'phuk_course_location_root', true);
        $phuk_ph_course_vars = get_post_meta($meta_id, 'phuk_ph_course_vars', true);

        if (!is_array($phuk_ph_course_vars)) {
            error_log("PTI Scheduled Email: Meta 'phuk_ph_course_vars' not found or not an array for meta_id: $meta_id");
            continue;
        }

        $product_obj = wc_get_product($product_id);
        $course_name = $product_obj ? $product_obj->get_name() : 'Unknown Course';

        $variation_name = "";
        foreach ($variation_data as $meta) {
            if ($meta->key === 'courses') {
                $variation_name = $meta->value;
                break;
            }
        }

        $first_date = "";
        $middle_date = "";
        $last_date = "";

        foreach ($phuk_ph_course_vars as $course) {
            if (isset($course['phuk_course_var_id']) && intval($course['phuk_course_var_id']) === intval($variation_id)) {
                $first_date  = $course['adv_course_date'] ?? '';
                $middle_date = $course['adv_course_middle_date'] ?? '';
                $last_date   = in_array($product_id, $three_days_ids)
                                ? ($course['adv_course_last_date'] ?? ($course['adv_course_date'] ?? ''))
                                : ($course['adv_course_date'] ?? '');
                break;
            }
        }

        if (empty($first_date)) {
            error_log("PTI Scheduled Email: Could not find first_date for variation_id: $variation_id on order_id: $order_id");
            continue;
        }

        $special_template_status = get_post_meta($meta_id, 'special_template_status', true);

        $reminder_properties = [
            "product-id" => $meta_id,
            "Course_Name" => $course_name,
            "Course_Date" => $variation_name ?: "",
            "location" => $location,
            "special_template_status" => $special_template_status ?: 'No',
        ];

        // ✅ Schedule Reminder at 10:00 AM one day before FIRST date — stored as UTC
        $site_tz = wp_timezone(); // WP Timezone object
        try {
            // Interpret $first_date as a date in site timezone at 10:00, then -1 day
            $dt = new DateTime($first_date . ' 10:00', $site_tz); // e.g., "2025-10-10 10:00" local
            $dt->modify('-1 day');
            $dt->setTimezone(new DateTimeZone('UTC'));
            $reminder_time_utc = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            error_log('PTI Scheduled Email: Date parse error for first_date='.$first_date.' : '.$e->getMessage());
            continue;
        }

        $reminder_subject = 'Reminder - Venue Details of ' . $course_name;

        $insert_result = $wpdb->insert($table_name, [
            'mailto'         => $customer_email,
            'subject'        => $reminder_subject,
            'content'        => wp_json_encode(['eventName' => 'ReminderMailPTI', 'properties' => $reminder_properties]),
            'scheduled_time' => $reminder_time_utc, // UTC
            'order_id'       => $order_id,
            'product_id'     => $product_id,
            'variation_id'   => $variation_id,
        ], ['%s','%s','%s','%s','%d','%d','%d']);

        if ($insert_result) {
            error_log("PTI Scheduled Email: Reminder event inserted for Order ID $order_id, Product ID $product_id at UTC ".$reminder_time_utc);
        } else {
            error_log("PTI Scheduled Email: FAILED to insert reminder event for Order ID $order_id, Product ID $product_id: " . $wpdb->last_error);
        }

        // Schedule Feedback Form Event
        $feedback_date = $last_date ?: $first_date;
        $feedback_time = date('Y-m-d H:i:s', strtotime("$feedback_date 4:45 PM"));
        $insert_result = $wpdb->insert($table_name, [
            'mailto'         => $customer_email,
            'subject' => 'Your thoughts matter! Share your feedback now',
            'content'        => json_encode(['eventName' => 'classroom_training', 'properties' => []]),
            'scheduled_time' => $feedback_time,
            'order_id'       => $order_id,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
        ]);
        if ($insert_result) {
            error_log("Scheduled Email Plugin: Feedback Email Inserted feedback event for Order ID $order_id, Product ID $product_id");
        } else {
            error_log("Scheduled Email Plugin: Feedback Email Failed to insert feedback event for Order ID $order_id, Product ID $product_id: " . $wpdb->last_error);
        }

        // Location based review email
        $review_properties = ["location" => $location];
        $review_subject = "Get a £10 Gift Card by Writing a Review";
        $review_time = date('Y-m-d H:i:s', strtotime("{$last_date} 5:00pm"));

        $insert_result = $wpdb->insert($table_name, [
            'mailto'         => $customer_email,
            'subject' => $review_subject,
            'content'        => json_encode(['eventName' =>  'google_map_review', 'properties' => $review_properties]),
            'scheduled_time' => $review_time,
            'order_id'       => $order_id,
            'product_id' => $product_id,
            'variation_id' => $variation_id,
        ]);
        if ($insert_result) {
            error_log("Scheduled Email Plugin: Location based review email Inserted review event for Order ID $order_id, Product ID $product_id");
        } else {
            error_log("Scheduled Email Plugin: Location based review email Failed to insert review event for Order ID $order_id, Product ID $product_id: " . $wpdb->last_error);
        }



        // Phlebotomy Training for theory part
        if (in_array($product_id, PHLEBOTOMY_TRAINING_OMNISEND) && !empty($first_date)) {
            $phle_time = date('Y-m-d H:i:s', strtotime("$first_date 4:45 PM -72 hours"));
            $insert_result = $wpdb->insert($table_name, [
                'mailto'         => $customer_email,
                'subject' => 'Phlebotomy Reminder Email for completing theory Part',
                'content'        => json_encode(['eventName' => 'phle_reminder_mail', 'properties' => []]),
                'scheduled_time' => $phle_time,
                'order_id'       => $order_id,
                'product_id' => $product_id,
                'variation_id' => $variation_id,
            ]);
            if ($insert_result) {
                error_log("Scheduled Email Plugin: Phlebotomy Training for Theory Inserted feedback event for Order ID $order_id, Product ID $product_id");
            } else {
                error_log("Scheduled Email Plugin: Phlebotomy Training for Theory Failed to insert feedback event for Order ID $order_id, Product ID $product_id: " . $wpdb->last_error);
            }
        }


    }

    if ($return) {
        wp_send_json_success(['message' => 'Event successfully scheduled.']);
    }
}

// --- Cron Job registration ---
add_action('init', 'pti_register_email_cron');
function pti_register_email_cron()
{
    if (!wp_next_scheduled('pti_send_scheduled_emails')) {
        // hourly is fine; you can run manually with WP Crontrol as needed
        wp_schedule_event(time(), 'hourly', 'pti_send_scheduled_emails');
    }
}

// --- Cron Job to Process Scheduled Events ---
add_action('pti_send_scheduled_emails', 'pti_process_scheduled_emails');
function pti_process_scheduled_emails()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'scheduled_emails';

    // ✅ Use PHP's UTC time (string) instead of DB NOW()
    $now_utc = current_time('mysql', true); // true => GMT/UTC

    $emails = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM $table_name
             WHERE sent_time IS NULL
               AND scheduled_time <= %s
             ORDER BY scheduled_time ASC
             LIMIT 500",
            $now_utc
        )
    );

    foreach ($emails as $email) {
        $payload = json_decode($email->content, true);

        // Valid Omnisend payload?
        if (json_last_error() === JSON_ERROR_NONE && isset($payload['eventName'])) {
            $data = [
                "contact"    => ["email" => $email->mailto],
                "origin"     => "api",
                "eventName"  => $payload['eventName'],
                "properties" => $payload['properties'] ?? [],
            ];

            $response = wp_remote_post("https://api.omnisend.com/v5/events", [
                'headers' => [
                    'X-API-KEY'    => PTI_OMNISEND_API_KEY,
                    'Content-Type' => 'application/json',
                ],
                'body'    => wp_json_encode($data),
                'timeout' => 20,
            ]);

            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) < 300) {
                // Mark sent with current UTC time
                $wpdb->update($table_name, ['sent_time' => current_time('mysql', true)], ['id' => $email->id], ['%s'], ['%d']);
                error_log("PTI Scheduled Email: ✅ Sent Omnisend event for Order ID " . $email->order_id . " (row {$email->id})");
            } else {
                $error_message = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response);
                error_log("PTI Scheduled Email: ❌ FAILED to send Omnisend event for Order ID {$email->order_id} (row {$email->id}). Response: " . $error_message);
                // do NOT set sent_time; leave for retry, unless you prefer to mark failures:
                // $wpdb->update($table_name, ['sent_time' => 'Failed - API'], ['id' => $email->id]);
            }
        } else {
            error_log("PTI Scheduled Email: Skipped entry ID {$email->id}. Invalid payload.");
            $wpdb->update($table_name, ['sent_time' => 'Failed - Invalid Payload'], ['id' => $email->id], ['%s'], ['%d']);
        }
    }

    error_log('✅ pti_send_scheduled_emails hook fired at (UTC): ' . current_time('mysql', true));
}

// --- Admin Interface & AJAX Handlers ---

add_action('admin_menu', function () {
    $hook = add_menu_page('Scheduled Email', 'Scheduled Email', 'manage_options', 'scheduled-emails', 'view_scheduled_emails', 'dashicons-email-alt', 24);
    add_action("load-$hook", function () {
        add_screen_option('per_page', ['label' => 'Emails per page', 'default' => 15, 'option' => 'emails_per_page']);
    });
});

add_filter('set-screen-option', function ($status, $option, $value) {
    return ($option === 'emails_per_page') ? (int) $value : $status;
}, 10, 3);

add_action('admin_enqueue_scripts', function () {
    $screen = get_current_screen();
    if ($screen && $screen->id === 'toplevel_page_scheduled-emails') {
        wp_enqueue_script('scheduled-emails-js', plugin_dir_url(__FILE__) . 'scripts.js', ['jquery'], time(), true);
        wp_enqueue_style('scheduled-emails-styles', plugins_url('styles.css', __FILE__), '', time());
        wp_localize_script('scheduled-emails-js', 'scheduledAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('update_content_nonce'),
            // send site tz offset (minutes) for any future use
            'tzOffsetMin' => (int) (get_option('gmt_offset') * 60),
        ]);
    }
});

function view_scheduled_emails()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'scheduled_emails';

    if (!empty($_GET['delete_email']) && is_numeric($_GET['delete_email'])) {
        $email_id = intval($_GET['delete_email']);
        $wpdb->update($table_name, ['sent_time' => 'Bin'], ['id' => $email_id], ['%s'], ['%d']);
        echo "<div class='notice notice-success is-dismissible'><p>Item moved to 'Bin' folder.</p></div>";
    }

    if (isset($_POST['delete']) && $_POST['delete'] == 1 && !empty($_POST['variation'])) {
        list($variation_id) = explode('~', sanitize_text_field($_POST['variation']));
        $rows_affected = $wpdb->delete($table_name, ['variation_id' => (int)$variation_id], ['%d']);
        echo '<div class="notice notice-error is-dismissible"><h3>🚨 Items Deleted!</h3><p><strong>' . esc_html($rows_affected) . '</strong> items deleted for Variation ID: <strong>' . esc_html($variation_id) . '</strong></p></div>';
    }

    if (isset($_POST['change']) && $_POST['change'] == 1 && !empty($_POST['variation']) && !empty($_POST['newDate'])) {
        $new_date = sanitize_text_field($_POST['newDate']);
        list($variation_id, $old_date) = array_map('sanitize_text_field', explode('~', $_POST['variation']));
        $schedules = $wpdb->get_results($wpdb->prepare("SELECT id, scheduled_time FROM $table_name WHERE variation_id = %d AND (sent_time IS NULL OR sent_time = '')", (int)$variation_id));
        if (!empty($schedules) && strtotime($old_date) !== false) {
            $date_diff = strtotime($new_date) - strtotime($old_date);
            $updated_count = 0;
            foreach ($schedules as $schedule) {
                $new_timestamp = strtotime($schedule->scheduled_time . ' UTC') + $date_diff;
                $new_scheduled_time = gmdate('Y-m-d H:i:s', $new_timestamp);
                if ($wpdb->update($table_name, ['scheduled_time' => $new_scheduled_time, 'sent_time' => null], ['id' => $schedule->id], ['%s','%s'], ['%d'])) {
                    $updated_count++;
                }
            }
            echo '<div class="notice notice-success is-dismissible"><h3>✅ Dates Successfully Changed!</h3><p><strong>' . esc_html($updated_count) . '</strong> unsent items updated for Variation ID: <strong>' . esc_html($variation_id) . '</strong></p></div>';
        } else {
            echo '<div class="notice notice-warning is-dismissible"><h3>No unsent schedules found for Variation ID: ' . esc_html($variation_id) . '.</h3></div>';
        }
    }

    $per_page = get_user_meta(get_current_user_id(), 'emails_per_page', true) ?: 15;
    $current_page = max(1, intval($_GET['paged'] ?? 1));
    $offset = ($current_page - 1) * $per_page;
    $filter_query = sanitize_text_field($_GET['r'] ?? '');
    $search_query = sanitize_text_field($_GET['s'] ?? '');
    $where_conditions = [];

    if (empty($filter_query)) $where_conditions[] = "(sent_time IS NULL OR (sent_time IS NOT NULL AND sent_time NOT IN ('Bin', 'Deleted')))";
    elseif ($filter_query === 'sent') $where_conditions[] = "(sent_time IS NOT NULL AND sent_time NOT IN ('Bin', 'Deleted'))";
    elseif ($filter_query === 'schedule') $where_conditions[] = "(sent_time IS NULL)";
    else $where_conditions[] = $wpdb->prepare("sent_time = %s", $filter_query);

    if (!empty($search_query)) {
        $like = '%' . $wpdb->esc_like($search_query) . '%';
        $where_conditions[] = $wpdb->prepare("(mailto LIKE %s OR subject LIKE %s OR order_id LIKE %s OR product_id LIKE %s OR variation_id LIKE %s)", $like, $like, $like, $like, $like);
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
    $total_items = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name $where_sql");
    $emails = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name $where_sql ORDER BY scheduled_time ASC LIMIT %d OFFSET %d", $per_page, $offset));
    $pagination_links = paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $current_page, 'total' => max(1, ceil($total_items / $per_page)), 'prev_text' => '&laquo;', 'next_text' => '&raquo;']);

    echo "<div class='wrap'><h1>Scheduled Emails & Events</h1>";
    ?>
    <form method="post" style="display:flex; flex-wrap: wrap; margin-bottom: 1em;">
        <div style="padding:5px;">
            <select name="product" id="course">
                <option value="">--Select Product--</option>
                <?php foreach (FACE_2_FACE_PRODUCT_CODES as $id):
                    $product = wc_get_product($id);
                    if (!$product) continue;
                    $selected = (isset($_POST['product']) && $id == (int)$_POST['product']) ? 'selected' : '';
                    echo "<option {$selected} value='{$id}'>" . esc_html($id . ' ~ ' . $product->get_name()) . "</option>";
                endforeach; ?>
            </select>
        </div>
        <div style="padding:5px;">
            <select name="variation" id="date">
                <option>--Select Date--</option>
                <?php foreach (FACE_2_FACE_PRODUCT_CODES as $id):
                    $meta_id = post_id_by_product_id($id);
                    $meta_group = get_post_meta($meta_id, 'phuk_ph_course_vars', true);
                    $product = wc_get_product($id);
                    if (!$product || !is_array($meta_group)) continue;

                    usort($meta_group, function($a, $b) {
                        return strtotime($a['adv_course_date'] ?? '1970-01-01') - strtotime($b['adv_course_date'] ?? '1970-01-01');
                    });

                    foreach ($meta_group as $variation):
                        if (empty($variation['phuk_course_var_id']) || empty($variation['adv_course_date'])) continue;

                        $val = $variation['phuk_course_var_id'] . '~' . $variation['adv_course_date'];
                        $is_selected_product = isset($_POST['product']) && (int)$_POST['product'] === (int)$id;
                        $display = $is_selected_product ? '' : 'style="display:none;"';
                        echo "<option {$display} data-course='{$id}' value='" . esc_attr($val) . "'>" . esc_html($val) . "</option>";
                    endforeach;
                endforeach; ?>
            </select>
        </div>
        <div style="padding:5px;">
            <button name="delete" value="1" class="button button-primary" type="submit" onclick="return confirm('Are you sure?')">Delete</button>
            <input type="date" name="newDate" />
            <button name="change" value="1" class="button button-primary" type="submit" onclick="return confirm('Are you sure?')">Change</button>
        </div>
    </form>
    <?php
    echo '<div class="search-filter">';
    echo '<ul class="subsubsub">';
    $statuses = ['All' => '', 'Sent' => 'sent', 'Scheduled' => 'schedule', 'Bin' => 'Bin'];
    foreach ($statuses as $label => $status) {
        if ($status === '') $count_where = "(sent_time IS NULL OR (sent_time IS NOT NULL AND sent_time NOT IN ('Bin', 'Deleted')))";
        elseif ($status === 'sent') $count_where = "(sent_time IS NOT NULL AND sent_time NOT IN ('Bin', 'Deleted'))";
        elseif ($status === 'schedule') $count_where = "(sent_time IS NULL)";
        else $count_where = $wpdb->prepare("sent_time = %s", $status);
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE $count_where");
        $class = ($filter_query === $status) ? 'class="current"' : '';
        echo "<li><a href='" . esc_url(add_query_arg('r', $status)) . "' $class>$label ($count)</a></li> | ";
    }
    echo '</ul>';
    echo '<button type="button" id="replaceContent" class="button button-primary">Replace</button>';
    echo '<button type="button" id="addByOrderId" class="button button-primary">+OrderID</button>';
    echo '<button type="button" id="openModalBtn" class="button button-primary">Add</button>';
    echo '<form method="GET" style="display:inline-block; margin-left:1em;">
            <input type="hidden" name="page" value="scheduled-emails" />
            <input type="text" name="s" value="' . esc_attr($search_query) . '" placeholder="Search..." />
            <button type="submit" class="button">Search</button>
          </form>';
    echo '</div>';

    // Helper: Format UTC datetime to site TZ for display
    $site_tz = wp_timezone();
    $utc     = new DateTimeZone('UTC');

    echo "<table class='wp-list-table widefat fixed striped'><thead><tr><th>ID</th><th>Email</th><th style='width:40%;'>Msg/Payload</th><th>Scheduled (UTC)</th><th>Order</th><th>Product</th><th>Variation</th><th>Status</th><th>Action</th></tr></thead><tbody>";
    if ($emails) {
        foreach ($emails as $email) {
            $escaped_content = esc_attr(htmlspecialchars($email->content, ENT_QUOTES, 'UTF-8'));

            // UTC
            $scheduled_utc = esc_html($email->scheduled_time);



            echo "<tr>
                <td>{$email->id}</td>
                <td class='editable' data-name='mailto' data-id='{$email->id}'>" . esc_html($email->mailto) . "</td>
                <td class='editable' data-subject='" . esc_attr($email->subject) . "' data-content='{$escaped_content}' data-name='edit_content' data-id='{$email->id}' style='width:40%;'>" . esc_html($email->subject) . "<br>" . wp_kses_post($email->content) . "</td>
                <td class='editable' data-name='scheduled_time' data-id='{$email->id}' data-content='" . esc_attr($email->scheduled_time) . "'>{$scheduled_utc}</td>
                <td><a href='" . esc_url( admin_url('post.php?post='.(int)$email->order_id.'&action=edit') ) . "'>" . (int)$email->order_id . "</a></td>
                <td>" . (int)$email->product_id . "</td>
                <td>" . (int)$email->variation_id . "</td>
                <td>" . esc_html($email->sent_time) . "</td>
                 <td>" . ($filter_query === 'Bin' ? "<button class='button button-small permanent-delete' data-id='" . (int)$email->id . "' onclick='return confirm(\"Permanently delete this email?\")'>🗑️ Permanently Delete</button>" : "<a href='" . esc_url(add_query_arg('delete_email', (int)$email->id)) . "' class='button button-small' onclick='return confirm(\"Move this to Bin?\")'>🗑️</a>") . "</td>
            </tr>";
        }
    } else {
        echo "<tr><td colspan='10'>No items found.</td></tr>";
    }
    echo "</tbody></table>";

    if ($pagination_links) {
        echo "<div class='custom-pagination'>";
        echo "<div class='pagination-info'>Showing page {$current_page} of " . max(1, ceil($total_items / $per_page)) . " ({$total_items} total items)</div>";
        echo "<div class='pagination-controls'>{$pagination_links}</div>";
        echo "</div>";
    }
    echo "</div>";
}

// --- AJAX: Replace content in bulk ---
add_action('wp_ajax_replace_email_content', 'handle_replace_email_content');
function handle_replace_email_content()
{
    check_ajax_referer('update_content_nonce', 'security');
    global $wpdb;
    $table_name = $wpdb->prefix . 'scheduled_emails';

    $data = isset($_POST['info']) ? json_decode(stripslashes($_POST['info']), true) : null;
    if (!$data) wp_send_json_error(['message' => 'Invalid JSON data.']);

    $new_content = wp_kses_post($data['content_replace'] ?? '');
    $old_content = wp_kses_post($data['content_find'] ?? '');
    $query_value = sanitize_text_field($data['query'] ?? '');

    if ($new_content === '' || $old_content === '' || $query_value === '') {
        wp_send_json_error(['message' => 'Missing required fields.']);
    }

    $updated_rows = $wpdb->query($wpdb->prepare(
        "UPDATE $table_name
         SET content = REPLACE(content, %s, %s)
         WHERE order_id = %s OR product_id = %s OR variation_id = %s",
        $old_content, $new_content, $query_value, $query_value, $query_value
    ));

    if ($updated_rows !== false) wp_send_json_success(['message' => 'Content replaced successfully', 'updated_rows' => (int)$updated_rows]);
    else wp_send_json_error(['message' => 'Failed to replace content.']);
}

// --- AJAX: Add by order id (reschedule from purchase logic) ---
add_action('wp_ajax_add_email_by_order_id', 'add_email_by_order_id');
function add_email_by_order_id()
{
    check_ajax_referer('update_content_nonce', 'security');
    schedule_events_on_purchase(absint($_POST['order_id']), true);
}

// --- AJAX: Add new manual email item ---
add_action('wp_ajax_add_email_content', 'handle_add_email_content');
function handle_add_email_content()
{
    check_ajax_referer('update_content_nonce', 'security');
    global $wpdb;
    $table_name = $wpdb->prefix . 'scheduled_emails';

    $data = isset($_POST['info']) ? json_decode(stripslashes($_POST['info']), true) : null;
    if (!$data) wp_send_json_error(['message' => 'Invalid JSON data.']);

    $subject  = sanitize_text_field($data['subject'] ?? '');
    $content  = wp_kses_post($data['content'] ?? '');
    $email_in = sanitize_text_field($data['email'] ?? '');
    $order_id = absint($data['order_id'] ?? 0);
    $product_id = absint($data['product_id'] ?? 0);
    $variation_id = absint($data['variation_id'] ?? 0);
    $sent_time_raw = sanitize_text_field($data['sent_time'] ?? ''); // 'Y-m-d\TH:i'

    if ($subject === '' || $email_in === '' || $sent_time_raw === '') {
        wp_send_json_error(['message' => 'Missing required fields.']);
    }

    // Parse list of emails
    $email_array = array_filter(array_map('trim', explode(',', $email_in)));
    if (empty($email_array)) {
        wp_send_json_error(['message' => 'No valid emails provided.']);
    }

    // Convert site-local datetime to UTC for storage
    $site_tz = wp_timezone();
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $sent_time_raw, $site_tz);
    if (!$dt) wp_send_json_error(['message' => 'Invalid date format for sent_time.']);
    $dt->setTimezone(new DateTimeZone('UTC'));
    $scheduled_time_utc = $dt->format('Y-m-d H:i:s');

    $inserted_count = 0;
    foreach ($email_array as $email) {
        if (!is_email($email)) continue;

        $insert = $wpdb->insert($table_name, [
            'mailto'         => sanitize_email($email),
            'subject'        => $subject,
            'content'        => $content,
            'order_id'       => $order_id ?: null,
            'product_id'     => $product_id ?: null,
            'variation_id'   => $variation_id ?: null,
            'scheduled_time' => $scheduled_time_utc, // UTC
        ], ['%s','%s','%s','%d','%d','%d','%s']);

        if ($insert) $inserted_count++;
    }

    if ($inserted_count > 0) wp_send_json_success(['message' => "$inserted_count email(s) added successfully."]);
    else wp_send_json_error(['message' => 'No valid emails were added.']);
}

// --- AJAX: Permanent delete ---
add_action('wp_ajax_permanent_delete_email', 'handle_permanent_delete_email');
function handle_permanent_delete_email()
{
    check_ajax_referer('update_content_nonce', 'security');
    global $wpdb;
    $table_name = $wpdb->prefix . 'scheduled_emails';

    $id = absint($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(['message' => 'Invalid ID.']);

    $deleted = $wpdb->delete($table_name, ['id' => $id], ['%d']);
    if ($deleted) {
        wp_send_json_success(['message' => 'Email permanently deleted.']);
    } else {
        wp_send_json_error(['message' => 'Failed to delete email.']);
    }
}

// --- AJAX: Inline update (mailto, content/subject, scheduled_time) ---
add_action('wp_ajax_update_content', 'handle_update_email_content');
function handle_update_email_content()
{
    check_ajax_referer('update_content_nonce', 'security');
    global $wpdb;
    $table_name = $wpdb->prefix . 'scheduled_emails';

    $id       = absint($_POST['id'] ?? 0);
    $name     = sanitize_text_field($_POST['name_info'] ?? '');
    $content  = isset($_POST['content']) ? stripslashes($_POST['content']) : '';
    $subject  = isset($_POST['subject_info']) ? sanitize_text_field($_POST['subject_info']) : '';

    if (!$id || $name === '') wp_send_json_error(['message' => 'Invalid parameters.']);

    $update_data = [];
    $format = [];

    if ($name === 'scheduled_time') {
        // Input from <input type="datetime-local"> is site-local; convert to UTC
        $date_raw = sanitize_text_field($content); // 'Y-m-d\TH:i'
        $site_tz  = wp_timezone();
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $date_raw, $site_tz);
        if (!$dt) wp_send_json_error(['message' => 'Invalid date format.']);
        $dt->setTimezone(new DateTimeZone('UTC'));
        $update_data = ['scheduled_time' => $dt->format('Y-m-d H:i:s'), 'sent_time' => null];
        $format = ['%s', '%s'];

    } elseif ($name === 'edit_content') {
        $update_data = ['content' => wp_kses_post($content), 'subject' => $subject];
        $format = ['%s', '%s'];

    } elseif ($name === 'mailto') {
        if (!is_email($content)) wp_send_json_error(['message' => 'Invalid email address.']);
        $update_data = ['mailto' => sanitize_email($content)];
        $format = ['%s'];
    }

    if (!empty($update_data) && $wpdb->update($table_name, $update_data, ['id' => $id], $format, ['%d']) !== false) {
        if ($name === 'edit_content') {
            $content_preview = esc_html($subject) . '<br>' . wp_kses_post($content);
            wp_send_json_success(['message' => 'Update successful.', 'content_preview' => $content_preview]);
        } else {
            wp_send_json_success(['message' => 'Update successful.']);
        }
    }
    wp_send_json_error(['message' => 'Update failed or no changes made.']);
}
