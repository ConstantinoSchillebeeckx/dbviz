<?php


/*------------------------------------*\
    database class
\*------------------------------------*/


/* Database class for loading structure of the database

Class properties:
- schemas : array of schemas in database
- struct : associative array where each schema is a key and
           the value is a Schema class
*/
class Database {

    public $schemas = array(); // list of schemas
    public $struct = array(); // associative array where each schema is key and each value is a Schema class

    public function __construct( $db ) {

        if ($db) {

            $query = $db->query('SHOW DATABASES')->fetchAll();
            if ($query !== true) {

                foreach ($query as $row) {
                    $schema_name = $row['Database'];
                
                    if ($schema_name != 'information_schema' && $schema_name != 'performance_schema') {
                        $this->schemas[] = $schema_name;   
                        $this->struct[$schema_name] = new Schema($schema_name, $db);
                    }
                }
            }
        }
    }

    // pretty print
    public function show() {
        echo '<pre style="font-size:8px;">';
        print_r($this);
        echo '</pre>';
    }

    // return the DB structure as JSON
    public function asJSON() {
        return json_encode( get_object_vars( $this ) );
    }
}





/* Schema class for loading structure of the database

Class properties:
- tables : array of tables associated with user's company
- struct : associative array where each table is a key and
           the value is a class Table
- db_name : name of database
*/
class Schema {

    public $tables = array(); // array of tables associated with user's company
    public $struct = array(); // associative array where each table is a key and the value is a class table()
    public $db_name = NULL; // DB name

