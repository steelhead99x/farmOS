<?php

declare(strict_types=1);

namespace Drupal\farm_quick_inventory;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\asset\Entity\AssetInterface;

/**
 * Finds inventory quick forms that reference an asset.
 */
class InventoryAssetReference {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Returns inventory quick forms that use this asset as their default.
   *
   * The asset is stored as a scalar ID in quick form settings, so it is not
   * visible to entity_reference_integrity.
   *
   * @param \Drupal\asset\Entity\AssetInterface $asset
   *   The asset that may be selected by an inventory quick form.
   *
   * @return \Drupal\farm_quick\Entity\QuickFormInstanceInterface[]
   *   Quick form instances keyed by ID.
   */
  public function getReferencingQuickForms(AssetInterface $asset): array {
    if ($asset->id() === NULL || $asset->id() === '') {
      return [];
    }

    $matches = [];
    $quick_forms = $this->entityTypeManager->getStorage('quick_form')->loadByProperties([
      'plugin' => 'inventory',
    ]);
    foreach ($quick_forms as $quick_form) {
      $asset_id = $quick_form->getSettings()['asset'] ?? NULL;
      if (!empty($asset_id) && (string) $asset_id === (string) $asset->id()) {
        $matches[$quick_form->id()] = $quick_form;
      }
    }
    return $matches;
  }

}
