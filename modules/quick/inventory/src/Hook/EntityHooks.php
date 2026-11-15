<?php

declare(strict_types=1);

namespace Drupal\farm_quick_inventory\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\asset\Entity\AssetInterface;
use Drupal\farm_quick_inventory\Exception\ReferencedInventoryAssetException;
use Drupal\farm_quick_inventory\InventoryAssetReference;

/**
 * Entity hook implementations for farm_quick_inventory.
 */
class EntityHooks {

  use AutowireTrait;

  public function __construct(
    protected InventoryAssetReference $inventoryAssetReference,
    protected RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Implements hook_entity_predelete().
   *
   * Run before other predelete hooks so a blocked asset is not processed.
   */
  #[Hook('entity_predelete', order: Order::First)]
  public function entityPredelete(EntityInterface $entity) {
    if (!$entity instanceof AssetInterface) {
      return;
    }
    $quick_forms = $this->inventoryAssetReference->getReferencingQuickForms($entity);
    if (!$quick_forms) {
      return;
    }

    $labels = [];
    foreach ($quick_forms as $quick_form) {
      $labels[] = $quick_form->label() . ' (' . $quick_form->id() . ')';
    }
    throw new ReferencedInventoryAssetException(sprintf(
      'Cannot delete asset "%s" (%s) because it is the default asset for inventory quick form(s): %s. Edit the quick form and choose a different asset first.',
      $entity->label(),
      $entity->id(),
      implode(', ', $labels),
    ));
  }

  /**
   * Implements hook_ENTITY_TYPE_access().
   */
  #[Hook('asset_access')]
  public function assetAccess(AssetInterface $asset, $operation, AccountInterface $account) {
    if ($operation !== 'delete') {
      return AccessResult::neutral();
    }

    $quick_forms = $this->inventoryAssetReference->getReferencingQuickForms($asset);

    // JSON:API checks entity access before delete. Deny that here so the API
    // returns 403 instead of a storage exception. The HTML delete form stays
    // reachable and explains the block. Programmatic deletes are stopped in
    // entity_predelete().
    if ($quick_forms && $this->isApiRequest()) {
      $result = AccessResult::forbidden('The asset is the default asset for an inventory quick form.');
    }
    else {
      $result = AccessResult::neutral();
    }
    $result->addCacheTags(['quick_form_list']);
    foreach ($quick_forms as $quick_form) {
      $result->addCacheableDependency($quick_form);
    }
    if ($quick_forms) {
      // The result depends on whether the current route is an API route.
      $result->addCacheContexts(['route']);
    }
    return $result;
  }

  /**
   * Whether the current route is a JSON:API request.
   *
   * @return bool
   *   TRUE when the route requires the api_json format.
   */
  protected function isApiRequest(): bool {
    $route = $this->routeMatch->getRouteObject();
    if (!$route) {
      return FALSE;
    }
    $format = $route->getRequirement('_format');
    if (!is_string($format) || $format === '') {
      return FALSE;
    }
    return in_array('api_json', explode('|', $format), TRUE);
  }

}
