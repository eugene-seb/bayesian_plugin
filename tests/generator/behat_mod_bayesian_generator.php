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
 * Behat data generator for mod_bayesian.
 *
 * @package   mod_bayesian
 * @category  test
 * @copyright 2019 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


/**
 * Behat data generator for mod_bayesian.
 *
 * @copyright 2019 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_bayesian_generator extends behat_generator_base {

    protected function get_creatable_entities(): array {
        return [
            'group overrides' => [
                'singular' => 'group override',
                'datagenerator' => 'override',
                'required' => ['bayesian', 'group'],
                'switchids' => ['bayesian' => 'bayesian', 'group' => 'groupid'],
            ],
            'user overrides' => [
                'singular' => 'user override',
                'datagenerator' => 'override',
                'required' => ['bayesian', 'user'],
                'switchids' => ['bayesian' => 'bayesian', 'user' => 'userid'],
            ],
        ];
    }

    /**
     * Look up the id of a bayesian from its name.
     *
     * @param string $bayesianname the bayesian name, for example 'Test bayesian'.
     * @return int corresponding id.
     */
    protected function get_bayesian_id(string $bayesianname): int {
        global $DB;

        if (!$id = $DB->get_field('bayesian', 'id', ['name' => $bayesianname])) {
            throw new Exception('There is no bayesian with name "' . $bayesianname . '" does not exist');
        }
        return $id;
    }
}
