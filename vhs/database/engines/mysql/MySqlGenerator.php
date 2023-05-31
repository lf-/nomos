<?php
/**
 * Created by PhpStorm.
 * User: Thomas
 * Date: 12/12/2014
 * Time: 4:51 PM
 */

namespace vhs\database\engines\mysql;

// XXX: required due to use of free functions of MySqlSegment.php prior to
// using a MySqlSegment instance. MySqlSegment is not expected to be used
// without this file anyway.
require_once __DIR__ . '/MySqlSegment.php';

use mysqli;
use vhs\database\Column;
use vhs\database\exceptions\DatabaseException;
use vhs\database\IColumnGenerator;
use vhs\database\IOnGenerator;
use vhs\database\joins\IJoinGenerator;
use vhs\database\joins\Join;
use vhs\database\joins\JoinCross;
use vhs\database\joins\JoinInner;
use vhs\database\joins\JoinLeft;
use vhs\database\joins\JoinOuter;
use vhs\database\joins\JoinRight;
use vhs\database\On;
use vhs\database\orders\IOrderByGenerator;
use vhs\database\orders\OrderBy;
use vhs\database\orders\OrderByAscending;
use vhs\database\orders\OrderByDescending;
use vhs\database\queries\IQueryGenerator;
use vhs\database\queries\QueryDelete;
use vhs\database\queries\QueryInsert;
use vhs\database\queries\QuerySelect;
use vhs\database\queries\QueryUpdate;
use vhs\database\queries\QueryCount;
use vhs\database\types\ITypeGenerator;
use vhs\database\types\Type;
use vhs\database\types\TypeBool;
use vhs\database\types\TypeDate;
use vhs\database\types\TypeDateTime;
use vhs\database\types\TypeEnum;
use vhs\database\types\TypeFloat;
use vhs\database\types\TypeInt;
use vhs\database\types\TypeString;
use vhs\database\types\TypeText;
use vhs\database\wheres\Where;
use vhs\database\wheres\WhereAnd;
use vhs\database\wheres\WhereComparator;
use vhs\database\wheres\IWhereGenerator;
use vhs\database\limits\ILimitGenerator;
use vhs\database\limits\Limit;
use vhs\database\offsets\IOffsetGenerator;
use vhs\database\offsets\Offset;
use vhs\database\wheres\WhereOr;
use vhs\database\engines\mysql\MySqlSegment;
use function vhs\database\engines\mysql\segs;