    public function __construct( $db_name=null, $db ) {

        if ($db_name && $db) {

            $this->db_name = $db_name;

            // get list of tables
            $sql = "SELECT TABLE_NAME, TABLE_COMMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = '" . $this->get_name() . "' AND TABLE_TYPE = 'BASE TABLE'";
            $results = $db->query($sql)->fetchAll();
            if ($results !== true) {
                $comments = array();
                foreach ($results as $row ) {
                    $this->tables[] = $row["TABLE_NAME"];
                    $comments[$row["TABLE_NAME"]] = json_decode($row["TABLE_COMMENT"], true);
                }


                // check FKs for table
                $sql = sprintf("select concat(table_schema, '.', table_name, '.', column_name) as 'foreign key',  
                concat(referenced_table_schema, '.', referenced_table_name, '.', referenced_column_name) as 'references'
                from
                    information_schema.key_column_usage
                where
                    referenced_table_name is not null
                    and table_schema = '%s'", $this->get_name());
                $results = $db->query($sql)->fetchAll();
                $fks = array();
                if ($results !== true) {
                    foreach ($results as $row) {
                        $fks[$row["foreign key"]] = $row["references"];
                    }
                }

                // generate DB structure
                foreach ($this->tables as $table) {
                    $comment = $comments[$table];

                    $this->struct[$table] = new Table($db_name . "." . $table, $fks, $db, $comment);
                }
            }
        }
    }

    // return array of all table names
    // including data and history tables
    public function get_all_tables() {
        return $this->tables;
    }


    // return array of tables names
    // that are all data tables (not history)   
    // if none exist return empty array
    public function get_data_tables() {
        $data_tables = [];
        foreach ( $this->get_all_tables() as $table ) {
            $is_history = $this->get_table( $table )->is_history();
            if ( !$is_history ) $data_tables[] = $table;
        }

        if ( count( $data_tables ) ) {
            return $data_tables;
        } else {
            return array();
        }
    }

    // return assoc array of table struct
    public function get_struct() {
        return $this->struct;
    }

    // return name of DB
    public function get_name() {
        return $this->db_name;
    }

    // return field name that is pk, if it exists
    // otherwise return false
    public function get_pk($table) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            $tmp = $this->get_table($table);
            return $tmp->get_pk();
        } else {
            return false;
        }
    }

    // return the given table's description
    // if none set, will return false
    public function get_table_descrip($table) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            $tmp = $this->get_table($table);
            $comment = $tmp->get_comment();
            if (isset($comment['description'])) {
                return $comment['description'];
            }
        }
        return false;
    }


    // given a table (name) return its Table class
    public function get_table($table) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            return $this->get_struct()[$table];
        } else {
            return false;
        }
    }

    // given a table (name) return the columns that are unique,
    // if any, as an array; will only return visible fields
    public function get_unique($table) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            $tmp = $this->get_struct()[$table];
            if ($tmp->get_unique()) {
                return $tmp->get_unique();
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    // given a table name, will return an assoc array
    // where keys are fields which must be unique and
    // the value are the values that field currently has
    // will only return values for visible fields
    // if the optional $key_col is provided, the unique
    // values will be returned as an assoc array where the 
    // key is the value of the field specified by $key_col
    // this is used in the case where $key_col is unique  & required
    // and thus serves as a unique identifier for the row
    // used with batch edit file
    public function get_unique_vals( $table, $key_col=NULL ) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            $unique_cols = $this->get_unique( $table );

            $unique_vals = [];
            if ( $unique_cols !== False ) {
   
                if ($key_col) $keys = $this->get_field($table, $key_col)->get_unique_vals();
                foreach( $unique_cols as $field ) {
                    $vals = $this->get_field( $table, $field)->get_unique_vals();
                    if ($key_col) $vals = array_combine($keys, $vals);
                    if ( $vals !== False ) $unique_vals[$field] = $vals;
                }                

            }
            return $unique_vals;
        } else {
            return false;
        }
        
    }


    // given a table name and field, will return the fields
    // comment value as an assoc array
    public function get_comment($table, $field) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            $table_class = $this->get_struct()[$table];
            if ( in_array( $field, $table_class->get_fields() ) ) {
                $field_class = $table_class->get_field($field);
                $comment = $field_class->get_comment();
                if (is_array($comment)) {
                    return $comment;
                } else {
                    return [];
                }
            }
        }
    }


    // given a table (name) and field return its Field class
    public function get_field($table, $field) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            $table_class = $this->get_struct()[$table];
            return $table_class->get_field($field);
        } else {
            return false;
        }
    }

    // given a table name and a field, return true if field is required
    public function is_field_required( $table, $field ) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            $table_class = $this->get_struct()[$table];
            $field_class = $table_class->get_field($field);
            if ( $field_class !== false && $field_class->is_required() ) {
                return true;
            } else {
                return false;
            }
            
        } else {
            return false;
        }
    }


    // given a table return all fields (including hidden) in table as array
    public function get_all_fields($table) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            return $this->get_struct()[$table]->get_fields();
        } else {
            return array();
        }
    }

    // given a table return all fields formats (including hidden fields) 
    // as assoc array where keys are field names
    public function get_all_field_formats($table) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            $fields = $this->get_struct()[$table]->get_fields();
            $formats = [];

            foreach($fields as $field) {
                $formats[$field] = $this->get_struct()[$table]->get_field($field)->get_format();
            }

            return $formats;
            
        } else {
            return array();
        }
    }

    // given a table return all visible (non-hidden)
    // fields in table as array
    public function get_visible_fields($table) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            return $this->get_struct()[$table]->get_visible_fields();
        } else {
            return array();
        }
    }

    // given a table return all required fields in table as array
    public function get_required_fields($table) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            return $this->get_struct()[$table]->get_required();
        } else {
            return array();
        }
    }

    
    // given a table, will return an assoc arr where keys
    // are fields that have a FK, and the value are the 
    // FK values it can have
    public function get_fk_vals( $table ) {
        if ( in_array( $table, $this->get_all_tables() ) ) {
            $table_fields = $this->get_visible_fields( $table );

            $fk_obj = [];
            foreach ( $table_fields as $field ) {
                $fks = $this->get_field( $table, $field)->get_fks();
                if ( $fks !== False ) {
                    $fk_obj[$field] = $fks;
                }
            }

            return $fk_obj;

        } else {
            return false;
        }
    }

    
    // given a table and a field, will return unique
    // values for that field
    public function get_unique_vals_field( $table, $field ) {
        return $this->get_field($table, $field)->get_unique_vals();
    }

    // pretty print
    public function show() {
        echo '<pre style="font-size:8px;">';
        print_r($this);
        echo '</pre>';
    }


    // return the DB structure as JSON
    // in the form {table: {struct}, }
    // if table is provided, will only return
    // that table structure
    public function asJSON( $table = False) {
        if ( $table !== False ) {
            return json_encode( get_object_vars( $this->get_table( $table ) ) );
        } else {
            return json_encode( get_object_vars( $this ) );
        }
    }

}



/* Table class defines properties of a given database table

Class roperties:
- struct : associative array where each field is a key and
           the value is a class Field
- name : name of table with prepended company
- fields : array of fields contained in table

*/
class Table {

    public $fields = array();
    public $name = NULL;
    public $struct = array();
    public $comment;
    

    public function __construct($name, $fks, $db, $comment) {
        $this->name = $name;
        $this->comment = $comment; //json_decode($info[$name]["Comment"], true);

        // get list of fields
        $sql = sprintf("SHOW FULL COLUMNS FROM %s", $this->name);
        $info = array();
        foreach($db->query($sql) as $row) {
            $this->fields[] = $row["Field"];
            $info[$row['Field']] = array("Type" => $row['Type'], 
                                           "Null" => $row['Null'],
                                           "Key" => $row['Key'],
                                           "Default" => $row['Default'],
                                           "Extra" => $row['Extra'],
                                           "Comment" => $row['Comment']
                                            );
        }

        // get details of each field
        foreach ($this->fields as $field) {
            $this->struct[$field] = new Field($this->name, $field, $fks, $info, $db);
        }
     }

