<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMText;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/**
 * DocxRichContentService
 * ------------------------
 * Feature: "Review & Comment" and the originator's revision editor show
 * the ACTUAL uploaded .docx — images, tables, bold/italic labels and all
 * — instead of the flattened plain text TextExtractionService produces
 * for classification. A flagged passage is corrected by surgically
 * editing the real word/document.xml in place (via PHP's built-in
 * ZipArchive, already relied on elsewhere in this app — no new Composer
 * package), so the file Archive later shows/prints/downloads is the
 * exact same document, same images and tables, with only the flagged
 * text corrected.
 *
 * The one invariant everything here depends on: render()'s flat_text
 * MUST stay byte-for-byte identical to TextExtractionService::
 * extractFromDocx()'s output for the same file — DocumentAnnotation's
 * start_offset/end_offset are already numbered against that string (see
 * its own docblock), and reusing that exact numbering is what lets this
 * feature work without inventing a second, parallel offset scheme or
 * touching the existing flag/withdraw JS at all. Concretely: every real
 * run of text contributes its characters in document order; every
 * paragraph end and every manual line break (<w:br/>) contributes
 * exactly one "\n" character; a <w:tab/> contributes nothing (silently
 * dropped by extractFromDocx()'s strip_tags(), so it must be dropped
 * here too); an image/table structural tag contributes nothing on its
 * own (only the real text inside a table cell's paragraphs does, via the
 * exact same paragraph/run walk used for the document body).
 */
