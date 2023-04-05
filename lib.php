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
 * Library of functions for the bayesian module.
 *
 * This contains functions that are called also from outside the bayesian module
 * Functions that are only called by the bayesian module itself are in {@link locallib.php}
 *
 * @package    mod_bayesian
 * @copyright  1999 onwards Martin Dougiamas {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

use mod_bayesian\question\bank\custom_view;
use core_question\statistics\questions\all_calculated_for_qubaid_condition;

require_once($CFG->dirroot . '/calendar/lib.php');


/**#@+
 * Option controlling what options are offered on the bayesian settings form.
 */
define('bayesian_MAX_ATTEMPT_OPTION', 10);
define('bayesian_MAX_QPP_OPTION', 50);
define('bayesian_MAX_DECIMAL_OPTION', 5);
define('bayesian_MAX_Q_DECIMAL_OPTION', 7);
/**#@-*/

/**#@+
 * Options determining how the grades from individual attempts are combined to give
 * the overall grade for a user
 */
define('bayesian_GRADEHIGHEST', '1');
define('bayesian_GRADEAVERAGE', '2');
define('bayesian_ATTEMPTFIRST', '3');
define('bayesian_ATTEMPTLAST',  '4');
/**#@-*/

/**
 * @var int If start and end date for the bayesian are more than this many seconds apart
 * they will be represented by two separate events in the calendar
 */
define('bayesian_MAX_EVENT_LENGTH', 5*24*60*60); // 5 days.

/**#@+
 * Options for navigation method within bayesianzes.
 */
define('bayesian_NAVMETHOD_FREE', 'free');
define('bayesian_NAVMETHOD_SEQ',  'sequential');
/**#@-*/

/**
 * Event types.
 */
define('bayesian_EVENT_TYPE_OPEN', 'open');
define('bayesian_EVENT_TYPE_CLOSE', 'close');

require_once(__DIR__ . '/deprecatedlib.php');

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param object $bayesian the data that came from the form.
 * @return mixed the id of the new instance on success,
 *          false or a string error message on failure.
 */
function bayesian_add_instance($bayesian) {
    global $DB;
    $cmid = $bayesian->coursemodule;

    // Process the options from the form.
    $bayesian->timecreated = time();
    $result = bayesian_process_options($bayesian);
    if ($result && is_string($result)) {
        return $result;
    }

    // Try to store it in the database.
    $bayesian->id = $DB->insert_record('bayesian', $bayesian);

    // Create the first section for this bayesian.
    $DB->insert_record('bayesian_sections', array('bayesianid' => $bayesian->id,
            'firstslot' => 1, 'heading' => '', 'shufflequestions' => 0));

    // Do the processing required after an add or an update.
    bayesian_after_add_or_update($bayesian);

    return $bayesian->id;
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $bayesian the data that came from the form.
 * @return mixed true on success, false or a string error message on failure.
 */
function bayesian_update_instance($bayesian, $mform) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');

    // Process the options from the form.
    $result = bayesian_process_options($bayesian);
    if ($result && is_string($result)) {
        return $result;
    }

    // Get the current value, so we can see what changed.
    $oldbayesian = $DB->get_record('bayesian', array('id' => $bayesian->instance));

    // We need two values from the existing DB record that are not in the form,
    // in some of the function calls below.
    $bayesian->sumgrades = $oldbayesian->sumgrades;
    $bayesian->grade     = $oldbayesian->grade;

    // Update the database.
    $bayesian->id = $bayesian->instance;
    $DB->update_record('bayesian', $bayesian);

    // Do the processing required after an add or an update.
    bayesian_after_add_or_update($bayesian);

    if ($oldbayesian->grademethod != $bayesian->grademethod) {
        bayesian_update_all_final_grades($bayesian);
        bayesian_update_grades($bayesian);
    }

    $bayesiandateschanged = $oldbayesian->timelimit   != $bayesian->timelimit
                     || $oldbayesian->timeclose   != $bayesian->timeclose
                     || $oldbayesian->graceperiod != $bayesian->graceperiod;
    if ($bayesiandateschanged) {
        bayesian_update_open_attempts(array('bayesianid' => $bayesian->id));
    }

    // Delete any previous preview attempts.
    bayesian_delete_previews($bayesian);

    // Repaginate, if asked to.
    if (!empty($bayesian->repaginatenow) && !bayesian_has_attempts($bayesian->id)) {
        bayesian_repaginate_questions($bayesian->id, $bayesian->questionsperpage);
    }

    return true;
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id the id of the bayesian to delete.
 * @return bool success or failure.
 */
function bayesian_delete_instance($id) {
    global $DB;

    $bayesian = $DB->get_record('bayesian', array('id' => $id), '*', MUST_EXIST);

    bayesian_delete_all_attempts($bayesian);
    bayesian_delete_all_overrides($bayesian);
    bayesian_delete_references($bayesian->id);

    // We need to do the following deletes before we try and delete randoms, otherwise they would still be 'in use'.
    $DB->delete_records('bayesian_slots', array('bayesianid' => $bayesian->id));
    $DB->delete_records('bayesian_sections', array('bayesianid' => $bayesian->id));

    $DB->delete_records('bayesian_feedback', array('bayesianid' => $bayesian->id));

    bayesian_access_manager::delete_settings($bayesian);

    $events = $DB->get_records('event', array('modulename' => 'bayesian', 'instance' => $bayesian->id));
    foreach ($events as $event) {
        $event = calendar_event::load($event);
        $event->delete();
    }

    bayesian_grade_item_delete($bayesian);
    // We must delete the module record after we delete the grade item.
    $DB->delete_records('bayesian', array('id' => $bayesian->id));

    return true;
}

/**
 * Deletes a bayesian override from the database and clears any corresponding calendar events
 *
 * @param object $bayesian The bayesian object.
 * @param int $overrideid The id of the override being deleted
 * @param bool $log Whether to trigger logs.
 * @return bool true on success
 */
function bayesian_delete_override($bayesian, $overrideid, $log = true) {
    global $DB;

    if (!isset($bayesian->cmid)) {
        $cm = get_coursemodule_from_instance('bayesian', $bayesian->id, $bayesian->course);
        $bayesian->cmid = $cm->id;
    }

    $override = $DB->get_record('bayesian_overrides', array('id' => $overrideid), '*', MUST_EXIST);

    // Delete the events.
    if (isset($override->groupid)) {
        // Create the search array for a group override.
        $eventsearcharray = array('modulename' => 'bayesian',
            'instance' => $bayesian->id, 'groupid' => (int)$override->groupid);
        $cachekey = "{$bayesian->id}_g_{$override->groupid}";
    } else {
        // Create the search array for a user override.
        $eventsearcharray = array('modulename' => 'bayesian',
            'instance' => $bayesian->id, 'userid' => (int)$override->userid);
        $cachekey = "{$bayesian->id}_u_{$override->userid}";
    }
    $events = $DB->get_records('event', $eventsearcharray);
    foreach ($events as $event) {
        $eventold = calendar_event::load($event);
        $eventold->delete();
    }

    $DB->delete_records('bayesian_overrides', array('id' => $overrideid));
    cache::make('mod_bayesian', 'overrides')->delete($cachekey);

    if ($log) {
        // Set the common parameters for one of the events we will be triggering.
        $params = array(
            'objectid' => $override->id,
            'context' => context_module::instance($bayesian->cmid),
            'other' => array(
                'bayesianid' => $override->bayesian
            )
        );
        // Determine which override deleted event to fire.
        if (!empty($override->userid)) {
            $params['relateduserid'] = $override->userid;
            $event = \mod_bayesian\event\user_override_deleted::create($params);
        } else {
            $params['other']['groupid'] = $override->groupid;
            $event = \mod_bayesian\event\group_override_deleted::create($params);
        }

        // Trigger the override deleted event.
        $event->add_record_snapshot('bayesian_overrides', $override);
        $event->trigger();
    }

    return true;
}

/**
 * Deletes all bayesian overrides from the database and clears any corresponding calendar events
 *
 * @param object $bayesian The bayesian object.
 * @param bool $log Whether to trigger logs.
 */
function bayesian_delete_all_overrides($bayesian, $log = true) {
    global $DB;

    $overrides = $DB->get_records('bayesian_overrides', array('bayesian' => $bayesian->id), 'id');
    foreach ($overrides as $override) {
        bayesian_delete_override($bayesian, $override->id, $log);
    }
}

/**
 * Updates a bayesian object with override information for a user.
 *
 * Algorithm:  For each bayesian setting, if there is a matching user-specific override,
 *   then use that otherwise, if there are group-specific overrides, return the most
 *   lenient combination of them.  If neither applies, leave the bayesian setting unchanged.
 *
 *   Special case: if there is more than one password that applies to the user, then
 *   bayesian->extrapasswords will contain an array of strings giving the remaining
 *   passwords.
 *
 * @param object $bayesian The bayesian object.
 * @param int $userid The userid.
 * @return object $bayesian The updated bayesian object.
 */
function bayesian_update_effective_access($bayesian, $userid) {
    global $DB;

    // Check for user override.
    $override = $DB->get_record('bayesian_overrides', array('bayesian' => $bayesian->id, 'userid' => $userid));

    if (!$override) {
        $override = new stdClass();
        $override->timeopen = null;
        $override->timeclose = null;
        $override->timelimit = null;
        $override->attempts = null;
        $override->password = null;
    }

    // Check for group overrides.
    $groupings = groups_get_user_groups($bayesian->course, $userid);

    if (!empty($groupings[0])) {
        // Select all overrides that apply to the User's groups.
        list($extra, $params) = $DB->get_in_or_equal(array_values($groupings[0]));
        $sql = "SELECT * FROM {bayesian_overrides}
                WHERE groupid $extra AND bayesian = ?";
        $params[] = $bayesian->id;
        $records = $DB->get_records_sql($sql, $params);

        // Combine the overrides.
        $opens = array();
        $closes = array();
        $limits = array();
        $attempts = array();
        $passwords = array();

        foreach ($records as $gpoverride) {
            if (isset($gpoverride->timeopen)) {
                $opens[] = $gpoverride->timeopen;
            }
            if (isset($gpoverride->timeclose)) {
                $closes[] = $gpoverride->timeclose;
            }
            if (isset($gpoverride->timelimit)) {
                $limits[] = $gpoverride->timelimit;
            }
            if (isset($gpoverride->attempts)) {
                $attempts[] = $gpoverride->attempts;
            }
            if (isset($gpoverride->password)) {
                $passwords[] = $gpoverride->password;
            }
        }
        // If there is a user override for a setting, ignore the group override.
        if (is_null($override->timeopen) && count($opens)) {
            $override->timeopen = min($opens);
        }
        if (is_null($override->timeclose) && count($closes)) {
            if (in_array(0, $closes)) {
                $override->timeclose = 0;
            } else {
                $override->timeclose = max($closes);
            }
        }
        if (is_null($override->timelimit) && count($limits)) {
            if (in_array(0, $limits)) {
                $override->timelimit = 0;
            } else {
                $override->timelimit = max($limits);
            }
        }
        if (is_null($override->attempts) && count($attempts)) {
            if (in_array(0, $attempts)) {
                $override->attempts = 0;
            } else {
                $override->attempts = max($attempts);
            }
        }
        if (is_null($override->password) && count($passwords)) {
            $override->password = array_shift($passwords);
            if (count($passwords)) {
                $override->extrapasswords = $passwords;
            }
        }

    }

    // Merge with bayesian defaults.
    $keys = array('timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'extrapasswords');
    foreach ($keys as $key) {
        if (isset($override->{$key})) {
            $bayesian->{$key} = $override->{$key};
        }
    }

    return $bayesian;
}

/**
 * Delete all the attempts belonging to a bayesian.
 *
 * @param object $bayesian The bayesian object.
 */
function bayesian_delete_all_attempts($bayesian) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');
    question_engine::delete_questions_usage_by_activities(new qubaids_for_bayesian($bayesian->id));
    $DB->delete_records('bayesian_attempts', array('bayesian' => $bayesian->id));
    $DB->delete_records('bayesian_grades', array('bayesian' => $bayesian->id));
}

/**
 * Delete all the attempts belonging to a user in a particular bayesian.
 *
 * @param object $bayesian The bayesian object.
 * @param object $user The user object.
 */
function bayesian_delete_user_attempts($bayesian, $user) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');
    question_engine::delete_questions_usage_by_activities(new qubaids_for_bayesian_user($bayesian->get_bayesianid(), $user->id));
    $params = [
        'bayesian' => $bayesian->get_bayesianid(),
        'userid' => $user->id,
    ];
    $DB->delete_records('bayesian_attempts', $params);
    $DB->delete_records('bayesian_grades', $params);
}

/**
 * Get the best current grade for a particular user in a bayesian.
 *
 * @param object $bayesian the bayesian settings.
 * @param int $userid the id of the user.
 * @return float the user's current grade for this bayesian, or null if this user does
 * not have a grade on this bayesian.
 */
function bayesian_get_best_grade($bayesian, $userid) {
    global $DB;
    $grade = $DB->get_field('bayesian_grades', 'grade',
            array('bayesian' => $bayesian->id, 'userid' => $userid));

    // Need to detect errors/no result, without catching 0 grades.
    if ($grade === false) {
        return null;
    }

    return $grade + 0; // Convert to number.
}

/**
 * Is this a graded bayesian? If this method returns true, you can assume that
 * $bayesian->grade and $bayesian->sumgrades are non-zero (for example, if you want to
 * divide by them).
 *
 * @param object $bayesian a row from the bayesian table.
 * @return bool whether this is a graded bayesian.
 */
function bayesian_has_grades($bayesian) {
    return $bayesian->grade >= 0.000005 && $bayesian->sumgrades >= 0.000005;
}

/**
 * Does this bayesian allow multiple tries?
 *
 * @return bool
 */
function bayesian_allows_multiple_tries($bayesian) {
    $bt = question_engine::get_behaviour_type($bayesian->preferredbehaviour);
    return $bt->allows_multiple_submitted_responses();
}

/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $bayesian
 * @return object|null
 */
function bayesian_user_outline($course, $user, $mod, $bayesian) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $grades = grade_get_grades($course->id, 'mod', 'bayesian', $bayesian->id, $user->id);

    if (empty($grades->items[0]->grades)) {
        return null;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $result = new stdClass();
    // If the user can't see hidden grades, don't return that information.
    $gitem = grade_item::fetch(array('id' => $grades->items[0]->id));
    if (!$gitem->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
        $result->info = get_string('gradenoun') . ': ' . $grade->str_long_grade;
    } else {
        $result->info = get_string('gradenoun') . ': ' . get_string('hidden', 'grades');
    }

    $result->time = grade_get_date_for_user_grade($grade, $user);

    return $result;
}

/**
 * Print a detailed representation of what a  user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $bayesian
 * @return bool
 */
function bayesian_user_complete($course, $user, $mod, $bayesian) {
    global $DB, $CFG, $OUTPUT;
    require_once($CFG->libdir . '/gradelib.php');
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');

    $grades = grade_get_grades($course->id, 'mod', 'bayesian', $bayesian->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        // If the user can't see hidden grades, don't return that information.
        $gitem = grade_item::fetch(array('id' => $grades->items[0]->id));
        if (!$gitem->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
            echo $OUTPUT->container(get_string('gradenoun').': '.$grade->str_long_grade);
            if ($grade->str_feedback) {
                echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
            }
        } else {
            echo $OUTPUT->container(get_string('gradenoun') . ': ' . get_string('hidden', 'grades'));
            if ($grade->str_feedback) {
                echo $OUTPUT->container(get_string('feedback').': '.get_string('hidden', 'grades'));
            }
        }
    }

    if ($attempts = $DB->get_records('bayesian_attempts',
            array('userid' => $user->id, 'bayesian' => $bayesian->id), 'attempt')) {
        foreach ($attempts as $attempt) {
            echo get_string('attempt', 'bayesian', $attempt->attempt) . ': ';
            if ($attempt->state != bayesian_attempt::FINISHED) {
                echo bayesian_attempt_state_name($attempt->state);
            } else {
                if (!isset($gitem)) {
                    if (!empty($grades->items[0]->grades)) {
                        $gitem = grade_item::fetch(array('id' => $grades->items[0]->id));
                    } else {
                        $gitem = new stdClass();
                        $gitem->hidden = true;
                    }
                }
                if (!$gitem->hidden || has_capability('moodle/grade:viewhidden', context_course::instance($course->id))) {
                    echo bayesian_format_grade($bayesian, $attempt->sumgrades) . '/' . bayesian_format_grade($bayesian, $bayesian->sumgrades);
                } else {
                    echo get_string('hidden', 'grades');
                }
                echo ' - '.userdate($attempt->timefinish).'<br />';
            }
        }
    } else {
        print_string('noattempts', 'bayesian');
    }

    return true;
}


/**
 * @param int|array $bayesianids A bayesian ID, or an array of bayesian IDs.
 * @param int $userid the userid.
 * @param string $status 'all', 'finished' or 'unfinished' to control
 * @param bool $includepreviews
 * @return array of all the user's attempts at this bayesian. Returns an empty
 *      array if there are none.
 */
function bayesian_get_user_attempts($bayesianids, $userid, $status = 'finished', $includepreviews = false) {
    global $DB, $CFG;
    // TODO MDL-33071 it is very annoying to have to included all of locallib.php
    // just to get the bayesian_attempt::FINISHED constants, but I will try to sort
    // that out properly for Moodle 2.4. For now, I will just do a quick fix for
    // MDL-33048.
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');

    $params = array();
    switch ($status) {
        case 'all':
            $statuscondition = '';
            break;

        case 'finished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = bayesian_attempt::FINISHED;
            $params['state2'] = bayesian_attempt::ABANDONED;
            break;

        case 'unfinished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = bayesian_attempt::IN_PROGRESS;
            $params['state2'] = bayesian_attempt::OVERDUE;
            break;
    }

    $bayesianids = (array) $bayesianids;
    list($insql, $inparams) = $DB->get_in_or_equal($bayesianids, SQL_PARAMS_NAMED);
    $params += $inparams;
    $params['userid'] = $userid;

    $previewclause = '';
    if (!$includepreviews) {
        $previewclause = ' AND preview = 0';
    }

    return $DB->get_records_select('bayesian_attempts',
            "bayesian $insql AND userid = :userid" . $previewclause . $statuscondition,
            $params, 'bayesian, attempt ASC');
}

/**
 * Return grade for given user or all users.
 *
 * @param int $bayesianid id of bayesian
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none. These are raw grades. They should
 * be processed with bayesian_format_grade for display.
 */
function bayesian_get_user_grades($bayesian, $userid = 0) {
    global $CFG, $DB;

    $params = array($bayesian->id);
    $usertest = '';
    if ($userid) {
        $params[] = $userid;
        $usertest = 'AND u.id = ?';
    }
    return $DB->get_records_sql("
            SELECT
                u.id,
                u.id AS userid,
                qg.grade AS rawgrade,
                qg.timemodified AS dategraded,
                MAX(qa.timefinish) AS datesubmitted

            FROM {user} u
            JOIN {bayesian_grades} qg ON u.id = qg.userid
            JOIN {bayesian_attempts} qa ON qa.bayesian = qg.bayesian AND qa.userid = u.id

            WHERE qg.bayesian = ?
            $usertest
            GROUP BY u.id, qg.grade, qg.timemodified", $params);
}

/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $bayesian The bayesian table row, only $bayesian->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function bayesian_format_grade($bayesian, $grade) {
    if (is_null($grade)) {
        return get_string('notyetgraded', 'bayesian');
    }
    return format_float($grade, $bayesian->decimalpoints);
}

/**
 * Determine the correct number of decimal places required to format a grade.
 *
 * @param object $bayesian The bayesian table row, only $bayesian->decimalpoints is used.
 * @return integer
 */
function bayesian_get_grade_format($bayesian) {
    if (empty($bayesian->questiondecimalpoints)) {
        $bayesian->questiondecimalpoints = -1;
    }

    if ($bayesian->questiondecimalpoints == -1) {
        return $bayesian->decimalpoints;
    }

    return $bayesian->questiondecimalpoints;
}

/**
 * Round a grade to the correct number of decimal places, and format it for display.
 *
 * @param object $bayesian The bayesian table row, only $bayesian->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function bayesian_format_question_grade($bayesian, $grade) {
    return format_float($grade, bayesian_get_grade_format($bayesian));
}

/**
 * Update grades in central gradebook
 *
 * @category grade
 * @param object $bayesian the bayesian settings.
 * @param int $userid specific user only, 0 means all users.
 * @param bool $nullifnone If a single user is specified and $nullifnone is true a grade item with a null rawgrade will be inserted
 */
function bayesian_update_grades($bayesian, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if ($bayesian->grade == 0) {
        bayesian_grade_item_update($bayesian);

    } else if ($grades = bayesian_get_user_grades($bayesian, $userid)) {
        bayesian_grade_item_update($bayesian, $grades);

    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        bayesian_grade_item_update($bayesian, $grade);

    } else {
        bayesian_grade_item_update($bayesian);
    }
}

/**
 * Create or update the grade item for given bayesian
 *
 * @category grade
 * @param object $bayesian object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function bayesian_grade_item_update($bayesian, $grades = null) {
    global $CFG, $OUTPUT;
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');
    require_once($CFG->libdir . '/gradelib.php');

    if (property_exists($bayesian, 'cmidnumber')) { // May not be always present.
        $params = array('itemname' => $bayesian->name, 'idnumber' => $bayesian->cmidnumber);
    } else {
        $params = array('itemname' => $bayesian->name);
    }

    if ($bayesian->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $bayesian->grade;
        $params['grademin']  = 0;

    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    // What this is trying to do:
    // 1. If the bayesian is set to not show grades while the bayesian is still open,
    //    and is set to show grades after the bayesian is closed, then create the
    //    grade_item with a show-after date that is the bayesian close date.
    // 2. If the bayesian is set to not show grades at either of those times,
    //    create the grade_item as hidden.
    // 3. If the bayesian is set to show grades, create the grade_item visible.
    $openreviewoptions = mod_bayesian_display_options::make_from_bayesian($bayesian,
            mod_bayesian_display_options::LATER_WHILE_OPEN);
    $closedreviewoptions = mod_bayesian_display_options::make_from_bayesian($bayesian,
            mod_bayesian_display_options::AFTER_CLOSE);
    if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks < question_display_options::MARK_AND_MAX) {
        $params['hidden'] = 1;

    } else if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks >= question_display_options::MARK_AND_MAX) {
        if ($bayesian->timeclose) {
            $params['hidden'] = $bayesian->timeclose;
        } else {
            $params['hidden'] = 1;
        }

    } else {
        // Either
        // a) both open and closed enabled
        // b) open enabled, closed disabled - we can not "hide after",
        //    grades are kept visible even after closing.
        $params['hidden'] = 0;
    }

    if (!$params['hidden']) {
        // If the grade item is not hidden by the bayesian logic, then we need to
        // hide it if the bayesian is hidden from students.
        if (property_exists($bayesian, 'visible')) {
            // Saving the bayesian form, and cm not yet updated in the database.
            $params['hidden'] = !$bayesian->visible;
        } else {
            $cm = get_coursemodule_from_instance('bayesian', $bayesian->id);
            $params['hidden'] = !$cm->visible;
        }
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    $gradebook_grades = grade_get_grades($bayesian->course, 'mod', 'bayesian', $bayesian->id);
    if (!empty($gradebook_grades->items)) {
        $grade_item = $gradebook_grades->items[0];
        if ($grade_item->locked) {
            // NOTE: this is an extremely nasty hack! It is not a bug if this confirmation fails badly. --skodak.
            $confirm_regrade = optional_param('confirm_regrade', 0, PARAM_INT);
            if (!$confirm_regrade) {
                if (!AJAX_SCRIPT) {
                    $message = get_string('gradeitemislocked', 'grades');
                    $back_link = $CFG->wwwroot . '/mod/bayesian/report.php?q=' . $bayesian->id .
                            '&amp;mode=overview';
                    $regrade_link = qualified_me() . '&amp;confirm_regrade=1';
                    echo $OUTPUT->box_start('generalbox', 'notice');
                    echo '<p>'. $message .'</p>';
                    echo $OUTPUT->container_start('buttons');
                    echo $OUTPUT->single_button($regrade_link, get_string('regradeanyway', 'grades'));
                    echo $OUTPUT->single_button($back_link,  get_string('cancel'));
                    echo $OUTPUT->container_end();
                    echo $OUTPUT->box_end();
                }
                return GRADE_UPDATE_ITEM_LOCKED;
            }
        }
    }

    return grade_update('mod/bayesian', $bayesian->course, 'mod', 'bayesian', $bayesian->id, 0, $grades, $params);
}

/**
 * Delete grade item for given bayesian
 *
 * @category grade
 * @param object $bayesian object
 * @return object bayesian
 */
function bayesian_grade_item_delete($bayesian) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/bayesian', $bayesian->course, 'mod', 'bayesian', $bayesian->id, 0,
            null, array('deleted' => 1));
}

