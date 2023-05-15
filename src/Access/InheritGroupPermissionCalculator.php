<?php

namespace Drupal\ggroup_role_mapper\Access;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\ggroup\GroupHierarchyManager;
use Drupal\ggroup\Plugin\Group\Relation\Subgroup;
use Drupal\group\Access\GroupPermissionCalculatorBase;
use Drupal\group\Access\RefinableCalculatedGroupPermissions;
use Drupal\group\Access\CalculatedGroupPermissionsItem;
use Drupal\group\Access\CalculatedGroupPermissionsItemInterface;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\GroupMembershipLoader;
use Drupal\ggroup_role_mapper\GroupRoleInheritanceInterface;

/**
 * Calculates group permissions for an account.
 */
class InheritGroupPermissionCalculator extends GroupPermissionCalculatorBase {

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
   * The group membership loader.
   *
   * @var \Drupal\group\GroupMembershipLoader
   */
  protected $membershipLoader;

  /**
   * The group role inheritance manager.
   *
   * @var \Drupal\ggroup_role_mapper\GroupRoleInheritanceInterface
   */
  protected $groupRoleInheritanceManager;

  /**
   * Static cache for all group memberships per user.
   *
   * A nested array with all group memberships keyed by user ID.
   *
   * @var \Drupal\group\GroupMembership[][]
   */
  protected $userMemberships = [];

  /**
   * Static cache for all inherited group roles by user.
   *
   * A nested array with all inherited roles keyed by user ID and group ID.
   *
   * @var array
   */
  protected $mappedRoles = [];

  /**
   * Static cache for all outsider roles of group type.
   *
   * A nested array with all outsider roles keyed by group type ID and role ID.
   *
   * @var array
   */
  protected $groupTypeOutsiderRoles = [];

  /**
   * Group role storage.
   *
   * @var \Drupal\group\Entity\Storage\GroupRoleStorageInterface
   */
  protected $groupRoleStorage = NULL;

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
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, GroupHierarchyManager $hierarchy_manager, GroupMembershipLoader $membership_loader, GroupRoleInheritanceInterface $group_role_inheritance_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->hierarchyManager = $hierarchy_manager;
    $this->membershipLoader = $membership_loader;
    $this->groupRoleInheritanceManager = $group_role_inheritance_manager;
    $this->groupRoleStorage = $this->entityTypeManager->getStorage('group_role');
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
   * Checker for mapped roles.
   *
   * @param string $account_id
   *   Account id.
   * @param string|null $group_id
   *   Group id.
   *
   * @return bool
   *   TRUE if there are mapped roles
   *   for given account id (optionally group id).
   */
  public function hasMappedRoles($account_id, $group_id = NULL) {
    return !empty($this->getMappedRoles($account_id, $group_id));
  }

