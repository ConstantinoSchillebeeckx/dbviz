<?php

// This is a helper script (and class) for parsing a
// database structure into JSON including schemas,
// tables, fields.



/**
 *
 * Initialize a new PDO database connection.
 *
 * @param void
 * @return PDO object
 *
*/

function get_db_conn() {

    define('HOST_DB','127.0.0.1');
    define('USER_DB','root');
    define('PASS_DB','');

    // Initialize connection
    try {

        $dsn = sprintf('mysql:host=%s;charset=UTF8', HOST_DB);
        $conn = new PDO($dsn, USER_DB, PASS_DB);

        // set the PDO error mode to silent
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        return $conn;

    } catch(PDOException $e) {
        echo " Could not connect to DB: " . $e->getMessage();
    }


}


/**
 *
 * Get the database setup using the 'Database class'
 *
 * @param void
 *
 * @return Database class
 *
*/

function get_db_setup() {

    require_once "db.class.php"; // Database class

    $db = new Database( get_db_conn( ) );

    return $db;

}



// get our data
echo get_db_setup()->asJSON();


?>
