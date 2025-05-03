@api @javascript @checkout
Feature: Checkout Flow PayPal
  In order to purchase products through the online store
  Users serving as Instructor
  Should be able to go through the full checkout process and pay using PayPal

  Scenario: Checkout Flow - Instructor purchasing vouchers
    Given I am on the homepage
    And I am logged-in via SSO as "TEST_USER_1"
    And I accept the Access Agreement
    And I navigate to the Product List page
    And I navigate to the Product Details page for "TEST_PRODUCT_6"
    And I add the product to the cart
    And I confirm that the Add to Cart confirmation modal is displayed
    And I press the 'Continue Shopping' button
    And I navigate to the Product Details page for "TEST_PRODUCT_8"
    And I add the product to the cart
    And I confirm that the Add to Cart confirmation modal is displayed
    And I navigate to the Cart from Added to Cart modal
    And I click to exit out of the You Already Have Access modal
    And I confirm that the "TEST_PRODUCT_8" course is in the cart and set quantity to "1"
    And I press the "Checkout" button
    And I choose to purchase vouchers and continue to Review
    And I click the PayPal button
    When I complete the PayPal payment form
    Then I see the purchase confirmation page
