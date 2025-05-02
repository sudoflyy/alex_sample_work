<?php

namespace Drupal\ipc_syncdb\Traits;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\ipc_commerce\GcommerceConstants;
use Drupal\ipc_commerce\UserHelper;

/**
 * The transaction customer trait.
 */
trait TransactionCustomerTrait {

  /**
   * The Entity Type Manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Helper function to get Customer from the order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   Customer ID from the entity.
   */
  protected function getCustomerId(OrderInterface $order): string {
    $customer = $order->getCustomer();
    $company = $this->getEntityCompanyGroup($order);
    $is_user_condition = $customer && !$customer->isAnonymous() && !$customer->get('netsuite_id')->isEmpty();
    $is_company_condition = $company && !$company->get('netsuite_id')->isEmpty();
    $customer_id = '';
    if ($is_company_condition) {
      $customer_id = $company->get('netsuite_id')->getString();
    }
    elseif ($is_user_condition) {
      $customer_id = $customer->get('netsuite_id')->getString();
    }
    return $customer_id;
  }

  /**
   * Gets endUserId.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   Value for 'endUserId' in PostTransaction.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getEndUserId(OrderInterface $order): string {
    $customer = $order->getCustomer();
    $company = $this->getEntityCompanyGroup($order);
    $is_user_condition = $customer && !$customer->isAnonymous() && !$customer->get('netsuite_id')->isEmpty();
    $is_company_condition = $company && !$company->get('netsuite_id')->isEmpty();
    $end_user_id = '';
    if ($is_user_condition) {
      $end_user_id = $customer->get('netsuite_id')->getString();
    }
    elseif ($is_company_condition) {
      $end_user_id = $company->get('netsuite_id')->getString();
    }
    return $end_user_id;
  }

  /**
   * Gets the Company (Group) from entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The commerce entity.
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The company.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  protected function getEntityCompanyGroup(ContentEntityInterface $entity): ?GroupInterface {
    /** @var \Drupal\group\Entity\Storage\GroupRelationshipStorageInterface $group_relationship_storage */
    $group_relationship_storage = $this->entityTypeManager->getStorage(GcommerceConstants::GROUP_RELATIONSHIP_ENTITY_TYPE_ID->value);
    if (!$entity->isNew()) {
      $group_relationships = $group_relationship_storage->loadByEntity($entity);

      if ($group_relationships) {
        $group_relationship = reset($group_relationships);
        $group = $group_relationship->getGroup();
      }
    }

    return $group ?? NULL;
  }

  /**
   * Get the primary company for the customer of an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The commerce order.
   *
   * @return \Drupal\group\Entity\GroupInterface|null
   *   The primary company of the order customer.
   */
  protected function getOrderCustomerPrimaryCompany(OrderInterface $order): ?GroupInterface {
    $customer = $order->getCustomer();
    return UserHelper::getPrimaryCompany($customer);
  }

}
