<?php

declare(strict_types=1);

namespace Drupal\farm_quick_inventory\Exception;

/**
 * Thrown when deleting an asset that an inventory quick form still uses.
 */
class ReferencedInventoryAssetException extends \RuntimeException {

}