    // same as get_table()
    public function get_name() {
        return $this->name;
    }

    // return true if table is history counter part
    public function is_history() {
        return $this->is_history;
    }

    // return an array of non-hidden fields
    public function get_visible_fields() {
        $fields = array();
        if (count($this->fields)) {
            foreach($this->fields as $field) {
                if (!$this->get_field($field)->is_hidden()) {
                    $fields[] = $field;
                }
            }
        }
        return $fields;
    }

    // return array of fields in table
    public function get_fields() {
        return $this->fields;
    }

    // return comment attribute
    public function get_comment() {
        return $this->comment;
    }


    // return table struct as assoc array
    // keys are field names, values are Field class
    public function get_struct() {
        return $this->struct;
    }

    // given a field name, return the Field class
    public function get_field($field) {
        if ( in_array( $field, $this->get_fields() ) ) {
            return $this->get_struct()[$field];
        } else {
            return false;
        }
    }

    // check if table contains a field that is
    // referenced by an FK
    // if so, return the field name(s) [table.col] as an array
    public function get_ref() {
        $fields = $this->get_fields();
        $fks = array();  

        foreach($fields as $field) {
            $field_class = $this->get_field($field);
            if ($field_class->is_ref()) {

                $ref = $field_class->get_ref();
                $fks[] = $ref;
            }
        }

        if ( count($fks) > 0 ) {
            return $fks;
        } else {
            return false;
        }
    }

    // return field name that is primary key in table
    // returns false if none found
    public function get_pk() {
        $info = $this->get_struct();
        foreach ($info as $k => $v) { // $k = field name, $v Field class
            if ( $v->is_pk() ) {
                return $k;
            }
        }
        return false;
    }
    
    // return any fields that are both unique and required
    // this make the field a PK, however it isn't stored with
    // that index - will only return a visible field if it exists
    // otherwise will return an empty array
    public function get_visible_pk() {
        $info = $this->get_struct();
        $pks = [];
        foreach ($info as $k => $v) { // $k = field name, $v Field class
            if ( $v->is_required() && $v->is_unique() && $v->is_hidden() === False ) {
                $pks[] = $k;
            }
        }
        return $pks;
    }


    // return an array of fields that have
    // the unique property in the table
    // otherwise false
    // NOTE: only returns visible fields
    public function get_unique() {
        $info = $this->get_struct();
        $tmp = array();
        foreach ($info as $k => $v) { // $k = field name, $v Field class
            if ( $v->is_unique() && $v->is_hidden() == false ) {
                array_push($tmp, $k);
            }
        }
        if ( count($tmp) > 0 ) {
            return $tmp;
        } else {
            return false;
        }
    }



    // return an array of fields that are
    // are required in the table (cannot
    // be null) otherwise return false
    // NOTE: this will only return non-hidden
    // fields (e.g. ignores _UID)
    public function get_required() {
        $info = $this->get_struct();
        $tmp = array();
        foreach ($info as $k => $v) { // $k = field name, $v Field class
            if ( $v->is_required() && $v->is_hidden() == false ) {
                array_push($tmp, $k);
            }
        }

        if ( count($tmp) > 0 ) {
            return $tmp;
        } else {
            return false;
        }
    }




    // pretty print
    public function show() {
        echo '<pre style="font-size:8px;">';
        print_r($this);
        echo '</pre>';
    }
}

/* Field class defined properties of a given column in a table

Class properties:
- name : name of field (e.g. sampleType)
- is_fk : bool for if a field is a foreign key
- fk_ref : if a field is a foreign key, it references this field (full_name)
- hidden : bool for whether field should be hidden from front-end view
- is_ref : bool if field is referenced by a foreign key (this makes the field a primary key)
- ref : if field is referenced by a foreign key, this is the field that references it (full_name)
- type : field type (e.g. datetime, varchar, etc)
- required : bool if field is required (inverst of NULL property)
- key: can be empty, PRI, UNI or MUL (see https://dev.mysql.com/doc/refman/5.7/en/show-columns.html)
- default : default value of field
- extra : any additional information that is available about a given column
- table : name of table field belongs
- comment : extra data stored in comment field, e.g. column_format
*/
class Field {

    public $is_fk; 
    public $fk_ref;
    public $hidden; 
    public $is_ref;
    public $ref; 
    public $type;
    public $required;
    public $key;
    public $default;
    public $extra;
    public $name;
    public $table;
    public $comment;
    public $length;
    public $unique;

