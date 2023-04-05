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
 * This script lists all the instances of bayesian in a particular course
 *
 * @package    mod_bayesian
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once("../../config.php");
require_once("locallib.php");

$id = required_param('id', PARAM_INT);
$PAGE->set_url('/mod/bayesian/index.php', array('id'=>$id));
if (!$course = $DB->get_record('course', array('id' => $id))) {
    throw new \moodle_exception('invalidcourseid');
}
$coursecontext = context_course::instance($id);
require_login($course);
$PAGE->set_pagelayout('incourse');

$params = array(
    'context' => $coursecontext
);
$event = \mod_bayesian\event\course_module_instance_list_viewed::create($params);
$event->trigger();

// Print the header.
$strbayesianzes = get_string("modulenameplural", "bayesian");
$PAGE->navbar->add($strbayesianzes);
$PAGE->set_title($strbayesianzes);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
echo $OUTPUT->heading($strbayesianzes, 2);

// Get all the appropriate data.
if (!$bayesianzes = get_all_instances_in_course("bayesian", $course)) {
    notice(get_string('thereareno', 'moodle', $strbayesianzes), "../../course/view.php?id=$course->id");
    die;
}

// Check if we need the feedback header.
$showfeedback = false;
foreach ($bayesianzes as $bayesian) {
    if (bayesian_has_feedback($bayesian)) {
        $showfeedback=true;
    }
    if ($showfeedback) {
        break;
    }
}

// Configure table for displaying the list of instances.
$headings = array(get_string('name'));
$align = array('left');

array_push($headings, get_string('bayesiancloses', 'bayesian'));
array_push($align, 'left');

if (course_format_uses_sections($course->format)) {
    array_unshift($headings, get_string('sectionname', 'format_'.$course->format));
} else {
    array_unshift($headings, '');
}
array_unshift($align, 'center');

$showing = '';

if (has_capability('mod/bayesian:viewreports', $coursecontext)) {
    array_push($headings, get_string('attempts', 'bayesian'));
    array_push($align, 'left');
    $showing = 'stats';

} else if (has_any_capability(array('mod/bayesian:reviewmyattempts', 'mod/bayesian:attempt'),
        $coursecontext)) {
    array_push($headings, get_string('grade', 'bayesian'));
    array_push($align, 'left');
    if ($showfeedback) {
        array_push($headings, get_string('feedback', 'bayesian'));
        array_push($align, 'left');
    }
    $showing = 'grades';

    $grades = $DB->get_records_sql_menu('
            SELECT qg.bayesian, qg.grade
            FROM {bayesian_grades} qg
            JOIN {bayesian} q ON q.id = qg.bayesian
            WHERE q.course = ? AND qg.userid = ?',
            array($course->id, $USER->id));
}

$table = new html_table();
$table->head = $headings;
$table->align = $align;

// Populate the table with the list of instances.
$currentsection = '';
// Get all closing dates.
$timeclosedates = bayesian_get_user_timeclose($course->id);
foreach ($bayesianzes as $bayesian) {
    $cm = get_coursemodule_from_instance('bayesian', $bayesian->id);
    $context = context_module::instance($cm->id);
    $data = array();

    // Section number if necessary.
    $strsection = '';
    if ($bayesian->section != $currentsection) {
        if ($bayesian->section) {
            $strsection = $bayesian->section;
            $strsection = get_section_name($course, $bayesian->section);
        }
        if ($currentsection !== "") {
            $table->data[] = 'hr';
        }
        $currentsection = $bayesian->section;
    }
    $data[] = $strsection;

    // Link to the instance.
    $class = '';
    if (!$bayesian->visible) {
        $class = ' class="dimmed"';
    }
    $data[] = "<a$class href=\"view.php?id=$bayesian->coursemodule\">" .
            format_string($bayesian->name, true) . '</a>';

    // Close date.
    if (($timeclosedates[$bayesian->id]->usertimeclose != 0)) {
        $data[] = userdate($timeclosedates[$bayesian->id]->usertimeclose);
    } else {
        $data[] = get_string('noclose', 'bayesian');
    }

    if ($showing == 'stats') {
        // The $bayesian objects returned by get_all_instances_in_course have the necessary $cm
        // fields set to make the following call work.
        $data[] = bayesian_attempt_summary_link_to_reports($bayesian, $cm, $context);

    } else if ($showing == 'grades') {
        // Grade and feedback.
        $attempts = bayesian_get_user_attempts($bayesian->id, $USER->id, 'all');
        list($someoptions, $alloptions) = bayesian_get_combined_reviewoptions(
                $bayesian, $attempts);

        $grade = '';
        $feedback = '';
        if ($bayesian->grade && array_key_exists($bayesian->id, $grades)) {
            if ($alloptions->marks >= question_display_options::MARK_AND_MAX) {
                $a = new stdClass();
                $a->grade = bayesian_format_grade($bayesian, $grades[$bayesian->id]);
                $a->maxgrade = bayesian_format_grade($bayesian, $bayesian->grade);
                $grade = get_string('outofshort', 'bayesian', $a);
            }
            if ($alloptions->overallfeedback) {
                $feedback = bayesian_feedback_for_grade($grades[$bayesian->id], $bayesian, $context);
            }
        }
        $data[] = $grade;
        if ($showfeedback) {
            $data[] = $feedback;
        }
    }

    $table->data[] = $data;
} // End of loop over bayesian instances.

// Display the table.
echo html_writer::table($table);

// Finish the page.
echo $OUTPUT->footer();
