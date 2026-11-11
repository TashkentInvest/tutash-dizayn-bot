<?php

namespace Tests\Unit;

use App\Support\Latin;
use PHPUnit\Framework\TestCase;

/** Street names from the official list against the names the map gives the same road (checked on the real list). */
class LatinTest extends TestCase
{
    private function match(string $street, string $road): bool
    {
        $a = Latin::words($street);
        $b = Latin::words($road);
        if (Latin::sameJoined($a, $b)) {
            return true;
        }
        foreach ($a as $w) {
            foreach ($b as $x) {
                if (Latin::same($w, $x)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function test_the_same_road_under_its_map_names(): void
    {
        foreach ([
            ['КХАЙ кўчаси', 'Малая кольцевая дорога'], ['ТХАЙ кўчаси', 'Ташкентская Кольцевая Автомобильная Дорога'],
            ['ТХАЙ кўчаси', 'Ташкентская городская объездная дорога'], ['Охангарон шоҳ кўчаси', 'Ахангаранское шоссе'],
            ['Чўпонота кўчаси', 'Чапаната улица'], ['Қатортол кўчаси', 'Катартал улица'], ['Янги Шаҳар кўчаси', 'Янгишахар улица'],
            ['Журжоний кўчаси', 'Джурджани улица'], ['Хусайн Байқаро кўчаси', "Husan Boyqaro ko'chasi"], ['Муқумий кўчаси', "Muqimiy ko'chasi"],
        ] as [$street, $road]) {
            $this->assertTrue($this->match($street, $road), "$street = $road");
        }
    }

    public function test_different_roads_stay_different(): void
    {
        foreach ([['КХАЙ кўчаси', 'Ташкентская Кольцевая Автомобильная Дорога'], ['Фурқат кўчаси', "Seul ko'chasi"],
            ['Миробод кўчаси', 'Сарабустан улица'], ['Осиё кўчаси', 'Бадамзар Йули 1-й проезд']] as [$street, $road]) {
            $this->assertFalse($this->match($street, $road), "$street != $road");
        }
    }
}
