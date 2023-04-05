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
require_once($CFG->libdir . "/phpunit/classes/restore_date_testcase.php");
/**
 * Restore override tests.
 *
 * @package   mod_bayesian
 * @author    2019 Nathan Nguyen <nathannguyen@catalyst-au.net>
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_override_test extends \restore_date_testcase {

    /**
     * Test restore overrides.
     */
    public function test_restore_overrides() {
        global $DB, $USER;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $bayesiangen = $this->getDataGenerator()->get_plugin_generator('mod_bayesian');
        $bayesian = $bayesiangen->create_instance(['course' => $course->id]);

        $group1 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));
        $group2 = $this->getDataGenerator()->create_group(array('courseid' => $course->id));

        $now = 100;

        // Group overrides.
        $groupoverride1 = (object)[
            'bayesian' => $bayesian->id,
            'groupid' => $group1->id,
            'timeopen' => $now,
            'timeclose' => $now + 20
        ];
        $DB->insert_record('bayesian_overrides', $groupoverride1);

        $groupoverride2 = (object)[
            'bayesian' => $bayesian->id,
            'groupid' => $group2->id,
            'timeopen' => $now,
            'timeclose' => $now + 40
        ];
        $DB->insert_record('bayesian_overrides', $groupoverride2);

        // Current bayesian overrides.
        $overrides = $DB->get_records('bayesian_overrides', ['bayesian' => $bayesian->id]);
        $this->assertEquals(2, count($overrides));

        // User Override.
        $useroverride = (object)[
            'bayesian' => $bayesian->id,
            'userid' => $USER->id,
            'sortorder' => 1,
            'timeopen' => 100,
            'timeclose' => 200
        ];
        $DB->insert_record('bayesian_overrides', $useroverride);

        // Current bayesian overrides.
        $overrides = $DB->get_records('bayesian_overrides', ['bayesian' => $bayesian->id]);
        $this->assertEquals(3, count($overrides));

        // Back up and restore including group info and user info.
        set_config('backup_general_groups', 1, 'backup');
        $newcourseid = $this->backup_and_restore($course);
        $newbayesian = $DB->get_record('bayesian', ['course' => $newcourseid]);
        $overrides = $DB->get_records('bayesian_overrides', ['bayesian' => $newbayesian->id]);
        // 2 groups overrides and 1 user override.
        $this->assertEquals(3, count($overrides));

        // Back up and restore with user info and without group info.
        set_config('backup_general_groups', 0, 'backup');
        $newcourseid = $this->backup_and_restore($course);
        $newbayesian = $DB->get_record('bayesian', ['course' => $newcourseid]);
        $overrides = $DB->get_records('bayesian_overrides', ['bayesian' => $newbayesian->id]);
        // 1 user override.
        $this->assertEquals(1, count($overrides));
    }
}