class MySqlGenerator implements
    IWhereGenerator,
    IOrderByGenerator,
    ILimitGenerator,
    IOffsetGenerator,
    IQueryGenerator,
    ITypeGenerator,
    IJoinGenerator,
    IOnGenerator,
    IColumnGenerator
    {

    /**
     * @var \mysqli
     */
    private $conn = null;

    /**
     * Dammit we need this because of how real_escape_string works
     *  it requires a connection because it uses whatever the charset
     *  is on the db to figure escaping
     * @param \mysqli $conn
     */
    public function SetMySqli(\mysqli $conn) {
        $this->conn = $conn;
    }

    private function generateInterspersedWheres(Where $where, string $op): MySqlSegment {
        $wheres = array_map(
            function ($v) { return segs('(', $v->generate($this), ')'); },
            $where->wheres
        );

        return segs("(", MySqlSegment::interspersedWith(" $op ", ...$wheres), ")");
    }

    public function generateAnd(WhereAnd $where): MySqlSegment {
        return $this->generateInterspersedWheres($where, 'AND');
    }

    public function generateOr(WhereOr $where): MySqlSegment {
        return $this->generateInterspersedWheres($where, 'OR');
    }

    public function generateComparator(WhereComparator $where) {
        // FIXME(jade): should change to instanceof
        if ($where->isArray || (is_object($where->value) && get_class($where->value) == "vhs\\database\\queries\\QuerySelect")) {
            $sql = $where->column->generate($this);

            if($where->equal)
                $sql .= " IN (";
            else
                $sql .= " NOT IN (";

            if ($where->isArray && !(is_object($where->value) && get_class($where->value) == "vhs\\database\\queries\\QuerySelect")) {
                foreach ($where->value as $val)
                    $sql .= $where->column->type->generate($this, $val) . ", ";

                $sql = substr($sql, 0, -2);
            } else {
                $sql .= $where->value->generate($this);
            }

            $sql .= ")";

            return $sql;
        } else {
            $col = $where->column->generate($this);
            $val = $where->value;
            $value = null;

            if(is_object($val) && get_class($val) == "vhs\\database\\Column") {
                /** @var Column $val */
                $value = $val->generate($this);
            } else {
                $value = $where->column->type->generate($this, $where->value);
            }

            $sign = "";

            if($where->null_compare) {
                if ($where->equal) $sign = "IS NULL";
                else $sign = "IS NOT NULL";

                return "{$col} {$sign}";
            } else {
                if ($where->greater) $sign .= ">";
                if ($where->lesser) $sign .= "<";
                if ($where->equal) $sign .= "=";
                else if(!$where->greater && !$where->lesser && !$where->like) $sign = "<>";
                else if ($where->like) $sign .= "LIKE";

                return "{$col} {$sign} {$value}";
            }
        }
    }

    public function generateAscending(OrderByAscending $ascending) {
        return $this->gen($ascending, "ASC");
    }

    public function generateDescending(OrderByDescending $descending) {
        return $this->gen($descending, "DESC");
    }
    /**
     * @param mixed $type
     */
    private function gen(OrderBy $orderBy, $type) {
        $clause = segs($orderBy->column->generate($this), " {$type}, ");

        $pieces = MySqlSegment::interspersedWith(", ", ...array_map(function ($n) { return $n->generate($this); }, $orderBy->orderBy));

        return segs($clause, $pieces);
    }

    public function generateLimit(Limit $limit) {
        $clause = "";
        if(isset($limit->limit) && is_numeric($limit->limit)) {
            $clause = sprintf(" LIMIT %s ", intval($limit->limit));
        }
        return $clause;
    }

    public function generateOffset(Offset $offset) {
        $clause = "";
        if(isset($offset->offset) && is_numeric($offset->offset)) {
            $clause = sprintf(" OFFSET %s ", intval($offset->offset));
        }
        return $clause;
    }

    /**
     * @param QuerySelect|QueryCount $query
     */
    private function generateSelectWith($query, string $selector): MySqlSegment
    {
        $clause = (!is_null($query->where)) ? segs($query->where->generate($this)) : MySqlSegment::empty();
        $orderClause = (!is_null($query->orderBy)) ? segs($query->orderBy->generate($this)) : MySqlSegment::empty();
        $limit = (!is_null($query->limit)) ? segs($query->limit->generate($this)) : MySqlSegment::empty();
        $offset = (!is_null($query->offset)) ? segs($query->offset->generate($this)) : MySqlSegment::empty();

        $sql = seg("SELECT {$selector} FROM `{$query->table->name}` AS {$query->table->alias}");

        if(!is_null($query->joins)) {
            /** @var Join $join */
            foreach($query->joins as $join) {
                $sql = segs($sql, " ", $join->generate($this));
            }
        }

        if(!$clause->isEmpty())
            $sql = segs($sql, " WHERE ", $clause);

        if(!$orderClause->isEmpty())
            $sql = segs($sql, " ORDER BY ", $orderClause);

        if(!empty($limit))
            $sql = segs($sql, " ", $limit);

        if(!empty($offset))
            $sql = segs($sql, " ", $offset);

        return $sql;
    }

    public function generateSelect(QuerySelect $query): MySqlSegment
    {
        $selector = implode(", ", array_map(function(Column $column) { return $column->generate($this); }, $query->columns->all()));
        return $this->generateSelectWith($query, $selector);
    }

    public function generateSelectCount(QueryCount $query): MySqlSegment
    {
        return $this->generateSelectWith($query, 'COUNT(*)');
    }

    public function generateInsert(QueryInsert $query): MySqlSegment
    {
        $columns = array();
        $values = array();

        foreach($query->values as $columnName => $value) {
            $column = $query->columns->getByName($columnName);
            array_push($columns, "`{$column->name}`");
            array_push($values, $column->type->generate($this, $value));
        }

        $columns = implode(", ", $columns);
        $values = MySqlSegment::interspersedWith(", ", ...$values);

        $sql = segs("INSERT INTO `{$query->table->name}` ({$columns}) VALUES (", $values, ")");

        return $sql;
    }

    public function generateUpdate(QueryUpdate $query)
    {
        $clause = (!is_null($query->where)) ? $query->where->generate($this) : "";
        $setsql = implode(", ",
            array_map(
                function($columnName, $value) use($query)
                {
                    $column = $query->columns->getByName($columnName);
                    return $column->generate($this) . " = " . $column->type->generate($this, $value);
                },
                array_keys($query->values),
                array_values($query->values)
            )
        );

        $sql = "UPDATE `{$query->table->name}` AS {$query->table->alias} SET {$setsql}";

        if(!empty($clause))
            $sql .= " WHERE {$clause}";

        return $sql;
    }

    public function generateDelete(QueryDelete $query)
    {
        $clause = (!is_null($query->where)) ? $query->where->generate($this) : "";

        $sql = "DELETE {$query->table->alias} FROM `{$query->table->name}` AS {$query->table->alias}";

        if(!empty($clause))
            $sql .= " WHERE {$clause}";

        return $sql;
    }
    /**
     * @param callable(): mixed $gen
     * @param mixed $value
     */
    private function genVal(callable $gen, Type $type, $value = null) {
        if (is_null($value)) {
            if ($type->nullable) return "NULL";
            else return $type->generate($this, $type->default);
        }

        $val = $gen($value);

        if (!is_null($this->conn))
            $val = $this->conn->real_escape_string($val);

        return "'{$val}'";
    }

    public function generateBool(TypeBool $type, $value = null)
    {
        return $this->genVal(function($val) {
            if (boolval($val) === true)
                return "1";
            else
                return "0";
        }, $type, $value);
    }

    public function generateInt(TypeInt $type, $value = null)
    {
        return $this->genVal(function($val) {
            return intval($val);
        }, $type, $value);
    }

    public function generateFloat(TypeFloat $type, $value = null)
    {
        return $this->genVal(function($val) {
            return floatval($val);
        }, $type, $value);
    }

    public function generateString(TypeString $type, $value = null)
    {
        return $this->genVal(function($val) use ($type) {
            $v = (string) $val;
            if (strlen($v) > $type->length)
                throw new DatabaseException("Value of Type::String exceeds defined length of {$type->length}");

            return $v;
        }, $type, $value);
    }

    public function generateText(TypeText $type, $value = null)
    {
        return $this->genVal(function($val) use ($type) {
            return (string) $val;
        }, $type, $value);
    }

    public function generateDate(TypeDate $type, $value = null)
    {
        return $this->genVal(function($val) {
            return (string) date('Y-m-d', strtotime(str_replace('-', '/', $val)));
        }, $type, $value);
    }

    public function generateDateTime(TypeDateTime $type, $value = null)
    {
        return $this->genVal(function($val) {
            return (string) date('Y-m-d H:i:s', strtotime(str_replace('-', '/', $val)));
        }, $type, $value);
    }

    public function generateEnum(TypeEnum $type, $value = null)
    {
        return $this->genVal(function($val) use ($type) {
            $v = (string) $val;

            if(!in_array($v, $type->values))
                throw new DatabaseException("Enum {$v} does not exist in Type::Enum");

            return $v;
        }, $type, $value);
    }

    public function generateLeft(JoinLeft $join)
    {
        return "LEFT JOIN {$join->table->name} {$join->table->alias} ON " . $join->on->generate($this);
    }

    public function generateRight(JoinRight $join)
    {
        return "RIGHT JOIN {$join->table->name} {$join->table->alias} ON " . $join->on->generate($this);
    }

    public function generateOuter(JoinOuter $join)
    {
        return "OUTER JOIN {$join->table->name} {$join->table->alias} ON " . $join->on->generate($this);
    }

    public function generateInner(JoinInner $join)
    {
        return "INNER JOIN {$join->table->name} {$join->table->alias} ON " . $join->on->generate($this);
    }

    public function generateCross(JoinCross $join)
    {
        return "CROSS JOIN {$join->table->name} {$join->table->alias} ON " . $join->on->generate($this);
    }

    public function generateOn(On $on)
    {
        return "(".$on->where->generate($this).")";
    }

    public function generateColumn(Column $column)
    {
        return "{$column->table->alias}.`{$column->name}`";
    }
}
