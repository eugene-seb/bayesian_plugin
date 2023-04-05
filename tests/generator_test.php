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

/**
 * PHPUnit data generator testcase
 *
 * @package    mod_bayesian
 * @category   phpunit
 * @copyright  2012 Matt Petro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_bayesian_generator
 */
class generator_test extends \advanced_testcase {
    public function test_generator() {
        global $DB, $SITE;

        $this->resetAfterTest(true);

        $this->assertEquals(0, $DB->count_records('bayesian'));

        /** @var \mod_bayesian_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_bayesian');
        $this->assertInstanceOf('mod_bayesian_generator', $generator);
        $this->assertEquals('bayesian', $generator->get_modulename());

        $generator->create_instance(array('course'=>$SITE->id));
        $generator->create_instance(array('course'=>$SITE->id));
        $createtime = time();
        $bayesian = $generator->create_instance(array('course' => $SITE->id, 'timecreated' => 0));
        $this->assertEquals(3, $DB->count_records('bayesian'));

        $cm = get_coursemodule_from_instance('bayesian', $bayesian->id);
        $this->assertEquals($bayesian->id, $cm->instance);
        $this->assertEquals('bayesian', $cm->modname);
        $this->assertEquals($SITE->id, $cm->course);

        $context = \context_module::instance($cm->id);
        $this->assertEquals($bayesian->cmid, $context->instanceid);

        $this->assertEqualsWithDelta($createtime,
                $DB->get_field('bayesian', 'timecreated', ['id' => $cm->instance]), 2);
    }

    public function test_generating_a_user_override() {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $bayesian = $generator->create_module('bayesian', ['course' => $course->id]);
        $generator->enrol_user($user->id, $course->id, 'student');

        /** @var \mod_bayesian_generator $bayesiangenerator */
        $bayesiangenerator = $generator->get_plugin_generator('mod_bayesian');
        $bayesiangenerator->create_override([
            'bayesian' => $bayesian->id,
            'userid' => $user->id,
            'timeclose' => strtotime('2022-10-20'),
        ]);

        // Check the corresponding calendar event now exists.
        $events = calendar_get_events(strtotime('2022-01-01'),
                strtotime('2022-12-31'), $user->id, false, $course->id);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals($user->id, $event->userid);
        $this->assertEquals(0, $event->groupid);
        $this->assertEquals(0, $event->courseid);
        $this->assertEquals('bayesian', $event->modulename);
        $this->assertEquals($bayesian->id, $event->instance);
        $this->assertEquals('close', $event->eventtype);
        $this->assertEquals(strtotime('2022-10-20'), $event->timestart);
    }

    public function test_generating_a_group_override() {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $bayesian = $generator->create_module('bayesian', ['course' => $course->id]);
        $group = $generator->create_group(['courseid' => $course->id]);

        /** @var \mod_bayesian_generator $bayesiangenerator */
        $bayesiangenerator = $generator->get_plugin_generator('mod_bayesian');
        $bayesiangenerator->create_override([
            'bayesian' => $bayesian->id,
            'groupid' => $group->id,
            'timeclose' => strtotime('2022-10-20'),
        ]);

        // Check the corresponding calendar event now exists.
        $events = calendar_get_events(strtotime('2022-01-01'),
                strtotime('2022-12-31'), false, $group->id, $course->id);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals(0, $event->userid);
        $this->assertEquals($group->id, $event->groupid);
        $this->assertEquals($course->id, $event->courseid);
        $this->assertEquals('bayesian', $event->modulename);
        $this->assertEquals($bayesian->id, $event->instance);
        $this->assertEquals('close', $event->eventtype);
        $this->assertEquals(strtotime('2022-10-20'), $event->timestart);
    }
}
