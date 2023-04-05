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

namespace bayesianaccess_timelimit;

use bayesian;
use bayesianaccess_timelimit;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/bayesian/accessrule/timelimit/rule.php');


/**
 * Unit tests for the bayesianaccess_timelimit plugin.
 *
 * @package    bayesianaccess_timelimit
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rule_test extends \basic_testcase {
    public function test_time_limit_access_rule() {
        $bayesian = new \stdClass();
        $bayesian->timeclose = 0;
        $bayesian->timelimit = 3600;
        $cm = new \stdClass();
        $cm->id = 0;
        $bayesianobj = new bayesian($bayesian, $cm, null);
        $rule = new bayesianaccess_timelimit($bayesianobj, 10000);
        $attempt = new \stdClass();

        $this->assertEquals($rule->description(),
            get_string('bayesiantimelimit', 'bayesianaccess_timelimit', format_time(3600)));

        $attempt->timestart = 10000;
        $attempt->preview = 0;
        $this->assertEquals(13600, $rule->end_time($attempt));
        $this->assertEquals(3600, $rule->time_left_display($attempt, 10000));
        $this->assertEquals(1600, $rule->time_left_display($attempt, 12000));
        $this->assertEquals(-400, $rule->time_left_display($attempt, 14000));

        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));
    }

    /**
     * Data provider for test_time_limit_access_rule_with_time_close.
     *
     * @return array of ($timetoclose, $timelimit, $displaylimit, $actuallimit)
     */
    public function time_limit_access_rule_with_time_close_provider() {
        return [
            'Close time is earlier than time limit' => [1800, 3600, 3600, 1800],
            'Close time is on time limit' => [3600, 3600, 3600, 3600],
            'Close time is later than time limit' => [3600, 1800, 1800, 1800]
        ];
    }

    /**
     * Test the time_left_display method of the bayesianaccess_timelimit class.
     *
     * @param int $timetoclose  The number of seconds that is left to the bayesian' closing time
     * @param int $timelimit    Time limit of the bayesian
     * @param int $displaylimit The limit that is displayed on the bayesian page
     * @param int $actuallimit  The actual limit that is being applied
     * @dataProvider time_limit_access_rule_with_time_close_provider
     */
    public function test_time_limit_access_rule_with_time_close($timetoclose, $timelimit, $displaylimit, $actuallimit) {
        $timenow = 10000;

        $bayesian = new \stdClass();
        $bayesian->timeclose = $timenow + $timetoclose;
        $bayesian->timelimit = $timelimit;
        $cm = new \stdClass();
        $cm->id = 0;
        $bayesianobj = new bayesian($bayesian, $cm, null);
        $rule = new bayesianaccess_timelimit($bayesianobj, $timenow);
        $attempt = new \stdClass();

        $this->assertEquals($rule->description(),
            get_string('bayesiantimelimit', 'bayesianaccess_timelimit', format_time($displaylimit)));

        $attempt->timestart = $timenow;
        $attempt->preview = 0;
        $this->assertEquals($timenow + $actuallimit, $rule->end_time($attempt));
        $this->assertEquals($actuallimit, $rule->time_left_display($attempt, $timenow));
        $this->assertEquals($actuallimit - 1000, $rule->time_left_display($attempt, $timenow + 1000));

        $this->assertFalse($rule->prevent_access());
        $this->assertFalse($rule->prevent_new_attempt(0, $attempt));
        $this->assertFalse($rule->is_finished(0, $attempt));
    }
}