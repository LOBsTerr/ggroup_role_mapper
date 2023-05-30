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
    GroupRoleInheritanceInterface $group_role_inheritance_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->hierarchyManager = $hierarchy_manager;
    $this->membershipLoader = $membership_loader;
    $this->groupRoleInheritanceManager = $group_role_inheritance_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function calculatePermissions(AccountInterface $account, $scope) {
    $this->calculatedPermissions = parent::calculatePermissions($account, $scope);

    // Anonymous user doesn't have id, but we want to cache it.
    $this->accountId = $account->isAnonymous() ? 0 : $account->id();
    if ($scope == PermissionScopeInterface::INDIVIDUAL_ID) {
      $this->calculateMemberPermissions();
    }

    if ($scope == PermissionScopeInterface::INSIDER_ID || $scope == PermissionScopeInterface::OUTSIDER_ID) {
      $this->calculateNonMemberPermissions($account);
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
    $group_memberships = $this->membershipLoader->loadByUser($user);

    $this->processedGroupTypes = [];
    foreach ($group_memberships as $group_membership) {
      $group_id = $group_membership->getGroup()->id();
      $this->addGroupTypeTags($group_membership->getGroup()->getGroupType()->id());

      $roles = $group_membership->getRoles();

      if (empty($roles)) {
        continue;
      }

      $this->getGroupMemberInheritedRoles($group_id, $roles);
      $this->addInheritedPermissions();
    }
  }

  /**
   * Add group types tags.
   */
  public function addGroupTypeTags($group_type_id) {
    // Add cache tags according to invalidate the cache when the subgroups hierarchy changes.
    if (empty($this->processedGroupTypes[$group_type_id])) {
      $this->calculatedPermissions->addCacheTags($this->groupRoleInheritanceManager->getGroupTypeCacheTags($group_type_id));
      $this->processedGroupTypes[$group_type_id] = $group_type_id;
    }
  }

  /**
   * Calculate permissions for non members.
   */
  public function calculateNonMemberPermissions($account) {
    $this->calculatedPermissions->addCacheContexts(['user']);

    // We need to run through all groups for outsiders and anonymous.
    $groups = $this->groupRoleInheritanceManager->loadGroups();

    // Reset processed group types cache.
    $this->processedGroupTypes = [];

    foreach ($groups as $group_id => $group_type_id) {
      $this->addGroupTypeTags($group_type_id);
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

      $this->getGroupNonMembersInheritedRoles($group_id, $roles);
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
      if (!empty($permissions)) {
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
   * @param $role_mapping
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
   *   Group od.
   * @param array $roles
   *   Array of roles.
   */
  public function getGroupNonMembersInheritedRoles($group_id, $roles = []) {
    if (empty($roles)) {
      return;
    }

    // Preload current group role map.
    $role_map = $this->groupRoleInheritanceManager->buildGroupRolesMap($group_id);

    foreach ($role_map as $mapped_group_id => $group_mappings) {
      foreach ($group_mappings as $group_mapping) {
        $role_mapping = array_intersect_key($group_mapping, $roles);
        $this->addMappedRoles($mapped_group_id, $role_mapping);
      }
    }
  }

  /**
   * Get group inherited roles.
   *
   * @param int $group_id
   *   Group od.
   * @param array $roles
   *   Array of roles.
   */
  public function getGroupMemberInheritedRoles($group_id, $roles = []) {
    if (empty($roles)) {
      return;
    }

    $role_map = $this->groupRoleInheritanceManager->buildGroupRolesMap($group_id);

    // When we check super groups for member roles we want to be sure that we
    // get role mapping for groups which using mapping
    // "Map subgroup roles to group roles".
    $super_subgroup_ids = $this->hierarchyManager->getGroupSupergroupIds($group_id);

    foreach ($super_subgroup_ids as $super_subgroup_id) {
      if (empty($role_map[$super_subgroup_id])) {
        continue;
      }

      $super_group_super_group_ids = $this->hierarchyManager->getGroupSupergroupIds($super_subgroup_id);
      foreach ($role_map[$super_subgroup_id] as $mapped_group_id => $group_mappings) {
        if (!in_array($mapped_group_id, $super_group_super_group_ids)) {
          $role_mapping = array_intersect_key($group_mappings, $roles);
          $this->addMappedRoles($super_subgroup_id, $role_mapping);
        }
      }
    }

    // When we check subgroups for member roles we want to check if any groups
    // provide role mapping.
    $subgroup_ids = $this->hierarchyManager->getGroupSubgroupIds($group_id);

    foreach ($subgroup_ids as $subgroup_id) {
      if (empty($role_map[$subgroup_id] )) {
        continue;
      }

      foreach ($role_map[$subgroup_id] as $group_mappings) {
        $role_mapping = array_intersect_key($group_mappings, $roles);
        $this->addMappedRoles($subgroup_id, $role_mapping);
      }
    }
  }

}