/**
 * This standard function will check all instances of this module
 * and make sure there are up-to-date events created for each of them.
 * If courseid = 0, then every bayesian event in the site is checked, else
 * only bayesian events belonging to the course specified are checked.
 * This function is used, in its new format, by restore_refresh_events()
 *
 * @param int $courseid
 * @param int|stdClass $instance bayesian module instance or ID.
 * @param int|stdClass $cm Course module object or ID (not used in this module).
 * @return bool
 */
function bayesian_refresh_events($courseid = 0, $instance = null, $cm = null) {
    global $DB;

    // If we have instance information then we can just update the one event instead of updating all events.
    if (isset($instance)) {
        if (!is_object($instance)) {
            $instance = $DB->get_record('bayesian', array('id' => $instance), '*', MUST_EXIST);
        }
        bayesian_update_events($instance);
        return true;
    }

    if ($courseid == 0) {
        if (!$bayesianzes = $DB->get_records('bayesian')) {
            return true;
        }
    } else {
        if (!$bayesianzes = $DB->get_records('bayesian', array('course' => $courseid))) {
            return true;
        }
    }

    foreach ($bayesianzes as $bayesian) {
        bayesian_update_events($bayesian);
    }

    return true;
}

/**
 * Returns all bayesian graded users since a given time for specified bayesian
 */
