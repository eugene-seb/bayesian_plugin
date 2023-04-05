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
 * List of deprecated mod_bayesian functions.
 *
 * @package   mod_bayesian
 * @copyright 2021 Shamim Rezaie <shamim@moodle.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Internal function used in bayesian_get_completion_state. Check passing grade (or no attempts left) requirement for completion.
 *
 * @deprecated since Moodle 3.11
 * @todo MDL-71196 Final deprecation in Moodle 4.3
 * @see \mod_bayesian\completion\custom_completion
 * @param stdClass $course
 * @param cm_info|stdClass $cm
 * @param int $userid
 * @param stdClass $bayesian
 * @return bool True if the passing grade (or no attempts left) requirement is disabled or met.
 * @throws coding_exception
 */
function bayesian_completion_check_passing_grade_or_all_attempts($course, $cm, $userid, $bayesian) {
    global $CFG;

    debugging('bayesian_completion_check_passing_grade_or_all_attempts has been deprecated.', DEBUG_DEVELOPER);

    if (!$cm->completionpassgrade) {
        return true;
    }

    // Check for passing grade.
    require_once($CFG->libdir . '/gradelib.php');
    $item = grade_item::fetch(array('courseid' => $course->id, 'itemtype' => 'mod',
            'itemmodule' => 'bayesian', 'iteminstance' => $cm->instance, 'outcomeid' => null));
    if ($item) {
        $grades = grade_grade::fetch_users_grades($item, array($userid), false);
        if (!empty($grades[$userid]) && $grades[$userid]->is_passed($item)) {
            return true;
        }
    }

    // If a passing grade is required and exhausting all available attempts is not accepted for completion,
    // then this bayesian is not complete.
    if (!$bayesian->completionattemptsexhausted) {
        return false;
    }

    // Check if all attempts are used up.
    $attempts = bayesian_get_user_attempts($bayesian->id, $userid, 'finished', true);
    if (!$attempts) {
        return false;
    }
    $lastfinishedattempt = end($attempts);
    $context = context_module::instance($cm->id);
    $bayesianobj = bayesian::create($bayesian->id, $userid);
    $accessmanager = new bayesian_access_manager($bayesianobj, time(),
            has_capability('mod/bayesian:ignoretimelimits', $context, $userid, false));

    return $accessmanager->is_finished(count($attempts), $lastfinishedattempt);
}

/**
 * Internal function used in bayesian_get_completion_state. Check minimum attempts requirement for completion.
 *
 * @deprecated since Moodle 3.11
 * @todo MDL-71196 Final deprecation in Moodle 4.3
 * @see \mod_bayesian\completion\custom_completion
 * @param int $userid
 * @param stdClass $bayesian
 * @return bool True if minimum attempts requirement is disabled or met.
 */
function bayesian_completion_check_min_attempts($userid, $bayesian) {

    debugging('bayesian_completion_check_min_attempts has been deprecated.', DEBUG_DEVELOPER);

    if (empty($bayesian->completionminattempts)) {
        return true;
    }

    // Check if the user has done enough attempts.
    $attempts = bayesian_get_user_attempts($bayesian->id, $userid, 'finished', true);
    return $bayesian->completionminattempts <= count($attempts);
}

/**
 * Obtains the automatic completion state for this bayesian on any conditions
 * in bayesian settings, such as if all attempts are used or a certain grade is achieved.
 *
 * @deprecated since Moodle 3.11
 * @todo MDL-71196 Final deprecation in Moodle 4.3
 * @see \mod_bayesian\completion\custom_completion
 * @param stdClass $course Course
 * @param cm_info|stdClass $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not. (If no conditions, then return
 *   value depends on comparison type)
 */
function bayesian_get_completion_state($course, $cm, $userid, $type) {
    global $DB;

    // No need to call debugging here. Deprecation debugging notice already being called in \completion_info::internal_get_state().

    $bayesian = $DB->get_record('bayesian', array('id' => $cm->instance), '*', MUST_EXIST);
    if (!$bayesian->completionattemptsexhausted && !$cm->completionpassgrade && !$bayesian->completionminattempts) {
        return $type;
    }

    if (!bayesian_completion_check_passing_grade_or_all_attempts($course, $cm, $userid, $bayesian)) {
        return false;
    }

    if (!bayesian_completion_check_min_attempts($userid, $bayesian)) {
        return false;
    }

    return true;
}
