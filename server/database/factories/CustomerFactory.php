<?php

namespace Database\Factories;

use App\Enums\AddressType;
use App\Enums\CustomerKind;
use App\Models\Corporation;
use App\Models\Customer;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'corporation_id' => Corporation::factory(),
            'kind' => CustomerKind::Person,
            'name' => fake()->name(),
            'address' => fake()->streetAddress(),
        ];
    }

    /**
     * A mailbox for this person.
     *
     * An email is an identity now, not a column, so it cannot be a plain
     * attribute in `definition()` — the customer has to exist before an
     * address can point at them. Off by default for the same reason a phone
     * number is: nothing about a customer is required.
     */
    public function withEmail(?string $email = null): static
    {
        return $this->afterCreating(fn (Customer $customer) => $customer->identities()->create([
            'corporation_id' => $customer->corporation_id,
            'address_type' => AddressType::Email,
            // Identities hold already-normalized values by contract, so the
            // factory normalizes exactly as the real write path does.
            'value' => AddressType::Email->normalize($email ?? fake()->unique()->safeEmail()),
        ]));
    }

    /**
     * A number that is actually dialable in Denmark.
     *
     * Danish mobile ranges are allocated in patches — plenty of numbers that
     * look plausible are not assigned to anything — and the model normalizes
     * on write, so a made-up number would be rejected. Generate and check.
     */
    public static function danishMobile(): string
    {
        do {
            $number = '+45'.fake()->randomElement(['20', '26', '27', '28', '30', '40', '50', '60', '71', '81']).fake()->numerify('######');
        } while (! PhoneNumber::isValid($number));

        return $number;
    }

    /**
     * Somebody the firm is talking to but has not identified — what an
     * unknown number becomes.
     */
    public function unnamed(): static
    {
        return $this->state(fn () => ['name' => null]);
    }

    public function organization(): static
    {
        return $this->state(fn () => [
            'kind' => CustomerKind::Organization,
            'name' => fake()->company(),
        ]);
    }
}
