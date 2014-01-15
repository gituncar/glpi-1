<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2013 by the INDEPNET Development Team.

 http://indepnet.net/   http://glpi-project.org
 -------------------------------------------------------------------------

 LICENSE

 This file is part of GLPI.

 GLPI is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 GLPI is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

/** @file
* @brief
*/

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/**
 * Search Class
 *
 * Generic class for Search Engine
**/
class Search2 {

   // Default number of items displayed in global search
   const GLOBAL_DISPLAY_COUNT = 10;
   // EXPORT TYPE
   const GLOBAL_SEARCH        = -1;
   const HTML_OUTPUT          = 0;
   const SYLK_OUTPUT          = 1;
   const PDF_OUTPUT_LANDSCAPE = 2;
   const CSV_OUTPUT           = 3;
   const PDF_OUTPUT_PORTRAIT  = 4;

   const LBBR = '#LBBR#';
   const LBHR = '#LBHR#';

   const NULLVALUE = '__NULL__';

   static $output_type = self::HTML_OUTPUT;

   /**
    * Display search engine for an type
    *
    * @param $itemtype item type to manage
    *
    * @return nothing
   **/
   static function show($itemtype) {

      self::manageGetValues($itemtype);
      self::showGenericSearch($itemtype, $_GET);

//       self::showList($itemtype, $_GET);
      $data = self::prepareDatasForSearch($itemtype, $_GET);
      self::constructSQL($data);

      self::getDatas($data);

      Html::printCleanArray($data);
      
//       self::displayDatas($data);
   }

   /**
    * Prepare search criteria to be used for a search
    *
    * @param $itemtype        item type
    * @param $params    array of parameters may include sort, order,
    *                         start, deleted, criteria, metacriteria
    * @param $forcedisplay    array of columns to display (default empty = empty use display pref and search criterias)
    *
    * @return array prepare to be used for a search (include criterias and others needed informations)
   **/
   static function prepareDatasForSearch($itemtype, array $params, array $forcedisplay = array()) {
      global $CFG_GLPI;
      
      // Default values of parameters
      $p['criteria']     = array();
      $p['metacriteria'] = array();
      $p['sort']         = '1'; //
      $p['order']        = 'ASC';//
      $p['start']        = 0;//
      $p['is_deleted']   = 0;
      $p['export_all']   = 0;
      $p['target']       = Toolbox::getItemTypeSearchURL($itemtype);
      $p['display_type'] = self::HTML_OUTPUT;
      $p['list_limit']   = $_SESSION['glpilist_limit'];

      foreach ($params as $key => $val) {
         $p[$key] = $val;
      }

      // Set display type for export if define
      if (isset($p['display_type'])) {
         // Limit to 10 element
         if ($p['display_type'] == self::GLOBAL_SEARCH) {
            $p['list_limit'] = self::GLOBAL_DISPLAY_COUNT;
         }
      }

      if ($p['export_all']) {
         $p['start'] = 0;
      }

      // Clean criteria / metacriteria based on glpisearchcount / glpisearchcount2
      if (isset($_SESSION["glpisearchcount"][$itemtype])) {
         foreach ($p['criteria'] as $key => $val) {
            if ($key >= $_SESSION["glpisearchcount"][$itemtype] ) {
               unset($p['criteria'][$key]);
            }
         }      
      }
      if (isset($_SESSION["glpisearchcount2"][$itemtype])) {
         foreach ($p['metacriteria'] as $key => $val) {
            if ($key >= $_SESSION["glpisearchcount2"][$itemtype] ) {
               unset($p['metacriteria'][$key]);
            }
         }
      }
      
      $data = array();
      $data['search'] = $p;
      $data['itemtype'] = $itemtype;

      // Instanciate an object to access method
      $data['item'] = NULL;

      if ($itemtype != 'AllAssets') {
         $data['item'] = getItemForItemtype($itemtype);
      }
      
      $data['display_type'] = $data['search']['display_type'];      
      
      if (!$CFG_GLPI['allow_search_all']) {
         foreach ($p['criteria'] as $val) {
            if ($val['field'] == 'all') {
               Html::displayRightError();
            }
         }
      }
      if (!$CFG_GLPI['allow_search_view']) {
         foreach ($p['criteria'] as $val) {
            if ($val['field'] == 'view') {
               Html::displayRightError();
            }
         }
      }

      /// Get the items to display
      // Force item to display
      if (is_array($forcedisplay) && count($forcedisplay)) {
         $data['toview'] = $forcedisplay;
      } else {
         $data['toview'] = self::addDefaultToView($itemtype);

         // Add items to display depending of personal prefs
         $displaypref = DisplayPreference::getForTypeUser($itemtype, Session::getLoginUserID());
         if (count($displaypref)) {
            foreach ($displaypref as $val) {
               array_push($data['toview'],$val);
            }
         }

         // Add searched items
         $data['search']['all_search']  = false;
         $data['search']['view_search'] = false;
         // If no research limit research to display item and compute number of item using simple request
         $data['search']['no_search'] = false;

         if (count($p['criteria']) > 0) {
            foreach ($p['criteria'] as $key => $val) {
               if (!in_array($val['field'], $data['toview'])) {
                  if (($val['field'] != 'all') && ($val['field'] != 'view')) {
                     array_push($data['toview'], $val['field']);
                  } else if ($val['field'] == 'all'){
                     $data['search']['all_search'] = true;
                  } else if ($val['field'] == 'view'){
                     $data['search']['view_search'] = true;
                  }
               }
               if (isset($val['value']) && (strlen($val['value']) > 0)) {
                  $data['search']['no_search'] = false;
               }
            }
         }

         if (count($p['metacriteria'])) {
            $data['search']['no_search'] = false;
         }

      
         // Add order item
         if (!in_array($p['sort'], $data['toview'])) {
            array_push($data['toview'], $p['sort']);
         }

         // Special case for Ticket : put ID in front
         if ($itemtype == 'Ticket') {
            array_unshift($data['toview'], 2);
         }
      }

      // Clean toview array
      $data['toview'] = array_unique($data['toview']);

      $limitsearchopt   = self::getCleanedOptions($itemtype);
      foreach ($data['toview'] as $key => $val) {
         if (!isset($limitsearchopt[$val])) {
            unset($data['toview'][$key]);
         }
      }

      // 
      
      return $data;
   }


   /**
    * Construct SQL request depending of search parameters
    *
    * add to data array a field sql containing an array of requests :
    *      search : request to get items limited to wanted ones
    *      count : to count all items based on search criterias
    *                    may be an array a request : need to add counts
    *                    maybe empty : use search one to count
    * @param $data array of search datas prepared to generate SQL
    *
    * @return nothing
   **/
   static function constructSQL(array &$data) {
      global $CFG_GLPI;
      
      if (!isset($data['itemtype'])) {
         return false;
      }
      
      $data['sql']['count']  = array();
      $data['sql']['search'] = '';

      $searchopt = &self::getOptions($data['itemtype']);
      
      $blacklist_tables = array();
      if (isset($CFG_GLPI['union_search_type'][$data['itemtype']])) {
         $itemtable = $CFG_GLPI['union_search_type'][$data['itemtype']];
         $blacklist_tables[] = getTableForItemType($data['itemtype']);
      } else {
         $itemtable = getTableForItemType($data['itemtype']);
      }

      // hack for AllAssets
      if (isset($CFG_GLPI['union_search_type'][$data['itemtype']])) {
         $entity_restrict = true;
      } else {
         $entity_restrict = $data['item']->isEntityAssign();
      }

      // Construct the request

      //// 1 - SELECT
      // request currentuser for SQL supervision, not displayed
      $SELECT = "SELECT '".Toolbox::addslashes_deep($_SESSION['glpiname'])."' AS currentuser,
                        ".self::addDefaultSelect($data['itemtype']);

      // Add select for all toview item
      $maxviewkey = 0;
      foreach ($data['toview'] as $key => $val) {
         $SELECT .= self::addSelect($data['itemtype'], $val, $key, 0);
         if ($key>$maxviewkey) {
            $maxviewkey = $key;
         }
      }

      //// 2 - FROM AND LEFT JOIN
      // Set reference table
      $FROM = " FROM `$itemtable`";

      // Init already linked tables array in order not to link a table several times
      $already_link_tables = array();
      // Put reference table
      array_push($already_link_tables, $itemtable);

      // Add default join
      $COMMONLEFTJOIN = self::addDefaultJoin($data['itemtype'], $itemtable, $already_link_tables);
      $FROM          .= $COMMONLEFTJOIN;

      // Add all table for toview items
      foreach ($data['toview'] as $key => $val) {
         if (!in_array($searchopt[$val]["table"], $blacklist_tables)) {
            $FROM .= self::addLeftJoin($data['itemtype'], $itemtable, $already_link_tables,
                                       $searchopt[$val]["table"],
                                       $searchopt[$val]["linkfield"], 0, 0,
                                       $searchopt[$val]["joinparams"],
                                       $searchopt[$val]["field"]);
         }
      }

      // Search all case :
      if ($data['search']['all_search']) {
         foreach ($searchopt as $key => $val) {
            // Do not search on Group Name
            if (is_array($val)) {
               if (!in_array($searchopt[$key]["table"], $blacklist_tables)) {
                  $FROM .= self::addLeftJoin($data['itemtype'], $itemtable, $already_link_tables,
                                             $searchopt[$key]["table"],
                                             $searchopt[$key]["linkfield"], 0, 0,
                                             $searchopt[$key]["joinparams"],
                                             $searchopt[$key]["field"]);
               }
            }
         }
      }


      //// 3 - WHERE

      // default string
      $COMMONWHERE = self::addDefaultWhere($data['itemtype']);
      $first       = empty($COMMONWHERE);

      // Add deleted if item have it
      if ($data['item'] && $data['item']->maybeDeleted()) {
         $LINK = " AND " ;
         if ($first) {
            $LINK  = " ";
            $first = false;
         }
         $COMMONWHERE .= $LINK."`$itemtable`.`is_deleted` = '".$data['search']['is_deleted']."' ";
      }

      // Remove template items
      if ($data['item'] && $data['item']->maybeTemplate()) {
         $LINK = " AND " ;
         if ($first) {
            $LINK  = " ";
            $first = false;
         }
         $COMMONWHERE .= $LINK."`$itemtable`.`is_template` = '0' ";
      }

      // Add Restrict to current entities
      if ($entity_restrict) {
         $LINK = " AND " ;
         if ($first) {
            $LINK  = " ";
            $first = false;
         }

         if ($data['itemtype'] == 'Entity') {
            $COMMONWHERE .= getEntitiesRestrictRequest($LINK, $itemtable, 'id', '', true);

         } else if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
            // Will be replace below in Union/Recursivity Hack
            $COMMONWHERE .= $LINK." ENTITYRESTRICT ";
         } else {
            $COMMONWHERE .= getEntitiesRestrictRequest($LINK, $itemtable, '', '',
                                                       $data['item']->maybeRecursive());
         }
      }
      $WHERE  = "";
      $HAVING = "";

      // Add search conditions
      // If there is search items
      if (count($data['search']['criteria'])) {
         foreach  ($data['search']['criteria'] as $key => $criteria) {
            // if real search (strlen >0) and not all and view search
            if (isset($criteria['value']) && (strlen($criteria['value']) > 0)) {
               // common search
               if (($criteria['field'] != "all") && ($criteria['field'] != "view")) {
                  $LINK    = " ";
                  $NOT     = 0;
                  $tmplink = "";
                  if (isset($criteria['link'])) {
                     if (strstr($criteria['link'],"NOT")) {
                        $tmplink = " ".str_replace(" NOT","",$criteria['link']);
                        $NOT     = 1;
                     } else {
                        $tmplink = " ".$criteria['link'];
                     }
                  } else {
                     $tmplink = " AND ";
                  }

                  if (isset($searchopt[$criteria['field']]["usehaving"])) {
                     // Manage Link if not first item
                     if (!empty($HAVING)) {
                        $LINK = $tmplink;
                     }
                     // Find key
                     $item_num = array_search($criteria['field'], $toview);
                     $HAVING  .= self::addHaving($LINK, $NOT, $data['itemtype'], $criteria['field'],
                                                 $criteria['searchtype'], $criteria['value'], 0,
                                                 $item_num);
                  } else {
                     // Manage Link if not first item
                     if (!empty($WHERE)) {
                        $LINK = $tmplink;
                     }
                     $WHERE .= self::addWhere($LINK, $NOT, $data['itemtype'], $criteria['field'],
                                              $criteria['searchtype'], $criteria['value']);
                  }

               // view and all search
               } else {
                  $LINK       = " OR ";
                  $NOT        = 0;
                  $globallink = " AND ";

                  if (isset($criteria['link'])) {
                     switch ($criteria['link']) {
                        case "AND" :
                           $LINK       = " OR ";
                           $globallink = " AND ";
                           break;

                        case "AND NOT" :
                           $LINK       = " AND ";
                           $NOT        = 1;
                           $globallink = " AND ";
                           break;

                        case "OR" :
                           $LINK       = " OR ";
                           $globallink = " OR ";
                           break;

                        case "OR NOT" :
                           $LINK       = " AND ";
                           $NOT        = 1;
                           $globallink = " OR ";
                           break;
                     }

                  } else {
                     $tmplink =" AND ";
                  }

                  // Manage Link if not first item
                  if (!empty($WHERE)) {
                     $WHERE .= $globallink;
                  }
                  $WHERE .= " ( ";
                  $first2 = true;

                  $items = array();

                  if ($criteria['field'] == "all") {
                     $items = $searchopt;

                  } else { // toview case : populate toview
                     foreach ($data['toview'] as $key2 => $val2) {
                        $items[$val2] = $searchopt[$val2];
                     }
                  }

                  foreach ($items as $key2 => $val2) {
                     if (isset($val2['nosearch']) && $val2['nosearch']) {
                        continue;
                     }
                     if (is_array($val2)) {
                        // Add Where clause if not to be done in HAVING CLAUSE
                        if (!isset($val2["usehaving"])) {
                           $tmplink = $LINK;
                           if ($first2) {
                              $tmplink = " ";
                              $first2  = false;
                           }
                           $WHERE .= self::addWhere($tmplink, $NOT, $data['itemtype'], $key2,
                                                    $criteria['searchtype'], $criteria['value']);
                        }
                     }
                  }
                  $WHERE .= " ) ";
               }
            }
         }
      }


      //// 4 - ORDER
      $ORDER = " ORDER BY `id` ";
      foreach ($data['toview'] as $key => $val) {
         if ($data['search']['sort'] == $val) {
            $ORDER = self::addOrderBy($data['itemtype'], $data['search']['sort'], $data['search']['order'], $key);
         }
      }

      //// 5 - META SEARCH
      // Preprocessing
      if (count($data['search']['metacriteria'])) {

         // Already link meta table in order not to linked a table several times
         $already_link_tables2 = array();
         $already_selected     = array();
         $metanum = $maxviewkey+1;
         
         foreach ($data['search']['metacriteria'] as $key => $metacriteria) {
            if (isset($metacriteria['itemtype']) && !empty($metacriteria['itemtype'])
                && isset($metacriteria['value']) && (strlen($metacriteria['value']) > 0)) {
               // a - SELECT
               if (!in_array($metacriteria['itemtype'].$metacriteria['field'], $already_selected)) {
                  $SELECT .= self::addSelect($metacriteria['itemtype'], $metacriteria['field'],
                                             $metanum, 1, $metacriteria['itemtype']);
                  $metanum++;
                  $already_selected[] = $metacriteria['itemtype'].$metacriteria['field'];
               }

               // b - ADD LEFT JOIN
               // Link reference tables
               if (!in_array(getTableForItemType($metacriteria['itemtype']), $already_link_tables2)) {
                  $FROM .= self::addMetaLeftJoin($data['itemtype'], $metacriteria['itemtype'],
                                                 $already_link_tables2,
                                                 (($metacriteria['value'] == "NULL")
                                                  || (strstr($metacriteria['link'], "NOT"))));
               }

               // Link items tables
               $metaopt = &self::getOptions($metacriteria['itemtype']);
               $sopt = $metaopt[$metacriteria['field']];
               if (!in_array($sopt["table"]."_".$metacriteria['itemtype'],
                             $already_link_tables2)) {

                  $FROM .= self::addLeftJoin($metacriteria['itemtype'],
                                             getTableForItemType($metacriteria['itemtype']),
                                             $already_link_tables2,
                                             $sopt["table"],
                                             $sopt["linkfield"],
                                             1, $metacriteria['itemtype'],
                                             $sopt["joinparams"],
                                             $sopt["field"]);
               }
            }
         }
      }

      //// 6 - Add item ID
      // Add ID to the select
      if (!empty($itemtable)) {
         $SELECT .= "`$itemtable`.`id` AS id ";
      }


      //// 7 - Manage GROUP BY
      $GROUPBY = "";
      // Meta Search / Search All / Count tickets
      if ((count($data['search']['metacriteria']))
          || !empty($HAVING)
          || $data['search']['all_search']) {
         $GROUPBY = " GROUP BY `$itemtable`.`id`";
      }

      if (empty($GROUPBY)) {
         foreach ($data['toview'] as $key2 => $val2) {
            if (!empty($GROUPBY)) {
               break;
            }
            if (isset($searchopt[$val2]["forcegroupby"])) {
               $GROUPBY = " GROUP BY `$itemtable`.`id`";
            }
         }
      }

      
      // Specific search for others item linked  (META search)
      if (count($data['search']['metacriteria'])) {
         foreach ($data['search']['metacriteria'] as $key => $metacriteria) {
            if (isset($metacriteria['itemtype']) && !empty($metacriteria['itemtype'])
                && isset($metacriteria['value']) && (strlen($metacriteria['value']) > 0)) {

               $LINK = "";

               // For AND NOT statement need to take into account all the group by items
               if (strstr($metacriteria['link'],"AND NOT")
                   || isset($data['searchopt'][$metacriteria['itemtype']][$metacriteria['field']]["usehaving"])) {

                  $NOT = 0;
                  if (strstr($metacriteria['link'],"NOT")) {
                     $tmplink = " ".str_replace(" NOT","",$metacriteria['link']);
                     $NOT     = 1;
                  } else {
                     $tmplink = " ".$metacriteria['link'];
                  }
                  if (!empty($HAVING)) {
                     $LINK = $tmplink;
                  }
                  $HAVING .= self::addHaving($LINK, $NOT, $metacriteria['itemtype'],
                                             $metacriteria['field'], $metacriteria['searchtype'],
                                             $metacriteria['value'], 1, $key);
               } else { // Meta Where Search
                  $LINK = " ";
                  $NOT  = 0;
                  // Manage Link if not first item
                  if (isset($metacriteria['link'])
                      && strstr($metacriteria['link'],"NOT")) {

                     $tmplink = " ".str_replace(" NOT", "", $metacriteria['link']);
                     $NOT     = 1;

                  } else if (isset($metacriteria['link'])) {
                     $tmplink = " ".$metacriteria['link'];

                  } else {
                     $tmplink = " AND ";
                  }

                  if (!empty($WHERE)) {
                     $LINK = $tmplink;
                  }
                  $WHERE .= self::addWhere($LINK, $NOT, $metacriteria['itemtype'], $metacriteria['field'],
                                           $metacriteria['searchtype'], $metacriteria['value'], 1);
               }
            }
         }
      }


