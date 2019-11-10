<?php
/**
 * @file database.php
 * @date 2018-04-13
 * @author Go Namhyeon <gnh1201@gmail.com>
 * @brief Database module
 */

if(!check_function_exists("get_db_driver")) {
    function get_db_driver() {
        $config = get_config();
        return get_value_in_array("db_driver", $config, false);
    }
}

if(!check_function_exists("check_db_driver")) {
    function check_db_driver($db_driver) {
        return (get_db_driver() == $db_driver);
    }
}

if(!check_function_exists("get_db_connect")) {
    function get_db_connect($a=3, $b=0) {
        $conn = false;
        $config = get_config();
        $db_driver = get_db_driver();

        if(in_array($db_driver, array("mysql", "mysql.pdo"))) {
            try {
                $conn = new PDO(
                    sprintf(
                        "mysql:host=%s;dbname=%s;charset=utf8",
                        $config['db_host'],
                        $config['db_name']
                    ),
                    $config['db_username'],
                    $config['db_password'],
                    array(
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
                    )
                );
                //$conn->query("SET NAMES 'utf8'");
            } catch(Exception $e) {
                if($b > $a) {
                    set_error($e->getMessage());
                    show_errors();
                } else {
                    $b++;
                    sleep(0.03);
                    $conn = get_db_connect($a, $b);
                }
            }
        } elseif(loadHelper("database.alt")) {
            $conn = call_user_func("get_db_alt_connect", $db_driver);
        }

        return $conn;
    }
}

if(!check_function_exists("exec_stmt_query")) {
    function exec_stmt_query($sql, $bind=array()) {
        $stmt = get_db_stmt($sql, $bind);
        $stmt->execute();

        return $stmt;
    }
}

if(!check_function_exists("get_dbc_object")) {
    function get_dbc_object($renew=false) {
        $dbc = get_scope("dbc");

        if($renew) {
            set_scope("dbc", get_db_connect());
        }

        return get_scope("dbc");
    }
}


// 2018-08-19: support lower php version (not supported anonymous function)
if(!function_exists("compare_db_key_length")) {
    function compare_db_key_length($a, $b) {
        return strlen($b) - strlen($a);
    }
}

if(!function_exists("get_db_binded_sql")) {
    function get_db_binded_sql($sql, $bind=array()) {
        if(is_array($bind) && check_array_length($bind, 0) > 0) {
            $bind_keys = array_keys($bind);

            // 2018-08-19: support lower php version (not supported anonymous function)
            usort($bind_keys, "compare_db_key_length");

            // bind values
            foreach($bind_keys as $k) {
                $sql = str_replace(":" . $k, "'" . addslashes($bind[$k]) . "'", $sql);
            }
        }
        return $sql;
    }
}

if(!check_function_exists("get_db_stmt")) {
    function get_db_stmt($sql, $bind=array(), $bind_pdo=false, $show_sql=false) {
        $sql = !$bind_pdo ? get_db_binded_sql($sql, $bind) : $sql;
        $stmt = get_dbc_object()->prepare($sql);

        if($show_sql) {
            set_error($sql, "DATABASE-SQL");
            show_errors(false);
        }

        // bind parameter by PDO statement
        if($bind_pdo) {
            if(check_array_length($bind, 0) > 0) {
                foreach($bind as $k=>$v) {
                    $stmt->bindParam(':' . $k, $v);
                }
            }
        }

        return $stmt;
    }
}

if(!check_function_exists("get_db_last_id")) {
    function get_db_last_id() {
        $last_id = false;

        $dbc = get_dbc_object();
        $config = get_config();
        $db_driver = get_db_driver();

        if(in_array($db_driver, array("mysql", "mysql.pdo"))) {
            $last_id = $dbc->lastInsertId();
        } elseif(loadHelper("database.dbt")) {
            $last_id = call_user_func("get_db_alt_last_id", $db_driver);
        }

        return $last_id;
    }
}

