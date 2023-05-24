<?php

namespace Drupal\ggroup_role_mapper;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ggroup\Graph\GroupGraphStorageInterface;
use Drupal\ggroup\Plugin\Group\Relation\Subgroup;
use Drupal\group\Entity\GroupTypeInterface;

/**
 * Provides all direct and indirect group relations and the inherited roles.
 */
class GroupRoleInheritance implements GroupRoleInheritanceInterface {

  /**
   * The group graph storage.
   *
   * @var \Drupal\ggroup\Graph\GroupGraphStorageInterface
   */
  protected $groupGraphStorage;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Static cache for the total role map.
   *
   * @var array[]
   */
  protected $roleMap = [];

  /**
   * Static cache for config of all installed subgroups.
   *
   * @var array[]
   */
  protected $subgroupConfig = [];

  /**
   * Static cache for group types.
   *
   * @var array
   */
  protected $groupTypes = [];

  /**
   * Static cache for all outsider roles of group type.
   *
   * A nested array with all outsider roles keyed by group type ID and role ID.
   *
   * @var array
   */
  protected $groupTypeRoles = [];

  /**
   * Static cache for all outsider roles of group type.
   *
   * @var array
   */
  protected $groupTypeOutsiderRoles = [];

  /**
   * Static cache for all anonymous roles of group type.
   *
   * @var array
   */
  protected $groupTypeAnonymousRoles = [];

  /**
   * Static cache for all member roles of group type.
   *
   * @var array
   */
  protected $groupTypeMemberRoles = [];

  /**
   * Static cache tags for group type.
   *
   * @var array
   */
  protected $groupTypeCacheTags = [];

  /**
   * Static cache of all group relationship types for subgroup group
   * relationship.
   *
   * This nested array is keyed by subgroup ID and group ID.
   *
   * @var string[][]
   */
  protected $subgroupRelations = [];

  /**
   * Group relation type storage.
   *
   * @var \Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface
   */
  protected $groupRelationshipTypeStorage = NULL;

  /**
   * Group type storage.
   *
   * @var \Drupal\group\Entity\Storage\GroupTypeStorageInterface
   */
  protected $groupTypeStorage = NULL;

  /**
   * Group role storage.
   *
   * @var \Drupal\group\Entity\Storage\GroupRoleStorageInterface
   */
  protected $groupRoleStorage = NULL;

  /**
   * Constructs a new GroupHierarchyManager.
   *
   * @param \Drupal\ggroup\Graph\GroupGraphStorageInterface $group_graph_storage
   *   The group graph storage service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   */
  public function __construct(GroupGraphStorageInterface $group_graph_storage, EntityTypeManagerInterface $entity_type_manager, CacheBackendInterface $cache) {
    $this->groupGraphStorage = $group_graph_storage;
    $this->entityTypeManager = $entity_type_manager;
    $this->groupTypeStorage = $entity_type_manager->getStorage('group_type');
    $this->groupRoleStorage = $entity_type_manager->getStorage('group_role');
    $this->groupRelationshipTypeStorage = $entity_type_manager->getStorage('group_relationship_type');
    $this->cache = $cache;

    // Preload group types.
    $this->getGroupTypes();
  }

  /**
   * {@inheritdoc}
   */
  public function buildGroupRolesMap($group_id, $group_type_id) {
    if (!empty($this->roleMap[$group_id])) {
      return $this->roleMap[$group_id];
    }

    $cid = GroupRoleInheritanceInterface::ROLE_MAP_CID . ':' . $group_id;

    $cache = $this->cache->get($cid);
    if ($cache && $cache->valid) {
      $this->roleMap[$group_id] = $cache->data;
      return $this->roleMap[$group_id];
    }

    $this->roleMap[$group_id] = $this->build($group_id);

    $cache_tags = $this->getGroupTypeCacheTags($group_type_id);
    $cache_tags[] = "group:$group_id";

    $this->cache->set($cid, $this->roleMap[$group_id], Cache::PERMANENT, $cache_tags);

    return $this->roleMap[$group_id];
  }