function bayesian_get_recent_mod_activity(&$activities, &$index, $timestart,
        $courseid, $cmid, $userid = 0, $groupid = 0) {
    global $CFG, $USER, $DB;
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');

    $course = get_course($courseid);
    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $bayesian = $DB->get_record('bayesian', array('id' => $cm->instance));

    if ($userid) {
        $userselect = "AND u.id = :userid";
        $params['userid'] = $userid;
    } else {
        $userselect = '';
    }

    if ($groupid) {
        $groupselect = 'AND gm.groupid = :groupid';
        $groupjoin   = 'JOIN {groups_members} gm ON  gm.userid=u.id';
        $params['groupid'] = $groupid;
    } else {
        $groupselect = '';
        $groupjoin   = '';
    }

    $params['timestart'] = $timestart;
    $params['bayesianid'] = $bayesian->id;

    $userfieldsapi = \core_user\fields::for_userpic();
    $ufields = $userfieldsapi->get_sql('u', false, '', 'useridagain', false)->selects;
    if (!$attempts = $DB->get_records_sql("
              SELECT qa.*,
                     {$ufields}
                FROM {bayesian_attempts} qa
                     JOIN {user} u ON u.id = qa.userid
                     $groupjoin
               WHERE qa.timefinish > :timestart
                 AND qa.bayesian = :bayesianid
                 AND qa.preview = 0
                     $userselect
                     $groupselect
            ORDER BY qa.timefinish ASC", $params)) {
        return;
    }

    $context         = context_module::instance($cm->id);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $context);
    $viewfullnames   = has_capability('moodle/site:viewfullnames', $context);
    $grader          = has_capability('mod/bayesian:viewreports', $context);
    $groupmode       = groups_get_activity_groupmode($cm, $course);

    $usersgroups = null;
    $aname = format_string($cm->name, true);
    foreach ($attempts as $attempt) {
        if ($attempt->userid != $USER->id) {
            if (!$grader) {
                // Grade permission required.
                continue;
            }

            if ($groupmode == SEPARATEGROUPS and !$accessallgroups) {
                $usersgroups = groups_get_all_groups($course->id,
                        $attempt->userid, $cm->groupingid);
                $usersgroups = array_keys($usersgroups);
                if (!array_intersect($usersgroups, $modinfo->get_groups($cm->groupingid))) {
                    continue;
                }
            }
        }

        $options = bayesian_get_review_options($bayesian, $attempt, $context);

        $tmpactivity = new stdClass();

        $tmpactivity->type       = 'bayesian';
        $tmpactivity->cmid       = $cm->id;
        $tmpactivity->name       = $aname;
        $tmpactivity->sectionnum = $cm->sectionnum;
        $tmpactivity->timestamp  = $attempt->timefinish;

        $tmpactivity->content = new stdClass();
        $tmpactivity->content->attemptid = $attempt->id;
        $tmpactivity->content->attempt   = $attempt->attempt;
        if (bayesian_has_grades($bayesian) && $options->marks >= question_display_options::MARK_AND_MAX) {
            $tmpactivity->content->sumgrades = bayesian_format_grade($bayesian, $attempt->sumgrades);
            $tmpactivity->content->maxgrade  = bayesian_format_grade($bayesian, $bayesian->sumgrades);
        } else {
            $tmpactivity->content->sumgrades = null;
            $tmpactivity->content->maxgrade  = null;
        }

        $tmpactivity->user = user_picture::unalias($attempt, null, 'useridagain');
        $tmpactivity->user->fullname  = fullname($tmpactivity->user, $viewfullnames);

        $activities[$index++] = $tmpactivity;
    }
}

function bayesian_print_recent_mod_activity($activity, $courseid, $detail, $modnames) {
    global $CFG, $OUTPUT;

    echo '<table border="0" cellpadding="3" cellspacing="0" class="forum-recent">';

    echo '<tr><td class="userpicture" valign="top">';
    echo $OUTPUT->user_picture($activity->user, array('courseid' => $courseid));
    echo '</td><td>';

    if ($detail) {
        $modname = $modnames[$activity->type];
        echo '<div class="title">';
        echo $OUTPUT->image_icon('monologo', $modname, $activity->type);
        echo '<a href="' . $CFG->wwwroot . '/mod/bayesian/view.php?id=' .
                $activity->cmid . '">' . $activity->name . '</a>';
        echo '</div>';
    }

    echo '<div class="grade">';
    echo  get_string('attempt', 'bayesian', $activity->content->attempt);
    if (isset($activity->content->maxgrade)) {
        $grades = $activity->content->sumgrades . ' / ' . $activity->content->maxgrade;
        echo ': (<a href="' . $CFG->wwwroot . '/mod/bayesian/review.php?attempt=' .
                $activity->content->attemptid . '">' . $grades . '</a>)';
    }
    echo '</div>';

    echo '<div class="user">';
    echo '<a href="' . $CFG->wwwroot . '/user/view.php?id=' . $activity->user->id .
            '&amp;course=' . $courseid . '">' . $activity->user->fullname .
            '</a> - ' . userdate($activity->timestamp);
    echo '</div>';

    echo '</td></tr></table>';

    return;
}