  /**
   * Get all (inherited) group roles a user account inherits for a group.
   *
   * Check if the account is a direct member of any subgroups/supergroups of
   * the group. For each subgroup/supergroup, we check which roles we are
   * allowed to map. The result contains a list of all roles the user has have
   * inherited from 1 or more subgroups or supergroups.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   An account to map only the roles for a specific user.
   *
   * @return \Drupal\group\Entity\GroupRoleInterface[]
   *   An array of group roles inherited for the given group.
   */
  public function calculateMemberPermissions(AccountInterface $account) {
    $calculated_permissions = new RefinableCalculatedGroupPermissions();
    // The member permissions need to be recalculated whenever the user is added
    // to or removed from a group.
    $calculated_permissions->addCacheTags(['group_relationship_list:plugin:group_membership:entity:' . $account->id()]);
    $calculated_permissions->addCacheContexts(['user']);

    $calculated_permissions->addCacheableDependency($this->entityTypeManager->getStorage('user')->load($account->id()));

    $group_types_processed = [];
    $group_type_cache_tags = [];
    foreach ($this->loadMembership($account) as $group_membership) {
      $group = $group_membership->getGroup();
      $group_type = $group->getGroupType();
      // Flag the already processed group types, so we don't process them twice.
      if (!isset($group_types_processed[$group_type->id()])) {
        $group_types_processed[$group_type->id()] = TRUE;
        $relationship_plugins = $group_type->getInstalledPlugins();

        foreach ($relationship_plugins as $relationship_plugin) {
          if ($relationship_plugin instanceof Subgroup) {
            $group_type_cache_tags[] = 'group_relationship_list:' . $group_type->id() . '-subgroup-' . $relationship_plugin->getPluginDefinition()['entity_bundle'];
          }
        }
      }

      $group_mapping = $this->getInheritedGroupRoleIds($account);
      $permission_sets = [];
      if ($group_mapping) {
        $groups = Group::loadMultiple(array_keys($group_mapping));
      }
      foreach ($group_mapping as $group_id => $group_roles) {
        foreach ($group_roles as $group_role) {
          $permission_sets[] = $group_role->getPermissions();
          $calculated_permissions->addCacheableDependency($group_role);
        }
        $permissions = $permission_sets ? array_merge(...$permission_sets) : [];
        if ($group_roles) {
          $item = new CalculatedGroupPermissionsItem(
            CalculatedGroupPermissionsItemInterface::SCOPE_GROUP,
            (string) $group_id,
            $permissions
          );
          $calculated_permissions->addItem($item);
          $calculated_permissions->addCacheableDependency($groups[$group_id]);
        }
      }
    }
    // Add cache tags according to invalidate the cache when the subgroups hierarchy changes.
    $calculated_permissions->addCacheTags($group_type_cache_tags);

    return $calculated_permissions;
  }

  /**
   * Get the inherited roles for groups with subgroup or direct membership.
   *
   * @param Drupal\Core\Session\AccountInterface
   *
   * @return An array of group roles keyed by group ID.
   */
  public function getInheritedGroupRoleIds(AccountInterface $account) {
    $account_id = $account->id();
    if ($this->hasMappedRoles($account_id)) {
      return $this->getMappedRoles($account_id);
    }

    $mapped_role_ids = [[]];
    foreach ($this->loadMembership($account) as $membership) {
      $roles = array_keys($membership->getRoles());
      $membership_gid = $membership->getGroup()->id();

      $subgroup_ids = $this->hierarchyManager->getGroupSupergroupIds($membership_gid) + $this->hierarchyManager->getGroupSubgroupIds($membership_gid);

      $subgroups = Group::loadMultiple($subgroup_ids);

      foreach ($subgroup_ids as $subgroup_id) {
        $role_map = $this->groupRoleInheritanceManager->getAllInheritedGroupRoleIds($subgroups[$subgroup_id]);
        if (!empty($role_map[$subgroup_id][$membership_gid])) {
          $mapped_role_ids[$subgroup_id] = array_merge(isset($mapped_role_ids[$subgroup_id]) ? $mapped_role_ids[$subgroup_id] : [], array_intersect_key($role_map[$subgroup_id][$membership_gid], array_flip($roles)));
        }
      }
    }

    foreach ($mapped_role_ids as $mapped_group_id => $role_ids) {
      if (!empty(array_unique($role_ids))) {
        $this->mappedRoles[$account_id][$mapped_group_id] = array_merge($this->getMappedRoles($account_id, $mapped_group_id), $this->groupRoleStorage->loadMultiple(array_unique($role_ids)));
      }
    }

    return $this->getMappedRoles($account_id);
  }

