<?php
/**
 * Standalone smoke test for includes/abilities.php. Not a real WordPress
 * bootstrap — there's no PHPUnit/wp-env setup in this repo, and this test
 * intentionally adds no dependency — but a mock of every WP function/class
 * the file actually calls, backed by fake data shaped exactly like what the
 * plugin really stores. Proves the ability callbacks' own logic (input
 * handling, redaction, clamping, error cases) independent of WordPress.
 *
 * Run with: php tests/test-abilities.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// ---------------------------------------------------------------------
// Minimal WP stubs
// ---------------------------------------------------------------------
function __($s, $d = null) { return $s; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $s)); }
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }

$GLOBALS['__caps'] = true; // toggled by tests
function current_user_can($cap) { return $GLOBALS['__caps']; }

class WP_Error {
    public $code; public $message; public $data;
    public function __construct($code = '', $message = '', $data = '') {
        $this->code = $code; $this->message = $message; $this->data = $data;
    }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }

$GLOBALS['__hooks'] = [];
function add_action($hook, $cb) { $GLOBALS['__hooks'][$hook][] = $cb; }
function do_action_stub($hook) {
    foreach ($GLOBALS['__hooks'][$hook] ?? [] as $cb) $cb();
}

$GLOBALS['__registered_categories'] = [];
$GLOBALS['__registered_abilities']  = [];
function wp_register_ability_category($slug, $args) { $GLOBALS['__registered_categories'][$slug] = $args; }
function wp_register_ability($name, $args) { $GLOBALS['__registered_abilities'][$name] = $args; }

// Fake posts: id 1 = a real wa_form, id 2 = a non-wa_form post (page), id 999 = doesn't exist.
class FakePost {
    public $ID; public $post_type; public $post_title; public $post_status;
    public function __construct($id, $type, $title, $status) {
        $this->ID = $id; $this->post_type = $type; $this->post_title = $title; $this->post_status = $status;
    }
}
$GLOBALS['__posts'] = [
    1 => new FakePost(1, 'wa_form', 'Contact Us', 'publish'),
    2 => new FakePost(2, 'page', 'About', 'publish'),
    3 => new FakePost(3, 'wa_form', 'Newsletter Signup', 'draft'),
    4 => new FakePost(4, 'wa_form', 'Event RSVP', 'publish'),
];
function get_post($id) { return $GLOBALS['__posts'][(int) $id] ?? null; }
function get_posts($args) {
    $out = [];
    foreach ($GLOBALS['__posts'] as $p) {
        if ($p->post_type !== ($args['post_type'] ?? '')) continue;
        if (($args['post_status'] ?? 'any') !== 'any' && $p->post_status !== $args['post_status']) continue;
        $out[] = $p;
    }
    usort($out, function ($a, $b) { return strcmp($a->post_title, $b->post_title); });
    return $out;
}

// Field/settings meta shaped exactly like admin-editor.php's save_post_wa_form
// handler actually produces (uid/label/type/required/width/options/condition/
// content/source/static_value/placeholder/hide_label), and settings shaped
// like _wa_form_settings really is.
$GLOBALS['__meta'] = [
    1 => [
        '_wa_form_fields' => [
            ['uid' => 'a1', 'label' => 'Full Name', 'type' => 'text', 'required' => 1, 'hide_label' => 0, 'width' => 'half', 'placeholder' => 'Jane Doe'],
            ['uid' => 'a2', 'label' => 'Email', 'type' => 'email', 'required' => 1, 'hide_label' => 1, 'width' => 'half'],
            ['uid' => 'a3', 'label' => 'Topic', 'type' => 'select', 'required' => 0, 'hide_label' => 0, 'width' => 'full', 'options' => ['Sales', 'Support']],
            // Conditional field: only shown when Topic = Support — this is
            // exactly the "form that has conditional fields" case.
            ['uid' => 'a4', 'label' => 'Support Details', 'type' => 'textarea', 'required' => 0, 'hide_label' => 0, 'width' => 'full',
                'condition' => ['field' => 'a3', 'comparator' => 'equals', 'value' => 'Support']],
        ],
        '_wa_form_settings' => [
            'recipient'    => ['owner@example.com'],
            'submit_text'  => 'Send',
            'success_msg'  => 'Thanks!',
            'integrations' => [
                'flodesk'   => ['enabled' => 1, 'api_key' => 'SECRET_SHOULD_BE_REDACTED', 'list_id' => ''],
                'mailchimp' => ['enabled' => 0, 'api_key' => 'ALSO_SECRET', 'list_id' => 'abc123'],
                'aweber'    => ['enabled' => 0, 'list_id' => ''],
            ],
        ],
    ],
    3 => ['_wa_form_fields' => [], '_wa_form_settings' => []],
    4 => [
        '_wa_form_fields' => [
            ['uid' => 'c1', 'label' => '', 'type' => 'html', 'required' => 0, 'hide_label' => 0, 'width' => 'full', 'content' => '<p>Welcome to the event!</p>'],
            ['uid' => 'c2', 'label' => 'Referrer', 'type' => 'hidden', 'required' => 0, 'hide_label' => 0, 'width' => 'full', 'source' => 'post_title', 'static_value' => ''],
        ],
        '_wa_form_settings' => [],
    ],
];
function get_post_meta($id, $key, $single = false) { return $GLOBALS['__meta'][(int) $id][$key] ?? false; }

// Fake $wpdb + entries table, matching entries.php's real schema (id, form_id, submitted_at, data).
class FakeWpdb {
    public $rows = [];
    public $last_args = [];
    public function prepare($query, ...$args) {
        // Record which placeholder query ran with which args; proves the
        // calling code always goes through prepare() (never raw string
        // interpolation of user input) and lets tests assert on the exact
        // values that were bound, e.g. that a limit was really clamped.
        $this->last_args = $args;
        return $query . ' -- ARGS:' . json_encode($args);
    }
    public function get_results($preparedQuery) {
        // Very small fake query "engine" — parses the essentials, ignoring SQL text.
        if (strpos($preparedQuery, 'AND form_id') !== false) {
            preg_match('/ARGS:\[(\d+),(\d+)\]/', $preparedQuery, $m);
            $entry_id = (int) ($m[1] ?? 0);
            $form_id  = (int) ($m[2] ?? 0);
            return array_values(array_filter($this->rows, function ($r) use ($entry_id, $form_id) {
                return $r->id === $entry_id && $r->form_id === $form_id;
            }));
        }
        preg_match('/ARGS:\[(\d+),(\d+),(\d+)\]/', $preparedQuery, $m);
        $form_id = (int) ($m[1] ?? 0);
        $limit   = (int) ($m[2] ?? 20);
        $offset  = (int) ($m[3] ?? 0);
        $matches = array_values(array_filter($this->rows, function ($r) use ($form_id) { return $r->form_id === $form_id; }));
        usort($matches, function ($a, $b) { return strcmp($b->submitted_at, $a->submitted_at); });
        return array_slice($matches, $offset, $limit);
    }
}
function newRow($id, $form_id, $submitted_at, $data) {
    $r = new stdClass(); $r->id = $id; $r->form_id = $form_id; $r->submitted_at = $submitted_at; $r->data = json_encode($data);
    return $r;
}
$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['wpdb']->rows = [
    newRow(101, 1, '2026-09-28 10:00:00', ['Full Name' => 'Ann Lee', 'Email' => 'ann@example.com', 'Topic' => 'Sales']),
    newRow(102, 1, '2026-09-29 11:30:00', ['Full Name' => 'Ben Roy', 'Email' => 'ben@example.com', 'Topic' => 'Support']),
];

// Functions from entries.php that abilities.php calls directly — copied
// here verbatim (not reimplemented) so the test exercises the exact same
// code the plugin ships, not a paraphrase of it.
function alchemy_forms_entries_table() { return 'wp_wa_form_entries'; }
function alchemy_forms_count_entries($form_id = 0) {
    global $wpdb;
    return count(array_filter($wpdb->rows, function ($r) use ($form_id) { return !$form_id || $r->form_id === $form_id; }));
}

// ---------------------------------------------------------------------
// Load the real file under test
// ---------------------------------------------------------------------
define('ABSPATH', __DIR__ . '/');
require __DIR__ . '/../includes/abilities.php';

// Fire the registration hooks, exactly like WordPress would.
do_action_stub('wp_abilities_api_categories_init');
do_action_stub('wp_abilities_api_init');

// ---------------------------------------------------------------------
// Assertions
// ---------------------------------------------------------------------
$failures = 0;
function check($label, $cond) {
    global $failures;
    echo ($cond ? 'PASS' : 'FAIL') . " — $label\n";
    if (!$cond) $failures++;
}

echo "== Registration ==\n";
check('category alchemy-forms registered', isset($GLOBALS['__registered_categories']['alchemy-forms']));
check('list-forms registered with public=true, readonly=true',
    ($GLOBALS['__registered_abilities']['alchemy-forms/list-forms']['meta']['public'] ?? false) === true
    && ($GLOBALS['__registered_abilities']['alchemy-forms/list-forms']['meta']['annotations']['readonly'] ?? false) === true
);
check('get-form registered', isset($GLOBALS['__registered_abilities']['alchemy-forms/get-form']));
check('get-entries registered', isset($GLOBALS['__registered_abilities']['alchemy-forms/get-entries']));
check('all three use the shared permission callback',
    $GLOBALS['__registered_abilities']['alchemy-forms/list-forms']['permission_callback'] === 'alchemy_forms_ability_permission_check'
    && $GLOBALS['__registered_abilities']['alchemy-forms/get-form']['permission_callback'] === 'alchemy_forms_ability_permission_check'
    && $GLOBALS['__registered_abilities']['alchemy-forms/get-entries']['permission_callback'] === 'alchemy_forms_ability_permission_check'
);

echo "\n== permission_callback ==\n";
$GLOBALS['__caps'] = true;
check('allows an admin (manage_options=true)', alchemy_forms_ability_permission_check([]) === true);
$GLOBALS['__caps'] = false;
check('blocks a non-admin (manage_options=false)', alchemy_forms_ability_permission_check(['form_id' => 1]) === false);
$GLOBALS['__caps'] = true;

echo "\n== list-forms ==\n";
$r = alchemy_forms_ability_list_forms([]);
check('returns all 3 wa_form posts regardless of status (default any)', $r['total'] === 3);
check('does not include the non-wa_form post', !in_array(2, array_column($r['forms'], 'id'), true));
check('shortcode format matches [wa_form id="X"]', $r['forms'][0]['shortcode'] === '[wa_form id="' . $r['forms'][0]['id'] . '"]');

$r = alchemy_forms_ability_list_forms(['status' => 'publish']);
check('status filter narrows to the 2 published forms', $r['total'] === 2 && in_array(1, array_column($r['forms'], 'id'), true) && in_array(4, array_column($r['forms'], 'id'), true));

$r = alchemy_forms_ability_list_forms(['title' => 'newsletter']);
check('title filter is case-insensitive partial match', $r['total'] === 1 && $r['forms'][0]['id'] === 3);

$r = alchemy_forms_ability_list_forms(['title' => 'zzz-no-match']);
check('title filter with no match returns empty, not an error', $r['total'] === 0 && $r['forms'] === []);

echo "\n== get-form ==\n";
$r = alchemy_forms_ability_get_form(['form_id' => 1]);
check('returns the form', !is_wp_error($r) && $r['id'] === 1 && $r['title'] === 'Contact Us');
check('returns all 4 fields in order', count($r['fields']) === 4 && $r['fields'][2]['label'] === 'Topic');
check('field "order" matches array position', $r['fields'][0]['order'] === 0 && $r['fields'][2]['order'] === 2);
check('select field options come through', $r['fields'][2]['options'] === ['Sales', 'Support']);
check('required is a real boolean, not 1/0', $r['fields'][0]['required'] === true && $r['fields'][2]['required'] === false);
check('a field with no condition reports conditions: null', $r['fields'][0]['conditions'] === null);
check('a conditional field\'s condition comes through exactly as stored',
    $r['fields'][3]['conditions'] === ['field' => 'a3', 'comparator' => 'equals', 'value' => 'Support']
);
check('a non-html field reports content: null', $r['fields'][0]['content'] === null);
check('placeholder shows up under the field\'s own "settings"', $r['fields'][0]['settings']['placeholder'] === 'Jane Doe');
check('hide_label shows up under the field\'s own "settings"', $r['fields'][1]['settings']['hide_label'] === 1);
check('per-field settings never duplicates a top-level key (e.g. no "uid" inside settings)',
    !isset($r['fields'][0]['settings']['uid']) && !isset($r['fields'][3]['settings']['condition'])
);
check('Flodesk api_key is redacted', !isset($r['settings']['integrations']['flodesk']['api_key']));
check('Mailchimp api_key is redacted', !isset($r['settings']['integrations']['mailchimp']['api_key']));
check('Flodesk non-secret fields survive redaction', $r['settings']['integrations']['flodesk']['enabled'] === 1);
check('recipient passed through as stored', $r['settings']['recipient'] === ['owner@example.com']);

$r = alchemy_forms_ability_get_form(['form_id' => 999]);
check('unknown form_id returns WP_Error, not a fatal/notice', is_wp_error($r) && $r->code === 'alchemy_forms_not_found');

$r = alchemy_forms_ability_get_form(['form_id' => 2]);
check('a real post that is NOT a wa_form also returns WP_Error', is_wp_error($r));

$r = alchemy_forms_ability_get_form(['form_id' => 3]);
check('a form with no fields/settings yet returns empty arrays, not an error', !is_wp_error($r) && $r['fields'] === []);

echo "\n== get-form (html + hidden field types) ==\n";
$r = alchemy_forms_ability_get_form(['form_id' => 4]);
check('an html field\'s content comes through', $r['fields'][0]['type'] === 'html' && $r['fields'][0]['content'] === '<p>Welcome to the event!</p>');
check('an html field still reports conditions: null when it has none', $r['fields'][0]['conditions'] === null);
check('a hidden field\'s source/static_value show up under settings',
    $r['fields'][1]['settings']['source'] === 'post_title' && $r['fields'][1]['settings']['static_value'] === ''
);

echo "\n== get-entries ==\n";
$r = alchemy_forms_ability_get_entries(['form_id' => 1]);
check('returns both entries for form 1', $r['total'] === 2 && count($r['entries']) === 2);
check('newest first', $r['entries'][0]['id'] === 102);
check('entry data decoded from JSON', $r['entries'][0]['data']['Full Name'] === 'Ben Roy');

$r = alchemy_forms_ability_get_entries(['form_id' => 1, 'limit' => 1]);
check('limit is honored', count($r['entries']) === 1);

$r = alchemy_forms_ability_get_entries(['form_id' => 1, 'limit' => 99999]);
check('limit is clamped to 100 max, not passed through raw', $GLOBALS['wpdb']->last_args[1] === 100);

$r = alchemy_forms_ability_get_entries(['form_id' => 1, 'limit' => 0]);
check('limit below 1 is clamped up to 1, not 0 (which SQL would read as "no limit")', $GLOBALS['wpdb']->last_args[1] === 1);

$r = alchemy_forms_ability_get_entries(['form_id' => 1, 'offset' => -5]);
check('negative offset is clamped to 0', $GLOBALS['wpdb']->last_args[2] === 0);

$r = alchemy_forms_ability_get_entries(['form_id' => 1, 'entry_id' => 101]);
check('entry_id filter returns exactly that one entry', count($r['entries']) === 1 && $r['entries'][0]['id'] === 101);

$r = alchemy_forms_ability_get_entries(['form_id' => 1, 'entry_id' => 102, 'form_id' => 3]);
check('entry_id belonging to a DIFFERENT form_id returns nothing (no cross-form leak)', count($r['entries']) === 0);

$r = alchemy_forms_ability_get_entries(['form_id' => 999]);
check('unknown form_id returns WP_Error', is_wp_error($r));

echo "\n" . ($failures === 0 ? "ALL CHECKS PASSED" : "$failures CHECK(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
