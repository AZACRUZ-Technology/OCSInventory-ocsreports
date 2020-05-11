<?php
/*
 * Copyright 2005-2019 OCSInventory-NG/OCSInventory-ocsreports contributors.
 * See the Contributors file for more details about them.
 *
 * This file is part of OCSInventory-NG/OCSInventory-ocsreports.
 *
 * OCSInventory-NG/OCSInventory-ocsreports is free software: you can redistribute
 * it and/or modify it under the terms of the GNU General Public License as
 * published by the Free Software Foundation, either version 2 of the License,
 * or (at your option) any later version.
 *
 * OCSInventory-NG/OCSInventory-ocsreports is distributed in the hope that it
 * will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty
 * of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with OCSInventory-NG/OCSInventory-ocsreports. if not, write to the
 * Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301, USA.
 */

 /**
  * Class for cve-search
  */

class Cve
{
  public $CVE_SEARCH_URL = '';
  public $CVE_ACTIVE = null;
  private $CVE_BAN;
  private $CVE_DATE = null;
  private $CVE_ALL = null;
  private $publisherName = null;
  public $cve_attr = [];
  public $cve_history = [
    'FLAG'    => null,
    'CVE_NB'  => 0,
    'PUBLISHER_ID' => null,
    'NAME_ID' => null,
    'VERSION_ID'  => null
  ];

  function __construct(){
    $champs = array('VULN_CVESEARCH_ENABLE' => 'VULN_CVESEARCH_ENABLE',
        'VULN_CVESEARCH_HOST' => 'VULN_CVESEARCH_HOST',
        'VULN_BAN_LIST' => 'VULN_BAN_LIST',
        'VULN_CVESEARCH_VERBOSE' => 'VULN_CVESEARCH_VERBOSE',
        'VULN_CVESEARCH_DATE' => 'VULN_CVESEARCH_DATE',
        'VULN_CVESEARCH_ALL' => 'VULN_CVESEARCH_ALL');

    // Get configuration values from DB
    $values = look_config_default_values($champs);

    $this->CVE_ACTIVE = $values['ivalue']["VULN_CVESEARCH_ENABLE"];
    $this->CVE_SEARCH_URL = $values['tvalue']['VULN_CVESEARCH_HOST'];
    $this->CVE_BAN = $values['tvalue']["VULN_BAN_LIST"];
    $this->CVE_VERBOSE = $values['ivalue']["VULN_CVESEARCH_VERBOSE"];
    $this->CVE_ALL = $values['ivalue']["VULN_CVESEARCH_ALL"];
    $this->CVE_DATE = $values['tvalue']["VULN_CVESEARCH_DATE"];
  }

  /**
   * Return latest flag date
   *
   * @param string $date
   * @return string
   */
  private function get_flag($date) {
    $sql = 'SELECT FLAG_DATE FROM cve_search_history ORDER BY ID ASC LIMIT 1';
    $result = mysqli_query($_SESSION['OCS']["readServer"], $sql);

    if($result != null) {
      while($item = mysqli_fetch_array($result)) {
        $this->cve_history['FLAG'] = $item['FLAG_DATE'];
      }
    }
  }

  /**
   * History cve history
   */
  private function verif_history(){
    $sql = "SELECT ID FROM cve_search_history WHERE PUBLISHER_ID = %s";
    $arg = array($this->cve_history['PUBLISHER_ID']);
    $result = mysql2_query_secure($sql, $_SESSION['OCS']["readServer"], $arg);
    return $result->num_rows;
  }

  /**
   * Insert FLAG on cve_history per Publisher
   */
  private function insertFlag() {
    $verif = $this->verif_history();
    if($verif >= 1) {
      $sql = "UPDATE cve_search_history SET FLAG_DATE = '%s', CVE_NB = %s WHERE PUBLISHER_ID = %s";
    } else {
      $sql = "INSERT INTO cve_search_history(FLAG_DATE, CVE_NB, PUBLISHER_ID) VALUES('%s', %s, %s)";
    }
    $sqlarg = array($this->cve_history['FLAG'], $this->cve_history['CVE_NB'], $this->cve_history['PUBLISHER_ID']);
    $result = mysql2_query_secure($sql, $_SESSION['OCS']["writeServer"], $sqlarg);
  }

