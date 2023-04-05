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
 * @package    mod_bayesian
 * @subpackage backup-moodle2
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/bayesian/backup/moodle2/restore_bayesian_stepslib.php');


/**
 * bayesian restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 *
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_bayesian_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // bayesian only has one structure step.
        $this->add_step(new restore_bayesian_activity_structure_step('bayesian_structure', 'bayesian.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    public static function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('bayesian', array('intro'), 'bayesian');
        $contents[] = new restore_decode_content('bayesian_feedback',
                array('feedbacktext'), 'bayesian_feedback');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    public static function define_decode_rules() {
        $rules = array();

        $rules[] = new restore_decode_rule('bayesianVIEWBYID',
                '/mod/bayesian/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('bayesianVIEWBYQ',
                '/mod/bayesian/view.php?q=$1', 'bayesian');
        $rules[] = new restore_decode_rule('bayesianINDEX',
                '/mod/bayesian/index.php?id=$1', 'course');

        return $rules;

    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * bayesian logs. It must return one array
     * of {@link restore_log_rule} objects
     */
    public static function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('bayesian', 'add',
                'view.php?id={course_module}', '{bayesian}');
        $rules[] = new restore_log_rule('bayesian', 'update',
                'view.php?id={course_module}', '{bayesian}');
        $rules[] = new restore_log_rule('bayesian', 'view',
                'view.php?id={course_module}', '{bayesian}');
        $rules[] = new restore_log_rule('bayesian', 'preview',
                'view.php?id={course_module}', '{bayesian}');
        $rules[] = new restore_log_rule('bayesian', 'report',
                'report.php?id={course_module}', '{bayesian}');
        $rules[] = new restore_log_rule('bayesian', 'editquestions',
                'view.php?id={course_module}', '{bayesian}');
        $rules[] = new restore_log_rule('bayesian', 'delete attempt',
                'report.php?id={course_module}', '[oldattempt]');
        $rules[] = new restore_log_rule('bayesian', 'edit override',
                'overrideedit.php?id={bayesian_override}', '{bayesian}');
        $rules[] = new restore_log_rule('bayesian', 'delete override',
                'overrides.php.php?cmid={course_module}', '{bayesian}');
        $rules[] = new restore_log_rule('bayesian', 'addcategory',
                'view.php?id={course_module}', '{question_category}');
        $rules[] = new restore_log_rule('bayesian', 'view summary',
                'summary.php?attempt={bayesian_attempt}', '{bayesian}');
        $rules[] = new restore_log_rule('bayesian', 'manualgrade',
                'comment.php?attempt={bayesian_attempt}&question={question}', '{bayesian}');
        $rules[] = new restore_log_rule('bayesian', 'manualgrading',
                'report.php?mode=grading&q={bayesian}', '{bayesian}');
        // All the ones calling to review.php have two rules to handle both old and new urls
        // in any case they are always converted to new urls on restore.
        // TODO: In Moodle 2.x (x >= 5) kill the old rules.
        // Note we are using the 'bayesian_attempt' mapping because that is the
        // one containing the bayesian_attempt->ids old an new for bayesian-attempt.
        $rules[] = new restore_log_rule('bayesian', 'attempt',
                'review.php?id={course_module}&attempt={bayesian_attempt}', '{bayesian}',
                null, null, 'review.php?attempt={bayesian_attempt}');
        $rules[] = new restore_log_rule('bayesian', 'attempt',
                'review.php?attempt={bayesian_attempt}', '{bayesian}',
                null, null, 'review.php?attempt={bayesian_attempt}');
        // Old an new for bayesian-submit.
        $rules[] = new restore_log_rule('bayesian', 'submit',
                'review.php?id={course_module}&attempt={bayesian_attempt}', '{bayesian}',
                null, null, 'review.php?attempt={bayesian_attempt}');
        $rules[] = new restore_log_rule('bayesian', 'submit',
                'review.php?attempt={bayesian_attempt}', '{bayesian}');
        // Old an new for bayesian-review.
        $rules[] = new restore_log_rule('bayesian', 'review',
                'review.php?id={course_module}&attempt={bayesian_attempt}', '{bayesian}',
                null, null, 'review.php?attempt={bayesian_attempt}');
        $rules[] = new restore_log_rule('bayesian', 'review',
                'review.php?attempt={bayesian_attempt}', '{bayesian}');
        // Old an new for bayesian-start attemp.
        $rules[] = new restore_log_rule('bayesian', 'start attempt',
                'review.php?id={course_module}&attempt={bayesian_attempt}', '{bayesian}',
                null, null, 'review.php?attempt={bayesian_attempt}');
        $rules[] = new restore_log_rule('bayesian', 'start attempt',
                'review.php?attempt={bayesian_attempt}', '{bayesian}');
        // Old an new for bayesian-close attemp.
        $rules[] = new restore_log_rule('bayesian', 'close attempt',
                'review.php?id={course_module}&attempt={bayesian_attempt}', '{bayesian}',
                null, null, 'review.php?attempt={bayesian_attempt}');
        $rules[] = new restore_log_rule('bayesian', 'close attempt',
                'review.php?attempt={bayesian_attempt}', '{bayesian}');
        // Old an new for bayesian-continue attempt.
        $rules[] = new restore_log_rule('bayesian', 'continue attempt',
                'review.php?id={course_module}&attempt={bayesian_attempt}', '{bayesian}',
                null, null, 'review.php?attempt={bayesian_attempt}');
        $rules[] = new restore_log_rule('bayesian', 'continue attempt',
                'review.php?attempt={bayesian_attempt}', '{bayesian}');
        // Old an new for bayesian-continue attemp.
        $rules[] = new restore_log_rule('bayesian', 'continue attemp',
                'review.php?id={course_module}&attempt={bayesian_attempt}', '{bayesian}',
                null, 'continue attempt', 'review.php?attempt={bayesian_attempt}');
        $rules[] = new restore_log_rule('bayesian', 'continue attemp',
                'review.php?attempt={bayesian_attempt}', '{bayesian}',
                null, 'continue attempt');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * course logs. It must return one array
     * of {@link restore_log_rule} objects
     *
     * Note this rules are applied when restoring course logs
     * by the restore final task, but are defined here at
     * activity level. All them are rules not linked to any module instance (cmid = 0)
     */
    public static function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('bayesian', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}
