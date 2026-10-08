<?php

use App\Models\User;
use App\Models\WorkflowStage;
use App\Services\ClassificationService;
use App\Services\WorkflowService;
use Illuminate\Http\UploadedFile;

/**
 * Regression coverage for a real reported gap: custom_routed alone can't
 * tell apart the two ways a document ends up hand-routed — option 2
 * ("choose the approver(s) yourself", a real matched category) and option
 * 3 ("doesn't belong to any of our categories") both set it identically,
 * so the tracking page showed the same "Custom Routed" badge for both.
 * See tracking-content.blade.php's own comment on this.
 */
function customRoutedDocument(string $routingMode): array
{
    $originator = User::factory()->originator()->create();
    $approver = User::factory()->approver('Job Order')->create();

    $mock = Mockery::mock(ClassificationService::class);
    $mock->shouldReceive('classify')->andReturn(['category' => 'Job Order', 'confidence' => 90.0, 'margin' => 80.0, 'model_id' => null]);
    $mock->shouldReceive('autoTrainIfDue')->andReturn(null);
    app()->instance(ClassificationService::class, $mock);

    $content = "Job Order No: JO-1\nDate Requested: today\nRequested By: someone\nDescription of Work: "
        .str_repeat('fix the widget assembly line carefully and thoroughly ', 5)
        ."\nEstimated Cost: PHP 3,500.00";

    $document = app(WorkflowService::class)->ingest(
        UploadedFile::fake()->createWithContent('test.txt', $content),
        $originator,
        now()->addDay()->toDateTimeString(),
        null, null, false, $routingMode,
    );

    app(WorkflowService::class)->routeToCustomApprovers($document, [$approver->user_id], $originator);

    return [$document->fresh(), $originator];
}

test('a document routed via "choose the approver(s) yourself" shows the plain Custom Routed badge', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    [$document, $originator] = customRoutedDocument('custom');

    $response = $this->actingAs($originator)->get(route('originator.documents.show', $document));

    $response->assertOk()
        ->assertSee('>Custom Routed<', false)
        ->assertDontSee('Other &middot; Custom Routed', false);
});

test('a document routed via "doesn\'t belong to any of our categories" shows its own distinct badge', function () {
    WorkflowStage::create(['document_category' => 'Job Order', 'stage_name' => 'Technical Review', 'sequence_order' => 1]);
    [$document, $originator] = customRoutedDocument('unrelated');

    $response = $this->actingAs($originator)->get(route('originator.documents.show', $document));

    $response->assertOk()->assertSee('Other &middot; Custom Routed', false);
});
