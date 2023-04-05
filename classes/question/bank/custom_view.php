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
 * Defines the custom question bank view used on the Edit bayesian page.
 *
 * @package   mod_bayesian
 * @category  question
 * @copyright 1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_bayesian\question\bank;

use core_question\local\bank\question_version_status;
use mod_bayesian\question\bank\filter\custom_category_condition;

/**
 * Subclass to customise the view of the question bank for the bayesian editing screen.
 *
 * @copyright  2009 Tim Hunt
 * @author     2021 Safat Shahin <safatshahin@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_view extends \core_question\local\bank\view {
    /** @var int number of questions per page to show in the add from question bank modal. */
    const DEFAULT_PAGE_SIZE = 20;

    /** @var bool $bayesianhasattempts whether the bayesian this is used by has been attemptd. */
    protected $bayesianhasattempts = false;

    /** @var \stdClass $bayesian the bayesian settings. */
    protected $bayesian = false;

    /** @var int The maximum displayed length of the category info. */
    const MAX_TEXT_LENGTH = 200;

    /**
     * Constructor.
     * @param \core_question\local\bank\question_edit_contexts $contexts
     * @param \moodle_url $pageurl
     * @param \stdClass $course course settings
     * @param \stdClass $cm activity settings.
     * @param \stdClass $bayesian bayesian settings.
     */
    public function __construct($contexts, $pageurl, $course, $cm, $bayesian) {
        parent::__construct($contexts, $pageurl, $course, $cm);
        $this->bayesian = $bayesian;
        $this->pagesize = self::DEFAULT_PAGE_SIZE;
    }

    protected function get_question_bank_plugins(): array {
        $questionbankclasscolumns = [];
        $corequestionbankcolumns = [
            'add_action_column',
            'checkbox_column',
            'question_type_column',
            'question_name_text_column',
            'preview_action_column'
        ];

        if (question_get_display_preference('qbshowtext', 0, PARAM_BOOL, new \moodle_url(''))) {
            $corequestionbankcolumns[] = 'question_text_row';
        }

        foreach ($corequestionbankcolumns as $fullname) {
            $shortname = $fullname;
            if (class_exists('mod_bayesian\\question\\bank\\' . $fullname)) {
                $fullname = 'mod_bayesian\\question\\bank\\' . $fullname;
                $questionbankclasscolumns[$shortname] = new $fullname($this);
            } else if (class_exists('core_question\\local\\bank\\' . $fullname)) {
                $fullname = 'core_question\\local\\bank\\' . $fullname;
                $questionbankclasscolumns[$shortname] = new $fullname($this);
            } else {
                $questionbankclasscolumns[$shortname] = '';
            }
        }
        $plugins = \core_component::get_plugin_list_with_class('qbank', 'plugin_feature', 'plugin_feature.php');
        foreach ($plugins as $componentname => $plugin) {
            $pluginentrypointobject = new $plugin();
            $plugincolumnobjects = $pluginentrypointobject->get_question_columns($this);
            // Don't need the plugins without column objects.
            if (empty($plugincolumnobjects)) {
                unset($plugins[$componentname]);
                continue;
            }
            foreach ($plugincolumnobjects as $columnobject) {
                $columnname = $columnobject->get_column_name();
                foreach ($corequestionbankcolumns as $key => $corequestionbankcolumn) {
                    if (!\core\plugininfo\qbank::is_plugin_enabled($componentname)) {
                        unset($questionbankclasscolumns[$columnname]);
                        continue;
                    }
                    // Check if it has custom preference selector to view/hide.
                    if ($columnobject->has_preference() && !$columnobject->get_preference()) {
                        continue;
                    }
                    if ($corequestionbankcolumn === $columnname) {
                        $questionbankclasscolumns[$columnname] = $columnobject;
                    }
                }
            }
        }

        // Mitigate the error in case of any regression.
        foreach ($questionbankclasscolumns as $shortname => $questionbankclasscolumn) {
            if (empty($questionbankclasscolumn)) {
                unset($questionbankclasscolumns[$shortname]);
            }
        }

        return $questionbankclasscolumns;
    }

    protected function heading_column(): string {
        return 'mod_bayesian\\question\\bank\\question_name_text_column';
    }

    protected function default_sort(): array {
        // Using the extended class for bayesian specific sort.
        return [
            'qbank_viewquestiontype\\question_type_column' => 1,
            'mod_bayesian\\question\\bank\\question_name_text_column' => 1,
        ];
    }

    /**
     * Let the question bank display know whether the bayesian has been attempted,
     * hence whether some bits of UI, like the add this question to the bayesian icon,
     * should be displayed.
     *
     * @param bool $bayesianhasattempts whether the bayesian has attempts.
     */
    public function set_bayesian_has_attempts($bayesianhasattempts): void {
        $this->bayesianhasattempts = $bayesianhasattempts;
        if ($bayesianhasattempts && isset($this->visiblecolumns['addtobayesianaction'])) {
            unset($this->visiblecolumns['addtobayesianaction']);
        }
    }

    /**
     * Question preview url.
     *
     * @param \stdClass $question
     * @return \moodle_url
     */
    public function preview_question_url($question) {
        return bayesian_question_preview_url($this->bayesian, $question);
    }

    /**
     * URL of add to bayesian.
     *
     * @param $questionid
     * @return \moodle_url
     */
    public function add_to_bayesian_url($questionid) {
        $params = $this->baseurl->params();
        $params['addquestion'] = $questionid;
        $params['sesskey'] = sesskey();
        return new \moodle_url('/mod/bayesian/edit.php', $params);
    }

    /**
     * Renders the html question bank (same as display, but returns the result).
     *
     * Note that you can only output this rendered result once per page, as
     * it contains IDs which must be unique.
     *
     * @param array $pagevars
     * @param string $tabname
     * @return string HTML code for the form
     */
    public function render($pagevars, $tabname): string {
        ob_start();
        $this->display($pagevars, $tabname);
        $out = ob_get_contents();
        ob_end_clean();
        return $out;
    }

    protected function display_bottom_controls(\context $catcontext): void {
        $cmoptions = new \stdClass();
        $cmoptions->hasattempts = !empty($this->bayesianhasattempts);

        $canuseall = has_capability('moodle/question:useall', $catcontext);

        echo \html_writer::start_tag('div', ['class' => 'pt-2']);
        if ($canuseall) {
            // Add selected questions to the bayesian.
            $params = array(
                'type' => 'submit',
                'name' => 'add',
                'class' => 'btn btn-primary',
                'value' => get_string('addselectedquestionstobayesian', 'bayesian'),
                'data-action' => 'toggle',
                'data-togglegroup' => 'qbank',
                'data-toggle' => 'action',
                'disabled' => true,
            );
            echo \html_writer::empty_tag('input', $params);
        }
        echo \html_writer::end_tag('div');
    }

    protected function create_new_question_form($category, $canadd): void {
        // Don't display this.
    }

    /**
     * Override the base implementation in \core_question\local\bank\view
     * because we don't want to print the headers in the fragment
     * for the modal.
     */
    protected function display_question_bank_header(): void {
    }

    /**
     * Override the base implementation in \core_question\bank\view
     * because we don't want it to read from the $_POST global variables
     * for the sort parameters since they are not present in a fragment.
     *
     * Unfortunately the best we can do is to look at the URL for
     * those parameters (only marginally better really).
     */
    protected function init_sort_from_params(): void {
        $this->sort = [];
        for ($i = 1; $i <= self::MAX_SORTS; $i++) {
            if (!$sort = $this->baseurl->param('qbs' . $i)) {
                break;
            }
            // Work out the appropriate order.
            $order = 1;
            if ($sort[0] == '-') {
                $order = -1;
                $sort = substr($sort, 1);
                if (!$sort) {
                    break;
                }
            }
            // Deal with subsorts.
            list($colname) = $this->parse_subsort($sort);
            $this->get_column_type($colname);
            $this->sort[$sort] = $order;
        }
    }

    protected function build_query(): void {
        // Get the required tables and fields.
        $joins = [];
        $fields = ['qv.status', 'qc.id as categoryid', 'qv.version', 'qv.id as versionid', 'qbe.id as questionbankentryid'];
        if (!empty($this->requiredcolumns)) {
            foreach ($this->requiredcolumns as $column) {
                $extrajoins = $column->get_extra_joins();
                foreach ($extrajoins as $prefix => $join) {
                    if (isset($joins[$prefix]) && $joins[$prefix] != $join) {
                        throw new \coding_exception('Join ' . $join . ' conflicts with previous join ' . $joins[$prefix]);
                    }
                    $joins[$prefix] = $join;
                }
                $fields = array_merge($fields, $column->get_required_fields());
            }
        }
        $fields = array_unique($fields);

        // Build the order by clause.
        $sorts = [];
        foreach ($this->sort as $sort => $order) {
            list($colname, $subsort) = $this->parse_subsort($sort);
            $sorts[] = $this->requiredcolumns[$colname]->sort_expression($order < 0, $subsort);
        }

        // Build the where clause.
        $latestversion = 'qv.version = (SELECT MAX(v.version)
                                          FROM {question_versions} v
                                          JOIN {question_bank_entries} be
                                            ON be.id = v.questionbankentryid
                                         WHERE be.id = qbe.id)';
        $readyonly = "qv.status = '" . question_version_status::QUESTION_STATUS_READY . "' ";
        $tests = ['q.parent = 0', $latestversion, $readyonly];
        $this->sqlparams = [];
        foreach ($this->searchconditions as $searchcondition) {
            if ($searchcondition->where()) {
                $tests[] = '((' . $searchcondition->where() .'))';
            }
            if ($searchcondition->params()) {
                $this->sqlparams = array_merge($this->sqlparams, $searchcondition->params());
            }
        }
        // Build the SQL.
        $sql = ' FROM {question} q ' . implode(' ', $joins);
        $sql .= ' WHERE ' . implode(' AND ', $tests);
        $this->countsql = 'SELECT count(1)' . $sql;
        $this->loadsql = 'SELECT ' . implode(', ', $fields) . $sql . ' ORDER BY ' . implode(', ', $sorts);
    }

    public function wanted_filters($cat, $tagids, $showhidden, $recurse, $editcontexts, $showquestiontext): void {
        global $CFG;
        list(, $contextid) = explode(',', $cat);
        $catcontext = \context::instance_by_id($contextid);
        $thiscontext = $this->get_most_specific_context();
        // Category selection form.
        $this->display_question_bank_header();

        // Display tag filter if usetags setting is enabled/enablefilters is true.
        if ($this->enablefilters) {
            if (is_array($this->customfilterobjects)) {
                foreach ($this->customfilterobjects as $filterobjects) {
                    $this->searchconditions[] = $filterobjects;
                }
            } else {
                if ($CFG->usetags) {
                    array_unshift($this->searchconditions,
                        new \core_question\bank\search\tag_condition([$catcontext, $thiscontext], $tagids));
                }

                array_unshift($this->searchconditions, new \core_question\bank\search\hidden_condition(!$showhidden));
                array_unshift($this->searchconditions, new custom_category_condition(
                    $cat, $recurse, $editcontexts, $this->baseurl, $this->course));
            }
        }
        $this->display_options_form($showquestiontext);
    }
}
