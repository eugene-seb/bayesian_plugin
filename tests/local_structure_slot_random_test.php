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

use mod_bayesian\question\bank\qbank_helper;

/**
 * Class mod_bayesian_local_structure_slot_random_test
 * Class for tests related to the {@link \mod_bayesian\local\structure\slot_random} class.
 *
 * @package    mod_bayesian
 * @category   test
 * @copyright  2018 Shamim Rezaie <shamim@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_structure_slot_random_test extends \advanced_testcase {
    /**
     * Constructor test.
     */
    public function test_constructor() {
        global $SITE;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a bayesian.
        $bayesiangenerator = $this->getDataGenerator()->get_plugin_generator('mod_bayesian');
        $bayesian = $bayesiangenerator->create_instance(array('course' => $SITE->id, 'questionsperpage' => 3, 'grade' => 100.0));

        // Create a question category in the system context.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();

        // Create a random question without adding it to a bayesian.
        // We don't want to use bayesian_add_random_questions because that itself, instantiates an object from the slot_random class.
        $form = new \stdClass();
        $form->category = $category->id . ',' . $category->contextid;
        $form->includesubcategories = true;
        $form->fromtags = [];
        $form->defaultmark = 1;
        $form->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_HIDDEN;
        $form->stamp = make_unique_id_code();

        // Set the filter conditions.
        $filtercondition = new \stdClass();
        $filtercondition->questioncategoryid = $category->id;
        $filtercondition->includingsubcategories = 1;

        // Slot data.
        $randomslotdata = new \stdClass();
        $randomslotdata->bayesianid = $bayesian->id;
        $randomslotdata->maxmark = 1;
        $randomslotdata->usingcontextid = \context_module::instance($bayesian->cmid)->id;
        $randomslotdata->questionscontextid = $category->contextid;

        // Insert the random question to the bayesian.
        $randomslot = new \mod_bayesian\local\structure\slot_random($randomslotdata);
        $randomslot->set_filter_condition($filtercondition);

        $rc = new \ReflectionClass('\mod_bayesian\local\structure\slot_random');
        $rcp = $rc->getProperty('filtercondition');
        $rcp->setAccessible(true);
        $record = json_decode($rcp->getValue($randomslot));

        $this->assertEquals($bayesian->id, $randomslot->get_bayesian()->id);
        $this->assertEquals($category->id, $record->questioncategoryid);
        $this->assertEquals(1, $record->includingsubcategories);

        $rcp = $rc->getProperty('record');
        $rcp->setAccessible(true);
        $record = $rcp->getValue($randomslot);
        $this->assertEquals(1, $record->maxmark);
    }

    public function test_get_bayesian_bayesian() {
        global $SITE, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a bayesian.
        $bayesiangenerator = $this->getDataGenerator()->get_plugin_generator('mod_bayesian');
        $bayesian = $bayesiangenerator->create_instance(array('course' => $SITE->id, 'questionsperpage' => 3, 'grade' => 100.0));

        // Create a question category in the system context.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();

        bayesian_add_random_questions($bayesian, 0, $category->id, 1, false);

        // Set the filter conditions.
        $filtercondition = new \stdClass();
        $filtercondition->questioncategoryid = $category->id;
        $filtercondition->includingsubcategories = 1;

        // Slot data.
        $randomslotdata = new \stdClass();
        $randomslotdata->bayesianid = $bayesian->id;
        $randomslotdata->maxmark = 1;
        $randomslotdata->usingcontextid = \context_module::instance($bayesian->cmid)->id;
        $randomslotdata->questionscontextid = $category->contextid;

        $randomslot = new \mod_bayesian\local\structure\slot_random($randomslotdata);
        $randomslot->set_filter_condition($filtercondition);

        // The create_instance had injected an additional cmid propery to the bayesian. Let's remove that.
        unset($bayesian->cmid);

        $this->assertEquals($bayesian, $randomslot->get_bayesian());
    }

    public function test_set_bayesian() {
        global $SITE, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a bayesian.
        $bayesiangenerator = $this->getDataGenerator()->get_plugin_generator('mod_bayesian');
        $bayesian = $bayesiangenerator->create_instance(array('course' => $SITE->id, 'questionsperpage' => 3, 'grade' => 100.0));

        // Create a question category in the system context.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();

        bayesian_add_random_questions($bayesian, 0, $category->id, 1, false);

        // Set the filter conditions.
        $filtercondition = new \stdClass();
        $filtercondition->questioncategoryid = $category->id;
        $filtercondition->includingsubcategories = 1;

        // Slot data.
        $randomslotdata = new \stdClass();
        $randomslotdata->bayesianid = $bayesian->id;
        $randomslotdata->maxmark = 1;
        $randomslotdata->usingcontextid = \context_module::instance($bayesian->cmid)->id;
        $randomslotdata->questionscontextid = $category->contextid;

        $randomslot = new \mod_bayesian\local\structure\slot_random($randomslotdata);
        $randomslot->set_filter_condition($filtercondition);

        // The create_instance had injected an additional cmid propery to the bayesian. Let's remove that.
        unset($bayesian->cmid);

        $randomslot->set_bayesian($bayesian);

        $rc = new \ReflectionClass('\mod_bayesian\local\structure\slot_random');
        $rcp = $rc->getProperty('bayesian');
        $rcp->setAccessible(true);
        $bayesianpropery = $rcp->getValue($randomslot);

        $this->assertEquals($bayesian, $bayesianpropery);
    }

    private function setup_for_test_tags($tagnames) {
        global $SITE, $DB;

        // Create a bayesian.
        $bayesiangenerator = $this->getDataGenerator()->get_plugin_generator('mod_bayesian');
        $bayesian = $bayesiangenerator->create_instance(array('course' => $SITE->id, 'questionsperpage' => 3, 'grade' => 100.0));

        // Create a question category in the system context.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();

        bayesian_add_random_questions($bayesian, 0, $category->id, 1, false);

        // Slot data.
        $randomslotdata = new \stdClass();
        $randomslotdata->bayesianid = $bayesian->id;
        $randomslotdata->maxmark = 1;
        $randomslotdata->usingcontextid = \context_module::instance($bayesian->cmid)->id;
        $randomslotdata->questionscontextid = $category->contextid;

        $randomslot = new \mod_bayesian\local\structure\slot_random($randomslotdata);

        // Create tags.
        foreach ($tagnames as $tagname) {
            $tagrecord = array(
                'isstandard' => 1,
                'flag' => 0,
                'rawname' => $tagname,
                'description' => $tagname . ' desc'
            );
            $tags[$tagname] = $this->getDataGenerator()->create_tag($tagrecord);
        }

        return array($randomslot, $tags);
    }

    public function test_set_tags() {
        $this->resetAfterTest();
        $this->setAdminUser();

        list($randomslot, $tags) = $this->setup_for_test_tags(['foo', 'bar']);
        $filtercondition = new \stdClass();
        $randomslot->set_tags([$tags['foo'], $tags['bar']]);
        $randomslot->set_filter_condition($filtercondition);

        $rc = new \ReflectionClass('\mod_bayesian\local\structure\slot_random');
        $rcp = $rc->getProperty('filtercondition');
        $rcp->setAccessible(true);
        $tagspropery = $rcp->getValue($randomslot);

        $this->assertEquals([
            $tags['foo']->id => $tags['foo'],
            $tags['bar']->id => $tags['bar'],
        ], (array)json_decode($tagspropery)->tags);
    }

    public function test_set_tags_twice() {
        $this->resetAfterTest();
        $this->setAdminUser();

        list($randomslot, $tags) = $this->setup_for_test_tags(['foo', 'bar', 'baz']);

        // Set tags for the first time.
        $filtercondition = new \stdClass();
        $randomslot->set_tags([$tags['foo'], $tags['bar']]);
        // Now set the tags again.
        $randomslot->set_tags([$tags['baz']]);
        $randomslot->set_filter_condition($filtercondition);

        $rc = new \ReflectionClass('\mod_bayesian\local\structure\slot_random');
        $rcp = $rc->getProperty('filtercondition');
        $rcp->setAccessible(true);
        $tagspropery = $rcp->getValue($randomslot);

        $this->assertEquals([
            $tags['baz']->id => $tags['baz'],
        ], (array)json_decode($tagspropery)->tags);
    }

    public function test_set_tags_duplicates() {
        $this->resetAfterTest();
        $this->setAdminUser();

        list($randomslot, $tags) = $this->setup_for_test_tags(['foo', 'bar', 'baz']);
        $filtercondition = new \stdClass();
        $randomslot->set_tags([$tags['foo'], $tags['bar'], $tags['foo']]);
        $randomslot->set_filter_condition($filtercondition);

        $rc = new \ReflectionClass('\mod_bayesian\local\structure\slot_random');
        $rcp = $rc->getProperty('filtercondition');
        $rcp->setAccessible(true);
        $tagspropery = $rcp->getValue($randomslot);

        $this->assertEquals([
            $tags['foo']->id => $tags['foo'],
            $tags['bar']->id => $tags['bar'],
        ], (array)json_decode($tagspropery)->tags);
    }

    public function test_set_tags_by_id() {
        $this->resetAfterTest();
        $this->setAdminUser();

        list($randomslot, $tags) = $this->setup_for_test_tags(['foo', 'bar', 'baz']);
        $filtercondition = new \stdClass();
        $randomslot->set_tags_by_id([$tags['foo']->id, $tags['bar']->id]);
        $randomslot->set_filter_condition($filtercondition);

        $rc = new \ReflectionClass('\mod_bayesian\local\structure\slot_random');
        $rcp = $rc->getProperty('tags');
        $rcp->setAccessible(true);
        $tagspropery = $rcp->getValue($randomslot);

        // The set_tags_by_id function only retrieves id and name fields of the tag object.
        $this->assertCount(2, $tagspropery);
        $this->assertArrayHasKey($tags['foo']->id, $tagspropery);
        $this->assertArrayHasKey($tags['bar']->id, $tagspropery);
        $this->assertEquals(
                (object)['id' => $tags['foo']->id, 'name' => $tags['foo']->name],
                $tagspropery[$tags['foo']->id]->to_object()
        );
        $this->assertEquals(
                (object)['id' => $tags['bar']->id, 'name' => $tags['bar']->name],
                $tagspropery[$tags['bar']->id]->to_object()
        );
    }

    public function test_set_tags_by_id_twice() {
        $this->resetAfterTest();
        $this->setAdminUser();

        list($randomslot, $tags) = $this->setup_for_test_tags(['foo', 'bar', 'baz']);

        // Set tags for the first time.
        $randomslot->set_tags_by_id([$tags['foo']->id, $tags['bar']->id]);
        // Now set the tags again.
        $randomslot->set_tags_by_id([$tags['baz']->id]);

        $rc = new \ReflectionClass('\mod_bayesian\local\structure\slot_random');
        $rcp = $rc->getProperty('tags');
        $rcp->setAccessible(true);
        $tagspropery = $rcp->getValue($randomslot);

        // The set_tags_by_id function only retrieves id and name fields of the tag object.
        $this->assertCount(1, $tagspropery);
        $this->assertArrayHasKey($tags['baz']->id, $tagspropery);
        $this->assertEquals(
                (object)['id' => $tags['baz']->id, 'name' => $tags['baz']->name],
                $tagspropery[$tags['baz']->id]->to_object()
        );
    }

    public function test_set_tags_by_id_duplicates() {
        $this->resetAfterTest();
        $this->setAdminUser();

        list($randomslot, $tags) = $this->setup_for_test_tags(['foo', 'bar', 'baz']);

        $randomslot->set_tags_by_id([$tags['foo']->id, $tags['bar']->id], $tags['foo']->id);

        $rc = new \ReflectionClass('\mod_bayesian\local\structure\slot_random');
        $rcp = $rc->getProperty('tags');
        $rcp->setAccessible(true);
        $tagspropery = $rcp->getValue($randomslot);

        // The set_tags_by_id function only retrieves id and name fields of the tag object.
        $this->assertCount(2, $tagspropery);
        $this->assertArrayHasKey($tags['foo']->id, $tagspropery);
        $this->assertArrayHasKey($tags['bar']->id, $tagspropery);
        $this->assertEquals(
                (object)['id' => $tags['foo']->id, 'name' => $tags['foo']->name],
                $tagspropery[$tags['foo']->id]->to_object()
        );
        $this->assertEquals(
                (object)['id' => $tags['bar']->id, 'name' => $tags['bar']->name],
                $tagspropery[$tags['bar']->id]->to_object()
        );
    }

    public function test_insert() {
        global $SITE;

        $this->resetAfterTest();
        $this->setAdminUser();

        // Create a bayesian.
        $bayesiangenerator = $this->getDataGenerator()->get_plugin_generator('mod_bayesian');
        $bayesian = $bayesiangenerator->create_instance(array('course' => $SITE->id, 'questionsperpage' => 3, 'grade' => 100.0));
        $bayesiancontext = \context_module::instance($bayesian->cmid);

        // Create a question category in the system context.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category();

        // Create a random question without adding it to a bayesian.
        $form = new \stdClass();
        $form->category = $category->id . ',' . $category->contextid;
        $form->includesubcategories = true;
        $form->fromtags = [];
        $form->defaultmark = 1;
        $form->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_HIDDEN;
        $form->stamp = make_unique_id_code();

        // Prepare 2 tags.
        $tagrecord = array(
            'isstandard' => 1,
            'flag' => 0,
            'rawname' => 'foo',
            'description' => 'foo desc'
        );
        $footag = $this->getDataGenerator()->create_tag($tagrecord);
        $tagrecord = array(
            'isstandard' => 1,
            'flag' => 0,
            'rawname' => 'bar',
            'description' => 'bar desc'
        );
        $bartag = $this->getDataGenerator()->create_tag($tagrecord);


        // Set the filter conditions.
        $filtercondition = new \stdClass();
        $filtercondition->questioncategoryid = $category->id;
        $filtercondition->includingsubcategories = 1;

        // Slot data.
        $randomslotdata = new \stdClass();
        $randomslotdata->bayesianid = $bayesian->id;
        $randomslotdata->maxmark = 1;
        $randomslotdata->usingcontextid = $bayesiancontext->id;
        $randomslotdata->questionscontextid = $category->contextid;

        // Insert the random question to the bayesian.
        $randomslot = new \mod_bayesian\local\structure\slot_random($randomslotdata);
        $randomslot->set_tags([$footag, $bartag]);
        $randomslot->set_filter_condition($filtercondition);
        $randomslot->insert(1); // Put the question on the first page of the bayesian.

        $slots = qbank_helper::get_question_structure($bayesian->id, $bayesiancontext);
        $bayesianslot = reset($slots);

        $this->assertEquals($category->id, $bayesianslot->category);
        $this->assertEquals(1, $bayesianslot->randomrecurse);
        $this->assertEquals(1, $bayesianslot->maxmark);
        $tagspropery = $bayesianslot->randomtags;

        $this->assertCount(2, $tagspropery);
        $this->assertEqualsCanonicalizing(
                [
                    ['tagid' => $footag->id, 'tagname' => $footag->name],
                    ['tagid' => $bartag->id, 'tagname' => $bartag->name]
                ],
                array_map(function($slottag) {
                    return ['tagid' => $slottag->id, 'tagname' => $slottag->name];
                }, $tagspropery));
    }
}
