<?php

namespace Drupal\ggroup_role_mapper\Access;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\flexible_permissions\CalculatedPermissionsItem;
use Drupal\flexible_permissions\PermissionCalculatorBase;
use Drupal\ggroup\GroupHierarchyManager;
use Drupal\ggroup\Plugin\Group\Relation\Subgroup;
use Drupal\group\Access\CalculatedGroupPermissionsItem;
use Drupal\group\Access\CalculatedGroupPermissionsItemInterface;
use Drupal\ggroup_role_mapper\GroupRoleInheritanceInterface;
use Drupal\group\GroupMembershipLoader;
use Drupal\group\PermissionScopeInterface;

/**
 * Calculates group permissions for an account.
 */
class InheritGroupPermissionCalculator extends PermissionCalculatorBase {

  /**
   * The group hierarchy manager.
   *
   * @var \Drupal\ggroup\GroupHierarchyManager
   */
  protected $hierarchyManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The group role inheritance manager.
   *
   * @var \Drupal\ggroup_role_mapper\GroupRoleInheritanceInterface
   */
  protected $groupRoleInheritanceManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The group membership loader.
   *
   * @var \Drupal\group\GroupMembershipLoader
   */
  protected $membershipLoader;

  /**
   * Static cache for all inherited group roles by user.
   *
   * A nested array with all inherited roles keyed by user ID and group ID.
   *
   * @var array
   */
  protected $mappedRoles = [];

  /**
   * Static cache for groups.
   *
   * @var array
   */
  protected $groups = [];

