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
 * Library of functions used by the bayesian module.
 *
 * This contains functions that are called from within the bayesian module only
 * Functions that are also called by core Moodle are in {@link lib.php}
 * This script also loads the code in {@link questionlib.php} which holds
 * the module-indpendent code for handling questions and which in turn
 * initialises all the questiontype classes.
 *
 * @package    mod_bayesian
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/bayesian/lib.php');
require_once($CFG->dirroot . '/mod/bayesian/accessmanager.php');
require_once($CFG->dirroot . '/mod/bayesian/accessmanager_form.php');
require_once($CFG->dirroot . '/mod/bayesian/renderer.php');
require_once($CFG->dirroot . '/mod/bayesian/attemptlib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/questionlib.php');

use mod_bayesian\question\bank\qbank_helper;

/**
 * @var int We show the countdown timer if there is less than this amount of time left before the
 * the bayesian close date. (1 hour)
 */
define('bayesian_SHOW_TIME_BEFORE_DEADLINE', '3600');

/**
 * @var int If there are fewer than this many seconds left when the student submits
 * a page of the bayesian, then do not take them to the next page of the bayesian. Instead
 * close the bayesian immediately.
 */
define('bayesian_MIN_TIME_TO_CONTINUE', '2');

/**
 * @var int We show no image when user selects No image from dropdown menu in bayesian settings.
 */
define('bayesian_SHOWIMAGE_NONE', 0);

/**
 * @var int We show small image when user selects small image from dropdown menu in bayesian settings.
 */
define('bayesian_SHOWIMAGE_SMALL', 1);

/**
 * @var int We show Large image when user selects Large image from dropdown menu in bayesian settings.
 */
define('bayesian_SHOWIMAGE_LARGE', 2);


// Functions related to attempts ///////////////////////////////////////////////

/**
 * Creates an object to represent a new attempt at a bayesian
 *
 * Creates an attempt object to represent an attempt at the bayesian by the current
 * user starting at the current time. The ->id field is not set. The object is
 * NOT written to the database.
 *
 * @param object $bayesianobj the bayesian object to create an attempt for.
 * @param int $attemptnumber the sequence number for the attempt.
 * @param stdClass|null $lastattempt the previous attempt by this user, if any. Only needed
 *         if $attemptnumber > 1 and $bayesian->attemptonlast is true.
 * @param int $timenow the time the attempt was started at.
 * @param bool $ispreview whether this new attempt is a preview.
 * @param int $userid  the id of the user attempting this bayesian.
 *
 * @return object the newly created attempt object.
 */
function bayesian_create_attempt(bayesian $bayesianobj, $attemptnumber, $lastattempt, $timenow, $ispreview = false, $userid = null) {
    global $USER;

    if ($userid === null) {
        $userid = $USER->id;
    }

    $bayesian = $bayesianobj->get_bayesian();
    if ($bayesian->sumgrades < 0.000005 && $bayesian->grade > 0.000005) {
        throw new moodle_exception('cannotstartgradesmismatch', 'bayesian',
                new moodle_url('/mod/bayesian/view.php', array('q' => $bayesian->id)),
                    array('grade' => bayesian_format_grade($bayesian, $bayesian->grade)));
    }

    if ($attemptnumber == 1 || !$bayesian->attemptonlast) {
        // We are not building on last attempt so create a new attempt.
        $attempt = new stdClass();
        $attempt->bayesian = $bayesian->id;
        $attempt->userid = $userid;
        $attempt->preview = 0;
        $attempt->layout = '';
    } else {
        // Build on last attempt.
        if (empty($lastattempt)) {
            throw new \moodle_exception('cannotfindprevattempt', 'bayesian');
        }
        $attempt = $lastattempt;
    }

    $attempt->attempt = $attemptnumber;
    $attempt->timestart = $timenow;
    $attempt->timefinish = 0;
    $attempt->timemodified = $timenow;
    $attempt->timemodifiedoffline = 0;
    $attempt->state = bayesian_attempt::IN_PROGRESS;
    $attempt->currentpage = 0;
    $attempt->sumgrades = null;
    $attempt->gradednotificationsenttime = null;

    // If this is a preview, mark it as such.
    if ($ispreview) {
        $attempt->preview = 1;
    }

    $timeclose = $bayesianobj->get_access_manager($timenow)->get_end_time($attempt);
    if ($timeclose === false || $ispreview) {
        $attempt->timecheckstate = null;
    } else {
        $attempt->timecheckstate = $timeclose;
    }

    return $attempt;
}
/**
 * Start a normal, new, bayesian attempt.
 *
 * @param bayesian      $bayesianobj            the bayesian object to start an attempt for.
 * @param question_usage_by_activity $quba
 * @param object    $attempt
 * @param integer   $attemptnumber      starting from 1
 * @param integer   $timenow            the attempt start time
 * @param array     $questionids        slot number => question id. Used for random questions, to force the choice
 *                                        of a particular actual question. Intended for testing purposes only.
 * @param array     $forcedvariantsbyslot slot number => variant. Used for questions with variants,
 *                                          to force the choice of a particular variant. Intended for testing
 *                                          purposes only.
 * @throws moodle_exception
 * @return object   modified attempt object
 */
function bayesian_start_new_attempt($bayesianobj, $quba, $attempt, $attemptnumber, $timenow,
                                $questionids = array(), $forcedvariantsbyslot = array()) {

    // Usages for this user's previous bayesian attempts.
    $qubaids = new \mod_bayesian\question\qubaids_for_users_attempts(
            $bayesianobj->get_bayesianid(), $attempt->userid);

    // Fully load all the questions in this bayesian.
    $bayesianobj->preload_questions();
    $bayesianobj->load_questions();

    // First load all the non-random questions.
    $randomfound = false;
    $slot = 0;
    $questions = array();
    $maxmark = array();
    $page = array();
    foreach ($bayesianobj->get_questions() as $questiondata) {
        $slot += 1;
        $maxmark[$slot] = $questiondata->maxmark;
        $page[$slot] = $questiondata->page;
        if ($questiondata->qtype == 'random') {
            $randomfound = true;
            continue;
        }
        if (!$bayesianobj->get_bayesian()->shuffleanswers) {
            $questiondata->options->shuffleanswers = false;
        }
        $questions[$slot] = question_bank::make_question($questiondata);
    }

    // Then find a question to go in place of each random question.
    if ($randomfound) {
        $slot = 0;
        $usedquestionids = array();
        foreach ($questions as $question) {
            if ($question->id && isset($usedquestions[$question->id])) {
                $usedquestionids[$question->id] += 1;
            } else {
                $usedquestionids[$question->id] = 1;
            }
        }
        $randomloader = new \core_question\local\bank\random_question_loader($qubaids, $usedquestionids);

        foreach ($bayesianobj->get_questions() as $questiondata) {
            $slot += 1;
            if ($questiondata->qtype != 'random') {
                continue;
            }

            $tagids = qbank_helper::get_tag_ids_for_slot($questiondata);

            // Deal with fixed random choices for testing.
            if (isset($questionids[$quba->next_slot_number()])) {
                if ($randomloader->is_question_available($questiondata->category,
                        (bool) $questiondata->questiontext, $questionids[$quba->next_slot_number()], $tagids)) {
                    $questions[$slot] = question_bank::load_question(
                            $questionids[$quba->next_slot_number()], $bayesianobj->get_bayesian()->shuffleanswers);
                    continue;
                } else {
                    throw new coding_exception('Forced question id not available.');
                }
            }

            // Normal case, pick one at random.
            $questionid = $randomloader->get_next_question_id($questiondata->category,
                    $questiondata->randomrecurse, $tagids);
            if ($questionid === null) {
                throw new moodle_exception('notenoughrandomquestions', 'bayesian',
                                           $bayesianobj->view_url(), $questiondata);
            }

            $questions[$slot] = question_bank::load_question($questionid,
                    $bayesianobj->get_bayesian()->shuffleanswers);
        }
    }

    // Finally add them all to the usage.
    ksort($questions);
    foreach ($questions as $slot => $question) {
        $newslot = $quba->add_question($question, $maxmark[$slot]);
        if ($newslot != $slot) {
            throw new coding_exception('Slot numbers have got confused.');
        }
    }

    // Start all the questions.
    $variantstrategy = new core_question\engine\variants\least_used_strategy($quba, $qubaids);

    if (!empty($forcedvariantsbyslot)) {
        $forcedvariantsbyseed = question_variant_forced_choices_selection_strategy::prepare_forced_choices_array(
            $forcedvariantsbyslot, $quba);
        $variantstrategy = new question_variant_forced_choices_selection_strategy(
            $forcedvariantsbyseed, $variantstrategy);
    }

    $quba->start_all_questions($variantstrategy, $timenow, $attempt->userid);

    // Work out the attempt layout.
    $sections = $bayesianobj->get_sections();
    foreach ($sections as $i => $section) {
        if (isset($sections[$i + 1])) {
            $sections[$i]->lastslot = $sections[$i + 1]->firstslot - 1;
        } else {
            $sections[$i]->lastslot = count($questions);
        }
    }

    $layout = array();
    foreach ($sections as $section) {
        if ($section->shufflequestions) {
            $questionsinthissection = array();
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                $questionsinthissection[] = $slot;
            }
            shuffle($questionsinthissection);
            $questionsonthispage = 0;
            foreach ($questionsinthissection as $slot) {
                if ($questionsonthispage && $questionsonthispage == $bayesianobj->get_bayesian()->questionsperpage) {
                    $layout[] = 0;
                    $questionsonthispage = 0;
                }
                $layout[] = $slot;
                $questionsonthispage += 1;
            }

        } else {
            $currentpage = $page[$section->firstslot];
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                if ($currentpage !== null && $page[$slot] != $currentpage) {
                    $layout[] = 0;
                }
                $layout[] = $slot;
                $currentpage = $page[$slot];
            }
        }

        // Each section ends with a page break.
        $layout[] = 0;
    }
    $attempt->layout = implode(',', $layout);

    return $attempt;
}

/**
 * Start a subsequent new attempt, in each attempt builds on last mode.
 *
 * @param question_usage_by_activity    $quba         this question usage
 * @param object                        $attempt      this attempt
 * @param object                        $lastattempt  last attempt
 * @return object                       modified attempt object
 *
 */
function bayesian_start_attempt_built_on_last($quba, $attempt, $lastattempt) {
    $oldquba = question_engine::load_questions_usage_by_activity($lastattempt->uniqueid);

    $oldnumberstonew = array();
    foreach ($oldquba->get_attempt_iterator() as $oldslot => $oldqa) {
        $newslot = $quba->add_question($oldqa->get_question(false), $oldqa->get_max_mark());

        $quba->start_question_based_on($newslot, $oldqa);

        $oldnumberstonew[$oldslot] = $newslot;
    }

    // Update attempt layout.
    $newlayout = array();
    foreach (explode(',', $lastattempt->layout) as $oldslot) {
        if ($oldslot != 0) {
            $newlayout[] = $oldnumberstonew[$oldslot];
        } else {
            $newlayout[] = 0;
        }
    }
    $attempt->layout = implode(',', $newlayout);
    return $attempt;
}

/**
 * The save started question usage and bayesian attempt in db and log the started attempt.
 *
 * @param bayesian                       $bayesianobj
 * @param question_usage_by_activity $quba
 * @param object                     $attempt
 * @return object                    attempt object with uniqueid and id set.
 */
function bayesian_attempt_save_started($bayesianobj, $quba, $attempt) {
    global $DB;
    // Save the attempt in the database.
    question_engine::save_questions_usage_by_activity($quba);
    $attempt->uniqueid = $quba->get_id();
    $attempt->id = $DB->insert_record('bayesian_attempts', $attempt);

    // Params used by the events below.
    $params = array(
        'objectid' => $attempt->id,
        'relateduserid' => $attempt->userid,
        'courseid' => $bayesianobj->get_courseid(),
        'context' => $bayesianobj->get_context()
    );
    // Decide which event we are using.
    if ($attempt->preview) {
        $params['other'] = array(
            'bayesianid' => $bayesianobj->get_bayesianid()
        );
        $event = \mod_bayesian\event\attempt_preview_started::create($params);
    } else {
        $event = \mod_bayesian\event\attempt_started::create($params);

    }

    // Trigger the event.
    $event->add_record_snapshot('bayesian', $bayesianobj->get_bayesian());
    $event->add_record_snapshot('bayesian_attempts', $attempt);
    $event->trigger();

    return $attempt;
}

/**
 * Returns an unfinished attempt (if there is one) for the given
 * user on the given bayesian. This function does not return preview attempts.
 *
 * @param int $bayesianid the id of the bayesian.
 * @param int $userid the id of the user.
 *
 * @return mixed the unfinished attempt if there is one, false if not.
 */