class DocxRichContentService
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';

    private const NS_WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';

    private const IMAGE_MIME_BY_EXTENSION = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
    ];

    /**
     * Renders the document as real HTML (images inlined as base64, tables
     * as <table>, bold/italic/underline preserved) alongside its flat
     * text (see this class's own docblock for why the two must stay in
     * lockstep). $annotations (already sorted by start_offset, each a
     * ['start_offset' => int, 'end_offset' => int, 'tooltip' => string]
     * array — tooltip pre-escaped by the caller) are highlighted with
     * the same <mark> treatment the plain-text "Review & Comment" panel
     * already uses for a non-.docx document.
     *
     * $tagSegments wraps every real text run in a
     * <span data-seg="N" contenteditable="true">, N matching exactly the
     * order applyTextEdits() below reads back — see that method's own
     * docblock for the full edit-anywhere-in-the-document feature this
     * exists for. Never combined with $annotations in practice (the
     * originator's full edit view shows no flag highlights, see
     * tracking-content.blade.php — the open flags are listed separately
     * above it instead, same layout as every other file type's revision
     * screen), so the two features' HTML never has to nest together.
     *
     * @param  array<int, array{start_offset: int, end_offset: int, tooltip: string}>  $annotations
     * @return array{html: string, flat_text: string}
     */
    public function render(string $absolutePath, array $annotations = [], bool $tagSegments = false): array
    {
        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException('Could not open .docx file.');
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            throw new RuntimeException('.docx file has no word/document.xml.');
        }

        $relationships = $this->loadRelationships($zip);

        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('w', self::NS_W);
        $xpath->registerNamespace('r', self::NS_R);
        $xpath->registerNamespace('a', self::NS_A);

        $body = $xpath->query('/w:document/w:body')->item(0);

        $marker = new DocxFlatTextMarker($annotations);
        $html = '';
        $flatText = '';
        $segmentIndex = 0;

        if ($body instanceof DOMElement) {
            $this->renderContainer($body, $xpath, $zip, $relationships, $marker, $html, $flatText, $tagSegments, $segmentIndex);
        }

        $html .= $marker->finish();
        $html = $this->collapsePageBreakGaps($html);

        $zip->close();

        return ['html' => $html, 'flat_text' => $flatText];
    }

    /**
     * The "edit anywhere, text only" feature: compares $segmentTexts
     * (index => current text, in document order — exactly what
     * render()'s $tagSegments => true output lets the client collect by
     * reading every [data-seg] element's own textContent back) against
     * what's actually in the live file right now, and writes back only
     * the ones that actually changed. Images, tables, and formatting are
     * never touched — this only ever sets a <w:t>'s own text content.
     *
     * Deliberately REJECTS rather than guesses when $segmentTexts has a
     * different COUNT than the file's own real text segments — that only
     * happens if something besides a segment's text changed (an image
     * removed, a table row added/removed, a paragraph split or merged),
     * none of which this method can safely translate back into the XML.
     * Revision is text correction, not document restructuring — see this
     * feature's own scoping discussion.
     */
    public function applyTextEdits(string $absolutePath, array $segmentTexts): string
    {
        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException('Could not open .docx file.');
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            throw new RuntimeException('.docx file has no word/document.xml.');
        }

        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('w', self::NS_W);

        $body = $xpath->query('/w:document/w:body')->item(0);
        if (! $body instanceof DOMElement) {
            $zip->close();
            throw new RuntimeException('.docx file has no document body.');
        }

        $segments = [];
        $this->collectSegments($body, $xpath, $segments);
        $textSegments = array_values(array_filter($segments, fn (array $segment) => ! $segment['boundary']));

        if (count($segmentTexts) !== count($textSegments)) {
            $zip->close();
            throw new RuntimeException("This document's structure changed (an image, table row, or paragraph was added or removed) — only text edits are supported. Please reload and try again.");
        }

        foreach (array_values($segmentTexts) as $index => $newText) {
            /** @var DOMText $node */
            $node = $textSegments[$index]['node'];
            if ($node->nodeValue !== $newText) {
                $node->nodeValue = $newText;
            }
        }

        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $doc->saveXML());
        $zip->close();

        return $this->render($absolutePath)['flat_text'];
    }

    /**
     * Surgically replaces the text spanning [$start, $end) in the SAME
     * flat-text numbering render() produces, writing the change straight
     * into word/document.xml inside the existing file (same path, same
     * images, same tables, same styles — only the flagged run(s)' own
     * text content changes) and returns the document's new flat text.
     *
     * Scoped to a single paragraph on purpose: a flagged passage that
     * includes a paragraph break or a manual line break (<w:br/>) would
     * require restructuring the document rather than editing text in
     * place, which this method declines rather than risking a corrupted
     * file — see this class's docblock.
     */
    public function replaceTextRange(string $absolutePath, int $start, int $end, string $replacement): string
    {
        if ($end <= $start) {
            throw new RuntimeException('Invalid replacement range.');
        }

        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            throw new RuntimeException('Could not open .docx file.');
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            throw new RuntimeException('.docx file has no word/document.xml.');
        }

        $doc = new DOMDocument;
        $doc->loadXML($xml);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('w', self::NS_W);

        $body = $xpath->query('/w:document/w:body')->item(0);
        if (! $body instanceof DOMElement) {
            $zip->close();
            throw new RuntimeException('.docx file has no document body.');
        }

        $segments = [];
        $this->collectSegments($body, $xpath, $segments);

        $affected = array_values(array_filter(
            $segments,
            fn (array $segment) => $segment['end'] > $start && $segment['start'] < $end
        ));

        if ($affected === []) {
            $zip->close();
            throw new RuntimeException('Flagged passage no longer found in this document.');
        }

        foreach ($affected as $segment) {
            if ($segment['boundary']) {
                $zip->close();
                throw new RuntimeException('This flagged passage spans more than one paragraph and cannot be auto-applied — please re-flag a passage within a single paragraph.');
            }
        }

        foreach ($affected as $index => $segment) {
            /** @var DOMText $node */
            $node = $segment['node'];
            $original = $node->nodeValue;
            $localStart = max($start, $segment['start']) - $segment['start'];
            $localEnd = min($end, $segment['end']) - $segment['start'];
            $before = mb_substr($original, 0, $localStart);
            $after = mb_substr($original, $localEnd);
            $node->nodeValue = $before.($index === 0 ? $replacement : '').$after;
        }

        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $doc->saveXML());
        $zip->close();

        return $this->render($absolutePath)['flat_text'];
    }

    /**
     * r:id -> media/imageN.png, read once from word/_rels/document.xml.rels.
     *
     * @return array<string, string>
     */
    private function loadRelationships(ZipArchive $zip): array
    {
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
        if ($relsXml === false) {
            return [];
        }

        $relsDoc = new DOMDocument;
        $relsDoc->loadXML($relsXml);

        $map = [];
        foreach ($relsDoc->getElementsByTagName('Relationship') as $relationship) {
            $id = $relationship->getAttribute('Id');
            $target = $relationship->getAttribute('Target');
            if ($id !== '' && $target !== '') {
                $map[$id] = 'word/'.ltrim($target, '/');
            }
        }

        return $map;
    }

    /**
     * Walks a block container (the document body, or a table cell) in
     * document order — a straight paragraph becomes flowing text/images,
     * a table becomes a real <table>, each cell recursing into this same
     * method. Appends directly into $html/$flatText by reference so a
     * table cell's own text keeps contributing to the SAME running
     * offset numbering as the rest of the document (matching
     * extractFromDocx(), which never treats a table specially either).
     *
     * @param  array<string, string>  $relationships
     */
    private function renderContainer(
        DOMElement $container,
        DOMXPath $xpath,
        ZipArchive $zip,
        array $relationships,
        DocxFlatTextMarker $marker,
        string &$html,
        string &$flatText,
        bool $tagSegments,
        int &$segmentIndex
    ): void {
        foreach ($container->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 'p') {
                $this->renderParagraph($child, $xpath, $zip, $relationships, $marker, $html, $flatText, $tagSegments, $segmentIndex);
            } elseif ($child->localName === 'tbl') {
                $html .= '<table class="border-collapse border border-surface-300 my-2 text-sm">';
                foreach ($child->childNodes as $row) {
                    if (! $row instanceof DOMElement || $row->localName !== 'tr') {
                        continue;
                    }
                    $html .= '<tr>';
                    foreach ($row->childNodes as $cell) {
                        if (! $cell instanceof DOMElement || $cell->localName !== 'tc') {
                            continue;
                        }
                        $html .= '<td class="border border-surface-300 px-2 py-1 align-top">';
                        $this->renderContainer($cell, $xpath, $zip, $relationships, $marker, $html, $flatText, $tagSegments, $segmentIndex);
                        $html .= '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</table>';
            }
        }
    }

    /**
     * @param  array<string, string>  $relationships
     */
    private function renderParagraph(
        DOMElement $paragraph,
        DOMXPath $xpath,
        ZipArchive $zip,
        array $relationships,
        DocxFlatTextMarker $marker,
        string &$html,
        string &$flatText,
        bool $tagSegments,
        int &$segmentIndex
    ): void {
        foreach ($xpath->query('.//w:r | .//w:br', $paragraph) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->localName === 'br') {
                $html .= $marker->consume("\n");
                $flatText .= "\n";

                continue;
            }

            [$runHtml, $runText] = $this->renderRun($node, $zip, $relationships, $marker, $tagSegments, $segmentIndex);
            $html .= $runHtml;
            $flatText .= $runText;
        }

        $html .= $marker->consume("\n");
        $flatText .= "\n";
    }

    /**
     * @param  array<string, string>  $relationships
     * @return array{0: string, 1: string} [html, flatTextContributed]
     */
    private function renderRun(DOMElement $run, ZipArchive $zip, array $relationships, DocxFlatTextMarker $marker, bool $tagSegments, int &$segmentIndex): array
    {
        $bold = $run->getElementsByTagNameNS(self::NS_W, 'rPr')->item(0)?->getElementsByTagNameNS(self::NS_W, 'b')->item(0) !== null;
        $italic = $run->getElementsByTagNameNS(self::NS_W, 'rPr')->item(0)?->getElementsByTagNameNS(self::NS_W, 'i')->item(0) !== null;
        $underline = $run->getElementsByTagNameNS(self::NS_W, 'rPr')->item(0)?->getElementsByTagNameNS(self::NS_W, 'u')->item(0) !== null;

        $html = '';
        $flatText = '';

        foreach ($run->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 't') {
                $text = $child->textContent;
                if ($text === '') {
                    continue;
                }
                $marked = $marker->consume($text);
                // Wraps the WHOLE segment, outside any <mark> that
                // consume() just opened/closed — see collectSegments(),
                // which walks these exact same <w:t> nodes in this exact
                // same order, so index N here always means the same real
                // XML text node as index N there.
                $html .= $tagSegments
                    ? '<span data-seg="'.$segmentIndex++.'">'.$marked.'</span>'
                    : $marked;
                $flatText .= $text;
            } elseif ($child->localName === 'drawing') {
                $html .= $this->renderDrawing($child, $zip, $relationships);
            }
            // w:tab and anything else contribute nothing — see this
            // class's own docblock on why that mirrors extractFromDocx().
        }

        if ($flatText !== '') {
            $open = ($bold ? '<strong>' : '').($italic ? '<em>' : '').($underline ? '<u>' : '');
            $close = ($underline ? '</u>' : '').($italic ? '</em>' : '').($bold ? '</strong>' : '');
            if ($open !== '') {
                $html = $open.$html.$close;
            }
        }

        return [$html, $flatText];
    }

    private function renderDrawing(DOMElement $drawing, ZipArchive $zip, array $relationships): string
    {
        $blip = $drawing->getElementsByTagNameNS(self::NS_A, 'blip')->item(0);
        if (! $blip instanceof DOMElement) {
            return '';
        }

        $relId = $blip->getAttributeNS(self::NS_R, 'embed');
        if ($relId === '' || ! isset($relationships[$relId])) {
            return '';
        }

        $mediaPath = $relationships[$relId];
        $data = $zip->getFromName($mediaPath);
        if ($data === false) {
            return '';
        }

        $extension = strtolower(pathinfo($mediaPath, PATHINFO_EXTENSION));
        $mime = self::IMAGE_MIME_BY_EXTENSION[$extension] ?? null;
        if ($mime === null) {
            return '';
        }

        $base64 = base64_encode($data);

        // Sized from the width the .docx file itself declares (wp:extent,
        // in EMU: 914400 per inch, 96 CSS px per inch), so an image keeps
        // the size it was laid out at in Word — a fixed max-height instead
        // scaled a tall letterhead down unevenly and changed the page
        // layout. The wrapper keeps the image whole when printing: browsers
        // ignore break-inside on a bare <img>, so without a block
        // container an image straddling a page edge gets split.
        $extent = $drawing->getElementsByTagNameNS(self::NS_WP, 'extent')->item(0);
        $widthPx = $extent instanceof DOMElement && (int) $extent->getAttribute('cx') > 0
            ? round((int) $extent->getAttribute('cx') / 914400 * 96)
            : null;
        $sizeStyle = $widthPx !== null ? 'width:'.$widthPx.'px;' : '';

        return '<span class="docx-figure" style="display:block;break-inside:avoid;page-break-inside:avoid;"><img src="data:'.$mime.';base64,'.$base64.'" alt="" style="'.$sizeStyle.'max-width:100%;height:auto;" class="my-2 block rounded border border-surface-200"></span>';
    }

    /**
     * A real .docx often reaches a new printed page not through an
     * explicit page-break marker but simply by accumulating enough
     * content/blank paragraphs to overflow one page's height — invisible
     * in Word (the overflow just continues on the next page), but this
     * renderer has no concept of "page height" at all (continuous HTML
     * flow, not print pagination — building real print-accurate
     * pagination would mean replicating Word's own page size/margin/line-
     * metrics math, a much larger undertaking than this feature needs).
     * Confirmed against a real uploaded document: ~30 consecutive blank
     * paragraphs, invisible in Word, rendered as one long empty scroll
     * here. A run of several consecutive paragraph-boundary newlines (see
     * renderParagraph()) with nothing real between them is a reliable
     * sign this is exactly that case — a single normal blank line
     * (paragraph spacing) is only 1-2 newlines and stays untouched.
     *
     * Purely a VISUAL-HEIGHT transform, never a text-content one — the
     * matched run of real "\n" characters is kept byte-for-byte (just
     * squashed to near-zero line-height so it stops taking up visible
     * space), never replaced with different text. That distinction
     * matters beyond flat_text/applyTextEdits(): the client-side
     * checkbox "jump to this flagged passage" handler (see
     * tracking.blade.php's findDocxRangeAt()) walks this exact same
     * rendered HTML's own text nodes to re-derive an offset — replacing
     * real characters with a differently-sized label there (an earlier,
     * wrong version of this method did exactly that) silently shifted
     * every offset after the first collapsed gap, landing a flagged
     * passage's highlight on the wrong text entirely. The "— page
     * break —" label itself is a SEPARATE, real-text-free <hr>, so nothing
     * about it is ever walked or counted as content either.
     */
    private function collapsePageBreakGaps(string $html): string
    {
        return preg_replace_callback(
            '/\n{4,}/',
            fn (array $matches) => '<span class="docx-page-gap" style="display:block;line-height:0;font-size:1px;overflow:hidden;" aria-hidden="true">'.$matches[0].'</span>'
                .'<hr class="docx-page-divider my-2 border-dashed border-surface-300" contenteditable="false" title="Page break in the original document">',
            $html
        );
    }

    /**
     * Same document-order walk as renderContainer()/renderParagraph(),
     * stripped down to just what replaceTextRange() needs: an ordered
     * list of real text nodes (each a <w:t>'s own DOMText) and paragraph/
     * line-break boundary markers, each tagged with its [start, end)
     * range in the SAME flat-text numbering render() produces.
     *
     * @param  array<int, array{node: ?DOMText, start: int, end: int, boundary: bool}>  &$segments
     */
    private function collectSegments(DOMElement $container, DOMXPath $xpath, array &$segments, int &$cursor = 0): void
    {
        foreach ($container->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            if ($child->localName === 'p') {
                foreach ($xpath->query('.//w:r | .//w:br', $child) as $node) {
                    if (! $node instanceof DOMElement) {
                        continue;
                    }

                    if ($node->localName === 'br') {
                        $segments[] = ['node' => null, 'start' => $cursor, 'end' => $cursor + 1, 'boundary' => true];
                        $cursor++;

                        continue;
                    }

                    foreach ($node->childNodes as $runChild) {
                        if (! $runChild instanceof DOMElement || $runChild->localName !== 't') {
                            continue;
                        }
                        $textNode = $runChild->firstChild;
                        $length = mb_strlen($runChild->textContent);
                        if ($length === 0) {
                            continue;
                        }
                        // firstChild is the DOMText the run's visible
                        // characters actually live on — mutating ITS
                        // nodeValue is what replaceTextRange() needs;
                        // $runChild (the <w:t> element) has no nodeValue
                        // of its own to set.
                        $segments[] = [
                            'node' => $textNode instanceof DOMText ? $textNode : null,
                            'start' => $cursor,
                            'end' => $cursor + $length,
                            'boundary' => false,
                        ];
                        $cursor += $length;
                    }
                }

                $segments[] = ['node' => null, 'start' => $cursor, 'end' => $cursor + 1, 'boundary' => true];
                $cursor++;
            } elseif ($child->localName === 'tbl') {
                foreach ($child->childNodes as $row) {
                    if (! $row instanceof DOMElement || $row->localName !== 'tr') {
                        continue;
                    }
                    foreach ($row->childNodes as $cell) {
                        if (! $cell instanceof DOMElement || $cell->localName !== 'tc') {
                            continue;
                        }
                        $this->collectSegments($cell, $xpath, $segments, $cursor);
                    }
                }
            }
        }
    }
}

