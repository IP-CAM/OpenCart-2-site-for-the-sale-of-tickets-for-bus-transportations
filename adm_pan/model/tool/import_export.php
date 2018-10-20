<?php


use Box\Spout\Reader\ReaderFactory;
use Box\Spout\Writer\WriterFactory;
use Box\Spout\Common\Type;

class ModelToolImportExport extends Model{
  public function import($path){
    $exclusion_sheet = ['product','waypoint_to_route','url_alias', 'product_to_category']; //this is exclusion for setDependentTable function
    try{
        $reading_data = $this->readFile($path);
        $last_id = $this->setProducts($reading_data['product']);
        $this->setDependentTable($reading_data, $last_id, $exclusion_sheet);
        $this->updateDataCategory($reading_data['product_to_category'], $last_id);
        $this->setUrlAliace($reading_data['url_alias'], $last_id);
        $this->setWayPointToRoute($reading_data['waypoint_to_route'], $last_id);
        return $last_id;
     }
    catch(Exception $e){
       echo 'Catch exception: ',  $e->getMessage(), "\n";
     }
  }

    /**
     * @param $path
     * @return array
     * @throws \Box\Spout\Common\Exception\UnsupportedTypeException
     */

  protected function readFile($path){
    $reader = ReaderFactory::create(Type::XLSX);
    $reader->open($path);
    $dataArray = array();
    foreach ($reader->getSheetIterator() as $sheet) {
      $tempArray = array(); //temporary array
      $head_sheet = array();
       foreach ($sheet->getRowIterator() as $key => $row) {
         if($key === 1){
           $head_sheet = $row;
         }
         else{
           $tempArray[] =  array_reduce(array_keys($head_sheet),
            function($acc,$val) use ($head_sheet,$row){
             if(!empty($row[$val]) && $row[$val] !== 0){
             $acc[$head_sheet[$val]] = $row[$val];
             }
                if(isset($row[$val]) && $row[$val] === 0)
                {
                    $acc[$head_sheet[$val]] = '0';
                }
              return $acc;
             },array());
         }
       }
     $dataArray[$sheet->getName()] = $tempArray;
     }
    $reader->close();
    return $dataArray;
  }

    /**
     * @param $array_product
     * @return array
     */
  protected function setProducts($array_product){
    $last_id = array();
    foreach ($array_product as $key => $value) {
        if (array_key_exists('product_id', $value)) {
            $this->updateProduct($value);
            $last_id[] = $value['product_id'];
        } else {
            $sql = "INSERT INTO " .DB_PREFIX. "product SET ";
            $sql .= array_reduce(array_keys($value), function($carry,$key) use($value) {
                if(!empty($key) && !empty($value[$key]) ){
                    if((strcasecmp($key,'from_t') == 0 || strcasecmp($key,'to_t') == 0)
                        && !is_numeric($value[$key]) ){
                        $query = $this->db->query("SELECT c.city_id FROM ".DB_PREFIX."city c WHERE c.name = '".$value[$key]."'");
                        if(array_key_exists('city_id',$query->row)){
                            return $carry." ".$key." = '" .$query->row['city_id']."',";
                        }
                        throw new \Exception('not find city_id in ' . $value[$key]);
                    }
                    return $carry." ".$key." = '" .$value[$key]."',";
                } else {
                    return $carry;
                }
            },"");
            $this->db->query(rtrim($sql,", "));
            $last_id[] = $this->db->getLastId();
        }

    }
   return $last_id;
  }

    /**
     * @param $array_dependent
     * @param $products_last_id
     * @param array $exclusion_sheet_name
     */
  protected function setDependentTable($array_dependent, $products_last_id, $exclusion_sheet_name = []){
    $new_array = array();
    foreach ($array_dependent as $name_sheet => $values) {
      if (in_array($name_sheet, $exclusion_sheet_name )) continue;
        $temp_array = array_map(function($value, $product_id){
          $value['product_id'] = $product_id;
          return $value;
        },$values,$products_last_id);
      $new_array[$name_sheet] = $temp_array;
    }

    foreach ($new_array as $nameTable => $values) {
        $updateValue = $this->existDataInTable($nameTable, $products_last_id);
        if (!empty($updateValue)) {
            $this->updateDataInDependentTable($nameTable, $values, $updateValue);
        } else {
            $this->insertDataInDependentTable($nameTable, $values);
        }

    }

  }

    /**
     * @param $array_url_alice
     * @param $products_id
     */
    protected function setUrlAliace($array_url_alice, $products_id){
    $temp_array = array();
      if ($array_url_alice) {
          $temp_array[] = array_map(function($a, $b){
              $a['query'] = trim("product_id=$b");
              $a['keyword'] =trim("квиток-на-автобус-".$a['keyword']."-купити-онлайн");
              return $a;
          },$array_url_alice,$products_id);

          foreach ($temp_array as $nameTable => $values) {
              foreach ($values as $key => $value) {
                  $sql = "INSERT INTO ".DB_PREFIX."url_alias SET ";
                  $sql .= array_reduce(array_keys($value), function($carry,$val) use($value){
                      return $carry." ".$val." = '" .$value[$val]."',";
                  },"");
                  $this->db->query(rtrim($sql,", "));
              }
          }
      }
  }