function bayesian_get_user_attempt_unfinished($bayesianid, $userid) {
    $attempts = bayesian_get_user_attempts($bayesianid, $userid, 'unfinished', true);
    if ($attempts) {
        return array_shift($attempts);
    } else {
        return false;
    }
}

/**
 * Delete a bayesian attempt.
 * @param mixed $attempt an integer attempt id or an attempt object
 *      (row of the bayesian_attempts table).
 * @param object $bayesian the bayesian object.
 */
function bayesian_delete_attempt($attempt, $bayesian) {
    global $DB;
    if (is_numeric($attempt)) {
        if (!$attempt = $DB->get_record('bayesian_attempts', array('id' => $attempt))) {
            return;
        }
    }

    if ($attempt->bayesian != $bayesian->id) {
        debugging("Trying to delete attempt $attempt->id which belongs to bayesian $attempt->bayesian " .
                "but was passed bayesian $bayesian->id.");
        return;
    }

    if (!isset($bayesian->cmid)) {
        $cm = get_coursemodule_from_instance('bayesian', $bayesian->id, $bayesian->course);
        $bayesian->cmid = $cm->id;
    }

    question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
    $DB->delete_records('bayesian_attempts', array('id' => $attempt->id));

    // Log the deletion of the attempt if not a preview.
    if (!$attempt->preview) {
        $params = array(
            'objectid' => $attempt->id,
            'relateduserid' => $attempt->userid,
            'context' => context_module::instance($bayesian->cmid),
            'other' => array(
                'bayesianid' => $bayesian->id
            )
        );
        $event = \mod_bayesian\event\attempt_deleted::create($params);
        $event->add_record_snapshot('bayesian_attempts', $attempt);
        $event->trigger();
    }

    // Search bayesian_attempts for other instances by this user.
    // If none, then delete record for this bayesian, this user from bayesian_grades
    // else recalculate best grade.
    $userid = $attempt->userid;
    if (!$DB->record_exists('bayesian_attempts', array('userid' => $userid, 'bayesian' => $bayesian->id))) {
        $DB->delete_records('bayesian_grades', array('userid' => $userid, 'bayesian' => $bayesian->id));
    } else {
        bayesian_save_best_grade($bayesian, $userid);
    }

    bayesian_update_grades($bayesian, $userid);
}

/**
 * Delete all the preview attempts at a bayesian, or possibly all the attempts belonging
 * to one user.
 * @param object $bayesian the bayesian object.
 * @param int $userid (optional) if given, only delete the previews belonging to this user.
 */
function bayesian_delete_previews($bayesian, $userid = null) {
    global $DB;
    $conditions = array('bayesian' => $bayesian->id, 'preview' => 1);
    if (!empty($userid)) {
        $conditions['userid'] = $userid;
    }
    $previewattempts = $DB->get_records('bayesian_attempts', $conditions);
    foreach ($previewattempts as $attempt) {
        bayesian_delete_attempt($attempt, $bayesian);
    }
}

/**
 * @param int $bayesianid The bayesian id.
 * @return bool whether this bayesian has any (non-preview) attempts.
 */
function bayesian_has_attempts($bayesianid) {
    global $DB;
    return $DB->record_exists('bayesian_attempts', array('bayesian' => $bayesianid, 'preview' => 0));
}

// Functions to do with bayesian layout and pages //////////////////////////////////

/**
 * Repaginate the questions in a bayesian
 * @param int $bayesianid the id of the bayesian to repaginate.
 * @param int $slotsperpage number of items to put on each page. 0 means unlimited.
 */
function bayesian_repaginate_questions($bayesianid, $slotsperpage) {
    global $DB;
    $trans = $DB->start_delegated_transaction();

    $sections = $DB->get_records('bayesian_sections', array('bayesianid' => $bayesianid), 'firstslot ASC');
    $firstslots = array();
    foreach ($sections as $section) {
        if ((int)$section->firstslot === 1) {
            continue;
        }
        $firstslots[] = $section->firstslot;
    }

    $slots = $DB->get_records('bayesian_slots', array('bayesianid' => $bayesianid),
            'slot');
    $currentpage = 1;
    $slotsonthispage = 0;
    foreach ($slots as $slot) {
        if (($firstslots && in_array($slot->slot, $firstslots)) ||
            ($slotsonthispage && $slotsonthispage == $slotsperpage)) {
            $currentpage += 1;
            $slotsonthispage = 0;
        }
        if ($slot->page != $currentpage) {
            $DB->set_field('bayesian_slots', 'page', $currentpage, array('id' => $slot->id));
        }
        $slotsonthispage += 1;
    }

    $trans->allow_commit();

    // Log bayesian re-paginated event.
    $cm = get_coursemodule_from_instance('bayesian', $bayesianid);
    $event = \mod_bayesian\event\bayesian_repaginated::create([
        'context' => \context_module::instance($cm->id),
        'objectid' => $bayesianid,
        'other' => [
            'slotsperpage' => $slotsperpage
        ]
    ]);
    $event->trigger();

}

// Functions to do with bayesian grades ////////////////////////////////////////////

/**
 * Convert the raw grade stored in $attempt into a grade out of the maximum
 * grade for this bayesian.
 *
 * @param float $rawgrade the unadjusted grade, fof example $attempt->sumgrades
 * @param object $bayesian the bayesian object. Only the fields grade, sumgrades and decimalpoints are used.
 * @param bool|string $format whether to format the results for display
 *      or 'question' to format a question grade (different number of decimal places.
 * @return float|string the rescaled grade, or null/the lang string 'notyetgraded'
 *      if the $grade is null.
 */
function bayesian_rescale_grade($rawgrade, $bayesian, $format = true) {
    if (is_null($rawgrade)) {
        $grade = null;
    } else if ($bayesian->sumgrades >= 0.000005) {
        $grade = $rawgrade * $bayesian->grade / $bayesian->sumgrades;
    } else {
        $grade = 0;
    }
    if ($format === 'question') {
        $grade = bayesian_format_question_grade($bayesian, $grade);
    } else if ($format) {
        $grade = bayesian_format_grade($bayesian, $grade);
    }
    return $grade;
}

/**
 * Get the feedback object for this grade on this bayesian.
 *
 * @param float $grade a grade on this bayesian.
 * @param object $bayesian the bayesian settings.
 * @return false|stdClass the record object or false if there is not feedback for the given grade
 * @since  Moodle 3.1
 */
function bayesian_feedback_record_for_grade($grade, $bayesian) {
    global $DB;

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedback = $DB->get_record_select('bayesian_feedback',
            'bayesianid = ? AND mingrade <= ? AND ? < maxgrade', array($bayesian->id, $grade, $grade));

    return $feedback;
}

/**
 * Get the feedback text that should be show to a student who
 * got this grade on this bayesian. The feedback is processed ready for diplay.
 *
 * @param float $grade a grade on this bayesian.
 * @param object $bayesian the bayesian settings.
 * @param object $context the bayesian context.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function bayesian_feedback_for_grade($grade, $bayesian, $context) {

    if (is_null($grade)) {
        return '';
    }

    $feedback = bayesian_feedback_record_for_grade($grade, $bayesian);

    if (empty($feedback->feedbacktext)) {
        return '';
    }

    // Clean the text, ready for display.
    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $feedbacktext = file_rewrite_pluginfile_urls($feedback->feedbacktext, 'pluginfile.php',
            $context->id, 'mod_bayesian', 'feedback', $feedback->id);
    $feedbacktext = format_text($feedbacktext, $feedback->feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * @param object $bayesian the bayesian database row.
 * @return bool Whether this bayesian has any non-blank feedback text.
 */
function bayesian_has_feedback($bayesian) {
    global $DB;
    static $cache = array();
    if (!array_key_exists($bayesian->id, $cache)) {
        $cache[$bayesian->id] = bayesian_has_grades($bayesian) &&
                $DB->record_exists_select('bayesian_feedback', "bayesianid = ? AND " .
                    $DB->sql_isnotempty('bayesian_feedback', 'feedbacktext', false, true),
                array($bayesian->id));
    }
    return $cache[$bayesian->id];
}

/**
 * Update the sumgrades field of the bayesian. This needs to be called whenever
 * the grading structure of the bayesian is changed. For example if a question is
 * added or removed, or a question weight is changed.
 *
 * You should call {@link bayesian_delete_previews()} before you call this function.
 *
 * @param object $bayesian a bayesian.
 */
function bayesian_update_sumgrades($bayesian) {
    global $DB;

    $sql = 'UPDATE {bayesian}
            SET sumgrades = COALESCE((
                SELECT SUM(maxmark)
                FROM {bayesian_slots}
                WHERE bayesianid = {bayesian}.id
            ), 0)
            WHERE id = ?';
    $DB->execute($sql, array($bayesian->id));
    $bayesian->sumgrades = $DB->get_field('bayesian', 'sumgrades', array('id' => $bayesian->id));

    if ($bayesian->sumgrades < 0.000005 && bayesian_has_attempts($bayesian->id)) {
        // If the bayesian has been attempted, and the sumgrades has been
        // set to 0, then we must also set the maximum possible grade to 0, or
        // we will get a divide by zero error.
        bayesian_set_grade(0, $bayesian);
    }
}

/**
 * Update the sumgrades field of the attempts at a bayesian.
 *
 * @param object $bayesian a bayesian.
 */
function bayesian_update_all_attempt_sumgrades($bayesian) {
    global $DB;
    $dm = new question_engine_data_mapper();
    $timenow = time();

    $sql = "UPDATE {bayesian_attempts}
            SET
                timemodified = :timenow,
                sumgrades = (
                    {$dm->sum_usage_marks_subquery('uniqueid')}
                )
            WHERE bayesian = :bayesianid AND state = :finishedstate";
    $DB->execute($sql, array('timenow' => $timenow, 'bayesianid' => $bayesian->id,
            'finishedstate' => bayesian_attempt::FINISHED));
}

/**
 * The bayesian grade is the maximum that student's results are marked out of. When it
 * changes, the corresponding data in bayesian_grades and bayesian_feedback needs to be
 * rescaled. After calling this function, you probably need to call
 * bayesian_update_all_attempt_sumgrades, bayesian_update_all_final_grades and
 * bayesian_update_grades.
 *
 * @param float $newgrade the new maximum grade for the bayesian.
 * @param object $bayesian the bayesian we are updating. Passed by reference so its
 *      grade field can be updated too.
 * @return bool indicating success or failure.
 */
function bayesian_set_grade($newgrade, $bayesian) {
    global $DB;
    // This is potentially expensive, so only do it if necessary.
    if (abs($bayesian->grade - $newgrade) < 1e-7) {
        // Nothing to do.
        return true;
    }

    $oldgrade = $bayesian->grade;
    $bayesian->grade = $newgrade;

    // Use a transaction, so that on those databases that support it, this is safer.
    $transaction = $DB->start_delegated_transaction();

    // Update the bayesian table.
    $DB->set_field('bayesian', 'grade', $newgrade, array('id' => $bayesian->instance));

    if ($oldgrade < 1) {
        // If the old grade was zero, we cannot rescale, we have to recompute.
        // We also recompute if the old grade was too small to avoid underflow problems.
        bayesian_update_all_final_grades($bayesian);

    } else {
        // We can rescale the grades efficiently.
        $timemodified = time();
        $DB->execute("
                UPDATE {bayesian_grades}
                SET grade = ? * grade, timemodified = ?
                WHERE bayesian = ?
        ", array($newgrade/$oldgrade, $timemodified, $bayesian->id));
    }

    if ($oldgrade > 1e-7) {
        // Update the bayesian_feedback table.
        $factor = $newgrade/$oldgrade;
        $DB->execute("
                UPDATE {bayesian_feedback}
                SET mingrade = ? * mingrade, maxgrade = ? * maxgrade
                WHERE bayesianid = ?
        ", array($factor, $factor, $bayesian->id));
    }

    // Update grade item and send all grades to gradebook.
    bayesian_grade_item_update($bayesian);
    bayesian_update_grades($bayesian);

    $transaction->allow_commit();

    // Log bayesian grade updated event.
    // We use $num + 0 as a trick to remove the useless 0 digits from decimals.
    $cm = get_coursemodule_from_instance('bayesian', $bayesian->id);
    $event = \mod_bayesian\event\bayesian_grade_updated::create([
        'context' => \context_module::instance($cm->id),
        'objectid' => $bayesian->id,
        'other' => [
            'oldgrade' => $oldgrade + 0,
            'newgrade' => $newgrade + 0
        ]
    ]);
    $event->trigger();
    return true;
}

/**
 * Save the overall grade for a user at a bayesian in the bayesian_grades table
 *
 * @param object $bayesian The bayesian for which the best grade is to be calculated and then saved.
 * @param int $userid The userid to calculate the grade for. Defaults to the current user.
 * @param array $attempts The attempts of this user. Useful if you are
 * looping through many users. Attempts can be fetched in one master query to
 * avoid repeated querying.
 * @return bool Indicates success or failure.
 */
function bayesian_save_best_grade($bayesian, $userid = null, $attempts = array()) {
    global $DB, $OUTPUT, $USER;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    if (!$attempts) {
        // Get all the attempts made by the user.
        $attempts = bayesian_get_user_attempts($bayesian->id, $userid);
    }

    // Calculate the best grade.
    $bestgrade = bayesian_calculate_best_grade($bayesian, $attempts);
    $bestgrade = bayesian_rescale_grade($bestgrade, $bayesian, false);

    // Save the best grade in the database.
    if (is_null($bestgrade)) {
        $DB->delete_records('bayesian_grades', array('bayesian' => $bayesian->id, 'userid' => $userid));

    } else if ($grade = $DB->get_record('bayesian_grades',
            array('bayesian' => $bayesian->id, 'userid' => $userid))) {
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->update_record('bayesian_grades', $grade);

    } else {
        $grade = new stdClass();
        $grade->bayesian = $bayesian->id;
        $grade->userid = $userid;
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->insert_record('bayesian_grades', $grade);
    }

    bayesian_update_grades($bayesian, $userid);
}

/**
 * Calculate the overall grade for a bayesian given a number of attempts by a particular user.
 *
 * @param object $bayesian    the bayesian settings object.
 * @param array $attempts an array of all the user's attempts at this bayesian in order.
 * @return float          the overall grade
 */
function bayesian_calculate_best_grade($bayesian, $attempts) {

    switch ($bayesian->grademethod) {

        case bayesian_ATTEMPTFIRST:
            $firstattempt = reset($attempts);
            return $firstattempt->sumgrades;

        case bayesian_ATTEMPTLAST:
            $lastattempt = end($attempts);
            return $lastattempt->sumgrades;

        case bayesian_GRADEAVERAGE:
            $sum = 0;
            $count = 0;
            foreach ($attempts as $attempt) {
                if (!is_null($attempt->sumgrades)) {
                    $sum += $attempt->sumgrades;
                    $count++;
                }
            }
            if ($count == 0) {
                return null;
            }
            return $sum / $count;

        case bayesian_GRADEHIGHEST:
        default:
            $max = null;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                }
            }
            return $max;
    }
}

