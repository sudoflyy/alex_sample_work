@api @javascript
Feature: Test My Purchases page
  In order manage My Purchases
  Users who have made purchases
  Should be able to view them on the My Purchases page

  Scenario: Make sure that instructor user can see their purchases on the "My Purchases" page
    Given I am on the homepage
    And I am logged-in via SSO as "TEST_USER_1"
    And I accept the Access Agreement
    And I navigate to My Dashboard
    When I click "View Purchases"
    Then I see the text "My Account: Purchases"