  /**
   *  Get distinct all software name and publisher
   */
  public function getSoftwareInformations($commandlineArg = null){

    $this->verbose($this->CVE_VERBOSE, 4);

    $sql = 'SELECT ID, PUBLISHER FROM software_publisher WHERE ID != 1 ORDER BY PUBLISHER';
    $result = mysqli_query($_SESSION['OCS']["readServer"], $sql);
    $this->verbose($this->CVE_VERBOSE, 5);

    while ($item_publisher = mysqli_fetch_array($result)) {
      # Reset date
      $this->cve_history['FLAG'] = date('Y-m-d H:i:s');
      # Reset CVE NB
      $this->cve_history['NB'] = 0;
      $this->cve_history['PUBLISHER_ID'] = $item_publisher['ID'];
      
      $this->publisherName = $item_publisher['PUBLISHER'];

      $sql_soft = "SELECT n.NAME, v.VERSION, s.NAME_ID, s.VERSION_ID FROM software_name n 
                  LEFT JOIN software s ON s.NAME_ID = n.ID 
                  LEFT JOIN software_version v ON v.ID = s.VERSION_ID 
                  WHERE s.PUBLISHER_ID = %s AND s.VERSION_ID != 1";
      if($this->CVE_BAN != ""){
        $sql_soft .= ' AND n.category NOT IN ('. $this->CVE_BAN .')';
      }
      $sql_soft .= " ORDER BY n.NAME";
      $arg_soft = array($item_publisher['ID']);
      $result_soft = mysql2_query_secure($sql_soft, $_SESSION['OCS']["readServer"], $arg_soft);

      $this->verbose($this->CVE_VERBOSE, 6);

      while ($item_soft = mysqli_fetch_array($result_soft)) {
        $this->cve_attr = null;
        if(!preg_match('/[^\x00-\x7F]/', $item_soft['NAME']) && !preg_match('#\\{([^}]+)\\}#', $item_soft['NAME'])){
          $soft = $this->cpeNormalizeName($item_soft['NAME']);
          $vendor = $this->cpeNormalizeVendor($item_publisher['PUBLISHER'], $soft);
          $this->cve_history['NAME_ID'] = $item_soft['NAME_ID'];
          $this->cve_history['VERSION_ID'] = $item_soft['VERSION_ID'];
          $this->cve_attr[] = ["NAME" => $soft, "VENDOR" => $vendor, "VERSION" => $item_soft['VERSION'], "REAL_NAME" => $item_soft['NAME'], "REAL_VENDOR" => $item_publisher['PUBLISHER']];
          if($this->cve_attr != null) {
            $this->get_cve($this->cve_attr);
          }
        }
      }

      $this->insertFlag();

    }
  }

  /**
   *  Normalize a software name to a CPE software
   */ 
  private function cpeNormalizeName($name){
    if(preg_match("/Java/", $name) && preg_match("/Update/", $name)){
      return "jre";
    }

    $name = strtolower($name);
    $name = preg_replace("/\s*\([^\)]+\)+$/", "", $name);
    $name = preg_replace("/.x86_/", "", $name);
    $name = preg_replace("/\s*\d+-bit$/", "", $name);
    $name = preg_replace("/\s*(v|version|release)?\s*[\d\.]+(\s+ESR)?$/", "", $name);
    $name = preg_replace("/\s*\(r\)/", "", $name);
    $name = preg_replace('/[^\x00-\x7F]/', "", $name);
    $name = trim($name);
    $name = preg_replace("/\s/", "_", $name);

    return $name;
  }

