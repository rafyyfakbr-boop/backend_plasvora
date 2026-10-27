<?php

namespace Database\Factories;

use App\Models\MaterialRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MaterialRequest>
 */
class MaterialRequestFactory extends Factory
{
    protected $model = MaterialRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requester_id'        => User::factory(),
            'material_category'   => $this->faker->randomElement(MaterialRequest::CATEGORIES),
            'requested_weight_kg' => $this->faker->randomFloat(2, 1, 50),
            'minimum_condition'   => $this->faker->randomElement(MaterialRequest::CONDITIONS),
            'general_location'    => $this->faker->randomElement([
                'Jakarta Selatan',
                'Bandung Utara',
                'Surabaya Barat',
                'Yogyakarta',
                'Semarang Tengah',
            ]),
            'intended_use' => $this->faker->randomElement([
                'Kerajinan daur ulang sekolah',
                'Bahan baku pembuatan pot tanaman',
                'Proyek seni komunitas lingkungan',
                'Bahan baku industri kreatif kecil',
                'Riset material daur ulang',
                'Bahan ecobrick untuk bangunan komunitas',
            ]),
            'status' => 'active',
        ];
    }

    /**
     * State: permintaan yang sudah terpenuhi.
     */
    public function fulfilled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'fulfilled',
        ]);
    }

    /**
     * State: permintaan yang dibatalkan.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
