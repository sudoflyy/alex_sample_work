@api @javascript @products
Feature: Add To Cart
  In order to purchase products
  Users serving as Instructor
  Should be able to add products to the cart

  Scenario: View cart page - Course with no pre-reqs & course with pre-reqs both in cart
    Given I am on the homepage
    And I navigate to the Product List page
    And I navigate to the Product Details page for "TEST_PRODUCT_3"
    And I add the product to the cart
    And I confirm that the Add to Cart confirmation modal is displayed
    And I press the 'Continue Shopping' button
    And I confirm that the active breadcrumb is "Education Catalog"
    And I navigate to the Product Details page for "TEST_PRODUCT_8"
    And I add the product to the cart
    And I confirm that the Add to Cart confirmation modal is displayed
    And I confirm that it is displayed that the course has pre-requisites
    When I navigate to the Cart from Added to Cart modal
    Then I confirm that the "TEST_PRODUCT_3" course is in the cart and set quantity to "1"
    And I confirm that the "TEST_PRODUCT_8" course is in the cart and set quantity to "1"
