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

namespace mod_bayesian;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/bayesian_question_helper_test_trait.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/mod/bayesian/locallib.php');

/**
 * bayesian backup and restore tests.
 *
 * @package    mod_bayesian
 * @category   test
 * @copyright  2021 Catalyst IT Australia Pty Ltd
 * @author     Safat Shahin <safatshahin@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversDefaultClass \mod_bayesian\question\bank\qbank_helper
 * @coversDefaultClass \backup_bayesian_activity_structure_step
 * @coversDefaultClass \restore_bayesian_activity_structure_step
 */
class bayesian_question_restore_test extends \advanced_testcase {
    use \bayesian_question_helper_test_trait;

    /**
     * @var \stdClass test student user.
     */
    protected $student;

    /**
     * Called before every test.
     */
    public function setUp(): void {
        global $USER;
        parent::setUp();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course();
        $this->student = $this->getDataGenerator()->create_user();
        $this->user = $USER;
    }

    /**
     * Test a bayesian backup and restore in a different course without attempts for course question bank.
     *
     * @covers ::get_question_structure
     */
    public function test_bayesian_restore_in_a_different_course_using_course_question_bank() {
        $this->resetAfterTest();

        // Create the test bayesian.
        $bayesian = $this->create_test_bayesian($this->course);
        $oldbayesiancontext = \context_module::instance($bayesian->cmid);
        // Test for questions from a different context.
        $coursecontext = \context_course::instance($this->course->id);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $this->add_two_regular_questions($questiongenerator, $bayesian, ['contextid' => $coursecontext->id]);
        $this->add_one_random_question($questiongenerator, $bayesian, ['contextid' => $coursecontext->id]);

        // Make the backup.
        $backupid = $this->backup_bayesian($bayesian, $this->user);

        // Delete the current course to make sure there is no data.
        delete_course($this->course, false);

        // Check if the questions and associated data are deleted properly.
        $this->assertEquals(0, count(\mod_bayesian\question\bank\qbank_helper::get_question_structure(
                $bayesian->id, $oldbayesiancontext)));

        // Restore the course.
        $newcourse = $this->getDataGenerator()->create_course();
        $this->restore_bayesian($backupid, $newcourse, $this->user);

        // Verify.
        $modules = get_fast_modinfo($newcourse->id)->get_instances_of('bayesian');
        $module = reset($modules);
        $questions = \mod_bayesian\question\bank\qbank_helper::get_question_structure(
                $module->instance, $module->context);
        $this->assertCount(3, $questions);
    }

    /**
     * Test a bayesian backup and restore in a different course without attempts for bayesian question bank.
     *
     * @covers ::get_question_structure
     */
    public function test_bayesian_restore_in_a_different_course_using_bayesian_question_bank() {
        $this->resetAfterTest();

        // Create the test bayesian.
        $bayesian = $this->create_test_bayesian($this->course);
        // Test for questions from a different context.
        $bayesiancontext = \context_module::instance($bayesian->cmid);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $this->add_two_regular_questions($questiongenerator, $bayesian, ['contextid' => $bayesiancontext->id]);
        $this->add_one_random_question($questiongenerator, $bayesian, ['contextid' => $bayesiancontext->id]);

        // Make the backup.
        $backupid = $this->backup_bayesian($bayesian, $this->user);

        // Delete the current course to make sure there is no data.
        delete_course($this->course, false);

        // Check if the questions and associated datas are deleted properly.
        $this->assertEquals(0, count(\mod_bayesian\question\bank\qbank_helper::get_question_structure(
                $bayesian->id, $bayesiancontext)));

        // Restore the course.
        $newcourse = $this->getDataGenerator()->create_course();
        $this->restore_bayesian($backupid, $newcourse, $this->user);

        // Verify.
        $modules = get_fast_modinfo($newcourse->id)->get_instances_of('bayesian');
        $module = reset($modules);
        $this->assertEquals(3, count(\mod_bayesian\question\bank\qbank_helper::get_question_structure(
                $module->instance, $module->context)));
    }

