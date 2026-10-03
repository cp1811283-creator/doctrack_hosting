<?php

use App\Services\DocxRichContentService;
use App\Services\TextExtractionService;
use Tests\Support\DocxFixtureBuilder;

function buildFixtureDocx(): string
{
    $path = sys_get_temp_dir().'/docx-fixture-'.uniqid().'.docx';
    DocxFixtureBuilder::build($path);

    return $path;
}

it('every real text node in the rendered HTML, with tags stripped, reproduces flat_text exactly — the invariant offset-based highlighting depends on', function () {
    $path = buildFixtureDocx();

    $rendered = app(DocxRichContentService::class)->render($path);

    expect(strip_tags($rendered['html']))->toBe($rendered['flat_text']);

    @unlink($path);
});

it('produces flat text byte-identical to TextExtractionService::extractFromDocx() for the same file', function () {
    $path = buildFixtureDocx();

    $viaExtraction = app(TextExtractionService::class)->extractFromDocx($path);
    $viaRichRenderer = app(DocxRichContentService::class)->render($path)['flat_text'];

    expect($viaRichRenderer)->toBe($viaExtraction)
        ->and($viaRichRenderer)->toBe("Label: value text\nCell A\nCell B\nAfter image\n");

    @unlink($path);
});

it('renders bold, a real table, and an inlined image from the actual file', function () {
    $path = buildFixtureDocx();

    $html = app(DocxRichContentService::class)->render($path)['html'];

    expect($html)->toContain('<strong>Label: </strong>')
        ->and($html)->toContain('<table')
        ->and($html)->toContain('Cell A')
        ->and($html)->toContain('Cell B')
        ->and($html)->toContain('<img src="data:image/png;base64,')
        ->and($html)->toContain('After image');

    @unlink($path);
});

it('highlights an open annotation range with <mark> across the same offsets as the plain-text panel would', function () {
    $path = buildFixtureDocx();

    // "value text" sits at offset 7-17 ("Label: " is 7 chars).
    $html = app(DocxRichContentService::class)->render($path, [
        ['start_offset' => 7, 'end_offset' => 17, 'tooltip' => 'Fix this'],
    ])['html'];

    expect($html)->toContain('<mark')
        ->and($html)->toContain('title="Fix this"')
        ->and($html)->toContain('<mark class="bg-processing-100 text-processing-900 rounded px-0.5 cursor-help" title="Fix this">value text</mark>');

    @unlink($path);
});

it('surgically replaces text within a single run, leaving the table and image untouched', function () {
    $path = buildFixtureDocx();

    $newFlatText = app(DocxRichContentService::class)->replaceTextRange($path, 7, 17, 'corrected value');

    expect($newFlatText)->toBe("Label: corrected value\nCell A\nCell B\nAfter image\n");

    $rerendered = app(DocxRichContentService::class)->render($path);
    expect($rerendered['flat_text'])->toBe($newFlatText)
        ->and($rerendered['html'])->toContain('<strong>Label: </strong>')
        ->and($rerendered['html'])->toContain('corrected value')
        ->and($rerendered['html'])->toContain('<img src="data:image/png;base64,')
        ->and($rerendered['html'])->toContain('Cell A');

    @unlink($path);
});

it('surgically replaces text that spans two separate runs', function () {
    $path = buildFixtureDocx();

    // Offsets 3-10 span the end of "Label: " (run 1, chars 3-7) and the
    // start of "value text" (run 2, chars 7-10 = "val").
    $newFlatText = app(DocxRichContentService::class)->replaceTextRange($path, 3, 10, 'XX');

    expect($newFlatText)->toBe("LabXXue text\nCell A\nCell B\nAfter image\n");

    @unlink($path);
});

it('rejects a replacement range that crosses a paragraph boundary rather than risk corrupting the file', function () {
    $path = buildFixtureDocx();

    // Offset 17 is the "\n" ending paragraph 1; 20 lands inside "Cell A".
    expect(fn () => app(DocxRichContentService::class)->replaceTextRange($path, 17, 20, 'x'))
        ->toThrow(RuntimeException::class);

    @unlink($path);
});

