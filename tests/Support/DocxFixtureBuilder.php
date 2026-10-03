<?php

namespace Tests\Support;

use ZipArchive;

/**
 * Builds a minimal, real, valid .docx (hand-assembled with ZipArchive —
 * the same primitive DocxRichContentService itself uses, no external
 * tool) for exercising that service against actual OOXML rather than a
 * mocked stand-in: one bold label + a plain run, a one-row table, and a
 * real embedded image. See DocxRichContentServiceTest for the exact flat
 * text this is expected to produce.
 */
class DocxFixtureBuilder
{
    // A real 1x1 transparent PNG — small enough to inline here, large
    // enough to be a genuine, decodable image file.
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    /**
     * Same as build(), but with ~10 consecutive blank paragraphs spliced
     * between "Label: value text" and the table — reproduces the real
     * confirmed case (a letterhead-style document using blank paragraphs
     * instead of an explicit page break) for
     * DocxRichContentService::collapsePageBreakGaps()'s own test.
     */
    public static function buildWithLongBlankRun(string $absolutePath): void
    {
        $zip = new ZipArchive;
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
              <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
              <Default Extension="xml" ContentType="application/xml"/>
              <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
            </Types>
            XML);

        $zip->addFromString('_rels/.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
              <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
            </Relationships>
            XML);

        $blankParagraphs = str_repeat('<w:p/>', 10);

        $zip->addFromString('word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'
            .'<w:p><w:r><w:t>Title</w:t></w:r></w:p>'
            .$blankParagraphs
            .'<w:p><w:r><w:t>Body content</w:t></w:r></w:p>'
            .'</w:body>'
            .'</w:document>'
        );

        $zip->close();
    }

    public static function build(string $absolutePath): void
    {
        $zip = new ZipArchive;
        $zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
              <Default Extension="png" ContentType="image/png"/>
              <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
              <Default Extension="xml" ContentType="application/xml"/>
              <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
            </Types>
            XML);

        $zip->addFromString('_rels/.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
              <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
            </Relationships>
            XML);

        $zip->addFromString('word/_rels/document.xml.rels', <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
              <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/image1.png"/>
            </Relationships>
            XML);

        $zip->addFromString('word/media/image1.png', base64_decode(self::PNG_BASE64));

        $zip->addFromString('word/document.xml', self::documentXml());

        $zip->close();
    }

    /**
     * Expected flat text (matches TextExtractionService::extractFromDocx()
     * for this exact XML): "Label: value text\nCell A\nCell B\nAfter image\n"
     *
     * Deliberately NO whitespace between tags — exactly how Word itself
     * writes document.xml (one unbroken line, not pretty-printed).
     * extractFromDocx()'s strip_tags() approach treats any whitespace
     * BETWEEN tags as real text content (it can't tell insignificant
     * XML whitespace apart from an actual space in a sentence), so a
     * pretty-printed fixture would silently leak indentation into the
     * expected flat text and make this fixture lie about what a real
     * uploaded .docx produces.
     */
    public static function documentXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            .' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<w:body>'
            .'<w:p>'
            .'<w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">Label: </w:t></w:r>'
            .'<w:r><w:t>value text</w:t></w:r>'
            .'</w:p>'
            .'<w:tbl><w:tr>'
            .'<w:tc><w:p><w:r><w:t>Cell A</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>Cell B</w:t></w:r></w:p></w:tc>'
            .'</w:tr></w:tbl>'
            .'<w:p>'
            .'<w:r><w:drawing><wp:inline><a:graphic><a:graphicData>'
            .'<pic:pic><pic:blipFill><a:blip r:embed="rId1"/></pic:blipFill></pic:pic>'
            .'</a:graphicData></a:graphic></wp:inline></w:drawing></w:r>'
            .'<w:r><w:t>After image</w:t></w:r>'
            .'</w:p>'
            .'</w:body>'
            .'</w:document>';
    }
}