if(!check_function_exists("exec_db_query")) {
    function exec_db_query($sql, $bind=array(), $options=array()) {
        $dbc = get_dbc_object();

        $validOptions = array();
        $optionAvailables = array("is_check_count", "is_commit", "display_error", "show_debug", "show_sql");
        foreach($optionAvailables as $opt) {
            if(!array_key_empty($opt, $options)) {
                $validOptions[$opt] = $options[$opt];
            } else {
                $validOptions[$opt] = false;
            }
        }
        extract($validOptions);

        $flag = false;
        $is_insert_with_bind = false;

        $sql_terms = explode(" ", $sql);
        if($sql_terms[0] == "insert") {
            $stmt = get_db_stmt($sql);
            if(check_array_length($bind, 0) > 0) {
                $is_insert_with_bind = true;
            }
        } else {
            if($show_sql) {
                $stmt = get_db_stmt($sql, $bind, false, true);
            } else {
                $stmt = get_db_stmt($sql, $bind);
            }
        }

        if($is_commit) {
            $dbc->beginTransaction();
        }

        // execute statement (insert->execute(bind) or if not, sql->bind->execute)
        $stmt_executed = $is_insert_with_bind ? $stmt->execute($bind) : $stmt->execute();

        if($show_debug) {
            $stmt->debugDumpParams();
        }

        if($display_error) {
            $error_info = $stmt->errorInfo();
            if(check_array_length($error_info, 0) > 0) {
                set_error(implode(" ", $error_info), "DATABASE-ERROR");
            }
            show_errors(false);
        }

        if($is_check_count == true) {
            if($stmt_executed && $stmt->rowCount() > 0) {
                $flag = true;
            }
        } else {
            $flag = $stmt_executed;
        }

        if($is_commit) {
            $dbc->commit();
        }

        if($flag === false) {
            set_error(get_hashed_text($sql), "DATABASE-QUERY-FAILURE");
        }

        return $flag;
    }
}

if(!check_function_exists("exec_db_fetch_all")) {
    function exec_db_fetch_all($sql, $bind=array(), $options=array()) {
        $response = array();

        $is_not_countable = false;
        $_cnt = 0;

        $rows = array();
        $stmt = get_db_stmt($sql, $bind);

        if($stmt->execute() && $stmt->rowCount() > 0) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // 1.6 or above
        $_rows = array();
        if(array_key_equals("getvalues", $options, true)) {
            foreach($rows as $row) {
                $_rows[] = array_values($row);
            }
            $rows = $_rows;
        }
        
        if(array_key_equals("do_count", $options, true)) {
            $_sql = sprintf("select count(*) as cnt from (%s) a", get_db_binded_sql($sql, $bind));
            $_data = exec_db_fetch($_sql);
            $_cnt = get_value_in_array("cnt", $_data, $_cnt);
        } elseif(array_key_equals("do_count", $options, "count")) {
            $_cnt = count($rows);
        } elseif(array_key_equals("do_count", $options, "PDOStatement::rowCount")) {
            $_cnt = $stmt->rowCount();
        } else {
            $response = $rows;
            $is_not_countable = true;
        }

        if(!$is_not_countable) {
            $response = array(
                "length" => $_cnt, // compatible with 1.4 or lower
                "cnt" => $_cnt,
                "data" => $rows,
            );
        }
        
        return $response;
    }
}

if(!check_function_exists("exec_db_fetch")) {
    function exec_db_fetch($sql, $bind=array(), $start=0, $bind_limit=false) {
        $fetched = NULL;
        $rows = array();

        if($bind_limit == true) {
            $sql = $sql . " limit 1";
        }
        $rows = exec_db_fetch_all($sql, $bind);

        if(check_array_length($rows, $start) > 0) {
            $idx = 0;
            foreach($rows as $row) {
                if($idx >= $start) {
                    $fetched = $row;
                    break;
                }
                $idx++;
            }
        }

        return $fetched;
    }
}

if(!check_function_exists("get_page_range")) {
    function get_page_range($page=1, $limit=16) {
        $append_sql = "";

        if($page < 1) {
            $page = 1;
        }

        if($limit > 0) {
            $record_start = ($page - 1) * $limit;
            $number_of_records = $limit;
            $append_sql .= sprintf(" limit %s, %s", $record_start, $number_of_records);
        }

        return $append_sql;
    }
}

