<?php

namespace vhs\database\engines\mysql;

/**
 * This is a piece of MySql with associated parameters. This is an immutable
 * by-value object.
 *
 * This object implements a Semigroup:
 * * The empty element is empty()
 * * Combining two MySqlSegments is an associative binary operation:
 *   a + (b + c) == (a + b) + c
 *   or, more clumsily in a syntax we can write:
 *   a->plus(b->plus(c)) == (a->plus(b))->plus(c)
 */
final class MySqlSegment
{
    public string $sql;
    public array $params;

    /**
     * @param int|string[] $params
     */
    public function __construct(string $sql, ...$params)
    {
        $this->sql = $sql;
        $this->params = $params;
        foreach ($params as $p) {
            if (!is_int($p) && !is_string($p)) {
                throw new \TypeError("Parameter {print_r($p, true)} is not int or string");
            }
        }
    }

    /**
     * Adds this to another MySqlSegment to create a new one.
     *
     * @param string|MySqlSegment $other
     */
    public function plus($other): MySqlSegment
    {
        if ($other instanceof MySqlSegment) {
            return new self($this->sql . $other->sql, ...array_merge($this->params, $other->params));
        } elseif (is_string($other)) {
            return new self($this->sql . $other, ...$this->params);
        } else {
            $otherType = gettype($other);
            throw new \TypeError("Wrong type $otherType passed to MySqlSegment::plus, expected string or MySqlSegment");
        }
    }

    public function __toString(): string
    {
        $asString = var_export($this->sql, true);
        $paramsList = implode(', ', array_map(function ($v) { var_export($v, true); }, $this->params));
        return "$asString [$paramsList]";
    }

    public function isEmpty(): bool {
        return $this == self::empty();
    }

    /**
     * Intersperses a list of MySqlSegments with a separator. Useful for
     * generating all manner of things.
     *
     * @param string|MySqlSegment $separator
     * @param string|MySqlSegment[] $list
     */
    public static function interspersedWith($separator, ...$list): MySqlSegment {
        return array_reduce($list, function ($acc, $val) use($separator) {
            if (is_null($acc)) {
                $acc = MySqlSegment::empty();
            } else {
                $acc = $acc->plus($separator);
            }
            return $acc->plus($val);
        }, null);
    }

    public static function empty(): MySqlSegment
    {
        return new self('');
    }
}

/**
 * Helper to construct a {@link MySqlSegment}.
 *
 * @param int|string[] $params
 */
function seg(string $sql, ...$params)
{
    return new MySqlSegment($sql, ...$params);
}

/**
 * Combines multiple {@link MySqlSegment} or literal strings
 * together.
 */
function segs(...$values)
{
    return array_reduce($values, function ($a, $b) {
        return ($a instanceof MySqlSegment ? $a : new MySqlSegment($a))->plus($b);
    }, MySqlSegment::empty());
}
