<?php

namespace Database\Factories;

use App\Models\MaterialListing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MaterialListing>
 */
class MaterialListingFactory extends Factory
{
    protected $model = MaterialListing::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category  = $this->faker->randomElement(MaterialListing::CATEGORIES);
        $condition = $this->faker->randomElement(MaterialListing::CONDITIONS);
        $isFree    = $this->faker->boolean(30); // 30% kemungkinan gratis
        $price     = $isFree ? 0 : $this->faker->randomFloat(2, 500, 10000);

        return [
            'owner_id'         => User::factory(),
            'name'             => 'Sampah ' . $category . ' ' . $this->faker->word(),
            'category'         => $category,
            'weight_kg'        => $this->faker->randomFloat(2, 0.5, 100),
            'condition'        => $condition,
            'price'            => $price,
            'is_free'          => $isFree,
            'general_location' => $this->faker->randomElement([
                'Jakarta Selatan',
                'Bandung Utara',
                'Surabaya Barat',
                'Yogyakarta',
                'Semarang Tengah',
                'Depok',
                'Bekasi',
                'Tangerang',
            ]),
            'description'      => $this->faker->paragraph(),
            'image_url'        => null,
            'status'           => 'available',
        ];
    }

    /**
     * State: listing dengan status available.
     */
    public function available(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'available',
        ]);
    }

    /**
     * State: listing dengan status reserved.
     */
    public function reserved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'reserved',
        ]);
    }

    /**
     * State: listing dengan status completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
        ]);
    }
}
