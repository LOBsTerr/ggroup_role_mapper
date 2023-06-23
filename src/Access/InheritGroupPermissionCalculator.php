<?php

namespace Drupal\ggroup_role_mapper\Access;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\flexible_permissions\CalculatedPermissionsItem;
use Drupal\flexible_permissions\PermissionCalculatorBase;
use Drupal\ggroup\GroupHierarchyManager;
use Drupal\group\Access\CalculatedGroupPermissionsItem;
use Drupal\group\Access\CalculatedGroupPermissionsItemInterface;
use Drupal\ggroup_role_mapper\GroupRoleInheritanceInterface;
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
   * Static cache for all inherited group roles by user.
   *
   * A nested array with all inherited roles keyed by user ID and group ID.
   *
   * @var array
   */
  protected $mappedRoles = [];

  /**
   * Processed group types.
   *
   * @var array
   */
  protected $processedGroupTypes = [];

  /**
   * Account id.
   *
   * @var int
   */
  protected $accountId = 0;

  /**
   * Account.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account = NULL;

  /**
   * Calculated permissions.
   *
   * @var \Drupal\flexible_permissions\CalculatedPermissionsInterface
   */
  protected $calculatedPermissions;

  /**
   * Constructs a InheritGroupPermissionCalculator object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ggroup\GroupHierarchyManager $hierarchy_manager
   *   The group hierarchy manager.
   * @param \Drupal\ggroup_role_mapper\GroupRoleInheritanceInterface $group_role_inheritance_manager
   *   The group membership loader.
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    GroupHierarchyManager $hierarchy_manager,
    GroupRoleInheritanceInterface $group_role_inheritance_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->hierarchyManager = $hierarchy_manager;
    $this->groupRoleInheritanceManager = $group_role_inheritance_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function calculatePermissions(AccountInterface $account, $scope) {
    $this->calculatedPermissions = parent::calculatePermissions($account, $scope);

    // Anonymous user doesn't have id, but we want to cache it.
    $this->account = $account;
    $this->accountId = $account->isAnonymous() ? 0 : $account->id();
    if ($scope == PermissionScopeInterface::INDIVIDUAL_ID) {
      // We will calculate all permisions as individual roles, so we can
      // assign them to specific group, in other case they will be assigned to
      // all group of specific group type.
      $this->calculateMemberPermissions();
      $this->calculateNonMemberPermissions();
    }

    return $this->calculatedPermissions;
  }

  /**
   * Calculate permissions for members.
   */
  public function calculateMemberPermissions() {
    // The member permissions need to be recalculated whenever the user is added
    // to or removed from a group.
    $this->calculatedPermissions->addCacheTags(['group_relationship_list:plugin:group_membership:entity:' . $this->accountId]);
    $this->calculatedPermissions->addCacheContexts(['user']);

    $user = $this->entityTypeManager->getStorage('user')->load($this->accountId);
    $this->calculatedPermissions->addCacheableDependency($user);

    // Get all memberships for the current user.
    $groups_with_memberships = array_keys($this->groupRoleInheritanceManager->getMemberships($user));

    $this->processedGroupTypes = [];
    foreach ($groups_with_memberships as $group_id) {
      $this->addGroupTypeTags($this->groupRoleInheritanceManager->getGroupTypeId($group_id));

      $this->getGroupMemberInheritedRoles($group_id);
      $this->addInheritedPermissions();
    }
  }

  /**
   * Add group types tags.
   */
  public function addGroupTypeTags($group_type_id) {
    // Add cache tags according to invalidate the cache when the subgroups hierarchy changes.
    if (empty($this->processedGroupTypes[$group_type_id])) {
      $this->calculatedPermissions->addCacheTags($this->groupRoleInheritanceManager->getGroupTypeCacheTags());
      $this->processedGroupTypes[$group_type_id] = $group_type_id;
    }
  }

  /**
   * Calculate permissions for non-members.
   */
  public function calculateNonMemberPermissions() {
    $this->calculatedPermissions->addCacheContexts(['user']);

    // We need to run through all groups for outsiders and anonymous.
    $groups = $this->groupRoleInheritanceManager->loadGroups();

    // Reset processed group types cache.
    $this->processedGroupTypes = [];

    foreach ($groups as $group_id => $group_type_id) {
      $this->addGroupTypeTags($group_type_id);

      $this->getGroupNonMembersInheritedRoles($group_id);
      $this->addInheritedPermissions();
    }
  }

  /**
   * Add inherited permissions.
   */
  public function addInheritedPermissions() {
    foreach ($this->getMappedRoles() as $mapped_group_id => $group_roles) {
      if (count($group_roles) == 0) {
        continue;
      }

      $is_admin = FALSE;
      $permission_sets = [];
      foreach ($group_roles as $group_role) {
        $permission_sets[] = $group_role->getPermissions();
        $this->calculatedPermissions->addCacheableDependency($group_role);
        // if the mapped roles is admin we pass with permissions.
        if (!$is_admin && $group_role->isAdmin()) {
          $is_admin = TRUE;
        }
      }

      $permissions = $permission_sets ? array_merge(...$permission_sets) : [];
      if ($is_admin || !empty($permissions)) {
        $this->calculatedPermissions->addCacheTags(["group:$mapped_group_id"]);
        $this->calculatedPermissions->addItem(new CalculatedPermissionsItem(
          PermissionScopeInterface::INDIVIDUAL_ID,
          $mapped_group_id,
          $permissions,
          $is_admin
        ));
      }
    }
  }

  /**
   * Getter for mapped roles.
   *
   * @param string|null $group_id
   *   Group id.
   *
   * @return array
   *   Mapped roles, defaults to empty array.
   */
  public function getMappedRoles() {
    return $this->mappedRoles[$this->accountId] ?? [];
  }

  /**
   * Add role mappings.
   *
   * @param $group_id
   *   Group id.
   * @param $mapped_roles
   *   Array of roles mapped to group.
   */
  public function calculateMappedRoles($group_id, $mapped_group_id, $role_map, $direct_mapping = TRUE) {

    if (!$direct_mapping) {
      // Reverse group id, if it is reverse mapping.
      $group_temp_id = $group_id;
      $group_id = $mapped_group_id;
      $mapped_group_id = $group_temp_id;
    }

    // Get group types config first to avoid any other calculations.
    $intermediary_group = $this->groupRoleInheritanceManager->getIntermediaryGroup($group_id, $mapped_group_id);

    // There is intermediary between group types, because we don't have direct connection we need to pass through all
    if (!empty($intermediary_group)) {
      $group_type_config = $this->groupRoleInheritanceManager->getPluginConfig($mapped_group_id, $intermediary_group);
    }
    else {
      if ($direct_mapping) {
        $group_type_config = $this->groupRoleInheritanceManager->getPluginConfig($mapped_group_id, $group_id);
      }
      else {
        $group_type_config = $this->groupRoleInheritanceManager->getPluginConfig($group_id, $mapped_group_id);
      }
    }

    if (empty($group_type_config)) {
      return;
    }

    $mapped_roles = $role_map[$group_id][$mapped_group_id] ?? [];
    if (empty($mapped_roles)) {
      return;
    }

    $user_mapped_group_roles = $this->groupRoleInheritanceManager->getUserGroupRoles($this->account, $mapped_group_id);
    if (empty($user_mapped_group_roles)) {
      return;
    }

    $role_mapping = [];

    $user_group_roles = $this->groupRoleInheritanceManager->getUserGroupRoles($this->account, $group_id);

    foreach ($mapped_roles as $mapped_role => $target_role) {
      // User doesn't have this role in the group we are mapping.
      if (!in_array($mapped_role, $user_mapped_group_roles)) {
        continue;
      }

      // We have connection between group types, but not config for direct
      // connection.
      if ($direct_mapping && empty($group_type_config['parent_role_mapping'][$mapped_role])) {
        continue;
      }

      // We have connection between group types, but not config for reverse
      // connection.
      if (!$direct_mapping && empty($group_type_config['child_role_mapping'][$mapped_role])) {
        continue;
      }

      // Get allowed roles from config.
      if ($direct_mapping) {
        $allowed_roles = $group_type_config['parent_target_roles'][$mapped_role] ?? [];
      }
      else {
        $allowed_roles = $group_type_config['child_target_roles'][$mapped_role] ?? [];
      }

      // If there is no allowed roles, everything is allowed.
      if (!empty($allowed_roles)) {
        $user_allowed_roles = array_intersect($allowed_roles, $user_group_roles);
        if (empty($user_allowed_roles)) {
          // Remove role from mapped roles, because user does not have
          // any of the allowed roles.
          continue;
        }
      }

      $role_mapping[] = $target_role;
    }

    $this->addMappedRoles($group_id, $role_mapping);
  }

  /**
   * Add role mappings.
   *
   * @param $group_id
   *   Group id.
   * @param $mapped_roles
   *   Array of roles mapped to group.
   */
  public function addMappedRoles($group_id, $role_mapping) {
    if (empty($role_mapping)) {
      return;
    }

    if (isset($this->mappedRoles[$this->accountId][$group_id])) {
      $this->mappedRoles[$this->accountId][$group_id] = array_merge($this->mappedRoles[$this->accountId][$group_id], $this->groupRoleInheritanceManager->getRoles($role_mapping));
    }
    else {
      $this->mappedRoles[$this->accountId][$group_id] = $this->groupRoleInheritanceManager->getRoles($role_mapping);
    }
  }

  /**
   * Get group inherited roles.
   *
   * @param int $group_id
   *   Group id.
   * @param array $roles
   *   Array of roles.
   */
  public function getGroupNonMembersInheritedRoles($group_id) {
    // Preload current group role map.
    $role_map = $this->groupRoleInheritanceManager->buildGroupRolesMap($group_id);

    foreach ($role_map as $mapped_group_id => $group_mappings) {
      // Skip current group.
      if ($mapped_group_id == $group_id) {
        continue;
      }

      // We check mapping from parent to children.
      $this->calculateMappedRoles($group_id, $mapped_group_id, $role_map);

      // From children to parent
      $this->calculateMappedRoles($group_id, $mapped_group_id, $role_map, FALSE);
    }
  }

  /**
   * Get group inherited roles.
   *
   * @param int $group_id
   *   Group id.
   * @param array $roles
   *   Array of roles.
   */
  public function getGroupMemberInheritedRoles($group_id) {
    $role_map = $this->groupRoleInheritanceManager->buildGroupRolesMap($group_id);

    // Check supergroups.
    $super_subgroup_ids = $this->hierarchyManager->getGroupSupergroupIds($group_id);
    foreach ($super_subgroup_ids as $super_subgroup_id) {
      $this->calculateMappedRoles($group_id, $super_subgroup_id, $role_map);
      $this->calculateMappedRoles($group_id, $super_subgroup_id, $role_map, FALSE);
    }

    // Check subgroup.
    $subgroup_ids = $this->hierarchyManager->getGroupSubgroupIds($group_id);
    foreach ($subgroup_ids as $subgroup_id) {
      $this->calculateMappedRoles($subgroup_id, $group_id, $role_map);
      $this->calculateMappedRoles($subgroup_id, $group_id, $role_map, FALSE);
    }
  }

}
