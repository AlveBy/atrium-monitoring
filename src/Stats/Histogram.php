<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Stats;

/**
 * A log-scale histogram that percentiles are read from.
 *
 * Percentiles of days cannot be averaged into the percentile of a month;
 * histograms can be added. Each bucket covers (γ^(i-1), γ^i] with γ = 1.05,
 * and a value is read back as the bucket's middle 2γ^i / (γ + 1), so any
 * percentile is within (γ - 1) / (γ + 1) ≈ 2.44 % of a real measurement of
 * that rank (the DDSketch idea). Values below MIN — a CLS of zero, a page
 * without queries — share a bucket of their own read back as 0.
 *
 * 5 ms to 60 s fits in about 190 buckets; a busy day of one measure takes a
 * few kilobytes of JSON.
 */
final class Histogram
{
    public const GAMMA = 1.05;

    /** Smaller values count as zero. */
    public const MIN = 0.001;

    /** The bucket of zeros. Real buckets start far above it (log(0.001) / log(1.05) ≈ -141). */
    private const ZERO = -1000;

    /** @var array<int,int> bucket index => count */
    private array $buckets = [];

    private int $count = 0;

    private float $sum = 0.0;

    private float $min = INF;

    private float $max = -INF;

    /**
     * @param  iterable<float|int>  $values
     */
    public static function of(iterable $values): self
    {
        $histogram = new self;

        foreach ($values as $value) {
            $histogram->add((float) $value);
        }

        return $histogram;
    }

    /**
     * @param  array{n?:int|string|null,s?:float|int|string,lo?:float|int|string,hi?:float|int|string,b?:array<int|string,float|int|string>}  $data
     */
    public static function fromArray(array $data): self
    {
        $histogram = new self;

        if (! isset($data['n']) || (int) $data['n'] < 1) {
            return $histogram;
        }

        $histogram->count = (int) $data['n'];
        $histogram->sum = (float) ($data['s'] ?? 0);
        $histogram->min = (float) ($data['lo'] ?? 0);
        $histogram->max = (float) ($data['hi'] ?? 0);

        foreach ($data['b'] ?? [] as $index => $stored) {
            $count = (int) $stored;

            if ($count > 0) {
                $histogram->buckets[(int) $index] = $count;
            }
        }

        return $histogram;
    }

    /**
     * A row of monitoring_daily: count, sum, min, max and the buckets as JSON.
     */
    public static function fromRow(object $row): self
    {
        $buckets = json_decode((string) ($row->buckets ?? '{}'), true);

        return self::fromArray([
            'n' => $row->count ?? null,
            's' => $row->sum ?? 0,
            'lo' => $row->min ?? 0,
            'hi' => $row->max ?? 0,
            'b' => is_array($buckets) ? $buckets : [],
        ]);
    }

    public function add(float $value, int $times = 1): void
    {
        if ($times < 1 || is_nan($value) || is_infinite($value)) {
            return;
        }

        $value = max(0.0, $value);
        $index = self::index($value);
        $this->buckets[$index] = ($this->buckets[$index] ?? 0) + $times;
        $this->count += $times;
        $this->sum += $value * $times;
        $this->min = min($this->min, $value);
        $this->max = max($this->max, $value);
    }

    public function merge(self $other): void
    {
        foreach ($other->buckets as $index => $count) {
            $this->buckets[$index] = ($this->buckets[$index] ?? 0) + $count;
        }

        $this->count += $other->count;
        $this->sum += $other->sum;
        $this->min = min($this->min, $other->min);
        $this->max = max($this->max, $other->max);
    }

    /**
     * The value at rank q·(n−1) in the sorted measurements, q from 0 to 1
     * (0.5 is the median, 0.95 the 95th percentile); null when empty.
     */
    public function quantile(float $q): ?float
    {
        if ($this->count === 0) {
            return null;
        }

        $q = min(1.0, max(0.0, $q));
        $rank = $q * ($this->count - 1);
        $seen = 0;
        $buckets = $this->buckets;
        ksort($buckets);

        foreach ($buckets as $index => $count) {
            $seen += $count;

            if ($seen > $rank) {
                return min($this->max, max($this->min, self::value($index)));
            }
        }

        return $this->max;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function sum(): float
    {
        return $this->sum;
    }

    public function mean(): ?float
    {
        return $this->count === 0 ? null : $this->sum / $this->count;
    }

    public function min(): ?float
    {
        return $this->count === 0 ? null : $this->min;
    }

    public function max(): ?float
    {
        return $this->count === 0 ? null : $this->max;
    }

    /**
     * @return array{n:int,s:float,lo:float,hi:float,b:array<int,int>}
     */
    public function toArray(): array
    {
        $buckets = $this->buckets;
        ksort($buckets);

        return [
            'n' => $this->count,
            's' => round($this->sum, 6),
            'lo' => $this->count === 0 ? 0.0 : $this->min,
            'hi' => $this->count === 0 ? 0.0 : $this->max,
            'b' => $buckets,
        ];
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray()['b'], JSON_FORCE_OBJECT);
    }

    /** The bucket a value falls in. */
    public static function index(float $value): int
    {
        if ($value < self::MIN) {
            return self::ZERO;
        }

        return (int) ceil(log($value) / log(self::GAMMA) - 1e-9);
    }

    /** The value a bucket is read back as: the middle of its range. */
    public static function value(int $index): float
    {
        if ($index === self::ZERO) {
            return 0.0;
        }

        return 2 * self::GAMMA ** $index / (self::GAMMA + 1);
    }
}