if(!check_function_exists("get_bind_to_sql_insert")) {
    function get_bind_to_sql_insert($tablename, $bind, $options=array()) {
        // check ignore
        if(!array_key_empty("ignore", $options)) {
            $cnt = intval(
                get_value_in_array("cnt", exec_db_fetch(get_bind_to_sql_select($tablename, false, array(
                    "getcnt" => true,
                    "setwheres" => $options['ignore']
                )), false), 0)
            );
            if($cnt > 0) {
                return "select " . $cnt;
            }
        }

        // make SQL statement
        $bind_keys = array_keys($bind);
        $sql = "insert into %s (%s) values (:%s)";

        $s1 = $tablename;
        $s2 = sprintf("`%s`", implode("`, `", $bind_keys));
        $s3 = implode(", :", $bind_keys);

        $sql = sprintf($sql, $s1, $s2, $s3);
        
        return $sql;
    }
}

// Deprecated: get_bind_to_sql_where($bind, $excludes) - lower than 1.6
// Now: get_bind_to_sql_where($bind, $options, $_options) - 1.6 or above

if(!check_function_exists("get_bind_to_sql_where")) {
    // warning: variable k is not protected. do not use variable k and external variable without filter
    function get_bind_to_sql_where($bind, $options=array(), $_options=array()) {
        $s3 = "";
        $sp = "";

        $excludes = get_value_in_array("excludes", $options, array());
        
        // compatible version 1.5
        if(array_key_equals("compatible", $_options, "1.5")) {
            $excludes = $options;
        }

        if(is_array($bind)) {    
            foreach($bind as $k=>$v) {
                if(!in_array($k, $excludes)) {
                    $s3 .= sprintf(" and %s = :%s", $k, $k);
                }
            }
        }
        
        if(!array_keys_empty(array("settimefield", "setminutes"), $options)) {
            $s3 .= get_bind_to_sql_past_minutes($options['settimefield'], $options['setminutes']);
        }

        if(!array_key_empty("setwheres", $options)) {
            if(is_array($options['setwheres'])) {
                foreach($options['setwheres'] as $opts) {
                    if(check_is_string_not_array($opts)) {
                        $s3 .= sprintf(" and (%s)", $opts);
                    } elseif(check_array_length($opts, 3) == 0 && is_array($opts[2])) {
                        $s3 .= sprintf(" %s (%s)", $opts[0], get_db_binded_sql($opts[1], $opts[2]));
                    } elseif(check_array_length($opts, 2) == 0 && is_array($opts[1])) {
                        if(is_array($opts[1][0])) {
                            // recursive
                            $s3 .= sprintf(" %s (%s)", $opts[0], get_bind_to_sql_where(false, array(
                                "setwheres" => $opts[1]
                            )));
                        } elseif($opts[1][0] == "like") {
                            if(check_array_length($opts[1][2], 0) > 0) {
                                $s3a = array();
                                foreach($opts[1][2] as $word) {
                                    $s3a[] = sprintf("%s like '%s'", get_value_in_array($opts[1][1], $s1a, $opts[1][1]), "%{$word}%");
                                }
                                $s3 .= sprintf(" %s (%s)", $opts[0], implode(" and ", $s3a));
                            } else {
                                $s3 .= sprintf(" %s (%s like %s)", $opts[0], get_value_in_array($opts[1][1], $s1a, $opts[1][1]), "'%{$opts[1][2]}%'");
                            }
                        } elseif($opts[1][0] == "left") {
                            if(check_array_length($opts[1][2], 0) > 0) {
                                $s3a = array();
                                foreach($opts[1][2] as $word) {
                                    $s3a[] = sprintf("%s like '%s'", get_value_in_array($opts[1][1], $s1a, $opts[1][1]), "{$word}%");
                                }
                                $s3 .= sprintf(" %s (%s)", $opts[0], implode(" and ", $s3a));
                            } else {
                                $s3 .= sprintf(" %s (%s like %s)", $opts[0], get_value_in_array($opts[1][1], $s1a, $opts[1][1]), "'{$opts[1][2]}%'");
                            }
                        } elseif($opts[1][0] == "right") {
                            if(check_array_length($opts[1][2], 0) > 0) {
                                $s3a = array();
                                foreach($opts[1][2] as $word) {
                                    $s3a[] = sprintf("%s like '%s'", get_value_in_array($opts[1][1], $s1a, $opts[1][1]), "%{$word}");
                                }
                                $s3 .= sprintf(" %s (%s)", $opts[0], implode(" and ", $s3a));
                            } else {
                                $s3 .= sprintf(" %s (%s like %s)", $opts[0], get_value_in_array($opts[1][1], $s1a, $opts[1][1]), "'%{$opts[1][2]}'");
                            }
                        } elseif($opts[1][0] == "in") {
                            if(check_array_length($opts[1][2], 0) > 0) {
                                $s3 .= sprintf(" %s (%s in ('%s'))", $opts[0], $opts[1][1], implode("', '", $opts[1][2]));
                            }
                        } elseif($opts[1][0] == "set") {
                            if(check_array_length($opts[1][2], 0) > 0) {
                                $s3a = array();
                                foreach($opts[1][2] as $word) {
                                    $s3a[] = sprintf("find_in_set('%s', %s)", $word, $opts[1][1]);
                                }
                                $s3 .= sprintf(" %s (%s)", $opts[0], implode(" and ", $s3a));
                            }
                        } else {
                            $ssts = array(
                                "eq" => "=",
                                "lt" => "<",
                                "lte" => "<=",
                                "gt" => ">",
                                "gte" => ">=",
                                "not" => "<>"
                            );
                            $opcode = get_value_in_array($opts[1][0], $ssts, $opts[1][0]);
                            if(!empty($opcode)) {
                                $s3 .= sprintf(" %s (%s %s '%s')", $opts[0], $opts[1][1], $opcode, $opts[1][2]);
                            }
                        }
                    } elseif(check_array_length($opts, 2) == 0) {
                        $s3 .= sprintf(" %s (%s)", $opts[0], $opts[1]);
                    }
                }
            }
        }

        if(!array_key_empty("sql_where", $options)) {
            $s3 .= sprintf(" %s", $options['sql_where']);
        }

        // set start prefix
        $s3 = trim($s3);
        $s3a = strpos($s3, " ");
        $s3b = "";
        if($s3a !== false) {
            $s3b = substr($s3, 0, $s3a);
        }
        if($s3b == "and") {
            $sp = "1";
        } elseif($s3b == "or") {
            $sp = "0";
        }

        return sprintf("%s %s", $sp, $s3);
    }
}

