<?php


function ensure_db_ok ($sql_stmt) {
  if($sql_stmt == False) {
    echo "Database is busy";
    header("refresh:1;");
    exit;
  }
}
