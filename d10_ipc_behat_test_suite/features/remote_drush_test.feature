@api @javascript @disabled
Feature: Test Running a Drush Command Against a Remote Server
  In order to utilize the full functionality of the Behat test suite
  Test Case developers
  Need to be able to execute Drush commands against remote environments

  Scenario: Make sure that anonymous users see the account menu links
    Given I am not logged in
    And I am on the homepage
    Then I should see the link "Register" in the "header_top" region
    And I should see the link "Log in" in the "header_top" region
    And I run drush "advancedqueue:queue:process" "ipc_registration_sync"