if(!check_function_exists("get_bind_to_sql_update_set")) {
    // warning: variable k is not protected. do not use variable k and external variable without filter
    function get_bind_to_sql_update_set($bind, $excludes=array()) {
        $sql_update_set = "";
        $set_items = "";

        foreach($bind as $k=>$v) {
            if(!in_array($k, $excludes)) {
                $set_items[] = sprintf("%s = :%s", $k, $k);
            }
        }
        $sql_update_set = implode(", ", $set_items);

        return $sql_update_set;
    }
}

if(!check_function_exists("get_bind_to_sql_select")) {
    // warning: variable k is not protected. do not use variable k and external variable without filter
    function get_bind_to_sql_select($tablename, $bind=array(), $options=array()) {
        $sql = "select %s from %s where %s %s %s";

        // s1: select fields
        $s1 = "";
        if(!array_key_empty("fieldnames", $options)) {
            $s1 .= (check_array_length($options['fieldnames'], 0) > 0) ? implode(", ", $options['fieldnames']) : "*";
        } elseif(array_key_equals("getcnt", $options, true)) {
            $s1 .= sprintf("count(%s) as cnt", ($options['getcnt'] === true ? "*" : $options['getcnt']));
        } elseif(!array_key_empty("getsum", $options)) {
            $s1 .= sprintf("sum(%s) as sum", $options['getsum']);
        } else {
            $s1 .= "*";
        }
        
        // s1a: s1 additonal (set new fields)
        $s1a = array();
        if(array_key_is_array("setfields", $options)) {
            $setfields = $options['setfields'];

            foreach($setfields as $k=>$v) {
                // add
                if(!array_keys_empty("add", $v)) {
                    $s1a[$k] = sprintf("(%s + %s)", $k, $v['add']);
                }
                
                // sub
                if(!array_keys_empty("sub", $v)) {
                    $s1a[$k] = sprintf("(%s - %s)", $k, $v['sub']);
                }
                
                // mul
                if(!array_key_empty("mul", $v)) {
                    $s1a[$k] = sprintf("(%s * %s)", $k, $v['mul']);
                }
                
                // div
                if(!array_key_empty("div", $v)) {
                    $s1a[$k] = sprintf("(%s / %s)", $k, $v['div']);
                }
                
                // eval (warning: do not use if you did not understand enough)
                if(!array_key_empty("eval", $v)) {
                    $s1a[$k] = sprintf("(%s)", $k, $v['eval']);
                }

                // concat and delimiter
                if(!array_keys_empty("concat", $v)) {
                    $delimiter = get_value_in_array("delimiter", $v, " ");
                    $s1a[$k] = sprintf("concat(%s)", implode(sprintf(", '%s', ", $delimiter), $v['concat']));
                }

                // use mysql function
                if(!array_key_empty("call", $v)) {
                    if(check_array_length($v['call'], 1) > 0) {
                        // add to s1a
                        $s1a[$k] = sprintf("%s(%s)", $v['call'][0], implode(", ", array_slice($v['call'], 1)));
                    }
                }

                // use simple distance
                if(!array_key_empty("simple_distance", $v)) {
                    if(check_array_length($v['simple_distance'], 2) == 0) {
                        $a = floatval($v['simple_distance'][1]); // percentage (range 0 to 1)
                        $b = $v['simple_distance'][0]; // field or number
                        $s1a[$k] = sprintf("abs(1.0 - (abs(%s - %s) / %s))", $b, $a, $a);
                    }
                }
            }
        }

        // s2: set table name
        $s2 = "";
        if(!empty($tablename)) {
            $s2 .= $tablename;
        } else {
            set_error("tablename can not empty");
            show_errors();
        }

        // s3: fields of where clause
        $s3 = get_bind_to_sql_where($bind, $options);

        // s4: set orders
        $s4 = "";
        $s4a = array();
        if(!array_key_empty("setorders", $options)) {
            if(is_array($options['setorders'])) {
                $s4 .= "order by ";
                foreach($options['setorders'] as $opts) {
                    if(check_is_string_not_array($opts)) {
                        $s4a[] = $opts;
                    } elseif(check_array_length($opts, 2) == 0) {
                        // example: array("desc", "datetime")
                        $s4a[] = sprintf("%s %s", get_value_in_array($opts[1], $s1a, $opts[1]), $opts[0]);
                    }
                }
                $s4 .= implode(", ", $s4a);
            }
        }

        // s5: set page and limit
        $s5 = "";
        if(!array_keys_empty(array("setpage", "setlimit"), $options)) {
            $s5 .= get_page_range($options['setpage'], $options['setlimit']);
        }

        // sql: make completed sql
        $sql = sprintf($sql, $s1, $s2, $s3, $s4, $s5);

        return $sql;
    }
}

