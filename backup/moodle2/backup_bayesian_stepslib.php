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
 * Define all the backup steps that will be used by the backup_bayesian_activity_task.
 *
 * @package    mod_bayesian
 * @subpackage backup-moodle2
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_bayesian_activity_structure_step extends backup_questions_activity_structure_step {

    protected function define_structure() {

        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element separated.
        $bayesian = new backup_nested_element('bayesian', ['id'], [
            'name', 'intro', 'introformat', 'timeopen', 'timeclose', 'timelimit',
            'overduehandling', 'graceperiod', 'preferredbehaviour', 'canredoquestions', 'attempts_number',
            'attemptonlast', 'grademethod', 'decimalpoints', 'questiondecimalpoints',
            'reviewattempt', 'reviewcorrectness', 'reviewmarks',
            'reviewspecificfeedback', 'reviewgeneralfeedback',
            'reviewrightanswer', 'reviewoverallfeedback',
            'questionsperpage', 'navmethod', 'shuffleanswers',
            'sumgrades', 'grade', 'timecreated',
            'timemodified', 'password', 'subnet', 'browsersecurity',
            'delay1', 'delay2', 'showuserpicture', 'showblocks', 'completionattemptsexhausted',
            'completionminattempts', 'allowofflineattempts']);

        // Define elements for access rule subplugin settings.
        $this->add_subplugin_structure('bayesianaccess', $bayesian, true);

        $qinstances = new backup_nested_element('question_instances');

        $qinstance = new backup_nested_element('question_instance', ['id'],
            ['slot', 'page', 'requireprevious', 'questionid', 'questioncategoryid', 'includingsubcategories', 'maxmark']);

        $this->add_question_references($qinstance, 'mod_bayesian', 'slot');

        $this->add_question_set_references($qinstance, 'mod_bayesian', 'slot');

        $sections = new backup_nested_element('sections');

        $section = new backup_nested_element('section', ['id'], ['firstslot', 'heading', 'shufflequestions']);

        $feedbacks = new backup_nested_element('feedbacks');

        $feedback = new backup_nested_element('feedback', ['id'], ['feedbacktext', 'feedbacktextformat', 'mingrade', 'maxgrade']);

        $overrides = new backup_nested_element('overrides');

        $override = new backup_nested_element('override', ['id'], [
            'userid', 'groupid', 'timeopen', 'timeclose',
            'timelimit', 'attempts', 'password']);

        $grades = new backup_nested_element('grades');

        $grade = new backup_nested_element('grade', ['id'], ['userid', 'gradeval', 'timemodified']);

        $attempts = new backup_nested_element('attempts');

        $attempt = new backup_nested_element('attempt', ['id'], [
            'userid', 'attemptnum', 'uniqueid', 'layout', 'currentpage', 'preview',
            'state', 'timestart', 'timefinish', 'timemodified', 'timemodifiedoffline',
            'timecheckstate', 'sumgrades', 'gradednotificationsenttime']);

        // This module is using questions, so produce the related question states and sessions
        // attaching them to the $attempt element based in 'uniqueid' matching.
        $this->add_question_usages($attempt, 'uniqueid');

        // Define elements for access rule subplugin attempt data.
        $this->add_subplugin_structure('bayesianaccess', $attempt, true);

        // Build the tree.
        $bayesian->add_child($qinstances);
        $qinstances->add_child($qinstance);

        $bayesian->add_child($sections);
        $sections->add_child($section);

        $bayesian->add_child($feedbacks);
        $feedbacks->add_child($feedback);

        $bayesian->add_child($overrides);
        $overrides->add_child($override);

        $bayesian->add_child($grades);
        $grades->add_child($grade);

        $bayesian->add_child($attempts);
        $attempts->add_child($attempt);

        // Define sources.
        $bayesian->set_source_table('bayesian', ['id' => backup::VAR_ACTIVITYID]);

        $qinstance->set_source_table('bayesian_slots', ['bayesianid' => backup::VAR_PARENTID]);

        $section->set_source_table('bayesian_sections', ['bayesianid' => backup::VAR_PARENTID]);

        $feedback->set_source_table('bayesian_feedback', ['bayesianid' => backup::VAR_PARENTID]);

        // bayesian overrides to backup are different depending of user info.
        $overrideparams = ['bayesian' => backup::VAR_PARENTID];
        if (!$userinfo) { //  Without userinfo, skip user overrides.
            $overrideparams['userid'] = backup_helper::is_sqlparam(null);

        }

        // Skip group overrides if not including groups.
        $groupinfo = $this->get_setting_value('groups');
        if (!$groupinfo) {
            $overrideparams['groupid'] = backup_helper::is_sqlparam(null);
        }

        $override->set_source_table('bayesian_overrides', $overrideparams);

        // All the rest of elements only happen if we are including user info.
        if ($userinfo) {
            $grade->set_source_table('bayesian_grades', ['bayesian' => backup::VAR_PARENTID]);
            $attempt->set_source_sql('
                    SELECT *
                    FROM {bayesian_attempts}
                    WHERE bayesian = :bayesian AND preview = 0', ['bayesian' => backup::VAR_PARENTID]);
        }

        // Define source alias.
        $bayesian->set_source_alias('attempts', 'attempts_number');
        $grade->set_source_alias('grade', 'gradeval');
        $attempt->set_source_alias('attempt', 'attemptnum');

        // Define id annotations.
        $qinstance->annotate_ids('question', 'questionid');
        $override->annotate_ids('user', 'userid');
        $override->annotate_ids('group', 'groupid');
        $grade->annotate_ids('user', 'userid');
        $attempt->annotate_ids('user', 'userid');

        // Define file annotations.
        $bayesian->annotate_files('mod_bayesian', 'intro', null); // This file area hasn't itemid.
        $feedback->annotate_files('mod_bayesian', 'feedback', 'id');

        // Return the root element (bayesian), wrapped into standard activity structure.
        return $this->prepare_activity_structure($bayesian);
    }
}
