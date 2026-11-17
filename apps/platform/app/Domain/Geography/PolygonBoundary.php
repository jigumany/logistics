<?php

namespace App\Domain\Geography;

use Illuminate\Validation\ValidationException;

final class PolygonBoundary
{
    public function validate(mixed $value): array
    {
        if (! is_array($value) || ($value['type'] ?? null) !== 'Polygon' || ! isset($value['coordinates']) || ! is_array($value['coordinates']) || count($value['coordinates']) !== 1) {
            $this->fail('Supply a GeoJSON Polygon with one exterior ring.');
        }
        $ring = $value['coordinates'][0];
        if (! is_array($ring) || count($ring) < 4 || count($ring) > 501 || $ring[0] != $ring[count($ring) - 1]) {
            $this->fail('Close the polygon ring and use between 3 and 500 vertices.');
        }
        foreach ($ring as $point) {
            if (! is_array($point) || count($point) !== 2 || ! is_numeric($point[0]) || ! is_numeric($point[1]) || ! is_finite((float) $point[0]) || ! is_finite((float) $point[1]) || abs((float) $point[0]) > 180 || abs((float) $point[1]) > 90) {
                $this->fail('Coordinates must be valid longitude/latitude pairs.');
            }
        }
        $area = 0.;
        $n = count($ring) - 1;
        for ($i = 0; $i < $n; $i++) {
            $a = $ring[$i];
            $b = $ring[$i + 1];
            if ($a == $b) {
                $this->fail('Consecutive vertices must differ.');
            }$area += $a[0] * $b[1] - $b[0] * $a[1];
            for ($j = $i + 2; $j < $n; $j++) {
                if ($i === 0 && $j === $n - 1) {
                    continue;
                }$c = $ring[$j];
                $d = $ring[$j + 1];
                if ($this->cross($a, $b, $c) * $this->cross($a, $b, $d) <= 0 && $this->cross($c, $d, $a) * $this->cross($c, $d, $b) <= 0 && max(min($a[0], $b[0]), min($c[0], $d[0])) <= min(max($a[0], $b[0]), max($c[0], $d[0])) && max(min($a[1], $b[1]), min($c[1], $d[1])) <= min(max($a[1], $b[1]), max($c[1], $d[1]))) {
                    $this->fail('Polygon edges must not intersect.');
                }
            }
        }
        if (abs($area) < 1e-12) {
            $this->fail('Polygon must enclose a non-zero area.');
        }

        return ['type' => 'Polygon', 'coordinates' => [array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $ring)]];
    }

    private function cross(array $a, array $b, array $c): float
    {
        return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['boundary' => $message]);
    }
}