/**
 * Pre-process the bayesian options form data, making any necessary adjustments.
 * Called by add/update instance in this file.
 *
 * @param object $bayesian The variables set on the form.
 */
function bayesian_process_options($bayesian) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');
    require_once($CFG->libdir . '/questionlib.php');

    $bayesian->timemodified = time();

    // bayesian name.
    if (!empty($bayesian->name)) {
        $bayesian->name = trim($bayesian->name);
    }

    // Password field - different in form to stop browsers that remember passwords
    // getting confused.
    $bayesian->password = $bayesian->bayesianpassword;
    unset($bayesian->bayesianpassword);

    // bayesian feedback.
    if (isset($bayesian->feedbacktext)) {
        // Clean up the boundary text.
        for ($i = 0; $i < count($bayesian->feedbacktext); $i += 1) {
            if (empty($bayesian->feedbacktext[$i]['text'])) {
                $bayesian->feedbacktext[$i]['text'] = '';
            } else {
                $bayesian->feedbacktext[$i]['text'] = trim($bayesian->feedbacktext[$i]['text']);
            }
        }

        // Check the boundary value is a number or a percentage, and in range.
        $i = 0;
        while (!empty($bayesian->feedbackboundaries[$i])) {
            $boundary = trim($bayesian->feedbackboundaries[$i]);
            if (!is_numeric($boundary)) {
                if (strlen($boundary) > 0 && $boundary[strlen($boundary) - 1] == '%') {
                    $boundary = trim(substr($boundary, 0, -1));
                    if (is_numeric($boundary)) {
                        $boundary = $boundary * $bayesian->grade / 100.0;
                    } else {
                        return get_string('feedbackerrorboundaryformat', 'bayesian', $i + 1);
                    }
                }
            }
            if ($boundary <= 0 || $boundary >= $bayesian->grade) {
                return get_string('feedbackerrorboundaryoutofrange', 'bayesian', $i + 1);
            }
            if ($i > 0 && $boundary >= $bayesian->feedbackboundaries[$i - 1]) {
                return get_string('feedbackerrororder', 'bayesian', $i + 1);
            }
            $bayesian->feedbackboundaries[$i] = $boundary;
            $i += 1;
        }
        $numboundaries = $i;

        // Check there is nothing in the remaining unused fields.
        if (!empty($bayesian->feedbackboundaries)) {
            for ($i = $numboundaries; $i < count($bayesian->feedbackboundaries); $i += 1) {
                if (!empty($bayesian->feedbackboundaries[$i]) &&
                        trim($bayesian->feedbackboundaries[$i]) != '') {
                    return get_string('feedbackerrorjunkinboundary', 'bayesian', $i + 1);
                }
            }
        }
        for ($i = $numboundaries + 1; $i < count($bayesian->feedbacktext); $i += 1) {
            if (!empty($bayesian->feedbacktext[$i]['text']) &&
                    trim($bayesian->feedbacktext[$i]['text']) != '') {
                return get_string('feedbackerrorjunkinfeedback', 'bayesian', $i + 1);
            }
        }
        // Needs to be bigger than $bayesian->grade because of '<' test in bayesian_feedback_for_grade().
        $bayesian->feedbackboundaries[-1] = $bayesian->grade + 1;
        $bayesian->feedbackboundaries[$numboundaries] = 0;
        $bayesian->feedbackboundarycount = $numboundaries;
    } else {
        $bayesian->feedbackboundarycount = -1;
    }

    // Combing the individual settings into the review columns.
    $bayesian->reviewattempt = bayesian_review_option_form_to_db($bayesian, 'attempt');
    $bayesian->reviewcorrectness = bayesian_review_option_form_to_db($bayesian, 'correctness');
    $bayesian->reviewmarks = bayesian_review_option_form_to_db($bayesian, 'marks');
    $bayesian->reviewspecificfeedback = bayesian_review_option_form_to_db($bayesian, 'specificfeedback');
    $bayesian->reviewgeneralfeedback = bayesian_review_option_form_to_db($bayesian, 'generalfeedback');
    $bayesian->reviewrightanswer = bayesian_review_option_form_to_db($bayesian, 'rightanswer');
    $bayesian->reviewoverallfeedback = bayesian_review_option_form_to_db($bayesian, 'overallfeedback');
    $bayesian->reviewattempt |= mod_bayesian_display_options::DURING;
    $bayesian->reviewoverallfeedback &= ~mod_bayesian_display_options::DURING;

    // Ensure that disabled checkboxes in completion settings are set to 0.
    // But only if the completion settinsg are unlocked.
    if (!empty($bayesian->completionunlocked)) {
        if (empty($bayesian->completionusegrade)) {
            $bayesian->completionpassgrade = 0;
        }
        if (empty($bayesian->completionpassgrade)) {
            $bayesian->completionattemptsexhausted = 0;
        }
        if (empty($bayesian->completionminattemptsenabled)) {
            $bayesian->completionminattempts = 0;
        }
    }
}

/**
 * Helper function for {@link bayesian_process_options()}.
 * @param object $fromform the sumbitted form date.
 * @param string $field one of the review option field names.
 */
function bayesian_review_option_form_to_db($fromform, $field) {
    static $times = array(
        'during' => mod_bayesian_display_options::DURING,
        'immediately' => mod_bayesian_display_options::IMMEDIATELY_AFTER,
        'open' => mod_bayesian_display_options::LATER_WHILE_OPEN,
        'closed' => mod_bayesian_display_options::AFTER_CLOSE,
    );

    $review = 0;
    foreach ($times as $whenname => $when) {
        $fieldname = $field . $whenname;
        if (!empty($fromform->$fieldname)) {
            $review |= $when;
            unset($fromform->$fieldname);
        }
    }

    return $review;
}

/**
 * This function is called at the end of bayesian_add_instance
 * and bayesian_update_instance, to do the common processing.
 *
 * @param object $bayesian the bayesian object.
 */
function bayesian_after_add_or_update($bayesian) {
    global $DB;
    $cmid = $bayesian->coursemodule;

    // We need to use context now, so we need to make sure all needed info is already in db.
    $DB->set_field('course_modules', 'instance', $bayesian->id, array('id'=>$cmid));
    $context = context_module::instance($cmid);

    // Save the feedback.
    $DB->delete_records('bayesian_feedback', array('bayesianid' => $bayesian->id));

    for ($i = 0; $i <= $bayesian->feedbackboundarycount; $i++) {
        $feedback = new stdClass();
        $feedback->bayesianid = $bayesian->id;
        $feedback->feedbacktext = $bayesian->feedbacktext[$i]['text'];
        $feedback->feedbacktextformat = $bayesian->feedbacktext[$i]['format'];
        $feedback->mingrade = $bayesian->feedbackboundaries[$i];
        $feedback->maxgrade = $bayesian->feedbackboundaries[$i - 1];
        $feedback->id = $DB->insert_record('bayesian_feedback', $feedback);
        $feedbacktext = file_save_draft_area_files((int)$bayesian->feedbacktext[$i]['itemid'],
                $context->id, 'mod_bayesian', 'feedback', $feedback->id,
                array('subdirs' => false, 'maxfiles' => -1, 'maxbytes' => 0),
                $bayesian->feedbacktext[$i]['text']);
        $DB->set_field('bayesian_feedback', 'feedbacktext', $feedbacktext,
                array('id' => $feedback->id));
    }

    // Store any settings belonging to the access rules.
    bayesian_access_manager::save_settings($bayesian);

    // Update the events relating to this bayesian.
    bayesian_update_events($bayesian);
    $completionexpected = (!empty($bayesian->completionexpected)) ? $bayesian->completionexpected : null;
    \core_completion\api::update_completion_date_event($bayesian->coursemodule, 'bayesian', $bayesian->id, $completionexpected);

    // Update related grade item.
    bayesian_grade_item_update($bayesian);
}

/**
 * This function updates the events associated to the bayesian.
 * If $override is non-zero, then it updates only the events
 * associated with the specified override.
 *
 * @uses bayesian_MAX_EVENT_LENGTH
 * @param object $bayesian the bayesian object.
 * @param object optional $override limit to a specific override
 */