// Deprecated: get_bind_to_sql_update($tablename, $bind, $filters, $options) - lower than 1.6
// Now: get_bind_to_sql_update($tablename, $bind, $options, $_options) - 1.6 or above

if(!check_function_exists("get_bind_to_sql_update")) {
    function get_bind_to_sql_update($tablename, $bind, $options=array(), $_options=array()) {
        $excludes = array();
        $_bind = array();

        // compatible version 1.5
        if(array_key_equals("compatible", $_options, "1.5")) {
            foreach($options as $k=>$v) {
                if($v == true) {
                    $excludes[] = $k;
                }
            }
            $options = $_options;
        }

        // setkeys
        if(!array_key_empty("setkeys", $options)) {
            $setkeys = $options['setkeys'];
            foreach($bind as $k=>$v) {
                if(in_array($k, $setkeys)) {
                    $_bind[$k] = $v;
                    $excludes[] = $k;
                }
            }
        }

        // add excludes to options
        if(!array_key_exists("excludes", $options)) {
            $options['excludes'] = array();
        }
        foreach($excludes as $k=>$v) {
            $options['excludes'][$k] = $v;
        }

        // make sql 'where' clause
        $sql_where = get_db_binded_sql(get_bind_to_sql_where($_bind, $options), $_bind);
        
        // make sql 'update set' clause
        $sql_update_set = get_bind_to_sql_update_set($bind, $excludes);

        // make completed sql statement
        $sql = sprintf("update %s set %s where %s", $tablename, $sql_update_set, $sql_where);

        return $sql;
    }
}