    public function __construct($table, $name, $fks, $info, $db) {
        $this->name = $name;
        $this->key = $info[$name]["Key"];
        $this->default = $info[$name]["Default"];
        $this->extra = $info[$name]["Extra"];
        $this->comment = json_decode($info[$name]["Comment"], true);
        $this->type = $info[$name]["Type"];
        $this->table = $table;
        $this->length = $this->get_length();
        $this->unique = $this->is_unique();

        // check if field is required
        if ( $info[$name]["Null"] == "YES" || in_array($this->type, array('timestamp', 'date') ) ) {
            $this->required = false;
        } else {
            $this->required = true;
        }

        // check if field is fk
        if (array_key_exists($table . '.' . $this->name, $fks)) {
            $this->is_fk = true;
            $this->fk_ref = $fks[$table . '.' . $this->name];
        } else {
            $this->is_fk = false;
            $this->fk_ref = false;
        }

        // check if field is referenced by fk
        $tmp = array_search($table . '.' . $this->name, $fks);
        if ($tmp) {
            $this->is_ref = true;
            $this->ref = $tmp;
        } else {
            $this->is_ref = false;
            $this->ref = false;
        }

    }

    // return true if field is a foreign key
    public function is_fk() {
        return $this->is_fk;
    }

    // return true if field is referenced by an FK
    public function is_ref() {
        return $this->is_ref;
    }

    // return the default value
    public function get_default() {
        return $this->default;
    }

    // return name of field (e.g. sample)
    public function get_name() {
        return $this->name;
    }

    // if a field is referenced by a FK
    // return the table.col it references
    public function get_ref() {
        return $this->ref;
    }

    // return name of table this field belongs to
    public function get_table() {
        return $this->table;
    }

    // return true if field is a primary key
    public function is_pk() {
        return $this->key == 'PRI' ? true : false;
    }

    // return field type
    public function get_type() {
        return $this->type;
    }

    // return field type length
    // if no type, return false,
    // if float, date or timestamp return null
    // if int or string, return int val
    public function get_length() {
        if ($this->type) {
            if (strpos($this->type, '(') !== false) {
                return intval(str_replace(')', '', explode('(', $this->type)[1]));
            } else {
                return NULL;
            }
        } else {
            return false;
        }
    }

    // return true if field is required
    public function is_required() {
        return $this->required;
    }

    // return true if field is unique (PRI or UNI key)
    public function is_unique() {
        return in_array($this->key, array('PRI','UNI'));
    }

    // return comment attribute
    public function get_comment() {
        return $this->comment;
    }

    // return format attribute
    // if not set, return null
    public function get_format() {
        $comment = $this->comment;
        return ($comment['column_format']) ? $comment['column_format'] : null;
    }

    // if a field is unique, return the current values
    // of the field, otherwise false
    public function get_unique_vals() {
        if ( $this->is_unique() ) {

            if ( !isset( $db ) ) $db = get_db_conn( $_SESSION['db_name'] );

            $sql = sprintf("SELECT DISTINCT(`%s`) FROM `%s`.`%s`", $this->get_name(), $_SESSION['db_name'], $this->get_table());
            $result = $db->query($sql)->fetchAll();
            $vals = array();

            if ($result) {
                foreach($result as $row) {
                    $vals[] = $row[$this->name];
                }
            }
            return $vals;
        } else {
            return false;
        }
    }

    // if a field is an fk, this will return
    // the table & field it references
    // in format [table, field]
    public function get_fk_ref() {
        if ( $this->is_fk ) {
            return explode('.',$this->fk_ref);
        }
        return false;
    }


    // will return a list of possible values a
    // field can take assuming it is an fk
    public function get_fks() {
        if ($this->is_fk) {

            if ( !isset( $db ) ) $db = get_db_conn( $_SESSION['db_name'] );

            $ref = explode('.',$this->fk_ref);
            $ref_table = $ref[0];
            $ref_field = $ref[1];
            $sql = sprintf( "SELECT DISTINCT(`%s`) from `%s`.`%s` ORDER BY `%s`", $ref_field, $_SESSION['db_name'], $ref_table, $ref_field );
            $res = $db->query($sql)->fetchAll();
            $vals = array();

            foreach ($res as $row) {
                $vals[] = $row[$ref_field];
            }
            if ( count( $vals ) > 0 ) {
                return $vals;
            } else {
                return false;
            }

        } else {
            return false;
        }
    }

    // pretty print
    public function show() {
        echo '<pre style="font-size:8px;">';
        print_r($this);
        echo '</pre>';
    }
}




?>
