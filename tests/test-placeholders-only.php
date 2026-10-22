<?php
/**
 * Smoke test for the "Hide labels, show placeholders only" form setting
 * (the $placeholders_only argument of alchemy_forms_render_field_markup()).
 *
 * Not a real WordPress bootstrap — stubs the handful of escaping/helper
 * functions render.php's field renderer calls, pulls the one real helper it
 * depends on (alchemy_forms_placeholder_eligible_types) straight out of
 * alchemy-forms.php so the test can't drift from the shipped list, then
 * renders real markup and inspects it.
 *
 * Run with: php tests/test-placeholders-only.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('ABSPATH', __DIR__ . '/');

function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html__($s, $d = null) { return htmlspecialchars($s, ENT_QUOTES); }
function esc_html_e($s, $d = null) { echo htmlspecialchars($s, ENT_QUOTES); }
function wp_kses_post($s) { return $s; }
function checked($a, $b = true, $echo = true) { $r = ($a == $b) ? 'checked="checked"' : ''; if ($echo) echo $r; return $r; }
function selected($a, $b = true, $echo = true) { $r = ($a == $b) ? 'selected="selected"' : ''; if ($echo) echo $r; return $r; }
function add_shortcode() {}
function add_action() {}
function alchemy_forms_evaluate_condition() { return true; }

// The real list of placeholder-capable types, lifted from the plugin itself.
$main = file_get_contents(__DIR__ . '/../alchemy-forms.php');
if (!preg_match('/function alchemy_forms_placeholder_eligible_types\(\)\s*\{.*?\n\}/s', $main, $m)) {
    fwrite(STDERR, "FAIL: could not find alchemy_forms_placeholder_eligible_types() in alchemy-forms.php\n");
    exit(1);
}
eval($m[0]);

require __DIR__ . '/../includes/render.php';

function render($field, $placeholders_only) {
    $field += ['name' => 'waf_0_x', 'width' => 'full'];
    ob_start();
    alchemy_forms_render_field_markup($field, 7, [], [], $placeholders_only);
    return ob_get_clean();
}

$failures = 0;
function check($label, $cond) {
    global $failures;
    echo ($cond ? 'PASS' : 'FAIL') . " — $label\n";
    if (!$cond) $failures++;
}
function label_hidden($html) { return (bool) preg_match('/<label[^>]*class="wa-visually-hidden"/', $html); }

echo "== Setting OFF: existing behaviour unchanged ==\n";
$html = render(['type' => 'text', 'label' => 'Full Name', 'required' => 1, 'placeholder' => 'Jane Doe'], false);
check('label stays visible', !label_hidden($html));
check('custom placeholder untouched', strpos($html, 'placeholder="Jane Doe"') !== false);
$html = render(['type' => 'text', 'label' => 'Full Name'], false);
check('no placeholder invented when none set', strpos($html, 'placeholder=') === false);
$html = render(['type' => 'select', 'label' => 'Topic', 'options' => ['A', 'B']], false);
check('select keeps "— Select —" first option', strpos($html, '— Select —') !== false);
$html = render(['type' => 'text', 'label' => 'Full Name', 'hide_label' => 1], false);
check('per-field hide_label still works on its own', label_hidden($html));

echo "\n== Setting ON ==\n";
$html = render(['type' => 'text', 'label' => 'Full Name', 'required' => 1, 'placeholder' => 'Jane Doe'], true);
check('label hidden visually (still in DOM for screen readers)', label_hidden($html) && strpos($html, '<label') !== false);
check('custom placeholder kept exactly as set', strpos($html, 'placeholder="Jane Doe"') !== false);

$html = render(['type' => 'email', 'label' => 'Email', 'required' => 1], true);
check('no placeholder set -> label text used, with * for required', strpos($html, 'placeholder="Email *"') !== false);

$html = render(['type' => 'tel', 'label' => 'Phone', 'required' => 0], true);
check('optional field falls back to plain label text', strpos($html, 'placeholder="Phone"') !== false);

$html = render(['type' => 'textarea', 'label' => 'Message', 'required' => 1], true);
check('textarea gets the label as placeholder too', strpos($html, '<textarea') !== false && strpos($html, 'placeholder="Message *"') !== false && label_hidden($html));

$html = render(['type' => 'select', 'label' => 'Topic', 'required' => 1, 'options' => ['A', 'B']], true);
check('select shows its label as the first option', strpos($html, '<option value="">Topic *</option>') !== false);
check('select no longer shows "— Select —"', strpos($html, '— Select —') === false);
check('select label hidden', label_hidden($html));
check('select real options untouched', strpos($html, '<option value="A"') !== false && strpos($html, '<option value="B"') !== false);

$html = render(['type' => 'radio', 'label' => 'Pick one', 'options' => ['X', 'Y']], true);
check('radio group keeps its visible legend (no placeholder possible)', strpos($html, '<legend>') !== false && strpos($html, 'wa-visually-hidden') === false);

$html = render(['type' => 'checkbox', 'label' => 'Pick many', 'options' => ['X', 'Y']], true);
check('checkbox group keeps its visible legend', strpos($html, '<legend>') !== false && strpos($html, 'wa-visually-hidden') === false);

$html = render(['type' => 'date', 'label' => 'Birthday'], true);
check('date field keeps its visible label (browsers ignore placeholder on date)', !label_hidden($html) && strpos($html, 'placeholder=') === false);

$html = render(['type' => 'file', 'label' => 'Certificate'], true);
check('file field keeps its visible label', !label_hidden($html));

$html = render(['type' => 'checkbox_single', 'label' => 'I agree'], true);
check('single checkbox label (it IS the option text) stays visible', strpos($html, 'I agree') !== false && strpos($html, 'wa-visually-hidden') === false);

$html = render(['type' => 'text', 'label' => 'Tricky "quote" <b>', 'required' => 0], true);
check('fallback placeholder is escaped', strpos($html, 'placeholder="Tricky &quot;quote&quot; &lt;b&gt;"') !== false);

echo "\n" . ($failures === 0 ? "ALL CHECKS PASSED" : "$failures CHECK(S) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
