<?php
/**
 * Random: determinism, serialization, ranges, weighted picks.
 *
 * @package MrPresident\Tests
 */

declare(strict_types=1);

use MrPresident\Engine\Random;
use MrPresident\Tests\Assert;

return [
    'same seed gives the same stream' => static function (Assert $t): void {
        $a = new Random(12345);
        $b = new Random(12345);
        for ($i = 0; $i < 50; $i++) {
            $t->same($a->nextInt(), $b->nextInt(), "draw {$i}");
        }
    },
    'different seeds diverge' => static function (Assert $t): void {
        $a = new Random(1);
        $b = new Random(2);
        $t->notSame($a->nextInt(), $b->nextInt());
    },
    'seed zero is remapped, not stuck' => static function (Assert $t): void {
        $r = new Random(0);
        $t->notSame(0, $r->nextInt());
        $t->notSame($r->nextInt(), $r->nextInt());
    },
    'toArray/fromArray resumes mid-stream' => static function (Assert $t): void {
        $a = new Random(777);
        $a->nextInt();
        $a->nextInt();
        $b = Random::fromArray($a->toArray());
        for ($i = 0; $i < 20; $i++) {
            $t->same($a->nextInt(), $b->nextInt(), "resumed draw {$i}");
        }
    },
    'values stay inside 32 bits and floats inside [0,1)' => static function (Assert $t): void {
        $r = new Random(99);
        for ($i = 0; $i < 1000; $i++) {
            $n = $r->nextInt();
            $t->true($n >= 1 && $n <= 0xFFFFFFFF, 'nextInt out of range');
            $f = $r->nextFloat();
            $t->true($f >= 0.0 && $f < 1.0, 'nextFloat out of range');
        }
    },
    'range is inclusive on both ends' => static function (Assert $t): void {
        $r    = new Random(5);
        $seen = [];
        for ($i = 0; $i < 500; $i++) {
            $v = $r->range(3, 6);
            $t->true($v >= 3 && $v <= 6, 'range out of bounds');
            $seen[$v] = true;
        }
        $t->count(4, $seen, 'every value in the range should appear');
    },
    'weightedPick respects weights and ignores zero' => static function (Assert $t): void {
        $r      = new Random(2024);
        $counts = ['a' => 0, 'b' => 0];
        for ($i = 0; $i < 2000; $i++) {
            $pick = $r->weightedPick(['a' => 9, 'b' => 1, 'c' => 0]);
            $t->true(isset($counts[$pick]), 'zero-weight id must never be picked');
            $counts[$pick]++;
        }
        $t->true($counts['a'] > $counts['b'] * 4, 'a should dominate b by roughly 9:1');
        $t->same(null, $r->weightedPick([]));
        $t->same(null, $r->weightedPick(['x' => 0]));
    },
];