function bayesian_update_events($bayesian, $override = null) {
    global $DB;

    // Load the old events relating to this bayesian.
    $conds = array('modulename'=>'bayesian',
                   'instance'=>$bayesian->id);
    if (!empty($override)) {
        // Only load events for this override.
        if (isset($override->userid)) {
            $conds['userid'] = $override->userid;
        } else {
            $conds['groupid'] = $override->groupid;
        }
    }
    $oldevents = $DB->get_records('event', $conds, 'id ASC');

    // Now make a to-do list of all that needs to be updated.
    if (empty($override)) {
        // We are updating the primary settings for the bayesian, so we need to add all the overrides.
        $overrides = $DB->get_records('bayesian_overrides', array('bayesian' => $bayesian->id), 'id ASC');
        // It is necessary to add an empty stdClass to the beginning of the array as the $oldevents
        // list contains the original (non-override) event for the module. If this is not included
        // the logic below will end up updating the wrong row when we try to reconcile this $overrides
        // list against the $oldevents list.
        array_unshift($overrides, new stdClass());
    } else {
        // Just do the one override.
        $overrides = array($override);
    }

    // Get group override priorities.
    $grouppriorities = bayesian_get_group_override_priorities($bayesian->id);

    foreach ($overrides as $current) {
        $groupid   = isset($current->groupid)?  $current->groupid : 0;
        $userid    = isset($current->userid)? $current->userid : 0;
        $timeopen  = isset($current->timeopen)?  $current->timeopen : $bayesian->timeopen;
        $timeclose = isset($current->timeclose)? $current->timeclose : $bayesian->timeclose;

        // Only add open/close events for an override if they differ from the bayesian default.
        $addopen  = empty($current->id) || !empty($current->timeopen);
        $addclose = empty($current->id) || !empty($current->timeclose);

        if (!empty($bayesian->coursemodule)) {
            $cmid = $bayesian->coursemodule;
        } else {
            $cmid = get_coursemodule_from_instance('bayesian', $bayesian->id, $bayesian->course)->id;
        }

        $event = new stdClass();
        $event->type = !$timeclose ? CALENDAR_EVENT_TYPE_ACTION : CALENDAR_EVENT_TYPE_STANDARD;
        $event->description = format_module_intro('bayesian', $bayesian, $cmid, false);
        $event->format = FORMAT_HTML;
        // Events module won't show user events when the courseid is nonzero.
        $event->courseid    = ($userid) ? 0 : $bayesian->course;
        $event->groupid     = $groupid;
        $event->userid      = $userid;
        $event->modulename  = 'bayesian';
        $event->instance    = $bayesian->id;
        $event->timestart   = $timeopen;
        $event->timeduration = max($timeclose - $timeopen, 0);
        $event->timesort    = $timeopen;
        $event->visible     = instance_is_visible('bayesian', $bayesian);
        $event->eventtype   = bayesian_EVENT_TYPE_OPEN;
        $event->priority    = null;

        // Determine the event name and priority.
        if ($groupid) {
            // Group override event.
            $params = new stdClass();
            $params->bayesian = $bayesian->name;
            $params->group = groups_get_group_name($groupid);
            if ($params->group === false) {
                // Group doesn't exist, just skip it.
                continue;
            }
            $eventname = get_string('overridegroupeventname', 'bayesian', $params);
            // Set group override priority.
            if ($grouppriorities !== null) {
                $openpriorities = $grouppriorities['open'];
                if (isset($openpriorities[$timeopen])) {
                    $event->priority = $openpriorities[$timeopen];
                }
            }
        } else if ($userid) {
            // User override event.
            $params = new stdClass();
            $params->bayesian = $bayesian->name;
            $eventname = get_string('overrideusereventname', 'bayesian', $params);
            // Set user override priority.
            $event->priority = CALENDAR_EVENT_USER_OVERRIDE_PRIORITY;
        } else {
            // The parent event.
            $eventname = $bayesian->name;
        }

        if ($addopen or $addclose) {
            // Separate start and end events.
            $event->timeduration  = 0;
            if ($timeopen && $addopen) {
                if ($oldevent = array_shift($oldevents)) {
                    $event->id = $oldevent->id;
                } else {
                    unset($event->id);
                }
                $event->name = get_string('bayesianeventopens', 'bayesian', $eventname);
                // The method calendar_event::create will reuse a db record if the id field is set.
                calendar_event::create($event, false);
            }
            if ($timeclose && $addclose) {
                if ($oldevent = array_shift($oldevents)) {
                    $event->id = $oldevent->id;
                } else {
                    unset($event->id);
                }
                $event->type      = CALENDAR_EVENT_TYPE_ACTION;
                $event->name      = get_string('bayesianeventcloses', 'bayesian', $eventname);
                $event->timestart = $timeclose;
                $event->timesort  = $timeclose;
                $event->eventtype = bayesian_EVENT_TYPE_CLOSE;
                if ($groupid && $grouppriorities !== null) {
                    $closepriorities = $grouppriorities['close'];
                    if (isset($closepriorities[$timeclose])) {
                        $event->priority = $closepriorities[$timeclose];
                    }
                }
                calendar_event::create($event, false);
            }
        }
    }

    // Delete any leftover events.
    foreach ($oldevents as $badevent) {
        $badevent = calendar_event::load($badevent);
        $badevent->delete();
    }
}

/**
 * Calculates the priorities of timeopen and timeclose values for group overrides for a bayesian.
 *
 * @param int $bayesianid The bayesian ID.
 * @return array|null Array of group override priorities for open and close times. Null if there are no group overrides.
 */
function bayesian_get_group_override_priorities($bayesianid) {
    global $DB;

    // Fetch group overrides.
    $where = 'bayesian = :bayesian AND groupid IS NOT NULL';
    $params = ['bayesian' => $bayesianid];
    $overrides = $DB->get_records_select('bayesian_overrides', $where, $params, '', 'id, timeopen, timeclose');
    if (!$overrides) {
        return null;
    }

    $grouptimeopen = [];
    $grouptimeclose = [];
    foreach ($overrides as $override) {
        if ($override->timeopen !== null && !in_array($override->timeopen, $grouptimeopen)) {
            $grouptimeopen[] = $override->timeopen;
        }
        if ($override->timeclose !== null && !in_array($override->timeclose, $grouptimeclose)) {
            $grouptimeclose[] = $override->timeclose;
        }
    }

    // Sort open times in ascending manner. The earlier open time gets higher priority.
    sort($grouptimeopen);
    // Set priorities.
    $opengrouppriorities = [];
    $openpriority = 1;
    foreach ($grouptimeopen as $timeopen) {
        $opengrouppriorities[$timeopen] = $openpriority++;
    }

    // Sort close times in descending manner. The later close time gets higher priority.
    rsort($grouptimeclose);
    // Set priorities.
    $closegrouppriorities = [];
    $closepriority = 1;
    foreach ($grouptimeclose as $timeclose) {
        $closegrouppriorities[$timeclose] = $closepriority++;
    }

    return [
        'open' => $opengrouppriorities,
        'close' => $closegrouppriorities
    ];
}

/**
 * List the actions that correspond to a view of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = 'r' and edulevel = LEVEL_PARTICIPATING will
 *       be considered as view action.
 *
 * @return array
 */
function bayesian_get_view_actions() {
    return array('view', 'view all', 'report', 'review');
}

/**
 * List the actions that correspond to a post of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = ('c' || 'u' || 'd') and edulevel = LEVEL_PARTICIPATING
 *       will be considered as post action.
 *
 * @return array
 */
function bayesian_get_post_actions() {
    return array('attempt', 'close attempt', 'preview', 'editquestions',
            'delete attempt', 'manualgrade');
}

/**
 * @param array $questionids of question ids.
 * @return bool whether any of these questions are used by any instance of this module.
 */
function bayesian_questions_in_use($questionids) {
    global $DB;
    list($test, $params) = $DB->get_in_or_equal($questionids);
    $params['component'] = 'mod_bayesian';
    $params['questionarea'] = 'slot';
    $sql = "SELECT qs.id
              FROM {bayesian_slots} qs
              JOIN {question_references} qr ON qr.itemid = qs.id
              JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
              JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
             WHERE qv.questionid $test
               AND qr.component = ?
               AND qr.questionarea = ?";
    return $DB->record_exists_sql($sql, $params) || question_engine::questions_in_use(
            $questionids, new qubaid_join('{bayesian_attempts} bayesiana',
            'bayesiana.uniqueid', 'bayesiana.preview = 0'));
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the bayesian.
 *
 * @param $mform the course reset form that is being built.
 */
function bayesian_reset_course_form_definition($mform) {
    $mform->addElement('header', 'bayesianheader', get_string('modulenameplural', 'bayesian'));
    $mform->addElement('advcheckbox', 'reset_bayesian_attempts',
            get_string('removeallbayesianattempts', 'bayesian'));
    $mform->addElement('advcheckbox', 'reset_bayesian_user_overrides',
            get_string('removealluseroverrides', 'bayesian'));
    $mform->addElement('advcheckbox', 'reset_bayesian_group_overrides',
            get_string('removeallgroupoverrides', 'bayesian'));
}

/**
 * Course reset form defaults.
 * @return array the defaults.
 */
function bayesian_reset_course_form_defaults($course) {
    return array('reset_bayesian_attempts' => 1,
                 'reset_bayesian_group_overrides' => 1,
                 'reset_bayesian_user_overrides' => 1);
}

/**
 * Removes all grades from gradebook
 *
 * @param int $courseid
 * @param string optional type
 */
function bayesian_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $bayesianzes = $DB->get_records_sql("
            SELECT q.*, cm.idnumber as cmidnumber, q.course as courseid
            FROM {modules} m
            JOIN {course_modules} cm ON m.id = cm.module
            JOIN {bayesian} q ON cm.instance = q.id
            WHERE m.name = 'bayesian' AND cm.course = ?", array($courseid));

    foreach ($bayesianzes as $bayesian) {
        bayesian_grade_item_update($bayesian, 'reset');
    }
}

/**
 * Actual implementation of the reset course functionality, delete all the
 * bayesian attempts for course $data->courseid, if $data->reset_bayesian_attempts is
 * set and true.
 *
 * Also, move the bayesian open and close dates, if the course start date is changing.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function bayesian_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/questionlib.php');

    $componentstr = get_string('modulenameplural', 'bayesian');
    $status = array();

    // Delete attempts.
    if (!empty($data->reset_bayesian_attempts)) {
        question_engine::delete_questions_usage_by_activities(new qubaid_join(
                '{bayesian_attempts} bayesiana JOIN {bayesian} bayesian ON bayesiana.bayesian = bayesian.id',
                'bayesiana.uniqueid', 'bayesian.course = :bayesiancourseid',
                array('bayesiancourseid' => $data->courseid)));

        $DB->delete_records_select('bayesian_attempts',
                'bayesian IN (SELECT id FROM {bayesian} WHERE course = ?)', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('attemptsdeleted', 'bayesian'),
            'error' => false);

        // Remove all grades from gradebook.
        $DB->delete_records_select('bayesian_grades',
                'bayesian IN (SELECT id FROM {bayesian} WHERE course = ?)', array($data->courseid));
        if (empty($data->reset_gradebook_grades)) {
            bayesian_reset_gradebook($data->courseid);
        }
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('gradesdeleted', 'bayesian'),
            'error' => false);
    }

    $purgeoverrides = false;

    // Remove user overrides.
    if (!empty($data->reset_bayesian_user_overrides)) {
        $DB->delete_records_select('bayesian_overrides',
                'bayesian IN (SELECT id FROM {bayesian} WHERE course = ?) AND userid IS NOT NULL', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('useroverridesdeleted', 'bayesian'),
            'error' => false);
        $purgeoverrides = true;
    }
    // Remove group overrides.
    if (!empty($data->reset_bayesian_group_overrides)) {
        $DB->delete_records_select('bayesian_overrides',
                'bayesian IN (SELECT id FROM {bayesian} WHERE course = ?) AND groupid IS NOT NULL', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('groupoverridesdeleted', 'bayesian'),
            'error' => false);
        $purgeoverrides = true;
    }

    // Updating dates - shift may be negative too.
    if ($data->timeshift) {
        $DB->execute("UPDATE {bayesian_overrides}
                         SET timeopen = timeopen + ?
                       WHERE bayesian IN (SELECT id FROM {bayesian} WHERE course = ?)
                         AND timeopen <> 0", array($data->timeshift, $data->courseid));
        $DB->execute("UPDATE {bayesian_overrides}
                         SET timeclose = timeclose + ?
                       WHERE bayesian IN (SELECT id FROM {bayesian} WHERE course = ?)
                         AND timeclose <> 0", array($data->timeshift, $data->courseid));

        $purgeoverrides = true;

        // Any changes to the list of dates that needs to be rolled should be same during course restore and course reset.
        // See MDL-9367.
        shift_course_mod_dates('bayesian', array('timeopen', 'timeclose'),
                $data->timeshift, $data->courseid);

        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('openclosedatesupdated', 'bayesian'),
            'error' => false);
    }

    if ($purgeoverrides) {
        cache::make('mod_bayesian', 'overrides')->purge();
    }

    return $status;
}

/**
 * @deprecated since Moodle 3.3, when the block_course_overview block was removed.
 */
function bayesian_print_overview() {
    throw new coding_exception('bayesian_print_overview() can not be used any more and is obsolete.');
}

/**
 * Return a textual summary of the number of attempts that have been made at a particular bayesian,
 * returns '' if no attempts have been made yet, unless $returnzero is passed as true.
 *
 * @param object $bayesian the bayesian object. Only $bayesian->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param bool $returnzero if false (default), when no attempts have been
 *      made '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string a string like "Attempts: 123", "Attemtps 123 (45 from your groups)" or
 *          "Attemtps 123 (45 from this group)".
 */
function bayesian_num_attempt_summary($bayesian, $cm, $returnzero = false, $currentgroup = 0) {
    global $DB, $USER;
    $numattempts = $DB->count_records('bayesian_attempts', array('bayesian'=> $bayesian->id, 'preview'=>0));
    if ($numattempts || $returnzero) {
        if (groups_get_activity_groupmode($cm)) {
            $a = new stdClass();
            $a->total = $numattempts;
            if ($currentgroup) {
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{bayesian_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE bayesian = ? AND preview = 0 AND groupid = ?',
                        array($bayesian->id, $currentgroup));
                return get_string('attemptsnumthisgroup', 'bayesian', $a);
            } else if ($groups = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid)) {
                list($usql, $params) = $DB->get_in_or_equal(array_keys($groups));
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{bayesian_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE bayesian = ? AND preview = 0 AND ' .
                        "groupid $usql", array_merge(array($bayesian->id), $params));
                return get_string('attemptsnumyourgroups', 'bayesian', $a);
            }
        }
        return get_string('attemptsnum', 'bayesian', $numattempts);
    }
    return '';
}

