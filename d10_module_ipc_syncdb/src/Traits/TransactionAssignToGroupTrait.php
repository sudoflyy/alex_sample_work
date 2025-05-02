<?php

namespace Drupal\ipc_syncdb\Traits;

use Drupal\commerce_invoice\Entity\InvoiceInterface;
use Drupal\commerce_license\Entity\LicenseInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\ipc_commerce\GcommerceConstants;

/**
 * Helper to assign entity to group.
 */
trait TransactionAssignToGroupTrait {

  /**
   * Add entity as a Group Relationship.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The provided entity.
   * @param \Drupal\group\Entity\GroupInterface $company
   *   The member company.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  protected function assignToGroup(ContentEntityInterface $entity, GroupInterface $company): void {
    $plugin_id = '';
    if ($entity instanceof InvoiceInterface) {
      $plugin_id = 'group_invoice:' . $entity->bundle();
    }
    elseif ($entity instanceof LicenseInterface) {
      $plugin_id = 'group_license:' . $entity->bundle();
    }

    $group_type = $company->getGroupType();
    if ($group_type->hasPlugin($plugin_id)) {
      /** @var \Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface $group_relationship_type_storage */
      $group_relationship_type_storage = $this->entityTypeManager->getStorage(GcommerceConstants::GROUP_RELATIONSHIP_TYPE_ENTITY_TYPE_ID->value);
      $group_relationship_type = $group_relationship_type_storage->getRelationshipTypeId('company', $entity->getEntityTypeId() . ':' . $entity->bundle());

      // Check if entity is already related to some groups before assigning
      // it to company from the transaction result.
      // Load all the group relationships for this entity.
      /** @var \Drupal\group\Entity\Storage\GroupRelationshipStorageInterface $group_relationship_storage */
      $group_relationship_storage = $this->entityTypeManager->getStorage(GcommerceConstants::GROUP_RELATIONSHIP_ENTITY_TYPE_ID->value);
      $group_relationships = $group_relationship_storage->loadByEntity($entity, $group_relationship_type);
      foreach ($group_relationships as $group_relationship) {
        if ($group_relationship->getGroup()->id() === $company->id()) {
          continue;
        }
        $group_relationship->delete();
      }

      // Add the invoice to the company, if not already added.
      if (empty($company->getRelationshipsByEntity($entity, $plugin_id))) {
        $company->addRelationship($entity, $plugin_id);
      }
    }

  }

}
