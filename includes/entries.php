<?php
if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * Table
 * ---------------------------------------------------------------------- */
function alchemy_forms_entries_table() {
    global $wpdb;
    return $wpdb->prefix . 'wa_form_entries';
}

function alchemy_forms_create_entries_table() {
    global $wpdb;
    $table   = alchemy_forms_entries_table();
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        form_id BIGINT UNSIGNED NOT NULL,
        submitted_at DATETIME NOT NULL,
        data LONGTEXT NOT NULL,
        PRIMARY KEY  (id),
        KEY form_id (form_id)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/* -------------------------------------------------------------------------
 * Save / query helpers
 * ---------------------------------------------------------------------- */
function alchemy_forms_save_entry($form_id, array $data) {
    global $wpdb;
    $wpdb->insert(alchemy_forms_entries_table(), [
        'form_id'      => (int) $form_id,
        'submitted_at' => current_time('mysql'),
        'data'         => wp_json_encode($data),
    ], ['%d', '%s', '%s']);
    return $wpdb->insert_id;
}

function alchemy_forms_count_entries($form_id = 0) {
    global $wpdb;
    $table = alchemy_forms_entries_table();
    if ($form_id) {
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $form_id));
    }
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
}

/* -------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------- */
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=wa_form',
        __('Entries', 'alchemy-forms'),
        __('Entries', 'alchemy-forms'),
        'manage_options',
        'wa-form-entries',
        'alchemy_forms_entries_page'
    );
});

function alchemy_forms_entries_page() {
    if (!current_user_can('manage_options')) wp_die(esc_html__('You do not have permission to view entries.', 'alchemy-forms'));

    // Single entry view
    if (isset($_GET['entry'])) {
        alchemy_forms_render_single_entry((int) $_GET['entry']);
        return;
    }

    global $wpdb;
    $table    = alchemy_forms_entries_table();
    $form_id  = isset($_GET['form_id']) ? (int) $_GET['form_id'] : 0;
    $paged    = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
    $per_page = 20;
    $offset   = ($paged - 1) * $per_page;

    $forms = get_posts(['post_type' => 'wa_form', 'numberposts' => -1, 'post_status' => 'any', 'orderby' => 'title', 'order' => 'ASC']);

    if ($form_id) {
        $total   = alchemy_forms_count_entries($form_id);
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
            $form_id, $per_page, $offset
        ));
    } else {
        $total   = alchemy_forms_count_entries();
        $entries = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY submitted_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
    }
    $pages = max(1, (int) ceil($total / $per_page));

    $export_url = wp_nonce_url(
        admin_url('admin-post.php?action=alchemy_forms_export' . ($form_id ? '&form_id=' . $form_id : '')),
        'alchemy_forms_export'
    );
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline"><?php esc_html_e('Form Entries', 'alchemy-forms'); ?></h1>
        <?php if ($total > 0) : ?>
            <a href="<?php echo esc_url($export_url); ?>" class="page-title-action"><?php esc_html_e('Export CSV', 'alchemy-forms'); ?></a>
        <?php endif; ?>
        <hr class="wp-header-end">

        <?php if (isset($_GET['deleted'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Entry deleted.', 'alchemy-forms'); ?></p></div>
        <?php endif; ?>

        <form method="get" style="margin: 12px 0;">
            <input type="hidden" name="post_type" value="wa_form">
            <input type="hidden" name="page" value="wa-form-entries">
            <select name="form_id">
                <option value="0"><?php esc_html_e('All forms', 'alchemy-forms'); ?></option>
                <?php foreach ($forms as $form) : ?>
                    <option value="<?php echo (int) $form->ID; ?>" <?php selected($form_id, $form->ID); ?>><?php echo esc_html($form->post_title); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button"><?php esc_html_e('Filter', 'alchemy-forms'); ?></button>
        </form>

        <?php if (empty($entries)) : ?>
            <p><?php esc_html_e('No entries yet. Once a form is submitted on the site, it will show up here.', 'alchemy-forms'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'alchemy-forms'); ?></th>
                        <th><?php esc_html_e('Form', 'alchemy-forms'); ?></th>
                        <th><?php esc_html_e('Preview', 'alchemy-forms'); ?></th>
                        <th style="width:120px;"><?php esc_html_e('Actions', 'alchemy-forms'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry) :
                    $data    = json_decode($entry->data, true);
                    if (!is_array($data)) $data = [];
                    $preview = implode(' · ', array_slice(array_filter(array_map('strval', array_values($data))), 0, 3));
                    $view    = admin_url('edit.php?post_type=wa_form&page=wa-form-entries&entry=' . (int) $entry->id);
                    $delete  = wp_nonce_url(
                        admin_url('admin-post.php?action=alchemy_forms_delete_entry&entry=' . (int) $entry->id . ($form_id ? '&form_id=' . $form_id : '')),
                        'alchemy_forms_delete_' . (int) $entry->id
                    );
                ?>
                    <tr>
                        <td><?php echo esc_html(mysql2date('j M Y, g:ia', $entry->submitted_at)); ?></td>
                        <td><?php echo esc_html(get_the_title((int) $entry->form_id) ?: '#' . (int) $entry->form_id); ?></td>
                        <td><?php echo esc_html(wp_html_excerpt($preview, 90, '…')); ?></td>
                        <td>
                            <a href="<?php echo esc_url($view); ?>"><?php esc_html_e('View', 'alchemy-forms'); ?></a> |
                            <a href="<?php echo esc_url($delete); ?>" style="color:#b32d2e;" onclick="return confirm('<?php echo esc_js(__('Delete this entry? This cannot be undone.', 'alchemy-forms')); ?>');"><?php esc_html_e('Delete', 'alchemy-forms'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($pages > 1) : ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php
                    echo wp_kses_post(paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $paged,
                        'total'   => $pages,
                    ]));
                    ?>
                </div></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

function alchemy_forms_render_single_entry($entry_id) {
    global $wpdb;
    $table = alchemy_forms_entries_table();
    $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $entry_id));
    $back  = admin_url('edit.php?post_type=wa_form&page=wa-form-entries');

    if (!$entry) {
        echo '<div class="wrap"><h1>' . esc_html__('Entry not found', 'alchemy-forms') . '</h1><p><a href="' . esc_url($back) . '">&larr; ' . esc_html__('Back to entries', 'alchemy-forms') . '</a></p></div>';
        return;
    }
    $data = json_decode($entry->data, true);
    if (!is_array($data)) $data = [];
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_the_title((int) $entry->form_id) ?: __('Entry', 'alchemy-forms')); ?> — <?php esc_html_e('Entry', 'alchemy-forms'); ?> #<?php echo (int) $entry->id; ?></h1>
        <p><a href="<?php echo esc_url($back); ?>">&larr; <?php esc_html_e('Back to entries', 'alchemy-forms'); ?></a></p>
        <table class="widefat striped" style="max-width:800px;">
            <tbody>
                <tr><th style="width:260px;"><?php esc_html_e('Submitted', 'alchemy-forms'); ?></th><td><?php echo esc_html(mysql2date('j M Y, g:ia', $entry->submitted_at)); ?></td></tr>
                <?php foreach ($data as $label => $value) : ?>
                    <tr>
                        <th><?php echo esc_html($label); ?></th>
                        <td>
                            <?php if (is_string($value) && preg_match('#^https?://#', $value)) : ?>
                                <a href="<?php echo esc_url($value); ?>" target="_blank" rel="noopener"><?php echo esc_html($value); ?></a>
                            <?php else : ?>
                                <?php echo nl2br(esc_html((string) $value)); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/* -------------------------------------------------------------------------
 * Delete entry
 * ---------------------------------------------------------------------- */
