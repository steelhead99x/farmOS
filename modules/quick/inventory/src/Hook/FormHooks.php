<?php

declare(strict_types=1);

namespace Drupal\farm_quick_inventory\Hook;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\asset\Entity\AssetInterface;
use Drupal\farm_quick_inventory\InventoryAssetReference;

/**
 * Form hook implementations for farm_quick_inventory.
 */
class FormHooks {

  use AutowireTrait;
  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected InventoryAssetReference $inventoryAssetReference,
    protected AccountInterface $currentUser,
    protected PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(&$form, FormStateInterface $form_state, $form_id) {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof EntityFormInterface || $form_object->getOperation() !== 'delete') {
      return;
    }
    $entity = $form_object->getEntity();
    if (!$entity instanceof AssetInterface || $entity->isNew()) {
      return;
    }
    $quick_forms = $this->inventoryAssetReference->getReferencingQuickForms($entity);
    if (!$quick_forms) {
      return;
    }
    $this->preventDeletion($form, $this->t('This asset cannot be deleted because it is the default asset for an inventory quick form. Edit the quick form and choose a different asset first.'), $quick_forms);
  }

  /**
   * Implements hook_form_FORM_ID_alter() for asset bulk deletion.
   */
  #[Hook('form_asset_delete_multiple_confirm_form_alter')]
  public function deleteMultipleFormAlter(&$form, FormStateInterface $form_state, $form_id) {
    $selection = $this->tempStoreFactory->get('entity_delete_multiple_confirm')->get($this->currentUser->id() . ':asset');
    if (!is_array($selection) || !$selection) {
      return;
    }
    $assets = $this->entityTypeManager->getStorage('asset')->loadMultiple(array_keys($selection));
    $quick_forms = [];
    foreach ($assets as $asset) {
      foreach ($this->inventoryAssetReference->getReferencingQuickForms($asset) as $id => $quick_form) {
        $quick_forms[$id] = $quick_form;
      }
    }
    if (!$quick_forms) {
      return;
    }
    $this->preventDeletion($form, $this->t('Some of the selected assets cannot be deleted because they are the default asset for an inventory quick form. Edit the quick form and choose a different asset first.'), $quick_forms);
  }

  /**
   * Replaces the delete submit button with an explanation.
   *
   * @param array $form
   *   The delete form.
   * @param string|\Stringable $message
   *   The error message to display.
   * @param \Drupal\farm_quick\Entity\QuickFormInstanceInterface[] $quick_forms
   *   Inventory quick forms that still reference the asset.
   */
  protected function preventDeletion(array &$form, string|\Stringable $message, array $quick_forms): void {
    unset($form['actions']['submit'], $form['description']);
    $items = [];
    foreach ($quick_forms as $quick_form) {
      $items[] = $quick_form->label() . ' (' . $quick_form->id() . ')';
    }
    $form['inventory_quick_form'] = [
      '#weight' => -10,
      'message' => [
        '#prefix' => '<div class="messages messages--error">',
        '#markup' => $message,
        '#suffix' => '</div>',
      ],
      'quick_forms' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Inventory quick forms'),
        '#items' => $items,
      ],
    ];
    $form['#validate'][] = [self::class, 'blockSubmission'];
    foreach ($quick_forms as $quick_form) {
      $form['#cache']['tags'] = array_merge($form['#cache']['tags'] ?? [], $quick_form->getCacheTags());
    }
  }

  /**
   * Prevents submission of a delete form for a referenced asset.
   *
   * The form is rebuilt before validation, so this callback is only attached
   * while a quick form still references the asset.
   */
  public static function blockSubmission(array &$form, FormStateInterface $form_state): void {
    $form_state->setErrorByName('inventory_quick_form', t('This asset cannot be deleted because it is the default asset for an inventory quick form. Edit the quick form and choose a different asset first.'));
  }

}
