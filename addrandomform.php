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
 * Defines the Moodle forum used to add random questions to the bayesian.
 *
 * @package   mod_bayesian
 * @copyright 2008 Olli Savolainen
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/formslib.php');


/**
 * The add random questions form.
 *
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bayesian_add_random_form extends moodleform {

    protected function definition() {
        global $OUTPUT, $PAGE, $CFG;

        $mform = $this->_form;
        $mform->setDisableShortforms();

        $contexts = $this->_customdata['contexts'];
        $usablecontexts = $contexts->having_cap('moodle/question:useall');

        // Random from existing category section.
        $mform->addElement('header', 'existingcategoryheader',
                get_string('randomfromexistingcategory', 'bayesian'));

        $mform->addElement('questioncategory', 'category', get_string('category'),
                array('contexts' => $usablecontexts, 'top' => true));
        $mform->setDefault('category', $this->_customdata['cat']);

        $mform->addElement('checkbox', 'includesubcategories', '', get_string('recurse', 'bayesian'));

        $tops = question_get_top_categories_for_contexts(array_column($contexts->all(), 'id'));
        $mform->hideIf('includesubcategories', 'category', 'in', $tops);

        if ($CFG->usetags) {
            $tagstrings = array();
            $tags = core_tag_tag::get_tags_by_area_in_contexts('core_question', 'question', $usablecontexts);
            foreach ($tags as $tag) {
                $tagstrings["{$tag->id},{$tag->name}"] = $tag->name;
            }
            $options = array(
                'multiple' => true,
                'noselectionstring' => get_string('anytags', 'bayesian'),
            );
            $mform->addElement('autocomplete', 'fromtags', get_string('randomquestiontags', 'mod_bayesian'), $tagstrings, $options);
            $mform->addHelpButton('fromtags', 'randomquestiontags', 'mod_bayesian');
        }

        // TODO: in the past, the drop-down used to only show sensible choices for
        // number of questions to add. That is, if the currently selected filter
        // only matched 9 questions (not already in the bayesian), then the drop-down would
        // only offer choices 1..9. This nice UI hint got lost when the UI became Ajax-y.
        // We should add it back.
        $mform->addElement('select', 'numbertoadd', get_string('randomnumber', 'bayesian'),
                $this->get_number_of_questions_to_add_choices());

        $previewhtml = $OUTPUT->render_from_template('mod_bayesian/random_question_form_preview', []);
        $mform->addElement('html', $previewhtml);

        $mform->addElement('submit', 'existingcategory', get_string('addrandomquestion', 'bayesian'));

        // If the manage categories plugins is enabled, add the elements to create a new category in the form.
        if (\core\plugininfo\qbank::is_plugin_enabled(\qbank_managecategories\helper::PLUGINNAME)) {
            // Random from a new category section.
            $mform->addElement('header', 'newcategoryheader',
                    get_string('randomquestionusinganewcategory', 'bayesian'));

            $mform->addElement('text', 'name', get_string('name'), 'maxlength="254" size="50"');
            $mform->setType('name', PARAM_TEXT);

            $mform->addElement('questioncategory', 'parent', get_string('parentcategory', 'question'),
                    array('contexts' => $usablecontexts, 'top' => true));
            $mform->addHelpButton('parent', 'parentcategory', 'question');

            $mform->addElement('submit', 'newcategory',
                    get_string('createcategoryandaddrandomquestion', 'bayesian'));
        }

        // Cancel button.
        $mform->addElement('cancel');
        $mform->closeHeaderBefore('cancel');

        $mform->addElement('hidden', 'addonpage', 0, 'id="rform_qpage"');
        $mform->setType('addonpage', PARAM_SEQUENCE);
        $mform->addElement('hidden', 'cmid', 0);
        $mform->setType('cmid', PARAM_INT);
        $mform->addElement('hidden', 'returnurl', 0);
        $mform->setType('returnurl', PARAM_LOCALURL);

        // Add the javascript required to enhance this mform.
        $PAGE->requires->js_call_amd('mod_bayesian/add_random_form', 'init', [
            $mform->getAttribute('id'),
            $contexts->lowest()->id,
            $tops,
            $CFG->usetags
        ]);
    }

    public function validation($fromform, $files) {
        $errors = parent::validation($fromform, $files);

        if (!empty($fromform['newcategory']) && trim($fromform['name']) == '') {
            $errors['name'] = get_string('categorynamecantbeblank', 'question');
        }

        return $errors;
    }

    /**
     * Return an arbitrary array for the dropdown menu
     *
     * @param int $maxrand
     * @return array of integers [1, 2, ..., 100] (or to the smaller of $maxrand and 100.)
     */
    private function get_number_of_questions_to_add_choices($maxrand = 100) {
        $randomcount = array();
        for ($i = 1; $i <= min(100, $maxrand); $i++) {
            $randomcount[$i] = $i;
        }
        return $randomcount;
    }
}