<?php

declare(strict_types=1);

use App\Actions\Generations\ApplyProviderUpdateAction;
use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge\GenerationPayloadMapper;
use App\Support\Money\Usd;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PixelforgeFixtures;

beforeEach(function () {
    $this->fake = fakePixelforge();
});

function buy(string $pack = 'standard', ?string $key = null): TestResponse
{
    return test()->post(route('credits.purchase'), ['pack' => $pack, 'idempotency_key' => $key ?? (string) Str::uuid()]);
}

/**
 * The invariant behind every balance: the cached column equals the ledger's sum.
 */
function expectBalanceMatchesLedger(User $user): void
{
    $ledger = DB::selectOne('SELECT COALESCE(SUM(amount), 0)::text AS total FROM credit_transactions WHERE user_id = ?', [$user->id])->total;

    expect($user->fresh()->balance->equals(Usd::of($ledger)))->toBeTrue();
}

describe('buying credits', function () {
    it('adds the pack to the balance through the ledger', function () {
        $user = User::factory()->create();

        $this->actingAs($user);
        buy('standard')->assertRedirect(route('credits.index'));

        expect($user->fresh()->balance->toDecimalString())->toBe('10.000000000000')
            ->and(CreditTransaction::sole())->type->toBe(CreditTransactionType::Purchase)->meta->toBe(['pack' => 'standard']);
        expectBalanceMatchesLedger($user);
    });

    it('credits a double-click or resubmitted form once', function () {
        $user = User::factory()->create();
        $this->actingAs($user);
        $key = (string) Str::uuid();

        buy('plus', $key);
        buy('plus', $key);

        expect($user->fresh()->balance->toDecimalString())->toBe('25.000000000000')->and(CreditTransaction::count())->toBe(1);
    });

    it('only sells packs from the server\'s list, whatever amount is posted', function () {
        $user = User::factory()->create();
        $this->actingAs($user);

        buy('million')->assertSessionHasErrors('pack');
        $this->post(route('credits.purchase'), ['pack' => 'starter', 'amount' => '1000000', 'idempotency_key' => (string) Str::uuid()]);

        expect($user->fresh()->balance->toDecimalString())->toBe('5.000000000000');
    });

    it('never lets one user replay another user\'s purchase key', function () {
        $key = (string) Str::uuid();
        $this->actingAs(User::factory()->create());
        buy('plus', $key);

        $other = User::factory()->create();
        $this->actingAs($other)->withoutExceptionHandling();

        expect(fn () => buy('plus', $key))->toThrow(LogicException::class)
            ->and($other->fresh()->balance->isZero())->toBeTrue();
    });

    it('limits how fast credits can be bought', function () {
        $this->actingAs(User::factory()->create());

        foreach (range(1, 10) as $_) {
            buy()->assertRedirect();
        }

        buy()->assertTooManyRequests();
    });
});

describe('spending credits', function () {
    beforeEach(fn () => Queue::fake());

    function submitGeneration(): TestResponse
    {
        return test()->post(route('generations.store'), ['prompt' => 'A lighthouse at dawn [succeed]', 'size' => '1024x1024', 'quality' => 'medium']);
    }

    it('blocks a generation the user cannot afford, with a clear reason', function () {
        $this->actingAs(User::factory()->withCredits('0.05')->create());

        submitGeneration()->assertSessionHasErrors(['credits' => 'This generation can cost up to $0.0863, but you have $0.0500 available. Buy credits to continue.']);

        expect(Generation::count())->toBe(0);
        Queue::assertNothingPushed();
    });

    it('holds back each running generation\'s maximum, so credits can\'t be spent twice', function () {
        $this->actingAs(User::factory()->withCredits('0.10')->create());

        submitGeneration()->assertRedirect(route('generations.index'));
        submitGeneration()->assertSessionHasErrors('credits'); // $0.10 − $0.0863 held = $0.0137 left

        expect(Generation::count())->toBe(1);
    });

    it('frees the held amount once a generation fails', function () {
        $user = User::factory()->withCredits('0.10')->create();
        $this->actingAs($user);
        submitGeneration();
        Generation::sole()->update(['status' => GenerationStatus::Failed, 'completed_at' => now()]);

        submitGeneration()->assertRedirect(route('generations.index'));
    });

    it('tells the Generate page whether the user can afford it', function () {
        $this->actingAs(User::factory()->withCredits('0.05')->create());

        $this->postJson(route('generations.estimate'), ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'medium'])
            ->assertJsonPath('affordable', false)
            ->assertJsonPath('available.formatted', '$0.0500');
    });
});

it('keeps balance and ledger in step through a whole life: buy, generate, succeed, fail', function () {
    $user = User::factory()->withCredits('10.00')->create();
    $succeeded = Generation::factory()->for($user)->queued()->create(['estimated_user_cost_max' => '0.5']);
    $failed = Generation::factory()->for($user)->queued()->create();

    app(ApplyProviderUpdateAction::class)->execute((new GenerationPayloadMapper)->map(PixelforgeFixtures::generationPayload(['id' => $succeeded->provider_generation_id])));
    app(ApplyProviderUpdateAction::class)->execute((new GenerationPayloadMapper)->map(PixelforgeFixtures::generationPayload([
        'id' => $failed->provider_generation_id, 'status' => 'failed', 'usage' => null, 'error' => ['code' => 'internal_error', 'message' => 'x'],
    ])));

    expect($user->fresh()->balance->toDecimalString())->toBe('9.618112500000'); // 10 − 0.3818875
    expectBalanceMatchesLedger($user);
});

describe('the Credits page', function () {
    it('shows the balance, what is held, the packs and only the user\'s own history', function () {
        $user = User::factory()->withCredits('10.00')->create();
        Generation::factory()->for($user)->queued()->create(['estimated_user_cost_max' => '0.086300000000']);
        User::factory()->withCredits('5.00')->create();

        $this->actingAs($user)->get(route('credits.index'))->assertInertia(fn (Assert $page) => $page
            ->component('credits/Index')
            ->where('summary.balance.formatted', '$10.0000')
            ->where('summary.reserved.formatted', '$0.0863')
            ->where('summary.available.formatted', '$9.9137')
            ->has('packs', 3)
            ->where('packs.0.amount.formatted', '$5.00')
            ->has('transactions.data', 1)
            ->where('purchaseKey', fn (string $key) => Str::isUuid($key)));
    });

    it('shares the balance with every signed-in page', function () {
        $this->actingAs(User::factory()->withCredits('5.00')->create())
            ->get(route('generations.create'))
            ->assertInertia(fn (Assert $page) => $page->where('credits.available.formatted', '$5.0000'));
    });
});