  /**
   * Build a nested array with all inherited roles for all group relations.
   *
   * @return array
   *   A nested array with all inherited roles for all direct/indirect group
   *   relations. The array is in the form of:
   *   $map[$group_a_id][$group_b_id][$group_b_role_id] = $group_a_role_id;
   */
  protected function build($gid) {
    $role_map = [];
    $group_relations = array_reverse($this->groupGraphStorage->getGraph($gid));

    foreach ($group_relations as $group_relation) {
      $group_id = $group_relation->start_vertex;
      $subgroup_id = $group_relation->end_vertex;
      $paths = $this->groupGraphStorage->getPath($group_id, $subgroup_id);

      foreach ($paths as $path) {
        $path_role_map = [];

        // Get all direct role mappings.
        foreach ($path as $key => $path_subgroup_id) {
          // We reached the end of the path, store mapped role IDs.
          if ($path_subgroup_id === $group_id) {
            break;
          }

          // Get the supergroup ID from the next element.
          $path_supergroup_id = $path[$key + 1] ?? NULL;

          if (!$path_supergroup_id) {
            continue;
          }

          // Get mapped roles for relation type. Filter array to remove
          // unmapped roles.
          if ($relation_config = $this->getSubgroupRelationConfig($path_supergroup_id, $path_subgroup_id)) {
            $path_role_map[$path_supergroup_id][$path_subgroup_id] = array_filter($relation_config['child_role_mapping']);
            $path_role_map[$path_subgroup_id][$path_supergroup_id] = array_filter($relation_config['parent_role_mapping']);
          }
        }
        $role_map[] = $path_role_map;

        // Add all indirectly inherited subgroup roles (bottom up).
        $role_map[] = $this->mapIndirectPathRoles($path, $path_role_map);

        // Add all indirectly inherited group roles between groups.
        $role_map[] = $this->mapIndirectPathRoles(array_reverse($path), $path_role_map);
      }
    }

    return !empty($role_map) ? array_replace_recursive(...$role_map) : [];
  }

  /**
   * Map all the indirectly inherited roles in a path between group A and B.
   *
   * Within a graph, getting the role inheritance for every direct relation is
   * relatively easy and cheap. There are also a lot of indirectly inherited
   * roles in a path between 2 groups though. When there is a relation between
   * groups like '1 => 20 => 300 => 4000', this method calculates the role
   * inheritance for every indirect relationship in the path:
   * 1 => 300
   * 1 => 4000
   * 20 => 4000
   *
   * @param array $path
   *   An array containing all group IDs in a path between group A and B.
   * @param array $path_role_map
   *   A nested array containing all directly inherited roles for the path
   *   between group A and B.
   *
   * @return array
   *   A nested array with all indirectly inherited roles for a path between 2
   *   groups. The array is in the form of:
   *   $map[$group_a_id][$group_b_id][$group_b_role_id] = $group_a_role_id;
   */
  protected function mapIndirectPathRoles(array $path, array $path_role_map) {
    $indirect_role_map = [];
    foreach ($path as $from_group_key => $path_from_group_id) {
      $inherited_roles_map = [];
      foreach ($path as $to_group_key => $path_to_group_id) {
        if ($to_group_key <= $from_group_key) {
          continue;
        }

        // Get the previous group ID from the previous element.
        $path_direct_to_group_id = isset($path[$to_group_key - 1]) ? $path[$to_group_key - 1] : NULL;

        if (!$path_direct_to_group_id) {
          continue;
        }

        $direct_role_map = isset($path_role_map[$path_to_group_id][$path_direct_to_group_id]) ? $path_role_map[$path_to_group_id][$path_direct_to_group_id] : NULL;

        if (empty($inherited_roles_map) && isset($direct_role_map)) {
          $inherited_roles_map = $direct_role_map;
        }

        foreach ($inherited_roles_map as $from_group_role_id => $to_group_role_id) {
          if (isset($direct_role_map[$to_group_role_id])) {
            $indirect_role_map[$path_to_group_id][$path_from_group_id][$from_group_role_id] = $direct_role_map[$to_group_role_id];
            $inherited_roles_map[$from_group_role_id] = $direct_role_map[$to_group_role_id];
          }
        }
      }
    }
    return $indirect_role_map;
  }

  /**
   * Get the config for all installed subgroup relations.
   *
   * @return array[]
   *   A nested array with configuration values keyed by subgroup relation ID.
   */
  protected function getSubgroupRelationsConfig() {
    // We create a static cache with the configuration for all subgroup
    // relations since having separate queries for every relation has a big
    // impact on performance.
    if (!$this->subgroupConfig) {
      $group_types = $this->getGroupTypes();
      foreach ($group_types as $group_type) {
        $plugin_id = 'ggroup:' . $group_type->id();
        $subgroup_relationship_types = $this->groupRelationshipTypeStorage->loadByPluginId($plugin_id);
        foreach ($subgroup_relationship_types as $subgroup_relationship_type) {
          /** @var \Drupal\group\Entity\GroupRelationshipTypeInterface $subgroup_relationship_type */
          $this->subgroupConfig[$subgroup_relationship_type->id()] = $subgroup_relationship_type->getPlugin()
            ->getConfiguration();
        }
      }
    }
    return $this->subgroupConfig;
  }

