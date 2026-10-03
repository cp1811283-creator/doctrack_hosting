<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Smalot\PdfParser\Parser as PdfParser;
use thiagoalessio\TesseractOCR\TesseractNotFoundException;
use thiagoalessio\TesseractOCR\TesseractOCR;

/**
 * TextExtractionService
 * -----------------------
 * Implements the "Hybrid Extraction Pipeline" from Scope (1.4):
 *   1. Digital Extraction — assume the document is searchable (born-digital)
 *      and pull text directly:
 *        - .txt          -> read as plain text
 *        - .docx         -> unzip and read word/document.xml (pure PHP,
 *                           via the built-in ZipArchive extension — no
 *                           external Composer package required)
 *        - .pdf          -> via smalot/pdfparser, if installed
 *   2. OCR fallback — if step 1 yields no usable text (e.g. a scanned
 *      image-only PDF, a plain image file, or a legacy .doc), fall back to
 *      an OCR engine (tesseract via `thiagoalessio/tesseract_ocr`, if
 *      installed) so the ML classifier always receives usable text
 *      regardless of format.
 *
 * Composer packages required for full-fidelity PDF/OCR support (optional —
 * .txt and .docx work with zero extra dependencies):
 *   smalot/pdfparser, thiagoalessio/tesseract_ocr (+ system tesseract-ocr)
 */
class TextExtractionService
{
    private const MIN_USABLE_CHARS = 40;

    public function extract(UploadedFile $file): array
    {
        $mime = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());
        $text = '';
        $usedOcr = false;
        $failureReason = null;

        // .docx is the one source whose line breaks come straight from
        // the file's own real paragraph markers (<w:p>), not from where
        // text happened to wrap on a printed page — see
        // reconstructParagraphs()'s own docblock for why that matters:
        // it's excluded below precisely because it has no "was this
        // really a new paragraph?" ambiguity left to resolve.
        $isStructuralText = false;

        if ($mime === 'application/pdf' || $extension === 'pdf') {
            $text = $this->extractFromPdf($file->getRealPath());
        } elseif ($extension === 'docx') {
            $text = $this->extractFromDocx($file->getRealPath());
            $isStructuralText = true;
        } elseif (str_starts_with($mime, 'text/') || $extension === 'txt') {
            $text = file_get_contents($file->getRealPath());
        }
        // .doc (legacy binary Word) and image types intentionally fall
        // through to the OCR attempt below — there is no reliable
        // dependency-free way to read either format directly.

        if (mb_strlen(trim($text)) < self::MIN_USABLE_CHARS) {
            $ocr = $this->extractWithOcr($file->getRealPath());
            $text = $ocr['text'];
            $usedOcr = true;
            // OCR output is per-visual-line text like PDF/plain text, not
            // XML-structural text — even for a .docx that was too sparse
            // in real text to skip OCR in the first place.
            $isStructuralText = false;
            if (mb_strlen(trim($text)) < self::MIN_USABLE_CHARS) {
                $failureReason = $ocr['failure_reason'];
            }
        }

        $text = self::normalizeLineEndings(trim($text));
        if (! $isStructuralText) {
            $text = self::reconstructParagraphs($text);
        }