/**
 * Stateful helper: feeds it the document's text one chunk at a time, in
 * document order, and it hands back that same chunk HTML-escaped with
 * <mark> opened/closed exactly where $annotations says to — the same
 * cursor-walking algorithm the plain-text "Review & Comment" panel
 * already uses against one whole string (see annotations-panel.blade.
 * php's own docblock), generalized to a stream of chunks interleaved
 * with non-text HTML (images, table tags) that never pass through here
 * at all and so never affect the cursor.
 */
class DocxFlatTextMarker
{
    private int $cursor = 0;

    private int $index = 0;

    private bool $insideMark = false;

    /**
     * @param  array<int, array{start_offset: int, end_offset: int, tooltip: string}>  $annotations
     */
    public function __construct(private array $annotations) {}

    public function consume(string $chunk): string
    {
        $out = '';
        $pos = 0;
        $length = mb_strlen($chunk);

        while ($pos < $length) {
            $globalPos = $this->cursor + $pos;
            $current = $this->annotations[$this->index] ?? null;

            if ($this->insideMark && $current !== null && $globalPos >= $current['end_offset']) {
                $out .= '</mark>';
                $this->insideMark = false;
                $this->index++;
                $current = $this->annotations[$this->index] ?? null;
            }

            if (! $this->insideMark && $current !== null && $globalPos >= $current['start_offset']) {
                $out .= '<mark class="bg-processing-100 text-processing-900 rounded px-0.5 cursor-help" title="'.$current['tooltip'].'">';
                $this->insideMark = true;
            }

            $boundary = $length;
            if ($this->insideMark && $current !== null) {
                $boundary = min($boundary, max($pos, $current['end_offset'] - $this->cursor));
            } elseif ($current !== null && $current['start_offset'] > $globalPos) {
                $boundary = min($boundary, $current['start_offset'] - $this->cursor);
            }

            $segment = mb_substr($chunk, $pos, $boundary - $pos);
            $out .= htmlspecialchars($segment, ENT_QUOTES);
            $pos = $boundary;
        }

        $this->cursor += $length;

        return $out;
    }

    public function finish(): string
    {
        return $this->insideMark ? '</mark>' : '';
    }
}