  /**
   * Get the config for a relation between a group and a subgroup.
   *
   * @param int $group_id
   *   The group for which to get the configuration.
   * @param int $subgroup_id
   *   The subgroup for which to get the configuration.
   *
   * @return array[]
   *   A nested array with configuration values.
   */
  protected function getSubgroupRelationConfig($group_id, $subgroup_id) {
    $subgroup_relations_config = $this->getSubgroupRelationsConfig();

    // We need the type of each relation to fetch the configuration. We create
    // a static cache for the types of all subgroup relations since fetching
    // each relation independently has a big impact on performance.
    if (!$this->subgroupRelations || empty($this->subgroupRelations[$group_id])) {
      // Get all type between the supergroup and subgroup.
      $group_relationships = $this->entityTypeManager->getStorage('group_relationship')
        ->loadByProperties([
          'type' => array_keys($subgroup_relations_config),
          'gid' => [$group_id],
        ]);
      foreach ($group_relationships as $group_relationship) {
        $this->subgroupRelations[$group_relationship->gid->target_id][$group_relationship->entity_id->target_id] = $group_relationship->bundle();
      }
    }

    $type = isset($this->subgroupRelations[$group_id][$subgroup_id]) ? $this->subgroupRelations[$group_id][$subgroup_id] : NULL;
    return isset($subgroup_relations_config[$type]) ? $subgroup_relations_config[$type] : NULL;
  }

  /**
   * Get outsider roles by group type id.
   *
   * @param string $group_type_id
   *   Group type id.
   *
   * @return array
   *   Get outsider roles.
   */
  public function getOutsiderRoles($group_type_id) {
    return $this->groupTypeOutsiderRoles[$group_type_id] ?? [];
  }

  /**
   * Get anonymous roles by group type id.
   *
   * @param string $group_type_id
   *   Group type id.
   *
   * @return array
   *   Get anonymous roles.
   */
  public function getAnonymousRoles($group_type_id) {
    return $this->groupTypeAnonymousRoles[$group_type_id] ?? [];
  }

  /**
   * Get group type by id
   *
   * @param string $group_type_id
   *   Group type id.
   *
   * @return \Drupal\group\Entity\GroupTypeInterface
   *   Group type.
   */
  public function getGroupType($group_type_id) {
    if (isset($this->groupTypes[$group_type_id])) {
      $group_type = $this->groupTypeStorage->load($group_type_id);
      $this->groupTypes[$group_type_id] = $group_type;

      $this->getGroupTypeRoles($group_type);
      $this->addGroupTypeTags($group_type);
    }

    return $this->groupTypes[$group_type_id];
  }

  /**
   * Get all group types in the system.
   *
   * @return array
   *   All group types.
   */
  protected function getGroupTypes() {
    if (!empty($this->groupTypes)) {
      return $this->groupTypes;
    }

    $group_types = $this->groupTypeStorage->loadMultiple();
    foreach ($group_types as $group_type) {
      $this->groupTypes[$group_type->id()] = $group_type;
      $this->getGroupTypeRoles($group_type);
      $this->addGroupTypeTags($group_type);
    }

    return $this->groupTypes;

  }

  /**
   * Get group type tags.
   *
   * @param string $group_type_id
   *   Group type id.
   *
   * @return array
   *   List of tags.
   */
  public function getGroupTypeCacheTags($group_type_id) {
    return $this->groupTypeCacheTags[$group_type_id] ?? [];
  }

  /**
   * Add group type tags.
   *
   * @param \Drupal\group\Entity\GroupTypeInterface $group_type
   *   Group type.
   */
  protected function addGroupTypeTags(GroupTypeInterface $group_type) {
    $cache_tags = [];
    $group_type_id = $group_type->id();
    $plugins = $group_type->getInstalledPlugins();
    foreach ($plugins as $plugin) {
      if ($plugin instanceof Subgroup) {
        $relation_type_id = $this->groupRelationshipTypeStorage->getRelationshipTypeId($group_type_id, $plugin->getPluginId());
        $cache_tags[] = "config:group.relationship_type.$relation_type_id";
        $cache_tags[] = "group_relationship_list:$relation_type_id";
      }
    }

    $this->groupTypeCacheTags[$group_type_id] = $cache_tags;
  }

  /**
   * Get group type roles.
   *
   * @param \Drupal\group\Entity\GroupTypeInterface $group_type
   *   Group type.
   */
  protected function getGroupTypeRoles(GroupTypeInterface $group_type) {
    $group_type_id = $group_type->id();
    // We store staticly all roles, because we need them anyway.
    foreach ($group_type->getRoles() as $id => $role) {
      $this->groupTypeRoles[$id] = $role;
      if ($role->isAnonymous()) {
        $this->groupTypeAnonymousRoles[$group_type_id][$id] = $role;
      }

      if ($role->isOutsider()) {
        $this->groupTypeOutsiderRoles[$group_type_id][$id] = $role;
      }

      if ($role->isMember()) {
        $this->groupTypeMemberRoles[$group_type_id][$id] = $role;
      }
    }
  }

  /**
   * Get roels by ids.
   *
   * @param array $roles_id
   *   Roels ids.
   *
   * @return array
   *   Group roles.
   */
  public function getRoles(array $roles_id = []) {
    $roles = [];
    foreach ($roles_id as $role_id) {
      if (!isset($this->groupTypeRoles[$role_id])) {
        $this->groupTypeRoles[$role_id] = $this->groupRoleStorage->load($role_id);
      }

      $roles[$role_id] = $this->groupTypeRoles[$role_id];

    }
    return $roles;
  }

}