/**
 * Returns the same as {@link bayesian_num_attempt_summary()} but wrapped in a link
 * to the bayesian reports.
 *
 * @param object $bayesian the bayesian object. Only $bayesian->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param object $context the bayesian context.
 * @param bool $returnzero if false (default), when no attempts have been made
 *      '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string HTML fragment for the link.
 */
function bayesian_attempt_summary_link_to_reports($bayesian, $cm, $context, $returnzero = false,
        $currentgroup = 0) {
    global $PAGE;

    return $PAGE->get_renderer('mod_bayesian')->bayesian_attempt_summary_link_to_reports(
            $bayesian, $cm, $context, $returnzero, $currentgroup);
}

/**
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed True if module supports feature, false if not, null if doesn't know or string for the module purpose.
 */
function bayesian_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                    return true;
        case FEATURE_GROUPINGS:                 return true;
        case FEATURE_MOD_INTRO:                 return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:   return true;
        case FEATURE_COMPLETION_HAS_RULES:      return true;
        case FEATURE_GRADE_HAS_GRADE:           return true;
        case FEATURE_GRADE_OUTCOMES:            return true;
        case FEATURE_BACKUP_MOODLE2:            return true;
        case FEATURE_SHOW_DESCRIPTION:          return true;
        case FEATURE_CONTROLS_GRADE_VISIBILITY: return true;
        case FEATURE_USES_QUESTIONS:            return true;
        case FEATURE_PLAGIARISM:                return true;
        case FEATURE_MOD_PURPOSE:               return MOD_PURPOSE_ASSESSMENT;

        default: return null;
    }
}

/**
 * @return array all other caps used in module
 */
function bayesian_get_extra_capabilities() {
    global $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    return question_get_all_capabilities();
}

/**
 * This function extends the settings navigation block for the site.
 *
 * It is safe to rely on PAGE here as we will only ever be within the module
 * context when this is called
 *
 * @param settings_navigation $settings
 * @param navigation_node $bayesiannode
 * @return void
 */
function bayesian_extend_settings_navigation(settings_navigation $settings, navigation_node $bayesiannode) {
    global $CFG;

    // Require {@link questionlib.php}
    // Included here as we only ever want to include this file if we really need to.
    require_once($CFG->libdir . '/questionlib.php');

    // We want to add these new nodes after the Edit settings node, and before the
    // Locally assigned roles node. Of course, both of those are controlled by capabilities.
    $keys = $bayesiannode->get_children_key_list();
    $beforekey = null;
    $i = array_search('modedit', $keys);
    if ($i === false and array_key_exists(0, $keys)) {
        $beforekey = $keys[0];
    } else if (array_key_exists($i + 1, $keys)) {
        $beforekey = $keys[$i + 1];
    }

    if (has_any_capability(['mod/bayesian:manageoverrides', 'mod/bayesian:viewoverrides'], $settings->get_page()->cm->context)) {
        $url = new moodle_url('/mod/bayesian/overrides.php', ['cmid' => $settings->get_page()->cm->id, 'mode' => 'user']);
        $node = navigation_node::create(get_string('overrides', 'bayesian'),
                    $url, navigation_node::TYPE_SETTING, null, 'mod_bayesian_useroverrides');
        $settingsoverride = $bayesiannode->add_node($node, $beforekey);
    }

    if (has_capability('mod/bayesian:manage', $settings->get_page()->cm->context)) {
        $node = navigation_node::create(get_string('questions', 'bayesian'),
            new moodle_url('/mod/bayesian/edit.php', array('cmid' => $settings->get_page()->cm->id)),
            navigation_node::TYPE_SETTING, null, 'mod_bayesian_edit', new pix_icon('t/edit', ''));
        $bayesiannode->add_node($node, $beforekey);
    }

    if (has_capability('mod/bayesian:preview', $settings->get_page()->cm->context)) {
        $url = new moodle_url('/mod/bayesian/startattempt.php',
                array('cmid' => $settings->get_page()->cm->id, 'sesskey' => sesskey()));
        $node = navigation_node::create(get_string('preview', 'bayesian'), $url,
                navigation_node::TYPE_SETTING, null, 'mod_bayesian_preview',
                new pix_icon('i/preview', ''));
        $previewnode = $bayesiannode->add_node($node, $beforekey);
        $previewnode->set_show_in_secondary_navigation(false);
    }

    question_extend_settings_navigation($bayesiannode, $settings->get_page()->cm->context)->trim_if_empty();

    if (has_any_capability(array('mod/bayesian:viewreports', 'mod/bayesian:grade'), $settings->get_page()->cm->context)) {
        require_once($CFG->dirroot . '/mod/bayesian/report/reportlib.php');
        $reportlist = bayesian_report_list($settings->get_page()->cm->context);

        $url = new moodle_url('/mod/bayesian/report.php',
                array('id' => $settings->get_page()->cm->id, 'mode' => reset($reportlist)));
        $reportnode = $bayesiannode->add_node(navigation_node::create(get_string('results', 'bayesian'), $url,
                navigation_node::TYPE_SETTING,
                null, 'bayesian_report', new pix_icon('i/report', '')));

        foreach ($reportlist as $report) {
            $url = new moodle_url('/mod/bayesian/report.php', ['id' => $settings->get_page()->cm->id, 'mode' => $report]);
            $reportnode->add_node(navigation_node::create(get_string($report, 'bayesian_'.$report), $url,
                    navigation_node::TYPE_SETTING,
                    null, 'bayesian_report_' . $report, new pix_icon('i/item', '')));
        }
    }
}

/**
 * Serves the bayesian files.
 *
 * @package  mod_bayesian
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function bayesian_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, false, $cm);

    if (!$bayesian = $DB->get_record('bayesian', array('id'=>$cm->instance))) {
        return false;
    }

    // The 'intro' area is served by pluginfile.php.
    $fileareas = array('feedback');
    if (!in_array($filearea, $fileareas)) {
        return false;
    }

    $feedbackid = (int)array_shift($args);
    if (!$feedback = $DB->get_record('bayesian_feedback', array('id'=>$feedbackid))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_bayesian/$filearea/$feedbackid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Called via pluginfile.php -> question_pluginfile to serve files belonging to
 * a question in a question_attempt when that attempt is a bayesian attempt.
 *
 * @package  mod_bayesian
 * @category files
 * @param stdClass $course course settings object
 * @param stdClass $context context object
 * @param string $component the name of the component we are serving files for.
 * @param string $filearea the name of the file area.
 * @param int $qubaid the attempt usage id.
 * @param int $slot the id of a question in this bayesian attempt.
 * @param array $args the remaining bits of the file path.
 * @param bool $forcedownload whether the user must be forced to download the file.
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function bayesian_question_pluginfile($course, $context, $component,
        $filearea, $qubaid, $slot, $args, $forcedownload, array $options=array()) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');

    $attemptobj = bayesian_attempt::create_from_usage_id($qubaid);
    require_login($attemptobj->get_course(), false, $attemptobj->get_cm());

    if ($attemptobj->is_own_attempt() && !$attemptobj->is_finished()) {
        // In the middle of an attempt.
        if (!$attemptobj->is_preview_user()) {
            $attemptobj->require_capability('mod/bayesian:attempt');
        }
        $isreviewing = false;

    } else {
        // Reviewing an attempt.
        $attemptobj->check_review_capability();
        $isreviewing = true;
    }

    if (!$attemptobj->check_file_access($slot, $isreviewing, $context->id,
            $component, $filearea, $args, $forcedownload)) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/$component/$filearea/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function bayesian_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $module_pagetype = array(
        'mod-bayesian-*'       => get_string('page-mod-bayesian-x', 'bayesian'),
        'mod-bayesian-view'    => get_string('page-mod-bayesian-view', 'bayesian'),
        'mod-bayesian-attempt' => get_string('page-mod-bayesian-attempt', 'bayesian'),
        'mod-bayesian-summary' => get_string('page-mod-bayesian-summary', 'bayesian'),
        'mod-bayesian-review'  => get_string('page-mod-bayesian-review', 'bayesian'),
        'mod-bayesian-edit'    => get_string('page-mod-bayesian-edit', 'bayesian'),
        'mod-bayesian-report'  => get_string('page-mod-bayesian-report', 'bayesian'),
    );
    return $module_pagetype;
}

/**
 * @return the options for bayesian navigation.
 */
