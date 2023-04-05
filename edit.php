<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.


/**
 * Page to edit bayesianzes
 *
 * This page generally has two columns:
 * The right column lists all available questions in a chosen category and
 * allows them to be edited or more to be added. This column is only there if
 * the bayesian does not already have student attempts
 * The left column lists all questions that have been added to the current bayesian.
 * The lecturer can add questions from the right hand list to the bayesian or remove them
 *
 * The script also processes a number of actions:
 * Actions affecting a bayesian:
 * up and down  Changes the order of questions and page breaks
 * addquestion  Adds a single question to the bayesian
 * add          Adds several selected questions to the bayesian
 * addrandom    Adds a certain number of random questions to the bayesian
 * repaginate   Re-paginates the bayesian
 * delete       Removes a question from the bayesian
 * savechanges  Saves the order and grades for questions in the bayesian
 *
 * @package    mod_bayesian
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/bayesian/locallib.php');
require_once($CFG->dirroot . '/mod/bayesian/addrandomform.php');
require_once($CFG->dirroot . '/question/editlib.php');

// These params are only passed from page request to request while we stay on
// this page otherwise they would go in question_edit_setup.
$scrollpos = optional_param('scrollpos', '', PARAM_INT);

list($thispageurl, $contexts, $cmid, $cm, $bayesian, $pagevars) =
        question_edit_setup('editq', '/mod/bayesian/edit.php', true);

$defaultcategoryobj = question_make_default_categories($contexts->all());
$defaultcategory = $defaultcategoryobj->id . ',' . $defaultcategoryobj->contextid;

$bayesianhasattempts = bayesian_has_attempts($bayesian->id);

$PAGE->set_url($thispageurl);
$PAGE->set_secondary_active_tab("mod_bayesian_edit");

// Get the course object and related bits.
$course = $DB->get_record('course', array('id' => $bayesian->course), '*', MUST_EXIST);
$bayesianobj = new bayesian($bayesian, $cm, $course);
$structure = $bayesianobj->get_structure();

// You need mod/bayesian:manage in addition to question capabilities to access this page.
require_capability('mod/bayesian:manage', $contexts->lowest());

// Process commands ============================================================.

// Get the list of question ids had their check-boxes ticked.
$selectedslots = array();
$params = (array) data_submitted();
foreach ($params as $key => $value) {
    if (preg_match('!^s([0-9]+)$!', $key, $matches)) {
        $selectedslots[] = $matches[1];
    }
}

$afteractionurl = new moodle_url($thispageurl);
if ($scrollpos) {
    $afteractionurl->param('scrollpos', $scrollpos);
}

if (optional_param('repaginate', false, PARAM_BOOL) && confirm_sesskey()) {
    // Re-paginate the bayesian.
    $structure->check_can_be_edited();
    $questionsperpage = optional_param('questionsperpage', $bayesian->questionsperpage, PARAM_INT);
    bayesian_repaginate_questions($bayesian->id, $questionsperpage );
    bayesian_delete_previews($bayesian);
    redirect($afteractionurl);
}

if (($addquestion = optional_param('addquestion', 0, PARAM_INT)) && confirm_sesskey()) {
    // Add a single question to the current bayesian.
    $structure->check_can_be_edited();
    bayesian_require_question_use($addquestion);
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    bayesian_add_bayesian_question($addquestion, $bayesian, $addonpage);
    bayesian_delete_previews($bayesian);
    bayesian_update_sumgrades($bayesian);
    $thispageurl->param('lastchanged', $addquestion);
    redirect($afteractionurl);
}

if (optional_param('add', false, PARAM_BOOL) && confirm_sesskey()) {
    $structure->check_can_be_edited();
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    // Add selected questions to the current bayesian.
    $rawdata = (array) data_submitted();
    foreach ($rawdata as $key => $value) { // Parse input for question ids.
        if (preg_match('!^q([0-9]+)$!', $key, $matches)) {
            $key = $matches[1];
            bayesian_require_question_use($key);
            bayesian_add_bayesian_question($key, $bayesian, $addonpage);
        }
    }
    bayesian_delete_previews($bayesian);
    bayesian_update_sumgrades($bayesian);
    redirect($afteractionurl);
}

if ($addsectionatpage = optional_param('addsectionatpage', false, PARAM_INT)) {
    // Add a section to the bayesian.
    $structure->check_can_be_edited();
    $structure->add_section_heading($addsectionatpage);
    bayesian_delete_previews($bayesian);
    redirect($afteractionurl);
}

if ((optional_param('addrandom', false, PARAM_BOOL)) && confirm_sesskey()) {
    // Add random questions to the bayesian.
    $structure->check_can_be_edited();
    $recurse = optional_param('recurse', 0, PARAM_BOOL);
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    $categoryid = required_param('categoryid', PARAM_INT);
    $randomcount = required_param('randomcount', PARAM_INT);
    bayesian_add_random_questions($bayesian, $addonpage, $categoryid, $randomcount, $recurse);

    bayesian_delete_previews($bayesian);
    bayesian_update_sumgrades($bayesian);
    redirect($afteractionurl);
}

if (optional_param('savechanges', false, PARAM_BOOL) && confirm_sesskey()) {

    // If rescaling is required save the new maximum.
    $maxgrade = unformat_float(optional_param('maxgrade', '', PARAM_RAW_TRIMMED), true);
    if (is_float($maxgrade) && $maxgrade >= 0) {
        bayesian_set_grade($maxgrade, $bayesian);
        bayesian_update_all_final_grades($bayesian);
        bayesian_update_grades($bayesian, 0, true);
    }

    redirect($afteractionurl);
}

// Log this visit.
$event = \mod_bayesian\event\edit_page_viewed::create([
    'courseid' => $course->id,
    'context' => $contexts->lowest(),
    'other' => [
        'bayesianid' => $bayesian->id
    ]
]);
$event->trigger();

// Get the question bank view.
$questionbank = new mod_bayesian\question\bank\custom_view($contexts, $thispageurl, $course, $cm, $bayesian);
$questionbank->set_bayesian_has_attempts($bayesianhasattempts);

// End of process commands =====================================================.

$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_pagetype('mod-bayesian-edit');

$output = $PAGE->get_renderer('mod_bayesian', 'edit');

$PAGE->set_title(get_string('editingbayesianx', 'bayesian', format_string($bayesian->name)));
$PAGE->set_heading($course->fullname);
$PAGE->activityheader->disable();
$node = $PAGE->settingsnav->find('mod_bayesian_edit', navigation_node::TYPE_SETTING);
if ($node) {
    $node->make_active();
}
echo $OUTPUT->header();

// Initialise the JavaScript.
$bayesianeditconfig = new stdClass();
$bayesianeditconfig->url = $thispageurl->out(true, array('qbanktool' => '0'));
$bayesianeditconfig->dialoglisteners = array();
$numberoflisteners = $DB->get_field_sql("
    SELECT COALESCE(MAX(page), 1)
      FROM {bayesian_slots}
     WHERE bayesianid = ?", array($bayesian->id));

for ($pageiter = 1; $pageiter <= $numberoflisteners; $pageiter++) {
    $bayesianeditconfig->dialoglisteners[] = 'addrandomdialoglaunch_' . $pageiter;
}

$PAGE->requires->data_for_js('bayesian_edit_config', $bayesianeditconfig);
$PAGE->requires->js('/question/qengine.js');

// Questions wrapper start.
echo html_writer::start_tag('div', array('class' => 'mod-bayesian-edit-content'));

echo $output->edit_page($bayesianobj, $structure, $contexts, $thispageurl, $pagevars);

// Questions wrapper end.
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