    /**
     * Count the questions for the context.
     *
     * @param int $contextid
     * @param string $extracondition
     * @return int the number of questions.
     */
    protected function question_count(int $contextid, string $extracondition = ''): int {
        global $DB;
        return $DB->count_records_sql(
            "SELECT COUNT(q.id)
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc on qc.id = qbe.questioncategoryid
              WHERE qc.contextid = ?
              $extracondition", [$contextid]);
    }

    /**
     * Test if a duplicate does not duplicate questions in course question bank.
     *
     * @covers ::duplicate_module
     */
    public function test_bayesian_duplicate_does_not_duplicate_course_question_bank_questions() {
        $this->resetAfterTest();
        $bayesian = $this->create_test_bayesian($this->course);
        // Test for questions from a different context.
        $context = \context_course::instance($this->course->id);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $this->add_two_regular_questions($questiongenerator, $bayesian, ['contextid' => $context->id]);
        $this->add_one_random_question($questiongenerator, $bayesian, ['contextid' => $context->id]);
        // Count the questions in course context.
        $this->assertEquals(7, $this->question_count($context->id));
        $newbayesian = $this->duplicate_bayesian($this->course, $bayesian);
        $this->assertEquals(7, $this->question_count($context->id));
        $context = \context_module::instance($newbayesian->id);
        // Count the questions in the bayesian context.
        $this->assertEquals(0, $this->question_count($context->id));
    }

    /**
     * Test bayesian duplicate for bayesian question bank.
     *
     * @covers ::duplicate_module
     */
    public function test_bayesian_duplicate_for_bayesian_question_bank_questions() {
        $this->resetAfterTest();
        $bayesian = $this->create_test_bayesian($this->course);
        // Test for questions from a different context.
        $context = \context_module::instance($bayesian->cmid);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $this->add_two_regular_questions($questiongenerator, $bayesian, ['contextid' => $context->id]);
        $this->add_one_random_question($questiongenerator, $bayesian, ['contextid' => $context->id]);
        // Count the questions in course context.
        $this->assertEquals(7, $this->question_count($context->id));
        $newbayesian = $this->duplicate_bayesian($this->course, $bayesian);
        $this->assertEquals(7, $this->question_count($context->id));
        $context = \context_module::instance($newbayesian->id);
        // Count the questions in the bayesian context.
        $this->assertEquals(7, $this->question_count($context->id));
    }

    /**
     * Test bayesian restore with attempts.
     *
     * @covers ::get_question_structure
     */
    public function test_bayesian_restore_with_attempts() {
        $this->resetAfterTest();

        // Create a bayesian.
        $bayesian = $this->create_test_bayesian($this->course);
        $bayesiancontext = \context_module::instance($bayesian->cmid);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $this->add_two_regular_questions($questiongenerator, $bayesian, ['contextid' => $bayesiancontext->id]);
        $this->add_one_random_question($questiongenerator, $bayesian, ['contextid' => $bayesiancontext->id]);

        // Attempt it as a student, and check.
        /** @var \question_usage_by_activity $quba */
        [, $quba] = $this->attempt_bayesian($bayesian, $this->student);
        $this->assertEquals(3, $quba->question_count());
        $this->assertCount(1, bayesian_get_user_attempts($bayesian->id, $this->student->id));

        // Make the backup.
        $backupid = $this->backup_bayesian($bayesian, $this->user);

        // Delete the current course to make sure there is no data.
        delete_course($this->course, false);

        // Restore the backup.
        $newcourse = $this->getDataGenerator()->create_course();
        $this->restore_bayesian($backupid, $newcourse, $this->user);

        // Verify.
        $modules = get_fast_modinfo($newcourse->id)->get_instances_of('bayesian');
        $module = reset($modules);
        $this->assertCount(1, bayesian_get_user_attempts($module->instance, $this->student->id));
        $this->assertCount(3, \mod_bayesian\question\bank\qbank_helper::get_question_structure(
                $module->instance, $module->context));
    }

    /**
     * Test pre 4.0 bayesian restore for regular questions.
     *
     * @covers ::process_bayesian_question_legacy_instance
     */
    public function test_pre_4_bayesian_restore_for_regular_questions() {
        global $USER, $DB;
        $this->resetAfterTest();
        $backupid = 'abc';
        $backuppath = make_backup_temp_directory($backupid);
        get_file_packer('application/vnd.moodle.backup')->extract_to_pathname(
            __DIR__ . "/fixtures/moodle_28_bayesian.mbz", $backuppath);

        // Do the restore to new course with default settings.
        $categoryid = $DB->get_field_sql("SELECT MIN(id) FROM {course_categories}");
        $newcourseid = \restore_dbops::create_new_course('Test fullname', 'Test shortname', $categoryid);
        $rc = new \restore_controller($backupid, $newcourseid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id,
            \backup::TARGET_NEW_COURSE);

        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        // Get the information about the resulting course and check that it is set up correctly.
        $modinfo = get_fast_modinfo($newcourseid);
        $bayesian = array_values($modinfo->get_instances_of('bayesian'))[0];
        $bayesianobj = \bayesian::create($bayesian->instance);
        $structure = structure::create_for_bayesian($bayesianobj);

        // Are the correct slots returned?
        $slots = $structure->get_slots();
        $this->assertCount(2, $slots);

        $bayesianobj->preload_questions();
        $bayesianobj->load_questions();
        $questions = $bayesianobj->get_questions();
        $this->assertCount(2, $questions);

        // Count the questions in bayesian qbank.
        $this->assertEquals(2, $this->question_count($bayesianobj->get_context()->id));
    }

    /**
     * Test pre 4.0 bayesian restore for random questions.
     *
     * @covers ::process_bayesian_question_legacy_instance
     */
    public function test_pre_4_bayesian_restore_for_random_questions() {
        global $USER, $DB;
        $this->resetAfterTest();

        $backupid = 'abc';
        $backuppath = make_backup_temp_directory($backupid);
        get_file_packer('application/vnd.moodle.backup')->extract_to_pathname(
            __DIR__ . "/fixtures/random_by_tag_bayesian.mbz", $backuppath);

        // Do the restore to new course with default settings.
        $categoryid = $DB->get_field_sql("SELECT MIN(id) FROM {course_categories}");
        $newcourseid = \restore_dbops::create_new_course('Test fullname', 'Test shortname', $categoryid);
        $rc = new \restore_controller($backupid, $newcourseid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id,
            \backup::TARGET_NEW_COURSE);

        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        // Get the information about the resulting course and check that it is set up correctly.
        $modinfo = get_fast_modinfo($newcourseid);
        $bayesian = array_values($modinfo->get_instances_of('bayesian'))[0];
        $bayesianobj = \bayesian::create($bayesian->instance);
        $structure = structure::create_for_bayesian($bayesianobj);

        // Are the correct slots returned?
        $slots = $structure->get_slots();
        $this->assertCount(1, $slots);

        $bayesianobj->preload_questions();
        $bayesianobj->load_questions();
        $questions = $bayesianobj->get_questions();
        $this->assertCount(1, $questions);

        // Count the questions for course question bank.
        $this->assertEquals(6, $this->question_count(\context_course::instance($newcourseid)->id));
        $this->assertEquals(6, $this->question_count(\context_course::instance($newcourseid)->id,
            "AND q.qtype <> 'random'"));

        // Count the questions in bayesian qbank.
        $this->assertEquals(0, $this->question_count($bayesianobj->get_context()->id));
    }

    /**
     * Test pre 4.0 bayesian restore for random question tags.
     *
     * @covers ::process_bayesian_question_legacy_instance
     */
    public function test_pre_4_bayesian_restore_for_random_question_tags() {
        global $USER, $DB;
        $this->resetAfterTest();
        $randomtags = [
            '1' => ['first question', 'one', 'number one'],
            '2' => ['first question', 'one', 'number one'],
            '3' => ['one', 'number one', 'second question'],
        ];
        $backupid = 'abc';
        $backuppath = make_backup_temp_directory($backupid);
        get_file_packer('application/vnd.moodle.backup')->extract_to_pathname(
            __DIR__ . "/fixtures/moodle_311_bayesian.mbz", $backuppath);

        // Do the restore to new course with default settings.
        $categoryid = $DB->get_field_sql("SELECT MIN(id) FROM {course_categories}");
        $newcourseid = \restore_dbops::create_new_course('Test fullname', 'Test shortname', $categoryid);
        $rc = new \restore_controller($backupid, $newcourseid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL, $USER->id,
            \backup::TARGET_NEW_COURSE);

        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        // Get the information about the resulting course and check that it is set up correctly.
        $modinfo = get_fast_modinfo($newcourseid);
        $bayesian = array_values($modinfo->get_instances_of('bayesian'))[0];
        $bayesianobj = \bayesian::create($bayesian->instance);
        $structure = \mod_bayesian\structure::create_for_bayesian($bayesianobj);

        // Count the questions in bayesian qbank.
        $context = \context_module::instance(get_coursemodule_from_instance("bayesian", $bayesianobj->get_bayesianid(), $newcourseid)->id);
        $this->assertEquals(2, $this->question_count($context->id));

        // Are the correct slots returned?
        $slots = $structure->get_slots();
        $this->assertCount(3, $slots);

        // Check if the tags match with the actual restored data.
        foreach ($slots as $slot) {
            $setreference = $DB->get_record('question_set_references',
                ['itemid' => $slot->id, 'component' => 'mod_bayesian', 'questionarea' => 'slot']);
            $filterconditions = json_decode($setreference->filtercondition);
            $tags = [];
            foreach ($filterconditions->tags as $tagstring) {
                $tag = explode(',', $tagstring);
                $tags[] = $tag[1];
            }
            $this->assertEquals([], array_diff($randomtags[$slot->slot], $tags));
        }

    }
}