add_action('admin_post_alchemy_forms_delete_entry', function () {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Not allowed.', 'alchemy-forms'));
    $entry_id = isset($_GET['entry']) ? (int) $_GET['entry'] : 0;
    check_admin_referer('alchemy_forms_delete_' . $entry_id);

    global $wpdb;
    $wpdb->delete(alchemy_forms_entries_table(), ['id' => $entry_id], ['%d']);

    $redirect = admin_url('edit.php?post_type=wa_form&page=wa-form-entries&deleted=1');
    if (!empty($_GET['form_id'])) $redirect .= '&form_id=' . (int) $_GET['form_id'];
    wp_safe_redirect($redirect);
    exit;
});

/* -------------------------------------------------------------------------
 * CSV export
 * ---------------------------------------------------------------------- */
add_action('admin_post_alchemy_forms_export', function () {
    if (!current_user_can('manage_options')) wp_die(esc_html__('Not allowed.', 'alchemy-forms'));
    check_admin_referer('alchemy_forms_export');

    global $wpdb;
    $table   = alchemy_forms_entries_table();
    $form_id = isset($_GET['form_id']) ? (int) $_GET['form_id'] : 0;

    if ($form_id) {
        $entries = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC", $form_id));
    } else {
        $entries = $wpdb->get_results("SELECT * FROM {$table} ORDER BY submitted_at DESC");
    }

    // Build the header row from the union of all labels across exported entries,
    // so renamed/removed fields from older entries still export cleanly.
    $labels = [];
    $rows   = [];
    foreach ($entries as $entry) {
        $data = json_decode($entry->data, true);
        if (!is_array($data)) $data = [];
        foreach (array_keys($data) as $label) {
            if (!in_array($label, $labels, true)) $labels[] = $label;
        }
        $rows[] = ['submitted_at' => $entry->submitted_at, 'form_id' => $entry->form_id, 'data' => $data];
    }

    $filename = 'wa-form-entries-' . ($form_id ? $form_id . '-' : '') . gmdate('Y-m-d') . '.csv';
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $out = fopen('php://output', 'w');
    // UTF-8 BOM so Excel opens accented characters correctly.
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['Submitted', 'Form'], $labels));
    foreach ($rows as $row) {
        $line = [$row['submitted_at'], get_the_title((int) $row['form_id']) ?: '#' . (int) $row['form_id']];
        foreach ($labels as $label) {
            $val = isset($row['data'][$label]) ? (string) $row['data'][$label] : '';
            // Guard against CSV formula injection when opened in Excel.
            if ($val !== '' && in_array($val[0], ['=', '+', '-', '@'], true)) {
                $val = "'" . $val;
            }
            $line[] = $val;
        }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
});
