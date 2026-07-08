<?php

namespace Tests\Feature;

use App\Models\Concerns\HasExternalReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExternalReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('external_reference_test_models', function (Blueprint $table) {
            $table->id();
            $table->externalRef();
            $table->timestamps();
        });
    }

    public function test_the_migration_macro_adds_a_nullable_external_ref_column()
    {
        $this->assertTrue(Schema::hasColumn('external_reference_test_models', 'external_ref'));

        $standalone = ExternalReferenceTestModel::create();

        $this->assertNull($standalone->external_ref);
    }

    public function test_the_trait_distinguishes_linked_from_standalone_records()
    {
        $standalone = ExternalReferenceTestModel::create();
        $linked = ExternalReferenceTestModel::create(['external_ref' => 'host-4711']);

        $this->assertFalse($standalone->isExternallyLinked());
        $this->assertTrue($linked->isExternallyLinked());

        $this->assertTrue(ExternalReferenceTestModel::externallyLinked()->get()->contains($linked));
        $this->assertFalse(ExternalReferenceTestModel::externallyLinked()->get()->contains($standalone));
    }
}

class ExternalReferenceTestModel extends Model
{
    use HasExternalReference;

    protected $guarded = [];
}
