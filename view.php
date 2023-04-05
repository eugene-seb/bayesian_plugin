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
 * This page is the entry page into the bayesian UI. Displays information about the
 * bayesian to students and teachers, and lets students see their previous attempts.
 *
 * @package   mod_bayesian
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/mod/bayesian/locallib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/course/format/lib.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID, or ...
$q = optional_param('q',  0, PARAM_INT);  // bayesian ID.

if ($id) {
    if (!$cm = get_coursemodule_from_id('bayesian', $id)) {
        throw new \moodle_exception('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
        throw new \moodle_exception('coursemisconf');
    }
} else {
    if (!$bayesian = $DB->get_record('bayesian', array('id' => $q))) {
        throw new \moodle_exception('invalidbayesianid', 'bayesian');
    }
    if (!$course = $DB->get_record('course', array('id' => $bayesian->course))) {
        throw new \moodle_exception('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("bayesian", $bayesian->id, $course->id)) {
        throw new \moodle_exception('invalidcoursemodule');
    }
}

// Check login and get context.
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/bayesian:view', $context);

// Cache some other capabilities we use several times.
$canattempt = has_capability('mod/bayesian:attempt', $context);
$canreviewmine = has_capability('mod/bayesian:reviewmyattempts', $context);
$canpreview = has_capability('mod/bayesian:preview', $context);

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$bayesianobj = bayesian::create($cm->instance, $USER->id);
$accessmanager = new bayesian_access_manager($bayesianobj, $timenow,
        has_capability('mod/bayesian:ignoretimelimits', $context, null, false));
$bayesian = $bayesianobj->get_bayesian();

// Trigger course_module_viewed event and completion.
bayesian_view($bayesian, $course, $cm, $context);

// Initialize $PAGE, compute blocks.
$PAGE->set_url('/mod/bayesian/view.php', array('id' => $cm->id));

// Create view object which collects all the information the renderer will need.
$viewobj = new mod_bayesian_view_object();
$viewobj->accessmanager = $accessmanager;
$viewobj->canreviewmine = $canreviewmine || $canpreview;

// Get this user's attempts.
$attempts = bayesian_get_user_attempts($bayesian->id, $USER->id, 'finished', true);
$lastfinishedattempt = end($attempts);
$unfinished = false;
$unfinishedattemptid = null;
if ($unfinishedattempt = bayesian_get_user_attempt_unfinished($bayesian->id, $USER->id)) {
    $attempts[] = $unfinishedattempt;

    // If the attempt is now overdue, deal with that - and pass isonline = false.
    // We want the student notified in this case.
    $bayesianobj->create_attempt_object($unfinishedattempt)->handle_if_time_expired(time(), false);

    $unfinished = $unfinishedattempt->state == bayesian_attempt::IN_PROGRESS ||
            $unfinishedattempt->state == bayesian_attempt::OVERDUE;
    if (!$unfinished) {
        $lastfinishedattempt = $unfinishedattempt;
    }
    $unfinishedattemptid = $unfinishedattempt->id;
    $unfinishedattempt = null; // To make it clear we do not use this again.
}
$numattempts = count($attempts);

$viewobj->attempts = $attempts;
$viewobj->attemptobjs = array();
foreach ($attempts as $attempt) {
    $viewobj->attemptobjs[] = new bayesian_attempt($attempt, $bayesian, $cm, $course, false);
}

// Work out the final grade, checking whether it was overridden in the gradebook.
if (!$canpreview) {
    $mygrade = bayesian_get_best_grade($bayesian, $USER->id);
} else if ($lastfinishedattempt) {
    // Users who can preview the bayesian don't get a proper grade, so work out a
    // plausible value to display instead, so the page looks right.
    $mygrade = bayesian_rescale_grade($lastfinishedattempt->sumgrades, $bayesian, false);
} else {
    $mygrade = null;
}

$mygradeoverridden = false;
$gradebookfeedback = '';

$item = null;

$grading_info = grade_get_grades($course->id, 'mod', 'bayesian', $bayesian->id, $USER->id);
if (!empty($grading_info->items)) {
    $item = $grading_info->items[0];
    if (isset($item->grades[$USER->id])) {
        $grade = $item->grades[$USER->id];

        if ($grade->overridden) {
            $mygrade = $grade->grade + 0; // Convert to number.
            $mygradeoverridden = true;
        }
        if (!empty($grade->str_feedback)) {
            $gradebookfeedback = $grade->str_feedback;
        }
    }
}

$title = $course->shortname . ': ' . format_string($bayesian->name);
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
if (html_is_blank($bayesian->intro)) {
    $PAGE->activityheader->set_description('');
}
$PAGE->add_body_class('limitedwidth');
/** @var mod_bayesian_renderer $output */
$output = $PAGE->get_renderer('mod_bayesian');

// Print table with existing attempts.
if ($attempts) {
    // Work out which columns we need, taking account what data is available in each attempt.
    list($someoptions, $alloptions) = bayesian_get_combined_reviewoptions($bayesian, $attempts);

    $viewobj->attemptcolumn  = $bayesian->attempts != 1;

    $viewobj->gradecolumn    = $someoptions->marks >= question_display_options::MARK_AND_MAX &&
            bayesian_has_grades($bayesian);
    $viewobj->markcolumn     = $viewobj->gradecolumn && ($bayesian->grade != $bayesian->sumgrades);
    $viewobj->overallstats   = $lastfinishedattempt && $alloptions->marks >= question_display_options::MARK_AND_MAX;

    $viewobj->feedbackcolumn = bayesian_has_feedback($bayesian) && $alloptions->overallfeedback;
}

$viewobj->timenow = $timenow;
$viewobj->numattempts = $numattempts;
$viewobj->mygrade = $mygrade;
$viewobj->moreattempts = $unfinished ||
        !$accessmanager->is_finished($numattempts, $lastfinishedattempt);
$viewobj->mygradeoverridden = $mygradeoverridden;
$viewobj->gradebookfeedback = $gradebookfeedback;
$viewobj->lastfinishedattempt = $lastfinishedattempt;
$viewobj->canedit = has_capability('mod/bayesian:manage', $context);
$viewobj->editurl = new moodle_url('/mod/bayesian/edit.php', array('cmid' => $cm->id));
$viewobj->backtocourseurl = new moodle_url('/course/view.php', array('id' => $course->id));
$viewobj->startattempturl = $bayesianobj->start_attempt_url();

if ($accessmanager->is_preflight_check_required($unfinishedattemptid)) {
    $viewobj->preflightcheckform = $accessmanager->get_preflight_check_form(
            $viewobj->startattempturl, $unfinishedattemptid);
}
$viewobj->popuprequired = $accessmanager->attempt_must_be_in_popup();
$viewobj->popupoptions = $accessmanager->get_popup_options();

// Display information about this bayesian.
$viewobj->infomessages = $viewobj->accessmanager->describe_rules();
if ($bayesian->attempts != 1) {
    $viewobj->infomessages[] = get_string('gradingmethod', 'bayesian',
            bayesian_get_grading_option_name($bayesian->grademethod));
}

// Inform user of the grade to pass if non-zero.
if ($item && grade_floats_different($item->gradepass, 0)) {
    $a = new stdClass();
    $a->grade = bayesian_format_grade($bayesian, $item->gradepass);
    $a->maxgrade = bayesian_format_grade($bayesian, $bayesian->grade);
    $viewobj->infomessages[] = get_string('gradetopassoutof', 'bayesian', $a);
}

// Determine wheter a start attempt button should be displayed.
$viewobj->bayesianhasquestions = $bayesianobj->has_questions();
$viewobj->preventmessages = array();
if (!$viewobj->bayesianhasquestions) {
    $viewobj->buttontext = '';

} else {
    if ($unfinished) {
        if ($canpreview) {
            $viewobj->buttontext = get_string('continuepreview', 'bayesian');
        } else if ($canattempt) {
            $viewobj->buttontext = get_string('continueattemptbayesian', 'bayesian');
        }
    } else {
        if ($canpreview) {
            $viewobj->buttontext = get_string('previewbayesianstart', 'bayesian');
        } else if ($canattempt) {
            $viewobj->preventmessages = $viewobj->accessmanager->prevent_new_attempt(
                    $viewobj->numattempts, $viewobj->lastfinishedattempt);
            if ($viewobj->preventmessages) {
                $viewobj->buttontext = '';
            } else if ($viewobj->numattempts == 0) {
                $viewobj->buttontext = get_string('attemptbayesian', 'bayesian');
            } else {
                $viewobj->buttontext = get_string('reattemptbayesian', 'bayesian');
            }
        }
    }

    // Users who can preview the bayesian should be able to see all messages for not being able to access the bayesian.
    if ($canpreview) {
        $viewobj->preventmessages = $viewobj->accessmanager->prevent_access();
    } else if ($viewobj->buttontext) {
        // If, so far, we think a button should be printed, so check if they will be allowed to access it.
        if (!$viewobj->moreattempts) {
            $viewobj->buttontext = '';
        } else if ($canattempt) {
            $viewobj->preventmessages = $viewobj->accessmanager->prevent_access();
            if ($viewobj->preventmessages) {
                $viewobj->buttontext = '';
            }
        }
    }
}

$viewobj->showbacktocourse = ($viewobj->buttontext === '' &&
        course_get_format($course)->has_view_page());

echo $OUTPUT->header();

if (isguestuser()) {
    // Guests can't do a bayesian, so offer them a choice of logging in or going back.
    echo $output->view_page_guest($course, $bayesian, $cm, $context, $viewobj->infomessages, $viewobj);
} else if (!isguestuser() && !($canattempt || $canpreview
          || $viewobj->canreviewmine)) {
    // If they are not enrolled in this course in a good enough role, tell them to enrol.
    echo $output->view_page_notenrolled($course, $bayesian, $cm, $context, $viewobj->infomessages, $viewobj);
} else {
    echo $output->view_page($course, $bayesian, $cm, $context, $viewobj);
}

echo $OUTPUT->footer();