/**
 * Update the final grade at this bayesian for all students.
 *
 * This function is equivalent to calling bayesian_save_best_grade for all
 * users, but much more efficient.
 *
 * @param object $bayesian the bayesian settings.
 */
function bayesian_update_all_final_grades($bayesian) {
    global $DB;

    if (!$bayesian->sumgrades) {
        return;
    }

    $param = array('ibayesianid' => $bayesian->id, 'istatefinished' => bayesian_attempt::FINISHED);
    $firstlastattemptjoin = "JOIN (
            SELECT
                ibayesiana.userid,
                MIN(attempt) AS firstattempt,
                MAX(attempt) AS lastattempt

            FROM {bayesian_attempts} ibayesiana

            WHERE
                ibayesiana.state = :istatefinished AND
                ibayesiana.preview = 0 AND
                ibayesiana.bayesian = :ibayesianid

            GROUP BY ibayesiana.userid
        ) first_last_attempts ON first_last_attempts.userid = bayesiana.userid";

    switch ($bayesian->grademethod) {
        case bayesian_ATTEMPTFIRST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(bayesiana.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'bayesiana.attempt = first_last_attempts.firstattempt AND';
            break;

        case bayesian_ATTEMPTLAST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(bayesiana.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'bayesiana.attempt = first_last_attempts.lastattempt AND';
            break;

        case bayesian_GRADEAVERAGE:
            $select = 'AVG(bayesiana.sumgrades)';
            $join = '';
            $where = '';
            break;

        default:
        case bayesian_GRADEHIGHEST:
            $select = 'MAX(bayesiana.sumgrades)';
            $join = '';
            $where = '';
            break;
    }

    if ($bayesian->sumgrades >= 0.000005) {
        $finalgrade = $select . ' * ' . ($bayesian->grade / $bayesian->sumgrades);
    } else {
        $finalgrade = '0';
    }
    $param['bayesianid'] = $bayesian->id;
    $param['bayesianid2'] = $bayesian->id;
    $param['bayesianid3'] = $bayesian->id;
    $param['bayesianid4'] = $bayesian->id;
    $param['statefinished'] = bayesian_attempt::FINISHED;
    $param['statefinished2'] = bayesian_attempt::FINISHED;
    $finalgradesubquery = "
            SELECT bayesiana.userid, $finalgrade AS newgrade
            FROM {bayesian_attempts} bayesiana
            $join
            WHERE
                $where
                bayesiana.state = :statefinished AND
                bayesiana.preview = 0 AND
                bayesiana.bayesian = :bayesianid3
            GROUP BY bayesiana.userid";

    $changedgrades = $DB->get_records_sql("
            SELECT users.userid, qg.id, qg.grade, newgrades.newgrade

            FROM (
                SELECT userid
                FROM {bayesian_grades} qg
                WHERE bayesian = :bayesianid
            UNION
                SELECT DISTINCT userid
                FROM {bayesian_attempts} bayesiana2
                WHERE
                    bayesiana2.state = :statefinished2 AND
                    bayesiana2.preview = 0 AND
                    bayesiana2.bayesian = :bayesianid2
            ) users

            LEFT JOIN {bayesian_grades} qg ON qg.userid = users.userid AND qg.bayesian = :bayesianid4

            LEFT JOIN (
                $finalgradesubquery
            ) newgrades ON newgrades.userid = users.userid

            WHERE
                ABS(newgrades.newgrade - qg.grade) > 0.000005 OR
                ((newgrades.newgrade IS NULL OR qg.grade IS NULL) AND NOT
                          (newgrades.newgrade IS NULL AND qg.grade IS NULL))",
                // The mess on the previous line is detecting where the value is
                // NULL in one column, and NOT NULL in the other, but SQL does
                // not have an XOR operator, and MS SQL server can't cope with
                // (newgrades.newgrade IS NULL) <> (qg.grade IS NULL).
            $param);

    $timenow = time();
    $todelete = array();
    foreach ($changedgrades as $changedgrade) {

        if (is_null($changedgrade->newgrade)) {
            $todelete[] = $changedgrade->userid;

        } else if (is_null($changedgrade->grade)) {
            $toinsert = new stdClass();
            $toinsert->bayesian = $bayesian->id;
            $toinsert->userid = $changedgrade->userid;
            $toinsert->timemodified = $timenow;
            $toinsert->grade = $changedgrade->newgrade;
            $DB->insert_record('bayesian_grades', $toinsert);

        } else {
            $toupdate = new stdClass();
            $toupdate->id = $changedgrade->id;
            $toupdate->grade = $changedgrade->newgrade;
            $toupdate->timemodified = $timenow;
            $DB->update_record('bayesian_grades', $toupdate);
        }
    }

    if (!empty($todelete)) {
        list($test, $params) = $DB->get_in_or_equal($todelete);
        $DB->delete_records_select('bayesian_grades', 'bayesian = ? AND userid ' . $test,
                array_merge(array($bayesian->id), $params));
    }
}

/**
 * Return summary of the number of settings override that exist.
 *
 * To get a nice display of this, see the bayesian_override_summary_links()
 * bayesian renderer method.
 *
 * @param stdClass $bayesian the bayesian settings. Only $bayesian->id is used at the moment.
 * @param stdClass|cm_info $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *      (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return array like 'group' => 3, 'user' => 12] where 3 is the number of group overrides,
 *      and 12 is the number of user ones.
 */
function bayesian_override_summary(stdClass $bayesian, stdClass $cm, int $currentgroup = 0): array {
    global $DB;

    if ($currentgroup) {
        // Currently only interested in one group.
        $groupcount = $DB->count_records('bayesian_overrides', ['bayesian' => $bayesian->id, 'groupid' => $currentgroup]);
        $usercount = $DB->count_records_sql("
                SELECT COUNT(1)
                  FROM {bayesian_overrides} o
                  JOIN {groups_members} gm ON o.userid = gm.userid
                 WHERE o.bayesian = ?
                   AND gm.groupid = ?
                    ", [$bayesian->id, $currentgroup]);
        return ['group' => $groupcount, 'user' => $usercount, 'mode' => 'onegroup'];
    }

    $bayesiangroupmode = groups_get_activity_groupmode($cm);
    $accessallgroups = ($bayesiangroupmode == NOGROUPS) ||
            has_capability('moodle/site:accessallgroups', context_module::instance($cm->id));

    if ($accessallgroups) {
        // User can see all groups.
        $groupcount = $DB->count_records_select('bayesian_overrides',
                'bayesian = ? AND groupid IS NOT NULL', [$bayesian->id]);
        $usercount = $DB->count_records_select('bayesian_overrides',
                'bayesian = ? AND userid IS NOT NULL', [$bayesian->id]);
        return ['group' => $groupcount, 'user' => $usercount, 'mode' => 'allgroups'];

    } else {
        // User can only see groups they are in.
        $groups = groups_get_activity_allowed_groups($cm);
        if (!$groups) {
            return ['group' => 0, 'user' => 0, 'mode' => 'somegroups'];
        }

        list($groupidtest, $params) = $DB->get_in_or_equal(array_keys($groups));
        $params[] = $bayesian->id;

        $groupcount = $DB->count_records_select('bayesian_overrides',
                "groupid $groupidtest AND bayesian = ?", $params);
        $usercount = $DB->count_records_sql("
                SELECT COUNT(1)
                  FROM {bayesian_overrides} o
                  JOIN {groups_members} gm ON o.userid = gm.userid
                 WHERE gm.groupid $groupidtest
                   AND o.bayesian = ?
               ", $params);

        return ['group' => $groupcount, 'user' => $usercount, 'mode' => 'somegroups'];
    }
}

/**
 * Efficiently update check state time on all open attempts
 *
 * @param array $conditions optional restrictions on which attempts to update
 *                    Allowed conditions:
 *                      courseid => (array|int) attempts in given course(s)
 *                      userid   => (array|int) attempts for given user(s)
 *                      bayesianid   => (array|int) attempts in given bayesian(s)
 *                      groupid  => (array|int) bayesianzes with some override for given group(s)
 *
 */
function bayesian_update_open_attempts(array $conditions) {
    global $DB;

    foreach ($conditions as &$value) {
        if (!is_array($value)) {
            $value = array($value);
        }
    }

    $params = array();
    $wheres = array("bayesiana.state IN ('inprogress', 'overdue')");
    $iwheres = array("ibayesiana.state IN ('inprogress', 'overdue')");

    if (isset($conditions['courseid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'cid');
        $params = array_merge($params, $inparams);
        $wheres[] = "bayesiana.bayesian IN (SELECT q.id FROM {bayesian} q WHERE q.course $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'icid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "ibayesiana.bayesian IN (SELECT q.id FROM {bayesian} q WHERE q.course $incond)";
    }

    if (isset($conditions['userid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'uid');
        $params = array_merge($params, $inparams);
        $wheres[] = "bayesiana.userid $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'iuid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "ibayesiana.userid $incond";
    }

    if (isset($conditions['bayesianid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['bayesianid'], SQL_PARAMS_NAMED, 'qid');
        $params = array_merge($params, $inparams);
        $wheres[] = "bayesiana.bayesian $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['bayesianid'], SQL_PARAMS_NAMED, 'iqid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "ibayesiana.bayesian $incond";
    }

    if (isset($conditions['groupid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'gid');
        $params = array_merge($params, $inparams);
        $wheres[] = "bayesiana.bayesian IN (SELECT qo.bayesian FROM {bayesian_overrides} qo WHERE qo.groupid $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'igid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "ibayesiana.bayesian IN (SELECT qo.bayesian FROM {bayesian_overrides} qo WHERE qo.groupid $incond)";
    }

    // SQL to compute timeclose and timelimit for each attempt:
    $bayesianausersql = bayesian_get_attempt_usertime_sql(
            implode("\n                AND ", $iwheres));

    // SQL to compute the new timecheckstate
    $timecheckstatesql = "
          CASE WHEN bayesianauser.usertimelimit = 0 AND bayesianauser.usertimeclose = 0 THEN NULL
               WHEN bayesianauser.usertimelimit = 0 THEN bayesianauser.usertimeclose
               WHEN bayesianauser.usertimeclose = 0 THEN bayesiana.timestart + bayesianauser.usertimelimit
               WHEN bayesiana.timestart + bayesianauser.usertimelimit < bayesianauser.usertimeclose THEN bayesiana.timestart + bayesianauser.usertimelimit
               ELSE bayesianauser.usertimeclose END +
          CASE WHEN bayesiana.state = 'overdue' THEN bayesian.graceperiod ELSE 0 END";

    // SQL to select which attempts to process
    $attemptselect = implode("\n                         AND ", $wheres);

   /*
    * Each database handles updates with inner joins differently:
    *  - mysql does not allow a FROM clause
    *  - postgres and mssql allow FROM but handle table aliases differently
    *  - oracle requires a subquery
    *
    * Different code for each database.
    */

    $dbfamily = $DB->get_dbfamily();
    if ($dbfamily == 'mysql') {
        $updatesql = "UPDATE {bayesian_attempts} bayesiana
                        JOIN {bayesian} bayesian ON bayesian.id = bayesiana.bayesian
                        JOIN ( $bayesianausersql ) bayesianauser ON bayesianauser.id = bayesiana.id
                         SET bayesiana.timecheckstate = $timecheckstatesql
                       WHERE $attemptselect";
    } else if ($dbfamily == 'postgres') {
        $updatesql = "UPDATE {bayesian_attempts} bayesiana
                         SET timecheckstate = $timecheckstatesql
                        FROM {bayesian} bayesian, ( $bayesianausersql ) bayesianauser
                       WHERE bayesian.id = bayesiana.bayesian
                         AND bayesianauser.id = bayesiana.id
                         AND $attemptselect";
    } else if ($dbfamily == 'mssql') {
        $updatesql = "UPDATE bayesiana
                         SET timecheckstate = $timecheckstatesql
                        FROM {bayesian_attempts} bayesiana
                        JOIN {bayesian} bayesian ON bayesian.id = bayesiana.bayesian
                        JOIN ( $bayesianausersql ) bayesianauser ON bayesianauser.id = bayesiana.id
                       WHERE $attemptselect";
    } else {
        // oracle, sqlite and others
        $updatesql = "UPDATE {bayesian_attempts} bayesiana
                         SET timecheckstate = (
                           SELECT $timecheckstatesql
                             FROM {bayesian} bayesian, ( $bayesianausersql ) bayesianauser
                            WHERE bayesian.id = bayesiana.bayesian
                              AND bayesianauser.id = bayesiana.id
                         )
                         WHERE $attemptselect";
    }

    $DB->execute($updatesql, $params);
}

/**
 * Returns SQL to compute timeclose and timelimit for every attempt, taking into account user and group overrides.
 * The query used herein is very similar to the one in function bayesian_get_user_timeclose, so, in case you
 * would change either one of them, make sure to apply your changes to both.
 *
 * @param string $redundantwhereclauses extra where clauses to add to the subquery
 *      for performance. These can use the table alias ibayesiana for the bayesian attempts table.
 * @return string SQL select with columns attempt.id, usertimeclose, usertimelimit.
 */
function bayesian_get_attempt_usertime_sql($redundantwhereclauses = '') {
    if ($redundantwhereclauses) {
        $redundantwhereclauses = 'WHERE ' . $redundantwhereclauses;
    }
    // The multiple qgo JOINS are necessary because we want timeclose/timelimit = 0 (unlimited) to supercede
    // any other group override
    $bayesianausersql = "
          SELECT ibayesiana.id,
           COALESCE(MAX(quo.timeclose), MAX(qgo1.timeclose), MAX(qgo2.timeclose), ibayesian.timeclose) AS usertimeclose,
           COALESCE(MAX(quo.timelimit), MAX(qgo3.timelimit), MAX(qgo4.timelimit), ibayesian.timelimit) AS usertimelimit

           FROM {bayesian_attempts} ibayesiana
           JOIN {bayesian} ibayesian ON ibayesian.id = ibayesiana.bayesian
      LEFT JOIN {bayesian_overrides} quo ON quo.bayesian = ibayesiana.bayesian AND quo.userid = ibayesiana.userid
      LEFT JOIN {groups_members} gm ON gm.userid = ibayesiana.userid
      LEFT JOIN {bayesian_overrides} qgo1 ON qgo1.bayesian = ibayesiana.bayesian AND qgo1.groupid = gm.groupid AND qgo1.timeclose = 0
      LEFT JOIN {bayesian_overrides} qgo2 ON qgo2.bayesian = ibayesiana.bayesian AND qgo2.groupid = gm.groupid AND qgo2.timeclose > 0
      LEFT JOIN {bayesian_overrides} qgo3 ON qgo3.bayesian = ibayesiana.bayesian AND qgo3.groupid = gm.groupid AND qgo3.timelimit = 0
      LEFT JOIN {bayesian_overrides} qgo4 ON qgo4.bayesian = ibayesiana.bayesian AND qgo4.groupid = gm.groupid AND qgo4.timelimit > 0
          $redundantwhereclauses
       GROUP BY ibayesiana.id, ibayesian.id, ibayesian.timeclose, ibayesian.timelimit";
    return $bayesianausersql;
}

/**
 * Return the attempt with the best grade for a bayesian
 *
 * Which attempt is the best depends on $bayesian->grademethod. If the grade
 * method is GRADEAVERAGE then this function simply returns the last attempt.
 * @return object         The attempt with the best grade
 * @param object $bayesian    The bayesian for which the best grade is to be calculated
 * @param array $attempts An array of all the attempts of the user at the bayesian
 */
function bayesian_calculate_best_attempt($bayesian, $attempts) {

    switch ($bayesian->grademethod) {

        case bayesian_ATTEMPTFIRST:
            foreach ($attempts as $attempt) {
                return $attempt;
            }
            break;

        case bayesian_GRADEAVERAGE: // We need to do something with it.
        case bayesian_ATTEMPTLAST:
            foreach ($attempts as $attempt) {
                $final = $attempt;
            }
            return $final;

        default:
        case bayesian_GRADEHIGHEST:
            $max = -1;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                    $maxattempt = $attempt;
                }
            }
            return $maxattempt;
    }
}

/**
 * @return array int => lang string the options for calculating the bayesian grade
 *      from the individual attempt grades.
 */
function bayesian_get_grading_options() {
    return array(
        bayesian_GRADEHIGHEST => get_string('gradehighest', 'bayesian'),
        bayesian_GRADEAVERAGE => get_string('gradeaverage', 'bayesian'),
        bayesian_ATTEMPTFIRST => get_string('attemptfirst', 'bayesian'),
        bayesian_ATTEMPTLAST  => get_string('attemptlast', 'bayesian')
    );
}

/**
 * @param int $option one of the values bayesian_GRADEHIGHEST, bayesian_GRADEAVERAGE,
 *      bayesian_ATTEMPTFIRST or bayesian_ATTEMPTLAST.
 * @return the lang string for that option.
 */
function bayesian_get_grading_option_name($option) {
    $strings = bayesian_get_grading_options();
    return $strings[$option];
}

/**
 * @return array string => lang string the options for handling overdue bayesian
 *      attempts.
 */
function bayesian_get_overdue_handling_options() {
    return array(
        'autosubmit'  => get_string('overduehandlingautosubmit', 'bayesian'),
        'graceperiod' => get_string('overduehandlinggraceperiod', 'bayesian'),
        'autoabandon' => get_string('overduehandlingautoabandon', 'bayesian'),
    );
}

/**
 * Get the choices for what size user picture to show.
 * @return array string => lang string the options for whether to display the user's picture.
 */
function bayesian_get_user_image_options() {
    return array(
        bayesian_SHOWIMAGE_NONE  => get_string('shownoimage', 'bayesian'),
        bayesian_SHOWIMAGE_SMALL => get_string('showsmallimage', 'bayesian'),
        bayesian_SHOWIMAGE_LARGE => get_string('showlargeimage', 'bayesian'),
    );
}

/**
 * Return an user's timeclose for all bayesianzes in a course, hereby taking into account group and user overrides.
 *
 * @param int $courseid the course id.
 * @return object An object with of all bayesianids and close unixdates in this course, taking into account the most lenient
 * overrides, if existing and 0 if no close date is set.
 */
function bayesian_get_user_timeclose($courseid) {
    global $DB, $USER;

    // For teacher and manager/admins return timeclose.
    if (has_capability('moodle/course:update', context_course::instance($courseid))) {
        $sql = "SELECT bayesian.id, bayesian.timeclose AS usertimeclose
                  FROM {bayesian} bayesian
                 WHERE bayesian.course = :courseid";

        $results = $DB->get_records_sql($sql, array('courseid' => $courseid));
        return $results;
    }

    $sql = "SELECT q.id,
  COALESCE(v.userclose, v.groupclose, q.timeclose, 0) AS usertimeclose
  FROM (
      SELECT bayesian.id as bayesianid,
             MAX(quo.timeclose) AS userclose, MAX(qgo.timeclose) AS groupclose
       FROM {bayesian} bayesian
  LEFT JOIN {bayesian_overrides} quo on bayesian.id = quo.bayesian AND quo.userid = :userid
  LEFT JOIN {groups_members} gm ON gm.userid = :useringroupid
  LEFT JOIN {bayesian_overrides} qgo on bayesian.id = qgo.bayesian AND qgo.groupid = gm.groupid
      WHERE bayesian.course = :courseid
   GROUP BY bayesian.id) v
       JOIN {bayesian} q ON q.id = v.bayesianid";

    $results = $DB->get_records_sql($sql, array('userid' => $USER->id, 'useringroupid' => $USER->id, 'courseid' => $courseid));
    return $results;

}

/**
 * Get the choices to offer for the 'Questions per page' option.
 * @return array int => string.
 */
function bayesian_questions_per_page_options() {
    $pageoptions = array();
    $pageoptions[0] = get_string('neverallononepage', 'bayesian');
    $pageoptions[1] = get_string('everyquestion', 'bayesian');
    for ($i = 2; $i <= bayesian_MAX_QPP_OPTION; ++$i) {
        $pageoptions[$i] = get_string('everynquestions', 'bayesian', $i);
    }
    return $pageoptions;
}

/**
 * Get the human-readable name for a bayesian attempt state.
 * @param string $state one of the state constants like {@link bayesian_attempt::IN_PROGRESS}.
 * @return string The lang string to describe that state.
 */
function bayesian_attempt_state_name($state) {
    switch ($state) {
        case bayesian_attempt::IN_PROGRESS:
            return get_string('stateinprogress', 'bayesian');
        case bayesian_attempt::OVERDUE:
            return get_string('stateoverdue', 'bayesian');
        case bayesian_attempt::FINISHED:
            return get_string('statefinished', 'bayesian');
        case bayesian_attempt::ABANDONED:
            return get_string('stateabandoned', 'bayesian');
        default:
            throw new coding_exception('Unknown bayesian attempt state.');
    }
}

// Other bayesian functions ////////////////////////////////////////////////////////

/**
 * @param object $bayesian the bayesian.
 * @param int $cmid the course_module object for this bayesian.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param int $variant which question variant to preview (optional).
 * @param bool $random if question is random, true.
 * @return string html for a number of icons linked to action pages for a
 * question - preview and edit / view icons depending on user capabilities.
 */
function bayesian_question_action_icons($bayesian, $cmid, $question, $returnurl, $variant = null) {
    $html = '';
    if ($question->qtype !== 'random') {
        $html = bayesian_question_preview_button($bayesian, $question, false, $variant);
    }
    $html .= bayesian_question_edit_button($cmid, $question, $returnurl);
    return $html;
}

/**
 * @param int $cmid the course_module.id for this bayesian.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param string $contentbeforeicon some HTML content to be added inside the link, before the icon.
 * @return the HTML for an edit icon, view icon, or nothing for a question
 *      (depending on permissions).
 */
function bayesian_question_edit_button($cmid, $question, $returnurl, $contentaftericon = '') {
    global $CFG, $OUTPUT;

    // Minor efficiency saving. Only get strings once, even if there are a lot of icons on one page.
    static $stredit = null;
    static $strview = null;
    if ($stredit === null) {
        $stredit = get_string('edit');
        $strview = get_string('view');
    }

    // What sort of icon should we show?
    $action = '';
    if (!empty($question->id) &&
            (question_has_capability_on($question, 'edit') ||
                    question_has_capability_on($question, 'move'))) {
        $action = $stredit;
        $icon = 't/edit';
    } else if (!empty($question->id) &&
            question_has_capability_on($question, 'view')) {
        $action = $strview;
        $icon = 'i/info';
    }

    // Build the icon.
    if ($action) {
        if ($returnurl instanceof moodle_url) {
            $returnurl = $returnurl->out_as_local_url(false);
        }
        $questionparams = array('returnurl' => $returnurl, 'cmid' => $cmid, 'id' => $question->id);
        $questionurl = new moodle_url("$CFG->wwwroot/question/bank/editquestion/question.php", $questionparams);
        return '<a title="' . $action . '" href="' . $questionurl->out() . '" class="questioneditbutton">' .
                $OUTPUT->pix_icon($icon, $action) . $contentaftericon .
                '</a>';
    } else if ($contentaftericon) {
        return '<span class="questioneditbutton">' . $contentaftericon . '</span>';
    } else {
        return '';
    }
}

/**
 * @param object $bayesian the bayesian settings
 * @param object $question the question
 * @param int $variant which question variant to preview (optional).
 * @return moodle_url to preview this question with the options from this bayesian.
 */
function bayesian_question_preview_url($bayesian, $question, $variant = null) {
    // Get the appropriate display options.
    $displayoptions = mod_bayesian_display_options::make_from_bayesian($bayesian,
            mod_bayesian_display_options::DURING);

    $maxmark = null;
    if (isset($question->maxmark)) {
        $maxmark = $question->maxmark;
    }

    // Work out the correcte preview URL.
    return \qbank_previewquestion\helper::question_preview_url($question->id, $bayesian->preferredbehaviour,
            $maxmark, $displayoptions, $variant);
}

/**
 * @param object $bayesian the bayesian settings
 * @param object $question the question
 * @param bool $label if true, show the preview question label after the icon
 * @param int $variant which question variant to preview (optional).
 * @param bool $random if question is random, true.
 * @return the HTML for a preview question icon.
 */
function bayesian_question_preview_button($bayesian, $question, $label = false, $variant = null, $random = null) {
    global $PAGE;
    if (!question_has_capability_on($question, 'use')) {
        return '';
    }
    return $PAGE->get_renderer('mod_bayesian', 'edit')->question_preview_icon($bayesian, $question, $label, $variant, null);
}

/**
 * @param object $attempt the attempt.
 * @param object $context the bayesian context.
 * @return int whether flags should be shown/editable to the current user for this attempt.
 */
function bayesian_get_flag_option($attempt, $context) {
    global $USER;
    if (!has_capability('moodle/question:flag', $context)) {
        return question_display_options::HIDDEN;
    } else if ($attempt->userid == $USER->id) {
        return question_display_options::EDITABLE;
    } else {
        return question_display_options::VISIBLE;
    }
}

/**
 * Work out what state this bayesian attempt is in - in the sense used by
 * bayesian_get_review_options, not in the sense of $attempt->state.
 * @param object $bayesian the bayesian settings
 * @param object $attempt the bayesian_attempt database row.
 * @return int one of the mod_bayesian_display_options::DURING,
 *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
 */
function bayesian_attempt_state($bayesian, $attempt) {
    if ($attempt->state == bayesian_attempt::IN_PROGRESS) {
        return mod_bayesian_display_options::DURING;
    } else if ($bayesian->timeclose && time() >= $bayesian->timeclose) {
        return mod_bayesian_display_options::AFTER_CLOSE;
    } else if (time() < $attempt->timefinish + 120) {
        return mod_bayesian_display_options::IMMEDIATELY_AFTER;
    } else {
        return mod_bayesian_display_options::LATER_WHILE_OPEN;
    }
}

/**
 * The the appropraite mod_bayesian_display_options object for this attempt at this
 * bayesian right now.
 *
 * @param stdClass $bayesian the bayesian instance.
 * @param stdClass $attempt the attempt in question.
 * @param context $context the bayesian context.
 *
 * @return mod_bayesian_display_options
 */
function bayesian_get_review_options($bayesian, $attempt, $context) {
    $options = mod_bayesian_display_options::make_from_bayesian($bayesian, bayesian_attempt_state($bayesian, $attempt));

    $options->readonly = true;
    $options->flags = bayesian_get_flag_option($attempt, $context);
    if (!empty($attempt->id)) {
        $options->questionreviewlink = new moodle_url('/mod/bayesian/reviewquestion.php',
                array('attempt' => $attempt->id));
    }

    // Show a link to the comment box only for closed attempts.
    if (!empty($attempt->id) && $attempt->state == bayesian_attempt::FINISHED && !$attempt->preview &&
            !is_null($context) && has_capability('mod/bayesian:grade', $context)) {
        $options->manualcomment = question_display_options::VISIBLE;
        $options->manualcommentlink = new moodle_url('/mod/bayesian/comment.php',
                array('attempt' => $attempt->id));
    }

    if (!is_null($context) && !$attempt->preview &&
            has_capability('mod/bayesian:viewreports', $context) &&
            has_capability('moodle/grade:viewhidden', $context)) {
        // People who can see reports and hidden grades should be shown everything,
        // except during preview when teachers want to see what students see.
        $options->attempt = question_display_options::VISIBLE;
        $options->correctness = question_display_options::VISIBLE;
        $options->marks = question_display_options::MARK_AND_MAX;
        $options->feedback = question_display_options::VISIBLE;
        $options->numpartscorrect = question_display_options::VISIBLE;
        $options->manualcomment = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        $options->overallfeedback = question_display_options::VISIBLE;
        $options->history = question_display_options::VISIBLE;
        $options->userinfoinhistory = $attempt->userid;

    }

    return $options;
}

/**
 * Combines the review options from a number of different bayesian attempts.
 * Returns an array of two ojects, so the suggested way of calling this
 * funciton is:
 * list($someoptions, $alloptions) = bayesian_get_combined_reviewoptions(...)
 *
 * @param object $bayesian the bayesian instance.
 * @param array $attempts an array of attempt objects.
 *
 * @return array of two options objects, one showing which options are true for
 *          at least one of the attempts, the other showing which options are true
 *          for all attempts.
 */
function bayesian_get_combined_reviewoptions($bayesian, $attempts) {
    $fields = array('feedback', 'generalfeedback', 'rightanswer', 'overallfeedback');
    $someoptions = new stdClass();
    $alloptions = new stdClass();
    foreach ($fields as $field) {
        $someoptions->$field = false;
        $alloptions->$field = true;
    }
    $someoptions->marks = question_display_options::HIDDEN;
    $alloptions->marks = question_display_options::MARK_AND_MAX;

    // This shouldn't happen, but we need to prevent reveal information.
    if (empty($attempts)) {
        return array($someoptions, $someoptions);
    }

    foreach ($attempts as $attempt) {
        $attemptoptions = mod_bayesian_display_options::make_from_bayesian($bayesian,
                bayesian_attempt_state($bayesian, $attempt));
        foreach ($fields as $field) {
            $someoptions->$field = $someoptions->$field || $attemptoptions->$field;
            $alloptions->$field = $alloptions->$field && $attemptoptions->$field;
        }
        $someoptions->marks = max($someoptions->marks, $attemptoptions->marks);
        $alloptions->marks = min($alloptions->marks, $attemptoptions->marks);
    }
    return array($someoptions, $alloptions);
}

// Functions for sending notification messages /////////////////////////////////

/**
 * Sends a confirmation message to the student confirming that the attempt was processed.
 *
 * @param object $a lots of useful information that can be used in the message
 *      subject and body.
 * @param bool $studentisonline is the student currently interacting with Moodle?
 *
 * @return int|false as for {@link message_send()}.
 */
function bayesian_send_confirmation($recipient, $a, $studentisonline) {

    // Add information about the recipient to $a.
    // Don't do idnumber. we want idnumber to be the submitter's idnumber.
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->courseid          = $a->courseid;
    $eventdata->component         = 'mod_bayesian';
    $eventdata->name              = 'confirmation';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailconfirmsubject', 'bayesian', $a);

    if ($studentisonline) {
        $eventdata->fullmessage = get_string('emailconfirmbody', 'bayesian', $a);
    } else {
        $eventdata->fullmessage = get_string('emailconfirmbodyautosubmit', 'bayesian', $a);
    }

    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailconfirmsmall', 'bayesian', $a);
    $eventdata->contexturl        = $a->bayesianurl;
    $eventdata->contexturlname    = $a->bayesianname;
    $eventdata->customdata        = [
        'cmid' => $a->bayesiancmid,
        'instance' => $a->bayesianid,
        'attemptid' => $a->attemptid,
    ];

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Sends notification messages to the interested parties that assign the role capability
 *
 * @param object $recipient user object of the intended recipient
 * @param object $a associative array of replaceable fields for the templates
 *
 * @return int|false as for {@link message_send()}.
 */
function bayesian_send_notification($recipient, $submitter, $a) {
    global $PAGE;

    // Recipient info for template.
    $a->useridnumber = $recipient->idnumber;
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->courseid          = $a->courseid;
    $eventdata->component         = 'mod_bayesian';
    $eventdata->name              = 'submission';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = $submitter;
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailnotifysubject', 'bayesian', $a);
    $eventdata->fullmessage       = get_string('emailnotifybody', 'bayesian', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailnotifysmall', 'bayesian', $a);
    $eventdata->contexturl        = $a->bayesianreviewurl;
    $eventdata->contexturlname    = $a->bayesianname;
    $userpicture = new user_picture($submitter);
    $userpicture->size = 1; // Use f1 size.
    $userpicture->includetoken = $recipient->id; // Generate an out-of-session token for the user receiving the message.
    $eventdata->customdata        = [
        'cmid' => $a->bayesiancmid,
        'instance' => $a->bayesianid,
        'attemptid' => $a->attemptid,
        'notificationiconurl' => $userpicture->get_url($PAGE)->out(false),
    ];

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Send all the requried messages when a bayesian attempt is submitted.
 *
 * @param object $course the course
 * @param object $bayesian the bayesian
 * @param object $attempt this attempt just finished
 * @param object $context the bayesian context
 * @param object $cm the coursemodule for this bayesian
 * @param bool $studentisonline is the student currently interacting with Moodle?
 *
 * @return bool true if all necessary messages were sent successfully, else false.
 */
function bayesian_send_notification_messages($course, $bayesian, $attempt, $context, $cm, $studentisonline) {
    global $CFG, $DB;

    // Do nothing if required objects not present.
    if (empty($course) or empty($bayesian) or empty($attempt) or empty($context)) {
        throw new coding_exception('$course, $bayesian, $attempt, $context and $cm must all be set.');
    }

    $submitter = $DB->get_record('user', array('id' => $attempt->userid), '*', MUST_EXIST);

    // Check for confirmation required.
    $sendconfirm = false;
    $notifyexcludeusers = '';
    if (has_capability('mod/bayesian:emailconfirmsubmission', $context, $submitter, false)) {
        $notifyexcludeusers = $submitter->id;
        $sendconfirm = true;
    }

    // Check for notifications required.
    $notifyfields = 'u.id, u.username, u.idnumber, u.email, u.emailstop, u.lang,
            u.timezone, u.mailformat, u.maildisplay, u.auth, u.suspended, u.deleted, ';
    $userfieldsapi = \core_user\fields::for_name();
    $notifyfields .= $userfieldsapi->get_sql('u', false, '', '', false)->selects;
    $groups = groups_get_all_groups($course->id, $submitter->id, $cm->groupingid);
    if (is_array($groups) && count($groups) > 0) {
        $groups = array_keys($groups);
    } else if (groups_get_activity_groupmode($cm, $course) != NOGROUPS) {
        // If the user is not in a group, and the bayesian is set to group mode,
        // then set $groups to a non-existant id so that only users with
        // 'moodle/site:accessallgroups' get notified.
        $groups = -1;
    } else {
        $groups = '';
    }
    $userstonotify = get_users_by_capability($context, 'mod/bayesian:emailnotifysubmission',
            $notifyfields, '', '', '', $groups, $notifyexcludeusers, false, false, true);

    if (empty($userstonotify) && !$sendconfirm) {
        return true; // Nothing to do.
    }

    $a = new stdClass();
    // Course info.
    $a->courseid        = $course->id;
    $a->coursename      = $course->fullname;
    $a->courseshortname = $course->shortname;
    // bayesian info.
    $a->bayesianname        = $bayesian->name;
    $a->bayesianreporturl   = $CFG->wwwroot . '/mod/bayesian/report.php?id=' . $cm->id;
    $a->bayesianreportlink  = '<a href="' . $a->bayesianreporturl . '">' .
            format_string($bayesian->name) . ' report</a>';
    $a->bayesianurl         = $CFG->wwwroot . '/mod/bayesian/view.php?id=' . $cm->id;
    $a->bayesianlink        = '<a href="' . $a->bayesianurl . '">' . format_string($bayesian->name) . '</a>';
    $a->bayesianid          = $bayesian->id;
    $a->bayesiancmid        = $cm->id;
    // Attempt info.
    $a->submissiontime  = userdate($attempt->timefinish);
    $a->timetaken       = format_time($attempt->timefinish - $attempt->timestart);
    $a->bayesianreviewurl   = $CFG->wwwroot . '/mod/bayesian/review.php?attempt=' . $attempt->id;
    $a->bayesianreviewlink  = '<a href="' . $a->bayesianreviewurl . '">' .
            format_string($bayesian->name) . ' review</a>';
    $a->attemptid       = $attempt->id;
    // Student who sat the bayesian info.
    $a->studentidnumber = $submitter->idnumber;
    $a->studentname     = fullname($submitter);
    $a->studentusername = $submitter->username;

    $allok = true;

    // Send notifications if required.
    if (!empty($userstonotify)) {
        foreach ($userstonotify as $recipient) {
            $allok = $allok && bayesian_send_notification($recipient, $submitter, $a);
        }
    }

    // Send confirmation if required. We send the student confirmation last, so
    // that if message sending is being intermittently buggy, which means we send
    // some but not all messages, and then try again later, then teachers may get
    // duplicate messages, but the student will always get exactly one.
    if ($sendconfirm) {
        $allok = $allok && bayesian_send_confirmation($submitter, $a, $studentisonline);
    }

    return $allok;
}

/**
 * Send the notification message when a bayesian attempt becomes overdue.
 *
 * @param bayesian_attempt $attemptobj all the data about the bayesian attempt.
 */
function bayesian_send_overdue_message($attemptobj) {
    global $CFG, $DB;

    $submitter = $DB->get_record('user', array('id' => $attemptobj->get_userid()), '*', MUST_EXIST);

    if (!$attemptobj->has_capability('mod/bayesian:emailwarnoverdue', $submitter->id, false)) {
        return; // Message not required.
    }

    if (!$attemptobj->has_response_to_at_least_one_graded_question()) {
        return; // Message not required.
    }

    // Prepare lots of useful information that admins might want to include in
    // the email message.
    $bayesianname = format_string($attemptobj->get_bayesian_name());

    $deadlines = array();
    if ($attemptobj->get_bayesian()->timelimit) {
        $deadlines[] = $attemptobj->get_attempt()->timestart + $attemptobj->get_bayesian()->timelimit;
    }
    if ($attemptobj->get_bayesian()->timeclose) {
        $deadlines[] = $attemptobj->get_bayesian()->timeclose;
    }
    $duedate = min($deadlines);
    $graceend = $duedate + $attemptobj->get_bayesian()->graceperiod;

    $a = new stdClass();
    // Course info.
    $a->courseid           = $attemptobj->get_course()->id;
    $a->coursename         = format_string($attemptobj->get_course()->fullname);
    $a->courseshortname    = format_string($attemptobj->get_course()->shortname);
    // bayesian info.
    $a->bayesianname           = $bayesianname;
    $a->bayesianurl            = $attemptobj->view_url();
    $a->bayesianlink           = '<a href="' . $a->bayesianurl . '">' . $bayesianname . '</a>';
    // Attempt info.
    $a->attemptduedate     = userdate($duedate);
    $a->attemptgraceend    = userdate($graceend);
    $a->attemptsummaryurl  = $attemptobj->summary_url()->out(false);
    $a->attemptsummarylink = '<a href="' . $a->attemptsummaryurl . '">' . $bayesianname . ' review</a>';
    // Student's info.
    $a->studentidnumber    = $submitter->idnumber;
    $a->studentname        = fullname($submitter);
    $a->studentusername    = $submitter->username;

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->courseid          = $a->courseid;
    $eventdata->component         = 'mod_bayesian';
    $eventdata->name              = 'attempt_overdue';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $submitter;
    $eventdata->subject           = get_string('emailoverduesubject', 'bayesian', $a);
    $eventdata->fullmessage       = get_string('emailoverduebody', 'bayesian', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailoverduesmall', 'bayesian', $a);
    $eventdata->contexturl        = $a->bayesianurl;
    $eventdata->contexturlname    = $a->bayesianname;
    $eventdata->customdata        = [
        'cmid' => $attemptobj->get_cmid(),
        'instance' => $attemptobj->get_bayesianid(),
        'attemptid' => $attemptobj->get_attemptid(),
    ];

    // Send the message.
    return message_send($eventdata);
}

/**
 * Handle the bayesian_attempt_submitted event.
 *
 * This sends the confirmation and notification messages, if required.
 *
 * @param object $event the event object.
 */
function bayesian_attempt_submitted_handler($event) {
    global $DB;

    $course  = $DB->get_record('course', array('id' => $event->courseid));
    $attempt = $event->get_record_snapshot('bayesian_attempts', $event->objectid);
    $bayesian    = $event->get_record_snapshot('bayesian', $attempt->bayesian);
    $cm      = get_coursemodule_from_id('bayesian', $event->get_context()->instanceid, $event->courseid);
    $eventdata = $event->get_data();

    if (!($course && $bayesian && $cm && $attempt)) {
        // Something has been deleted since the event was raised. Therefore, the
        // event is no longer relevant.
        return true;
    }

    // Update completion state.
    $completion = new completion_info($course);
    if ($completion->is_enabled($cm) &&
        ($bayesian->completionattemptsexhausted || $bayesian->completionminattempts)) {
        $completion->update_state($cm, COMPLETION_COMPLETE, $event->userid);
    }
    return bayesian_send_notification_messages($course, $bayesian, $attempt,
            context_module::instance($cm->id), $cm, $eventdata['other']['studentisonline']);
}

/**
 * Send the notification message when a bayesian attempt has been manual graded.
 *
 * @param bayesian_attempt $attemptobj Some data about the bayesian attempt.
 * @param object $userto
 * @return int|false As for message_send.
 */
function bayesian_send_notify_manual_graded_message(bayesian_attempt $attemptobj, object $userto): ?int {
    global $CFG;

    $bayesianname = format_string($attemptobj->get_bayesian_name());

    $a = new stdClass();
    // Course info.
    $a->courseid           = $attemptobj->get_courseid();
    $a->coursename         = format_string($attemptobj->get_course()->fullname);
    // bayesian info.
    $a->bayesianname           = $bayesianname;
    $a->bayesianurl            = $CFG->wwwroot . '/mod/bayesian/view.php?id=' . $attemptobj->get_cmid();

    // Attempt info.
    $a->attempttimefinish  = userdate($attemptobj->get_attempt()->timefinish);
    // Student's info.
    $a->studentidnumber    = $userto->idnumber;
    $a->studentname        = fullname($userto);

    $eventdata = new \core\message\message();
    $eventdata->component = 'mod_bayesian';
    $eventdata->name = 'attempt_grading_complete';
    $eventdata->userfrom = core_user::get_noreply_user();
    $eventdata->userto = $userto;

    $eventdata->subject = get_string('emailmanualgradedsubject', 'bayesian', $a);
    $eventdata->fullmessage = get_string('emailmanualgradedbody', 'bayesian', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml = '';

    $eventdata->notification = 1;
    $eventdata->contexturl = $a->bayesianurl;
    $eventdata->contexturlname = $a->bayesianname;

    // Send the message.
    return message_send($eventdata);
}

/**
 * Handle groups_member_added event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_bayesian\group_observers::group_member_added()}.
 */
function bayesian_groups_member_added_handler($event) {
    debugging('bayesian_groups_member_added_handler() is deprecated, please use ' .
        '\mod_bayesian\group_observers::group_member_added() instead.', DEBUG_DEVELOPER);
    bayesian_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
}

/**
 * Handle groups_member_removed event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_bayesian\group_observers::group_member_removed()}.
 */
function bayesian_groups_member_removed_handler($event) {
    debugging('bayesian_groups_member_removed_handler() is deprecated, please use ' .
        '\mod_bayesian\group_observers::group_member_removed() instead.', DEBUG_DEVELOPER);
    bayesian_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
}

/**
 * Handle groups_group_deleted event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_bayesian\group_observers::group_deleted()}.
 */
function bayesian_groups_group_deleted_handler($event) {
    global $DB;
    debugging('bayesian_groups_group_deleted_handler() is deprecated, please use ' .
        '\mod_bayesian\group_observers::group_deleted() instead.', DEBUG_DEVELOPER);
    bayesian_process_group_deleted_in_course($event->courseid);
}

/**
 * Logic to happen when a/some group(s) has/have been deleted in a course.
 *
 * @param int $courseid The course ID.
 * @return void
 */
function bayesian_process_group_deleted_in_course($courseid) {
    global $DB;

    // It would be nice if we got the groupid that was deleted.
    // Instead, we just update all bayesianzes with orphaned group overrides.
    $sql = "SELECT o.id, o.bayesian, o.groupid
              FROM {bayesian_overrides} o
              JOIN {bayesian} bayesian ON bayesian.id = o.bayesian
         LEFT JOIN {groups} grp ON grp.id = o.groupid
             WHERE bayesian.course = :courseid
               AND o.groupid IS NOT NULL
               AND grp.id IS NULL";
    $params = array('courseid' => $courseid);
    $records = $DB->get_records_sql($sql, $params);
    if (!$records) {
        return; // Nothing to do.
    }
    $DB->delete_records_list('bayesian_overrides', 'id', array_keys($records));
    $cache = cache::make('mod_bayesian', 'overrides');
    foreach ($records as $record) {
        $cache->delete("{$record->bayesian}_g_{$record->groupid}");
    }
    bayesian_update_open_attempts(['bayesianid' => array_unique(array_column($records, 'bayesian'))]);
}

/**
 * Handle groups_members_removed event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_bayesian\group_observers::group_member_removed()}.
 */
function bayesian_groups_members_removed_handler($event) {
    debugging('bayesian_groups_members_removed_handler() is deprecated, please use ' .
        '\mod_bayesian\group_observers::group_member_removed() instead.', DEBUG_DEVELOPER);
    if ($event->userid == 0) {
        bayesian_update_open_attempts(array('courseid'=>$event->courseid));
    } else {
        bayesian_update_open_attempts(array('courseid'=>$event->courseid, 'userid'=>$event->userid));
    }
}

/**
 * Get the information about the standard bayesian JavaScript module.
 * @return array a standard jsmodule structure.
 */
function bayesian_get_js_module() {
    global $PAGE;

    return array(
        'name' => 'mod_bayesian',
        'fullpath' => '/mod/bayesian/module.js',
        'requires' => array('base', 'dom', 'event-delegate', 'event-key',
                'core_question_engine'),
        'strings' => array(
            array('cancel', 'moodle'),
            array('flagged', 'question'),
            array('functiondisabledbysecuremode', 'bayesian'),
            array('startattempt', 'bayesian'),
            array('timesup', 'bayesian'),
        ),
    );
}


/**
 * An extension of question_display_options that includes the extra options used
 * by the bayesian.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_bayesian_display_options extends question_display_options {
    /**#@+
     * @var integer bits used to indicate various times in relation to a
     * bayesian attempt.
     */
    const DURING =            0x10000;
    const IMMEDIATELY_AFTER = 0x01000;
    const LATER_WHILE_OPEN =  0x00100;
    const AFTER_CLOSE =       0x00010;
    /**#@-*/

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $attempt = true;

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $overallfeedback = self::VISIBLE;

    /**
     * Set up the various options from the bayesian settings, and a time constant.
     * @param object $bayesian the bayesian settings.
     * @param int $one of the {@link DURING}, {@link IMMEDIATELY_AFTER},
     * {@link LATER_WHILE_OPEN} or {@link AFTER_CLOSE} constants.
     * @return mod_bayesian_display_options set up appropriately.
     */
    public static function make_from_bayesian($bayesian, $when) {
        $options = new self();

        $options->attempt = self::extract($bayesian->reviewattempt, $when, true, false);
        $options->correctness = self::extract($bayesian->reviewcorrectness, $when);
        $options->marks = self::extract($bayesian->reviewmarks, $when,
                self::MARK_AND_MAX, self::MAX_ONLY);
        $options->feedback = self::extract($bayesian->reviewspecificfeedback, $when);
        $options->generalfeedback = self::extract($bayesian->reviewgeneralfeedback, $when);
        $options->rightanswer = self::extract($bayesian->reviewrightanswer, $when);
        $options->overallfeedback = self::extract($bayesian->reviewoverallfeedback, $when);

        $options->numpartscorrect = $options->feedback;
        $options->manualcomment = $options->feedback;

        if ($bayesian->questiondecimalpoints != -1) {
            $options->markdp = $bayesian->questiondecimalpoints;
        } else {
            $options->markdp = $bayesian->decimalpoints;
        }

        return $options;
    }

    protected static function extract($bitmask, $bit,
            $whenset = self::VISIBLE, $whennotset = self::HIDDEN) {
        if ($bitmask & $bit) {
            return $whenset;
        } else {
            return $whennotset;
        }
    }
}

/**
 * A {@link qubaid_condition} for finding all the question usages belonging to
 * a particular bayesian.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qubaids_for_bayesian extends qubaid_join {
    public function __construct($bayesianid, $includepreviews = true, $onlyfinished = false) {
        $where = 'bayesiana.bayesian = :bayesianabayesian';
        $params = array('bayesianabayesian' => $bayesianid);

        if (!$includepreviews) {
            $where .= ' AND preview = 0';
        }

        if ($onlyfinished) {
            $where .= ' AND state = :statefinished';
            $params['statefinished'] = bayesian_attempt::FINISHED;
        }

        parent::__construct('{bayesian_attempts} bayesiana', 'bayesiana.uniqueid', $where, $params);
    }
}

/**
 * A {@link qubaid_condition} for finding all the question usages belonging to a particular user and bayesian combination.
 *
 * @copyright  2018 Andrew Nicols <andrwe@nicols.co.uk>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qubaids_for_bayesian_user extends qubaid_join {
    /**
     * Constructor for this qubaid.
     *
     * @param   int     $bayesianid The bayesian to search.
     * @param   int     $userid The user to filter on
     * @param   bool    $includepreviews Whether to include preview attempts
     * @param   bool    $onlyfinished Whether to only include finished attempts or not
     */
    public function __construct($bayesianid, $userid, $includepreviews = true, $onlyfinished = false) {
        $where = 'bayesiana.bayesian = :bayesianabayesian AND bayesiana.userid = :bayesianauserid';
        $params = [
            'bayesianabayesian' => $bayesianid,
            'bayesianauserid' => $userid,
        ];

        if (!$includepreviews) {
            $where .= ' AND preview = 0';
        }

        if ($onlyfinished) {
            $where .= ' AND state = :statefinished';
            $params['statefinished'] = bayesian_attempt::FINISHED;
        }

        parent::__construct('{bayesian_attempts} bayesiana', 'bayesiana.uniqueid', $where, $params);
    }
}

/**
 * Creates a textual representation of a question for display.
 *
 * @param object $question A question object from the database questions table
 * @param bool $showicon If true, show the question's icon with the question. False by default.
 * @param bool $showquestiontext If true (default), show question text after question name.
 *       If false, show only question name.
 * @param bool $showidnumber If true, show the question's idnumber, if any. False by default.
 * @param core_tag_tag[]|bool $showtags if array passed, show those tags. Else, if true, get and show tags,
 *       else, don't show tags (which is the default).
 * @return string HTML fragment.
 */
function bayesian_question_tostring($question, $showicon = false, $showquestiontext = true,
        $showidnumber = false, $showtags = false) {
    global $OUTPUT;
    $result = '';

    // Question name.
    $name = shorten_text(format_string($question->name), 200);
    if ($showicon) {
        $name .= print_question_icon($question) . ' ' . $name;
    }
    $result .= html_writer::span($name, 'questionname');

    // Question idnumber.
    if ($showidnumber && $question->idnumber !== null && $question->idnumber !== '') {
        $result .= ' ' . html_writer::span(
                html_writer::span(get_string('idnumber', 'question'), 'accesshide') .
                ' ' . s($question->idnumber), 'badge badge-primary');
    }

    // Question tags.
    if (is_array($showtags)) {
        $tags = $showtags;
    } else if ($showtags) {
        $tags = core_tag_tag::get_item_tags('core_question', 'question', $question->id);
    } else {
        $tags = [];
    }
    if ($tags) {
        $result .= $OUTPUT->tag_list($tags, null, 'd-inline', 0, null, true);
    }

    // Question text.
    if ($showquestiontext) {
        $questiontext = question_utils::to_plain_text($question->questiontext,
                $question->questiontextformat, array('noclean' => true, 'para' => false));
        $questiontext = shorten_text($questiontext, 50);
        if ($questiontext) {
            $result .= ' ' . html_writer::span(s($questiontext), 'questiontext');
        }
    }

    return $result;
}

/**
 * Verify that the question exists, and the user has permission to use it.
 * Does not return. Throws an exception if the question cannot be used.
 * @param int $questionid The id of the question.
 */
function bayesian_require_question_use($questionid) {
    global $DB;
    $question = $DB->get_record('question', array('id' => $questionid), '*', MUST_EXIST);
    question_require_capability_on($question, 'use');
}

/**
 * Verify that the question exists, and the user has permission to use it.
 *
 * @deprecated in 4.1 use mod_bayesian\structure::has_use_capability(...) instead.
 *
 * @param object $bayesian the bayesian settings.
 * @param int $slot which question in the bayesian to test.
 * @return bool whether the user can use this question.
 */
function bayesian_has_question_use($bayesian, $slot) {
    global $DB;

    debugging('Deprecated. Please use mod_bayesian\structure::has_use_capability instead.');

    $sql = 'SELECT q.*
              FROM {bayesian_slots} slot
              JOIN {question_references} qre ON qre.itemid = slot.id
              JOIN {question_bank_entries} qbe ON qbe.id = qre.questionbankentryid
              JOIN {question_versions} qve ON qve.questionbankentryid = qbe.id
              JOIN {question} q ON q.id = qve.questionid
             WHERE slot.bayesianid = ?
               AND slot.slot = ?
               AND qre.component = ?
               AND qre.questionarea = ?';

    $question = $DB->get_record_sql($sql, [$bayesian->id, $slot, 'mod_bayesian', 'slot']);

    if (!$question) {
        return false;
    }
    return question_has_capability_on($question, 'use');
}

/**
 * Add a question to a bayesian
 *
 * Adds a question to a bayesian by updating $bayesian as well as the
 * bayesian and bayesian_slots tables. It also adds a page break if required.
 * @param int $questionid The id of the question to be added
 * @param object $bayesian The extended bayesian object as used by edit.php
 *      This is updated by this function
 * @param int $page Which page in bayesian to add the question on. If 0 (default),
 *      add at the end
 * @param float $maxmark The maximum mark to set for this question. (Optional,
 *      defaults to question.defaultmark.
 * @return bool false if the question was already in the bayesian
 */
function bayesian_add_bayesian_question($questionid, $bayesian, $page = 0, $maxmark = null) {
    global $DB;

    if (!isset($bayesian->cmid)) {
        $cm = get_coursemodule_from_instance('bayesian', $bayesian->id, $bayesian->course);
        $bayesian->cmid = $cm->id;
    }

    // Make sue the question is not of the "random" type.
    $questiontype = $DB->get_field('question', 'qtype', array('id' => $questionid));
    if ($questiontype == 'random') {
        throw new coding_exception(
                'Adding "random" questions via bayesian_add_bayesian_question() is deprecated. Please use bayesian_add_random_questions().'
        );
    }

    $trans = $DB->start_delegated_transaction();

    $sql = "SELECT qbe.id
              FROM {bayesian_slots} slot
              JOIN {question_references} qr ON qr.itemid = slot.id
              JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
             WHERE slot.bayesianid = ?
               AND qr.component = ?
               AND qr.questionarea = ?";

    $questionslots = $DB->get_records_sql($sql, [$bayesian->id, 'mod_bayesian', 'slot']);

    $currententry = get_question_bank_entry($questionid);

    if (array_key_exists($currententry->id, $questionslots)) {
        $trans->allow_commit();
        return false;
    }

    $sql = "SELECT slot.slot, slot.page, slot.id
              FROM {bayesian_slots} slot
             WHERE slot.bayesianid = ?
          ORDER BY slot.slot";

    $slots = $DB->get_records_sql($sql, [$bayesian->id]);

    $maxpage = 1;
    $numonlastpage = 0;
    foreach ($slots as $slot) {
        if ($slot->page > $maxpage) {
            $maxpage = $slot->page;
            $numonlastpage = 1;
        } else {
            $numonlastpage += 1;
        }
    }

    // Add the new instance.
    $slot = new stdClass();
    $slot->bayesianid = $bayesian->id;

    if ($maxmark !== null) {
        $slot->maxmark = $maxmark;
    } else {
        $slot->maxmark = $DB->get_field('question', 'defaultmark', array('id' => $questionid));
    }

    if (is_int($page) && $page >= 1) {
        // Adding on a given page.
        $lastslotbefore = 0;
        foreach (array_reverse($slots) as $otherslot) {
            if ($otherslot->page > $page) {
                $DB->set_field('bayesian_slots', 'slot', $otherslot->slot + 1, array('id' => $otherslot->id));
            } else {
                $lastslotbefore = $otherslot->slot;
                break;
            }
        }
        $slot->slot = $lastslotbefore + 1;
        $slot->page = min($page, $maxpage + 1);

        bayesian_update_section_firstslots($bayesian->id, 1, max($lastslotbefore, 1));

    } else {
        $lastslot = end($slots);
        if ($lastslot) {
            $slot->slot = $lastslot->slot + 1;
        } else {
            $slot->slot = 1;
        }
        if ($bayesian->questionsperpage && $numonlastpage >= $bayesian->questionsperpage) {
            $slot->page = $maxpage + 1;
        } else {
            $slot->page = $maxpage;
        }
    }

    $slotid = $DB->insert_record('bayesian_slots', $slot);

    // Update or insert record in question_reference table.
    $sql = "SELECT DISTINCT qr.id, qr.itemid
              FROM {question} q
              JOIN {question_versions} qv ON q.id = qv.questionid
              JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              JOIN {question_references} qr ON qbe.id = qr.questionbankentryid AND qr.version = qv.version
              JOIN {bayesian_slots} qs ON qs.id = qr.itemid
             WHERE q.id = ?
               AND qs.id = ?
               AND qr.component = ?
               AND qr.questionarea = ?";
    $qreferenceitem = $DB->get_record_sql($sql, [$questionid, $slotid, 'mod_bayesian', 'slot']);

    if (!$qreferenceitem) {
        // Create a new reference record for questions created already.
        $questionreferences = new \StdClass();
        $questionreferences->usingcontextid = context_module::instance($bayesian->cmid)->id;
        $questionreferences->component = 'mod_bayesian';
        $questionreferences->questionarea = 'slot';
        $questionreferences->itemid = $slotid;
        $questionreferences->questionbankentryid = get_question_bank_entry($questionid)->id;
        $questionreferences->version = null; // Always latest.
        $DB->insert_record('question_references', $questionreferences);

    } else if ($qreferenceitem->itemid === 0 || $qreferenceitem->itemid === null) {
        $questionreferences = new \StdClass();
        $questionreferences->id = $qreferenceitem->id;
        $questionreferences->itemid = $slotid;
        $DB->update_record('question_references', $questionreferences);
    } else {
        // If the reference record exits for another bayesian.
        $questionreferences = new \StdClass();
        $questionreferences->usingcontextid = context_module::instance($bayesian->cmid)->id;
        $questionreferences->component = 'mod_bayesian';
        $questionreferences->questionarea = 'slot';
        $questionreferences->itemid = $slotid;
        $questionreferences->questionbankentryid = get_question_bank_entry($questionid)->id;
        $questionreferences->version = null; // Always latest.
        $DB->insert_record('question_references', $questionreferences);
    }

    $trans->allow_commit();

    // Log slot created event.
    $cm = get_coursemodule_from_instance('bayesian', $bayesian->id);
    $event = \mod_bayesian\event\slot_created::create([
        'context' => context_module::instance($cm->id),
        'objectid' => $slotid,
        'other' => [
            'bayesianid' => $bayesian->id,
            'slotnumber' => $slot->slot,
            'page' => $slot->page
        ]
    ]);
    $event->trigger();
}

/**
 * Move all the section headings in a certain slot range by a certain offset.
 *
 * @param int $bayesianid the id of a bayesian
 * @param int $direction amount to adjust section heading positions. Normally +1 or -1.
 * @param int $afterslot adjust headings that start after this slot.
 * @param int|null $beforeslot optionally, only adjust headings before this slot.
 */
function bayesian_update_section_firstslots($bayesianid, $direction, $afterslot, $beforeslot = null) {
    global $DB;
    $where = 'bayesianid = ? AND firstslot > ?';
    $params = [$direction, $bayesianid, $afterslot];
    if ($beforeslot) {
        $where .= ' AND firstslot < ?';
        $params[] = $beforeslot;
    }
    $firstslotschanges = $DB->get_records_select_menu('bayesian_sections',
            $where, $params, '', 'firstslot, firstslot + ?');
    update_field_with_unique_index('bayesian_sections', 'firstslot', $firstslotschanges, ['bayesianid' => $bayesianid]);
}

/**
 * Add a random question to the bayesian at a given point.
 * @param stdClass $bayesian the bayesian settings.
 * @param int $addonpage the page on which to add the question.
 * @param int $categoryid the question category to add the question from.
 * @param int $number the number of random questions to add.
 * @param bool $includesubcategories whether to include questoins from subcategories.
 * @param int[] $tagids Array of tagids. The question that will be picked randomly should be tagged with all these tags.
 */
function bayesian_add_random_questions($bayesian, $addonpage, $categoryid, $number,
        $includesubcategories, $tagids = []) {
    global $DB;

    $category = $DB->get_record('question_categories', ['id' => $categoryid]);
    if (!$category) {
        new moodle_exception('invalidcategoryid');
    }

    $catcontext = context::instance_by_id($category->contextid);
    require_capability('moodle/question:useall', $catcontext);

    // Tags for filter condition.
    $tags = \core_tag_tag::get_bulk($tagids, 'id, name');
    $tagstrings = [];
    foreach ($tags as $tag) {
        $tagstrings[] = "{$tag->id},{$tag->name}";
    }
    // Create the selected number of random questions.
    for ($i = 0; $i < $number; $i++) {
        // Set the filter conditions.
        $filtercondition = new stdClass();
        $filtercondition->questioncategoryid = $categoryid;
        $filtercondition->includingsubcategories = $includesubcategories ? 1 : 0;
        if (!empty($tagstrings)) {
            $filtercondition->tags = $tagstrings;
        }

        if (!isset($bayesian->cmid)) {
            $cm = get_coursemodule_from_instance('bayesian', $bayesian->id, $bayesian->course);
            $bayesian->cmid = $cm->id;
        }

        // Slot data.
        $randomslotdata = new stdClass();
        $randomslotdata->bayesianid = $bayesian->id;
        $randomslotdata->usingcontextid = context_module::instance($bayesian->cmid)->id;
        $randomslotdata->questionscontextid = $category->contextid;
        $randomslotdata->maxmark = 1;

        $randomslot = new \mod_bayesian\local\structure\slot_random($randomslotdata);
        $randomslot->set_bayesian($bayesian);
        $randomslot->set_filter_condition($filtercondition);
        $randomslot->insert($addonpage);
    }
}

/**
 * Mark the activity completed (if required) and trigger the course_module_viewed event.
 *
 * @param  stdClass $bayesian       bayesian object
 * @param  stdClass $course     course object
 * @param  stdClass $cm         course module object
 * @param  stdClass $context    context object
 * @since Moodle 3.1
 */
function bayesian_view($bayesian, $course, $cm, $context) {

    $params = array(
        'objectid' => $bayesian->id,
        'context' => $context
    );

    $event = \mod_bayesian\event\course_module_viewed::create($params);
    $event->add_record_snapshot('bayesian', $bayesian);
    $event->trigger();

    // Completion.
    $completion = new completion_info($course);
    $completion->set_module_viewed($cm);
}

/**
 * Validate permissions for creating a new attempt and start a new preview attempt if required.
 *
 * @param  bayesian $bayesianobj bayesian object
 * @param  bayesian_access_manager $accessmanager bayesian access manager
 * @param  bool $forcenew whether was required to start a new preview attempt
 * @param  int $page page to jump to in the attempt
 * @param  bool $redirect whether to redirect or throw exceptions (for web or ws usage)
 * @return array an array containing the attempt information, access error messages and the page to jump to in the attempt
 * @throws moodle_bayesian_exception
 * @since Moodle 3.1
 */
function bayesian_validate_new_attempt(bayesian $bayesianobj, bayesian_access_manager $accessmanager, $forcenew, $page, $redirect) {
    global $DB, $USER;
    $timenow = time();

    if ($bayesianobj->is_preview_user() && $forcenew) {
        $accessmanager->current_attempt_finished();
    }

    // Check capabilities.
    if (!$bayesianobj->is_preview_user()) {
        $bayesianobj->require_capability('mod/bayesian:attempt');
    }

    // Check to see if a new preview was requested.
    if ($bayesianobj->is_preview_user() && $forcenew) {
        // To force the creation of a new preview, we mark the current attempt (if any)
        // as abandoned. It will then automatically be deleted below.
        $DB->set_field('bayesian_attempts', 'state', bayesian_attempt::ABANDONED,
                array('bayesian' => $bayesianobj->get_bayesianid(), 'userid' => $USER->id));
    }

    // Look for an existing attempt.
    $attempts = bayesian_get_user_attempts($bayesianobj->get_bayesianid(), $USER->id, 'all', true);
    $lastattempt = end($attempts);

    $attemptnumber = null;
    // If an in-progress attempt exists, check password then redirect to it.
    if ($lastattempt && ($lastattempt->state == bayesian_attempt::IN_PROGRESS ||
            $lastattempt->state == bayesian_attempt::OVERDUE)) {
        $currentattemptid = $lastattempt->id;
        $messages = $accessmanager->prevent_access();

        // If the attempt is now overdue, deal with that.
        $bayesianobj->create_attempt_object($lastattempt)->handle_if_time_expired($timenow, true);

        // And, if the attempt is now no longer in progress, redirect to the appropriate place.
        if ($lastattempt->state == bayesian_attempt::ABANDONED || $lastattempt->state == bayesian_attempt::FINISHED) {
            if ($redirect) {
                redirect($bayesianobj->review_url($lastattempt->id));
            } else {
                throw new moodle_bayesian_exception($bayesianobj, 'attemptalreadyclosed');
            }
        }

        // If the page number was not explicitly in the URL, go to the current page.
        if ($page == -1) {
            $page = $lastattempt->currentpage;
        }

    } else {
        while ($lastattempt && $lastattempt->preview) {
            $lastattempt = array_pop($attempts);
        }

        // Get number for the next or unfinished attempt.
        if ($lastattempt) {
            $attemptnumber = $lastattempt->attempt + 1;
        } else {
            $lastattempt = false;
            $attemptnumber = 1;
        }
        $currentattemptid = null;

        $messages = $accessmanager->prevent_access() +
            $accessmanager->prevent_new_attempt(count($attempts), $lastattempt);

        if ($page == -1) {
            $page = 0;
        }
    }
    return array($currentattemptid, $attemptnumber, $lastattempt, $messages, $page);
}

/**
 * Prepare and start a new attempt deleting the previous preview attempts.
 *
 * @param bayesian $bayesianobj bayesian object
 * @param int $attemptnumber the attempt number
 * @param object $lastattempt last attempt object
 * @param bool $offlineattempt whether is an offline attempt or not
 * @param array $forcedrandomquestions slot number => question id. Used for random questions,
 *      to force the choice of a particular actual question. Intended for testing purposes only.
 * @param array $forcedvariants slot number => variant. Used for questions with variants,
 *      to force the choice of a particular variant. Intended for testing purposes only.
 * @param int $userid Specific user id to create an attempt for that user, null for current logged in user
 * @return object the new attempt
 * @since  Moodle 3.1
 */
function bayesian_prepare_and_start_new_attempt(bayesian $bayesianobj, $attemptnumber, $lastattempt,
        $offlineattempt = false, $forcedrandomquestions = [], $forcedvariants = [], $userid = null) {
    global $DB, $USER;

    if ($userid === null) {
        $userid = $USER->id;
        $ispreviewuser = $bayesianobj->is_preview_user();
    } else {
        $ispreviewuser = has_capability('mod/bayesian:preview', $bayesianobj->get_context(), $userid);
    }
    // Delete any previous preview attempts belonging to this user.
    bayesian_delete_previews($bayesianobj->get_bayesian(), $userid);

    $quba = question_engine::make_questions_usage_by_activity('mod_bayesian', $bayesianobj->get_context());
    $quba->set_preferred_behaviour($bayesianobj->get_bayesian()->preferredbehaviour);

    // Create the new attempt and initialize the question sessions
    $timenow = time(); // Update time now, in case the server is running really slowly.
    $attempt = bayesian_create_attempt($bayesianobj, $attemptnumber, $lastattempt, $timenow, $ispreviewuser, $userid);

    if (!($bayesianobj->get_bayesian()->attemptonlast && $lastattempt)) {
        $attempt = bayesian_start_new_attempt($bayesianobj, $quba, $attempt, $attemptnumber, $timenow,
                $forcedrandomquestions, $forcedvariants);
    } else {
        $attempt = bayesian_start_attempt_built_on_last($quba, $attempt, $lastattempt);
    }

    $transaction = $DB->start_delegated_transaction();

    // Init the timemodifiedoffline for offline attempts.
    if ($offlineattempt) {
        $attempt->timemodifiedoffline = $attempt->timemodified;
    }
    $attempt = bayesian_attempt_save_started($bayesianobj, $quba, $attempt);

    $transaction->allow_commit();

    return $attempt;
}

/**
 * Check if the given calendar_event is either a user or group override
 * event for bayesian.
 *
 * @param calendar_event $event The calendar event to check
 * @return bool
 */
function bayesian_is_overriden_calendar_event(\calendar_event $event) {
    global $DB;

    if (!isset($event->modulename)) {
        return false;
    }

    if ($event->modulename != 'bayesian') {
        return false;
    }

    if (!isset($event->instance)) {
        return false;
    }

    if (!isset($event->userid) && !isset($event->groupid)) {
        return false;
    }

    $overrideparams = [
        'bayesian' => $event->instance
    ];

    if (isset($event->groupid)) {
        $overrideparams['groupid'] = $event->groupid;
    } else if (isset($event->userid)) {
        $overrideparams['userid'] = $event->userid;
    }

    return $DB->record_exists('bayesian_overrides', $overrideparams);
}

/**
 * Retrieves tag information for the given list of bayesian slot ids.
 * Currently the only slots that have tags are random question slots.
 *
 * Example:
 * If we have 3 slots with id 1, 2, and 3. The first slot has two tags, the second
 * has one tag, and the third has zero tags. The return structure will look like:
 * [
 *      1 => [
 *          bayesian_slot_tags.id => { ...tag data... },
 *          bayesian_slot_tags.id => { ...tag data... },
 *      ],
 *      2 => [
 *          bayesian_slot_tags.id => { ...tag data... },
 *      ],
 *      3 => [],
 * ]
 *
 * @param int[] $slotids The list of id for the bayesian slots.
 * @return array[] List of bayesian_slot_tags records indexed by slot id.
 * @deprecated since Moodle 4.0
 * @todo Final deprecation on Moodle 4.4 MDL-72438
 */
function bayesian_retrieve_tags_for_slot_ids($slotids) {
    debugging('Method bayesian_retrieve_tags_for_slot_ids() is deprecated, ' .
        'see filtercondition->tags from the question_set_reference table.', DEBUG_DEVELOPER);
    global $DB;
    if (empty($slotids)) {
        return [];
    }

    $slottags = $DB->get_records_list('bayesian_slot_tags', 'slotid', $slotids);
    $tagsbyid = core_tag_tag::get_bulk(array_filter(array_column($slottags, 'tagid')), 'id, name');
    $tagsbyname = false; // It will be loaded later if required.
    $emptytagids = array_reduce($slotids, function($carry, $slotid) {
        $carry[$slotid] = [];
        return $carry;
    }, []);

    return array_reduce(
        $slottags,
        function($carry, $slottag) use ($slottags, $tagsbyid, $tagsbyname) {
            if (isset($tagsbyid[$slottag->tagid])) {
                // Make sure that we're returning the most updated tag name.
                $slottag->tagname = $tagsbyid[$slottag->tagid]->name;
            } else {
                if ($tagsbyname === false) {
                    // We were hoping that this query could be avoided, but life
                    // showed its other side to us!
                    $tagcollid = core_tag_area::get_collection('core', 'question');
                    $tagsbyname = core_tag_tag::get_by_name_bulk(
                        $tagcollid,
                        array_column($slottags, 'tagname'),
                        'id, name'
                    );
                }
                if (isset($tagsbyname[$slottag->tagname])) {
                    // Make sure that we're returning the current tag id that matches
                    // the given tag name.
                    $slottag->tagid = $tagsbyname[$slottag->tagname]->id;
                } else {
                    // The tag does not exist anymore (neither the tag id nor the tag name
                    // matches an existing tag).
                    // We still need to include this row in the result as some callers might
                    // be interested in these rows. An example is the editing forms that still
                    // need to display tag names even if they don't exist anymore.
                    $slottag->tagid = null;
                }
            }

            $carry[$slottag->slotid][$slottag->id] = $slottag;
            return $carry;
        },
        $emptytagids
    );
}

/**
 * Get bayesian attempt and handling error.
 *
 * @param int $attemptid the id of the current attempt.
 * @param int|null $cmid the course_module id for this bayesian.
 * @return bayesian_attempt $attemptobj all the data about the bayesian attempt.
 * @throws moodle_exception
 */
function bayesian_create_attempt_handling_errors($attemptid, $cmid = null) {
    try {
        $attempobj = bayesian_attempt::create($attemptid);
    } catch (moodle_exception $e) {
        if (!empty($cmid)) {
            list($course, $cm) = get_course_and_cm_from_cmid($cmid, 'bayesian');
            $continuelink = new moodle_url('/mod/bayesian/view.php', array('id' => $cmid));
            $context = context_module::instance($cm->id);
            if (has_capability('mod/bayesian:preview', $context)) {
                throw new moodle_exception('attempterrorcontentchange', 'bayesian', $continuelink);
            } else {
                throw new moodle_exception('attempterrorcontentchangeforuser', 'bayesian', $continuelink);
            }
        } else {
            throw new moodle_exception('attempterrorinvalid', 'bayesian');
        }
    }
    if (!empty($cmid) && $attempobj->get_cmid() != $cmid) {
        throw new moodle_exception('invalidcoursemodule');
    } else {
        return $attempobj;
    }
}