function bayesian_get_navigation_options() {
    return array(
        bayesian_NAVMETHOD_FREE => get_string('navmethod_free', 'bayesian'),
        bayesian_NAVMETHOD_SEQ  => get_string('navmethod_seq', 'bayesian')
    );
}

/**
 * Check if the module has any update that affects the current user since a given time.
 *
 * @param  cm_info $cm course module data
 * @param  int $from the time to check updates from
 * @param  array $filter  if we need to check only specific updates
 * @return stdClass an object with the different type of areas indicating if they were updated or not
 * @since Moodle 3.2
 */
function bayesian_check_updates_since(cm_info $cm, $from, $filter = array()) {
    global $DB, $USER, $CFG;
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');

    $updates = course_check_module_updates_since($cm, $from, array(), $filter);

    // Check if questions were updated.
    $updates->questions = (object) array('updated' => false);
    $bayesianobj = bayesian::create($cm->instance, $USER->id);
    $bayesianobj->preload_questions();
    $bayesianobj->load_questions();
    $questionids = array_keys($bayesianobj->get_questions());
    if (!empty($questionids)) {
        list($questionsql, $params) = $DB->get_in_or_equal($questionids, SQL_PARAMS_NAMED);
        $select = 'id ' . $questionsql . ' AND (timemodified > :time1 OR timecreated > :time2)';
        $params['time1'] = $from;
        $params['time2'] = $from;
        $questions = $DB->get_records_select('question', $select, $params, '', 'id');
        if (!empty($questions)) {
            $updates->questions->updated = true;
            $updates->questions->itemids = array_keys($questions);
        }
    }

    // Check for new attempts or grades.
    $updates->attempts = (object) array('updated' => false);
    $updates->grades = (object) array('updated' => false);
    $select = 'bayesian = ? AND userid = ? AND timemodified > ?';
    $params = array($cm->instance, $USER->id, $from);

    $attempts = $DB->get_records_select('bayesian_attempts', $select, $params, '', 'id');
    if (!empty($attempts)) {
        $updates->attempts->updated = true;
        $updates->attempts->itemids = array_keys($attempts);
    }
    $grades = $DB->get_records_select('bayesian_grades', $select, $params, '', 'id');
    if (!empty($grades)) {
        $updates->grades->updated = true;
        $updates->grades->itemids = array_keys($grades);
    }

    // Now, teachers should see other students updates.
    if (has_capability('mod/bayesian:viewreports', $cm->context)) {
        $select = 'bayesian = ? AND timemodified > ?';
        $params = array($cm->instance, $from);

        if (groups_get_activity_groupmode($cm) == SEPARATEGROUPS) {
            $groupusers = array_keys(groups_get_activity_shared_group_members($cm));
            if (empty($groupusers)) {
                return $updates;
            }
            list($insql, $inparams) = $DB->get_in_or_equal($groupusers);
            $select .= ' AND userid ' . $insql;
            $params = array_merge($params, $inparams);
        }

        $updates->userattempts = (object) array('updated' => false);
        $attempts = $DB->get_records_select('bayesian_attempts', $select, $params, '', 'id');
        if (!empty($attempts)) {
            $updates->userattempts->updated = true;
            $updates->userattempts->itemids = array_keys($attempts);
        }

        $updates->usergrades = (object) array('updated' => false);
        $grades = $DB->get_records_select('bayesian_grades', $select, $params, '', 'id');
        if (!empty($grades)) {
            $updates->usergrades->updated = true;
            $updates->usergrades->itemids = array_keys($grades);
        }
    }
    return $updates;
}

/**
 * Get icon mapping for font-awesome.
 */
function mod_bayesian_get_fontawesome_icon_map() {
    return [
        'mod_bayesian:navflagged' => 'fa-flag',
    ];
}

/**
 * This function receives a calendar event and returns the action associated with it, or null if there is none.
 *
 * This is used by block_myoverview in order to display the event appropriately. If null is returned then the event
 * is not displayed on the block.
 *
 * @param calendar_event $event
 * @param \core_calendar\action_factory $factory
 * @param int $userid User id to use for all capability checks, etc. Set to 0 for current user (default).
 * @return \core_calendar\local\event\entities\action_interface|null
 */
function mod_bayesian_core_calendar_provide_event_action(calendar_event $event,
                                                     \core_calendar\action_factory $factory,
                                                     int $userid = 0) {
    global $CFG, $USER;

    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');

    if (empty($userid)) {
        $userid = $USER->id;
    }

    $cm = get_fast_modinfo($event->courseid, $userid)->instances['bayesian'][$event->instance];
    $bayesianobj = bayesian::create($cm->instance, $userid);
    $bayesian = $bayesianobj->get_bayesian();

    // Check they have capabilities allowing them to view the bayesian.
    if (!has_any_capability(['mod/bayesian:reviewmyattempts', 'mod/bayesian:attempt'], $bayesianobj->get_context(), $userid)) {
        return null;
    }

    $completion = new \completion_info($cm->get_course());

    $completiondata = $completion->get_data($cm, false, $userid);

    if ($completiondata->completionstate != COMPLETION_INCOMPLETE) {
        return null;
    }

    bayesian_update_effective_access($bayesian, $userid);

    // Check if bayesian is closed, if so don't display it.
    if (!empty($bayesian->timeclose) && $bayesian->timeclose <= time()) {
        return null;
    }

    if (!$bayesianobj->is_participant($userid)) {
        // If the user is not a participant then they have
        // no action to take. This will filter out the events for teachers.
        return null;
    }

    $attempts = bayesian_get_user_attempts($bayesianobj->get_bayesianid(), $userid);
    if (!empty($attempts)) {
        // The student's last attempt is finished.
        return null;
    }

    $name = get_string('attemptbayesiannow', 'bayesian');
    $url = new \moodle_url('/mod/bayesian/view.php', [
        'id' => $cm->id
    ]);
    $itemcount = 1;
    $actionable = true;

    // Check if the bayesian is not currently actionable.
    if (!empty($bayesian->timeopen) && $bayesian->timeopen > time()) {
        $actionable = false;
    }

    return $factory->create_instance(
        $name,
        $url,
        $itemcount,
        $actionable
    );
}

/**
 * Add a get_coursemodule_info function in case any bayesian type wants to add 'extra' information
 * for the course (see resource).
 *
 * Given a course_module object, this function returns any "extra" information that may be needed
 * when printing this activity in a course listing.  See get_array_of_activities() in course/lib.php.
 *
 * @param stdClass $coursemodule The coursemodule object (record).
 * @return cached_cm_info An object on information that the courses
 *                        will know about (most noticeably, an icon).
 */
function bayesian_get_coursemodule_info($coursemodule) {
    global $DB;

    $dbparams = ['id' => $coursemodule->instance];
    $fields = 'id, name, intro, introformat, completionattemptsexhausted, completionminattempts,
        timeopen, timeclose';
    if (!$bayesian = $DB->get_record('bayesian', $dbparams, $fields)) {
        return false;
    }

    $result = new cached_cm_info();
    $result->name = $bayesian->name;

    if ($coursemodule->showdescription) {
        // Convert intro to html. Do not filter cached version, filters run at display time.
        $result->content = format_module_intro('bayesian', $bayesian, $coursemodule->id, false);
    }

    // Populate the custom completion rules as key => value pairs, but only if the completion mode is 'automatic'.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        if ($bayesian->completionattemptsexhausted) {
            $result->customdata['customcompletionrules']['completionpassorattemptsexhausted'] = [
                'completionpassgrade' => $coursemodule->completionpassgrade,
                'completionattemptsexhausted' => $bayesian->completionattemptsexhausted,
            ];
        } else {
            $result->customdata['customcompletionrules']['completionpassorattemptsexhausted'] = [];
        }

        $result->customdata['customcompletionrules']['completionminattempts'] = $bayesian->completionminattempts;
    }

    // Populate some other values that can be used in calendar or on dashboard.
    if ($bayesian->timeopen) {
        $result->customdata['timeopen'] = $bayesian->timeopen;
    }
    if ($bayesian->timeclose) {
        $result->customdata['timeclose'] = $bayesian->timeclose;
    }

    return $result;
}

/**
 * Sets dynamic information about a course module
 *
 * This function is called from cm_info when displaying the module
 *
 * @param cm_info $cm
 */
function mod_bayesian_cm_info_dynamic(cm_info $cm) {
    global $USER;

    $cache = cache::make('mod_bayesian', 'overrides');
    $override = $cache->get("{$cm->instance}_u_{$USER->id}");

    if (!$override) {
        $override = (object) [
            'timeopen' => null,
            'timeclose' => null,
        ];
    }

    // No need to look for group overrides if there are user overrides for both timeopen and timeclose.
    if (is_null($override->timeopen) || is_null($override->timeclose)) {
        $opens = [];
        $closes = [];
        $groupings = groups_get_user_groups($cm->course, $USER->id);
        foreach ($groupings[0] as $groupid) {
            $groupoverride = $cache->get("{$cm->instance}_g_{$groupid}");
            if (isset($groupoverride->timeopen)) {
                $opens[] = $groupoverride->timeopen;
            }
            if (isset($groupoverride->timeclose)) {
                $closes[] = $groupoverride->timeclose;
            }
        }
        // If there is a user override for a setting, ignore the group override.
        if (is_null($override->timeopen) && count($opens)) {
            $override->timeopen = min($opens);
        }
        if (is_null($override->timeclose) && count($closes)) {
            if (in_array(0, $closes)) {
                $override->timeclose = 0;
            } else {
                $override->timeclose = max($closes);
            }
        }
    }

    // Populate some other values that can be used in calendar or on dashboard.
    if (!is_null($override->timeopen)) {
        $cm->override_customdata('timeopen', $override->timeopen);
    }
    if (!is_null($override->timeclose)) {
        $cm->override_customdata('timeclose', $override->timeclose);
    }
}

