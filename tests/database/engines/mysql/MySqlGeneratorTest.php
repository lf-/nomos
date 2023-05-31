<?php

namespace tests\vhs\database\engines\mysql;

use PHPUnit\Framework\TestCase;
use vhs\database\Columns;
use vhs\database\Table;
use vhs\database\engines\mysql\MySqlGenerator;
use vhs\database\limits\Limit;
use vhs\database\offsets\Offset;
use vhs\database\orders\OrderBy;
use vhs\database\queries\Query;
use vhs\database\types\Type;
use vhs\database\wheres\Where;
use vhs\domain\Schema;
use vhs\loggers\ConsoleLogger;

use function vhs\database\engines\mysql\segs;
use function vhs\database\engines\mysql\seg;

class TestSchema extends Schema
{
    /**
     * @return Table
     */
    public static function init(): Table
    {
        $table = new Table("test");
        $table->addColumn("test1", Type::String());
        $table->addColumn("test3", Type::String());
        $table->addColumn("test5", Type::String());

        return $table;
    }
}

class MySqlGeneratorTest extends TestCase
{
    /** @var Logger */
    private static $logger;

    public static function setUpBeforeClass(): void
    {
        self::$logger = new ConsoleLogger();
    }

    private MySqlGenerator $mySqlGenerator;

    public function setUp(): void
    {
        $this->mySqlGenerator = new MySqlGenerator();
    }

    public function test_Select(): void
    {
        $where = Where::Equal(TestSchema::Column("test1"), "nya");
        $limit = Limit::Limit(10);
        $offset = Offset::Offset(5);
        $orderBy = OrderBy::Ascending(TestSchema::Column('test1'));
        $q = Query::Select(TestSchema::Table(), new Columns(TestSchema::Columns()->test1), $where, $orderBy, $limit, $offset);

        $clause = $q->generate($this->mySqlGenerator);
        self::$logger->log($clause);
        $this->assertEquals(seg("SELECT tst0.`test1` FROM `test` AS tst0 WHERE tst0.`test1` = 'nya' ORDER BY tst0.`test1` ASC  LIMIT 10   OFFSET 5 "), $clause);
    }

    public function test_SelectCount(): void
    {
        $where = Where::_Or(
            Where::Equal(TestSchema::Columns()->test1, "nya"),
            Where::Equal(TestSchema::Columns()->test1, 'nyanya')
        );
        $limit = Limit::Limit(10);
        $offset = Offset::Offset(5);
        $orderBy = OrderBy::Ascending(TestSchema::Column('test1'));
        $q = Query::Count(TestSchema::Table(), $where, $orderBy, $limit, $offset);

        $clause = $q->generate($this->mySqlGenerator);
        self::$logger->log($clause);
        $this->assertEquals(seg("SELECT COUNT(*) FROM `test` AS tst0 WHERE ((tst0.`test1` = 'nya') OR (tst0.`test1` = 'nyanya')) ORDER BY tst0.`test1` ASC  LIMIT 10   OFFSET 5 "), $clause);
    }

    public function test_Insert(): void
    {
        $q = Query::Insert(TestSchema::Table(), TestSchema::Columns(), [
            'test1' => 'nya',
            'test3' => 'meow',
            'test5' => 'kitties',
        ]);

        $clause = $q->generate($this->mySqlGenerator);
        self::$logger->log($clause);
        $this->assertEquals(seg("INSERT INTO `test` (`test1`, `test3`, `test5`) VALUES ('nya', 'meow', 'kitties')"), $clause);
    }
}
