<?php

namespace Drupal\ggroup_role_mapper;

use Drupal\group\Entity\GroupInterface;

/**
 * An interface for the group role inheritance manager.
 */
interface GroupRoleInheritanceInterface {

  /**
   * Inherited group role map cache ID.
   */
  const ROLE_MAP_CID = 'ggroup:role_map';

  /**
   * Build group roles map.
   *
   * For all (direct/indirect) relations between groups, we check if there are
   * roles we should map. We map the roles up/down for each relation in the full
   * path between all groups. The result contains all inherited roles between
   * all groups.
   *
   * @param int $group_id
   *   Group id.
   *
   * @return array
   *   A nested array with all inherited roles for all direct/indirect group
   *   relations. The array is in the form of:
   *   $map[$group_a_id][$group_b_id][$group_b_role_id] = $group_a_role_id;
   */
  public function buildGroupRolesMap($group_id);

}