  /**
   * {@inheritdoc}
   */
  public function getInheritedGroupOutsiderRoleIds(GroupInterface $group, AccountInterface $account) {

    $account_id = $account->id();
    $group_id = $group->id();

    if ($this->hasMappedRoles($account_id, $group_id)) {
      return $this->getMappedRoles($account_id, $group_id);
    }

    $role_map = $this->groupRoleInheritanceManager->getAllInheritedGroupRoleIds($group);

    $mapped_role_ids = [[]];
    foreach ($this->loadMembership($account) as $membership) {
      $membership_group = $membership->getGroupRelationship()->getGroup();
      $membership_group_id = $membership_group->id();
      $role_mapping = [];

      // Get all outsider roles.
      $outsider_roles = $this->getOutsiderGroupRoles($membership_group);
      if (!empty($role_map[$membership_group_id][$group_id])) {
        $role_mapping = array_intersect_key($role_map[$membership_group_id][$group_id], $outsider_roles);
      }
      else if (!empty($role_map[$group_id][$membership_group_id])) {
        $role_mapping = array_intersect_key($role_map[$group_id][$membership_group_id], $outsider_roles);
      }

      $mapped_role_ids[] = $role_mapping;
    }

    $mapped_role_ids = array_replace_recursive(...$mapped_role_ids);

    $this->mappedRoles[$account_id][$group_id] = $this->groupRoleStorage->loadMultiple(array_unique($mapped_role_ids));

    return $this->getMappedRoles($account_id, $group_id);
  }

  /**
   * Get outsider group type roles.
   *
   * @param Drupal\group\Entity\GroupInterface $group
   *   Group.
   * @return array
   *   Group type roles.
   */
  protected function getOutsiderGroupRoles(GroupInterface $group) {
    $group_type = $group->getGroupType();
    $group_type_id = $group_type->id();
    if (!isset($this->groupTypeOutsiderRoles[$group_type_id])) {
      $outsider_roles = $this->groupRoleStorage->loadSynchronizedByGroupTypes([$group_type_id]);
      // @todo rewrite getOutsiderRoleId and getOutsiderRole.
      $outsider_roles[$group_type->getOutsiderRoleId()] = $group_type->getOutsiderRole();
      $this->groupTypeOutsiderRoles[$group_type_id] = $outsider_roles;
    }
    return $this->groupTypeOutsiderRoles[$group_type_id];
  }

  /**
   * {@inheritdoc}
   */
  public function getInheritedGroupAnonymousRoleIds(GroupInterface $group, array $groups) {
    // Anonymous user doesn't have id, but we want to cache it.
    $account_id = 0;
    $group_id = $group->id();

    $role_map = $this->groupRoleInheritanceManager->getAllInheritedGroupRoleIds($group);
    $mapped_role_ids = [[]];
    foreach ($groups as $group_item) {
      $group_item_gid = $group_item->id();
      $role_mapping = [];
      // @todo replace getAnonymousRoleId.
      $group_item_anonymous_role_id = $group_item->getGroupType()->getAnonymousRoleId();
      $anonymous_role = [$group_item_anonymous_role_id => $group_item_anonymous_role_id];

      if (!empty($role_map[$group_item_gid][$group_id])) {
        $role_mapping = array_intersect_key($role_map[$group_item_gid][$group_id], $anonymous_role);
      }
      else if (!empty($role_map[$group_id][$group_item_gid])) {
        $role_mapping = array_intersect_key($role_map[$group_id][$group_item_gid], $anonymous_role);
      }

      $mapped_role_ids[] = $role_mapping;
    }

    $mapped_role_ids = array_replace_recursive(...$mapped_role_ids);

    $this->mappedRoles[$account_id][$group_id] = $this->groupRoleStorage->loadMultiple(array_unique($mapped_role_ids));

    return $this->getMappedRoles($account_id, $group_id);
  }

  /**
   * Load membership.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User account.
   *
   * @return array|\Drupal\group\GroupMembership[]
   *   List of memberships.
   */
  protected function loadMembership(AccountInterface $account) {
    // Statically cache the memberships of a user since this method could get
    // called a lot.
    $account_id = $account->id();
    if (!isset($this->userMemberships[$account_id])) {
      $this->userMemberships[$account_id] = $this->membershipLoader->loadByUser($account);
    }

    return $this->userMemberships[$account_id];
  }

}
