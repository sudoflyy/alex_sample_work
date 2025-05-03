@api @javascript @checkout @vouchers
Feature: Purchase vouchers and verify
  In order to obtain vouchers and enroll self or others in courses
  Users serving as Instructor
  Should be able to purchase vouchers and verify the purchase by viewing the My Vouchers page

  @debug
  Scenario: Purchase single voucher - enroll self
    Given I am on the homepage
    And I am logged-in via SSO as "TEST_USER_1"
    And I accept the Access Agreement
    And I navigate to the Product List page
    And I navigate to the Product Details page for "TEST_PRODUCT_3"
    And I add the product to the cart with quantity "3"
    And I confirm that the Add to Cart confirmation modal is displayed
    And I navigate to the Cart from Added to Cart modal
    And I click to exit out of the You Already Have Access modal
    And I confirm that the "TEST_PRODUCT_3" course is in the cart and set quantity to "3"
    And I press the "Checkout" button
    And I choose to purchase vouchers and continue to Review
    And I click the PayPal button
    When I complete the PayPal payment form
    Then I see the purchase confirmation page
    And I navigate to My Dashboard
    And I click "Enroll Students"
    And I should see the text "My Account: Vouchers" in the "breadcrumb" region
    And I verify that the Voucher availability for course "TEST_PRODUCT_3" is "3 of 3"
    When I view the existing vouchers for course "TEST_PRODUCT_3"
    Then I verify that the data in View Vouchers modal is correct for course "TEST_PRODUCT_3"
    Then I verify that there are a total of "3" rows(s) in the Vouchers table
