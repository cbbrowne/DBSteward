<?php
/**
 * Manipulate table nodes
 *
 * @package DBSteward
 * @subpackage pgsql8
 * @license http://www.opensource.org/licenses/bsd-license.php Simplified BSD License
 * @author Nicholas J Kiraly <kiraly.nicholas@gmail.com>
 */

class pgsql8_table extends sql99_table {

  /**
   * Creates and returns SQL for creation of the table.
   *
   * @return created SQL command
   */
  public function get_creation_sql($node_schema, $node_table) {
    if ( $node_schema->getName() != 'schema' ) {
      throw new exception("node_schema object element name is not schema. check stack for offending caller");
    }

    if ( $node_table->getName() != 'table' ) {
      throw new exception("node_table object element name is not table. check stack for offending caller");
    }

    $table_name = pgsql8_diff::get_quoted_name($node_schema['name'], dbsteward::$quote_schema_names) . '.' . pgsql8_diff::get_quoted_name($node_table['name'], dbsteward::$quote_table_names);

    $sql = "CREATE TABLE " . $table_name . " (\n";

    foreach(dbx::get_table_columns($node_table) as $column) {
      $sql .= "\t"
        . pgsql8_column::get_full_definition(dbsteward::$new_database, $node_schema, $node_table, $column, false)
        . ",\n";
    }

    $sql = substr($sql, 0, strlen($sql) - 2);
    $sql .= "\n)";
    if (isset($node_table['inherits']) && strlen($node_table['inherits']) > 0) {
      $sql .= " INHERITS " . $node_table['inherits'];
    }
    $sql .= ";";

    // table comment
    if (isset($node_table['description']) && strlen($node_table['description']) > 0) {
      $sql .= "\nCOMMENT ON TABLE " . $table_name . " IS '" . pg_escape_string(dbsteward::string_cast($node_table['description'])) . "';\n";
    }

    foreach(dbx::get_table_columns($node_table) as $column) {
      if ( isset($column['statistics']) ) {
        $sql .= "\nALTER TABLE ONLY "
          . $table_name
          . " ALTER COLUMN " . pgsql8_diff::get_quoted_name($column['name'], dbsteward::$quote_column_names)
          . " SET STATISTICS " . $column['statistics'] . ";\n";
      }

      // column comments
      if ( isset($column['description']) && strlen($column['description']) > 0 ) {
        $sql .= "\nCOMMENT ON COLUMN " . $table_name . '.' . pgsql8_diff::get_quoted_name($column['name'], dbsteward::$quote_column_names)
          . " IS '" . pg_escape_string(dbsteward::string_cast($column['description'])) . "';\n";
      }
    }

    // table ownership
    if (isset($node_table['owner']) && strlen($node_table['owner']) > 0) {
      // see dtd owner attribute enum: ROLE_OWNER, ROLE_APPLICATION, ROLE_SLONY
      // map ROLE_ enums to database->role->owner etc
      $owner = xml_parser::role_enum(dbsteward::$new_database, $node_table['owner']);
      $sql .= "\nALTER TABLE " . $table_name . " OWNER TO " . $owner . ";\n";

      // set serial columns ownership based on table ownership
      foreach($node_table->column AS $column ) {
        if ( preg_match(pgsql8::PATTERN_TABLE_LINKED_TYPES, dbsteward::string_cast($column['type'])) > 0 ) {
          $sequence_name = pgsql8::identifier_name($node_schema['name'], $node_table['name'], $column['name'], '_seq');
          // we use alter table so we change the ownership of the sequence tracking counter, alter sequence can't do this
          $sql .= "\nALTER TABLE " . $node_schema['name'] . '.' . $sequence_name . " OWNER TO " . $owner . ";\n";
        }
      }
    }

    return $sql;
  }

  /**
   * Creates and returns SQL command for dropping the table.
   *
   * @return created SQL command
   */
  public function get_drop_sql($node_schema, $node_table) {
    if ( !is_object($node_schema) ) {
      var_dump($node_schema);
      throw new exception("node_schema is not an object");
    }
    if ( $node_schema->getName() != 'schema' ) {
      var_dump($node_schema);
      throw new exception("node_schema element type " . $node_schema->getName() . " != schema. check stack for offending caller");
    }
    if ( $node_table->getName() != 'table' ) {
      var_dump($node_schema);
      var_dump($node_table);
      throw new exception("node_table element type " . $node_table->getName() . " != table. check stack for offending caller");
    }
    return "DROP TABLE " . pgsql8_diff::get_quoted_name($node_schema['name'], dbsteward::$quote_schema_names) . '.' . pgsql8_diff::get_quoted_name($node_table['name'], dbsteward::$quote_table_names) . ";";
  }

  /**
   * create SQL To create the constraint passed in the $constraint array
   *
   * @return string
   */
  public function get_constraint_sql($constraint) {
    if ( !is_array($constraint) ) {
      throw new exception("constraint is not an array?");
    }
    if ( strlen($constraint['table_name']) == 0 ) {
      var_dump(array_keys($constraint));
      throw new exception("table_name is blank");
    }
    $sql = "ALTER TABLE "
      . pgsql8_diff::get_quoted_name($constraint['schema_name'], dbsteward::$quote_schema_names) . '.'
      . pgsql8_diff::get_quoted_name($constraint['table_name'], dbsteward::$quote_table_names) . "\n"
      . static::get_constraint_sql_change_statement($constraint);

    $sql .= ';';
    return $sql;
  }

  public static function get_constraint_sql_change_statement($constraint) {
    $sql = "\tADD CONSTRAINT "
      . pgsql8_diff::get_quoted_name($constraint['name'], dbsteward::$quote_object_names) . ' '
      . $constraint['type'] . ' '
      . $constraint['definition'] ;

   // FOREIGN KEY ON DELETE / ON UPDATE handling
    if ( isset($constraint['foreignOnDelete']) && strlen($constraint['foreignOnDelete']) ) {
      $sql .= " ON DELETE " . $constraint['foreignOnDelete'];
    }
    if ( isset($constraint['foreignOnUpdate']) && strlen($constraint['foreignOnUpdate']) ) {
      $sql .= " ON UPDATE " . $constraint['foreignOnUpdate'];
    }

    return $sql;
  }

  public static function get_constraint_drop_sql_change_statement($constraint) {
      return "\tDROP CONSTRAINT "
        . pgsql8_diff::get_quoted_name($constraint['name'], dbsteward::$quote_object_names);
  }

  public function get_constraint_drop_sql($constraint) {
    if ( !is_array($constraint) ) {
      throw new exception("constraint is not an array?");
    }
    if ( strlen($constraint['table_name']) == 0 ) {
      var_dump(array_keys($constraint));
      throw new exception("table_name is blank");
    }
    $sql = "ALTER TABLE "
      . pgsql8_diff::get_quoted_name($constraint['schema_name'], dbsteward::$quote_schema_names) . '.'
      . pgsql8_diff::get_quoted_name($constraint['table_name'], dbsteward::$quote_table_names) . "\n"
      . static::get_constraint_drop_sql_change_statement($constraint)
      . ';';
    return $sql;
  }

}

?>
