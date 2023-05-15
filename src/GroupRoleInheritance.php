<?php

namespace Drupal\ggroup_role_mapper;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ggroup\Graph\GroupGraphStorageInterface;
use Drupal\ggroup\Plugin\GroupContentEnabler\Subgroup;
use Drupal\group\Entity\GroupContentType;

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
   * Static cache of all group content types for subgroup group content.
   *
   * This nested array is keyed by subgroup ID and group ID.
   *
   * @var string[][]
   */
  protected $subgroupRelations = [];

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
    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public function getAllInheritedGroupRoleIds($group) {
    $group_id = $group->id();
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

    $cache_tags = ["group:$group_id"];
    $group_type = $group->getGroupType();
    // Add group content types to cache tags.
    $plugins = $group_type->getInstalledContentPlugins();
    foreach ($plugins as $plugin) {
      if ($plugin instanceof Subgroup) {
        $group_content_types = GroupContentType::loadByContentPluginId($plugin->getPluginId());
        foreach ($group_content_types as $group_content_type) {
          $cache_tags[] = "config:group.content_type.{$group_content_type->id()}";
        }
        // Add a tag to invalidate cache when hierarchy changes.
        $cache_tags[] = "group_content_list:{$group_type->id()}-subgroup-{$plugin->getPluginDefinition()['entity_bundle']}";
      }
    }

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
      /** @var \Drupal\group\Entity\Storage\GroupContentTypeStorageInterface $group_content_type_storage */
      $group_content_type_storage = $this->entityTypeManager->getStorage('group_content_type');
      foreach ($this->entityTypeManager->getStorage('group_type')->loadMultiple() as $group_type) {
        $plugin_id = 'ggroup:' . $group_type->id();
        $subgroup_content_types = $group_content_type_storage->loadByContentPluginId($plugin_id);
        foreach ($subgroup_content_types as $subgroup_content_type) {
          /** @var \Drupal\group\Entity\GroupContentTypeInterface $subgroup_content_type */
          $this->subgroupConfig[$subgroup_content_type->id()] = $subgroup_content_type->getContentPlugin()->getConfiguration();
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
      $group_contents = $this->entityTypeManager->getStorage('group_content')
        ->loadByProperties([
          'type' => array_keys($subgroup_relations_config),
          'gid' => [$group_id],
        ]);
      foreach ($group_contents as $group_content) {
        $this->subgroupRelations[$group_content->gid->target_id][$group_content->entity_id->target_id] = $group_content->bundle();
      }
    }

    $type = isset($this->subgroupRelations[$group_id][$subgroup_id]) ? $this->subgroupRelations[$group_id][$subgroup_id] : NULL;
    return isset($subgroup_relations_config[$type]) ? $subgroup_relations_config[$type] : NULL;
  }

}
