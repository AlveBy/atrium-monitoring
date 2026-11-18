<?php

/*
 * This file is part of Atrium (https://github.com/AlveBy/atrium).
 * Copyright (c) 2026 AlveBy. Licensed under the MIT License, see LICENSE.
 */

declare(strict_types=1);

namespace Atrium\Monitoring\Tests;

use Atrium\Monitoring\Models\Incident;
use Atrium\Monitoring\Models\UptimeCheck;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

final class ModelsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function check(array $attributes = []): UptimeCheck
    {
        $check = new UptimeCheck;
        $check->forceFill($attributes + ['site_id' => null, 'name' => 'Demo shop', 'url' => 'https://shop.example.test/'])->save();

        return $check;
    }

    /** @return array<string,array{0:string,1:string,2:string}> started, until, in whole minutes */
    public static function lengths(): array
    {
        return [
            'nothing' => ['2026-11-16 10:00:00', '2026-11-16 10:00:00', '0'],
            'under a minute' => ['2026-11-16 10:00:00', '2026-11-16 10:00:59', '0'],
            'a minute' => ['2026-11-16 10:00:00', '2026-11-16 10:01:00', '1'],
            'a minute and a half' => ['2026-11-16 10:00:00', '2026-11-16 10:01:30', '1'],
            'two minutes less a second' => ['2026-11-16 10:00:00', '2026-11-16 10:01:59', '1'],
            'a day' => ['2026-11-16 10:00:00', '2026-11-17 10:00:00', '1440'],
            'the end before the start counts the same' => ['2026-11-16 10:05:00', '2026-11-16 10:00:00', '5'],
        ];
    }

    #[DataProvider('lengths')]
    public function test_an_incident_lasts_in_whole_minutes(string $started, string $until, string $minutes): void
    {
        $incident = new Incident(['started_at' => $started]);

        $this->assertSame((int) $minutes, $incident->minutes(Carbon::parse($until)));
    }

    public function test_a_closed_incident_stops_at_its_resolution(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $incident = new Incident(['started_at' => '2026-11-16 10:00:00', 'resolved_at' => '2026-11-16 10:07:30']);

        $this->assertSame(7, $incident->minutes());
        $this->assertSame(7, $incident->minutes(Carbon::parse('2026-11-16 11:00:00')), 'a later "now" changes nothing');
    }

    public function test_an_open_incident_runs_up_to_the_given_moment_or_to_now(): void
    {
        Carbon::setTestNow('2026-11-16 12:00:00');
        $incident = new Incident(['started_at' => '2026-11-16 10:00:00']);

        $this->assertSame(120, $incident->minutes());
        $this->assertSame(30, $incident->minutes(Carbon::parse('2026-11-16 10:30:00')), 'the moment given, not now');
    }

    public function test_the_duration_is_worded_in_minutes(): void
    {
        $incident = new Incident(['started_at' => '2026-11-16 10:00:00', 'resolved_at' => '2026-11-16 10:00:20']);
        $this->assertSame('0 minutes', $incident->getDurationAttribute());

        $incident->resolved_at = Carbon::parse('2026-11-16 10:01:00');
        $this->assertSame('1 minute', $incident->getDurationAttribute());
        $this->assertSame('1 minute', $incident->duration);

        $incident->resolved_at = Carbon::parse('2026-11-16 10:12:00');
        $this->assertSame('12 minutes', $incident->getDurationAttribute());
    }

    public function test_an_incident_names_its_check_or_nothing(): void
    {
        $check = $this->check(['name' => 'Price list']);
        $incident = Incident::query()->create(['check_id' => $check->id, 'started_at' => '2026-11-16 10:00:00', 'failures' => 3]);

        $this->assertSame('Price list', $incident->getCheckNameAttribute());
        $this->assertSame('Price list', $incident->check_name);
        $this->assertTrue($incident->check->is($check));

        $this->assertSame('', (new Incident(['started_at' => '2026-11-16 10:00:00']))->getCheckNameAttribute());
    }

    public function test_incident_values_come_back_typed(): void
    {
        $incident = new Incident(['check_id' => '5', 'failures' => '3', 'started_at' => '2026-11-16 10:00:00', 'resolved_at' => '2026-11-16 10:05:00']);

        $this->assertSame(5, $incident->check_id);
        $this->assertSame(3, $incident->failures);
        $this->assertInstanceOf(Carbon::class, $incident->started_at);
        $this->assertInstanceOf(Carbon::class, $incident->resolved_at);
        $this->assertNull((new Incident)->resolved_at);
    }

    public function test_incidents_are_read_only_and_listed_newest_first(): void
    {
        $this->assertFalse(Incident::isCreatableInAdmin());
        $this->assertFalse(Incident::isEditableInAdmin());
        // Public as the panel's controller calls them from outside; a protected one would still answer through the model's __callStatic.
        $this->assertTrue((new ReflectionMethod(Incident::class, 'isCreatableInAdmin'))->isPublic());
        $this->assertTrue((new ReflectionMethod(Incident::class, 'isEditableInAdmin'))->isPublic());
        $this->assertSame('Incidents', Incident::getAdminLabel());
        $this->assertSame('monitoring_incidents', Incident::getAdminRoutePrefix());
        $this->assertSame('started_at', Incident::getDefaultSortColumn());
        $this->assertSame('desc', Incident::getDefaultSortDirection());
        $this->assertSame([], Incident::getAdministrationFields());

        $columns = Incident::getAdministrationListColumns();
        $this->assertSame(['id', 'check.name', 'started_at', 'resolved_at', 'duration', 'last_error'], array_map(fn ($column) => $column->getName(), $columns));
        $this->assertSame([true, false, true, true, false, false], array_map(fn ($column) => $column->toArray()['sortable'], $columns));
        $this->assertSame(['ID', 'Check', 'Started', 'Resolved', 'Duration', 'Last error'], array_map(fn ($column) => $column->toArray()['label'], $columns));
    }

    public function test_an_address_starts_unknown_active_and_not_a_front_page(): void
    {
        $check = $this->check()->fresh();

        $this->assertSame(UptimeCheck::UNKNOWN, $check->status);
        $this->assertSame(0, $check->failures);
        $this->assertSame(true, $check->active);
        $this->assertSame(false, $check->is_home);
        $this->assertNull($check->last_checked_at);
    }

    public function test_address_values_come_back_typed(): void
    {
        $check = new UptimeCheck;
        $check->forceFill(['is_home' => 1, 'active' => 0, 'failures' => '2', 'last_status_code' => '503', 'last_response_ms' => '12.5', 'last_checked_at' => '2026-11-16 10:00:00']);

        $this->assertSame(true, $check->is_home);
        $this->assertSame(false, $check->active);
        $this->assertSame(2, $check->failures);
        $this->assertSame(503, $check->last_status_code);
        $this->assertSame(12.5, $check->last_response_ms);
        $this->assertInstanceOf(Carbon::class, $check->last_checked_at);
    }

    public function test_the_status_is_worded(): void
    {
        $check = new UptimeCheck;

        foreach ([UptimeCheck::UNKNOWN => 'Not checked yet', UptimeCheck::UP => 'Up', UptimeCheck::FAILING => 'Failing', UptimeCheck::DOWN => 'Down'] as $status => $label) {
            $check->status = $status;

            $this->assertSame($label, $check->getStatusLabelAttribute());
            $this->assertSame($label, $check->status_label);
        }
    }

    public function test_an_address_finds_its_open_incident(): void
    {
        $check = $this->check();
        $other = $this->check(['name' => 'Other']);
        $this->assertNull($check->openIncident());

        Incident::query()->create(['check_id' => $check->id, 'started_at' => '2026-11-10 10:00:00', 'resolved_at' => '2026-11-10 11:00:00', 'failures' => 3]);
        Incident::query()->create(['check_id' => $other->id, 'started_at' => '2026-11-15 10:00:00', 'failures' => 3]);
        $this->assertNull($check->openIncident(), 'closed ones and other addresses do not count');

        Incident::query()->create(['check_id' => $check->id, 'started_at' => '2026-11-12 10:00:00', 'failures' => 3]);
        $newer = Incident::query()->create(['check_id' => $check->id, 'started_at' => '2026-11-14 10:00:00', 'failures' => 3]);

        $this->assertTrue($check->openIncident()->is($newer));
        $this->assertSame(3, $check->incidents()->count());
        $this->assertSame(1, $other->incidents()->count());
    }

    public function test_only_a_front_page_cannot_be_deleted(): void
    {
        $this->assertFalse($this->check(['is_home' => true])->canDelete());
        $this->assertTrue($this->check(['is_home' => false])->canDelete());
    }

    public function test_the_checks_are_listed_oldest_first_and_edited_with_four_fields(): void
    {
        $this->assertSame('Uptime checks', UptimeCheck::getAdminLabel());
        $this->assertSame('uptime_checks', UptimeCheck::getAdminRoutePrefix());
        $this->assertSame('id', UptimeCheck::getDefaultSortColumn());
        $this->assertSame('asc', UptimeCheck::getDefaultSortDirection());

        $fields = UptimeCheck::getAdministrationFields();
        $this->assertSame(['active', 'name', 'url', 'status_label', 'last_error'], array_map(fn ($field) => $field->getName(), $fields));
        $this->assertTrue($fields[0]->getDefault(), 'a new address is checked at once');
        $this->assertNull($fields[1]->getDefault());

        $columns = UptimeCheck::getAdministrationListColumns();
        $this->assertSame(['id', 'name', 'url', 'status_label', 'last_response_ms', 'last_checked_at', 'active'], array_map(fn ($column) => $column->getName(), $columns));
        $this->assertSame([true, true, false, false, false, true, false], array_map(fn ($column) => $column->toArray()['sortable'], $columns));
        $this->assertSame([false, true, true, false, false, false, false], array_map(fn ($column) => $column->toArray()['searchable'], $columns));
    }
}