/**
 * Callback which returns human-readable strings describing the active completion custom rules for the module instance.
 *
 * @param cm_info|stdClass $cm object with fields ->completion and ->customdata['customcompletionrules']
 * @return array $descriptions the array of descriptions for the custom rules.
 */
function mod_bayesian_get_completion_active_rule_descriptions($cm) {
    // Values will be present in cm_info, and we assume these are up to date.
    if (empty($cm->customdata['customcompletionrules'])
        || $cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
        return [];
    }

    $descriptions = [];
    $rules = $cm->customdata['customcompletionrules'];

    if (!empty($rules['completionpassorattemptsexhausted'])) {
        if (!empty($rules['completionpassorattemptsexhausted']['completionattemptsexhausted'])) {
            $descriptions[] = get_string('completionpassorattemptsexhausteddesc', 'bayesian');
        }
    } else {
        // Fallback.
        if (!empty($rules['completionattemptsexhausted'])) {
            $descriptions[] = get_string('completionpassorattemptsexhausteddesc', 'bayesian');
        }
    }

    if (!empty($rules['completionminattempts'])) {
        $descriptions[] = get_string('completionminattemptsdesc', 'bayesian', $rules['completionminattempts']);
    }

    return $descriptions;
}

/**
 * Returns the min and max values for the timestart property of a bayesian
 * activity event.
 *
 * The min and max values will be the timeopen and timeclose properties
 * of the bayesian, respectively, if they are set.
 *
 * If either value isn't set then null will be returned instead to
 * indicate that there is no cutoff for that value.
 *
 * If the vent has no valid timestart range then [false, false] will
 * be returned. This is the case for overriden events.
 *
 * A minimum and maximum cutoff return value will look like:
 * [
 *     [1505704373, 'The date must be after this date'],
 *     [1506741172, 'The date must be before this date']
 * ]
 *
 * @throws \moodle_exception
 * @param \calendar_event $event The calendar event to get the time range for
 * @param stdClass $bayesian The module instance to get the range from
 * @return array
 */
function mod_bayesian_core_calendar_get_valid_event_timestart_range(\calendar_event $event, \stdClass $bayesian) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');

    // Overrides do not have a valid timestart range.
    if (bayesian_is_overriden_calendar_event($event)) {
        return [false, false];
    }

    $mindate = null;
    $maxdate = null;

    if ($event->eventtype == bayesian_EVENT_TYPE_OPEN) {
        if (!empty($bayesian->timeclose)) {
            $maxdate = [
                $bayesian->timeclose,
                get_string('openafterclose', 'bayesian')
            ];
        }
    } else if ($event->eventtype == bayesian_EVENT_TYPE_CLOSE) {
        if (!empty($bayesian->timeopen)) {
            $mindate = [
                $bayesian->timeopen,
                get_string('closebeforeopen', 'bayesian')
            ];
        }
    }

    return [$mindate, $maxdate];
}

/**
 * This function will update the bayesian module according to the
 * event that has been modified.
 *
 * It will set the timeopen or timeclose value of the bayesian instance
 * according to the type of event provided.
 *
 * @throws \moodle_exception
 * @param \calendar_event $event A bayesian activity calendar event
 * @param \stdClass $bayesian A bayesian activity instance
 */
function mod_bayesian_core_calendar_event_timestart_updated(\calendar_event $event, \stdClass $bayesian) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');

    if (!in_array($event->eventtype, [bayesian_EVENT_TYPE_OPEN, bayesian_EVENT_TYPE_CLOSE])) {
        // This isn't an event that we care about so we can ignore it.
        return;
    }

    $courseid = $event->courseid;
    $modulename = $event->modulename;
    $instanceid = $event->instance;
    $modified = false;
    $closedatechanged = false;

    // Something weird going on. The event is for a different module so
    // we should ignore it.
    if ($modulename != 'bayesian') {
        return;
    }

    if ($bayesian->id != $instanceid) {
        // The provided bayesian instance doesn't match the event so
        // there is nothing to do here.
        return;
    }

    // We don't update the activity if it's an override event that has
    // been modified.
    if (bayesian_is_overriden_calendar_event($event)) {
        return;
    }

    $coursemodule = get_fast_modinfo($courseid)->instances[$modulename][$instanceid];
    $context = context_module::instance($coursemodule->id);

    // The user does not have the capability to modify this activity.
    if (!has_capability('moodle/course:manageactivities', $context)) {
        return;
    }

    if ($event->eventtype == bayesian_EVENT_TYPE_OPEN) {
        // If the event is for the bayesian activity opening then we should
        // set the start time of the bayesian activity to be the new start
        // time of the event.
        if ($bayesian->timeopen != $event->timestart) {
            $bayesian->timeopen = $event->timestart;
            $modified = true;
        }
    } else if ($event->eventtype == bayesian_EVENT_TYPE_CLOSE) {
        // If the event is for the bayesian activity closing then we should
        // set the end time of the bayesian activity to be the new start
        // time of the event.
        if ($bayesian->timeclose != $event->timestart) {
            $bayesian->timeclose = $event->timestart;
            $modified = true;
            $closedatechanged = true;
        }
    }

    if ($modified) {
        $bayesian->timemodified = time();
        $DB->update_record('bayesian', $bayesian);

        if ($closedatechanged) {
            bayesian_update_open_attempts(array('bayesianid' => $bayesian->id));
        }

        // Delete any previous preview attempts.
        bayesian_delete_previews($bayesian);
        bayesian_update_events($bayesian);
        $event = \core\event\course_module_updated::create_from_cm($coursemodule, $context);
        $event->trigger();
    }
}

/**
 * Generates the question bank in a fragment output. This allows
 * the question bank to be displayed in a modal.
 *
 * The only expected argument provided in the $args array is
 * 'querystring'. The value should be the list of parameters
 * URL encoded and used to build the question bank page.
 *
 * The individual list of parameters expected can be found in
 * question_build_edit_resources.
 *
 * @param array $args The fragment arguments.
 * @return string The rendered mform fragment.
 */
function mod_bayesian_output_fragment_bayesian_question_bank($args) {
    global $CFG, $DB, $PAGE;
    require_once($CFG->dirroot . '/mod/bayesian/locallib.php');
    require_once($CFG->dirroot . '/question/editlib.php');

    $querystring = preg_replace('/^\?/', '', $args['querystring']);
    $params = [];
    parse_str($querystring, $params);

    // Build the required resources. The $params are all cleaned as
    // part of this process.
    list($thispageurl, $contexts, $cmid, $cm, $bayesian, $pagevars) =
            question_build_edit_resources('editq', '/mod/bayesian/edit.php', $params, custom_view::DEFAULT_PAGE_SIZE);

    // Get the course object and related bits.
    $course = $DB->get_record('course', array('id' => $bayesian->course), '*', MUST_EXIST);
    require_capability('mod/bayesian:manage', $contexts->lowest());

    // Create bayesian question bank view.
    $questionbank = new custom_view($contexts, $thispageurl, $course, $cm, $bayesian);
    $questionbank->set_bayesian_has_attempts(bayesian_has_attempts($bayesian->id));

    // Output.
    $renderer = $PAGE->get_renderer('mod_bayesian', 'edit');
    return $renderer->question_bank_contents($questionbank, $pagevars);
}

/**
 * Generates the add random question in a fragment output. This allows the
 * form to be rendered in javascript, for example inside a modal.
 *
 * The required arguments as keys in the $args array are:
 *      cat {string} The category and category context ids comma separated.
 *      addonpage {int} The page id to add this question to.
 *      returnurl {string} URL to return to after form submission.
 *      cmid {int} The course module id the questions are being added to.
 *
 * @param array $args The fragment arguments.
 * @return string The rendered mform fragment.
 */
function mod_bayesian_output_fragment_add_random_question_form($args) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/bayesian/addrandomform.php');

    $contexts = new \core_question\local\bank\question_edit_contexts($args['context']);
    $formoptions = [
        'contexts' => $contexts,
        'cat' => $args['cat']
    ];
    $formdata = [
        'category' => $args['cat'],
        'addonpage' => $args['addonpage'],
        'returnurl' => $args['returnurl'],
        'cmid' => $args['cmid']
    ];

    $form = new bayesian_add_random_form(
        new \moodle_url('/mod/bayesian/addrandom.php'),
        $formoptions,
        'post',
        '',
        null,
        true,
        $formdata
    );
    $form->set_data($formdata);

    return $form->render();
}

/**
 * Callback to fetch the activity event type lang string.
 *
 * @param string $eventtype The event type.
 * @return lang_string The event type lang string.
 */
function mod_bayesian_core_calendar_get_event_action_string(string $eventtype): string {
    $modulename = get_string('modulename', 'bayesian');

    switch ($eventtype) {
        case bayesian_EVENT_TYPE_OPEN:
            $identifier = 'bayesianeventopens';
            break;
        case bayesian_EVENT_TYPE_CLOSE:
            $identifier = 'bayesianeventcloses';
            break;
        default:
            return get_string('requiresaction', 'calendar', $modulename);
    }

    return get_string($identifier, 'bayesian', $modulename);
}

/**
 * Delete question reference data.
 *
 * @param int $bayesianid The id of bayesian.
 */
function bayesian_delete_references($bayesianid): void {
    global $DB;
    $slots = $DB->get_records('bayesian_slots', ['bayesianid' => $bayesianid]);
    foreach ($slots as $slot) {
        $params = [
            'itemid' => $slot->id,
            'component' => 'mod_bayesian',
            'questionarea' => 'slot'
        ];
        // Delete any set references.
        $DB->delete_records('question_set_references', $params);
        // Delete any references.
        $DB->delete_records('question_references', $params);
    }
}

/**
 * Implement the calculate_question_stats callback.
 *
 * This enables bayesian statistics to be shown in statistics columns in the database.
 *
 * @param context $context return the statistics related to this context (which will be a bayesian context).
 * @return all_calculated_for_qubaid_condition|null The statistics for this bayesian, if any, else null.
 */
function mod_bayesian_calculate_question_stats(context $context): ?all_calculated_for_qubaid_condition {
    global $CFG;
    require_once($CFG->dirroot . '/mod/bayesian/report/statistics/report.php');
    $cm = get_coursemodule_from_id('bayesian', $context->instanceid);
    $report = new bayesian_statistics_report();
    return $report->calculate_questions_stats_for_question_bank($cm->instance);
}