  /**
   *  Normalize a software vendor to a CPE vendor
   */
  private function cpeNormalizeVendor($vendor, $soft){
    if(preg_match("/^https?:[^\s]+$/", $vendor)){
      return $soft;
    }
    $vendor = strtolower($vendor);
    $vendor = preg_replace("/https?:[^\s]+/", "", $vendor);
    $vendor = preg_replace("/,?\s*(corporation|gmbh|inc\.|incorporated|LLC|spol\.\ss\sr\.o\.|systems\sinc\.|systems\sincorporated)$/", "", $vendor);
    $vendor = preg_replace("/\s*\(r\)/", "", $vendor);
    $vendor = preg_replace('/[^\x00-\x7F]/', "", $vendor);
    $vendor = preg_replace("/[^A-Za-z0-9\._]/", "", $vendor);
    $vendor = trim($vendor);
    $vendor = preg_replace("/\s/", "_", $vendor);
    
    return $vendor;
  }

  /**
   *  Init curl session for get CVE by call api cve-search server
   */
  public function get_cve($cve_attr){
    $curl = curl_init();

    foreach($cve_attr as $key => $values){
      $url = trim($this->CVE_SEARCH_URL)."/api/search/".$values['VENDOR']."/".$values['NAME']; 
      curl_setopt($curl, CURLOPT_HTTPHEADER, array('content-type: application/json'));  
      curl_setopt($curl, CURLOPT_URL, $url);
      curl_setopt ($curl, CURLOPT_RETURNTRANSFER, 1);
      $result = curl_exec ($curl);
      $vars = json_decode($result, true);
      if($vars['total'] != 0){
        $this->search_by_version($vars, $values);
      }
    }

    curl_close ($curl) ;
  }

  /**
   *  Clean soft version and verif if CVE is for this version or not
   */
  private function search_by_version($vars, $software){
    $software["VERSION_MODIF"] = $software["VERSION"];
    if(preg_match("/[^0-9,.:-]/", $software["VERSION_MODIF"])){
      $software["VERSION_MODIF"] = preg_replace("/[^0-9,.:-]/", "", $software["VERSION_MODIF"]);
      if(preg_match("/:/", $software["VERSION_MODIF"])){
        $sft = explode(":", $software["VERSION_MODIF"]);
        foreach($sft as $num => $cut){
          if(preg_match("/[.]/", $cut)){
            $software["VERSION_MODIF"] = $cut;
          }
        }
      }
      if(preg_match("/-/", $software["VERSION_MODIF"])){
        $sft = explode("-", $software["VERSION_MODIF"]);
        foreach($sft as $num => $cut){
          if(preg_match("/[.]/", $cut)){
            $software["VERSION_MODIF"] = $cut;
          }
        }
      }
    }
    $vuln_conf = "cpe:2.3:a:".$software["VENDOR"].":".$software["NAME"].":".$software["VERSION_MODIF"];
    $vuln_conf_all = null;

    if($this->CVE_ALL == 1) {
      $vuln_conf_all = "cpe:2.3:a:".$software["VENDOR"].":".$software["NAME"].":*:*:";
    }
    
    if($software["NAME"] == "jre" && preg_match("/Update/", $software["REAL_NAME"])){
      $jre = explode(" ", $software["REAL_NAME"]);
      foreach($jre as $keys => $word){
        if($word == "Update"){
          $vuln_conf .= ":".strtolower($word)."_".$jre[$keys+1];
        }
      }
    }

    foreach($vars as $key => $array){
      if(is_array($array)){
        foreach($array as $keys => $values) {
          if(isset($values["vulnerable_configuration"])) {
            foreach($values["vulnerable_configuration"] as $keys => $vuln){
              if((strpos($vuln, $vuln_conf) !== false) || (strpos($vuln, $vuln_conf_all) !== false)){
                if($this->CVE_VERBOSE == 1) {
                  error_log(print_r($values['id']." has been referenced for ".$software["REAL_NAME"], true));
                }
                $this->get_infos_cve($values['cvss'], $values['id'], $values['references'][0]);
                $this->cve_history['CVE_NB'] ++;
              }
            }
          }
        }
      }
    }
  }

