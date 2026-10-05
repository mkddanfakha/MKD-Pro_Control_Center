<?php

namespace Tests\Unit\Services\Provisioning;

use App\Models\ProvisioningRunStep;
use App\Services\Provisioning\ProvisioningStepRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProvisioningStepRegistryTest extends TestCase
{
    private ProvisioningStepRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new ProvisioningStepRegistry;
    }

    public function test_registry_contains_exactly_eighteen_steps_in_documented_order(): void
    {
        $keys = $this->registry->orderedStepKeys();

        $this->assertSame(18, $this->registry->stepCount());
        $this->assertSame(ProvisioningRunStep::CANONICAL_STEP_KEYS, $keys);
        $this->assertSame('validate', $keys[0]);
        $this->assertSame('mark_ready', $keys[17]);
    }

    public function test_no_duplicate_or_unknown_step_keys(): void
    {
        $keys = $this->registry->orderedStepKeys();
        $this->registry->assertRegistryIntegrity($keys);
        $this->assertSame(18, count(array_unique($keys)));
    }

    public function test_assert_registry_integrity_rejects_missing_or_extra_steps(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->assertRegistryIntegrity(['validate']);
    }

    #[DataProvider('duplicateKeysProvider')]
    public function test_assert_registry_integrity_rejects_duplicates(array $keys): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->assertRegistryIntegrity($keys);
    }

    /**
     * @return array<string, array{0: list<string>}>
     */
    public static function duplicateKeysProvider(): array
    {
        $base = ProvisioningRunStep::CANONICAL_STEP_KEYS;
        $dup = $base;
        $dup[3] = $base[0];

        return ['duplicate validate' => [$dup]];
    }

    public function test_ordered_descriptors_match_one_based_order(): void
    {
        $descriptors = $this->registry->orderedDescriptors();
        $this->assertCount(18, $descriptors);
        $this->assertSame('validate', $descriptors[0]['step_key']);
        $this->assertSame(1, $descriptors[0]['step_order']);
        $this->assertSame('mark_ready', $descriptors[17]['step_key']);
        $this->assertSame(18, $descriptors[17]['step_order']);
    }

    public function test_executable_registry_starts_empty(): void
    {
        $this->assertTrue($this->registry->isEmpty());
        $this->assertSame([], $this->registry->orderedExecutableSteps());
    }

    public function test_register_and_retrieve_executable_step(): void
    {
        $step = new \Tests\Fakes\Provisioning\FakeSuccessfulStep('prepare', 1);
        $this->registry->register($step);

        $this->assertFalse($this->registry->isEmpty());
        $this->assertTrue($this->registry->hasExecutableStep('prepare'));
        $this->assertSame('prepare', $this->registry->getExecutableStep('prepare')->stepKey());
    }

    public function test_register_rejects_duplicate_step_key(): void
    {
        $this->registry->register(new \Tests\Fakes\Provisioning\FakeSuccessfulStep('prepare', 1));

        $this->expectException(InvalidArgumentException::class);
        $this->registry->register(new \Tests\Fakes\Provisioning\FakeSuccessfulStep('prepare', 2));
    }

    public function test_unknown_executable_step_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->registry->getExecutableStep('missing');
    }

    public function test_executable_steps_sorted_by_order_then_key(): void
    {
        $this->registry->register(new \Tests\Fakes\Provisioning\FakeSpyStep('b', 20));
        $this->registry->register(new \Tests\Fakes\Provisioning\FakeSpyStep('a', 10));

        $ordered = $this->registry->orderedExecutableSteps();
        $this->assertSame(['a', 'b'], array_map(fn ($s) => $s->stepKey(), $ordered));
    }
}