it('rejects a replacement range that is no longer found in the document', function () {
    $path = buildFixtureDocx();

    expect(fn () => app(DocxRichContentService::class)->replaceTextRange($path, 9999, 10005, 'x'))
        ->toThrow(RuntimeException::class);

    @unlink($path);
});

it('tags every real text segment with data-seg in document order when requested', function () {
    $path = buildFixtureDocx();

    $html = app(DocxRichContentService::class)->render($path, [], true)['html'];

    // 4 real text runs in the fixture: "Label: ", "value text", "Cell A", "Cell B".
    expect($html)->toContain('<span data-seg="0">')
        ->and($html)->toContain('<span data-seg="1">')
        ->and($html)->toContain('<span data-seg="2">')
        ->and($html)->toContain('<span data-seg="3">')
        ->and($html)->toContain('<span data-seg="0">Label: </span>')
        ->and($html)->toContain('<span data-seg="1">value text</span>');

    @unlink($path);
});

it('applies edit-anywhere text changes by segment index, leaving images and tables untouched', function () {
    $path = buildFixtureDocx();

    // Segment order: 0 "Label: ", 1 "value text", 2 "Cell A", 3 "Cell B", 4 "After image".
    $newFlatText = app(DocxRichContentService::class)->applyTextEdits($path, [
        'Field: ', 'corrected value', 'Cell A', 'Cell X', 'After image, edited',
    ]);

    expect($newFlatText)->toBe("Field: corrected value\nCell A\nCell X\nAfter image, edited\n");

    $rerendered = app(DocxRichContentService::class)->render($path);
    expect($rerendered['html'])->toContain('<strong>Field: </strong>')
        ->and($rerendered['html'])->toContain('<img src="data:image/png;base64,')
        ->and($rerendered['html'])->toContain('<table');

    @unlink($path);
});

it('rejects edit-anywhere submission whose segment count no longer matches the real document (a structural change)', function () {
    $path = buildFixtureDocx();

    expect(fn () => app(DocxRichContentService::class)->applyTextEdits($path, ['only one segment submitted']))
        ->toThrow(RuntimeException::class);

    @unlink($path);
});

it('visually collapses a long run of blank paragraphs into a divider, without removing a single character from the text stream', function () {
    $path = sys_get_temp_dir().'/docx-blankrun-'.uniqid().'.docx';
    DocxFixtureBuilder::buildWithLongBlankRun($path);

    $rendered = app(DocxRichContentService::class)->render($path);
    $expectedFlatText = 'Title'."\n".str_repeat("\n", 10).'Body content'."\n";

    expect($rendered['html'])->toContain('<hr')
        // The real "\n" characters are PRESERVED, not swapped for a
        // differently-sized label — only visually squashed (see this
        // method's own docblock on why a client-side offset walk
        // depends on that). Confirmed two ways: the literal run is
        // still right there in the HTML...
        ->and($rendered['html'])->toContain(str_repeat("\n", 10))
        // ...and stripping every tag out of the HTML reproduces
        // flat_text byte-for-byte — the exact invariant
        // findDocxRangeAt() (tracking.blade.php) depends on to land a
        // flagged passage's highlight on the right text instead of
        // silently drifting past a collapsed page-break run.
        ->and(strip_tags($rendered['html']))->toBe($expectedFlatText)
        ->and($rendered['flat_text'])->toBe($expectedFlatText);

    @unlink($path);
});

it('does not collapse a normal single blank line between two paragraphs', function () {
    $path = buildFixtureDocx();

    $html = app(DocxRichContentService::class)->render($path)['html'];

    expect($html)->not->toContain('<hr');

    @unlink($path);
});

it('is a no-op for segments whose submitted text is unchanged', function () {
    $path = buildFixtureDocx();
    $before = app(DocxRichContentService::class)->render($path)['flat_text'];

    $after = app(DocxRichContentService::class)->applyTextEdits($path, [
        'Label: ', 'value text', 'Cell A', 'Cell B', 'After image',
    ]);

    expect($after)->toBe($before);

    @unlink($path);
});
