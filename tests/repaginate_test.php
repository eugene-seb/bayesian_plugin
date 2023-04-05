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
 * Unit tests for the {@link \mod_bayesian\repaginate} class.
 * @package   mod_bayesian
 * @category  test
 * @copyright 2014 The Open Univsersity
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_bayesian;

use bayesian;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/bayesian/locallib.php');
require_once($CFG->dirroot . '/mod/bayesian/classes/repaginate.php');

/**
 * Testable subclass, giving access to the protected methods of {@link \mod_bayesian\repaginate}
 * @copyright 2014 The Open Univsersity
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_bayesian_repaginate_testable extends repaginate {

    public function __construct($bayesianid = 0, $slots = null) {
        return parent::__construct($bayesianid, $slots);
    }
    public function get_this_slot($slots, $slotnumber) {
        return parent::get_this_slot($slots, $slotnumber);
    }
    public function get_slots_by_slotid($slots = null) {
        return parent::get_slots_by_slotid($slots);
    }
    public function get_slots_by_slot_number($slots = null) {
        return parent::get_slots_by_slot_number($slots);
    }
    public function repaginate_this_slot($slot, $newpagenumber) {
        return parent::repaginate_this_slot($slot, $newpagenumber);
    }
    public function repaginate_next_slot($nextslotnumber, $type) {
        return parent::repaginate_next_slot($nextslotnumber, $type);
    }
}

/**
 * Test for some parts of the repaginate class.
 * @copyright 2014 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repaginate_test extends \advanced_testcase {

    /** @var array stores the slots. */
    private $bayesianslots;
    /** @var mod_bayesian_repaginate_testable the object being tested. */
    private $repaginate = null;

    public function setUp(): void {
        $this->set_bayesian_slots($this->get_bayesian_object()->get_slots());
        $this->repaginate = new mod_bayesian_repaginate_testable(0, $this->bayesianslots);
    }

    public function tearDown(): void {
        $this->repaginate = null;
    }

    /**
     * Create a bayesian, add five questions to the bayesian
     * which are all on one page and return the bayesian object.
     */
    private function get_bayesian_object() {
        global $SITE;
        $this->resetAfterTest(true);

        // Make a bayesian.
        $bayesiangenerator = $this->getDataGenerator()->get_plugin_generator('mod_bayesian');

        $bayesian = $bayesiangenerator->create_instance(array(
                'course' => $SITE->id, 'questionsperpage' => 0, 'grade' => 100.0, 'sumgrades' => 2));
        $cm = get_coursemodule_from_instance('bayesian', $bayesian->id, $SITE->id);

        // Create five questions.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();

        $shortanswer = $questiongenerator->create_question('shortanswer', null, array('category' => $cat->id));
        $numerical = $questiongenerator->create_question('numerical', null, array('category' => $cat->id));
        $essay = $questiongenerator->create_question('essay', null, array('category' => $cat->id));
        $truefalse = $questiongenerator->create_question('truefalse', null, array('category' => $cat->id));
        $match = $questiongenerator->create_question('match', null, array('category' => $cat->id));

        // Add them to the bayesian.
        bayesian_add_bayesian_question($shortanswer->id, $bayesian);
        bayesian_add_bayesian_question($numerical->id, $bayesian);
        bayesian_add_bayesian_question($essay->id, $bayesian);
        bayesian_add_bayesian_question($truefalse->id, $bayesian);
        bayesian_add_bayesian_question($match->id, $bayesian);

        // Return the bayesian object.
        $bayesianobj = new bayesian($bayesian, $cm, $SITE);
        return structure::create_for_bayesian($bayesianobj);
    }

    /**
     * Set the bayesian slots
     * @param string $slots
     */
    private function set_bayesian_slots($slots = null) {
        if (!$slots) {
            $this->bayesianslots = $this->get_bayesian_object()->get_slots();
        } else {
            $this->bayesianslots = $slots;
        }
    }

    /**
     * Test the get_this_slot() method
     */
    public function test_get_this_slot() {
        $this->set_bayesian_slots();
        $actual = array();
        $expected = $this->repaginate->get_slots_by_slot_number();
        $this->assertEquals($expected, $actual);

        $slotsbyno = $this->repaginate->get_slots_by_slot_number($this->bayesianslots);
        $slotnumber = 5;
        $thisslot = $this->repaginate->get_this_slot($this->bayesianslots, $slotnumber);
        $this->assertEquals($slotsbyno[$slotnumber], $thisslot);
    }

    public function test_get_slots_by_slotnumber() {
        $this->set_bayesian_slots();
        $expected = array();
        $actual = $this->repaginate->get_slots_by_slot_number();
        $this->assertEquals($expected, $actual);

        foreach ($this->bayesianslots as $slot) {
            $expected[$slot->slot] = $slot;
        }
        $actual = $this->repaginate->get_slots_by_slot_number($this->bayesianslots);
        $this->assertEquals($expected, $actual);
    }

    public function test_get_slots_by_slotid() {
        $this->set_bayesian_slots();
        $actual = $this->repaginate->get_slots_by_slotid();
        $this->assertEquals(array(), $actual);

        $slotsbyno = $this->repaginate->get_slots_by_slot_number($this->bayesianslots);
        $actual = $this->repaginate->get_slots_by_slotid($slotsbyno);
        $this->assertEquals($this->bayesianslots, $actual);
    }

    public function test_repaginate_n_questions_per_page() {
        $this->set_bayesian_slots();

        // Expect 2 questions per page.
        $expected = array();
        foreach ($this->bayesianslots as $slot) {
            // Page 1 contains Slots 1 and 2.
            if ($slot->slot >= 1 && $slot->slot <= 2) {
                $slot->page = 1;
            }
            // Page 2 contains slots 3 and 4.
            if ($slot->slot >= 3 && $slot->slot <= 4) {
                $slot->page = 2;
            }
            // Page 3 contains slots 5.
            if ($slot->slot >= 5 && $slot->slot <= 6) {
                $slot->page = 3;
            }
            $expected[$slot->id] = $slot;
        }
        $actual = $this->repaginate->repaginate_n_question_per_page($this->bayesianslots, 2);
        $this->assertEquals($expected, $actual);

        // Expect 3 questions per page.
        $expected = array();
        foreach ($this->bayesianslots as $slot) {
            // Page 1 contains Slots 1, 2 and 3.
            if ($slot->slot >= 1 && $slot->slot <= 3) {
                $slot->page = 1;
            }
            // Page 2 contains slots 4 and 5.
            if ($slot->slot >= 4 && $slot->slot <= 6) {
                $slot->page = 2;
            }
            $expected[$slot->id] = $slot;
        }
        $actual = $this->repaginate->repaginate_n_question_per_page($this->bayesianslots, 3);
        $this->assertEquals($expected, $actual);

        // Expect 5 questions per page.
        $expected = array();
        foreach ($this->bayesianslots as $slot) {
            // Page 1 contains Slots 1, 2, 3, 4 and 5.
            if ($slot->slot > 0 && $slot->slot < 6) {
                $slot->page = 1;
            }
            // Page 2 contains slots 6, 7, 8, 9 and 10.
            if ($slot->slot > 5 && $slot->slot < 11) {
                $slot->page = 2;
            }
            $expected[$slot->id] = $slot;
        }
        $actual = $this->repaginate->repaginate_n_question_per_page($this->bayesianslots, 5);
        $this->assertEquals($expected, $actual);

        // Expect 10 questions per page.
        $expected = array();
        foreach ($this->bayesianslots as $slot) {
            // Page 1 contains Slots 1 to 10.
            if ($slot->slot >= 1 && $slot->slot <= 10) {
                $slot->page = 1;
            }
            // Page 2 contains slots 11 to 20.
            if ($slot->slot >= 11 && $slot->slot <= 20) {
                $slot->page = 2;
            }
            $expected[$slot->id] = $slot;
        }
        $actual = $this->repaginate->repaginate_n_question_per_page($this->bayesianslots, 10);
        $this->assertEquals($expected, $actual);

        // Expect 1 questions per page.
        $expected = array();
        $page = 1;
        foreach ($this->bayesianslots as $slot) {
            $slot->page = $page++;
            $expected[$slot->id] = $slot;
        }
        $actual = $this->repaginate->repaginate_n_question_per_page($this->bayesianslots, 1);
        $this->assertEquals($expected, $actual);
    }

    public function test_repaginate_this_slot() {
        $this->set_bayesian_slots();
        $slotsbyslotno = $this->repaginate->get_slots_by_slot_number($this->bayesianslots);
        $slotnumber = 3;
        $newpagenumber = 2;
        $thisslot = $slotsbyslotno[3];
        $thisslot->page = $newpagenumber;
        $expected = $thisslot;
        $actual = $this->repaginate->repaginate_this_slot($slotsbyslotno[3], $newpagenumber);
        $this->assertEquals($expected, $actual);
    }

    public function test_repaginate_the_rest() {
        $this->set_bayesian_slots();
        $slotfrom = 1;
        $type = repaginate::LINK;
        $expected = array();
        foreach ($this->bayesianslots as $slot) {
            if ($slot->slot > $slotfrom) {
                $slot->page = $slot->page - 1;
                $expected[$slot->id] = $slot;
            }
        }
        $actual = $this->repaginate->repaginate_the_rest($this->bayesianslots, $slotfrom, $type, false);
        $this->assertEquals($expected, $actual);

        $slotfrom = 2;
        $newslots = array();
        foreach ($this->bayesianslots as $s) {
            if ($s->slot === $slotfrom) {
                $s->page = $s->page - 1;
            }
            $newslots[$s->id] = $s;
        }

        $type = repaginate::UNLINK;
        $expected = array();
        foreach ($this->bayesianslots as $slot) {
            if ($slot->slot > ($slotfrom - 1)) {
                $slot->page = $slot->page - 1;
                $expected[$slot->id] = $slot;
            }
        }
        $actual = $this->repaginate->repaginate_the_rest($newslots, $slotfrom, $type, false);
        $this->assertEquals($expected, $actual);
    }

}
