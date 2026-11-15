<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_quick_inventory\Kernel;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormState;
use Drupal\Tests\farm_quick\Kernel\QuickFormTestBase;
use Drupal\asset\Entity\Asset;
use Drupal\farm_quick\Entity\QuickFormInstance;
use Drupal\farm_quick\Form\QuickForm;
use Drupal\farm_quick_inventory\Exception\ReferencedInventoryAssetException;
use Drupal\farm_quick_inventory\Hook\FormHooks;
use Drupal\farm_quick_inventory\InventoryAssetReference;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Route;

/**
 * Tests inventory quick forms that reference an asset.
 *
 * @group farm
 */
class QuickInventoryAssetReferenceTest extends QuickFormTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'farm_activity',
    'farm_equipment',
    'farm_equipment_type',
    'farm_inventory',
    'farm_observation',
    'farm_quantity_standard',
    'farm_quick_inventory',
    'farm_unit',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig([
      'farm_activity',
      'farm_equipment_type',
      'farm_equipment',
      'farm_observation',
      'farm_quantity_standard',
      'farm_unit',
    ]);
  }

  /**
   * A missing configured asset must not fatal menu access checks.
   */
  public function testAccessWhenConfiguredAssetIsMissing(): void {
    $quick_form = $this->createInventoryQuickForm(999999, 'seed_inventory', 'Seed inventory');
    $result = $this->quickFormAccess($quick_form->id());

    $this->assertTrue($result->isForbidden());
    $this->assertContains('asset:999999', $result->getCacheTags());
    $this->assertContains('quick_form_list', $result->getCacheTags());
    $this->assertContains('config:farm_quick.quick_form.seed_inventory', $result->getCacheTags());
  }

  /**
   * Access is allowed when the configured asset exists and is viewable.
   */
  public function testAccessWhenConfiguredAssetExists(): void {
    $asset = $this->createEquipment('Tractor');
    $quick_form = $this->createInventoryQuickForm((int) $asset->id(), 'tractor_inventory', 'Tractor inventory');
    $result = $this->quickFormAccess($quick_form->id());

    $this->assertTrue($result->isAllowed());
    $this->assertContains('quick_form_list', $result->getCacheTags());
    $this->assertContains('asset:' . $asset->id(), $result->getCacheTags());
  }

  /**
   * An asset selected by an inventory quick form cannot be deleted.
   */
  public function testReferencedAssetCannotBeDeleted(): void {
    $other = $this->createEquipment('Other');
    $asset = $this->createEquipment('Tractor');
    $quick_form = $this->createInventoryQuickForm((string) $asset->id(), 'seed_inventory', 'Seed inventory');

    // A different asset is not blocked by this quick form.
    $other->delete();
    $this->assertNull(Asset::load($other->id()));

    try {
      $asset->delete();
      $this->fail('Deleting an asset referenced by an inventory quick form should fail.');
    }
    catch (EntityStorageException $exception) {
      $this->assertInstanceOf(ReferencedInventoryAssetException::class, $exception->getPrevious());
      $this->assertStringContainsString('Seed inventory', $exception->getMessage());
      $this->assertStringContainsString('seed_inventory', $exception->getMessage());
    }

    $storage = $this->container->get('entity_type.manager')->getStorage('asset');
    $storage->resetCache();
    $this->assertNotNull($storage->load($asset->id()));

    // Clearing the quick form reference allows the asset to be deleted.
    $quick_form->set('settings', [
      'asset' => NULL,
      'log_type' => 'observation',
    ]);
    $quick_form->save();
    $asset->delete();
    $storage->resetCache();
    $this->assertNull($storage->load($asset->id()));
  }

  /**
   * JSON:API delete access is denied while the HTML delete form can explain.
   */
  public function testApiDeleteAccessIsForbidden(): void {
    $asset = $this->createEquipment('Tractor');
    $this->createInventoryQuickForm((string) $asset->id(), 'seed_inventory', 'Seed inventory');

    $this->assertTrue($asset->access('delete'));

    $this->container->get('entity_type.manager')->getAccessControlHandler('asset')->resetCache();
    $request = Request::create('/api/asset/equipment/' . $asset->uuid());
    $route = new Route('/api/asset/equipment/{entity}');
    $route->setRequirement('_format', 'api_json');
    $request->attributes->set('_route', 'jsonapi.asset--equipment.individual');
    $request->attributes->set('_route_object', $route);
    // Kernel tests clear the session during tearDown. Give this request a
    // session and remove it before the test ends.
    $request->setSession(new Session(new MockArraySessionStorage()));
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $access = $asset->access('delete', NULL, TRUE);
      $this->assertTrue($access->isForbidden());
      $this->assertContains('quick_form_list', $access->getCacheTags());
      $this->assertContains('route', $access->getCacheContexts());
    }
    finally {
      $request_stack->pop();
    }
  }

  /**
   * The asset delete form explains the reference and cannot be submitted.
   */
  public function testDeleteFormExplainsReferencedAsset(): void {
    $asset = $this->createEquipment('Tractor');
    $this->createInventoryQuickForm((string) $asset->id(), 'seed_inventory', 'Seed inventory');

    $form_object = $this->container->get('entity_type.manager')->getFormObject('asset', 'delete');
    $form_object->setEntity($asset);
    $form_state = new FormState();
    $form_state->setFormObject($form_object);
    $form = [
      'description' => ['#markup' => 'Are you sure?'],
      'actions' => [
        'submit' => ['#type' => 'submit'],
      ],
    ];
    $hooks = new FormHooks(
      $this->container->get('entity_type.manager'),
      $this->container->get(InventoryAssetReference::class),
      $this->container->get('current_user'),
      $this->container->get('tempstore.private'),
    );
    $hooks->formAlter($form, $form_state, 'asset_equipment_delete_form');

    $this->assertArrayNotHasKey('submit', $form['actions']);
    $this->assertArrayNotHasKey('description', $form);
    $this->assertArrayHasKey('inventory_quick_form', $form);
    $this->assertStringContainsString('Seed inventory', (string) $form['inventory_quick_form']['quick_forms']['#items'][0]);
    $this->assertContains([FormHooks::class, 'blockSubmission'], $form['#validate']);

    FormHooks::blockSubmission($form, $form_state);
    $this->assertTrue($form_state->hasAnyErrors());

    // An asset that is not referenced keeps its delete button.
    $other = $this->createEquipment('Other');
    $other_form_object = $this->container->get('entity_type.manager')->getFormObject('asset', 'delete');
    $other_form_object->setEntity($other);
    $other_state = new FormState();
    $other_state->setFormObject($other_form_object);
    $other_form = [
      'actions' => [
        'submit' => ['#type' => 'submit'],
      ],
    ];
    $hooks->formAlter($other_form, $other_state, 'asset_equipment_delete_form');
    $this->assertArrayHasKey('submit', $other_form['actions']);
  }

  /**
   * Creates an equipment asset.
   */
  protected function createEquipment(string $name): Asset {
    $asset = Asset::create([
      'name' => $name,
      'type' => 'equipment',
    ]);
    $asset->save();
    return $asset;
  }

  /**
   * Creates an inventory quick form instance configured for an asset.
   */
  protected function createInventoryQuickForm(int|string $asset_id, string $id, string $label): QuickFormInstance {
    $quick_form = QuickFormInstance::create([
      'id' => $id,
      'plugin' => 'inventory',
      'label' => $label,
      'description' => 'Record an inventory adjustment.',
      'helpText' => 'Choose the asset to adjust.',
      'settings' => [
        'asset' => $asset_id,
        'log_type' => 'observation',
      ],
    ]);
    $quick_form->save();
    return $quick_form;
  }

  /**
   * Runs the production quick form access check for an instance.
   */
  protected function quickFormAccess(string $id): AccessResultInterface {
    return $this->container->get('class_resolver')->getInstanceFromDefinition(QuickForm::class)->access($this->container->get('current_user'), $id);
  }

}
