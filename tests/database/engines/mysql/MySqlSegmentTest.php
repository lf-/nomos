<?php

use PHPUnit\Framework\TestCase;
use vhs\database\engines\mysql\MySqlSegment;
use function vhs\database\engines\mysql\seg;
use function vhs\database\engines\mysql\segs;

class MySqlSegmentTest extends TestCase {
    function test_Equals(): void {
        $this->assertEquals(MySqlSegment::empty(), MySqlSegment::empty());
        $this->assertEquals(seg('select * from nyas'), seg('select * from nyas'));
        $this->assertNotEquals(seg('select * from nyas'), seg('select * from meows'));
        $sql = 'select * from nyas where cat = ? and recipient = ?';
        $this->assertNotEquals(seg($sql, 1, 2), seg($sql, 2, 3));
        $this->assertEquals(seg($sql, 1, 2), seg($sql, 1, 2));
    }

    function prop_associative(MySqlSegment $a, MySqlSegment $b): void {
        $this->assertEquals(($a->plus($b))->plus($a), $a->plus($b->plus($a)));
    }

    function test_Combine(): void {
        $this->prop_associative(seg('and a = ?', 1), seg('and b = ?', 2));
        $this->prop_associative(seg(''), seg(''));

        $this->assertEquals(segs('a', ' b'), seg('a b'));
        $this->assertEquals(segs(seg('a = ?', 1), ' b'), seg('a = ? b', 1));
        $this->assertEquals(segs(seg('a'), seg(' b = ?', 1)), seg('a b = ?', 1));
    }
}