        return [
            'text' => $text,
            'used_ocr_fallback' => $usedOcr,
            // Specific, user-facing-safe reason extraction produced no
            // usable text — null when extraction actually succeeded.
            // One of: 'ocr_binary_missing', 'ocr_package_missing',
            // 'ocr_error', or null (generic/unknown).
            'failure_reason' => $failureReason,
        ];
    }

    /**
     * Turns per-visual-line extracted text into properly paragraph-spaced
     * text: a blank line between genuinely different fields/paragraphs, a
     * plain single line break kept everywhere else. Confirmed by direct
     * investigation: PDF text extraction (smalot/pdfparser) and OCR
     * (Tesseract) both only ever produce a SINGLE newline per visual
     * line, with no concept of "this line ended because the paragraph
     * did" vs "this line just reached the edge of the page" — PDF never
     * inserts a blank line at all, and OCR's own paragraph-gap detection
     * turned out to be inconsistent (confirmed missing a blank line
     * between two fields on a real uploaded document). This reconstructs
     * that distinction uniformly after the fact, using a line's length
     * relative to the document's own longest line (meaningfully shorter
     * reliably means a real line/field actually ended there, not that the
     * page ran out of room) plus whether it already ends in terminal
     * punctuation.
     *
     * NOT applied to .docx's real paragraph markers (see extract()) — and
     * intentionally a heuristic, not a layout measurement: there's no
     * access to the original PDF/image's real text coordinates through
     * either library's public API, so this is the closest approximation
     * available without depending on either library's internals.
     *
     * Public — reused by documents:reformat-extracted-paragraphs (see
     * that command) to bring already-uploaded, already-extracted
     * documents in line without needing to re-run PDF parsing or OCR a
     * second time.
     */
    public static function reconstructParagraphs(string $text): string
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $text)),
            fn (string $line) => $line !== ''
        ));

        if ($lines === []) {
            return '';
        }

        $maxLength = max(array_map('mb_strlen', $lines));
        $count = count($lines);
        $result = '';

        foreach ($lines as $index => $line) {
            $result .= $line;
            if ($index === $count - 1) {
                break;
            }

            $looksWrapped = mb_strlen($line) >= $maxLength * 0.85 && ! preg_match('/[.!?:;]$/', $line);
            $result .= $looksWrapped ? "\n" : "\n\n";
        }

        return $result;
    }

    /**
     * Normalizes every line ending to a plain \n (LF). Extracted text can
     * contain literal \r\n (CRLF) depending on the source file's own
     * encoding — invisible to THIS string's PHP character count
     * (mb_strlen/mb_substr count \r and \n as two separate characters),
     * but NOT invisible once the text is rendered as part of an HTML page:
     * a browser's own HTML parser collapses every \r\n pair down to a
     * single \n character before it's ever part of the DOM (a spec-
     * mandated step of HTML parsing, not something this app controls —
     * see the HTML Living Standard's "preprocessing the input stream").
     * Left unnormalized, any character offset computed client-side
     * against the rendered DOM (see the "select a passage to flag" JS in
     * approver/dashboard.blade.php) silently undercounts by one character
     * for every \r\n pair that came before the selected point — this was
     * the actual cause of a flagged passage's highlight landing on the
     * wrong characters, reproduced and confirmed against a real document.
     *
     * Public — reused by WorkflowService::saveDocumentRevision() (an
     * edited textarea's submitted value can reintroduce \r\n depending on
     * the browser) and by the backfill migration that normalizes already-
     * stored text (2026_09_15_000002_normalize_line_endings.php).
     */
    public static function normalizeLineEndings(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private function extractFromPdf(string $path): string
    {
        try {
            if (! class_exists(PdfParser::class)) {
                return ''; // package not installed in this environment; triggers OCR fallback
            }
            $parser = new PdfParser;
            $pdf = $parser->parseFile($path);

            return $pdf->getText();
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }

    /**
     * .docx files are a zip archive containing XML. word/document.xml holds
     * the visible body text. This needs no external library — PHP's
     * bundled ZipArchive extension is enough.
     *
     * Public (not just called from extract() above) so the backfill
     * migration that re-extracts already-uploaded .docx documents (see
     * 2026_09_15_000001_backfill_docx_paragraph_breaks.php) can reuse this
     * exact same logic against a locally-downloaded copy of the stored
     * file, rather than duplicating it.
     */
    public function extractFromDocx(string $path): string
    {
        try {
            if (! class_exists(\ZipArchive::class)) {
                return ''; // php-zip extension not enabled; triggers OCR fallback
            }

            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                return '';
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml === false) {
                return '';
            }

            // Word's paragraph/break tags become real newlines, not spaces —
            // a space here used to just glue every paragraph into one run-on
            // line once tags were stripped (fine for the ML classifier this
            // was originally built for, unreadable for a human looking at it
            // in the "editable document" view added later — see
            // WorkflowService::requestRevision()'s docblock). PDF/OCR
            // extraction already produce real line breaks; this just brings
            // .docx in line with how those already behave.
            $xml = preg_replace('/<\/w:p>|<w:br\/?>/', "\n", $xml);
            $text = strip_tags($xml);

            return html_entity_decode($text, ENT_QUOTES | ENT_XML1);
        } catch (\Throwable $e) {
            report($e);

            return '';
        }
    }

    /**
     * @return array{text: string, failure_reason: ?string}
     */
    private function extractWithOcr(string $path): array
    {
        if (! class_exists(TesseractOCR::class)) {
            return ['text' => '', 'failure_reason' => 'ocr_package_missing'];
        }

        try {
            $ocr = new TesseractOCR($path);
            $this->useBundledTesseractIfPresent($ocr);

            return ['text' => $ocr->run(), 'failure_reason' => null];
        } catch (TesseractNotFoundException $e) {
            // The PHP wrapper is installed, but the system `tesseract-ocr`
            // binary it shells out to isn't — distinct from "no OCR support
            // was ever installed" so the failure message can tell an admin
            // exactly what's missing rather than a generic hedge.
            report($e);

            return ['text' => '', 'failure_reason' => 'ocr_binary_missing'];
        } catch (\Throwable $e) {
            report($e);

            return ['text' => '', 'failure_reason' => 'ocr_error'];
        }
    }

    /**
     * This environment has no system-wide `tesseract-ocr` package
     * installed, and installing one via apt requires root privileges this
     * process doesn't have. A self-contained copy (binary + its
     * libtesseract/liblept shared libraries + eng.traineddata) was
     * extracted from the official .deb packages — via `apt-get download`
     * + `dpkg-deb -x`, neither of which need root — into
     * storage/tesseract-bin. Point the OCR wrapper at it if present;
     * otherwise leave it alone so a real system install (e.g. in a
     * different environment where `sudo apt-get install tesseract-ocr`
     * was run) is used via $PATH as normal.
     */
    private function useBundledTesseractIfPresent(TesseractOCR $ocr): void
    {
        // Prefer a real system install when one's on $PATH — the bundled
        // copy only carries its own libtesseract/liblept, not the ~15
        // further transitive shared libraries tesseract itself links
        // against (libarchive, libcurl, libnettle, etc.). Those happen to
        // already be present on a typical dev machine but are NOT
        // guaranteed on a minimal container image, where the bundled
        // binary can fail with "error while loading shared libraries" for
        // whichever one is missing. A real `apt-get install tesseract-ocr`
        // pulls in its complete, correct dependency chain automatically,
        // so it's strictly more reliable whenever it's available.
        if ($this->systemTesseractAvailable()) {
            return;
        }

        $binDir = storage_path('tesseract-bin');
        $executable = $binDir.'/bin/tesseract';

        if (! is_file($executable)) {
            return;
        }

        putenv('LD_LIBRARY_PATH='.$binDir.'/lib');
        putenv('TESSDATA_PREFIX='.$binDir.'/share/5/tessdata');
        $ocr->executable($executable);
    }

    private function systemTesseractAvailable(): bool
    {
        $path = trim((string) @shell_exec('command -v tesseract 2>/dev/null'));

        return $path !== '';
    }
}