  /**
   *  Insert CVE on BDD
   */
  private function get_infos_cve($cvss, $id, $reference){
    $sql = 'INSERT INTO cve_search VALUES(%s, %s, %s, %s, "%s", "%s")';
    $arg_sql = array($this->cve_history['PUBLISHER_ID'], $this->cve_history['NAME_ID'], $this->cve_history['VERSION_ID'], $cvss, $id, $reference);
    $result_verif = mysql2_query_secure($sql, $_SESSION['OCS']["writeServer"], $arg_sql);
  }  

  /**
   *  Clean CVE and ban software
   */
  public function clean_cve(){
    $sql = 'SELECT DISTINCT cve FROM cve_search';
    $result = mysqli_query($_SESSION['OCS']["readServer"], $sql);
    $curl = curl_init();

    while ($item_cve = mysqli_fetch_array($result)) {
      $url = $this->CVE_SEARCH_URL."/api/cve/".$item_cve["cve"];
      
      curl_setopt($curl, CURLOPT_URL, $url);
      curl_setopt ($curl, CURLOPT_RETURNTRANSFER, 1);
      $result = curl_exec ($curl);
      $vars = json_decode($result, true);
      curl_close ($curl) ;
      if(empty($vars)){
        $sql_clean = "DELETE FROM cve_search WHERE cve = '%s'";
        $sql_arg = array($item_cve["cve"]);
        $result_verif = mysql2_query_secure($sql_clean, $_SESSION['OCS']["writeServer"], $arg_sql);
      }
    }

    curl_close ($curl);

    if($this->CVE_BAN != ""){
      $sql_ban = "SELECT DISTINCT c.NAME_ID FROM cve_search c LEFT JOIN software_name as s ON c.NAME_ID = s.ID WHERE s.category IN (%s)";
      $sql_ban_arg = array($this->CVE_BAN);
      $result_ban = mysql2_query_secure($sql_ban, $_SESSION['OCS']["readServer"], $sql_ban_arg);

      while ($item_ban = mysqli_fetch_array($result_ban)) {
        if($item_ban != null){
          $sql_remove = "DELETE FROM cve_search WHERE NAME_ID = %s";
          $sql_remove_arg = array($item_ban["NAME_ID"]);
          $result = mysql2_query_secure($sql_remove, $_SESSION['OCS']["writeServer"], $sql_remove_arg);
        }
      }
    }
  }

  /**
   * Print verbose
   */
  public function verbose($config, $code) {
    if($config == 1) {
      switch($code) {
        case 1:
          error_log(print_r($this->CVE_SEARCH_URL." is not reachable.",true));
        break;
        case 2:
          error_log(print_r($this->cve_history['CVE_NB']." CVE has been added to database",true));
        break;
        case 3:
          error_log(print_r("CVE feature isn't enabled", true));
        break;
        case 4:
          error_log(print_r("Get software publisher ...", true));
        break;
        case 5:
          error_log(print_r("Software publisher OK ... \nCVE treatment started ... \nPlease wait, CVE processing is in progress. It could take a few hours", true));
        break;
        case 6:
          error_log(print_r("Processing ".$this->publisherName." softwares ...", true));
        break;
        case 7:
          error_log(print_r($values['id']." has been referenced for ".$software["REAL_NAME"], true));
        break;
      }
    }
  }

  /**
   *  Add regex correspondance
   */
  public function add_regex($regex, $publish = null, $name = null) {
    $sql = "INSERT INTO cve_search_correspondance (NAME_REG, PUBLISH_RESULT, NAME_RESULT) VALUES ('%s','%s','%s')";
    $arg = array($regex, $publish, $name);
    $result = mysql2_query_secure($sql, $_SESSION['OCS']["writeServer"], $arg);

    return $result;
  }
}

?>