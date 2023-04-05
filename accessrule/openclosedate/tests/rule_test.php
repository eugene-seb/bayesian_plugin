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

namespace bayesianaccess_openclosedate;

use bayesian;
use bayesianaccess_openclosedate;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/bayesian/accessrule/openclosedate/rule.php');


/**
 * Unit tests for the bayesianaccess_openclosedate plugin.
 *
 * @package    bayesianaccess_openclosedate
 * @category   test
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_test extends \basic_testcase {
    public function test_no_dates() {
        $bayesian = new \stdClass();
        $bayesian->timeopen = 0;
        $bayesian->timeclose = 0;
        $bayesian->overduehandling = 'autosubmit';
        $cm = new \stdClass();
        $cm->id = 0;
        $bayesianobj = new bayesian($bayesian, $cm, null);
        $attempt = new \stdClass();
        $attempt->preview = 0;

        $rule = new bayesianaccess_openclosedate($bayesianobj, 10000);
        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));
        $this->assertFalse($rule->end_time($attempt));
        $this->assertFalse($rule->time_left_display($attempt, 10000));
        $this->assertFalse($rule->time_left_display($attempt, 0));

        $rule = new bayesianaccess_openclosedate($bayesianobj, 0);
        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));
        $this->assertFalse($rule->end_time($attempt));
        $this->assertFalse($rule->time_left_display($attempt, 0));
    }

    public function test_start_date() {
        $bayesian = new \stdClass();
        $bayesian->timeopen = 10000;
        $bayesian->timeclose = 0;
        $bayesian->overduehandling = 'autosubmit';
        $cm = new \stdClass();
        $cm->id = 0;
        $bayesianobj = new bayesian($bayesian, $cm, null);
        $attempt = new \stdClass();
        $attempt->preview = 0;

        $rule = new bayesianaccess_openclosedate($bayesianobj, 9999);
        $this->assertEquals($rule->prevent_access(),
            get_string('notavailable', 'bayesianaccess_openclosedate'));
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));
        $this->assertFalse($rule->end_time($attempt));
        $this->assertFalse($rule->time_left_display($attempt, 0));

        $rule = new bayesianaccess_openclosedate($bayesianobj, 10000);
        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));
        $this->assertFalse($rule->end_time($attempt));
        $this->assertFalse($rule->time_left_display($attempt, 0));
    }

    public function test_close_date() {
        $bayesian = new \stdClass();
        $bayesian->timeopen = 0;
        $bayesian->timeclose = 20000;
        $bayesian->overduehandling = 'autosubmit';
        $cm = new \stdClass();
        $cm->id = 0;
        $bayesianobj = new bayesian($bayesian, $cm, null);
        $attempt = new \stdClass();
        $attempt->preview = 0;

        $rule = new bayesianaccess_openclosedate($bayesianobj, 20000);
        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));

        $this->assertEquals($rule->end_time($attempt), 20000);
        $this->assertFalse($rule->time_left_display($attempt, 20000 - bayesian_SHOW_TIME_BEFORE_DEADLINE));
        $this->assertEquals($rule->time_left_display($attempt, 19900), 100);
        $this->assertEquals($rule->time_left_display($attempt, 20000), 0);
        $this->assertEquals($rule->time_left_display($attempt, 20100), -100);

        $rule = new bayesianaccess_openclosedate($bayesianobj, 20001);
        $this->assertEquals($rule->prevent_access(),
            get_string('notavailable', 'bayesianaccess_openclosedate'));
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertTrue($rule->is_finished(0, $attempt));
        $this->assertEquals($rule->end_time($attempt), 20000);
        $this->assertFalse($rule->time_left_display($attempt, 20000 - bayesian_SHOW_TIME_BEFORE_DEADLINE));
        $this->assertEquals($rule->time_left_display($attempt, 19900), 100);
        $this->assertEquals($rule->time_left_display($attempt, 20000), 0);
        $this->assertEquals($rule->time_left_display($attempt, 20100), -100);
    }

    public function test_both_dates() {
        $bayesian = new \stdClass();
        $bayesian->timeopen = 10000;
        $bayesian->timeclose = 20000;
        $bayesian->overduehandling = 'autosubmit';
        $cm = new \stdClass();
        $cm->id = 0;
        $bayesianobj = new bayesian($bayesian, $cm, null);
        $attempt = new \stdClass();
        $attempt->preview = 0;

        $rule = new bayesianaccess_openclosedate($bayesianobj, 9999);
        $this->assertEquals($rule->prevent_access(),
            get_string('notavailable', 'bayesianaccess_openclosedate'));
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));

        $rule = new bayesianaccess_openclosedate($bayesianobj, 10000);
        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));

        $rule = new bayesianaccess_openclosedate($bayesianobj, 20000);
        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));

        $rule = new bayesianaccess_openclosedate($bayesianobj, 20001);
        $this->assertEquals($rule->prevent_access(),
            get_string('notavailable', 'bayesianaccess_openclosedate'));
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertTrue($rule->is_finished(0, $attempt));

        $this->assertEquals($rule->end_time($attempt), 20000);
        $this->assertFalse($rule->time_left_display($attempt, 20000 - bayesian_SHOW_TIME_BEFORE_DEADLINE));
        $this->assertEquals($rule->time_left_display($attempt, 19900), 100);
        $this->assertEquals($rule->time_left_display($attempt, 20000), 0);
        $this->assertEquals($rule->time_left_display($attempt, 20100), -100);
    }

    public function test_close_date_with_overdue() {
        $bayesian = new \stdClass();
        $bayesian->timeopen = 0;
        $bayesian->timeclose = 20000;
        $bayesian->overduehandling = 'graceperiod';
        $bayesian->graceperiod = 1000;
        $cm = new \stdClass();
        $cm->id = 0;
        $bayesianobj = new bayesian($bayesian, $cm, null);
        $attempt = new \stdClass();
        $attempt->preview = 0;

        $rule = new bayesianaccess_openclosedate($bayesianobj, 20000);
        $this->assertFalse($rule->prevent_access());

        $rule = new bayesianaccess_openclosedate($bayesianobj, 20001);
        $this->assertFalse($rule->prevent_access());

        $rule = new bayesianaccess_openclosedate($bayesianobj, 21000);
        $this->assertFalse($rule->prevent_access());

        $rule = new bayesianaccess_openclosedate($bayesianobj, 21001);
        $this->assertEquals($rule->prevent_access(),
                get_string('notavailable', 'bayesianaccess_openclosedate'));
    }
}