    /**
     * @param $array_waypoint
     * @param $products_id
     */
    protected function setWayPointToRoute($array_waypoint, $products_id) {
      if ($array_waypoint) {
          $formatted_array = array_reduce($array_waypoint, function($acc, $item) use ($products_id) {
              if (isset($item['product_id'])) {
                  list($name, $value) = explode('=',$item['product_id'] );
                  if(empty($products_id[$value-1])) return $acc;
                  $acc[$products_id[$value-1]][] = $item['waypoint_id'];
              }
              return $acc;
          }, []);
          foreach ($formatted_array as $product_id => $waypoints) {
              $sql = "INSERT INTO ".DB_PREFIX."waypoint_to_route VALUES ";
              $sql.= array_reduce($waypoints, function($acc, $waypoint_id) use ($product_id) {
                  return $acc.="('$product_id', '$waypoint_id',''),";
              },"");
              $this->db->query(rtrim($sql,", "));
          }
      }
  }

    /**
     * @param $productData
     */
    private function updateProduct($productData) {
        $sqlQuery = 'UPDATE ' . DB_PREFIX . 'product SET';
        $sqlQuery .= array_reduce(array_keys($productData), function($carry,$key) use($productData) {
            if(!empty($key)){
                if((strcasecmp($key,'from_t') == 0 || strcasecmp($key,'to_t') == 0)
                    && !is_numeric($productData[$key]) ){
                    $query = $this->db->query("SELECT c.city_id FROM ".DB_PREFIX."city c WHERE c.name = '".$productData[$key]."'");
                    if(array_key_exists('city_id',$query->row)){
                        return $carry." ".$key." = '" .$query->row['city_id']."',";
                    }
                    throw new \Exception('not find city_id in ' . $productData[$key]);
                }
                return $carry." ".$key." = '" .$productData[$key]."',";
            } else {
                return $carry;
            }
        },"");
        $sqlQuery = rtrim($sqlQuery,", ");
        $sqlQuery .= sprintf(' WHERE product_id = \'%s\'', $productData['product_id']);
        $this->db->query($sqlQuery);
    }

    /**
     * @param $nameTable
     * @param $data
     * @param $updateData
     */
    private function updateDataInDependentTable($nameTable, $data, $updateData)
    {
        foreach ($data as $key => $value) {
            $isUpdate = array_key_exists($value['product_id'], $updateData);
            if ($isUpdate) {
                $sqlOperation = 'UPDATE';
                $sql = sprintf('%s %s%s SET ',$sqlOperation, DB_PREFIX, $nameTable);
                $sql .= array_reduce(array_keys($value), function($carry,$val) use($value){
                    if(!empty($val) && !empty($value[$val]) ){
                        return $carry." ".$val." = '" .$value[$val]." ',";
                    }
                    else{
                        return $carry;
                    }
                },"");

                $sql = rtrim($sql,", ").sprintf(' WHERE product_id = \'%s\'', $value['product_id']);
                $this->db->query(rtrim($sql,", "));
            }

        }

    }

    private function insertDataInDependentTable($nameTable, $data)
    {
        $sql = "INSERT INTO " .DB_PREFIX. "$nameTable ";
        if (!empty($data) && is_array($data)) {
            $first = $data[0];
            $sql .= "(" . implode(", ", array_keys($first)) . ") VALUES ";

            $sql .= array_reduce($data, function ($acc, $data) {
                $quoteData = array_map(function ($value){
                    return "'". quotemeta($value) ."'";
                }, array_values($data));
                $acc .=  "(" . implode(", ",  $quoteData) . "), ";
                return $acc;
            }, " ");
            
            $sql = rtrim($sql,", ") . ";";
            $this->db->query($sql);
        }
    }

    /**
     * @param $arrayCategoryToProduct
     * @param $last_id
     * @throws Exception
     */
    private function updateDataCategory($arrayCategoryToProduct, $last_id) {
//            $this->db->query('TRUNCATE TABLE oc_product_to_category');
            $sql = "INSERT INTO ".DB_PREFIX."product_to_category VALUES ";

            if (!empty($arrayCategoryToProduct)) {
                foreach ($arrayCategoryToProduct as $key => $value) {
                    $sql .= sprintf(
                        "(%s, %s, %s),",
                        isset($value['product_id']) ? $value['product_id']: $last_id[$key] ,
                        $value['category_id'],
                        $value['main_category']
                    );
                }
                $this->db->query(rtrim($sql,", "));
            } else {

                throw new \Exception('table oc_product_to_category  not created');
            }


    }

    /**
     * @param $tableName
     * @param $data
     * @return mixed
     */
    private function existDataInTable($tableName, $data)
    {
        $sqlQuery = 'SELECT product_id FROM '.DB_PREFIX.$tableName.' WHERE product_id IN ';
        $sqlQuery .= sprintf('(%s)', implode(',', $data));
        $result = $this->db->query($sqlQuery);
        return array_reduce($result->rows, function($acc, $value) {
            $acc[$value['product_id']] = 0;
            return $acc;
        }, []);
    }
}