      $LIMIT   = "";
      $numrows = 0;
      //No search : count number of items using a simple count(ID) request and LIMIT search
      if ($data['search']['no_search']) {
         $LIMIT = " LIMIT ".$data['search']['start'].", ".$data['search']['list_limit'];

         // Force group by for all the type -> need to count only on table ID
         if (!isset($searchopt[1]['forcegroupby'])) {
            $count = "count(*)";
         } else {
            $count = "count(DISTINCT `$itemtable`.`id`)";
         }
         // request currentuser for SQL supervision, not displayed
         $query_num = "SELECT $count,
                              '".Toolbox::addslashes_deep($_SESSION['glpiname'])."' AS currentuser
                       FROM `$itemtable`".
                       $COMMONLEFTJOIN;

         $first     = true;

         if (!empty($COMMONWHERE)) {
            $LINK = " AND " ;
            if ($first) {
               $LINK  = " WHERE ";
               $first = false;
            }
            $query_num .= $LINK.$COMMONWHERE;
         }
         // Union Search :
         if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
            $tmpquery = $query_num;
            $numrows  = 0;

            foreach ($CFG_GLPI[$CFG_GLPI["union_search_type"][$data['itemtype']]] as $ctype) {
               $ctable = getTableForItemType($ctype);
               if (($citem = getItemForItemtype($ctype))
                   && $citem->canView()) {
                  // State case
                  if ($data['itemtype'] == 'AllAssets') {
                     $query_num = str_replace($CFG_GLPI["union_search_type"][$data['itemtype']],
                                              $ctable, $tmpquery);
                     $query_num = str_replace($data['itemtype'], $ctype, $query_num);
                     $query_num .= " AND `$ctable`.`id` IS NOT NULL ";

                     // Add deleted if item have it
                     if ($citem && $citem->maybeDeleted()) {
                        $query_num .= " AND `$ctable`.`is_deleted` = '0' ";
                     }

                     // Remove template items
                     if ($citem && $citem->maybeTemplate()) {
                        $query_num .= " AND `$ctable`.`is_template` = '0' ";
                     }

                  } else {// Ref table case
                     $reftable = getTableForItemType($data['itemtype']);
                     if ($item && $item->maybeDeleted()) {
                        $tmpquery = str_replace("`".$CFG_GLPI["union_search_type"][$data['itemtype']]."`.
                                                   `is_deleted`",
                                                "`$reftable`.`is_deleted`", $tmpquery);
                     }
                     $replace  = "FROM `$reftable`
                                  INNER JOIN `$ctable`
                                       ON (`$reftable`.`items_id` =`$ctable`.`id`
                                           AND `$reftable`.`itemtype` = '$ctype')";

                     $query_num = str_replace("FROM `".$CFG_GLPI["union_search_type"][$data['itemtype']]."`",
                                              $replace, $tmpquery);
                     $query_num = str_replace($CFG_GLPI["union_search_type"][$data['itemtype']], $ctable,
                                              $query_num);

                  }
                  $query_num = str_replace("ENTITYRESTRICT",
                                           getEntitiesRestrictRequest('', $ctable, '', '',
                                                                      $citem->maybeRecursive()),
                                           $query_num);
                  $data['sql']['count'][] = $query_num;
               }
            }

         } else {
            $data['sql']['count'][] = $query_num;
         }
      }

      // If export_all reset LIMIT condition
      if ($data['search']['export_all']) {
         $LIMIT = "";
      }

      if (!empty($WHERE) || !empty($COMMONWHERE)) {
         if (!empty($COMMONWHERE)) {
            $WHERE = ' WHERE '.$COMMONWHERE.(!empty($WHERE)?' AND ( '.$WHERE.' )':'');
         } else {
            $WHERE = ' WHERE '.$WHERE.' ';
         }
         $first = false;
      }

      if (!empty($HAVING)) {
         $HAVING = ' HAVING '.$HAVING;
      }


      // Create QUERY
      if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
         $first = true;
         $QUERY = "";
         foreach ($CFG_GLPI[$CFG_GLPI["union_search_type"][$data['itemtype']]] as $ctype) {
            $ctable = getTableForItemType($ctype);
            if (($citem = getItemForItemtype($ctype))
                && $citem->canView()) {
               if ($first) {
                  $first = false;
               } else {
                  $QUERY .= " UNION ";
               }
               $tmpquery = "";
               // AllAssets case
               if ($data['itemtype'] == 'AllAssets') {
                  $tmpquery = $SELECT.", '$ctype' AS TYPE ".
                              $FROM.
                              $WHERE;

                  $tmpquery .= " AND `$ctable`.`id` IS NOT NULL ";

                  // Add deleted if item have it
                  if ($citem && $citem->maybeDeleted()) {
                     $tmpquery .= " AND `$ctable`.`is_deleted` = '0' ";
                  }

                  // Remove template items
                  if ($citem && $citem->maybeTemplate()) {
                     $tmpquery .= " AND `$ctable`.`is_template` = '0' ";
                  }

                  $tmpquery.= $GROUPBY.
                              $HAVING;

                  $tmpquery = str_replace($CFG_GLPI["union_search_type"][$data['itemtype']],
                                          $ctable, $tmpquery);
                  $tmpquery = str_replace($data['itemtype'], $ctype, $tmpquery);

               } else {// Ref table case
                  $reftable = getTableForItemType($data['itemtype']);

                  $tmpquery = $SELECT.", '$ctype' AS TYPE,
                                      `$reftable`.`id` AS refID, "."
                                      `$ctable`.`entities_id` AS ENTITY ".
                              $FROM.
                              $WHERE;
                  if ($item->maybeDeleted()) {
                     $tmpquery = str_replace("`".$CFG_GLPI["union_search_type"][$data['itemtype']]."`.
                                                `is_deleted`",
                                             "`$reftable`.`is_deleted`", $tmpquery);
                  }


                  $replace = "FROM `$reftable`"."
                              INNER JOIN `$ctable`"."
                                 ON (`$reftable`.`items_id`=`$ctable`.`id`"."
                                     AND `$reftable`.`itemtype` = '$ctype')";
                  $tmpquery = str_replace("FROM `".$CFG_GLPI["union_search_type"][$data['itemtype']]."`",
                                          $replace, $tmpquery);
                  $tmpquery = str_replace($CFG_GLPI["union_search_type"][$itemtype], $ctable,
                                          $tmpquery);
               }
               $tmpquery = str_replace("ENTITYRESTRICT",
                                       getEntitiesRestrictRequest('', $ctable, '', '',
                                                                  $citem->maybeRecursive()),
                                       $tmpquery);

               // SOFTWARE HACK
               if ($ctype == 'Software') {
                  $tmpquery = str_replace("`glpi_softwares`.`serial`", "''", $tmpquery);
                  $tmpquery = str_replace("`glpi_softwares`.`otherserial`", "''", $tmpquery);
               }
               $QUERY .= $tmpquery;
            }
         }
         if (empty($QUERY)) {
            echo self::showError(self::$output_type);
            return;
         }
         $QUERY .= str_replace($CFG_GLPI["union_search_type"][$data['itemtype']].".", "", $ORDER) . $LIMIT;
      } else {
         $QUERY = $SELECT.
                  $FROM.
                  $WHERE.
                  $GROUPBY.
                  $HAVING.
                  $ORDER.
                  $LIMIT;
      }
      $data['sql']['search'] = $QUERY;
   }


   /**
    * Retrieve datas from DB : construct data array containing columns definitions and rows datas
    *
    * add to data array a field data containing :
    *      cols : columns definition
    *      rows : rows data
    
    * @param $data array of search datas prepared to get datas
    *
    * @return nothing
   **/
   static function getDatas(array &$data) {
      global $CFG_GLPI;

      if (!isset($data['sql']) || !isset($data['sql']['search'])) {
         return false;
      }
      $data['data'] = array();
      
      // Use a ReadOnly connection if available and configured to be used
      $DBread = DBConnection::getReadConnection();
      $DBread->query("SET SESSION group_concat_max_len = 4096;");
      $result = $DBread->query($data['sql']['search']);
      /// Check group concat limit : if warning : increase limit
      if ($result2 = $DBread->query('SHOW WARNINGS')) {
         if ($DBread->numrows($result2) > 0) {
            $data = $DBread->fetch_assoc($result2);
            if ($data['Code'] == 1260) {
               $DBread->query("SET SESSION group_concat_max_len = 4194304;");
               $result = $DBread->query($data['sql']['search']);
            }
         }
      }

      if ($result) {
         $data['data']['totalcount'] = 0;
         // if real search or complete export : get numrows from request
         if (!$data['search']['no_search']
             || $data['search']['export_all']) {
            $data['data']['totalcount'] = $DBread->numrows($result);
         } else {
            if (!isset($data['sql']['count'])
               || (count($data['sql']['count']) == 0)) {
               $data['data']['totalcount'] = $DBread->numrows($result);
            } else {
               foreach ($data['sql']['count'] as $sqlcount) {
                  $result_num = $DBread->query($sqlcount);
                  $data['data']['totalcount'] += $DBread->result($result_num, 0, 0);
               }
            }
         }

         // Search case
         $data['data']['begin'] = $data['search']['start'];
         $data['data']['end']   = min($data['data']['totalcount'],
                                       $data['search']['start']+$data['search']['list_limit'])-1;

         // No search Case
         if ($data['search']['no_search']) {
            $data['data']['begin'] = 0;
            $data['data']['end']   = min($data['data']['totalcount']-$data['search']['start'],
                                          $data['search']['list_limit'])-1;
         }
         // Export All case
         if ($data['search']['export_all']) {
            $data['data']['begin'] = 0;
            $data['data']['end']   = $data['data']['totalcount']-1;
         }

         $data['data']['count'] = $data['data']['end']-$data['data']['begin']+1;

         // Get columns
         $data['data']['cols'] = array();

         $num = 0;
         $searchopt = &self::getOptions($data['itemtype']);
         
         foreach ($data['toview'] as $key => $val) {
            $data['data']['cols'][$num] = array();
            
            $data['data']['cols'][$num]['itemtype']  = $data['itemtype'];
            $data['data']['cols'][$num]['id']        = $val;
            $data['data']['cols'][$num]['name']      = $searchopt[$val]["name"];
            $data['data']['cols'][$num]['meta']      = 0;
            $num++;
         }

         // Display columns Headers for meta items
         $already_printed = array();
         $metanames       = array();
         if (count($data['search']['metacriteria'])) {
            foreach ($data['search']['metacriteria'] as $metacriteria) {
               if (isset($metacriteria['itemtype']) && !empty($metacriteria['itemtype'])
                     && isset($metacriteria['value']) && (strlen($metacriteria['value']) > 0)) {

                  if (!isset($already_printed[$metacriteria['itemtype'].$metacriteria['field']])) {
                     $searchopt = &self::getOptions($metacriteria['itemtype']);
                     if (!isset($metanames[$metacriteria['itemtype']])) {
                        if ($metaitem = getItemForItemtype($metacriteria['itemtype'])) {
                           $metanames[$metacriteria['itemtype']] = $metaitem->getTypeName();
                        }
                     }
                     $data['data']['cols'][$num]['itemtype']  = $metacriteria['itemtype'];
                     $data['data']['cols'][$num]['id']        = $metacriteria['field'];
                     $data['data']['cols'][$num]['name']      = $searchopt[$metacriteria['field']]["name"];
                     $data['data']['cols'][$num]['meta']      = 1;
                     $num++;

                     $already_printed[$metacriteria['itemtype'].$metacriteria['field']] = 1;
                  }
               }
            }
         }

         // Add specific column Header
         if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
            $data['data']['cols'][$num]['itemtype']     = '';
            $data['data']['cols'][$num]['id'] = 0;
            $data['data']['cols'][$num]['name']         = __('Item type');
            $num++;
         }
         
         // Get rows

         // if real search seek to begin of items to display (because of complete search)
         if (!$data['search']['no_search']) {
            $DBread->data_seek($result, $data['search']['start']);
         }

         $i = $data['data']['begin'];
         $data['data']['warning'] = "For compatibility keep raw data  (ITEM_X, META_X) at the top for the moment. Will be drop in next version";
         
         while (($i < $data['data']['totalcount']) && ($i <= $data['data']['end'])) {
            $row = $DBread->fetch_assoc($result);

            $newrow = array();
            $newrow['raw'] = $row;

            // Parse datas
            foreach ($newrow['raw'] as $key => $val) {
               // For compatibility keep data at the top for the moment
               $newrow[$key] = $val;
               
               $keysplit = explode('_', $key);
               
               if (isset($keysplit[1])
                  && $keysplit[0] == 'ITEM') {
                  $j = $keysplit[1];
                  $fieldname = 'rawname';
                  if (isset($keysplit[2])) {
                     $fieldname = $keysplit[2];
                  }

                  // No Group_concat case
                  if (strpos($val,"$$$$") === false) {
                     $newrow[$j]['count'] = 1;
                     
                     if (strpos($val,"$$") === false) {
                        $newrow[$j][0][$fieldname] = $val;
                     } else {
                        $split2 = self::explodeWithID("$$", $val);
                        $newrow[$j][0][$fieldname] = $split2[0];
                        $newrow[$j][0]['id']       = $split2[1];
                     }
                  } else {
                     $newrow[$j] = array();
                     $split = explode("$$$$", $val);
                     $newrow[$j]['count'] = count($split);

                     foreach ($split as $key2 => $val2) {
                        if (strpos($val2,"$$") === false) {
                           $newrow[$j][$key2][$fieldname] = $val2;
                        } else {
                           $split2 = self::explodeWithID("$$", $val2);
                           $newrow[$j][$key2][$fieldname] = $split2[0];
                           $newrow[$j][$key2]['id']      = $split2[1];
                        }
                     }
                  }
               } else {
                  $newrow[$key] = $val;
               }
            }

            foreach ($data['data']['cols'] as $key => $val) {
               if (!empty($val['itemtype'])) {
                  $newrow[$key]['name'] = self::giveItem($val['itemtype'], $val['id'], $newrow, $key);
               }
            }
            
            $data['data']['rows'][$i] = $newrow;
            $i++;
         }

      } else {
         echo $DBread->error();
      }
   }

   
   /**
    * Generic Search and list function
    *
    * Build the query, make the search and list items after a search.
    *
    * @param $itemtype        item type
    * @param $params    array of parameters may include sort, order,
    *                         start, deleted, criteria, metacriteria
    *
    * @return Nothing (display)
   **/
   static function showList($itemtype, array $params) {
      global $DB, $CFG_GLPI;



//       $DBread->query("SET SESSION group_concat_max_len = 4096;");
//       $result = $DBread->query($QUERY);
//       /// Check group concat limit : if warning : increase limit
//       if ($result2 = $DBread->query('SHOW WARNINGS')) {
//          if ($DBread->numrows($result2) > 0) {
//             $data = $DBread->fetch_assoc($result2);
//             if ($data['Code'] == 1260) {
//                $DBread->query("SET SESSION group_concat_max_len = 4194304;");
//                $result = $DBread->query($QUERY);
//             }
//          }
//       }


      // Get it from database and DISPLAY
      if ($result) {

//          // if real search or complete export : get numrows from request
//          if (!$nosearch
//              || $p['export_all']) {
//             $numrows = $DBread->numrows($result);
//          }

         // Contruct Pager parameters
          
         $globallinkto = Toolbox::append_params(array('criteria'=> Toolbox::stripslashes_deep($p['criteria']),
                                                      'metacriteria' => Toolbox::stripslashes_deep($p['metacriteria'])),
                                               '&amp;');
         $parameters = "sort=".$p['sort']."&amp;order=".$p['order'].'&amp;'.$globallinkto;

         // Not more used : clean pages : try to comment it
         /*
         $tmp=explode('?',$p['target'],2);
         if (count($tmp)>1) {
            $p['target'] = $tmp[0];
            $parameters = $tmp[1].'&amp;'.$parameters;
         }
         */
         if (self::$output_type == self::GLOBAL_SEARCH) {
            if ($item = getItemForItemtype($itemtype)) {
               echo "<div class='center'><h2>".$item->getTypeName();
               // More items
               if ($numrows > ($p['start'] + self::GLOBAL_DISPLAY_COUNT)) {
                  echo " <a href='".$p['target']."?$parameters'>".__('All')."</a>";
               }
               echo "</h2></div>\n";
            } else {
               return false;
            }
         }

         // If the begin of the view is before the number of items
         if ($p['start'] < $numrows) {
            // Display pager only for HTML
            if (self::$output_type == self::HTML_OUTPUT) {
               // For plugin add new parameter if available
               if ($plug = isPluginItemType($itemtype)) {
                  $function = 'plugin_'.$plug['plugin'].'_addParamFordynamicReport';

                  if (function_exists($function)) {
                     $out = $function($itemtype);
                     if (is_array($out) && count($out)) {
                        $parameters .= Toolbox::append_params($out, '&amp;');
                     }
                  }
               }
               $search_config = "";
               if (Session::haveRightsOr('search_config', array(DisplayPreference::PERSONAL,
                                                                DisplayPreference::GENERAL))) {

                  $tmp = " class='pointer' onClick=\"".Html::jsGetElementbyID('search_config').".
                                                       dialog('open');\"";

                  $search_config
                     = "<img alt=\"".__s('Select default items to show')."\" title=\"".
                         __s('Select default items to show')."\" src='".
                         $CFG_GLPI["root_doc"]."/pics/options_search.png' ";
                  $search_config
                    .= $tmp.">";
                  $search_config
                    .= Ajax::createIframeModalWindow('search_config',
                                                     $CFG_GLPI["root_doc"].
                                                       "/front/displaypreference.form.php?itemtype=$itemtype",
                                                     array('title'
                                                             => __('Select default items to show'),
                                                           'reloadonclose'
                                                             => true,
                                                           'display'
                                                             => false));
               }

               Html::printPager($p['start'], $numrows, $p['target'], $parameters, $itemtype, 0,
                                $search_config);
            }

            // Define begin and end var for loop
            // Search case
            $begin_display = $p['start'];
            $end_display   = min($numrows, $p['start']+$LIST_LIMIT);

            // No search Case
            if ($nosearch) {
               $begin_display = 0;
               $end_display   = min($numrows-$p['start'], $LIST_LIMIT);
            }
            // Export All case
            if ($p['export_all']) {
               $begin_display = 0;
               $end_display   = $numrows;
            }


            // Form to massive actions
            $isadmin = ($item && $item->canUpdate());
            if (!$isadmin
                && InfoCom::canApplyOn($itemtype)) {
               $isadmin = (Infocom::canUpdate() || Infocom::canCreate());
            }
            if ($itemtype != 'AllAssets') {
               $showmassiveactions = count(MassiveAction::getAllMassiveActions($item,
                                                                               $p['is_deleted']));
            } else {
               $showmassiveactions = true;
            }
            if ($showmassiveactions
                && (self::$output_type == self::HTML_OUTPUT)) {
               Html::openMassiveActionsForm('massform'.$itemtype);
               $massiveactionparams = array('num_displayed' => $end_display-$begin_display,
                                            'fixed'         => false,
                                            'is_deleted'    => $p['is_deleted'],
                                            'container'     => 'massform'.$itemtype);
               Html::showMassiveActions($massiveactionparams);
            }

            // Compute number of columns to display
            // Add toview elements
            $nbcols          = $toview_count;
            $already_printed = array();
            // Add meta search elements if real search (strlen>0) or only NOT search
            if (($_SESSION["glpisearchcount2"][$itemtype] > 0)
                && is_array($p['metacriteria'])) {
               $metacriteria = array();

               if (isset($p['metacriteria'][$i])
                     && is_array($p['metacriteria'][$i])) {
                  $metacriteria = $p['metacriteria'][$i];
               }
               for ($i=0 ; $i<$_SESSION["glpisearchcount2"][$itemtype] ; $i++) {
                  if (isset($metacriteria['itemtype']) && !empty($metacriteria['itemtype'])
                      && isset($metacriteria['value']) && (strlen($metacriteria['value']) > 0)
                      && (!isset($metacriteria['link']) || !strstr($metacriteria['link'],"NOT"))) {

                     if (!isset($already_printed[$metacriteria['itemtype'].$metacriteria['field']])) {
                        $nbcols++;
                        $already_printed[$metacriteria['itemtype'].$metacriteria['field']] = 1;
                     }
                  }
               }
            }

            if (self::$output_type == self::HTML_OUTPUT) { // HTML display - massive modif
               $nbcols++;
            }


            // Display List Header
            echo self::showHeader(self::$output_type, $end_display-$begin_display+1, $nbcols);

            // New Line for Header Items Line
            $headers_line        = '';
            $headers_line_top    = '';
            $headers_line_bottom = '';
            echo self::showBeginHeader(self::$output_type);
            echo self::showNewLine(self::$output_type);
            $header_num = 1;
            if ((self::$output_type == self::HTML_OUTPUT)
                && $showmassiveactions) { // HTML display - massive modif
               $headers_line_top
                  .= self::showHeaderItem(self::$output_type,
                                          Html::getCheckAllAsCheckbox('massform'.$itemtype),
                                          $header_num, "", 0, $p['order']);
               $headers_line_bottom
                  .= self::showHeaderItem(self::$output_type,
                                          Html::getCheckAllAsCheckbox('massform'.$itemtype),
                                          $header_num, "", 0, $p['order']);
            }

            // Display column Headers for toview items
            foreach ($toview as $key => $val) {
               $linkto = '';
               if (!isset($searchopt[$itemtype][$val]['nosort'])
                   || !$searchopt[$itemtype][$val]['nosort']) {

                  $linkto = $p['target']."?itemtype=$itemtype&amp;sort=".$val."&amp;order=".
                             (($p['order'] == "ASC") ?"DESC":"ASC")."&amp;start=".$p['start'].
                             "&amp;".$globallinkto;
               }
               $headers_line .= self::showHeaderItem(self::$output_type,
                                                     $searchopt[$itemtype][$val]["name"],
                                                     $header_num, $linkto, ($p['sort'] == $val),
                                                     $p['order']);
            }

            // Display columns Headers for meta items
            $already_printed = array();
            if (($_SESSION["glpisearchcount2"][$itemtype] > 0)
                && is_array($p['metacriteria'])) {

               for ($i=0 ; $i<$_SESSION["glpisearchcount2"][$itemtype] ; $i++) {
                  $metacriteria = array();

                  if (isset($p['metacriteria'][$i])
                        && is_array($p['metacriteria'][$i])) {
                     $metacriteria = $p['metacriteria'][$i];
                  }
                  if (isset($metacriteria['itemtype']) && !empty($metacriteria['itemtype'])
                      && isset($metacriteria['value']) && (strlen($metacriteria['value']) > 0)) {

                     if (!isset($already_printed[$metacriteria['itemtype'].$metacriteria['field']])) {
                        if (!isset($metanames[$metacriteria['itemtype']])) {
                           if ($metaitem = getItemForItemtype($metacriteria['itemtype'])) {
                              $metanames[$metacriteria['itemtype']] = $metaitem->getTypeName();
                           }
                        }

                        $headers_line
                           .= self::showHeaderItem(self::$output_type,
                                                   sprintf(__('%1$s - %2$s'),
                                                           $metanames[$metacriteria['itemtype']],
                                                           $searchopt[$metacriteria['itemtype']]
                                                               [$metacriteria['field']]["name"]),
                                                           $header_num);
                        $already_printed[$metacriteria['itemtype'].$metacriteria['field']] = 1;
                     }
                  }
               }
            }

            // Add specific column Header
            if (isset($CFG_GLPI["union_search_type"][$itemtype])) {
               $headers_line .= self::showHeaderItem(self::$output_type, __('Item type'),
                                                     $header_num);
            }
            // End Line for column headers
            $headers_line        .= self::showEndLine(self::$output_type);

            $headers_line_top    .= $headers_line;
            $headers_line_bottom .= $headers_line;

            echo $headers_line_top;
            echo self::showEndHeader(self::$output_type);

            // if real search seek to begin of items to display (because of complete search)
            if (!$nosearch) {
               $DBread->data_seek($result, $p['start']);
            }

            // Define begin and end var for loop
            // Search case
            $i = $begin_display;

            // Init list of items displayed
            if (self::$output_type == self::HTML_OUTPUT) {
               Session::initNavigateListItems($itemtype);
            }

            // Num of the row (1=header_line)
            $row_num = 1;

            $massiveaction_field = 'id';
            if (($itemtype != 'AllAssets')
                && isset($CFG_GLPI["union_search_type"][$itemtype])) {
               $massiveaction_field = 'refID';
            }

            // Display Loop
            while (($i < $numrows) && ($i < $end_display)) {
               // Column num
               $item_num = 1;
               // Get data and increment loop variables
               $data = $DBread->fetch_assoc($result);
               $i++;
               $row_num++;
               // New line
               echo self::showNewLine(self::$output_type, ($i%2), $p['is_deleted']);

               $current_type       = (isset($data['TYPE']) ? $data['TYPE'] : $itemtype);
               $massiveaction_type = $current_type;

               if (($itemtype != 'AllAssets')
                   && isset($CFG_GLPI["union_search_type"][$itemtype])) {
                  $massiveaction_type = $itemtype;
               }

               // Add item in item list
               Session::addToNavigateListItems($current_type, $data["id"]);

               if ((self::$output_type == self::HTML_OUTPUT)
                   && $showmassiveactions) { // HTML display - massive modif
                  $tmpcheck = "";
                  if (($itemtype == 'Entity')
                      && !in_array($data["id"], $_SESSION["glpiactiveentities"])) {

                     $tmpcheck = "&nbsp;";

                  } else if (($item instanceof CommonDBTM)
                             && $item->maybeRecursive()
                             && !in_array($data["entities_id"], $_SESSION["glpiactiveentities"])) {
                     $tmpcheck = "&nbsp;";

                  } else {
                     $tmpcheck = Html::getMassiveActionCheckBox($massiveaction_type,
                                                                $data[$massiveaction_field]);
                  }
                  echo self::showItem(self::$output_type, $tmpcheck, $item_num, $row_num,
                                      "width='10'");
               }

               // Print other toview items
               foreach ($toview as $key => $val) {
                  echo self::showItem(self::$output_type, self::giveItem($itemtype, $val, $data, $key),
                                      $item_num, $row_num,
                                      self::displayConfigItem($itemtype, $val, $data, $key));
               }

               // Print Meta Item
               $already_printed = array();
               if (($_SESSION["glpisearchcount2"][$itemtype] > 0)
                   && is_array($p['metacriteria'])) {

                  for ($j=0 ; $j<$_SESSION["glpisearchcount2"][$itemtype] ; $j++) {
                     $metacriteria = array();

                     if (isset($p['metacriteria'][$j])
                           && is_array($p['metacriteria'][$j])) {
                        $metacriteria = $p['metacriteria'][$j];
                     }
                     if (isset($metacriteria['itemtype']) && !empty($metacriteria['itemtype'])
                         && isset($metacriteria['value']) && (strlen($metacriteria['value'])  >0)) {
                        $sopt = $searchopt[$metacriteria['itemtype']][$metacriteria['field']];

                        if (!isset($already_printed[$metacriteria['itemtype'].$metacriteria['field']])) {
                           // General case
                           if (strpos($data["META_$j"],"$$$$") === false) {

                              $out = self::giveItem($metacriteria['itemtype'], $metacriteria['field'], $data,
                                                    $j, 1);
                              echo self::showItem(self::$output_type, $out, $item_num, $row_num);

                           // Case of GROUP_CONCAT item : split item and multilline display
                           } else {
                              $split         = explode("$$$$", $data["META_$j"]);
                              $count_display = 0;
                              $out           = "";
                              $unit          = "";
                              $separate      = self::LBBR;

                              if (isset($sopt['splititems'])
                                  && $sopt['splititems']) {
                                 $separate = self::LBHR;
                              }

                              if (isset($sopt['unit'])) {
                                 $unit = $sopt['unit'];
                              }

                              for ($k=0 ; $k<count($split) ; $k++) {
                                 if (($metacriteria['value'] == "NULL")
                                     || (strlen($metacriteria['value']) == 0)
                                     || preg_match('/'.$metacriteria['value'].'/i',$split[$k])
                                     || isset($sopt['forcegroupby'])) {

                                    if ($count_display) {
                                       $out .= $separate;
                                    }
                                    $count_display++;
                                    // Manage Link to item
                                    $split2 = self::explodeWithID("$$", $split[$k]);
                                    if (isset($split2[1])) {
                                       if (isset($sopt['datatype'])
                                           && ($sopt['datatype'] == 'itemlink')) {
                                          $out .= "<a id='".$metacriteria['itemtype'].'_'.$data["id"].'_'.
                                                   $split2[1]."' ";
                                          $out .= "href=\"".
                                                   Toolbox::getItemTypeFormURL($metacriteria['itemtype']).
                                                   "?id=".$split2[1]."\">";
                                          $out .= Dropdown::getValueWithUnit($split2[0],$unit);
                                          $linkout = $out;
                                          if ($_SESSION["glpiis_ids_visible"]
                                              || empty($split2[0])) {
                                             $linkout = sprintf(__('%1$s (%2$s)'), $linkout,
                                                                $split2[1]);
                                          }
                                          $out = $linkout."</a>";
                                       } else {
                                          // Get specific display if available
                                          $itemtypemeta = getItemTypeForTable($searchopt[$metacriteria['itemtype']]
                                                                              [$metacriteria['field']]['table']);
                                          if ($itemmeta = getItemForItemtype($itemtypemeta)) {
                                             $tmpdata
                                                = array($searchopt[$metacriteria['itemtype']]
                                                        [$metacriteria['field']]['field'] => $split2[0]);
                                             $valdiplay = $searchopt[$metacriteria['itemtype']]
                                                                    [$metacriteria['field']]['field'];
                                             $specific
                                                = $itemmeta->getSpecificValueToDisplay($valdisplay,
                                                                                       $tmpdata,
                                                                                       array('html'
                                                                                             => true));
                                          }
                                          if (!empty($specific)) {
                                             $out .= $specific;
                                          } else {
                                             $out .= Dropdown::getValueWithUnit($split2[0], $unit);
                                          }
                                       }
                                    } else {
                                       $out .= Dropdown::getValueWithUnit($split[$k],$unit);
                                    }
                                 }
                              }
                              echo self::showItem(self::$output_type, $out, $item_num, $row_num);
                           }
                           $already_printed[$metacriteria['itemtype'].$metacriteria['field']] = 1;
                        }
                     }
                  }
               }
               if (isset($CFG_GLPI["union_search_type"][$itemtype])) {
                  $typename = $data["TYPE"];
                  if ($itemtmp = getItemForItemtype($data["TYPE"])) {
                     $typename = $itemtmp->getTypeName();
                  }
                  echo self::showItem(self::$output_type, $typename, $item_num, $row_num);
               }
               // End Line
               echo self::showEndLine(self::$output_type);
            }

            $title = "";
            // Create title
            if ((self::$output_type == self::PDF_OUTPUT_LANDSCAPE)
                || (self::$output_type == self::PDF_OUTPUT_PORTRAIT)) {

               if (($_SESSION["glpisearchcount"][$itemtype] > 0)
                   && (count($p['criteria']) > 0)) {

                  for ($key=0 ; $key<$_SESSION["glpisearchcount"][$itemtype] ; $key++) {
                     $criteria = array();

                     if (isset($p['criteria'][$key])
                           && is_array($p['criteria'][$key])) {
                        $criteria = $p['criteria'][$key];
                     }                  
                     $titlecontain = '';
                     if (strlen($criteria['value']) > 0) {
                        if (isset($criteria['link'])) {
                           $titlecontain = " ".$criteria['link']." ";
                        }
                        switch ($criteria['field']) {
                           case "all" :
                              $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain, __('All'));
                              break;

                           case "view" :
                              $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain,
                                                      __('Items seen'));
                              break;

                           default :
                              $titlecontain
                                 = sprintf(__('%1$s %2$s'), $titlecontain,
                                           $searchopt[$itemtype][$criteria['field']]["name"]);
                        }

                        $gdname = Dropdown::getDropdownName($searchopt[$itemtype][$criteria['field']]
                                                                      ["table"],
                                                            $criteria['value']);
                        switch ($criteria['searchtype']) {
                           case "equals" :
                              if (in_array($searchopt[$itemtype][$criteria['field']]["field"],
                                           array('name', 'completename'))) {
                                 $titlecontain = sprintf(__('%1$s = %2$s'), $titlecontain,
                                                         $gdname);
                              } else {
                                 $titlecontain = sprintf(__('%1$s = %2$s'), $titlecontain,
                                                         $criteria['value']);
                              }
                              break;

                           case "notequals" :
                              if (in_array($searchopt[$itemtype][$criteria['field']]["field"],
                                           array('name', 'completename'))) {
                                 $titlecontain = sprintf(__('%1$s <> %2$s'), $titlecontain,
                                                         $gdname);
                              } else {
                                 $titlecontain = sprintf(__('%1$s <> %2$s'), $titlecontain,
                                                         $criteria['value']);
                              }
                              break;

                           case "lessthan" :
                              $titlecontain = sprintf(__('%1$s < %2$s'), $titlecontain,
                                                      $criteria['value']);
                              break;

                           case "morethan" :
                              $titlecontain = sprintf(__('%1$s > %2$s'), $titlecontain,
                                                      $criteria['value']);
                              break;

                           case "contains" :
                              $titlecontain = sprintf(__('%1$s = %2$s'), $titlecontain,
                                                      '%'.$criteria['value'].'%');
                              break;

                           case "under" :
                              $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain,
                                                      sprintf(__('%1$s %2$s'), __('under'),
                                                               $gdname));
                              break;

                           case "notunder" :
                              $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain,
                                                      sprintf(__('%1$s %2$s'), __('not under'),
                                                              $gdname));
                              break;

                           default :
                              $titlecontain = sprintf(__('%1$s = %2$s'), $titlecontain,
                                                      $criteria['value']);
                              break;
                        }
                     }
                     $title .= $titlecontain;
                  }
               }
               if (($_SESSION["glpisearchcount2"][$itemtype] > 0)
                   && (count($p['metacriteria']) > 0)) {
                  for ($key=0 ; $key<$_SESSION["glpisearchcount2"][$itemtype] ; $key++) {
                     $metacriteria = array();

                     if (isset($p['metacriteria'][$key])
                           && is_array($p['metacriteria'][$key])) {
                        $metacriteria = $p['metacriteria'][$key];
                     }
                     $titlecontain2 = '';
                     if (strlen($metacriteria['value']) > 0) {
                        if (isset($metacriteria['link'])) {
                           $titlecontain2 = sprintf(__('%1$s %2$s'), $titlecontain2,
                                                    $metacriteria['link']);
                        }
                        $titlecontain2
                           = sprintf(__('%1$s %2$s'), $titlecontain2,
                                     sprintf(__('%1$s / %2$s'),
                                             $metanames[$metacriteria['itemtype']],
                                             $searchopt[$metacriteria['itemtype']][$metacriteria['field']]
                                                       ["name"]));

                        $gdname2 = Dropdown::getDropdownName($searchopt[$metacriteria['itemtype']]
                                                                       [$metacriteria['link']]["table"],
                                                             $metacriteria['value']);
                        switch ($metacriteria['searchtype']) {
                           case "equals" :
                              if (in_array($searchopt[$metacriteria['itemtype']][$metacriteria['link']]
                                                     ["field"],
                                           array('name', 'completename'))) {
                                 $titlecontain2 = sprintf(__('%1$s = %2$s'), $titlecontain2,
                                                          $gdname2);
                              } else {
                                 $titlecontain2 = sprintf(__('%1$s = %2$s'), $titlecontain2,
                                                          $metacriteria['value']);
                              }
                              break;

                           case "notequals" :
                              if (in_array($searchopt[$metacriteria['itemtype']][$metacriteria['link']]
                                                     ["field"],
                                           array('name', 'completename'))) {
                                 $titlecontain2 = sprintf(__('%1$s <> %2$s'), $titlecontain2,
                                                          $gdname2);
                              } else {
                                 $titlecontain2 = sprintf(__('%1$s <> %2$s'), $titlecontain2,
                                                          $metacriteria['value']);
                              }
                              break;

                           case "lessthan" :
                              $titlecontain2 = sprintf(__('%1$s < %2$s'), $titlecontain2,
                                                       $metacriteria['value']);
                              break;

                           case "morethan" :
                              $titlecontain2 = sprintf(__('%1$s > %2$s'), $titlecontain2,
                                                       $metacriteria['value']);
                              break;

                           case "contains" :
                              $titlecontain2 = sprintf(__('%1$s = %2$s'), $titlecontain2,
                                                       '%'.$metacriteria['value'].'%');
                              break;

                           case "under" :
                              $titlecontain2 = sprintf(__('%1$s %2$s'), $titlecontain2,
                                                       sprintf(__('%1$s %2$s'), __('under'),
                                                               $gdname2));
                              break;

                           case "notunder" :
                              $titlecontain2 = sprintf(__('%1$s %2$s'), $titlecontain2,
                                                       sprintf(__('%1$s %2$s'), __('not under'),
                                                               $gdname2));
                              break;

                           default :
                              $titlecontain2 = sprintf(__('%1$s = %2$s'), $titlecontain2,
                                                       $metacriteria['value']);
                              break;
                        }
                     }
                     $title .= $titlecontain2;
                  }
               }
            }

            if (self::$output_type == self::HTML_OUTPUT) {
               echo $headers_line_bottom;
            }
            // Display footer
            echo self::showFooter(self::$output_type, $title);

            // Delete selected item
            if (self::$output_type == self::HTML_OUTPUT) {
               if ($showmassiveactions) {
                  $massiveactionparams['ontop'] = false;
                  Html::showMassiveActions($massiveactionparams);
                  // End form for delete item
                  Html::closeForm();
               } else {
                  echo "<br>";
               }
            }
            if (self::$output_type == self::HTML_OUTPUT) { // In case of HTML display
               Html::printPager($p['start'], $numrows, $p['target'], $parameters, '', 0,
                                $search_config);

            }
         } else {
            echo self::showError(self::$output_type);
         }
      } else {
         echo $DBread->error();
      }
   }

   /**
    * Get meta types available for search engine
    *
    * @param $itemtype type to display the form
    *
    * @return Array of available itemtype
   **/
   static function getMetaItemtypeAvailable ($itemtype) {

      // Display meta search items
      $linked = array();
      // Define meta search items to linked
      switch ($itemtype) {
         case 'Computer' :
            $linked = array('Monitor', 'Peripheral', 'Phone', 'Printer', 'Software');
            break;

         case 'Ticket' :
            if (Session::haveRight("ticket", Ticket::READALL)) {
               $linked = array_keys(Ticket::getAllTypesForHelpdesk());
            }
            break;

         case 'Printer' :
         case 'Monitor' :
         case 'Peripheral' :
         case 'Software' :
         case 'Phone' :
            $linked = array('Computer');
            break;
      }
      return $linked;
   }


   /**
    * Print generic search form
    *
    * @param $itemtype        type to display the form
    * @param $params    array of parameters may include sort, is_deleted, criteria, metacriteria
    *
    * @return nothing (displays)
   **/
   static function showGenericSearch($itemtype, array $params) {
      global $CFG_GLPI;

      // Default values of parameters
      $p['sort']         = '';
      $p['is_deleted']   = 0;
      $p['criteria']     = array();
      $p['metacriteria'] = array();

      $p['target']      = Toolbox::getItemTypeSearchURL($itemtype);

      foreach ($params as $key => $val) {
         $p[$key] = $val;
      }

      $options = self::getCleanedOptions($itemtype);

      // Instanciate an object to access method
      $item = NULL;
      if ($itemtype != 'AllAssets') {
         $item = getItemForItemtype($itemtype);
      }

      $linked =  self::getMetaItemtypeAvailable($itemtype);

      echo "<form name='searchform$itemtype' method='get' action=\"".$p['target']."\">";
      echo "<div id='searchcriterias'>";
      echo "<table class='tab_cadre_fixe'>";
      echo "<tr class='tab_bg_1'>";

      if (($_SESSION["glpisearchcount"][$itemtype] + $_SESSION["glpisearchcount2"][$itemtype]) > 1) {
         echo "<td width='10' class='center'>";
         echo "<a href=\"javascript:toggleTableDisplay('searchcriteriastable','searchcriteriasimg',
                                                       '".$CFG_GLPI["root_doc"].
                                                          "/pics/deplier_down.png',
                                                       '".$CFG_GLPI["root_doc"].
                                                          "/pics/deplier_up.png')\">";
         echo "<img alt='' name='searchcriteriasimg' src=\"".$CFG_GLPI["root_doc"].
                                                            "/pics/deplier_up.png\">";
         echo "</td>";
      }
      echo "<td>";

      echo "<table id='searchcriteriastable' width='100%'>";

      $logicaloperators = array('AND'     => 'AND',
                                'OR'      => 'OR',
                                'AND NOT' => 'AND NOT',
                                'OR NOT'  => 'OR NOT',);

      // Display normal search parameters
      for ($i=0 ; $i<$_SESSION["glpisearchcount"][$itemtype] ; $i++) {
         echo "<tr ".($i==0?"class='headerRow'":'')."><td class='left' width='45%'>";
         // First line display add / delete images for normal and meta search items
         if ($i == 0) {
            echo "<input type='hidden' disabled id='add_search_count' name='add_search_count'
                   value='1'>";
            echo "<a href='#' onClick = \"".Html::jsEnable('add_search_count')."
                   document.forms['searchform$itemtype'].submit();\">";
            echo "<img src=\"".$CFG_GLPI["root_doc"]."/pics/plus.png\" alt='+' title=\"".
                   __s('Add a search criterion')."\"></a>&nbsp;&nbsp;&nbsp;&nbsp;";
            if ($_SESSION["glpisearchcount"][$itemtype] > 1) {
               echo "<input type='hidden' disabled id='delete_search_count'
                      name='delete_search_count' value='1'>";
               echo "<a href='#' onClick = \"".Html::jsEnable('delete_search_count')."
                      document.forms['searchform$itemtype'].submit();\">";
               echo "<img src=\"".$CFG_GLPI["root_doc"]."/pics/moins.png\" alt='-' title=\"".
                      __s('Delete a search criterion')."\"></a>&nbsp;&nbsp;&nbsp;&nbsp;";
            }
            if (is_array($linked) && (count($linked) > 0)) {
               echo "<input type='hidden' disabled id='add_search_count2' name='add_search_count2'
                      value='1'>";
               echo "<a href='#' onClick=\"".Html::jsEnable('add_search_count2')."
                      document.forms['searchform$itemtype'].submit();\">";
               echo "<img src=\"".$CFG_GLPI["root_doc"]."/pics/meta_plus.png\" alt='+' title=\"".
                      __s('Add a global search criterion')."\"></a>&nbsp;&nbsp;&nbsp;&nbsp;";

               if ($_SESSION["glpisearchcount2"][$itemtype] > 0) {
                  echo "<input type='hidden' disabled id='delete_search_count2'
                         name='delete_search_count2' value='1'>";
                  echo "<a href='#' onClick=\"".Html::jsEnable('delete_search_count2')."
                         document.forms['searchform$itemtype'].submit();\">";
                  echo "<img src=\"".$CFG_GLPI["root_doc"]."/pics/meta_moins.png\" alt='-' title=\"".
                         __s('Delete a global search criterion')."\"></a>&nbsp;&nbsp;&nbsp;&nbsp;";
               }
            }

            $itemtable = getTableForItemType($itemtype);
            if ($item && $item->maybeDeleted()) {
               echo "<input type='hidden' id='is_deleted' name='is_deleted' value='".
                      $p['is_deleted']."'>";
               echo "<a href='#' onClick = \"toogle('is_deleted','','','');
                      document.forms['searchform$itemtype'].submit();\">
                      <img src=\"".$CFG_GLPI["root_doc"]."/pics/showdeleted".
                       (!$p['is_deleted']?'_no':'').".png\" name='img_deleted' alt=\"".
                       (!$p['is_deleted']?__s('Show the dustbin'):__s("Don't show deleted items")).
                      "\" title=\"".
                       (!$p['is_deleted']?__s('Show the dustbin'):__s("Don't show deleted items")).
                      "\"></a>";
               echo '&nbsp;&nbsp;';
            }
         }

         $criteria = array();

         if (isset($p['criteria'][$i])
               && is_array($p['criteria'][$i])) {
            $criteria = $p['criteria'][$i];
         }

         // Display link item
         if ($i > 0) {
            $value = '';
            if (isset($criteria["link"])) {
               $value = $criteria["link"];
            }
            Dropdown::showFromArray("criteria[$i][link]",$logicaloperators,
                                    array('value' => $value,
                                          'width' => '30%'));
         }


         $selected = $first = '';
         $values   = array();
         // display select box to define search item
         if ($CFG_GLPI['allow_search_view'] == 2) {
            $values['view'] = __('Items seen');
         }

         reset($options);
         $group = '';

         foreach ($options as $key => $val) {
            // print groups
            if (!is_array($val)) {
               $group = $val;
            } else {
               if (!isset($val['nosearch']) || ($val['nosearch'] == false)) {
                  $values[$group][$key] = $val["name"];
               }
            }
         }
         if ($CFG_GLPI['allow_search_view'] == 1) {
            $values['view'] = __('Items seen');
         }
         if ($CFG_GLPI['allow_search_all']) {
            $values['all'] = __('All');
         }
         $value = '';
         if (isset($criteria['field'])) {
            $value = $criteria['field'];
         }

         $rand     = Dropdown::showFromArray("criteria[$i][field]", $values, array('value' => $value,
                                                                                   'width' => '60%'));
         $field_id = Html::cleanId("dropdown_criteria[$i][field]$rand");
         echo "</td><td class='left'>";
         echo "<div id='SearchSpan$itemtype$i'>\n";

         $used_itemtype = $itemtype;

         // Force Computer itemtype for AllAssets to permit to show specific items
         if ($itemtype == 'AllAssets') {
            $used_itemtype = 'Computer';
         }

         $_POST['itemtype']   = $used_itemtype;
         $_POST['num']        = $i;
         $_POST['field']      = $value;
         $_POST['searchtype'] = (isset($criteria['searchtype'])?$criteria['searchtype']:"" );
         $_POST['value']      = (isset($criteria['value'])?stripslashes($criteria['value']):"" );
         include (GLPI_ROOT."/ajax/searchoption.php");
         echo "</div>\n";

         $params = array('field'      => '__VALUE__',
                         'itemtype'   => $used_itemtype,
                         'num'        => $i,
                         'value'      => $_POST["value"],
                         'searchtype' => $_POST["searchtype"]);
         Ajax::updateItemOnSelectEvent($field_id, "SearchSpan$itemtype$i",
                                       $CFG_GLPI["root_doc"]."/ajax/searchoption.php", $params);

         echo "</td></tr>\n";
      }


      $metanames = array();

      if (is_array($linked) && (count($linked) > 0)) {
         for ($i=0 ; $i<$_SESSION["glpisearchcount2"][$itemtype] ; $i++) {

            $metacriteria = array();

            if (isset($p['metacriteria'][$i])
                  && is_array($p['metacriteria'][$i])) {
               $metacriteria = $p['metacriteria'][$i];
            }

            echo "<tr><td class='left' colspan='2'>";
            $rand = mt_rand();

            echo "<table width='100%'><tr class='left'><td width='30%'>";
            // Display link item (not for the first item)
            $value = '';
            if (isset($metacriteria["link"])) {
               $value = $metacriteria["link"];
            }
            Dropdown::showFromArray("metacriteria[$i][link]",$logicaloperators,
                                    array('value' => $value,
                                          'width' => '45%'));

            // Display select of the linked item type available
            foreach ($linked as $key) {
               if (!isset($metanames[$key])) {
                  if ($linkitem = getItemForItemtype($key)) {
                     $metanames[$key] = $linkitem->getTypeName();
                  }
               }
            }
            $value = '';
            if (isset($metacriteria['itemtype'])
                && !empty($metacriteria['itemtype'])) {
               $value = $metacriteria['itemtype'];
            }

            $rand = Dropdown::showItemTypes("metacriteria[$i][itemtype]", $linked, array('width' => '50%',
                                                                                         'value' => $value));
            $field_id = Html::cleanId("dropdown_metacriteria[$i][itemtype]$rand");
            echo "</td><td>";
            // Ajax script for display search met& item
            echo "<span id='show_".$itemtype."_".$i."_$rand'>&nbsp;</span>\n";

            $params = array('itemtype'   => '__VALUE__',
                            'num'        => $i,
                            'field'      => (isset($metacriteria['field'])?$metacriteria['field']:""),
                            'value'      => (isset($metacriteria['value'])?stripslashes($metacriteria['value']):""),
                            'searchtype' => (isset($metacriteria['searchtype'])?$metacriteria['searchtype']:""));

            Ajax::updateItemOnSelectEvent($field_id,
                                          "show_".$itemtype."_".$i."_$rand",
                                          $CFG_GLPI["root_doc"]."/ajax/updateMetaSearch.php",
                                          $params);

            if (isset($metacriteria['itemtype'])
                && !empty($metacriteria['itemtype'])) {

               $params['itemtype'] = $metacriteria['itemtype'];

               Ajax::updateItem("show_".$itemtype."_".$i."_$rand",
                                $CFG_GLPI["root_doc"]."/ajax/updateMetaSearch.php", $params);

            }
            echo "</td></tr></table>";

            echo "</td></tr>\n";
         }
      }
      echo "</table>\n";
      echo "</td>\n";

      echo "<td width='150px'>";
      echo "<table width='100%'>";

      // Display deleted selection

      echo "<tr>";

      // Display submit button
      echo "<td width='80' class='center'>";
      echo "<input type='submit' value=\""._sx('button', 'Search')."\" class='submit' >";
      echo "</td><td>";
      Bookmark::showSaveButton(Bookmark::SEARCH, $itemtype);
      echo "<a href='".$p['target']."?reset=reset' >";
      echo "&nbsp;&nbsp;<img title=\"".__s('Blank')."\" alt=\"".__s('Blank')."\" src='".
            $CFG_GLPI["root_doc"]."/pics/reset.png' class='calendrier'></a>";

      echo "</td></tr></table>\n";

      echo "</td></tr>";
      echo "</table>\n";

      // For dropdown
      echo "<input type='hidden' name='itemtype' value='$itemtype'>";

      // Reset to start when submit new search
      echo "<input type='hidden' name='start' value='0'>";
      echo "</div>";
      Html::closeForm();
   }


   /**
    * Generic Function to add GROUP BY to a request
    *
    * @param $LINK           link to use
    * @param $NOT            is is a negative search ?
    * @param $itemtype       item type
    * @param $ID             ID of the item to search
    * @param $searchtype     search type ('contains' or 'equals')
    * @param $val            value search
    * @param $meta           is it a meta item ?
    * @param $num            item number
    *
    * @return select string
   **/
   static function addHaving($LINK, $NOT, $itemtype, $ID, $searchtype, $val, $meta, $num) {

      $searchopt  = &self::getOptions($itemtype);
      $table      = $searchopt[$ID]["table"];
      $field      = $searchopt[$ID]["field"];

      $NAME = "ITEM_";
      if ($meta) {
         $NAME = "META_";
      }

      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $function = 'plugin_'.$plug['plugin'].'_addHaving';
         if (function_exists($function)) {
            $out = $function($LINK, $NOT, $itemtype, $ID, $val, $num);
            if (!empty($out)) {
               return $out;
            }
         }
      }

      //// Default cases
      // Link with plugin tables
      if (preg_match("/^glpi_plugin_([a-z0-9]+)/", $table, $matches)) {
         if (count($matches) == 2) {
            $plug     = $matches[1];
            $function = 'plugin_'.$plug.'_addHaving';
            if (function_exists($function)) {
               $out = $function($LINK, $NOT, $itemtype, $ID, $val, $num);
               if (!empty($out)) {
                  return $out;
               }
            }
         }
      }

      // Preformat items
      if (isset($searchopt[$ID]["datatype"])) {
         switch ($searchopt[$ID]["datatype"]) {
            case "count" :
            case "number" :
            case "decimal" :
            case "timestamp" :
               $search  = array("/\&lt;/","/\&gt;/");
               $replace = array("<",">");
               $val     = preg_replace($search, $replace, $val);
               if (preg_match("/([<>])([=]*)[[:space:]]*([0-9]+)/",$val,$regs)) {
                  if ($NOT) {
                     if ($regs[1] == '<') {
                        $regs[1] = '>';
                     } else {
                        $regs[1] = '<';
                     }
                  }
                  $regs[1] .= $regs[2];
                  return " $LINK (`$NAME$num` ".$regs[1]." ".$regs[3]." ) ";
               }

               if (is_numeric($val)) {
                  if (isset($searchopt[$ID]["width"])) {
                     if (!$NOT) {
                        return " $LINK (`$NAME$num` < ".(intval($val) + $searchopt[$ID]["width"])."
                                        AND `$NAME$num` > ".
                                           (intval($val) - $searchopt[$ID]["width"]).") ";
                     }
                     return " $LINK (`$NAME$num` > ".(intval($val) + $searchopt[$ID]["width"])."
                                     OR `$NAME$num` < ".
                                        (intval($val) - $searchopt[$ID]["width"])." ) ";
                  }
                  // Exact search
                  if (!$NOT) {
                     return " $LINK (`$NAME$num` = ".(intval($val)).") ";
                  }
                  return " $LINK (`$NAME$num` <> ".(intval($val)).") ";
               }
               break;
         }
      }

