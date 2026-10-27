<?php

namespace Database\Factories;

use App\Models\HandoverRequest;
use App\Models\MaterialListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\HandoverRequest>
 */
class HandoverRequestFactory extends Factory
{
    protected $model = HandoverRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $requestedWeight = $this->faker->randomFloat(2, 1, 20);

        return [
            'listing_id'           => MaterialListing::factory(),
            'owner_id'             => User::factory(),
            'requester_id'         => User::factory(),
            'requested_weight_kg'  => $requestedWeight,
            'agreed_weight_kg'     => null,
            'actual_weight_kg'     => null,
            'note'                 => $this->faker->optional()->sentence(),
            'status'               => 'pending',
            'approved_at'          => null,
            'handed_over_at'       => null,
            'utilization_confirmed_at' => null,
        ];
    }

    /**
     * State: handover yang sudah disetujui.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'           => 'approved',
            'agreed_weight_kg' => $attributes['requested_weight_kg'],
            'approved_at'      => now()->subDays(rand(1, 7)),
        ]);
    }

    /**
     * State: handover yang sudah diserahkan.
     */
    public function handedOver(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'              => 'handed_over',
            'agreed_weight_kg'    => $attributes['requested_weight_kg'],
            'actual_weight_kg'    => $attributes['requested_weight_kg'],
            'approved_at'         => now()->subDays(rand(5, 14)),
            'handed_over_at'      => now()->subDays(rand(1, 4)),
        ]);
    }

    /**
     * State: pemanfaatan sudah dikonfirmasi.
     */
    public function utilized(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'                  => 'utilization_confirmed',
            'agreed_weight_kg'        => $attributes['requested_weight_kg'],
            'actual_weight_kg'        => $attributes['requested_weight_kg'],
            'approved_at'             => now()->subDays(rand(10, 20)),
            'handed_over_at'          => now()->subDays(rand(5, 9)),
            'utilization_confirmed_at' => now()->subDays(rand(1, 4)),
        ]);
    }

    /**
     * State: handover yang ditolak.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'note'   => 'Permintaan tidak dapat dipenuhi saat ini.',
        ]);
    }
}
