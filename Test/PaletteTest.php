<?php
require_once 'tests/units/Base.php';

use KanboardTests\units\Base;
use Kanboard\Plugin\Pulse\Model\Palette;

class PaletteTest extends Base
{
    public function testAssignPrefersIdSlot()
    {
        $this->assertSame(
            [3 => Palette::SERIES[3], 5 => Palette::SERIES[5]],
            Palette::assign([3 => 10, 5 => 4])
        );
    }

    public function testAssignProbesOnCollision()
    {
        // 1 is heavier, so it keeps slot 1; 9 also prefers slot 1 and probes to slot 2.
        $this->assertSame(
            [1 => Palette::SERIES[1], 9 => Palette::SERIES[2]],
            Palette::assign([1 => 20, 9 => 5])
        );
        // Slot 7 taken: probing wraps to slot 0.
        $this->assertSame(
            [7 => Palette::SERIES[7], 15 => Palette::SERIES[0]],
            Palette::assign([7 => 2, 15 => 1])
        );
    }

    public function testAssignFoldsBeyondEight()
    {
        $weights = [];
        for ($id = 1; $id <= 10; $id++) {
            $weights[$id] = $id; // 10 is heaviest, 1 and 2 are the lightest
        }

        $colours = Palette::assign($weights);

        $this->assertCount(10, $colours);
        $this->assertSame(Palette::OTHER, $colours[1]);
        $this->assertSame(Palette::OTHER, $colours[2]);
        $assigned = array_values(array_filter($colours, fn ($c) => $c !== Palette::OTHER));
        $this->assertCount(8, $assigned);
        $this->assertSame(Palette::SERIES, array_values(array_intersect(Palette::SERIES, $assigned)));
        $this->assertCount(8, array_unique($assigned));
    }

    public function testAssignIgnoresInputOrder()
    {
        $a = [1 => 5, 9 => 5, 17 => 5, 4 => 1];
        $b = [4 => 1, 17 => 5, 9 => 5, 1 => 5];

        $colours = Palette::assign($a);
        ksort($colours);
        $reordered = Palette::assign($b);
        ksort($reordered);

        $this->assertSame($colours, $reordered);
        // Ties go to the lower id: 1 gets slot 1, 9 probes to 2, 17 to 3.
        $this->assertSame(Palette::SERIES[1], $colours[1]);
        $this->assertSame(Palette::SERIES[2], $colours[9]);
        $this->assertSame(Palette::SERIES[3], $colours[17]);
        $this->assertSame(Palette::SERIES[4], $colours[4]);
    }

    public function testTypeColours()
    {
        $this->assertSame('#2a78d6', Palette::typeColour('move'));
        $this->assertSame('#e87ba4', Palette::typeColour('commit'));
    }
}
