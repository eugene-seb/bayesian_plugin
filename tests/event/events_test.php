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
 * bayesian events tests.
 *
 * @package    mod_bayesian
 * @category   phpunit
 * @copyright  2013 Adrian Greeve
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_bayesian\event;

use bayesian;
use bayesian_attempt;
use context_module;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/bayesian/attemptlib.php');

/**
 * Unit tests for bayesian events.
 *
 * @package    mod_bayesian
 * @category   phpunit
 * @copyright  2013 Adrian Greeve
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class events_test extends \advanced_testcase {

    /**
     * Setup a bayesian.
     *
     * @return bayesian the generated bayesian.
     */
    protected function prepare_bayesian() {

        $this->resetAfterTest(true);

        // Create a course
        $course = $this->getDataGenerator()->create_course();

        // Make a bayesian.
        $bayesiangenerator = $this->getDataGenerator()->get_plugin_generator('mod_bayesian');

        $bayesian = $bayesiangenerator->create_instance(array('course' => $course->id, 'questionsperpage' => 0,
                'grade' => 100.0, 'sumgrades' => 2));

        $cm = get_coursemodule_from_instance('bayesian', $bayesian->id, $course->id);

        // Create a couple of questions.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $cat = $questiongenerator->create_question_category();
        $saq = $questiongenerator->create_question('shortanswer', null, array('category' => $cat->id));
        $numq = $questiongenerator->create_question('numerical', null, array('category' => $cat->id));

        // Add them to the bayesian.
        bayesian_add_bayesian_question($saq->id, $bayesian);
        bayesian_add_bayesian_question($numq->id, $bayesian);

        // Make a user to do the bayesian.
        $user1 = $this->getDataGenerator()->create_user();
        $this->setUser($user1);

        return bayesian::create($bayesian->id, $user1->id);
    }

    /**
     * Setup a bayesian attempt at the bayesian created by {@link prepare_bayesian()}.
     *
     * @param bayesian $bayesianobj the generated bayesian.
     * @param bool $ispreview Make the attempt a preview attempt when true.
     * @return array with three elements, array($bayesianobj, $quba, $attempt)
     */
    protected function prepare_bayesian_attempt($bayesianobj, $ispreview = false) {
        // Start the attempt.
        $quba = \question_engine::make_questions_usage_by_activity('mod_bayesian', $bayesianobj->get_context());
        $quba->set_preferred_behaviour($bayesianobj->get_bayesian()->preferredbehaviour);

        $timenow = time();
        $attempt = bayesian_create_attempt($bayesianobj, 1, false, $timenow, $ispreview);
        bayesian_start_new_attempt($bayesianobj, $quba, $attempt, 1, $timenow);
        bayesian_attempt_save_started($bayesianobj, $quba, $attempt);

        return array($bayesianobj, $quba, $attempt);
    }

    /**
     * Setup some convenience test data with a single attempt.
     *
     * @param bool $ispreview Make the attempt a preview attempt when true.
     * @return array with three elements, array($bayesianobj, $quba, $attempt)
     */
    protected function prepare_bayesian_data($ispreview = false) {
        $bayesianobj = $this->prepare_bayesian();
        return $this->prepare_bayesian_attempt($bayesianobj, $ispreview);
    }

    public function test_attempt_submitted() {

        list($bayesianobj, $quba, $attempt) = $this->prepare_bayesian_data();
        $attemptobj = bayesian_attempt::create($attempt->id);

        // Catch the event.
        $sink = $this->redirectEvents();

        $timefinish = time();
        $attemptobj->process_finish($timefinish, false);
        $events = $sink->get_events();
        $sink->close();

        // Validate the event.
        $this->assertCount(3, $events);
        $event = $events[2];
        $this->assertInstanceOf('\mod_bayesian\event\attempt_submitted', $event);
        $this->assertEquals('bayesian_attempts', $event->objecttable);
        $this->assertEquals($bayesianobj->get_context(), $event->get_context());
        $this->assertEquals($attempt->userid, $event->relateduserid);
        $this->assertEquals(null, $event->other['submitterid']); // Should be the user, but PHP Unit complains...
        $this->assertEquals('bayesian_attempt_submitted', $event->get_legacy_eventname());
        $legacydata = new \stdClass();
        $legacydata->component = 'mod_bayesian';
        $legacydata->attemptid = (string) $attempt->id;
        $legacydata->timestamp = $timefinish;
        $legacydata->userid = $attempt->userid;
        $legacydata->cmid = $bayesianobj->get_cmid();
        $legacydata->courseid = $bayesianobj->get_courseid();
        $legacydata->bayesianid = $bayesianobj->get_bayesianid();
        // Submitterid should be the user, but as we are in PHP Unit, CLI_SCRIPT is set to true which sets null in submitterid.
        $legacydata->submitterid = null;
        $legacydata->timefinish = $timefinish;
        $this->assertEventLegacyData($legacydata, $event);
        $this->assertEventContextNotUsed($event);
    }

    public function test_attempt_becameoverdue() {

        list($bayesianobj, $quba, $attempt) = $this->prepare_bayesian_data();
        $attemptobj = bayesian_attempt::create($attempt->id);

        // Catch the event.
        $sink = $this->redirectEvents();
        $timefinish = time();
        $attemptobj->process_going_overdue($timefinish, false);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf('\mod_bayesian\event\attempt_becameoverdue', $event);
        $this->assertEquals('bayesian_attempts', $event->objecttable);
        $this->assertEquals($bayesianobj->get_context(), $event->get_context());
        $this->assertEquals($attempt->userid, $event->relateduserid);
        $this->assertNotEmpty($event->get_description());
        // Submitterid should be the user, but as we are in PHP Unit, CLI_SCRIPT is set to true which sets null in submitterid.
        $this->assertEquals(null, $event->other['submitterid']);
        $this->assertEquals('bayesian_attempt_overdue', $event->get_legacy_eventname());
        $legacydata = new \stdClass();
        $legacydata->component = 'mod_bayesian';
        $legacydata->attemptid = (string) $attempt->id;
        $legacydata->timestamp = $timefinish;
        $legacydata->userid = $attempt->userid;
        $legacydata->cmid = $bayesianobj->get_cmid();
        $legacydata->courseid = $bayesianobj->get_courseid();
        $legacydata->bayesianid = $bayesianobj->get_bayesianid();
        $legacydata->submitterid = null; // Should be the user, but PHP Unit complains...
        $this->assertEventLegacyData($legacydata, $event);
        $this->assertEventContextNotUsed($event);
    }

    public function test_attempt_abandoned() {

        list($bayesianobj, $quba, $attempt) = $this->prepare_bayesian_data();
        $attemptobj = bayesian_attempt::create($attempt->id);

        // Catch the event.
        $sink = $this->redirectEvents();
        $timefinish = time();
        $attemptobj->process_abandon($timefinish, false);
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf('\mod_bayesian\event\attempt_abandoned', $event);
        $this->assertEquals('bayesian_attempts', $event->objecttable);
        $this->assertEquals($bayesianobj->get_context(), $event->get_context());
        $this->assertEquals($attempt->userid, $event->relateduserid);
        // Submitterid should be the user, but as we are in PHP Unit, CLI_SCRIPT is set to true which sets null in submitterid.
        $this->assertEquals(null, $event->other['submitterid']);
        $this->assertEquals('bayesian_attempt_abandoned', $event->get_legacy_eventname());
        $legacydata = new \stdClass();
        $legacydata->component = 'mod_bayesian';
        $legacydata->attemptid = (string) $attempt->id;
        $legacydata->timestamp = $timefinish;
        $legacydata->userid = $attempt->userid;
        $legacydata->cmid = $bayesianobj->get_cmid();
        $legacydata->courseid = $bayesianobj->get_courseid();
        $legacydata->bayesianid = $bayesianobj->get_bayesianid();
        $legacydata->submitterid = null; // Should be the user, but PHP Unit complains...
        $this->assertEventLegacyData($legacydata, $event);
        $this->assertEventContextNotUsed($event);
    }

    public function test_attempt_started() {
        $bayesianobj = $this->prepare_bayesian();

        $quba = \question_engine::make_questions_usage_by_activity('mod_bayesian', $bayesianobj->get_context());
        $quba->set_preferred_behaviour($bayesianobj->get_bayesian()->preferredbehaviour);

        $timenow = time();
        $attempt = bayesian_create_attempt($bayesianobj, 1, false, $timenow);
        bayesian_start_new_attempt($bayesianobj, $quba, $attempt, 1, $timenow);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        bayesian_attempt_save_started($bayesianobj, $quba, $attempt);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\attempt_started', $event);
        $this->assertEquals('bayesian_attempts', $event->objecttable);
        $this->assertEquals($attempt->id, $event->objectid);
        $this->assertEquals($attempt->userid, $event->relateduserid);
        $this->assertEquals($bayesianobj->get_context(), $event->get_context());
        $this->assertEquals('bayesian_attempt_started', $event->get_legacy_eventname());
        $this->assertEquals(\context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        // Check legacy log data.
        $expected = array($bayesianobj->get_courseid(), 'bayesian', 'attempt', 'review.php?attempt=' . $attempt->id,
            $bayesianobj->get_bayesianid(), $bayesianobj->get_cmid());
        $this->assertEventLegacyLogData($expected, $event);
        // Check legacy event data.
        $legacydata = new \stdClass();
        $legacydata->component = 'mod_bayesian';
        $legacydata->attemptid = $attempt->id;
        $legacydata->timestart = $attempt->timestart;
        $legacydata->timestamp = $attempt->timestart;
        $legacydata->userid = $attempt->userid;
        $legacydata->bayesianid = $bayesianobj->get_bayesianid();
        $legacydata->cmid = $bayesianobj->get_cmid();
        $legacydata->courseid = $bayesianobj->get_courseid();
        $this->assertEventLegacyData($legacydata, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt question restarted event.
     *
     * There is no external API for replacing a question, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_question_restarted() {
        list($bayesianobj, $quba, $attempt) = $this->prepare_bayesian_data();

        $params = [
            'objectid' => 1,
            'relateduserid' => 2,
            'courseid' => $bayesianobj->get_courseid(),
            'context' => \context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'page' => 2,
                'slot' => 3,
                'newquestionid' => 2
            ]
        ];
        $event = \mod_bayesian\event\attempt_question_restarted::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\attempt_question_restarted', $event);
        $this->assertEquals(\context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt updated event.
     *
     * There is no external API for updating an attempt, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_updated() {
        list($bayesianobj, $quba, $attempt) = $this->prepare_bayesian_data();

        $params = [
            'objectid' => 1,
            'relateduserid' => 2,
            'courseid' => $bayesianobj->get_courseid(),
            'context' => \context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'page' => 0
            ]
        ];
        $event = \mod_bayesian\event\attempt_updated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\attempt_updated', $event);
        $this->assertEquals(\context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt auto-saved event.
     *
     * There is no external API for auto-saving an attempt, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_autosaved() {
        list($bayesianobj, $quba, $attempt) = $this->prepare_bayesian_data();

        $params = [
            'objectid' => 1,
            'relateduserid' => 2,
            'courseid' => $bayesianobj->get_courseid(),
            'context' => \context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'page' => 0
            ]
        ];

        $event = \mod_bayesian\event\attempt_autosaved::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\attempt_autosaved', $event);
        $this->assertEquals(\context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the edit page viewed event.
     *
     * There is no external API for updating a bayesian, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_edit_page_viewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bayesian = $this->getDataGenerator()->create_module('bayesian', array('course' => $course->id));

        $params = array(
            'courseid' => $course->id,
            'context' => \context_module::instance($bayesian->cmid),
            'other' => array(
                'bayesianid' => $bayesian->id
            )
        );
        $event = \mod_bayesian\event\edit_page_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\edit_page_viewed', $event);
        $this->assertEquals(\context_module::instance($bayesian->cmid), $event->get_context());
        $expected = array($course->id, 'bayesian', 'editquestions', 'view.php?id=' . $bayesian->cmid, $bayesian->id, $bayesian->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt deleted event.
     */
    public function test_attempt_deleted() {
        list($bayesianobj, $quba, $attempt) = $this->prepare_bayesian_data();

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        bayesian_delete_attempt($attempt, $bayesianobj->get_bayesian());
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\attempt_deleted', $event);
        $this->assertEquals(\context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $expected = array($bayesianobj->get_courseid(), 'bayesian', 'delete attempt', 'report.php?id=' . $bayesianobj->get_cmid(),
            $attempt->id, $bayesianobj->get_cmid());
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test that preview attempt deletions are not logged.
     */
    public function test_preview_attempt_deleted() {
        // Create bayesian with preview attempt.
        list($bayesianobj, $quba, $previewattempt) = $this->prepare_bayesian_data(true);

        // Delete a preview attempt, capturing events.
        $sink = $this->redirectEvents();
        bayesian_delete_attempt($previewattempt, $bayesianobj->get_bayesian());

        // Verify that no events were generated.
        $this->assertEmpty($sink->get_events());
    }

    /**
     * Test the report viewed event.
     *
     * There is no external API for viewing reports, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_report_viewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bayesian = $this->getDataGenerator()->create_module('bayesian', array('course' => $course->id));

        $params = array(
            'context' => $context = \context_module::instance($bayesian->cmid),
            'other' => array(
                'bayesianid' => $bayesian->id,
                'reportname' => 'overview'
            )
        );
        $event = \mod_bayesian\event\report_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\report_viewed', $event);
        $this->assertEquals(\context_module::instance($bayesian->cmid), $event->get_context());
        $expected = array($course->id, 'bayesian', 'report', 'report.php?id=' . $bayesian->cmid . '&mode=overview',
            $bayesian->id, $bayesian->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt reviewed event.
     *
     * There is no external API for reviewing attempts, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_reviewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bayesian = $this->getDataGenerator()->create_module('bayesian', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'courseid' => $course->id,
            'context' => \context_module::instance($bayesian->cmid),
            'other' => array(
                'bayesianid' => $bayesian->id
            )
        );
        $event = \mod_bayesian\event\attempt_reviewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\attempt_reviewed', $event);
        $this->assertEquals(\context_module::instance($bayesian->cmid), $event->get_context());
        $expected = array($course->id, 'bayesian', 'review', 'review.php?attempt=1', $bayesian->id, $bayesian->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt summary viewed event.
     *
     * There is no external API for viewing the attempt summary, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_summary_viewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bayesian = $this->getDataGenerator()->create_module('bayesian', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'courseid' => $course->id,
            'context' => \context_module::instance($bayesian->cmid),
            'other' => array(
                'bayesianid' => $bayesian->id
            )
        );
        $event = \mod_bayesian\event\attempt_summary_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\attempt_summary_viewed', $event);
        $this->assertEquals(\context_module::instance($bayesian->cmid), $event->get_context());
        $expected = array($course->id, 'bayesian', 'view summary', 'summary.php?attempt=1', $bayesian->id, $bayesian->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the user override created event.
     *
     * There is no external API for creating a user override, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_user_override_created() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bayesian = $this->getDataGenerator()->create_module('bayesian', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'context' => \context_module::instance($bayesian->cmid),
            'other' => array(
                'bayesianid' => $bayesian->id
            )
        );
        $event = \mod_bayesian\event\user_override_created::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\user_override_created', $event);
        $this->assertEquals(\context_module::instance($bayesian->cmid), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the group override created event.
     *
     * There is no external API for creating a group override, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_group_override_created() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bayesian = $this->getDataGenerator()->create_module('bayesian', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'context' => \context_module::instance($bayesian->cmid),
            'other' => array(
                'bayesianid' => $bayesian->id,
                'groupid' => 2
            )
        );
        $event = \mod_bayesian\event\group_override_created::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\group_override_created', $event);
        $this->assertEquals(\context_module::instance($bayesian->cmid), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the user override updated event.
     *
     * There is no external API for updating a user override, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_user_override_updated() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bayesian = $this->getDataGenerator()->create_module('bayesian', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'context' => \context_module::instance($bayesian->cmid),
            'other' => array(
                'bayesianid' => $bayesian->id
            )
        );
        $event = \mod_bayesian\event\user_override_updated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\user_override_updated', $event);
        $this->assertEquals(\context_module::instance($bayesian->cmid), $event->get_context());
        $expected = array($course->id, 'bayesian', 'edit override', 'overrideedit.php?id=1', $bayesian->id, $bayesian->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the group override updated event.
     *
     * There is no external API for updating a group override, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_group_override_updated() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bayesian = $this->getDataGenerator()->create_module('bayesian', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'context' => \context_module::instance($bayesian->cmid),
            'other' => array(
                'bayesianid' => $bayesian->id,
                'groupid' => 2
            )
        );
        $event = \mod_bayesian\event\group_override_updated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\group_override_updated', $event);
        $this->assertEquals(\context_module::instance($bayesian->cmid), $event->get_context());
        $expected = array($course->id, 'bayesian', 'edit override', 'overrideedit.php?id=1', $bayesian->id, $bayesian->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the user override deleted event.
     */
    public function test_user_override_deleted() {
        global $DB;

        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bayesian = $this->getDataGenerator()->create_module('bayesian', array('course' => $course->id));

        // Create an override.
        $override = new \stdClass();
        $override->bayesian = $bayesian->id;
        $override->userid = 2;
        $override->id = $DB->insert_record('bayesian_overrides', $override);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        bayesian_delete_override($bayesian, $override->id);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\user_override_deleted', $event);
        $this->assertEquals(\context_module::instance($bayesian->cmid), $event->get_context());
        $expected = array($course->id, 'bayesian', 'delete override', 'overrides.php?cmid=' . $bayesian->cmid, $bayesian->id, $bayesian->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the group override deleted event.
     */
    public function test_group_override_deleted() {
        global $DB;

        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bayesian = $this->getDataGenerator()->create_module('bayesian', array('course' => $course->id));

        // Create an override.
        $override = new \stdClass();
        $override->bayesian = $bayesian->id;
        $override->groupid = 2;
        $override->id = $DB->insert_record('bayesian_overrides', $override);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        bayesian_delete_override($bayesian, $override->id);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\group_override_deleted', $event);
        $this->assertEquals(\context_module::instance($bayesian->cmid), $event->get_context());
        $expected = array($course->id, 'bayesian', 'delete override', 'overrides.php?cmid=' . $bayesian->cmid, $bayesian->id, $bayesian->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt viewed event.
     *
     * There is no external API for continuing an attempt, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_viewed() {
        $this->resetAfterTest();

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $bayesian = $this->getDataGenerator()->create_module('bayesian', array('course' => $course->id));

        $params = array(
            'objectid' => 1,
            'relateduserid' => 2,
            'courseid' => $course->id,
            'context' => \context_module::instance($bayesian->cmid),
            'other' => array(
                'bayesianid' => $bayesian->id,
                'page' => 0
            )
        );
        $event = \mod_bayesian\event\attempt_viewed::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\attempt_viewed', $event);
        $this->assertEquals(\context_module::instance($bayesian->cmid), $event->get_context());
        $expected = array($course->id, 'bayesian', 'continue attempt', 'review.php?attempt=1', $bayesian->id, $bayesian->cmid);
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt previewed event.
     */
    public function test_attempt_preview_started() {
        $bayesianobj = $this->prepare_bayesian();

        $quba = \question_engine::make_questions_usage_by_activity('mod_bayesian', $bayesianobj->get_context());
        $quba->set_preferred_behaviour($bayesianobj->get_bayesian()->preferredbehaviour);

        $timenow = time();
        $attempt = bayesian_create_attempt($bayesianobj, 1, false, $timenow, true);
        bayesian_start_new_attempt($bayesianobj, $quba, $attempt, 1, $timenow);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        bayesian_attempt_save_started($bayesianobj, $quba, $attempt);
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\attempt_preview_started', $event);
        $this->assertEquals(\context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $expected = array($bayesianobj->get_courseid(), 'bayesian', 'preview', 'view.php?id=' . $bayesianobj->get_cmid(),
            $bayesianobj->get_bayesianid(), $bayesianobj->get_cmid());
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the question manually graded event.
     *
     * There is no external API for manually grading a question, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_question_manually_graded() {
        list($bayesianobj, $quba, $attempt) = $this->prepare_bayesian_data();

        $params = array(
            'objectid' => 1,
            'courseid' => $bayesianobj->get_courseid(),
            'context' => \context_module::instance($bayesianobj->get_cmid()),
            'other' => array(
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'attemptid' => 2,
                'slot' => 3
            )
        );
        $event = \mod_bayesian\event\question_manually_graded::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\question_manually_graded', $event);
        $this->assertEquals(\context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $expected = array($bayesianobj->get_courseid(), 'bayesian', 'manualgrade', 'comment.php?attempt=2&slot=3',
            $bayesianobj->get_bayesianid(), $bayesianobj->get_cmid());
        $this->assertEventLegacyLogData($expected, $event);
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt regraded event.
     *
     * There is no external API for regrading attempts, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_regraded() {
      $this->resetAfterTest();

      $this->setAdminUser();
      $course = $this->getDataGenerator()->create_course();
      $bayesian = $this->getDataGenerator()->create_module('bayesian', array('course' => $course->id));

      $params = array(
        'objectid' => 1,
        'relateduserid' => 2,
        'courseid' => $course->id,
        'context' => \context_module::instance($bayesian->cmid),
        'other' => array(
          'bayesianid' => $bayesian->id
        )
      );
      $event = \mod_bayesian\event\attempt_regraded::create($params);

      // Trigger and capture the event.
      $sink = $this->redirectEvents();
      $event->trigger();
      $events = $sink->get_events();
      $event = reset($events);

      // Check that the event data is valid.
      $this->assertInstanceOf('\mod_bayesian\event\attempt_regraded', $event);
      $this->assertEquals(\context_module::instance($bayesian->cmid), $event->get_context());
      $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the attempt notify manual graded event.
     * There is no external API for notification email when manual grading of user's attempt is completed,
     * so the unit test will simply create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_attempt_manual_grading_completed() {
        $this->resetAfterTest();
        list($bayesianobj, $quba, $attempt) = $this->prepare_bayesian_data();
        $attemptobj = bayesian_attempt::create($attempt->id);

        $params = [
            'objectid' => $attemptobj->get_attemptid(),
            'relateduserid' => $attemptobj->get_userid(),
            'courseid' => $attemptobj->get_course()->id,
            'context' => \context_module::instance($attemptobj->get_cmid()),
            'other' => [
                'bayesianid' => $attemptobj->get_bayesianid()
            ]
        ];
        $event = \mod_bayesian\event\attempt_manual_grading_completed::create($params);

        // Catch the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        // Validate the event.
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf('\mod_bayesian\event\attempt_manual_grading_completed', $event);
        $this->assertEquals('bayesian_attempts', $event->objecttable);
        $this->assertEquals($bayesianobj->get_context(), $event->get_context());
        $this->assertEquals($attempt->userid, $event->relateduserid);
        $this->assertNotEmpty($event->get_description());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the page break created event.
     *
     * There is no external API for creating page break, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_page_break_created() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => 1,
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'slotnumber' => 3,
            ]
        ];
        $event = \mod_bayesian\event\page_break_created::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\page_break_created', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the page break deleted event.
     *
     * There is no external API for deleting page break, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_page_deleted_created() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => 1,
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'slotnumber' => 3,
            ]
        ];
        $event = \mod_bayesian\event\page_break_deleted::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\page_break_deleted', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the bayesian grade updated event.
     *
     * There is no external API for updating bayesian grade, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_bayesian_grade_updated() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => $bayesianobj->get_bayesianid(),
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'oldgrade' => 1,
                'newgrade' => 3,
            ]
        ];
        $event = \mod_bayesian\event\bayesian_grade_updated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\bayesian_grade_updated', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the bayesian re-paginated event.
     *
     * There is no external API for re-paginating bayesian, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_bayesian_repaginated() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => $bayesianobj->get_bayesianid(),
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'slotsperpage' => 3,
            ]
        ];
        $event = \mod_bayesian\event\bayesian_repaginated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\bayesian_repaginated', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the section break created event.
     *
     * There is no external API for creating section break, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_section_break_created() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => 1,
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'firstslotid' => 1,
                'firstslotnumber' => 2,
                'title' => 'New title'
            ]
        ];
        $event = \mod_bayesian\event\section_break_created::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\section_break_created', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertStringContainsString($params['other']['title'], $event->get_description());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the section break deleted event.
     *
     * There is no external API for deleting section break, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_section_break_deleted() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => 1,
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'firstslotid' => 1,
                'firstslotnumber' => 2
            ]
        ];
        $event = \mod_bayesian\event\section_break_deleted::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\section_break_deleted', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the section shuffle updated event.
     *
     * There is no external API for updating section shuffle, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_section_shuffle_updated() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => 1,
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'firstslotid' => 1,
                'firstslotnumber' => 2,
                'shuffle' => true
            ]
        ];
        $event = \mod_bayesian\event\section_shuffle_updated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\section_shuffle_updated', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the section title updated event.
     *
     * There is no external API for updating section title, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_section_title_updated() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => 1,
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'firstslotid' => 1,
                'firstslotnumber' => 2,
                'newtitle' => 'New title'
            ]
        ];
        $event = \mod_bayesian\event\section_title_updated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\section_title_updated', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertStringContainsString($params['other']['newtitle'], $event->get_description());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the slot created event.
     *
     * There is no external API for creating slot, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_slot_created() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => 1,
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'slotnumber' => 1,
                'page' => 1
            ]
        ];
        $event = \mod_bayesian\event\slot_created::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\slot_created', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the slot deleted event.
     *
     * There is no external API for deleting slot, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_slot_deleted() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => 1,
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'slotnumber' => 1,
            ]
        ];
        $event = \mod_bayesian\event\slot_deleted::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\slot_deleted', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the slot mark updated event.
     *
     * There is no external API for updating slot mark, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_slot_mark_updated() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => 1,
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'previousmaxmark' => 1,
                'newmaxmark' => 2,
            ]
        ];
        $event = \mod_bayesian\event\slot_mark_updated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\slot_mark_updated', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the slot moved event.
     *
     * There is no external API for moving slot, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_slot_moved() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => 1,
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'previousslotnumber' => 1,
                'afterslotnumber' => 2,
                'page' => 1
            ]
        ];
        $event = \mod_bayesian\event\slot_moved::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\slot_moved', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }

    /**
     * Test the slot require previous updated event.
     *
     * There is no external API for updating slot require previous option, so the unit test will simply
     * create and trigger the event and ensure the event data is returned as expected.
     */
    public function test_slot_requireprevious_updated() {
        $bayesianobj = $this->prepare_bayesian();

        $params = [
            'objectid' => 1,
            'context' => context_module::instance($bayesianobj->get_cmid()),
            'other' => [
                'bayesianid' => $bayesianobj->get_bayesianid(),
                'requireprevious' => true
            ]
        ];
        $event = \mod_bayesian\event\slot_requireprevious_updated::create($params);

        // Trigger and capture the event.
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $event = reset($events);

        // Check that the event data is valid.
        $this->assertInstanceOf('\mod_bayesian\event\slot_requireprevious_updated', $event);
        $this->assertEquals(context_module::instance($bayesianobj->get_cmid()), $event->get_context());
        $this->assertEventContextNotUsed($event);
    }
}