  /**
   * Constructs a InheritGroupPermissionCalculator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ggroup\GroupHierarchyManager $hierarchy_manager
   *   The group hierarchy manager.
   * @param \Drupal\group\GroupMembershipLoader $membership_loader
   *   The group membership loader.
   * @param \Drupal\ggroup_role_mapper\GroupRoleInheritanceInterface $group_role_inheritance_manager
   *   The group membership loader.
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    GroupHierarchyManager $hierarchy_manager,
    GroupMembershipLoader $membership_loader,
    GroupRoleInheritanceInterface $group_role_inheritance_manager,
    Connection $database
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->hierarchyManager = $hierarchy_manager;
    $this->membershipLoader = $membership_loader;
    $this->groupRoleInheritanceManager = $group_role_inheritance_manager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public function calculatePermissions(AccountInterface $account, $scope) {
    $calculated_permissions = parent::calculatePermissions($account, $scope);

    if ($scope == PermissionScopeInterface::INDIVIDUAL_ID) {
      return $this->calculateMemberPermissions($calculated_permissions, $account, TRUE);
    }

    if ($scope == PermissionScopeInterface::INSIDER_ID || $scope == PermissionScopeInterface::OUTSIDER_ID) {
//      return $this->calculateNonMemberPermissions($calculated_permissions, $account);
    }

    return $calculated_permissions;
  }

  public function calculateMemberPermissions($calculated_permissions, $account) {
    $account_id = $account->id();

    // The member permissions need to be recalculated whenever the user is added
    // to or removed from a group.
    $calculated_permissions->addCacheTags(['group_relationship_list:plugin:group_membership:entity:' . $account_id]);
    $calculated_permissions->addCacheContexts(['user']);

    $calculated_permissions->addCacheableDependency($this->entityTypeManager->getStorage('user')->load($account_id));

    $group_memberships = $this->membershipLoader->loadByUser($account);

    $this->processedGroupTypes = [];
    foreach ($group_memberships as $group_membership) {
      $group_id = $group_membership->getGroup()->id();
      $group_type_id = $group_membership->getGroup()->getGroupType()->id();

      $roles = $group_membership->getRoles();

      // No roles found, nothing to do here.
      if (empty($roles)) {
        continue;
      }

      $this->processedGroupTypes[$group_type_id] = $group_type_id;

      $group_ids = $this->hierarchyManager->getGroupSupergroupIds($group_id) + $this->hierarchyManager->getGroupSubgroupIds($group_id);

      $group_mapping = $this->getInheritedGroupRoleIds($account_id, $group_id, $group_type_id, $group_ids, $roles);
      $this->addInheritedPermissions($calculated_permissions, $group_mapping);
    }

    // Add cache tags according to invalidate the cache when the subgroups hierarchy changes.
    foreach ($this->processedGroupTypes as $group_type_id) {
      $calculated_permissions->addCacheTags($this->groupRoleInheritanceManager->getGroupTypeCacheTags($group_type_id));
    }

    return $calculated_permissions;
  }

  public function calculateNonMemberPermissions($calculated_permissions, $account, $is_member = false) {
    // Anonymous user doesn't have id, but we want to cache it.
    $account_id = $account->isAnonymous() ? 0 : $account->id();
    $calculated_permissions->addCacheContexts(['user']);

    $groups = $this->loadGroups();
    $group_ids = array_keys($groups);

    $this->processedGroupTypes = [];

    foreach ($groups as $group_id => $group_type_id) {
      if ($account->isAnonymous() ) {
        $roles = $this->groupRoleInheritanceManager->getAnonymousRoles($group_type_id);
      }
      else {
        $roles = $this->groupRoleInheritanceManager->getOutsiderRoles($group_type_id);
      }

      // No roles found, nothing to do here.
      if (empty($roles)) {
        continue;
      }

      $this->processedGroupTypes[$group_type_id] = $group_type_id;

      $group_mapping = $this->getInheritedGroupRoleIds($account_id, $group_id, $group_type_id, $group_ids, $roles);
      $this->addInheritedPermissions($calculated_permissions, $group_mapping);
    }

    // Add cache tags according to invalidate the cache when the subgroups hierarchy changes.
    foreach ($this->processedGroupTypes as $group_type_id) {
      $calculated_permissions->addCacheTags($this->groupRoleInheritanceManager->getGroupTypeCacheTags($group_type_id));
    }

    return $calculated_permissions;
  }

  public function addInheritedPermissions($calculated_permissions, $group_mapping) {
    foreach ($group_mapping as $mapped_group_id => $group_roles) {
      if (count($group_roles) == 0) {
        continue;
      }
      $is_admin = FALSE;
      $permission_sets = [];
      foreach ($group_roles as $group_role) {
        $permission_sets[] = $group_role->getPermissions();
        $calculated_permissions->addCacheableDependency($group_role);
        if (!$is_admin && $group_role->isAdmin()) {
          $is_admin = TRUE;
        }
      }
      $permissions = $permission_sets ? array_merge(...$permission_sets) : [];

      $calculated_permissions->addItem(new CalculatedPermissionsItem(
        PermissionScopeInterface::INDIVIDUAL_ID,
        $mapped_group_id,
        $permissions,
        $is_admin
      ));
    }

    return $calculated_permissions;
  }

  /**
   * Getter for mapped roles.
   *
   * @param string $account_id
   *   Account id.
   * @param string|null $group_id
   *   Group id.
   *
   * @return array
   *   Mapped roles, defaults to empty array.
   */
  public function getMappedRoles($account_id, $group_id = NULL) {
    if (!empty($group_id)) {
      return $this->mappedRoles[$account_id][$group_id] ?? [];
    }
    return $this->mappedRoles[$account_id] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getInheritedGroupRoleIds($account_id, $group_id, $group_type_id, array $groups, $roles = []) {
    $role_mapping = [];

    $role_map = $this->groupRoleInheritanceManager->getAllInheritedGroupRoleIds($group_id, $group_type_id);
    $mapped_role_ids = [[]];
    foreach ($groups as $group_item_gid) {
      if (!empty($role_map[$group_id][$group_item_gid])) {
        $role_mapping = array_intersect_key($role_map[$group_id][$group_item_gid], $roles);
      }

      $mapped_role_ids[] = $role_mapping;
    }

    $mapped_role_ids = array_replace_recursive(...$mapped_role_ids);

    $this->mappedRoles[$account_id][$group_id] = $this->groupRoleInheritanceManager->getRoles(array_unique($mapped_role_ids));

    return $this->getMappedRoles($account_id);
  }

  protected function loadGroups() {
    if (empty($this->groups)) {
      $this->groups = $this->database->select('groups', 'gr')
        ->fields('gr', ['id', 'type'])
        ->execute()
        ->fetchAllKeyed();
    }

    return $this->groups;
  }

  protected function loadMembershipGroups($account_id) {
    if (empty($this->membershipGroups)) {
      $this->membershipGroups = $this->database->select('group_relationship_field_data', 'gr')
        ->fields('gr', ['gid', 'group_type'])
        ->condition('entity_id', $account_id)
        ->condition('plugin_id', 'group_membership')
        ->execute()
        ->fetchAllKeyed();
    }

    return $this->membershipGroups;
  }

}
