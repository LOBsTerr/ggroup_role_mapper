<?php

namespace Drupal\ggroup_role_mapper;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
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
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Static cache for the total role map.
   *
   * @var array[]
   */
  protected $roleMap = [];

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
   * Static cache tags for group type.
   *
   * @var array
   */
  protected $groupTypeCacheTags = [];

  /**
   * Static cache for group type plugin config.
   *
   * @var array
   */
  protected $groupTypePluginConfig = [];

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
   * Static cache for groups.
   *
   * @var array
   */
  protected $groups = [];

  /**
   * Constructs a new GroupHierarchyManager.
   *
   * @param \Drupal\ggroup\Graph\GroupGraphStorageInterface $group_graph_storage
   *   The group graph storage service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   * @param \Drupal\Core\Database\Connection $database
   *   The database.
   */
  public function __construct(
    GroupGraphStorageInterface $group_graph_storage,
    EntityTypeManagerInterface $entity_type_manager,
    CacheBackendInterface $cache,
    Connection $database
  ) {
    $this->groupGraphStorage = $group_graph_storage;
    $this->groupTypeStorage = $entity_type_manager->getStorage('group_type');
    $this->groupRoleStorage = $entity_type_manager->getStorage('group_role');
    $this->groupRelationshipTypeStorage = $entity_type_manager->getStorage('group_relationship_type');
    $this->cache = $cache;
    $this->database = $database;

    // Preload group types.
    $this->getGroupTypes();

    // Preload groups.
    $this->loadGroups();
  }

  /**
   * {@inheritdoc}
   */
  public function buildGroupRolesMap($group_id) {
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

    $cache_tags = $this->getGroupTypeCacheTags($this->getGroupTypeId($group_id));
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
          if ($relation_config = $this->getPluginConfig($path_supergroup_id, $path_subgroup_id)) {
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
        $path_direct_to_group_id = $path[$to_group_key - 1] ?? NULL;

        if (!$path_direct_to_group_id) {
          continue;
        }

        $direct_role_map = $path_role_map[$path_to_group_id][$path_direct_to_group_id] ?? NULL;

        if (empty($inherited_roles_map) && !empty($direct_role_map)) {
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
      $this->addGroupTypeInfo($group_type);
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
      $this->addGroupTypeInfo($group_type);
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
   * Add group type tags and plugins config.
   *
   * @param \Drupal\group\Entity\GroupTypeInterface $group_type
   *   Group type.
   */
  protected function addGroupTypeInfo(GroupTypeInterface $group_type) {
    $cache_tags = [];
    $group_type_id = $group_type->id();
    $plugins = $group_type->getInstalledPlugins();
    foreach ($plugins as $plugin) {
      if ($plugin instanceof Subgroup) {
        $relation_type_id = $this->groupRelationshipTypeStorage->getRelationshipTypeId($group_type_id, $plugin->getPluginId());
        $this->groupTypePluginConfig[$group_type->id()][$plugin->getPluginDefinition()->getEntityBundle()] = $plugin->getConfiguration();
        $cache_tags[] = "config:group.relationship_type.$relation_type_id";
        $cache_tags[] = "group_relationship_list:$relation_type_id";
      }
    }

    $this->groupTypeCacheTags[$group_type_id] = $cache_tags;
  }

  protected function getPluginConfig($group_id, $subgroup_id) {
    return $this->groupTypePluginConfig[$this->getGroupTypeId($group_id)][$this->getGroupTypeId($subgroup_id)] ?? NULL;
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
    }
  }

  /**
   * Get roles by ids.
   *
   * @param array $roles_id
   *   Roles ids.
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


  /**
   * Load all groups.
   *
   * @return array
   *   Array of groups ids.
   */
  public function loadGroups() {
    // @todo: Add db cache here ?
    // @todo: Load only groups with types where we have subgroup plugin enabled.
    if (empty($this->groups)) {
      // Load all groups using query, instead of loading all group entities.
      $this->groups = $this->database->select('groups', 'gr')
        ->fields('gr', ['id', 'type'])
        ->execute()
        ->fetchAllKeyed();
    }

    return $this->groups;
  }


  /**
   * Get group type id by group id.
   *
   * @param $group_id
   *   Group id.
   *
   * @return mixed|null
   *   Group type id.
   */
  protected function getGroupTypeId($group_id) {
    return $this->groups[$group_id] ?? NULL;
  }

}
