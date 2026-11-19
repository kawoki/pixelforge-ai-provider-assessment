<?php

declare(strict_types=1);

use App\Data\ProviderUsageData;
use App\Data\ProviderUsageDayData;
use App\Exceptions\Pixelforge\ProviderUnavailableException;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money\Usd;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'UTC'));
    $this->fake = fakePixelforge();
    $this->admin = User::factory()->admin()->create();
});

/**
 * A succeeded generation Pixelforge completed (and billed) at $completedAt (UTC).
 */
function billed(User $user, string $completedAt, string $cost, string $charged): Generation
{
    return Generation::factory()->for($user)->succeeded()->create([
        'completed_at' => CarbonImmutable::parse($completedAt, 'UTC'),
        'actual_provider_cost' => $cost,
        'actual_user_cost' => $charged,
    ]);
}

function spendReport(array $query = []): TestResponse
{
    return test()->actingAs(test()->admin)->get(route('admin.reports.spend', $query));
}

describe('access', function () {
    it('is for operators only', function () {
        $this->get(route('admin.reports.spend'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('admin.reports.spend'))->assertForbidden();
        spendReport()->assertOk();
    });

    it('lets an operator grant admin rights from the command line', function () {
        $user = User::factory()->create(['email' => 'ops@example.test']);

        $this->artisan('users:admin', ['email' => 'ops@example.test'])->assertSuccessful();
        expect($user->fresh()->is_admin)->toBeTrue();

        $this->artisan('users:admin', ['email' => 'ops@example.test', '--revoke' => true])->assertSuccessful();
        expect($user->fresh()->is_admin)->toBeFalse();
    });
});

describe('figures', function () {
    it('totals charged, cost and profit per UTC day and per user, exactly', function () {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        billed($alice, '2026-11-19 23:59:59', '0.305510000', '0.3818875');
        billed($alice, '2026-11-20 00:00:01', '0.055405000', '0.06925625'); // next UTC day
        billed($bob, '2026-11-19 08:00:00', '0.062930000', '0.0786625');
        Generation::factory()->for($bob)->failed()->create(['completed_at' => '2026-11-19 09:00:00']);
        Generation::factory()->for($bob)->processing()->create();

        spendReport(['start' => '2026-11-19', 'end' => '2026-11-20'])->assertInertia(fn (Assert $page) => $page
            ->component('admin/SpendReport')
            ->where('report.days.0.date', '2026-11-20')
            ->where('report.days.0.generations', 1)
            ->where('report.days.1.date', '2026-11-19')
            ->where('report.days.1.generations', 2)
            ->where('report.days.1.cost.amount', '0.368440000000')
            ->where('report.days.1.charged.amount', '0.460550000000')
            ->where('report.days.1.profit.amount', '0.092110000000')
            ->has('report.days.1.users', 2)
            ->where('report.users.0.name', 'Alice')
            ->where('report.users.0.charged.amount', '0.451143750000')
            ->where('report.total.generations', 3)
            // profit = 0.52980625 charged − 0.423845 cost
            ->where('report.total.profit.amount', '0.105961250000'));
    });

    it('lists every day of the range, newest first, including days with nothing billed', function () {
        spendReport(['start' => '2026-11-14', 'end' => '2026-11-20'])->assertInertia(fn (Assert $page) => $page
            ->has('report.days', 7)
            ->where('report.days.6.date', '2026-11-14')
            ->where('report.days.6.cost.formatted', '$0.000000000'));
    });

    it('covers the last 30 days by default', function () {
        spendReport()->assertInertia(fn (Assert $page) => $page->where('report.start', '2026-10-22')->where('report.end', '2026-11-20'));
    });
});

describe('reconciliation with Pixelforge (GET /v1/usage)', function () {
    function pixelforgeBilled(string $date, int $generations, string $cost): void
    {
        test()->fake->setUsage(new ProviderUsageData([new ProviderUsageDayData($date, $generations, Usd::of($cost))]));
    }

    it('confirms days that match Pixelforge to the last decimal', function () {
        billed(User::factory()->create(), '2026-11-20 10:00:00', '0.169080000', '0.21135');
        pixelforgeBilled('2026-11-20', 1, '0.169080000');

        spendReport(['start' => '2026-11-20', 'end' => '2026-11-20'])->assertInertia(fn (Assert $page) => $page
            ->where('report.days.0.matches', true)
            ->where('report.pixelforge.total.formatted', '$0.169080000')
            ->where('report.pixelforge.mismatched_days', 0));
    });

    it('flags a day where our provider cost differs from Pixelforge\'s', function () {
        billed(User::factory()->create(), '2026-11-20 10:00:00', '0.169080000', '0.21135');
        pixelforgeBilled('2026-11-20', 2, '0.217827500'); // e.g. a generation we never settled

        spendReport(['start' => '2026-11-20', 'end' => '2026-11-20'])->assertInertia(fn (Assert $page) => $page
            ->where('report.days.0.matches', false)
            ->where('report.pixelforge.mismatched_days', 1));
    });

    it('still shows our figures when Pixelforge cannot be reached', function () {
        billed(User::factory()->create(), '2026-11-20 10:00:00', '0.169080000', '0.21135');
        $this->fake->setUsage(new ProviderUnavailableException('down'));

        spendReport(['start' => '2026-11-20', 'end' => '2026-11-20'])->assertInertia(fn (Assert $page) => $page
            ->where('report.pixelforge.available', false)
            ->where('report.days.0.matches', null)
            ->where('report.total.cost.formatted', '$0.169080000'));
    });

});

it('accepts only ranges /v1/usage can answer', function (array $query, string $field) {
    spendReport($query)->assertSessionHasErrors($field);
})->with([
    'more than 92 days' => [['start' => '2026-08-01', 'end' => '2026-11-20'], 'end'],
    'end before start' => [['start' => '2026-11-20', 'end' => '2026-11-19'], 'end'],
    'in the future' => [['start' => '2026-11-20', 'end' => '2026-11-21'], 'end'],
    'not a date' => [['start' => 'yesterday'], 'start'],
]);