/*
      $ADD="";
      if (($NOT && $val!="NULL")
         || $val=='^$') {

         $ADD = " OR `$NAME$num` IS NULL";
      }

      return " $LINK (`$NAME$num`".self::makeTextSearch($val,$NOT)."
                     $ADD ) ";
*/
      return self::makeTextCriteria("`$NAME$num`",$val,$NOT,$LINK);
   }


   /**
    * Generic Function to add ORDER BY to a request
    *
    * @param $itemtype  ID of the device type
    * @param $ID        field to add
    * @param $order     order define
    * @param $key       item number (default 0)
    *
    * @return select string
    *
   **/
   static function addOrderBy($itemtype, $ID, $order, $key=0) {
      global $CFG_GLPI;

      // Security test for order
      if ($order != "ASC") {
         $order = "DESC";
      }
      $searchopt = &self::getOptions($itemtype);

      $table     = $searchopt[$ID]["table"];
      $field     = $searchopt[$ID]["field"];


      $addtable = '';

      if (($table != getTableForItemType($itemtype))
          && ($searchopt[$ID]["linkfield"] != getForeignKeyFieldForTable($table))) {
         $addtable .= "_".$searchopt[$ID]["linkfield"];
      }

      if (isset($searchopt[$ID]['joinparams'])) {
         $complexjoin = self::computeComplexJoinID($searchopt[$ID]['joinparams']);

         if (!empty($complexjoin)) {
            $addtable .= "_".$complexjoin;
         }
      }

      if (isset($CFG_GLPI["union_search_type"][$itemtype])) {
         return " ORDER BY ITEM_$key $order ";
      }

      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $function = 'plugin_'.$plug['plugin'].'_addOrderBy';
         if (function_exists($function)) {
            $out = $function($itemtype, $ID, $order, $key);
            if (!empty($out)) {
               return $out;
            }
         }
      }

      switch($table.".".$field) {
         case "glpi_auth_tables.name" :
            $user_searchopt = self::getOptions('User');
            return " ORDER BY `glpi_users`.`authtype` $order,
                              `glpi_authldaps".$addtable."_".
                                 self::computeComplexJoinID($user_searchopt[30]['joinparams'])."`.
                                 `name` $order,
                              `glpi_authmails".$addtable."_".
                                 self::computeComplexJoinID($user_searchopt[31]['joinparams'])."`.
                                 `name` $order ";

         case "glpi_users.name" :
            if ($itemtype!='User') {
               return " ORDER BY ".$table.$addtable.".`realname` $order,
                                 ".$table.$addtable.".`firstname` $order,
                                 ".$table.$addtable.".`name` $order";
            }
            return " ORDER BY `".$table."`.`name` $order";

         case "glpi_networkequipments.ip" :
         case "glpi_ipaddresses.name" :
            return " ORDER BY INET_ATON($table$addtable.$field) $order ";
      }

      //// Default cases

      // Link with plugin tables
      if (preg_match("/^glpi_plugin_([a-z0-9]+)/", $table, $matches)) {
         if (count($matches) == 2) {
            $plug     = $matches[1];
            $function = 'plugin_'.$plug.'_addOrderBy';
            if (function_exists($function)) {
               $out = $function($itemtype, $ID, $order, $key);
               if (!empty($out)) {
                  return $out;
               }
            }
         }
      }

      // Preformat items
      if (isset($searchopt[$ID]["datatype"])) {
         switch ($searchopt[$ID]["datatype"]) {
            case "date_delay" :
               $interval = "MONTH";
               if (isset($searchopt[$ID]['delayunit'])) {
                  $interval = $searchopt[$ID]['delayunit'];
               }

               $add_minus = '';
               if (isset($searchopt[$ID]["datafields"][3])) {
                  $add_minus = "- `$table$addtable`.`".$searchopt[$ID]["datafields"][3]."`";
               }
               return " ORDER BY ADDDATE(`$table$addtable`.`".$searchopt[$ID]["datafields"][1]."`,
                                         INTERVAL (`$table$addtable`.`".
                                                   $searchopt[$ID]["datafields"][2]."` $add_minus)
                                         $interval) $order ";
         }
      }

      //return " ORDER BY $table.$field $order ";
      return " ORDER BY ITEM_$key $order ";

   }


   /**
    * Generic Function to add default columns to view
    *
    * @param $itemtype device type
    *
    * @return select string
   **/
   static function addDefaultToView($itemtype) {
      global $CFG_GLPI;

      $toview = array();
      $item   = NULL;
      if ($itemtype != 'AllAssets') {
         $item = getItemForItemtype($itemtype);
      }
      // Add first element (name)
      array_push($toview, 1);

      // Add entity view :
      if (Session::isMultiEntitiesMode()
          && (isset($CFG_GLPI["union_search_type"][$itemtype])
              || ($item && $item->maybeRecursive())
              || (count($_SESSION["glpiactiveentities"]) > 1))) {
         array_push($toview, 80);
      }
      return $toview;
   }


   /**
    * Generic Function to add default select to a request
    *
    * @param $itemtype device type
    *
    * @return select string
   **/
   static function addDefaultSelect($itemtype) {

      $itemtable = getTableForItemType($itemtype);
      $item      = NULL;
      $mayberecursive = false;
      if ($itemtype != 'AllAssets') {
         $item           = getItemForItemtype($itemtype);
         $mayberecursive = $item->maybeRecursive();
      }
      $ret = "";
      switch ($itemtype) {

         case 'FieldUnicity' :
            $ret = "`glpi_fieldunicities`.`itemtype` AS ITEMTYPE,";
            break;

         default :
            // Plugin can override core definition for its type
            if ($plug = isPluginItemType($itemtype)) {
               $function = 'plugin_'.$plug['plugin'].'_addDefaultSelect';
               if (function_exists($function)) {
                  $ret = $function($itemtype);
               }
            }
      }
      if ($itemtable == 'glpi_entities') {
         $ret .= "`$itemtable`.`id` AS entities_id, '1' AS is_recursive, ";
      } else if ($mayberecursive) {
         $ret .= "`$itemtable`.`entities_id`, `$itemtable`.`is_recursive`, ";
      }
      return $ret;
   }


   /**
    * Generic Function to add select to a request
    *
    * @param $itemtype     item type
    * @param $ID           ID of the item to add
    * @param $num          item num in the reque (default 0)
    * @param $meta         boolean is a meta
    * @param $meta_type    meta type table ID (default 0)
    *
    * @return select string
   **/
   static function addSelect($itemtype, $ID, $num, $meta=0, $meta_type=0) {
      global $CFG_GLPI;

      $searchopt   = &self::getOptions($itemtype);
      $table       = $searchopt[$ID]["table"];
      $field       = $searchopt[$ID]["field"];
      $addtable    = "";
      $NAME        = "ITEM";
      $complexjoin = '';

      if (isset($searchopt[$ID]['joinparams'])) {
         $complexjoin = self::computeComplexJoinID($searchopt[$ID]['joinparams']);
      }

      if (((($table != getTableForItemType($itemtype))
            && (!isset($CFG_GLPI["union_search_type"][$itemtype])
                || ($CFG_GLPI["union_search_type"][$itemtype] != $table)))
           || !empty($complexjoin))
          && ($searchopt[$ID]["linkfield"] != getForeignKeyFieldForTable($table))) {
         $addtable .= "_".$searchopt[$ID]["linkfield"];
      }

      if (!empty($complexjoin)) {
         $addtable .= "_".$complexjoin;
      }

      if ($meta) {
//          $NAME = "META";
         if (getTableForItemType($meta_type)!=$table) {
            $addtable .= "_".$meta_type;
         }
      }

      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $function = 'plugin_'.$plug['plugin'].'_addSelect';
         if (function_exists($function)) {
            $out = $function($itemtype,$ID,$num);
            if (!empty($out)) {
               return $out;
            }
         }
      }


      $tocompute      = "`$table$addtable`.`$field`";
      $tocomputeid    = "`$table$addtable`.`id`";
      $tocomputetrans = "`$table".$addtable."_trans`.`value`";

      $ADDITONALFIELDS = '';
      if (isset($searchopt[$ID]["additionalfields"])
          && count($searchopt[$ID]["additionalfields"])) {
         foreach ($searchopt[$ID]["additionalfields"] as $key) {
            if ($meta
                || (isset($searchopt[$ID]["forcegroupby"]) && $searchopt[$ID]["forcegroupby"])) {
               $ADDITONALFIELDS .= " GROUP_CONCAT(DISTINCT CONCAT(IFNULL(`$table$addtable`.`$key`,
                                                                         '".self::NULLVALUE."'),
                                                   '$$', $tocomputeid) SEPARATOR '$$$$')
                                    AS ".$NAME."_".$num."_$key, ";
            } else {
               $ADDITONALFIELDS .= "`$table$addtable`.`$key` AS ".$NAME."_".$num."_$key, ";
            }
         }
      }


      // Virtual display no select : only get additional fields
      if ($field == '_virtual') {
         return $ADDITONALFIELDS;
      }

      switch ($table.".".$field) {

         case "glpi_users.name" :
            if ($itemtype != 'User') {
               if ((isset($searchopt[$ID]["forcegroupby"]) && $searchopt[$ID]["forcegroupby"])) {
                  $addaltemail = "";
                  if ((($itemtype == 'Ticket') || ($itemtype == 'Problem'))
                      && isset($searchopt[$ID]['joinparams']['beforejoin']['table'])
                      && (($searchopt[$ID]['joinparams']['beforejoin']['table']
                            == 'glpi_tickets_users')
                          || ($searchopt[$ID]['joinparams']['beforejoin']['table']
                                == 'glpi_problems_users')
                          || ($searchopt[$ID]['joinparams']['beforejoin']['table']
                                == 'glpi_changes_users'))) { // For tickets_users

                     $ticket_user_table
                        = $searchopt[$ID]['joinparams']['beforejoin']['table'].
                          "_".self::computeComplexJoinID($searchopt[$ID]['joinparams']['beforejoin']
                                                                   ['joinparams']);
                     $addaltemail
                        = "GROUP_CONCAT(DISTINCT CONCAT(`$ticket_user_table`.`users_id`, ' ',
                                                        `$ticket_user_table`.`alternative_email`)
                                                        SEPARATOR '$$$$') AS ".$NAME."_".$num."_2, ";
                  }
                  return " GROUP_CONCAT(DISTINCT `$table$addtable`.`id` SEPARATOR '$$$$')
                                       AS ".$NAME."_".$num.",
                           $addaltemail
                           $ADDITONALFIELDS";

               }
               return " `$table$addtable`.`$field` AS ".$NAME."_$num,
                        `$table$addtable`.`realname` AS ".$NAME."_".$num."_2,
                        `$table$addtable`.`id`  AS ".$NAME."_".$num."_3,
                        `$table$addtable`.`firstname` AS ".$NAME."_".$num."_4,
                        $ADDITONALFIELDS";
            }
            break;

         case "glpi_softwarelicenses.number" :
            return " FLOOR(SUM(`$table$addtable`.`$field`)
                           * COUNT(DISTINCT `$table$addtable`.`id`)
                           / COUNT(`$table$addtable`.`id`)) AS ".$NAME."_".$num.",
                     MIN(`$table$addtable`.`$field`) AS ".$NAME."_".$num."_2,
                      $ADDITONALFIELDS";

         case "glpi_networkports.mac" :
            $port = " GROUP_CONCAT(`$table$addtable`.`$field` SEPARATOR '$$$$')
                                  AS ".$NAME."_$num, ";
            if ($itemtype == 'Computer') {
               $port .= " GROUP_CONCAT(`glpi_items_devicenetworkcards`.`mac` SEPARATOR '$$$$')
                                      AS ".$NAME."_".$num."_2, ";
            }
            return $port.$ADDITONALFIELDS;

         case "glpi_profiles.name" :
            if (($itemtype == 'User')
                && ($ID == 20)) {
               return " GROUP_CONCAT(`$table$addtable`.`$field` SEPARATOR '$$$$') AS ".$NAME."_$num,
                        GROUP_CONCAT(`glpi_profiles_users`.`entities_id` SEPARATOR '$$$$')
                                    AS ".$NAME."_".$num."_2,
                        GROUP_CONCAT(`glpi_profiles_users`.`is_recursive` SEPARATOR '$$$$')
                                    AS ".$NAME."_".$num."_3,
                        GROUP_CONCAT(`glpi_profiles_users`.`is_dynamic` SEPARATOR '$$$$')
                                    AS ".$NAME."_".$num."_4,
                        $ADDITONALFIELDS";
            }
            break;

         case "glpi_entities.completename" :
            if (($itemtype == 'User')
                && ($ID == 80)) {
               return " GROUP_CONCAT(`$table$addtable`.`completename` SEPARATOR '$$$$')
                                    AS ".$NAME."_$num,
                        GROUP_CONCAT(`glpi_profiles_users`.`profiles_id` SEPARATOR '$$$$')
                                    AS ".$NAME."_".$num."_2,
                        GROUP_CONCAT(`glpi_profiles_users`.`is_recursive` SEPARATOR '$$$$')
                                    AS ".$NAME."_".$num."_3,
                        GROUP_CONCAT(`glpi_profiles_users`.`is_dynamic` SEPARATOR '$$$$')
                                    AS ".$NAME."_".$num."_4,
                        $ADDITONALFIELDS";
            }
            break;

         case "glpi_auth_tables.name":
            $user_searchopt = self::getOptions('User');
            return " `glpi_users`.`authtype` AS ".$NAME."_".$num.",
                     `glpi_users`.`auths_id` AS ".$NAME."_".$num."_2,
                     `glpi_authldaps".$addtable."_".
                           self::computeComplexJoinID($user_searchopt[30]['joinparams'])."`.`$field`
                              AS ".$NAME."_".$num."_3,
                     `glpi_authmails".$addtable."_".
                           self::computeComplexJoinID($user_searchopt[31]['joinparams'])."`.`$field`
                              AS ".$NAME."_".$num."_4,
                     $ADDITONALFIELDS";

         case "glpi_softwarelicenses.name" :
         case "glpi_softwareversions.name" :
            if ($meta) {
               return " GROUP_CONCAT(DISTINCT CONCAT(`glpi_softwares`.`name`, ' - ',
                                                     `$table$addtable`.`$field`, '$$',
                                                     `$table$addtable`.`id`) SEPARATOR '$$$$')
                                    AS ".$NAME."_".$num.",
                        $ADDITONALFIELDS";
            }
            break;

         case "glpi_softwarelicenses.serial" :
         case "glpi_softwarelicenses.otherserial" :
         case "glpi_softwarelicenses.comment" :
         case "glpi_softwareversions.comment" :
            if ($meta) {
               return " GROUP_CONCAT(DISTINCT CONCAT(`glpi_softwares`.`name`, ' - ',
                                                     `$table$addtable`.`$field`,'$$',
                                                     `$table$addtable`.`id`) SEPARATOR '$$$$')
                                    AS ".$NAME."_".$num.",
                        $ADDITONALFIELDS";
            }
            return " GROUP_CONCAT(DISTINCT CONCAT(`$table$addtable`.`name`, ' - ',
                                                  `$table$addtable`.`$field`, '$$',
                                                  `$table$addtable`.`id`) SEPARATOR '$$$$')
                                 AS ".$NAME."_".$num.",
                     $ADDITONALFIELDS";

         case "glpi_states.name" :
            if ($meta && ($meta_type == 'Software')) {
               return " GROUP_CONCAT(DISTINCT CONCAT(`glpi_softwares`.`name`, ' - ',
                                                     `glpi_softwareversions$addtable`.`name`, ' - ',
                                                     `$table$addtable`.`$field`, '$$',
                                                     `$table$addtable`.`id`) SEPARATOR '$$$$')
                                     AS ".$NAME."_".$num.",
                        $ADDITONALFIELDS";
            } else if ($itemtype == 'Software') {
               return " GROUP_CONCAT(DISTINCT CONCAT(`glpi_softwareversions`.`name`, ' - ',
                                                     `$table$addtable`.`$field`,'$$',
                                                     `$table$addtable`.`id`) SEPARATOR '$$$$')
                                    AS ".$NAME."_".$num.",
                        $ADDITONALFIELDS";
            }
            break;
      }

      //// Default cases
      // Link with plugin tables
      if (preg_match("/^glpi_plugin_([a-z0-9]+)/", $table, $matches)) {
         if (count($matches) == 2) {
            $plug     = $matches[1];
            $function = 'plugin_'.$plug.'_addSelect';
            if (function_exists($function)) {
               $out = $function($itemtype, $ID, $num);
               if (!empty($out)) {
                  return $out;
               }
            }
         }
      }

      if (isset($searchopt[$ID]["computation"])) {
         $tocompute = $searchopt[$ID]["computation"];
         $tocompute = str_replace("TABLE", "`$table$addtable`", $tocompute);
      }

      // Preformat items
      if (isset($searchopt[$ID]["datatype"])) {
         switch ($searchopt[$ID]["datatype"]) {
            case "count" :
               return " COUNT(DISTINCT `$table$addtable`.`$field`) AS ".$NAME."_".$num.",
                     $ADDITONALFIELDS";

            case "date_delay" :
               $interval = "MONTH";
               if (isset($searchopt[$ID]['delayunit'])) {
                  $interval = $searchopt[$ID]['delayunit'];
               }

               $add_minus = '';
               if (isset($searchopt[$ID]["datafields"][3])) {
                  $add_minus = "-`$table$addtable`.`".$searchopt[$ID]["datafields"][3]."`";
               }
               if ($meta
                   || (isset($searchopt[$ID]["forcegroupby"]) && $searchopt[$ID]["forcegroupby"])) {
                  return " GROUP_CONCAT(DISTINCT ADDDATE(`$table$addtable`.`".
                                                            $searchopt[$ID]["datafields"][1]."`,
                                                         INTERVAL (`$table$addtable`.`".
                                                                    $searchopt[$ID]["datafields"][2].
                                                                    "` $add_minus) $interval)
                                         SEPARATOR '$$$$') AS ".$NAME."_$num,
                           $ADDITONALFIELDS";
               }
               return "ADDDATE(`$table$addtable`.`".$searchopt[$ID]["datafields"][1]."`,
                               INTERVAL (`$table$addtable`.`".$searchopt[$ID]["datafields"][2].
                                          "` $add_minus) $interval) AS ".$NAME."_$num,
                       $ADDITONALFIELDS";

            case "itemlink" :
               if ($meta
                  || (isset($searchopt[$ID]["forcegroupby"]) && $searchopt[$ID]["forcegroupby"])) {
                  return " GROUP_CONCAT(DISTINCT CONCAT($tocompute, '$$' ,
                                                        `$table$addtable`.`id`) SEPARATOR '$$$$')
                                       AS ".$NAME."_$num,
                           $ADDITONALFIELDS";
               }
               return " $tocompute AS ".$NAME."_$num,
                        `$table$addtable`.`id` AS ".$NAME."_".$num."_2,
                        $ADDITONALFIELDS";
         }
      }
      // Default case
      if ($meta
          || (isset($searchopt[$ID]["forcegroupby"]) && $searchopt[$ID]["forcegroupby"]
              && !isset($searchopt[$ID]["computation"]))) { // Not specific computation
         $TRANS = '';
         if (Session::haveTranslations(getItemTypeForTable($table), $field)) {
            $TRANS = ", '$$', $tocomputetrans";

         }
         return " GROUP_CONCAT(DISTINCT CONCAT(IFNULL($tocompute, '".self::NULLVALUE."') $TRANS,
                                               '$$',$tocomputeid) SEPARATOR '$$$$')
                              AS ".$NAME."_$num,
                  $ADDITONALFIELDS";
      }
      $TRANS = '';
      if (Session::haveTranslations(getItemTypeForTable($table), $field)) {
         $TRANS = $tocomputetrans." AS ".$NAME."_".$num."_trans, ";

      }
      return "$tocompute AS ".$NAME."_$num, $TRANS $ADDITONALFIELDS";
   }


   /**
    * Generic Function to add default where to a request
    *
    * @param $itemtype device type
    *
    * @return select string
   **/
   static function addDefaultWhere($itemtype) {
      global $CFG_GLPI;

      switch ($itemtype) {
         case 'Reminder' :
            return Reminder::addVisibilityRestrict();

         case 'Notification' :
            if (!Config::canView()) {
               return " `glpi_notifications`.`itemtype` NOT IN ('Crontask', 'DBConnection') ";
            }
            break;

         // No link
         case 'User' :
            // View all entities
            if (Session::isViewAllEntities()) {
               return "";
            }
            return getEntitiesRestrictRequest("","glpi_profiles_users");

         case 'ProjectTask' :
            $condition  = '';
            $teamtable  = 'glpi_projecttaskteams';
            $condition .= "((`$teamtable`.`itemtype` = 'User'
                             AND `$teamtable`.`items_id` = '".Session::getLoginUserID()."')";
            if (count($_SESSION['glpigroups'])) {
               $condition .= " OR (`$teamtable`.`itemtype` = 'Group'
                                    AND `$teamtable`.`items_id`
                                       IN (".implode(",",$_SESSION['glpigroups'])."))";
            }
            $condition .= ") ";

            return $condition;

         case 'Project' :
            $condition = '';
            if (!Session::haveRight("project", Ticket::READALL)) {
               $teamtable  = 'glpi_projectteams';
               $condition .= "(`glpi_projects`.users_id = '".Session::getLoginUserID()."'
                               OR (`$teamtable`.`itemtype` = 'User'
                                   AND `$teamtable`.`items_id` = '".Session::getLoginUserID()."')";
               if (count($_SESSION['glpigroups'])) {
                  $condition .= " OR (`glpi_projects`.`groups_id`
                                       IN (".implode(",",$_SESSION['glpigroups'])."))";
                  $condition .= " OR (`$teamtable`.`itemtype` = 'Group'
                                      AND `$teamtable`.`items_id`
                                          IN (".implode(",",$_SESSION['glpigroups'])."))";
               }
               $condition .= ") ";
            }
            return $condition;

         case 'Ticket' :
            // Same structure in addDefaultJoin
            $condition = '';
            if (!Session::haveRight("ticket", Ticket::READALL)) {

               $searchopt
                  = &self::getOptions($itemtype);
               $requester_table
                  = '`glpi_tickets_users_'.
                     self::computeComplexJoinID($searchopt[4]['joinparams']['beforejoin']
                                                          ['joinparams']).'`';
               $requestergroup_table
                  = '`glpi_groups_tickets_'.
                     self::computeComplexJoinID($searchopt[71]['joinparams']['beforejoin']
                                                          ['joinparams']).'`';

               $assign_table
                  = '`glpi_tickets_users_'.
                     self::computeComplexJoinID($searchopt[5]['joinparams']['beforejoin']
                                                          ['joinparams']).'`';
               $assigngroup_table
                  = '`glpi_groups_tickets_'.
                     self::computeComplexJoinID($searchopt[8]['joinparams']['beforejoin']
                                                          ['joinparams']).'`';

               $observer_table
                  = '`glpi_tickets_users_'.
                     self::computeComplexJoinID($searchopt[66]['joinparams']['beforejoin']
                                                          ['joinparams']).'`';
               $observergroup_table
                  = '`glpi_groups_tickets_'.
                     self::computeComplexJoinID($searchopt[65]['joinparams']['beforejoin']
                                                          ['joinparams']).'`';

               $condition = "(";

               $condition .= " $requester_table.users_id = '".Session::getLoginUserID()."'
                              OR $observer_table.users_id = '".Session::getLoginUserID()."'
                              OR `glpi_tickets`.`users_id_recipient` = '".Session::getLoginUserID()."'";


               if (Session::haveRight("ticket", Ticket::READGROUP)) {
                  if (count($_SESSION['glpigroups'])) {
                     $condition .= " OR $requestergroup_table.`groups_id`
                                             IN (".implode(",",$_SESSION['glpigroups']).")";
                     $condition .= " OR $observergroup_table.`groups_id`
                                             IN (".implode(",",$_SESSION['glpigroups']).")";
                  }
               }

               if (Session::haveRight("ticket", Ticket::OWN)) {// Can own ticket : show assign to me
                  $condition .= " OR $assign_table.users_id = '".Session::getLoginUserID()."' ";
               }

               if (Session::haveRightsOr("ticket", array(Ticket::READMY, Ticket::READASSIGN))) { // show mine + assign to me

                  $condition .=" OR $assign_table.`users_id` = '".Session::getLoginUserID()."'";
                  if (count($_SESSION['glpigroups'])) {
                     $condition .= " OR $assigngroup_table.`groups_id`
                                             IN (".implode(",",$_SESSION['glpigroups']).")";
                  }
                  if (Session::haveRight('ticket', Ticket::ASSIGN)) {
                     $condition .= " OR `glpi_tickets`.`status`='".CommonITILObject::INCOMING."'";
                  }
               }

               if (Session::haveRightsOr('ticketvalidation',
                                         array(TicketValidation::VALIDATEINCIDENT,
                                               TicketValidation::VALIDATEREQUEST))) {
                  $condition .= " OR `glpi_ticketvalidations`.`users_id_validate`
                                          = '".Session::getLoginUserID()."'";
               }
               $condition .= ") ";
            }
            return $condition;

         default :
            // Plugin can override core definition for its type
            if ($plug = isPluginItemType($itemtype)) {
               $function = 'plugin_'.$plug['plugin'].'_addDefaultWhere';
               if (function_exists($function)) {
                  $out = $function($itemtype);
                  if (!empty($out)) {
                     return $out;
                  }
               }
            }
            return "";
      }
   }


   /**
    * Generic Function to add where to a request
    *
    * @param $link         link string
    * @param $nott         is it a negative search ?
    * @param $itemtype     item type
    * @param $ID           ID of the item to search
    * @param $searchtype   searchtype used (equals or contains)
    * @param $val          item num in the request
    * @param $meta         is a meta search (meta=2 in search.class.php) (default 0)
    *
    * @return select string
   **/
   static function addWhere($link, $nott, $itemtype, $ID, $searchtype, $val, $meta=0) {

      $searchopt = &self::getOptions($itemtype);
      $table     = $searchopt[$ID]["table"];
      $field     = $searchopt[$ID]["field"];

      $inittable = $table;
      $addtable  = '';
      if (($table != 'asset_types')
          && ($table != getTableForItemType($itemtype))
          && ($searchopt[$ID]["linkfield"] != getForeignKeyFieldForTable($table))) {
         $addtable = "_".$searchopt[$ID]["linkfield"];
         $table   .= $addtable;
      }

      if (isset($searchopt[$ID]['joinparams'])) {
         $complexjoin = self::computeComplexJoinID($searchopt[$ID]['joinparams']);

         if (!empty($complexjoin)) {
            $table .= "_".$complexjoin;
         }
      }

      if ($meta
          && (getTableForItemType($itemtype) != $table)) {
         $table .= "_".$itemtype;
      }

      // Hack to allow search by ID on every sub-table
      if (preg_match('/^\$\$\$\$([0-9]+)$/',$val,$regs)) {
         return $link." (`$table`.`id` ".($nott?"<>":"=").$regs[1]." ".
                         (($regs[1] == 0)?" OR `$table`.`id` IS NULL":'').") ";
      }

      // Preparse value
      if (isset($searchopt[$ID]["datatype"])) {
         switch ($searchopt[$ID]["datatype"]) {
            case "datetime" :
            case "date" :
            case "date_delay" :
               $force_day = true;
               if ($searchopt[$ID]["datatype"] == 'datetime') {
                  $force_day = false;
               }
               if (strstr($val,'BEGIN') || strstr($val,'LAST')) {
                  $force_day = true;
               }

               $val = Html::computeGenericDateTimeSearch($val, $force_day);

               break;
         }
      }
      switch ($searchtype) {
         case "contains" :
            $SEARCH = self::makeTextSearch($val, $nott);
            break;

         case "equals" :
            if ($nott) {
               $SEARCH = " <> '$val'";
            } else {
               $SEARCH = " = '$val'";
            }
            break;

         case "notequals" :
            if ($nott) {
               $SEARCH = " = '$val'";
            } else {
               $SEARCH = " <> '$val'";
            }
            break;

         case "under" :
            if ($nott) {
               $SEARCH = " NOT IN ('".implode("','",getSonsOf($inittable, $val))."')";
            } else {
               $SEARCH = " IN ('".implode("','",getSonsOf($inittable, $val))."')";
            }
            break;

         case "notunder" :
            if ($nott) {
               $SEARCH = " IN ('".implode("','",getSonsOf($inittable, $val))."')";
            } else {
               $SEARCH = " NOT IN ('".implode("','",getSonsOf($inittable, $val))."')";
            }
            break;

      }

      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $function = 'plugin_'.$plug['plugin'].'_addWhere';
         if (function_exists($function)) {
            $out = $function($link,$nott,$itemtype,$ID,$val);
            if (!empty($out)) {
               return $out;
            }
         }
      }

      switch ($inittable.".".$field) {
//          case "glpi_users_validation.name" :
         case "glpi_users.name" :
            if ($itemtype == 'User') { // glpi_users case / not link table
               if (in_array($searchtype, array('equals', 'notequals'))) {
                  return " $link `$table`.`id`".$SEARCH;
               }
               return self::makeTextCriteria("`$table`.`$field`", $val, $nott, $link);
            }
            if ($_SESSION["glpinames_format"] == User::FIRSTNAME_BEFORE) {
               $name1 = 'firstname';
               $name2 = 'realname';
            } else {
               $name1 = 'realname';
               $name2 = 'firstname';
            }

            if (in_array($searchtype, array('equals', 'notequals'))) {
               return " $link (`$table`.`id`".$SEARCH.
                               (($val == 0)?" OR `$table`.`id` IS NULL":'').') ';
            }
            $toadd   = '';

            $tmplink = 'OR';
            if ($nott) {
               $tmplink = 'AND';
            }

            if (($itemtype == 'Ticket') || ($itemtype == 'Problem')) {
               if (isset($searchopt[$ID]["joinparams"]["beforejoin"]["table"])
                   && isset($searchopt[$ID]["joinparams"]["beforejoin"]["joinparams"])
                   && (($searchopt[$ID]["joinparams"]["beforejoin"]["table"]
                         == 'glpi_tickets_users')
                       || ($searchopt[$ID]["joinparams"]["beforejoin"]["table"]
                             == 'glpi_problems_users')
                       || ($searchopt[$ID]["joinparams"]["beforejoin"]["table"]
                             == 'glpi_changes_users'))) {

                  $bj        = $searchopt[$ID]["joinparams"]["beforejoin"];
                  $linktable = $bj['table'].'_'.self::computeComplexJoinID($bj['joinparams']);
                  //$toadd     = "`$linktable`.`alternative_email` $SEARCH $tmplink ";
                  $toadd     = self::makeTextCriteria("`$linktable`.`alternative_email`", $val,
                                                      $nott, $tmplink);
               }
            }
            $toadd2 = '';
            if ($nott
                && ($val != 'NULL') && ($val != 'null')) {
               $toadd2 = " OR `$table`.`$field` IS NULL";
            }
            return $link." (((`$table`.`$name1` $SEARCH
                            $tmplink `$table`.`$name2` $SEARCH
                            $tmplink `$table`.`$field` $SEARCH
                            $tmplink CONCAT(`$table`.`$name1`, ' ', `$table`.`$name2`) $SEARCH )
                            $toadd2) $toadd)";


         case "glpi_groups.completename" :
            if ($val == 'mygroups') {
               switch ($searchtype) {
                  case 'equals' :
                     return " $link (`$table`.`id` IN ('".implode("','",
                                                                  $_SESSION['glpigroups'])."')) ";

                  case 'notequals' :
                     return " $link (`$table`.`id` NOT IN ('".implode("','",
                                                                      $_SESSION['glpigroups'])."')) ";

                  case 'under' :
                     $groups = $_SESSION['glpigroups'];
                     foreach ($_SESSION['glpigroups'] as $g) {
                        $groups += getSonsOf($inittable, $g);
                     }
                     $groups = array_unique($groups);
                     return " $link (`$table`.`id` IN ('".implode("','", $groups)."')) ";

                  case 'notunder' :
                     $groups = $_SESSION['glpigroups'];
                     foreach ($_SESSION['glpigroups'] as $g) {
                        $groups += getSonsOf($inittable, $g);
                     }
                     $groups = array_unique($groups);
                     return " $link (`$table`.`id` NOT IN ('".implode("','", $groups)."')) ";
               }
            }
            break;

         case "glpi_networkports.mac" :
            if ($itemtype == 'Computer') {
               return "$link (".self::makeTextCriteria("`glpi_items_devicenetworkcards`.`mac`",
                                                       $val, $nott,'').
                              self::makeTextCriteria("`$table`.`$field`", $val ,$nott, 'OR').")";
            }
            return self::makeTextCriteria("`$table`.`$field`", $val, $nott, $link);


         case "glpi_auth_tables.name" :
            $user_searchopt = self::getOptions('User');
            $tmplink        = 'OR';
            if ($nott) {
               $tmplink = 'AND';
            }
            return $link." (`glpi_authmails".$addtable."_".
                              self::computeComplexJoinID($user_searchopt[31]['joinparams'])."`.`name`
                           $SEARCH
                           $tmplink `glpi_authldaps".$addtable."_".
                              self::computeComplexJoinID($user_searchopt[30]['joinparams'])."`.`name`
                           $SEARCH ) ";

         case "glpi_ipaddresses.name" :
            $search  = array("/\&lt;/","/\&gt;/");
            $replace = array("<",">");
            $val     = preg_replace($search, $replace, $val);
            if (preg_match("/^\s*([<>])([=]*)[[:space:]]*([0-9\.]+)/",$val,$regs)) {
               if ($nott) {
                  if ($regs[1] == '<') {
                     $regs[1] = '>';
                  } else {
                     $regs[1] = '<';
                  }
               }
               $regs[1] .= $regs[2];
               return $link." (INET_ATON(`$table`.`$field`) ".$regs[1]." INET_ATON('".$regs[3]."')) ";
            }
            break;

         case "glpi_tickets.status" :
         case "glpi_problems.status" :
         case "glpi_changes.status" :
            if ($val == 'all') {
               return "";
            }
            $tocheck = array();
            if ($item = getItemForItemtype($itemtype)) {
               switch ($val) {
                  case 'process' :
                     $tocheck = $item->getProcessStatusArray();
                     break;

                  case 'notclosed' :
                     $tocheck = $item->getAllStatusArray();
                     foreach ($item->getClosedStatusArray() as $status) {
                        if (isset($tocheck[$status])) {
                           unset($tocheck[$status]);
                        }
                     }
                     $tocheck = array_keys($tocheck);
                     break;

                  case 'old' :
                     $tocheck = array_merge($item->getSolvedStatusArray(),
                                            $item->getClosedStatusArray());
                     break;

                  case 'notold' :
                     $tocheck = $item->getAllStatusArray();
                     foreach ($item->getSolvedStatusArray() as $status) {
                        if (isset($tocheck[$status])) {
                           unset($tocheck[$status]);
                        }
                     }
                     foreach ($item->getClosedStatusArray() as $status) {
                        if (isset($tocheck[$status])) {
                           unset($tocheck[$status]);
                        }
                     }
                     $tocheck = array_keys($tocheck);
                     break;
               }
            }

            if (count($tocheck) == 0) {
               $statuses = $item->getAllStatusArray();
               if (isset($statuses[$val])) {
                  $tocheck = array($val);
               }
            }

            if (count($tocheck)) {
               if ($nott) {
                  return $link." `$table`.`$field` NOT IN ('".implode("','",$tocheck)."')";
               }
               return $link." `$table`.`$field` IN ('".implode("','",$tocheck)."')";
            }
            break;

         case "glpi_tickets_tickets.tickets_id_1" :
            $tmplink = 'OR';
            $compare = '=';
            if ($nott) {
               $tmplink = 'AND';
               $compare = '<>';
            }
            $toadd2 = '';
            if ($nott
                && ($val != 'NULL') && ($val != 'null')) {
               $toadd2 = " OR `$table`.`$field` IS NULL";
            }

            return $link." (((`$table`.`tickets_id_1` $compare '$val'
                              $tmplink `$table`.`tickets_id_2` $compare '$val')
                             AND `glpi_tickets`.`id` <> '$val')
                            $toadd2)";

         case "glpi_tickets.priority" :
         case "glpi_tickets.impact" :
         case "glpi_tickets.urgency" :
         case "glpi_problems.priority" :
         case "glpi_problems.impact" :
         case "glpi_problems.urgency" :
         case "glpi_changes.priority" :
         case "glpi_changes.impact" :
         case "glpi_changes.urgency" :
         case "glpi_projects.priority" :
            if (is_numeric($val)) {
               if ($val > 0) {
                  return $link." `$table`.`$field` = '$val'";
               }
               if ($val < 0) {
                  return $link." `$table`.`$field` >= '".abs($val)."'";
               }
               // Show all
               return $link." `$table`.`$field` >= '0' ";
            }
            return "";

         case "glpi_tickets.global_validation" :
         case "glpi_ticketvalidations.status" :
            if ($val == 'all') {
               return "";
            }
            $tocheck = array();
            switch ($val) {
               case 'can' :
                  $tocheck = CommonITILValidation::getCanValidationStatusArray();
                  break;

               case 'all' :
                  $tocheck = CommonITILValidation::getAllValidationStatusArray();
                  break;
               }
            if (count($tocheck) == 0) {
               $tocheck = array($val);
            }
            if (count($tocheck)) {
               if ($nott) {
                  return $link." `$table`.`$field` NOT IN ('".implode("','",$tocheck)."')";
               }
               return $link." `$table`.`$field` IN ('".implode("','",$tocheck)."')";
            }
            break;

      }

      //// Default cases

      // Link with plugin tables
      if (preg_match("/^glpi_plugin_([a-z0-9]+)/", $inittable, $matches)) {
         if (count($matches) == 2) {
            $plug     = $matches[1];
            $function = 'plugin_'.$plug.'_addWhere';
            if (function_exists($function)) {
               $out = $function($link, $nott, $itemtype, $ID, $val);
               if (!empty($out)) {
                  return $out;
               }
            }
         }
      }

      $tocompute      = "`$table`.`$field`";
      $tocomputetrans = "`".$table."_trans`.`value`";
      if (isset($searchopt[$ID]["computation"])) {
         $tocompute = $searchopt[$ID]["computation"];
         $tocompute = str_replace("TABLE", "`$table`", $tocompute);
      }

      // Preformat items
      if (isset($searchopt[$ID]["datatype"])) {
         switch ($searchopt[$ID]["datatype"]) {
            case "itemtypename" :
               if (in_array($searchtype, array('equals', 'notequals'))) {
                  return " $link (`$table`.`$field`".$SEARCH.') ';
               }
               break;

            case "itemlink" :
               if (in_array($searchtype, array('equals', 'notequals'))) {
                  return " $link (`$table`.`id`".$SEARCH.') ';
               }
               break;

            case "datetime" :
            case "date" :
            case "date_delay" :
               if ($searchopt[$ID]["datatype"] == 'datetime') {
                  // Specific search for datetime
                  if (in_array($searchtype, array('equals', 'notequals'))) {
                     $val = preg_replace("/:00$/",'',$val);
                     $val = '^'.$val;
                     if ($searchtype == 'notequals') {
                        $nott = !$nott;
                     }
                     return self::makeTextCriteria("`$table`.`$field`", $val, $nott, $link);
                  }
               }
               if ($searchtype == 'lessthan') {
                 $val = '<'.$val;
               }
               if ($searchtype == 'morethan') {
                 $val = '>'.$val;
               }
               if ($searchtype) {
                  $date_computation = $tocompute;
               }
               $search_unit = ' MONTH ';
               if (isset($searchopt[$ID]['searchunit'])) {
                  $search_unit = $searchopt[$ID]['searchunit'];
               }
               if ($searchopt[$ID]["datatype"]=="date_delay") {
                  $delay_unit = ' MONTH ';
                  if (isset($searchopt[$ID]['delayunit'])) {
                     $delay_unit = $searchopt[$ID]['delayunit'];
                  }
                  $add_minus = '';
                  if (isset($searchopt[$ID]["datafields"][3])) {
                     $add_minus = "-`$table`.`".$searchopt[$ID]["datafields"][3]."`";
                  }
                  $date_computation = "ADDDATE(`$table`.".$searchopt[$ID]["datafields"][1].",
                                               INTERVAL (`$table`.".$searchopt[$ID]["datafields"][2]."
                                                         $add_minus)
                                               $delay_unit)";
               }
               if (in_array($searchtype, array('equals', 'notequals'))) {
                  return " $link ($date_computation ".$SEARCH.') ';
               }
               $search  = array("/\&lt;/","/\&gt;/");
               $replace = array("<",">");
               $val     = preg_replace($search,$replace,$val);
               if (preg_match("/^\s*([<>=]+)(.*)/",$val,$regs)) {
                  if (is_numeric($regs[2])) {
                     return $link." $date_computation ".$regs[1]."
                            ADDDATE(NOW(), INTERVAL ".$regs[2]." $search_unit) ";
                  }
                  // ELSE Reformat date if needed
                  $regs[2] = preg_replace('@(\d{1,2})(-|/)(\d{1,2})(-|/)(\d{4})@','\5-\3-\1',
                                          $regs[2]);
                  if (preg_match('/[0-9]{2,4}-[0-9]{1,2}-[0-9]{1,2}/', $regs[2])) {
                     return $link." $date_computation ".$regs[1]." '".$regs[2]."'";
                  }
                  return "";
               }
               // ELSE standard search
               // Date format modification if needed
               $val = preg_replace('@(\d{1,2})(-|/)(\d{1,2})(-|/)(\d{4})@','\5-\3-\1', $val);
               return self::makeTextCriteria($date_computation, $val, $nott, $link);

            case "right" :
               if ($searchtype == 'notequals') {
                  $nott = !$nott;
               }
               return $link. ($nott?' NOT':'')." ($tocompute & '$val') ";

            case "bool" :
               if (!is_numeric($val)) {
                  if (strcasecmp($val,__('No')) == 0) {
                     $val = 0;
                  } else if (strcasecmp($val,__('Yes')) == 0) {
                     $val = 1;
                  }
               }
               // No break here : use number comparaison case

            case "count" :
            case "number" :
            case "decimal" :
            case "timestamp" :
               $search  = array("/\&lt;/", "/\&gt;/");
               $replace = array("<", ">");
               $val     = preg_replace($search, $replace, $val);

               if (preg_match("/([<>])([=]*)[[:space:]]*([0-9]+)/", $val, $regs)) {
                  if ($nott) {
                     if ($regs[1] == '<') {
                        $regs[1] = '>';
                     } else {
                        $regs[1] = '<';
                     }
                  }
                  $regs[1] .= $regs[2];
                  return $link." ($tocompute ".$regs[1]." ".$regs[3].") ";
               }
               if (is_numeric($val)) {
                  if (isset($searchopt[$ID]["width"])) {
                     $ADD = "";
                     if ($nott
                         && ($val != 'NULL') && ($val != 'null')) {
                        $ADD = " OR $tocompute IS NULL";
                     }
                     if ($nott) {
                        return $link." ($tocompute < ".(intval($val) - $searchopt[$ID]["width"])."
                                        OR $tocompute > ".(intval($val) + $searchopt[$ID]["width"])."
                                        $ADD) ";
                     }
                     return $link." (($tocompute >= ".(intval($val) - $searchopt[$ID]["width"])."
                                      AND $tocompute <= ".(intval($val) + $searchopt[$ID]["width"]).")
                                     $ADD) ";
                  }
                  if (!$nott) {
                     return " $link ($tocompute = ".(intval($val)).") ";
                  }
                  return " $link ($tocompute <> ".(intval($val)).") ";
               }
               break;
         }
      }

      // Default case
      if (in_array($searchtype, array('equals', 'notequals','under', 'notunder'))) {

         if ((!isset($searchopt[$ID]['searchequalsonfield'])
              || !$searchopt[$ID]['searchequalsonfield'])
            && ($table != getTableForItemType($itemtype)
                || ($itemtype == 'AllAssets'))) {
            $out = " $link (`$table`.`id`".$SEARCH;
         } else {
            $out = " $link (`$table`.`$field`".$SEARCH;
         }
         if ($searchtype == 'notequals') {
            $nott = !$nott;
         }
         // Add NULL if $val = 0 and not negative search
         // Or negative search on real value
         if ((!$nott && ($val == 0))
             || ($nott && ($val != 0))) {
            $out .= " OR `$table`.`id` IS NULL";
         }
         $out .= ')';
         return $out;
      }
      $transitemtype = getItemTypeForTable($inittable);
      if (Session::haveTranslations($transitemtype, $field)) {
         return " $link (".self::makeTextCriteria($tocompute,$val,$nott,'')."
                          OR ".self::makeTextCriteria($tocomputetrans,$val,$nott,'').")";
      }

      return self::makeTextCriteria($tocompute,$val,$nott,$link);
   }


   /**
    * Generic Function to add Default left join to a request
    *
    * @param $itemtype                    reference ID
    * @param $ref_table                   reference table
    * @param &$already_link_tables  array of tables already joined
    *
    * @return Left join string
   **/
   static function addDefaultJoin($itemtype, $ref_table, array &$already_link_tables) {

      switch ($itemtype) {
         // No link
          case 'User' :
             return self::addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                      "glpi_profiles_users", "profiles_users_id", 0, 0,
                                      array('jointype' => 'child'));

         case 'Reminder' :
            return Reminder::addVisibilityJoins();

         case 'ProjectTask' :
            // Same structure in addDefaultWhere
            $out  = '';
            $out .= self::addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                      "glpi_projecttaskteams", "projecttaskteams_id", 0, 0,
                                      array('jointype' => 'child'));
            return $out;

         case 'Project' :
            // Same structure in addDefaultWhere
            $out = '';
            if (!Session::haveRight("project", Ticket::READALL)) {
               $out .= self::addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                          "glpi_projectteams", "projectteams_id", 0, 0,
                                          array('jointype' => 'child'));
            }
            return $out;

         case 'Ticket' :
            // Same structure in addDefaultWhere
            $out = '';
            if (!Session::haveRight("ticket", Ticket::READALL)) {
               $searchopt = &self::getOptions($itemtype);

               // show mine : requester
               $out .= self::addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                         "glpi_tickets_users", "tickets_users_id", 0, 0,
                                         $searchopt[4]['joinparams']['beforejoin']['joinparams']);

               if (Session::haveRight("ticket", Ticket::READGROUP)) {
                  if (count($_SESSION['glpigroups'])) {
                     $out .= self::addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                               "glpi_groups_tickets", "groups_tickets_id", 0, 0,
                                               $searchopt[71]['joinparams']['beforejoin']
                                                         ['joinparams']);
                  }
               }

               // show mine : observer
               $out .= self::addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                         "glpi_tickets_users", "tickets_users_id", 0, 0,
                                         $searchopt[66]['joinparams']['beforejoin']['joinparams']);

               if (count($_SESSION['glpigroups'])) {
                  $out .= self::addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                            "glpi_groups_tickets", "groups_tickets_id", 0, 0,
                                            $searchopt[65]['joinparams']['beforejoin']['joinparams']);
               }

               if (Session::haveRight("ticket", Ticket::OWN)) { // Can own ticket : show assign to me
                  $out .= self::addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                            "glpi_tickets_users", "tickets_users_id", 0, 0,
                                            $searchopt[5]['joinparams']['beforejoin']['joinparams']);
               }

               if (Session::haveRightsOr("ticket", array(Ticket::READMY, Ticket::READASSIGN))) { // show mine + assign to me
                  $out .= self::addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                            "glpi_tickets_users", "tickets_users_id", 0, 0,
                                            $searchopt[5]['joinparams']['beforejoin']['joinparams']);

                  if (count($_SESSION['glpigroups'])) {
                     $out .= self::addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                               "glpi_groups_tickets", "groups_tickets_id", 0, 0,
                                               $searchopt[8]['joinparams']['beforejoin']
                                                         ['joinparams']);
                  }
               }

               if (Session::haveRightsOr('ticketvalidation',
                                         array(TicketValidation::VALIDATEINCIDENT,
                                               TicketValidation::VALIDATEREQUEST))) {
                  $out .= self::addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                            "glpi_ticketvalidations", "ticketvalidations_id", 0, 0,
                                            $searchopt[58]['joinparams']['beforejoin']['joinparams']);
               }
            }
            return $out;

         default :
            // Plugin can override core definition for its type
            if ($plug = isPluginItemType($itemtype)) {
               $function = 'plugin_'.$plug['plugin'].'_addDefaultJoin';
               if (function_exists($function)) {
                  $out = $function($itemtype, $ref_table, $already_link_tables);
                  if (!empty($out)) {
                     return $out;
                  }
               }
            }


            return "";
      }
   }


   /**
    * Generic Function to add left join to a request
    *
    * @param $itemtype                    item type
    * @param $ref_table                   reference table
    * @param $already_link_tables  array  of tables already joined
    * @param $new_table                   new table to join
    * @param $linkfield                   linkfield for LeftJoin
    * @param $meta                        is it a meta item ? (default 0)
    * @param $meta_type                   meta type table (default 0)
    * @param $joinparams           array  join parameters (condition / joinbefore...)
    * @param $field                string field to display (needed for translation join) (default '')
    *
    * @return Left join string
   **/
   static function addLeftJoin($itemtype, $ref_table, array &$already_link_tables, $new_table,
                               $linkfield, $meta=0, $meta_type=0, $joinparams=array(), $field='') {
      global $CFG_GLPI;

      // Rename table for meta left join
      $AS = "";
      $nt = $new_table;
      $cleannt    = $nt;

      // Virtual field no link
      if ($linkfield == '_virtual') {
         return false;
      }

      // Multiple link possibilies case
//       if ($new_table=="glpi_users"
//           || $new_table=="glpi_groups"
//           || $new_table=="glpi_users_validation") {
      if (!empty($linkfield) && ($linkfield != getForeignKeyFieldForTable($new_table))) {
         $nt .= "_".$linkfield;
         $AS  = " AS ".$nt;
      }

      $complexjoin = self::computeComplexJoinID($joinparams);

      if (!empty($complexjoin)) {
         $nt .= "_".$complexjoin;
         $AS  = " AS ".$nt;
      }

//       }

      $addmetanum = "";
      $rt         = $ref_table;
      $cleanrt    = $rt;
      if ($meta) {
         $addmetanum = "_".$meta_type;
         $AS         = " AS $nt$addmetanum";
         $nt         = $nt.$addmetanum;
      }


      // Auto link
      if (($ref_table == $new_table)
          && empty($complexjoin)) {
         return "";
      }

      // Do not take into account standard linkfield
      $tocheck = $nt.".".$linkfield;
      if ($linkfield == getForeignKeyFieldForTable($new_table)) {
         $tocheck = $nt;
      }

      if (in_array($tocheck,$already_link_tables)) {
         return "";
      }
      array_push($already_link_tables, $tocheck);

      $specific_leftjoin = '';

      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $function = 'plugin_'.$plug['plugin'].'_addLeftJoin';
         if (function_exists($function)) {
            $specific_leftjoin = $function($itemtype, $ref_table, $new_table, $linkfield,
                                           $already_link_tables);
         }
      }

      // Link with plugin tables : need to know left join structure
      if (empty($specific_leftjoin)
          && preg_match("/^glpi_plugin_([a-z0-9]+)/", $new_table, $matches)) {
         if (count($matches) == 2) {
            $function = 'plugin_'.$matches[1].'_addLeftJoin';
            if (function_exists($function)) {
               $specific_leftjoin = $function($itemtype, $ref_table, $new_table, $linkfield,
                                              $already_link_tables);
            }
         }
      }
      if (!empty($linkfield)) {
         $before = '';

         if (isset($joinparams['beforejoin']) && is_array($joinparams['beforejoin']) ) {

            if (isset($joinparams['beforejoin']['table'])) {
               $joinparams['beforejoin'] = array($joinparams['beforejoin']);
            }

            foreach ($joinparams['beforejoin'] as $tab) {
               if (isset($tab['table'])) {
                  $intertable = $tab['table'];
                  if (isset($tab['linkfield'])) {
                     $interlinkfield = $tab['linkfield'];
                  } else {
                     $interlinkfield = getForeignKeyFieldForTable($intertable);
                  }

                  $interjoinparams = array();
                  if (isset($tab['joinparams'])) {
                     $interjoinparams = $tab['joinparams'];
                  }
                  $before .= self::addLeftJoin($itemtype, $rt, $already_link_tables, $intertable,
                                               $interlinkfield, $meta, $meta_type, $interjoinparams);
               }

               // No direct link with the previous joins
               if (!isset($tab['joinparams']['nolink']) || !$tab['joinparams']['nolink']) {
                  $cleanrt     = $intertable;
                  $complexjoin = self::computeComplexJoinID($interjoinparams);
                  if (!empty($complexjoin)) {
                     $intertable .= "_".$complexjoin;
                  }
                  $rt = $intertable.$addmetanum;
               }
            }
         }

         $addcondition = '';
         if (isset($joinparams['condition'])) {
            $from         = array("`REFTABLE`", "REFTABLE", "`NEWTABLE`", "NEWTABLE");
            $to           = array("`$rt`", "`$rt`", "`$nt`", "`$nt`");
            $addcondition = str_replace($from, $to, $joinparams['condition']);
            $addcondition = $addcondition." ";
         }

         if (!isset($joinparams['jointype'])) {
            $joinparams['jointype'] = 'standard';
         }

         if (empty($specific_leftjoin)) {
            switch ($new_table) {
               // No link
               case "glpi_auth_tables" :
                     $user_searchopt     = self::getOptions('User');

                     $specific_leftjoin  = self::addLeftJoin($itemtype, $rt, $already_link_tables,
                                                             "glpi_authldaps", 'auths_id', 0, 0,
                                                             $user_searchopt[30]['joinparams']);
                     $specific_leftjoin .= self::addLeftJoin($itemtype, $rt, $already_link_tables,
                                                             "glpi_authmails", 'auths_id', 0, 0,
                                                             $user_searchopt[31]['joinparams']);
                     break;
            }
         }

         if (empty($specific_leftjoin)) {
            switch ($joinparams['jointype']) {
               case 'child' :
                  $linkfield = getForeignKeyFieldForTable($cleanrt);
                  if (isset($joinparams['linkfield'])) {
                     $linkfield = $joinparams['linkfield'];
                  }

                  // Child join
                  $specific_leftjoin = " LEFT JOIN `$new_table` $AS
                                             ON (`$rt`.`id` = `$nt`.`$linkfield`
                                                 $addcondition)";
                  break;

               case 'item_item' :
                  // Item_Item join
                  $specific_leftjoin = " LEFT JOIN `$new_table` $AS
                                          ON ((`$rt`.`id`
                                                = `$nt`.`".getForeignKeyFieldForTable($cleanrt)."_1`
                                               OR `$rt`.`id`
                                                 = `$nt`.`".getForeignKeyFieldForTable($cleanrt)."_2`)
                                              $addcondition)";
                  break;

               case 'item_item_revert' :
                  // Item_Item join reverting previous item_item
                  $specific_leftjoin = " LEFT JOIN `$new_table` $AS
                                          ON ((`$nt`.`id`
                                                = `$rt`.`".getForeignKeyFieldForTable($cleannt)."_1`
                                               OR `$nt`.`id`
                                                 = `$rt`.`".getForeignKeyFieldForTable($cleannt)."_2`)
                                              $addcondition)";
                  break;

               case "itemtype_item" :
                  $used_itemtype = $itemtype;
                  if (isset($joinparams['specific_itemtype'])
                      && !empty($joinparams['specific_itemtype'])) {
                     $used_itemtype = $joinparams['specific_itemtype'];
                  }
                  // Itemtype join
                  $specific_leftjoin = " LEFT JOIN `$new_table` $AS
                                          ON (`$rt`.`id` = `$nt`.`items_id`
                                              AND `$nt`.`itemtype` = '$used_itemtype'
                                              $addcondition) ";
                  break;

               default :
                  // Standard join
                  $specific_leftjoin = "LEFT JOIN `$new_table` $AS
                                          ON (`$rt`.`$linkfield` = `$nt`.`id`
                                              $addcondition)";
                  $transitemtype = getItemTypeForTable($new_table);
                  if (Session::haveTranslations($transitemtype, $field)) {
                     $transAS            = $nt.'_trans';
                     $specific_leftjoin .= "LEFT JOIN `glpi_dropdowntranslations` AS $transAS
                                             ON (`$transAS`.`itemtype` = '$transitemtype'
                                                 AND `$transAS`.`items_id` = `$nt`.`id`
                                                 AND `$transAS`.`language` = '".
                                                       $_SESSION['glpilanguage']."'
                                                 AND `$transAS`.`field` = '$field')";
                  }
                  break;
            }
         }
         return $before.$specific_leftjoin;
      }
   }


   /**
    * Generic Function to add left join for meta items
    *
    * @param $from_type                   reference item type ID
    * @param $to_type                     item type to add
    * @param $already_link_tables2  array of tables already joined
    * @param $nullornott                  Used LEFT JOIN (null generation)
    *                                     or INNER JOIN for strict join
    *
    * @return Meta Left join string
   **/
   static function addMetaLeftJoin($from_type, $to_type, array &$already_link_tables2,
                                   $nullornott) {

      $LINK = " INNER JOIN ";
      if ($nullornott) {
         $LINK = " LEFT JOIN ";
      }

      switch ($from_type) {
         case 'Ticket' :
            $totable = getTableForItemType($to_type);
            array_push($already_link_tables2,$totable);
            return " $LINK `$totable`
                        ON (`$totable`.`id` = `glpi_tickets`.`items_id`
                            AND `glpi_tickets`.`itemtype` = '$to_type')";

         case 'Computer' :
            switch ($to_type) {
               case 'Printer' :
                  array_push($already_link_tables2, getTableForItemType($to_type));
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK `glpi_computers_items` AS glpi_computers_items_$to_type
                              ON (`glpi_computers_items_$to_type`.`computers_id`
                                       = `glpi_computers`.`id`
                                  AND `glpi_computers_items_$to_type`.`itemtype` = '$to_type')
                           $LINK `glpi_printers`
                              ON (`glpi_computers_items_$to_type`.`items_id` = `glpi_printers`.`id`) ";

               case 'Monitor' :
                  array_push($already_link_tables2, getTableForItemType($to_type));
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK `glpi_computers_items` AS glpi_computers_items_$to_type
                              ON (`glpi_computers_items_$to_type`.`computers_id`
                                       = `glpi_computers`.`id`
                                  AND `glpi_computers_items_$to_type`.`itemtype` = '$to_type')
                           $LINK `glpi_monitors`
                              ON (`glpi_computers_items_$to_type`.`items_id` = `glpi_monitors`.`id`) ";

               case 'Peripheral' :
                  array_push($already_link_tables2, getTableForItemType($to_type));
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK `glpi_computers_items` AS glpi_computers_items_$to_type
                              ON (`glpi_computers_items_$to_type`.`computers_id`
                                       = `glpi_computers`.`id`
                                  AND `glpi_computers_items_$to_type`.`itemtype` = '$to_type')
                           $LINK `glpi_peripherals`
                              ON (`glpi_computers_items_$to_type`.`items_id`
                                       = `glpi_peripherals`.`id`) ";

               case 'Phone' :
                  array_push($already_link_tables2,getTableForItemType($to_type));
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK `glpi_computers_items` AS glpi_computers_items_$to_type
                              ON (`glpi_computers_items_$to_type`.`computers_id`
                                       = `glpi_computers`.`id`
                                  AND `glpi_computers_items_$to_type`.`itemtype` = '$to_type')
                           $LINK `glpi_phones`
                              ON (`glpi_computers_items_$to_type`.`items_id` = `glpi_phones`.`id`) ";

               case 'Software' :
                  /// TODO: link licenses via installed software OR by affected/computers_id ???
                  array_push($already_link_tables2,getTableForItemType($to_type));
                  array_push($already_link_tables2,"glpi_softwareversions_$to_type");
                  array_push($already_link_tables2,"glpi_softwarelicenses_$to_type");
                  return " $LINK `glpi_computers_softwareversions`
                                    AS glpi_computers_softwareversions_$to_type
                              ON (`glpi_computers_softwareversions_$to_type`.`computers_id`
                                       = `glpi_computers`.`id`)
                           $LINK `glpi_softwareversions` AS glpi_softwareversions_$to_type
                              ON (`glpi_computers_softwareversions_$to_type`.`softwareversions_id`
                                       = `glpi_softwareversions_$to_type`.`id`)
                           $LINK `glpi_softwares`
                              ON (`glpi_softwareversions_$to_type`.`softwares_id`
                                       = `glpi_softwares`.`id`)
                           LEFT JOIN `glpi_softwarelicenses` AS glpi_softwarelicenses_$to_type
                              ON (`glpi_softwares`.`id`
                                       = `glpi_softwarelicenses_$to_type`.`softwares_id`".
                                  getEntitiesRestrictRequest(' AND',
                                                             "glpi_softwarelicenses_$to_type",
                                                             '', '', true).") ";
            }
            break;

         case 'Monitor' :
            switch ($to_type) {
               case 'Computer' :
                  array_push($already_link_tables2, getTableForItemType($to_type));
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK `glpi_computers_items` AS glpi_computers_items_$to_type
                              ON (`glpi_computers_items_$to_type`.`items_id` = `glpi_monitors`.`id`
                                  AND `glpi_computers_items_$to_type`.`itemtype` = '$from_type')
                           $LINK `glpi_computers`
                              ON (`glpi_computers_items_$to_type`.`computers_id`
                                       = `glpi_computers`.`id`) ";
            }
            break;

         case 'Printer' :
            switch ($to_type) {
               case 'Computer' :
                  array_push($already_link_tables2, getTableForItemType($to_type));
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK `glpi_computers_items` AS glpi_computers_items_$to_type
                              ON (`glpi_computers_items_$to_type`.`items_id` = `glpi_printers`.`id`
                                  AND `glpi_computers_items_$to_type`.`itemtype` = '$from_type')
                           $LINK `glpi_computers`
                              ON (`glpi_computers_items_$to_type`.`computers_id`
                                       = `glpi_computers`.`id` ".
                                  getEntitiesRestrictRequest("AND", 'glpi_computers').") ";
            }
            break;

         case 'Peripheral' :
            switch ($to_type) {
               case 'Computer' :
                  array_push($already_link_tables2,getTableForItemType($to_type));
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK `glpi_computers_items` AS glpi_computers_items_$to_type
                              ON (`glpi_computers_items_$to_type`.`items_id`
                                       = `glpi_peripherals`.`id`
                                  AND `glpi_computers_items_$to_type`.`itemtype` = '$from_type')
                           $LINK `glpi_computers`
                              ON (`glpi_computers_items_$to_type`.`computers_id`
                                       = `glpi_computers`.`id`) ";
            }
            break;

         case 'Phone' :
            switch ($to_type) {
               case 'Computer' :
                  array_push($already_link_tables2,getTableForItemType($to_type));
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK `glpi_computers_items` AS glpi_computers_items_$to_type
                              ON (`glpi_computers_items_$to_type`.`items_id` = `glpi_phones`.`id`
                                  AND `glpi_computers_items_$to_type`.`itemtype` = '$from_type')
                           $LINK `glpi_computers`
                              ON (`glpi_computers_items_$to_type`.`computers_id`
                                       = `glpi_computers.id`) ";
            }
            break;

         case 'Software' :
            switch ($to_type) {
               case 'Computer' :
                  array_push($already_link_tables2,getTableForItemType($to_type));
                  array_push($already_link_tables2,"glpi_softwareversions_$to_type");
                  array_push($already_link_tables2,"glpi_softwareversions_$to_type");
                  return " $LINK `glpi_softwareversions` AS glpi_softwareversions_$to_type
                              ON (`glpi_softwareversions_$to_type`.`softwares_id`
                                       = `glpi_softwares`.`id`)
                           $LINK `glpi_computers_softwareversions`
                                    AS glpi_computers_softwareversions_$to_type
                              ON (`glpi_computers_softwareversions_$to_type`.`softwareversions_id`
                                       = `glpi_softwareversions_$to_type`.`id`)
                           $LINK `glpi_computers`
                              ON (`glpi_computers_softwareversions_$to_type`.`computers_id`
                                       = `glpi_computers`.`id` ".
                                  getEntitiesRestrictRequest("AND", 'glpi_computers').") ";
            }
            break;
      }
   }


   /**
    * Generic Function to display Items
    *
    * @param $itemtype           item type
    * @param $ID                 ID of the SEARCH_OPTION item
    * @param $data         array retrieved data array
    * @param $num                number of the displayed item (default 0)
    *
    * @return string to print
   **/
   static function displayConfigItem($itemtype, $ID, $data=array(), $num=0) {

      $searchopt  = &self::getOptions($itemtype);

      $NAME       = "ITEM_";
      $table      = $searchopt[$ID]["table"];
      $field      = $searchopt[$ID]["field"];

      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $function = 'plugin_'.$plug['plugin'].'_displayConfigItem';
         if (function_exists($function)) {
            $out = $function($itemtype, $ID, $data, $num);
            if (!empty($out)) {
               return $out;
            }
         }
      }


      switch ($table.".".$field) {
//          case "glpi_computers.date_mod" :
//          case "glpi_printers.date_mod" :
//          case "glpi_networkequipments.date_mod" :
//          case "glpi_peripherals.date_mod" :
//          case "glpi_phones.date_mod" :
//          case "glpi_softwares.date_mod" :
//          case "glpi_monitors.date_mod" :
//          case "glpi_documents.date_mod" :
//          case "glpi_users.last_login" :
//          case "glpi_users.date_mod" :
//             return " class='center'";

         case "glpi_tickets.priority" :
         case "glpi_problems.priority" :
            return " style=\"background-color:".$_SESSION["glpipriority_".$data[$NAME.$num]].";\" ";

         case "glpi_tickets.due_date" :
         case "glpi_problems.due_date" :
         case "glpi_changes.due_date" :
            if (($ID <> 151) && !empty($data[$NAME.$num])
                && ($data[$NAME.$num.'_status'] != CommonITILObject::WAITING)
                && ($data[$NAME.$num] < $_SESSION['glpi_currenttime'])) {
               return " style=\"background-color: #cf9b9b\" ";
            }

         case "glpi_projectstates.color" :
            return " style=\"background-color:".$data[$NAME.$num].";\" ";

         default :
            return "";
      }
   }


   /**
    * Generic Function to display Items
    *
    * @param $itemtype              item type
    * @param $ID                    ID of the SEARCH_OPTION item
    * @param $data            array containing data results
    * @param $num                   item num in the request
    * @param $meta                  is a meta item ? (default 0)
    * @param $addobjectparams array added parameters for union search
    *
    * @return string to print
   **/
   static function giveItem($itemtype, $ID, array $data, $num, $meta=0,
                            array $addobjectparams=array()) {
      global $CFG_GLPI;

      $searchopt = &self::getOptions($itemtype);
      if (isset($CFG_GLPI["union_search_type"][$itemtype])
          && ($CFG_GLPI["union_search_type"][$itemtype] == $searchopt[$ID]["table"])) {

         if (isset($searchopt[$ID]['addobjectparams'])
             && $searchopt[$ID]['addobjectparams']) {
            return self::giveItem($data["TYPE"], $ID, $data, $num, $meta,
                                  $searchopt[$ID]['addobjectparams']);
         }

         return self::giveItem($data["TYPE"], $ID, $data, $num, $meta);
      }

      if (count($addobjectparams)) {
         $searchopt[$ID] = array_merge($searchopt[$ID], $addobjectparams);
      }
      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $function = 'plugin_'.$plug['plugin'].'_giveItem';
         if (function_exists($function)) {
            $out = $function($itemtype, $ID, $data, $num);
            if (!empty($out)) {
               return $out;
            }
         }
      }

      $NAME = "ITEM_";
//       if ($meta) {
//          $NAME = "META_";
//       }
      if (isset($searchopt[$ID]["table"])) {
         $table     = $searchopt[$ID]["table"];
         $field     = $searchopt[$ID]["field"];
         $linkfield = $searchopt[$ID]["linkfield"];


         /// TODO try to clean all specific cases using SpecificToDisplay

         switch ($table.'.'.$field) {
            case "glpi_users.name" :
               // USER search case
               if (($itemtype != 'User')
                   && isset($searchopt[$ID]["forcegroupby"]) && $searchopt[$ID]["forcegroupby"]) {

                  $out           = "";
                  $split         = explode("$$$$",$data[$NAME.$num]);
                  $count_display = 0;
                  $added         = array();

                  $showuserlink = 0;
                  if (Session::haveRight('user', READ)) {
                     $showuserlink = 1;
                  }

                  for ($k=0 ; $k<count($split) ; $k++) {
                     if ($split[$k] > 0) {
                        if ($count_display) {
                           $out .= self::LBBR;
                        }
                        $count_display++;
                        if ($itemtype == 'Ticket') {
                           $userdata = getUserName($split[$k],2);
                           $tooltip = "";
                           if (Session::haveRight('user', READ)) {
                              $tooltip = Html::showToolTip($userdata["comment"],
                                                           array('link'    => $userdata["link"],
                                                                 'display' => false));
                           }
                           $out .= sprintf(__('%1$s %2$s'), $userdata['name'], $tooltip);
                        } else {
                           $out .= getUserName($split[$k], $showuserlink);
                        }
                     }
                  }

                  // Manage alternative_email for tickets_users
                  if (($itemtype == 'Ticket')
                      && isset($data[$NAME.$num.'_2'])) {
                     $split = explode("$$$$",$data[$NAME.$num.'_2']);
                     for ($k=0 ; $k<count($split) ; $k++) {
                        $split2 = explode(" ",$split[$k]);
                        if ((count($split2) == 2) && ($split2[0] == 0) && !empty($split2[1])) {
                           if ($count_display) {
                              $out .= self::LBBR;
                           }
                           $count_display++;
                           $out .= "<a href='mailto:".$split2[1]."'>".$split2[1]."</a>";
                        }
                     }
                  }

                  return $out;
               }
               if ($itemtype != 'User') {
                  $toadd = '';
                  if (($itemtype == 'Ticket')
                      && ($data[$NAME.$num."_3"] > 0)) {
                     $userdata = getUserName($data[$NAME.$num."_3"], 2);
                     $toadd    = Html::showToolTip($userdata["comment"],
                                                   array('link'    => $userdata["link"],
                                                         'display' => false));
                  }
                  $usernameformat = formatUserName($data[$NAME.$num."_3"], $data[$NAME.$num],
                                                   $data[$NAME.$num."_2"], $data[$NAME.$num."_4"],
                                                   1);
                  return sprintf(__('%1$s %2$s'), $usernameformat, $toadd);
               }
               break;

            case "glpi_profiles.name" :
               if (($itemtype == 'User')
                   && ($ID == 20)) {
                  $out           = "";
                  $split         = explode("$$$$",$data[$NAME.$num]);
                  $split2        = explode("$$$$",$data[$NAME.$num."_2"]);
                  $split3        = explode("$$$$",$data[$NAME.$num."_3"]);
                  $split4        = explode("$$$$",$data[$NAME.$num."_4"]);

                  $count_display = 0;
                  $added         = array();
                  for ($k=0 ; $k<count($split) ; $k++) {
                     if (strlen(trim($split[$k])) > 0) {
                        $text = sprintf(__('%1$s - %2$s'), $split[$k],
                                        Dropdown::getDropdownName('glpi_entities', $split2[$k]));
                        $comp = '';
                        if ($split3[$k]) {
                           $comp = __('R');
                           if ($split4[$k]) {
                              $comp = sprintf(__('%1$s%2$s'), $comp, ", ");
                           }
                        }
                        if ($split4[$k]) {
                           $comp = sprintf(__('%1$s%2$s'), $comp, __('D'));
                        }
                        if (!empty($comp)) {
                           $text = sprintf(__('%1$s %2$s'), $text, "(".$comp.")");
                        }
                        if (!in_array($text,$added)) {
                           if ($count_display) {
                              $out .= self::LBBR;
                           }
                           $count_display++;
                           $out     .= $text;
                           $added[]  = $text;
                        }
                     }
                  }
                  return $out;
               }
               break;

            case "glpi_entities.completename" :
               if ($itemtype == 'User') {
                  $out           = "";
                  $split         = explode("$$$$",$data[$NAME.$num]);
                  $split2        = explode("$$$$",$data[$NAME.$num."_2"]);
                  $split3        = explode("$$$$",$data[$NAME.$num."_3"]);
                  $split4        = explode("$$$$",$data[$NAME.$num."_4"]);
                  $added         = array();
                  $count_display = 0;
                  for ($k=0 ; $k<count($split) ; $k++) {
                     if (strlen(trim($split[$k])) > 0) {
                        $text = sprintf(__('%1$s - %2$s'), $split[$k],
                                        Dropdown::getDropdownName('glpi_profiles', $split2[$k]));
                        $comp = '';
                        if ($split3[$k]) {
                           $comp = __('R');
                           if ($split4[$k]) {
                              $comp = sprintf(__('%1$s%2$s'), $comp, ", ");
                           }
                        }
                        if ($split4[$k]) {
                           $comp = sprintf(__('%1$s%2$s'), $comp, __('D'));
                        }
                        if (!empty($comp)) {
                           $text = sprintf(__('%1$s %2$s'), $text, "(".$comp.")");
                        }
                        if (!in_array($text,$added)) {
                           if ($count_display) {
                              $out .= self::LBBR;
                           }
                           $count_display++;
                           $out    .= $text;
                           $added[] = $text;
                        }
                     }
                  }
                  return $out;
               }
               break;

            case "glpi_documenttypes.icon" :
               if (!empty($data[$NAME.$num])) {
                  return "<img class='middle' alt='' src='".$CFG_GLPI["typedoc_icon_dir"]."/".
                           $data[$NAME.$num]."'>";
               }
               return "&nbsp;";

            case "glpi_documents.filename" :
               $doc = new Document();
               if ($doc->getFromDB($data['id'])) {
                  return $doc->getDownloadLink();
               }
               return NOT_AVAILABLE;

            case "glpi_networkports.mac" :
               $out = "";
               if ($itemtype == 'Computer') {
                  $displayed = array();
                  if (!empty($data[$NAME.$num."_2"])) {
                     $split         = explode("$$$$",$data[$NAME.$num."_2"]);
                     $count_display = 0;
                     for ($k=0 ; $k<count($split) ; $k++) {
                        $lowstr = Toolbox::strtolower($split[$k]);
                        if ((strlen(trim($split[$k])) > 0)
                            && !in_array($lowstr, $displayed)) {
                           if ($count_display) {
                              $out .= self::LBBR;
                           }
                           $count_display++;
                           $out        .= $split[$k];
                           $displayed[] = $lowstr;
                        }
                     }
                     if (!empty($data[$NAME.$num])) {
                        $out .= self::LBBR;
                     }
                  }
                  if (!empty($data[$NAME.$num])) {
                     $split         = explode("$$$$",$data[$NAME.$num]);
                     $count_display = 0;
                     for ($k=0 ; $k<count($split) ; $k++) {
                        $lowstr = Toolbox::strtolower($split[$k]);
                        if ((strlen(trim($split[$k])) > 0)
                            && !in_array($lowstr, $displayed)) {
                           if ($count_display) {
                              $out .= self::LBBR;
                           }
                           $count_display++;
                           $out        .= $split[$k];
                           $displayed[] = $lowstr;
                        }
                     }
                  }
                  return $out;
               }
               break;

            case "glpi_contracts.end_date" :
               if ($data[$NAME.$num."_renewal"] > 0) {
                  return Contract::getContractEndDate($data['id'], $data[$NAME.$num."_begin_date"],
                                                      $data[$NAME.$num."_duration"],
                                                      $data[$NAME.$num."_periodicity"],
                                                      $data[$NAME.$num."_renewal"]);
               }
               return Infocom::getWarrantyExpir($data[$NAME.$num."_begin_date"],
                                                $data[$NAME.$num."_duration"]);

            case "glpi_tickets_tickets.tickets_id_1" :
               $out        = "";
               $split      = explode("$$$$",$data[$NAME.$num]);
               $split2     = explode("$$$$",$data[$NAME.$num."_tickets_id_2"]);
               $displayed  = array();
               for ($k=0 ; $k<count($split) ; $k++) {
                  $split3 = self::explodeWithID("$$", $split[$k]);
                  $split4 = self::explodeWithID("$$", $split2[$k]);

                  $linkid = ($split4[0] == $data['id']) ? $split4[0] : $split4[0];
                  if (($linkid > 0) && !isset($displayed[$linkid])) {
                     $text  = "<a ";
                     $text .= "href=\"".$CFG_GLPI["root_doc"]."/front/ticket.form.php?id=$linkid\">";
                     $text .= Dropdown::getDropdownName('glpi_tickets', $linkid)."</a>";
                     if (count($displayed)) {
                        $out .= self::LBBR;
                     }
                     $displayed[$linkid] = $linkid;
                     $out               .= $text;
                  }
               }
               return $out;

            case "glpi_problems.count" :
               if (($data[$NAME.$num] > 0)
                   && Session::haveRight("problem", Problem::READALL)) {
                  if ($itemtype == 'ITILCategory') {
                     $options['criteria'][0]['field']      = 7;
                     $options['criteria'][0]['searchtype'] = 'equals';
                     $options['criteria'][0]['value']      = $data['id'];
                     $options['criteria'][0]['link']       = 'AND';
                  }

                  $options['reset'] = 'reset';

                  $out  = "<a id='problem$itemtype".$data['id']."' ";
                  $out .= "href=\"".$CFG_GLPI["root_doc"]."/front/problem.php?".
                             Toolbox::append_params($options, '&amp;')."\">";
                  $out .= $data[$NAME.$num]."</a>";

               } else {
                  $out = $data[$NAME.$num];
               }
               return $out;

            case "glpi_tickets.count" :
               if (($data[$NAME.$num] > 0)
                   && Session::haveRight("ticket", Ticket::READALL)) {

                  if ($itemtype == 'User') {
                     $options['criteria'][0]['field']      = 4;
                     $options['criteria'][0]['searchtype']= 'equals';
                     $options['criteria'][0]['value']      = $data['id'];
                     $options['criteria'][0]['link']       = 'AND';

                     $options['criteria'][1]['field']      = 22;
                     $options['criteria'][1]['searchtype'] = 'equals';
                     $options['criteria'][1]['value']      = $data['id'];
                     $options['criteria'][1]['link']       = 'OR';

                     $options['criteria'][2]['field']      = 5;
                     $options['criteria'][2]['searchtype'] = 'equals';
                     $options['criteria'][2]['value']      = $data['id'];
                     $options['criteria'][2]['link']       = 'OR';

                  } else if ($itemtype == 'ITILCategory') {
                     $options['criteria'][0]['field']      = 7;
                     $options['criteria'][0]['searchtype'] = 'equals';
                     $options['criteria'][0]['value']      = $data['id'];
                     $options['criteria'][0]['link']       = 'AND';

                  } else {
                     $options['criteria'][0]['field']       = 12;
                     $options['criteria'][0]['searchtype']  = 'equals';
                     $options['criteria'][0]['value']       = 'all';
                     $options['criteria'][0]['link']        = 'AND';

                     $options['metacriteria'][0]['itemtype']   = $itemtype;
                     $options['metacriteria'][0]['field']      = self::getOptionNumber($itemtype, 'name');
                     $options['metacriteria'][0]['searchtype'] = 'equals';
                     $options['metacriteria'][0]['value']      = $data['id'];
                     $options['metacriteria'][0]['link']       = 'AND';
                  }

                  $options['reset'] = 'reset';

                  $out  = "<a id='ticket$itemtype".$data['id']."' ";
                  $out .= "href=\"".$CFG_GLPI["root_doc"]."/front/ticket.php?".
                             Toolbox::append_params($options, '&amp;')."\">";
                  $out .= $data[$NAME.$num]."</a>";

               } else {
                  $out = $data[$NAME.$num];
               }
               return $out;

            case "glpi_tickets.due_date" :
            case "glpi_problems.due_date" :
            case "glpi_changes.due_date" :
               // Due date + progress
               if ($ID == 151) {
                  $out = Html::convDate($data[$NAME.$num]);

                  // No due date in waiting status
                  if ($data[$NAME.$num.'_status'] == CommonITILObject::WAITING) {
                     return '';
                  }
                  if (empty($data[$NAME.$num])) {
                     return '';
                  }
                  if (($data[$NAME.$num.'_status'] == Ticket::SOLVED)
                      || ($data[$NAME.$num.'_status'] == Ticket::CLOSED)) {
                     return $data[$NAME.$num];
                  }
                  $itemtype = getItemTypeForTable($table);
                  $item = new $itemtype();
                  $item->getFromDB($data['id']);
                  $percentage  = 0;
                  $totaltime   = 0;
                  $currenttime = 0;
                  if ($item->isField('slas_id') && $item->fields['slas_id'] != 0) { // Have SLA
                     $sla = new SLA();
                     $sla->getFromDB($item->fields['slas_id']);
                     $currenttime = $sla->getActiveTimeBetween($item->fields['date'],
                                                               date('Y-m-d H:i:s'));
                     $totaltime   = $sla->getActiveTimeBetween($item->fields['date'],
                                                               $data[$NAME.$num]);
                  } else {
                     $calendars_id = Entity::getUsedConfig('calendars_id',
                                                           $item->fields['entities_id']);
                     if ($calendars_id != 0) { // Ticket entity have calendar
                        $calendar = new Calendar();
                        $calendar->getFromDB($calendars_id);
                        $currenttime = $calendar->getActiveTimeBetween($item->fields['date'],
                                                                       date('Y-m-d H:i:s'));
                        $totaltime   = $calendar->getActiveTimeBetween($item->fields['date'],
                                                                       $data[$NAME.$num]);
                     } else { // No calendar
                        $currenttime = strtotime(date('Y-m-d H:i:s'))
                                                 - strtotime($item->fields['date']);
                        $totaltime   = strtotime($data[$NAME.$num])
                                                 - strtotime($item->fields['date']);
                     }
                  }
                  if ($totaltime != 0)  {
                     $percentage  = round((100 * $currenttime) / $totaltime);
                  } else {
                     // Total time is null : no active time
                     $percentage = 100;
                  }
                  if ($percentage > 100) {
                     $percentage = 100;
                  }
                  $percentage_text = $percentage;

                  if ($_SESSION['glpiduedatewarning_unit'] == '%') {
                     $less_warn_limit = $_SESSION['glpiduedatewarning_less'];
                     $less_warn       = (100 - $percentage);
                  } else if ($_SESSION['glpiduedatewarning_unit'] == 'hour') {
                     $less_warn_limit = $_SESSION['glpiduedatewarning_less'] * HOUR_TIMESTAMP;
                     $less_warn       = ($totaltime - $currenttime);
                  } else if ($_SESSION['glpiduedatewarning_unit'] == 'day') {
                     $less_warn_limit = $_SESSION['glpiduedatewarning_less'] * DAY_TIMESTAMP;
                     $less_warn       = ($totaltime - $currenttime);
                  }

                  if ($_SESSION['glpiduedatecritical_unit'] == '%') {
                     $less_crit_limit = $_SESSION['glpiduedatecritical_less'];
                     $less_crit       = (100 - $percentage);
                  } else if ($_SESSION['glpiduedatecritical_unit'] == 'hour') {
                     $less_crit_limit = $_SESSION['glpiduedatecritical_less'] * HOUR_TIMESTAMP;
                     $less_crit       = ($totaltime - $currenttime);
                  } else if ($_SESSION['glpiduedatecritical_unit'] == 'day') {
                     $less_crit_limit = $_SESSION['glpiduedatecritical_less'] * DAY_TIMESTAMP;
                     $less_crit       = ($totaltime - $currenttime);
                  }

                  $color = $_SESSION['glpiduedateok_color'];
                  if ($less_crit < $less_crit_limit) {
                     $color = $_SESSION['glpiduedatecritical_color'];
                  } else if ($less_warn < $less_warn_limit) {
                     $color = $_SESSION['glpiduedatewarning_color'];
                  }

                  //Calculate bar progress
                  $out .= "<div class='center' style='background-color: #ffffff; width: 100%;
                            border: 1px solid #9BA563;' >";
                  $out .= "<div style='position:absolute;'>&nbsp;".$percentage_text."%</div>";
                  $out .= "<div class='center' style='background-color: ".$color.";
                            width: ".$percentage."%; height: 12px' ></div>";
                  $out .= "</div>";
                  return $out;
               }
               break;

            case "glpi_softwarelicenses.number" :
               if ($data[$NAME.$num."_2"] == -1) {
                  return __('Unlimited');
               }
               if (empty($data[$NAME.$num])) {
                  return 0;
               }
               return $data[$NAME.$num];

            case "glpi_auth_tables.name" :
               return Auth::getMethodName($data[$NAME.$num], $data[$NAME.$num."_2"], 1,
                                          $data[$NAME.$num."_3"].$data[$NAME.$num."_4"]);

            case "glpi_reservationitems.comment" :
               if (empty($data[$NAME.$num])) {
                  return "<a title=\"".__s('Modify the comment')."\"
                           href='".$CFG_GLPI["root_doc"]."/front/reservationitem.form.php?id=".
                           $data["refID"]."' >".__('None')."</a>";
               }
               return "<a title=\"".__s('Modify the comment')."\"
                        href='".$CFG_GLPI["root_doc"]."/front/reservationitem.form.php?id=".
                        $data['refID']."' >".Html::resume_text($data[$NAME.$num])."</a>";

            case 'glpi_crontasks.description' :
               $tmp = new CronTask();
               return $tmp->getDescription($data[$NAME.$num]);

            case 'glpi_changes.status':
               $status = Change::getStatus($data[$NAME.$num]);
               return "<img src=\"".Change::getStatusIconURL($data[$NAME.$num])."\"
                        alt=\"$status\" title=\"$status\">&nbsp;$status";

            case 'glpi_problems.status':
               $status = Problem::getStatus($data[$NAME.$num]);
               return "<img src=\"".Problem::getStatusIconURL($data[$NAME.$num])."\"
                        alt=\"$status\" title=\"$status\">&nbsp;$status";

            case 'glpi_tickets.status':
               $status = Ticket::getStatus($data[$NAME.$num]);
               return "<img src=\"".Ticket::getStatusIconURL($data[$NAME.$num])."\"
                        alt=\"$status\" title=\"$status\">&nbsp;$status";

            case 'glpi_tickets.items_id' :
               if (!empty($data[$NAME.$num."_itemtype"])
                   && ($item = getItemForItemtype($data[$NAME.$num."_itemtype"]))) {
                  if ($item->getFromDB($data[$NAME.$num])) {
                     return $item->getLink(array('comments' => true));
                  }
               }
               return '&nbsp;';

            case 'glpi_tickets.name' :
            case 'glpi_problems.name' :
            case 'glpi_changes.name' :
               if (isset($data[$NAME.$num."_content"])
                   && isset($data[$NAME.$num."_id"])
                   && isset($data[$NAME.$num."_status"])) {
                  $link = Toolbox::getItemTypeFormURL($itemtype);
                  $out  = "<a id='$itemtype".$data[$NAME.$num."_id"]."' href=\"".$link;
                  $out .= (strstr($link,'?') ?'&amp;' :  '?');
                  $out .= 'id='.$data[$NAME.$num."_id"];
                  // Force solution tab if solved
                  if ($item = getItemForItemtype($itemtype)) {
                     if (in_array($data[$NAME.$num."_status"], $item->getSolvedStatusArray())) {
                        $out .= "&amp;forcetab=$itemtype$2";
                     }
                  }
                  $out .= "\">";
                  $name = $data[$NAME.$num];
                  if ($_SESSION["glpiis_ids_visible"]
                      || empty($data[$NAME.$num])) {
                     $name = sprintf(__('%1$s (%2$s)'), $name, $data[$NAME.$num."_id"]);
                  }
                  $out    .= $name."</a>";
                  $hdecode = Html::entity_decode_deep($data[$NAME.$num."_content"]);
                  $content = Toolbox::unclean_cross_side_scripting_deep($hdecode);
                  $out     = sprintf(__('%1$s %2$s'), $out,
                                     Html::showToolTip(nl2br(Html::Clean($content)),
                                                             array('applyto' => $itemtype.
                                                                                $data[$NAME.$num."_id"],
                                                                   'display' => false)));
                  return $out;
               }

            case 'glpi_ticketvalidations.status' :
               $split = explode("$$$$",$data[$NAME.$num]);
               $out   = '';
               foreach ($split as $val) {
                  if (!empty($val)) {
                     $split2  = self::explodeWithID("$$", $val);
                     $status  = TicketValidation::getStatus($split2[0]);
                     $bgcolor = TicketValidation::getStatusColor($split2[0]);
                     $out    .= (empty($out)?'':self::LBBR).
                                 "<div style=\"background-color:".$bgcolor.";\">".$status.'</div>';
                  }
               }
               return $out;

            case 'glpi_ticketsatisfactions.satisfaction' :
               return TicketSatisfaction::displaySatisfaction($data[$NAME.$num]);

            case 'glpi_cartridgeitems._virtual' :
               return Cartridge::getCount($data["id"], $data[$NAME.$num.'_alarm_threshold'],
                                          self::$output_type != self::HTML_OUTPUT);

            case 'glpi_printers._virtual' :
               return Cartridge::getCountForPrinter($data["id"],
                                                    self::$output_type != self::HTML_OUTPUT);

            case 'glpi_consumableitems._virtual' :
               return Consumable::getCount($data["id"], $data[$NAME.$num.'_alarm_threshold'],
                                           self::$output_type != self::HTML_OUTPUT);

            case 'glpi_reservationitems._virtual' :
               if ($data[$NAME.$num.'_is_active']) {
                  return "<a href='reservation.php?reservationitems_id=".
                                          $data["refID"]."' title=\"".__s('See planning')."\">".
                                          "<img src=\"".$CFG_GLPI["root_doc"].
                                          "/pics/reservation-3.png\" alt='' title=''></a>";
               } else {
                  return "&nbsp;";
               }
         }
      }


      //// Default case

      // Link with plugin tables : need to know left join structure
      if (isset($table)) {
         if (preg_match("/^glpi_plugin_([a-z0-9]+)/", $table.'.'.$field, $matches)) {
            if (count($matches) == 2) {
               $plug     = $matches[1];
               $function = 'plugin_'.$plug.'_giveItem';
               if (function_exists($function)) {
                  $out = $function($itemtype,$ID,$data,$num);
                  if (!empty($out)) {
                     return $out;
                  }
               }
            }
         }
      }
      $unit = '';
      if (isset($searchopt[$ID]['unit'])) {
         $unit = $searchopt[$ID]['unit'];
      }
      /// TODO try to use getvalueToDisplay instead of redefine display system
      // Preformat items

      if (isset($searchopt[$ID]["datatype"])) {
         switch ($searchopt[$ID]["datatype"]) {
            case "itemlink" :
               $linkitemtype = getItemTypeForTable($searchopt[$ID]["table"]);
               if (isset($data[$NAME.$num."_2"]) && strlen($data[$NAME.$num."_2"])) {
                  $link = Toolbox::getItemTypeFormURL($linkitemtype);

                  $out  = "<a id='".$itemtype."_".$data[$NAME.$num."_2"]."' href=\"".$link;
                  $out .= (strstr($link,'?') ?'&amp;' :  '?');
                  $out .= 'id='.$data[$NAME.$num."_2"];

                  if (isset($searchopt[$ID]['forcetab'])) {
                  $out .= "&amp;forcetab=".$searchopt[$ID]['forcetab'];
                  }
                  $out .= "\">";
                  $name = Dropdown::getValueWithUnit($data[$NAME.$num],$unit);
                  if ($_SESSION["glpiis_ids_visible"] || empty($data[$NAME.$num])) {
                     $name = sprintf(__('%1$s (%2$s)'), $name, $data[$NAME.$num."_2"]);
                  }
                  $out .= $name."</a>";
                  return $out;
               }

               $out           = "";
               $split         = explode("$$$$", $data[$NAME.$num]);
               $count_display = 0;
               $separate      = self::LBBR;
               if (isset($searchopt[$ID]['splititems']) && $searchopt[$ID]['splititems']) {
                  $separate = self::LBHR;
               }

               for ($k=0 ; $k<count($split) ; $k++) {
                  if (strlen(trim($split[$k])) > 0) {
                     $split2 = self::explodeWithID("$$", $split[$k]);
                     if (isset($split2[1]) && ($split2[1] > 0)) {
                        if ($count_display) {
                           $out .= $separate;
                        }
                        $count_display++;
                        $page  = Toolbox::getItemTypeFormURL($linkitemtype);
                        $page .= (strpos($page,'?') ? '&id' : '?id');
                        $name  = Dropdown::getValueWithUnit($split2[0],$unit);
                        if ($_SESSION["glpiis_ids_visible"] || empty($split2[0])) {
                           $name = sprintf(__('%1$s (%2$s)'), $name, $split2[1]);
                        }
                        $out  .= "<a id='".$linkitemtype."_".$data['id']."_".
                                    $split2[1]."' href='$page=".$split2[1]."'>".$name."</a>";
                     }
                  }
               }
               return $out;

            case "text" :
               $separate = self::LBBR;
               if (isset($searchopt[$ID]['splititems']) && $searchopt[$ID]['splititems']) {
                  $separate = self::LBHR;
               }
               $split         = explode("$$$$", $data[$NAME.$num]);
               $out           = '';
               $count_display = 0;
               foreach ($split as $val) {
                  if (strlen(trim($val)) > 0) {
                     $split2 = self::explodeWithID("$$", $val);
                     if ($count_display) {
                        $out .= $separate;
                     }
                     $count_display++;
                     if (isset($searchopt[$ID]['htmltext']) && $searchopt[$ID]['htmltext']) {
                        $out .= Html::clean(Toolbox::unclean_cross_side_scripting_deep(nl2br($split2[0])));
                     } else {
                        $out .= nl2br($split2[0]);
                     }
                  }
               }
               return $out;

            case "date" :
            case "date_delay" :
               $split = explode("$$$$", $data[$NAME.$num]);
               $out   = '';
               foreach ($split as $val) {
                  $split2 = self::explodeWithID("$$", $val);
                  if (is_null($split2[0])
                      && isset($searchopt[$ID]['emptylabel']) && $searchopt[$ID]['emptylabel']) {
                     $out .= (empty($out)?'':self::LBBR).$searchopt[$ID]['emptylabel'];
                  } else {
                     $out .= (empty($out)?'':self::LBBR).Html::convDate($split2[0]);
                  }
               }
               return $out;

            case "datetime" :
               $split = explode("$$$$", $data[$NAME.$num]);
               $out   = '';
               foreach ($split as $val) {
                  $split2 = self::explodeWithID("$$", $val);
                  if (is_null($split2[0])
                      && isset($searchopt[$ID]['emptylabel']) && $searchopt[$ID]['emptylabel']) {
                     $out .= (empty($out)?'':self::LBBR).$searchopt[$ID]['emptylabel'];
                  } else {
                     $out .= (empty($out)?'':self::LBBR).Html::convDateTime($split2[0]);
                  }
               }
               return $out;

            case "timestamp" :
               $withseconds = false;
               if (isset($searchopt[$ID]['withseconds'])) {
                  $withseconds = $searchopt[$ID]['withseconds'];
               }
               $withdays = true;
               if (isset($searchopt[$ID]['withdays'])) {
                  $withdays = $searchopt[$ID]['withdays'];
               }

               $split = explode("$$$$", $data[$NAME.$num]);
               $out   = '';
               foreach ($split as $val) {
                   $out .= (empty($out)?'':'<br>').Html::timestampToString($val, $withseconds,
                                                                           $withdays);
               }
               return $out;

            case "email" :
               $split         = explode('$$$$', $data[$NAME.$num]);
               $out           = '';
               $count_display = 0;
               foreach ($split as $val) {
                  $split2 = self::explodeWithID("$$", $val);
                  if ($count_display) {
                     $out .= self::LBBR;
                  }
                  $count_display++;
                  if (!empty($val)) {
                     $out .= (empty($out)?'':self::LBBR);
                     $out .= "<a href='mailto:$split2[0]'>$split2[0]</a>";
                  }
               }
               return (empty($out) ? "&nbsp;" : $out);

            case "weblink" :
               $orig_link = trim($data[$NAME.$num]);
               if (!empty($orig_link)) {
                  // strip begin of link
                  $link = preg_replace('/https?:\/\/(www[^\.]*\.)?/','',$orig_link);
                  $link = preg_replace('/\/$/', '', $link);
                  if (Toolbox::strlen($link)>$CFG_GLPI["url_maxlength"]) {
                     $link = Toolbox::substr($link, 0, $CFG_GLPI["url_maxlength"])."...";
                  }
                  return "<a href=\"".formatOutputWebLink($orig_link)."\" target='_blank'>$link</a>";
               }
               return "&nbsp;";

            case "count" :
            case "number" :
               if (isset($searchopt[$ID]['forcegroupby']) && $searchopt[$ID]['forcegroupby']) {
                  $out           = "";
                  $split         = explode("$$$$", $data[$NAME.$num]);
                  $count_display = 0;
                  for ($k=0 ; $k<count($split) ; $k++) {
                     if (strlen(trim($split[$k])) > 0) {
                        $split2 = self::explodeWithID("$$", $split[$k]);
                        if ($count_display) {
                           $out .= self::LBBR;
                        }
                        $count_display++;
                        if (isset($searchopt[$ID]['toadd'])
                            && isset($searchopt[$ID]['toadd'][$split2[0]])) {
                           $out .= $searchopt[$ID]['toadd'][$split2[0]];
                        } else {
                           $number = str_replace(' ', '&nbsp;', Html::formatNumber($split2[0],
                                                 false, 0));
                           $out .= Dropdown::getValueWithUnit($number, $unit);
                        }
                     }
                  }
                  return $out;
               }
               if (isset($searchopt[$ID]['toadd'])
                   && isset($searchopt[$ID]['toadd'][$data[$NAME.$num]])) {
                  return $searchopt[$ID]['toadd'][$data[$NAME.$num]];
               }
               $number = str_replace(' ', '&nbsp;', Html::formatNumber($data[$NAME.$num], false, 0));
               return Dropdown::getValueWithUnit($number, $unit);


            case "decimal" :
               if (isset($searchopt[$ID]['forcegroupby']) && $searchopt[$ID]['forcegroupby']) {
                  $out           = "";
                  $split         = explode("$$$$" ,$data[$NAME.$num]);
                  $count_display = 0;
                  for ($k=0 ; $k<count($split) ; $k++) {
                     if (strlen(trim($split[$k])) > 0) {
                        $split2 = self::explodeWithID("$$", $split[$k]);

                        if ($count_display) {
                           $out .= self::LBBR;
                        }
                        $count_display++;
                        if (isset($searchopt[$ID]['toadd'])
                            && isset($searchopt[$ID]['toadd'][$split2[0]])) {
                           $out .= $searchopt[$ID]['toadd'][$split2[0]];
                        } else {
                           $number = str_replace(' ', '&nbsp;', Html::formatNumber($split2[0]));
                           $out   .= Dropdown::getValueWithUnit($number, $unit);
                        }
                     }
                  }
                  return $out;
               }
               $number = str_replace(' ', '&nbsp;', Html::formatNumber($data[$NAME.$num]));
               return Dropdown::getValueWithUnit($number, $unit);

            case "bool" :
               if (isset($searchopt[$ID]['forcegroupby']) && $searchopt[$ID]['forcegroupby']) {
                  $out           = "";
                  $split         = explode("$$$$", $data[$NAME.$num]);
                  $count_display = 0;
                  for ($k=0 ; $k<count($split) ; $k++) {
                     if (strlen(trim($split[$k])) > 0) {
                        $split2 = self::explodeWithID("$$", $split[$k]);
                        if ($count_display) {
                           $out .= self::LBBR;
                        }
                        $count_display++;
                        $out .= Dropdown::getValueWithUnit(Dropdown::getYesNo($split2[0]),$unit);
                     }
                  }
                  return $out;
               }
               return Dropdown::getValueWithUnit(Dropdown::getYesNo($data[$NAME.$num]),$unit);

            case "itemtypename":
               if ($obj = getItemForItemtype($data[$NAME.$num])) {
                  return $obj->getTypeName();
               }
               return "";

            case "language":
               if (isset($CFG_GLPI['languages'][$data[$NAME.$num]])) {
                  return $CFG_GLPI['languages'][$data[$NAME.$num]][0];
               }
               return __('Default value');
         }
      }
      // Manage items with need group by / group_concat
      if (isset($searchopt[$ID]['forcegroupby']) && $searchopt[$ID]['forcegroupby']) {
         $out           = "";
         $split         = explode("$$$$", $data[$NAME.$num]);
         $count_display = 0;
         $separate      = self::LBBR;
         if (isset($searchopt[$ID]['splititems']) && $searchopt[$ID]['splititems']) {
            $separate = self::LBHR;
         }
         for ($k=0 ; $k<count($split) ; $k++) {
            if (strlen(trim($split[$k])) > 0) {
               if ($count_display) {
                  $out .= $separate;
               }
               $withoutid = self::explodeWithID("$$", $split[$k]);
               $count_display++;
               // Get specific display if available
               $itemtype = getItemTypeForTable($table);
               if ($item = getItemForItemtype($itemtype)) {
                  $tmpdata  = array($field => $withoutid[0]);
                  $specific = $item->getSpecificValueToDisplay($field, $tmpdata,
                                                               array('html' => true));
               }
               if (!empty($specific)) {
                  $out .= $specific;
               } else {
                  $out      .= Dropdown::getValueWithUnit($withoutid[0], $unit);
               }
            }
         }
         return $out;
      }

      // Get specific display if available
      if (isset($table)) {
         $itemtype = getItemTypeForTable($table);
         if ($item = getItemForItemtype($itemtype)) {
            $tmpdata  = array($field => $data[$NAME.$num]);
            if (isset($searchopt[$ID]['additionalfields'])
                && count($searchopt[$ID]['additionalfields'])) {

               foreach ($searchopt[$ID]['additionalfields'] as $key) {
                  $tmpdata[$key] = $data[$NAME.$num.'_'.$key];
               }
            }
            $specific = $item->getSpecificValueToDisplay($field, $tmpdata,
                                                         array('html'      => true,
                                                               'searchopt' => $searchopt[$ID]));

            if (!empty($specific)) {
               return $specific;
            }
         }
      }

      // Manage auto CONCAT id
      $split = self::explodeWithID('$$', $data[$NAME.$num]);
      $split[0] = trim($split[0]);
      if (isset($searchopt[$ID]['toadd']) && isset($searchopt[$ID]['toadd'][$split[0]])) {
         return $searchopt[$ID]['toadd'][$split[0]];
      }
      // Empty is 0 or empty
      if (empty($split[0])&& isset($searchopt[$ID]['emptylabel'])) {
         return $searchopt[$ID]['emptylabel'];
      }
      // Trans field exists
      if (isset($data[$NAME.$num.'_trans']) && !empty($data[$NAME.$num.'_trans'])) {
         return Dropdown::getValueWithUnit($data[$NAME.$num.'_trans'], $unit);
      }
      // Trans in group concat
      if (count($split) == 3 && !empty($split[1])) {
         return Dropdown::getValueWithUnit($split[1], $unit);
      }

      return Dropdown::getValueWithUnit($split[0], $unit);
   }


   /**
    * Reset save searches
    *
    * @return nothing
   **/
   static function resetSaveSearch() {

      unset($_SESSION['glpisearch']);
      $_SESSION['glpisearch']       = array();
      unset($_SESSION['glpisearchcount']);
      $_SESSION['glpisearchcount']  = array();
      unset($_SESSION['glpisearchcount2']);
      $_SESSION['glpisearchcount2'] = array();
   }


   /**
    * Completion of the URL $_GET values with the $_SESSION values or define default values
    *
    * @param $itemtype        item type to manage
    * @param $usesession      Use datas save in session (true by default)
    * @param $forcebookmark   force trying to load parameters from default bookmark:
    *                         used for global search (false by default)
    *
    * @return nothing
   **/
   static function manageGetValues($itemtype, $usesession=true, $forcebookmark=false) {
      global $_GET, $DB;

      $redirect = false;

      if (isset($_GET["add_search_count"]) && $_GET["add_search_count"]) {
         $_SESSION["glpisearchcount"][$itemtype]++;
         Html::redirect(str_replace("add_search_count=1&", "", $_SERVER['REQUEST_URI']));
      }

      if (isset($_GET["delete_search_count"]) && $_GET["delete_search_count"]) {
         if ($_SESSION["glpisearchcount"][$itemtype] > 1) {
            $_SESSION["glpisearchcount"][$itemtype]--;
         }
         Html::redirect(str_replace("delete_search_count=1&", "", $_SERVER['REQUEST_URI']));
      }

      if (isset($_GET["add_search_count2"]) && $_GET["add_search_count2"]) {
         $_SESSION["glpisearchcount2"][$itemtype]++;
         Html::redirect(str_replace("add_search_count2=1&", "", $_SERVER['REQUEST_URI']));
      }

      if (isset($_GET["delete_search_count2"]) && $_GET["delete_search_count2"]) {
         if ($_SESSION["glpisearchcount2"][$itemtype] >= 1) {
            $_SESSION["glpisearchcount2"][$itemtype]--;
         }
         Html::redirect(str_replace("delete_search_count2=1&", "", $_SERVER['REQUEST_URI']));
      }

      $default_values = array();

      $default_values["start"]       = 0;
      $default_values["order"]       = "ASC";
      $default_values["sort"]        = 1;
      $default_values["is_deleted"]  = 0;
      $default_values["criteria"]    = array();
      $default_values["metacriteria"]    = array();
      
      // Reorg search array
      // start
      // order
      // sort
      // is_deleted
      // itemtype
      // criteria : array (0 => array (link =>
      //                               field =>
      //                               searchtype => 
      //                               value =>   (contains)
      // metacriteria : array (0 => array (itemtype =>
      //                                  link =>
      //                                  field =>
      //                                  searchtype =>  
      //                                  value =>   (contains)

      if (($itemtype != 'AllAssets')
          && class_exists($itemtype)
          && method_exists($itemtype,'getDefaultSearchRequest')) {

         $default_values = array_merge($default_values,
                                       call_user_func(array($itemtype,
                                                            'getDefaultSearchRequest')));
      }

      // First view of the page or force bookmark : try to load a bookmark
      if ($forcebookmark
          || ($usesession
              && !isset($_GET["reset"])
              && !isset($_SESSION['glpisearch'][$itemtype]))) {

         $query = "SELECT `bookmarks_id`
                   FROM `glpi_bookmarks_users`
                   WHERE `users_id`='".Session::getLoginUserID()."'
                         AND `itemtype` = '$itemtype'";
         if ($result = $DB->query($query)) {
            if ($DB->numrows($result) > 0) {
               $IDtoload = $DB->result($result, 0, 0);
               // Set session variable
               $_SESSION['glpisearch'][$itemtype] = array();
               // Load bookmark on main window
               $bookmark = new Bookmark();
               // Only get datas for bookmarks
               if ($forcebookmark) {
                  $_GET = $bookmark->getParameters($IDtoload);
               } else {
                  $bookmark->load($IDtoload, false);
               }
            }
         }
      }

      if ($usesession
          && isset($_GET["reset"])) {
         if (isset($_SESSION['glpisearch'][$itemtype])) {
            unset($_SESSION['glpisearch'][$itemtype]);
         }
         if (isset($_SESSION['glpisearchcount'][$itemtype])) {
            unset($_SESSION['glpisearchcount'][$itemtype]);
         }
         if (isset($_SESSION['glpisearchcount2'][$itemtype])) {
            unset($_SESSION['glpisearchcount2'][$itemtype]);
         }

         // Bookmark use
         if (isset($_GET["glpisearchcount"])) {
            $_SESSION["glpisearchcount"][$itemtype] = $_GET["glpisearchcount"];
         } else if (isset($_GET["criteria"])) {
            $_SESSION["glpisearchcount"][$itemtype] = count($_GET["criteria"]);
         }

         // Bookmark use
         if (isset($_GET["glpisearchcount2"])) {
            $_SESSION["glpisearchcount2"][$itemtype] = $_GET["glpisearchcount2"];
         } else if (isset($_GET["metacriteria"])) {
            $_SESSION["glpisearchcount2"][$itemtype] = count($_GET["metacriteria"]);
         }
      }

      if (isset($_GET)
          && is_array($_GET)
          && $usesession) {
         foreach ($_GET as $key => $val) {
            $_SESSION['glpisearch'][$itemtype][$key] = $val;
         }
      }

      foreach ($default_values as $key => $val) {
         if (!isset($_GET[$key])) {
            if ($usesession
                && isset($_SESSION['glpisearch'][$itemtype][$key])) {
               $_GET[$key] = $_SESSION['glpisearch'][$itemtype][$key];
            } else {
               $_GET[$key]                    = $val;
               $_SESSION['glpisearch'][$itemtype][$key] = $val;
            }
         }
      }

      if (!isset($_SESSION["glpisearchcount"][$itemtype])) {
         if (isset($_GET["glpisearchcount"])) {
            $_SESSION["glpisearchcount"][$itemtype] = $_GET["glpisearchcount"];
         } else {
            $_SESSION["glpisearchcount"][$itemtype] = 1;
         }
      }
      if (!isset($_SESSION["glpisearchcount2"][$itemtype])) {
         // Set in URL for bookmark
         if (isset($_GET["glpisearchcount2"])) {
            $_SESSION["glpisearchcount2"][$itemtype] = $_GET["glpisearchcount2"];
         } else {
            $_SESSION["glpisearchcount2"][$itemtype] = 0;
         }
      }

   }


   /**
    * Clean search options depending of user active profile
    *
    * @param $itemtype              item type to manage
    * @param $action                action which is used to manupulate searchoption
    *                               (default READ)
    * @param $withplugins  boolean  get plugins options (true by default)
    *
    * @return clean $SEARCH_OPTION array
   **/
   static function getCleanedOptions($itemtype, $action=READ, $withplugins=true) {
      global $CFG_GLPI;

      $options = &self::getOptions($itemtype, $withplugins);
      $todel   = array();

      if (!Session::haveRight('infocom',$action)
          && InfoCom::canApplyOn($itemtype)) {
         $itemstodel = Infocom::getSearchOptionsToAdd($itemtype);
         $todel      = array_merge($todel, array_keys($itemstodel));
      }

      if (!Session::haveRight('contract',$action)
          && in_array($itemtype, $CFG_GLPI["contract_types"])) {
         $itemstodel = Contract::getSearchOptionsToAdd();
         $todel      = array_merge($todel, array_keys($itemstodel));
      }

      if (!Session::haveRight('document',$action)
          && Document::canApplyOn($itemtype)) {
         $itemstodel = Document::getSearchOptionsToAdd();
         $todel      = array_merge($todel, array_keys($itemstodel));
      }

      // do not show priority if you don't have right in profile
      if (($itemtype == 'Ticket')
          && ($action == UPDATE)
          && !Session::haveRight('ticket', Ticket::CHANGEPRIORITY)) {
         $todel[] = 3;
      }

      if ($itemtype == 'Computer') {
         if (!Session::haveRight('networking', $action)) {
            $itemstodel = NetworkPort::getSearchOptionsToAdd($itemtype);
            $todel      = array_merge($todel, array_keys($itemstodel));
         }
      }
      if (!Session::haveRight(strtolower($itemtype), READNOTE)) {
         $todel[] = 90;
      }

      if (count($todel)) {
         foreach ($todel as $ID) {
            if (isset($options[$ID])) {
               unset($options[$ID]);
            }
         }
      }

      return $options;
   }


   /**
    *
    * Get an option number in the SEARCH_OPTION array
    *
    * @param $itemtype
    * @param $field     name
    *
    * @return integer
   **/
   static function getOptionNumber($itemtype, $field) {

      $table = getTableForItemType($itemtype);
      $opts  = &self::getOptions($itemtype);

      foreach ($opts as $num => $opt) {
         if (is_array($opt)
             && ($opt['table'] == $table)
             && ($opt['field'] == $field)) {
            return $num;
         }
      }
      return 0;
   }


   /**
    * Get the SEARCH_OPTION array
    *
    * @param $itemtype
    * @param $withplugins boolean get search options from plugins (true by default)
    *
    * @return the reference to  array of search options for the given item type
   **/
   static function &getOptions($itemtype, $withplugins=true) {
      global $CFG_GLPI;

      static $search = array();
      $item = NULL;
      
      if (!isset($search[$itemtype])) {
         // standard type first
         switch ($itemtype) {
            case 'Internet' :
               $search[$itemtype]['common']            = __('Characteristics');

               $search[$itemtype][1]['table']          = 'networkport_types';
               $search[$itemtype][1]['field']          = 'name';
               $search[$itemtype][1]['name']           = __('Name');
               $search[$itemtype][1]['datatype']       = 'itemlink';
               $search[$itemtype][1]['searchtype']     = 'contains';

               $search[$itemtype][2]['table']          = 'networkport_types';
               $search[$itemtype][2]['field']          = 'id';
               $search[$itemtype][2]['name']           = __('ID');
               $search[$itemtype][2]['searchtype']     = 'contains';

               $search[$itemtype][31]['table']         = 'glpi_states';
               $search[$itemtype][31]['field']         = 'completename';
               $search[$itemtype][31]['name']          = __('Status');

               $search[$itemtype] += NetworkPort::getSearchOptionsToAdd('networkport_types');
               break;

            case 'AllAssets' :
               $search[$itemtype]['common']            = __('Characteristics');

               $search[$itemtype][1]['table']          = 'asset_types';
               $search[$itemtype][1]['field']          = 'name';
               $search[$itemtype][1]['name']           = __('Name');
               $search[$itemtype][1]['datatype']       = 'itemlink';
               $search[$itemtype][1]['searchtype']     = 'contains';

               $search[$itemtype][2]['table']          = 'asset_types';
               $search[$itemtype][2]['field']          = 'id';
               $search[$itemtype][2]['name']           = __('ID');
               $search[$itemtype][2]['searchtype']     = 'contains';

               $search[$itemtype][31]['table']         = 'glpi_states';
               $search[$itemtype][31]['field']         = 'completename';
               $search[$itemtype][31]['name']          = __('Status');

               $search[$itemtype] += Location::getSearchOptionsToAdd();

               $search[$itemtype][5]['table']          = 'asset_types';
               $search[$itemtype][5]['field']          = 'serial';
               $search[$itemtype][5]['name']           = __('Serial number');

               $search[$itemtype][6]['table']          = 'asset_types';
               $search[$itemtype][6]['field']          = 'otherserial';
               $search[$itemtype][6]['name']           = __('Inventory number');

               $search[$itemtype][16]['table']         = 'asset_types';
               $search[$itemtype][16]['field']         = 'comment';
               $search[$itemtype][16]['name']          = __('Comments');
               $search[$itemtype][16]['datatype']      = 'text';

               $search[$itemtype][70]['table']         = 'glpi_users';
               $search[$itemtype][70]['field']         = 'name';
               $search[$itemtype][70]['name']          = __('User');

               $search[$itemtype][7]['table']          = 'asset_types';
               $search[$itemtype][7]['field']          = 'contact';
               $search[$itemtype][7]['name']           = __('Alternate username');
               $search[$itemtype][7]['datatype']       = 'string';

               $search[$itemtype][8]['table']          = 'asset_types';
               $search[$itemtype][8]['field']          = 'contact_num';
               $search[$itemtype][8]['name']           = __('Alternate username number');
               $search[$itemtype][8]['datatype']       = 'string';

               $search[$itemtype][71]['table']         = 'glpi_groups';
               $search[$itemtype][71]['field']         = 'completename';
               $search[$itemtype][71]['name']          = __('Group');

               $search[$itemtype][19]['table']         = 'asset_types';
               $search[$itemtype][19]['field']         = 'date_mod';
               $search[$itemtype][19]['name']          = __('Last update');
               $search[$itemtype][19]['datatype']      = 'datetime';
               $search[$itemtype][19]['massiveaction'] = false;

               $search[$itemtype][23]['table']         = 'glpi_manufacturers';
               $search[$itemtype][23]['field']         = 'name';
               $search[$itemtype][23]['name']          = __('Manufacturer');

               $search[$itemtype][24]['table']         = 'glpi_users';
               $search[$itemtype][24]['field']         = 'name';
               $search[$itemtype][24]['linkfield']     = 'users_id_tech';
               $search[$itemtype][24]['name']          = __('Technician in charge of the hardware');

               $search[$itemtype][80]['table']         = 'glpi_entities';
               $search[$itemtype][80]['field']         = 'completename';
               $search[$itemtype][80]['name']          = __('Entity');
               break;

            default :
               if ($item = getItemForItemtype($itemtype)) {
                  $search[$itemtype] = $item->getSearchOptions();
               }
               break;
         }

         if (Session::getLoginUserID()
             && in_array($itemtype, $CFG_GLPI["ticket_types"])) {
            $search[$itemtype]['tracking']          = __('Assistance');

            $search[$itemtype][60]['table']         = 'glpi_tickets';
            $search[$itemtype][60]['linkfield']     = 'items_id';
            $search[$itemtype][60]['field']         = 'count';
            $search[$itemtype][60]['name']          = _x('quantity', 'Number of tickets');
            $search[$itemtype][60]['forcegroupby']  = true;
            $search[$itemtype][60]['usehaving']     = true;
            $search[$itemtype][60]['datatype']      = 'number';
            $search[$itemtype][60]['massiveaction'] = false;
            $search[$itemtype][60]['joinparams']    = array('jointype'
                                                             => "itemtype_item",
                                                            'condition'
                                                             => getEntitiesRestrictRequest('AND',
                                                                                           'NEWTABLE'));
            $search[$itemtype][140]['table']         = 'glpi_problems';
            $search[$itemtype][140]['field']         = 'count';
            $search[$itemtype][140]['name']          = _x('quantity', 'Number of problems');
            $search[$itemtype][140]['forcegroupby']  = true;
            $search[$itemtype][140]['usehaving']     = true;
            $search[$itemtype][140]['datatype']      = 'number';
            $search[$itemtype][140]['massiveaction'] = false;
            $search[$itemtype][140]['joinparams']    = array('beforejoin'
                                                              => array('table'
                                                                        => 'glpi_items_problems',
                                                                       'joinparams'
                                                                        => array('jointype'
                                                                                  => 'itemtype_item')),
                                                             'condition'
                                                              => getEntitiesRestrictRequest('AND',
                                                                                            'NEWTABLE'));
         }

         if (in_array($itemtype, $CFG_GLPI["networkport_types"])
             || ($itemtype == 'AllAssets')) {
            $search[$itemtype] += NetworkPort::getSearchOptionsToAdd($itemtype);
         }

         if (in_array($itemtype, $CFG_GLPI["contract_types"])
             || ($itemtype == 'AllAssets')) {
            $search[$itemtype] += Contract::getSearchOptionsToAdd();
         }

         if (Document::canApplyOn($itemtype)
             || ($itemtype == 'AllAssets')) {
            $search[$itemtype] += Document::getSearchOptionsToAdd();
         }

         if (InfoCom::canApplyOn($itemtype)
             || ($itemtype == 'AllAssets')) {
            $search[$itemtype] += Infocom::getSearchOptionsToAdd($itemtype);
         }

         if ($withplugins) {
            // Search options added by plugins
            $plugsearch = Plugin::getAddSearchOptions($itemtype);
            if (count($plugsearch)) {
               $search[$itemtype] += array('plugins' => __('Plugins'));
               $search[$itemtype] += $plugsearch;
            }
         }

         // Complete linkfield if not define
         if (is_null($item)) { // Special union type
            $itemtable = $CFG_GLPI['union_search_type'][$itemtype];
         } else {
            $itemtable = $item->getTable();
         }

         foreach ($search[$itemtype] as $key => $val) {
            if (!is_array($val)) {
               // skip sub-menu
               continue;
            }
            // Compatibility before 0.80 : Force massive action to false if linkfield is empty :
            if (isset($val['linkfield']) && empty($val['linkfield'])) {
               $search[$itemtype][$key]['massiveaction'] = false;
            }

            // Set default linkfield
            if (!isset($val['linkfield']) || empty($val['linkfield'])) {
               if ((strcmp($itemtable,$val['table']) == 0)
                   && (!isset($val['joinparams']) || (count($val['joinparams']) == 0))) {
                  $search[$itemtype][$key]['linkfield'] = $val['field'];
               } else {
                  $search[$itemtype][$key]['linkfield'] = getForeignKeyFieldForTable($val['table']);
               }
            }
            // Set default datatype
//             if (!isset($val['datatype']) || empty($val['datatype'])) {
//                if ((strcmp($itemtable,$val['table']) != 0)
//                    && ($val['field'] == 'name' || $val['field'] == 'completename')) {
//                   $search[$itemtype][$key]['datatype'] = 'dropdown';
//                } else {
//                   $search[$itemtype][$key]['datatype'] = 'string';
//                }
//             }
            // Add default joinparams
            if (!isset($val['joinparams'])) {
               $search[$itemtype][$key]['joinparams'] = array();
            }
         }

      }

      return $search[$itemtype];
   }

   /**
    * Is the search item related to infocoms
    *
    * @param $itemtype  item type
    * @param $searchID  ID of the element in $SEARCHOPTION
    *
    * @return boolean
   **/
   static function isInfocomOption($itemtype, $searchID) {
      global $CFG_GLPI;

      return (((($searchID >= 25) && ($searchID <= 28))
               || (($searchID >= 37) && ($searchID <= 38))
               || (($searchID >= 50) && ($searchID <= 59))
               || (($searchID >= 120) && ($searchID <= 125)))
              && InfoCom::canApplyOn($itemtype));
   }


   /**
    * @param $itemtype
    * @param $field_num
   **/
   static function getActionsFor($itemtype, $field_num) {

      $searchopt = &self::getOptions($itemtype);
      $actions   = array('contains'  => __('contains'),
                         'searchopt' => array());

      if (isset($searchopt[$field_num])) {
         $actions['searchopt'] = $searchopt[$field_num];

         // Force search type
         if (isset($actions['searchopt']['searchtype'])) {
            // Reset search option
            $actions              = array();
            $actions['searchopt'] = $searchopt[$field_num];
            if (!is_array($actions['searchopt']['searchtype'])) {
               $actions['searchopt']['searchtype'] = array($actions['searchopt']['searchtype']);
            }
            foreach ($actions['searchopt']['searchtype'] as $searchtype) {
               switch ($searchtype) {
                  case "equals" :
                     $actions['equals'] = __('is');
                     break;

                  case "notequals" :
                     $actions['notequals'] = __('is not');
                     break;

                  case "contains" :
                     $actions['contains'] = __('contains');
                     break;

                  case "under" :
                     $actions['under'] = __('under');
                     break;

                  case "notunder" :
                     $actions['notunder'] = __('not under');
                     break;

                  case "lessthan" :
                     $actions['lessthan'] = __('before');
                     break;

                  case "morethan" :
                     $actions['morethan'] = __('after');
                     break;
               }
            }
            return $actions;
         }

         if (isset($searchopt[$field_num]['datatype'])) {
            switch ($searchopt[$field_num]['datatype']) {
               case 'count' :
               case 'number' :
                  $opt = array('contains'  => __('contains'),
                               'equals'    => __('is'),
                               'notequals' => __('is not'),
                               'searchopt' => $searchopt[$field_num]);
                  // No is / isnot if no limits defined
                  if (!isset($searchopt[$field_num]['min'])
                      && !isset($searchopt[$field_num]['max'])) {
                     unset($opt['equals']);
                     unset($opt['notequals']);
                  }
                  return $opt;

               case 'bool' :
                  return array('equals'    => __('is'),
                               'notequals' => __('is not'),
                               'contains'  => __('contains'),
                               'searchopt' => $searchopt[$field_num]);

               case 'right' :
                  return array('equals'    => __('is'),
                               'notequals' => __('is not'),
                               'searchopt' => $searchopt[$field_num]);

               case 'itemtypename' :
                  return array('equals'    => __('is'),
                               'notequals' => __('is not'),
                               'searchopt' => $searchopt[$field_num]);

               case 'date' :
               case 'datetime' :
               case 'date_delay' :
                  return array('equals'    => __('is'),
                               'notequals' => __('is not'),
                               'lessthan'  => __('before'),
                               'morethan'  => __('after'),
                               'contains'  => __('contains'),
                               'searchopt' => $searchopt[$field_num]);
            }
         }

//          switch ($searchopt[$field_num]['table']) {
//             case 'glpi_users_validation' :
//                return array('equals'    => __('is'),
//                             'notequals' => __('is not'),
//                             'searchopt' => $searchopt[$field_num]);
//          }

         switch ($searchopt[$field_num]['field']) {
            case 'id' :
               return array('equals'    => __('is'),
                            'notequals' => __('is not'),
                            'searchopt' => $searchopt[$field_num]);

            case 'name' :
            case 'completename' :
               $actions = array('contains'  => __('contains'),
                                'equals'    => __('is'),
                                'notequals' => __('is not'),
                                'searchopt' => $searchopt[$field_num]);

               // Specific case of TreeDropdown : add under
               $itemtype_linked = getItemTypeForTable($searchopt[$field_num]['table']);
               if ($itemlinked = getItemForItemtype($itemtype_linked)) {
                  if ($itemlinked instanceof CommonTreeDropdown) {
                     $actions['under']    = __('under');
                     $actions['notunder'] = __('not under');
                  }
                  return $actions;
               }
         }
      }
      return $actions;
   }


   /**
    * Print generic Header Column
    *
    * @param $type            display type (0=HTML, 1=Sylk,2=PDF,3=CSV)
    * @param $value           value to display
    * @param &$num            column number
    * @param $linkto          link display element (HTML specific) (default '')
    * @param $issort          is the sort column ? (default 0)
    * @param $order           order type ASC or DESC (defaut '')
    * @param $options  string options to add (default '')
    *
    * @return string to display
   **/
   static function showHeaderItem($type, $value, &$num, $linkto="", $issort=0, $order="",
                                  $options="") {
      global $CFG_GLPI;

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf

         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $PDF_TABLE .= "<th $options>";
            $PDF_TABLE .= Html::clean($value);
            $PDF_TABLE .= "</th>\n";
            break;

         case self::SYLK_OUTPUT : //sylk
            global $SYLK_HEADER,$SYLK_SIZE;
            $SYLK_HEADER[$num] = self::sylk_clean($value);
            $SYLK_SIZE[$num]   = Toolbox::strlen($SYLK_HEADER[$num]);
            break;

         case self::CSV_OUTPUT : //CSV
            $out = "\"".self::csv_clean($value)."\"".$_SESSION["glpicsv_delimiter"];
            break;

         default :
            $out = "<th $options>";
            if ($issort) {
               if ($order=="DESC") {
                  $out .= "<img src=\"".$CFG_GLPI["root_doc"]."/pics/puce-down.png\" alt='' title=''>";
               } else {
                  $out .= "<img src=\"".$CFG_GLPI["root_doc"]."/pics/puce-up.png\" alt='' title=''>";
               }
            }
            if (!empty($linkto)) {
               $out .= "<a href=\"$linkto\">";
            }
            $out .= $value;
            if (!empty($linkto)) {
               $out .= "</a>";
            }
            $out .= "</th>\n";
      }
      $num++;
      return $out;
   }


   /**
    * Print generic normal Item Cell
    *
    * @param $type         display type (0=HTML, 1=Sylk,2=PDF,3=CSV)
    * @param $value        value to display
    * @param &$num         column number
    * @param $row          row number
    * @param $extraparam   extra parameters for display (default '')
    *
    *@return string to display
   **/
   static function showItem($type, $value, &$num, $row, $extraparam='') {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $value = preg_replace('/'.self::LBBR.'/','<br>',$value);
            $value = preg_replace('/'.self::LBHR.'/','<hr>',$value);
            $PDF_TABLE .= "<td $extraparam valign='top'>";
            $PDF_TABLE .= Html::weblink_extract(Html::clean($value));
            $PDF_TABLE .= "</td>\n";

            break;

         case self::SYLK_OUTPUT : //sylk
            global $SYLK_ARRAY,$SYLK_HEADER,$SYLK_SIZE;
            $value                  = Html::weblink_extract($value);
            $value = preg_replace('/'.self::LBBR.'/','<br>',$value);
            $value = preg_replace('/'.self::LBHR.'/','<hr>',$value);
            $SYLK_ARRAY[$row][$num] = self::sylk_clean($value);
            $SYLK_SIZE[$num]        = max($SYLK_SIZE[$num],
                                          Toolbox::strlen($SYLK_ARRAY[$row][$num]));
            break;

         case self::CSV_OUTPUT : //csv
            $value = preg_replace('/'.self::LBBR.'/','<br>',$value);
            $value = preg_replace('/'.self::LBHR.'/','<hr>',$value);
            $value = Html::weblink_extract($value);
            $out   = "\"".self::csv_clean($value)."\"".$_SESSION["glpicsv_delimiter"];
            break;

         default :
            //TODO supprimer valign pour mettre class mais conflit avec $extraparam
            $out = "<td $extraparam valign='top'>";

            if (!preg_match('/'.self::LBHR.'/',$value)) {
               $values = preg_split('/'.self::LBBR.'/i',$value);
               $line_delimiter = '<br>';
            } else {
               $values = preg_split('/'.self::LBHR.'/i',$value);
               $line_delimiter = '<hr>';
            }
            $limitto = 20;
            if (count($values) > $limitto) {
               for ( $i=0 ; $i<$limitto ; $i++) {
                  $out .= $values[$i].$line_delimiter;
               }
//                $rand=mt_rand();
               $out .= "...&nbsp;";
               $value = preg_replace('/'.self::LBBR.'/','<br>',$value);
               $value = preg_replace('/'.self::LBHR.'/','<hr>',$value);
               $out .= Html::showToolTip($value,array('display'   => false,
                                                      'autoclose' => false));

            } else {
               $value = preg_replace('/'.self::LBBR.'/','<br>',$value);
               $value = preg_replace('/'.self::LBHR.'/','<hr>',$value);
               $out .= $value;
            }
            $out .= "</td>\n";
      }
      $num++;
      return $out;
   }


   /**
    * Print generic error
    *
    * @param $type display type (0=HTML, 1=Sylk,2=PDF,3=CSV)
    *
    * @return string to display
   **/
   static function showError($type) {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
         case self::SYLK_OUTPUT : //sylk
         case self::CSV_OUTPUT : //csv
            break;

         default :
            $out = "<div class='center b'>".__('No item found')."</div>\n";
      }
      return $out;
   }


   /**
    * Print generic footer
    *
    * @param $type   display type (0=HTML, 1=Sylk,2=PDF,3=CSV)
    * @param $title  title of file : used for PDF (default '')
    *
    * @return string to display
   **/
   static function showFooter($type, $title="") {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            if ($type == self::PDF_OUTPUT_LANDSCAPE) {
               $pdf = new GLPIPDF('L', 'mm', 'A4', true, 'UTF-8', false);
            } else {
               $pdf = new GLPIPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            }
            $pdf->SetCreator('GLPI');
            $pdf->SetAuthor('GLPI');
            $pdf->SetTitle($title);
            $pdf->SetHeaderData('', '', $title, '');
            $font       = 'helvetica';
            //$subsetting = true;
            $fonsize    = 8;
            if (isset($_SESSION['glpipdffont']) && $_SESSION['glpipdffont']) {
               $font       = $_SESSION['glpipdffont'];
               //$subsetting = false;
            }
            $pdf->setHeaderFont(Array($font, 'B', 8));
            $pdf->setFooterFont(Array($font, 'B', 8));

            //set margins
            $pdf->SetMargins(10, 15, 10);
            $pdf->SetHeaderMargin(10);
            $pdf->SetFooterMargin(10);

            //set auto page breaks
            $pdf->SetAutoPageBreak(TRUE, 15);


            // For standard language
            //$pdf->setFontSubsetting($subsetting);
            // set font
            $pdf->SetFont($font, '', 8);
            $pdf->AddPage();
            $PDF_TABLE.='</table>';
            $pdf->writeHTML($PDF_TABLE, true, false, true, false, '');
            $pdf->Output('glpi.pdf', 'I');
            break;

         case self::SYLK_OUTPUT : //sylk
            global $SYLK_HEADER,$SYLK_ARRAY,$SYLK_SIZE;
            // largeurs des colonnes
            foreach ($SYLK_SIZE as $num => $val) {
               $out .= "F;W".$num." ".$num." ".min(50,$val)."\n";
            }
            $out .= "\n";
            // Header
            foreach ($SYLK_HEADER as $num => $val) {
               $out .= "F;SDM4;FG0C;".($num == 1 ? "Y1;" : "")."X$num\n";
               $out .= "C;N;K\"".self::sylk_clean($val)."\"\n";
               $out .= "\n";
            }
            // Datas
            foreach ($SYLK_ARRAY as $row => $tab) {
               foreach ($tab as $num => $val) {
                  $out .= "F;P3;FG0L;".($num == 1 ? "Y".$row.";" : "")."X$num\n";
                  $out .= "C;N;K\"".self::sylk_clean($val)."\"\n";
               }
            }
            $out.= "E\n";
            break;

         case self::CSV_OUTPUT : //csv
            break;

         default :
            $out = "</table></div>\n";
      }
      return $out;
   }


   /**
    * Print generic footer
    *
    * @param $type   display type (0=HTML, 1=Sylk,2=PDF,3=CSV)
    * @param $rows   number of rows
    * @param $cols   number of columns
    * @param $fixed  used tab_cadre_fixe table for HTML export ? (default 0)
    *
    * @return string to display
   **/
   static function showHeader($type, $rows, $cols, $fixed=0) {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $PDF_TABLE = "<table nobr=\"true\" cellspacing=\"0\" cellpadding=\"1\" border=\"1\" >";
            break;

         case self::SYLK_OUTPUT : // Sylk
            global $SYLK_ARRAY, $SYLK_HEADER, $SYLK_SIZE;
            $SYLK_ARRAY  = array();
            $SYLK_HEADER = array();
            $SYLK_SIZE   = array();
            // entetes HTTP
            header("Expires: Mon, 26 Nov 1962 00:00:00 GMT");
            header('Pragma: private'); /// IE BUG + SSL
            header('Cache-control: private, must-revalidate'); /// IE BUG + SSL
            header("Content-disposition: filename=glpi.slk");
            header('Content-type: application/octetstream');
            // entete du fichier
            echo "ID;PGLPI_EXPORT\n"; // ID;Pappli
            echo "\n";
            // formats
            echo "P;PGeneral\n";
            echo "P;P#,##0.00\n";       // P;Pformat_1 (reels)
            echo "P;P#,##0\n";          // P;Pformat_2 (entiers)
            echo "P;P@\n";              // P;Pformat_3 (textes)
            echo "\n";
            // polices
            echo "P;EArial;M200\n";
            echo "P;EArial;M200\n";
            echo "P;EArial;M200\n";
            echo "P;FArial;M200;SB\n";
            echo "\n";
            // nb lignes * nb colonnes
            echo "B;Y".$rows;
            echo ";X".$cols."\n"; // B;Yligmax;Xcolmax
            echo "\n";
            break;

         case self::CSV_OUTPUT : // csv
            header("Expires: Mon, 26 Nov 1962 00:00:00 GMT");
            header('Pragma: private'); /// IE BUG + SSL
            header('Cache-control: private, must-revalidate'); /// IE BUG + SSL
            header("Content-disposition: filename=glpi.csv");
            header('Content-type: application/octetstream');
            break;

         default :
            if ($fixed) {
               $out = "<div class='center'><table border='0' class='tab_cadre_fixehov'>\n";
            } else {
               $out = "<div class='center'><table border='0' class='tab_cadrehov'>\n";
            }
      }
      return $out;
   }


   /**
    * Print begin of header part
    *
    * @param $type         display type (0=HTML, 1=Sylk,2=PDF,3=CSV)
    *
    * @since version 0.85
    *
    * @return string to display
   **/
   static function showBeginHeader($type) {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $PDF_TABLE .= "<thead>";
            break;

         case self::SYLK_OUTPUT : //sylk
         case self::CSV_OUTPUT : //csv
            break;

         default :
            $out = "<thead>";
      }
      return $out;
   }


   /**
    * Print end of header part
    *
    * @param $type         display type (0=HTML, 1=Sylk,2=PDF,3=CSV)
    *
    * @since version 0.85
    *
    * @return string to display
   **/
   static function showEndHeader($type) {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $PDF_TABLE .= "</thead>";
            break;

         case self::SYLK_OUTPUT : //sylk
         case self::CSV_OUTPUT : //csv
            break;

         default :
            $out = "</thead>";
      }
      return $out;
   }


   /**
    * Print generic new line
    *
    * @param $type         display type (0=HTML, 1=Sylk,2=PDF,3=CSV)
    * @param $odd          is it a new odd line ? (false by default)
    * @param $is_deleted   is it a deleted search ? (false by default)
    *
    * @return string to display
   **/
   static function showNewLine($type, $odd=false, $is_deleted=false) {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $style = "";
            if ($odd) {
               $style = " style=\"background-color:#DDDDDD;\" ";
            }
            $PDF_TABLE .= "<tr nobr=\"true\" $style>";
            break;

         case self::SYLK_OUTPUT : //sylk
         case self::CSV_OUTPUT : //csv
            break;

         default :
            $class = " class='tab_bg_2".($is_deleted?'_2':'')."' ";
            if ($odd) {
               $class = " class='tab_bg_1".($is_deleted?'_2':'')."' ";
            }
            $out = "<tr $class>";
      }
      return $out;
   }


   /**
    * Print generic end line
    *
    * @param $type display type (0=HTML, 1=Sylk,2=PDF,3=CSV)
    *
    * @return string to display
   **/
   static function showEndLine($type) {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $PDF_TABLE.= '</tr>';
            break;

         case self::SYLK_OUTPUT : //sylk
            break;

         case self::CSV_OUTPUT : //csv
            $out = "\n";
            break;

         default :
            $out = "</tr>";
      }
      return $out;
   }


   /**
    * @param $joinparams   array
    */
   static function computeComplexJoinID(array $joinparams) {

      $complexjoin = '';

      if (isset($joinparams['condition'])) {
         $complexjoin .= $joinparams['condition'];
      }

      // For jointype == child
      if (isset($joinparams['jointype']) && ($joinparams['jointype'] == 'child')
          && isset($joinparams['linkfield'])) {
         $complexjoin .= $joinparams['linkfield'];
      }

      if (isset($joinparams['beforejoin'])) {
         if (isset($joinparams['beforejoin']['table'])) {
            $joinparams['beforejoin'] = array($joinparams['beforejoin']);
         }
         foreach ($joinparams['beforejoin'] as $tab) {
            if (isset($tab['table'])) {
               $complexjoin .= $tab['table'];
            }
            if (isset($tab['joinparams']) && isset($tab['joinparams']['condition'])) {
               $complexjoin .= $tab['joinparams']['condition'];
            }
         }
      }

      if (!empty($complexjoin)) {
         $complexjoin = md5($complexjoin);
      }
      return $complexjoin;
   }


   /**
    * Clean display value for csv export
    *
    * @param $value string value
    *
    * @return clean value
   **/
   static function csv_clean($value) {

      if (Toolbox::get_magic_quotes_runtime()) {
         $value = stripslashes($value);
      }

      $value = str_replace("\"", "''", $value);
      $value = Html::clean($value);

      return $value;
   }


   /**
    * Clean display value for sylk export
    *
    * @param $value string value
    *
    * @return clean value
   **/
   static function sylk_clean($value) {

      if (Toolbox::get_magic_quotes_runtime()) {
         $value = stripslashes($value);
      }

      $value = preg_replace('/\x0A/', ' ', $value);
      $value = preg_replace('/\x0D/', NULL, $value);
      $value = str_replace("\"", "''", $value);
      $value = str_replace(';', ';;', $value);
      $value = Html::clean($value);

      return $value;
   }


   /**
    * Create SQL search condition
    *
    * @param $field           name (should be ` protected)
    * @param $val    string   value to search
    * @param $not    boolean  is a negative search ? (false by default)
    * @param $link            with previous criteria (default 'AND')
    *
    * @return search SQL string
   **/
   static function makeTextCriteria ($field, $val, $not=false, $link='AND') {

      $sql = $field . self::makeTextSearch($val, $not);

      if (($not && ($val != 'NULL') && ($val != 'null') && ($val != '^$'))    // Not something
          ||(!$not && ($val == '^$'))) {   // Empty
         $sql = "($sql OR $field IS NULL)";
      }
      return " $link $sql ";
   }


   /**
    * Create SQL search condition
    *
    * @param $val string   value to search
    * @param $not boolean  is a negative search ? (false by default)
    *
    * @return search string
   **/
   static function makeTextSearch($val, $not=false) {

      $NOT = "";
      if ($not) {
         $NOT = "NOT";
      }

      // Unclean to permit < and > search
      $val = Toolbox::unclean_cross_side_scripting_deep($val);

      if (($val == 'NULL') || ($val == 'null')) {
         $SEARCH = " IS $NOT NULL ";

      } else {
         $begin = 0;
         $end   = 0;
         if (($length = strlen($val)) > 0) {
            if (($val[0] == '^')) {
               $begin = 1;
            }

            if ($val[$length-1] == '$') {
               $end = 1;
            }
         }

         if ($begin || $end) {
            // no Toolbox::substr, to be consistent with strlen result
            $val = substr($val, $begin, $length-$end-$begin);
         }

         $SEARCH = " $NOT LIKE '".(!$begin?"%":"").$val.(!$end?"%":"")."' ";
      }
      return $SEARCH;
   }


   /**
    * @since version 0.84
    *
    * @param $pattern
    * @param $subject
   **/
   static function explodeWithID($pattern, $subject) {

      $tab = explode($pattern, $subject);

      if (isset($tab[1]) && !is_numeric($tab[1])) {
         // Report $ to tab[0]
         if (preg_match('/^(\\$*)(.*)/',$tab[1],$matchs)) {
            if (isset($matchs[2]) && is_numeric($matchs[2])) {
               $tab[1]  = $matchs[2];
               $tab[0] .= $matchs[1];
            }
         }
      }
      // Manage NULL value
      if ($tab[0] == self::NULLVALUE) {
         $tab[0] = NULL;
      }
      return $tab;
   }

}
?>