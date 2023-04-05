@mod @mod_bayesian @javascript
Feature: Adding questions to a bayesian from the question bank
  In order to re-use questions
  As a teacher
  I want to add questions from the question bank

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email |
      | teacher1 | Teacher | 1 | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1 | weeks |
    And the following "course enrolments" exist:
      | user | course | role |
      | teacher1 | C1 | editingteacher |
    And the following "activities" exist:
      | activity   | name   | intro                           | course | idnumber |
      | bayesian       | bayesian 1 | bayesian 1 for testing the Add menu | C1     | bayesian1    |
    And the following "question categories" exist:
      | contextlevel | reference | name           |
      | Course       | C1        | Test questions |
    And the following "questions" exist:
      | questioncategory | qtype     | name             | user     | questiontext     | idnumber |
      | Test questions   | essay     | question 01 name | admin    | Question 01 text |          |
      | Test questions   | essay     | question 02 name | teacher1 | Question 02 text | qidnum   |

  Scenario: The questions can be filtered by tag
    Given I am on the "question 01 name" "core_question > edit" page logged in as teacher1
    And I set the following fields to these values:
      | Tags | foo |
    And I press "id_submitbutton"
    And I choose "Edit question" action for "question 02 name" in the question bank
    And I set the following fields to these values:
      | Tags | bar |
    And I press "id_submitbutton"
    When I am on the "bayesian 1" "mod_bayesian > Edit" page
    And I open the "last" add to bayesian menu
    And I follow "from question bank"
    Then I should see "foo" in the "question 01 name" "table_row"
    And I should see "bar" in the "question 02 name" "table_row"
    And I should see "qidnum" in the "question 02 name" "table_row"
    And I set the field "Filter by tags..." to "foo"
    And I press the enter key
    And I should see "question 01 name" in the "categoryquestions" "table"
    And I should not see "question 02 name" in the "categoryquestions" "table"

  Scenario: The question modal can be paginated
    Given the following "questions" exist:
      | questioncategory | qtype     | name             | user     | questiontext     |
      | Test questions   | essay     | question 03 name | teacher1 | Question 03 text |
      | Test questions   | essay     | question 04 name | teacher1 | Question 04 text |
      | Test questions   | essay     | question 05 name | teacher1 | Question 05 text |
      | Test questions   | essay     | question 06 name | teacher1 | Question 06 text |
      | Test questions   | essay     | question 07 name | teacher1 | Question 07 text |
      | Test questions   | essay     | question 08 name | teacher1 | Question 08 text |
      | Test questions   | essay     | question 09 name | teacher1 | Question 09 text |
      | Test questions   | essay     | question 10 name | teacher1 | Question 10 text |
      | Test questions   | essay     | question 11 name | teacher1 | Question 11 text |
      | Test questions   | essay     | question 12 name | teacher1 | Question 12 text |
      | Test questions   | essay     | question 13 name | teacher1 | Question 13 text |
      | Test questions   | essay     | question 14 name | teacher1 | Question 14 text |
      | Test questions   | essay     | question 15 name | teacher1 | Question 15 text |
      | Test questions   | essay     | question 16 name | teacher1 | Question 16 text |
      | Test questions   | essay     | question 17 name | teacher1 | Question 17 text |
      | Test questions   | essay     | question 18 name | teacher1 | Question 18 text |
      | Test questions   | essay     | question 19 name | teacher1 | Question 19 text |
      | Test questions   | essay     | question 20 name | teacher1 | Question 20 text |
      | Test questions   | essay     | question 21 name | teacher1 | Question 21 text |
      | Test questions   | essay     | question 22 name | teacher1 | Question 22 text |
    And I log in as "teacher1"
    And I am on the "bayesian 1" "mod_bayesian > Edit" page
    And I open the "last" add to bayesian menu
    And I follow "from question bank"
    And I click on "2" "link" in the ".pagination" "css_element"
    Then I should see "question 21 name" in the "categoryquestions" "table"
    And I should see "question 22 name" in the "categoryquestions" "table"
    And I should not see "question 01 name" in the "categoryquestions" "table"
    And I click on "Show all 22" "link" in the ".question-showall-text" "css_element"
    And I should see "question 01 name" in the "categoryquestions" "table"
    And I should see "question 22 name" in the "categoryquestions" "table"

  Scenario: Questions are added in the right place with multiple sections
    Given the following "questions" exist:
      | questioncategory | qtype | name             | questiontext     |
      | Test questions   | essay | question 03 name | question 03 text |
    And bayesian "bayesian 1" contains the following questions:
      | question         | page |
      | question 01 name | 1    |
      | question 02 name | 2    |
    And bayesian "bayesian 1" contains the following sections:
      | heading   | firstslot | shuffle |
      | Section 1 | 1         | 0       |
      | Section 2 | 2         | 0       |
    And I log in as "teacher1"
    And I am on the "bayesian 1" "mod_bayesian > Edit" page
    When I open the "Page 1" add to bayesian menu
    And I follow "from question bank"
    And I set the field with xpath "//tr[contains(normalize-space(.), 'question 03 name')]//input[@type='checkbox']" to "1"
    And I click on "Add selected questions to the bayesian" "button"
    Then I should see "question 03 name" on bayesian page "1"
    And I should see "question 01 name" before "question 03 name" on the edit bayesian page

  Scenario: Add several selected questions from the question bank
    Given I am on the "bayesian 1" "mod_bayesian > Edit" page logged in as "teacher1"
    When I open the "last" add to bayesian menu
    And I follow "from question bank"
    And I set the field with xpath "//input[@type='checkbox' and @id='qbheadercheckbox']" to "1"
    And I press "Add selected questions to the bayesian"
    Then I should see "question 01 name" on bayesian page "1"
    And I should see "question 02 name" on bayesian page "2"

  @javascript
  Scenario: Validate the sorting while adding questions from question bank
    Given the following "questions" exist:
      | questioncategory | qtype       | name              | questiontext          |
      | Test questions   | multichoice | question 03 name  | question 03 name text |
    And I am on the "bayesian 1" "mod_bayesian > Edit" page logged in as "teacher1"
    When I open the "last" add to bayesian menu
    And I follow "from question bank"
    And I click on "Sort by Question ascending" "link"
    Then "question 01 name" "text" should appear before "question 02 name" "text"
    And I click on "Sort by Question descending" "link"
    And "question 03 name" "text" should appear before "question 01 name" "text"
    And I follow "Sort by Question type ascending"
    Then "question 01 name" "text" should appear before "question 03 name" "text"
    And I follow "Sort by Question type descending"
    Then "question 03 name" "text" should appear before "question 01 name" "text"