if(!check_function_exists("get_bind_to_sql_delete")) {
    function get_bind_to_sql_delete($tablename, $bind, $options=array()) {
        $sql = sprintf("delete from %s where %s", $tablename, get_db_binded_sql(get_bind_to_sql_where($bind, $options), $bind));
        return $sql;
    }
}

if(!check_function_exists("get_bind_to_sql_past_minutes")) {
    function get_bind_to_sql_past_minutes($fieldname, $minutes=5) {
        $sql_past_minutes = "";
        if($minutes > 0) {
            $sql_past_minutes = sprintf(" and %s > DATE_SUB(now(), INTERVAL %d MINUTE)", $fieldname, $minutes);
        }
        return $sql_past_minutes;
    }
}

// SQL eXtensible
if(!check_function_exists("get_bind_to_sqlx")) {
    function get_bind_to_sqlx($filename, $bind, $options=array()) {
        $result = false;
        $sql = read_storage_file(get_file_name($filename, "sqlx"), array(
            "storage_type" => "sqlx"
        ));

        if(!empty($sql)) {
            $result = get_db_binded_sql($sql, $bind);
        }
        return $result;
    }
}

// alias sql_query from exec_db_query
if(!check_function_exists("sql_query")) {
    function sql_query($sql, $bind=array(), $options=array()) {
        return exec_db_query($sql, $bind, $options);
    }
}

// get timediff
if(!check_function_exists("get_timediff_on_query")) {
    function get_timediff_on_query($a, $b) {
        $dt = 0;

        $sql = "select timediff(:a, :b) as dt";
        $bind = array(
            "a" => $a,
            "b" => $b
        );
        $row = exec_db_fetch($sql, $bind);
        $dt = get_value_in_array("dt", $row, $dt);

        return $dt;
    }
}

// temporary table
if(!check_function_exists("exec_db_temp_create")) {
    function exec_db_temp_create($schemes=array(), $options=array()) {
        $_tablename = make_random_id();
        $_schemes = array();
        foreach($schemes as $k=>$v) {
            if(is_array($v)) {
                $_argc = count($v);
                if($_argc == 1) {
                    $_schemes[] = sprintf("%s %s", $k, $v[0]);
                } elseif($_argc == 2) {
                    $_schemes[] = sprintf("%s %s(%s)", $k, $v[0], $v[1]);
                } elseif($_argc == 3) {
                    $_schemes[] = sprintf("%s %s(%s) %s", $k, $v[0], $v[1], ($v[2] === true ? "not null" : ""));
                }
            }
        }
        $_sql = sprintf("create temporary table if not exists %s (%s)", $_tablename, implode(",", $_schemes));
        return (exec_db_query($_sql) ? $_tablename : false);
    }
}

if(!check_function_exists("exec_db_temp_start")) {
    function exec_db_temp_start($sql, $bind=array(), $options=array()) { 
        $_tablename = make_random_id();
        $_sql = sprintf("create temporary table if not exists %s as (%s)", $_tablename, get_db_binded_sql($sql, $bind));
        return (exec_db_query($_sql) ? $_tablename : false);
    }
}

// temporary table
if(!check_function_exists("exec_db_temp_end")) {
    function exec_db_temp_end($tablename, $options=array()) {
        $_sql = sprintf("drop temporary table %s", $tablename);
        return exec_db_query($_sql);
    }
}

// close db connection
if(!check_function_exists("close_db_connect")) {
    function close_db_connect() {
        $dbc = get_scope("dbc");
        $dbc->close();
        set_scope("dbc", null);
    }
}

// get assoc from json raw data
if(!check_function_exists("json_decode_to_assoc")) {
    function json_decode_to_assoc($data) {
        if(loadHelper("json.format")) {
            return json_decode_ex($data, array("assoc" => true));
        }
    }
}

