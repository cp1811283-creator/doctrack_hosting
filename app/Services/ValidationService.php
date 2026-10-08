<?php

namespace App\Services;

use App\Models\DocumentRepository;
use App\Models\MlStagingSample;

/**
 * ValidationService
 * ------------------
 * Implements "Automated Document Validation" (Scope 1.4): checks required
 * sections, mandatory fields, and formatting standards before a document
 * proceeds to the approval workflow (DFD Process 3.0 sub-process 3.3/3.4).
 *
 * Templates below are configurable per document category, corresponding to
 * the "Standardized Digital Document Submission" templates named in Scope
 * (Job Order, Purchase Requisition, Service Report).
 */
class ValidationService
{
    /**
     * Required section keywords each document category must contain. Each
     * entry is a list of acceptable phrasings for the SAME field, not a
     * list of separate fields — a document only needs to contain ONE of
     * them (e.g. "Requested By" or "Requestor") to satisfy that
     * requirement.
     *
     * Deliberately no "reference number" field (Job Order No/Requisition
     * No/Service Report No) here — the value that follows a label is
     * never checked at all (see validate()'s matching str_contains logic),
     * so requiring one only made the originator guess a meaningless,
     * self-invented number and its exact label wording, for zero real
     * quality control. Every remaining field below tests actual content
     * (a real date, a real requester, a real description) instead.
     */
    private const TEMPLATES = [
        'Job Order' => [
            'required_sections' => [
                ['date requested', 'date needed', 'requested date'],
                ['requested by', 'requestor', 'requested for'],
                ['description of work', 'work description', 'scope of work'],
                // Added (2026-10-08) — Job Order's pipeline has had a
                // Budget Check stage since the workflow redesign, but
                // nothing ever required the document to actually contain
                // a budget/cost figure, so that approver had nothing to
                // check. Purchase Requisition already requires this same
                // kind of field for its own Budget Check stage.
                ['estimated cost', 'estimated budget', 'budget', 'cost estimate'],
            ],
            'min_word_count' => 30,
        ],
        'Purchase Requisition' => [
            'required_sections' => [
                ['department'],
                ['item description', 'items', 'description of items'],
                ['quantity', 'qty'],
                ['budget', 'estimated cost', 'estimated budget', 'total cost'],
            ],
            'min_word_count' => 20,
        ],
        'Service Report' => [
            'required_sections' => [
                // 'technician' deliberately dropped (2026-10-08) — who
                // physically did the field work is outside what this
                // system actually tracks or has any record of. The
                // originator who files the report is office staff, not
                // the technician; requiring a technician's name here
                // asked a document to contain information nobody
                // submitting it would reliably have.
                ['date of service', 'service date', 'date serviced'],
                ['findings', 'observations'],
            ],
            'min_word_count' => 25,
        ],
    ];

    /**
     * Below this many staged training samples for a category, there isn't
     * enough real vocabulary yet to score against — mirrors
     * AdminController::TRAINING_MIN_PER_CATEGORY, the same bar the ML
     * training page already uses to decide "enough to train on."
     */
    private const MIN_VOCABULARY_SAMPLES = 5;

    /**
     * Caps how many routed real documents' text feed the vocabulary below
     * — this only needs enough real-world words to meaningfully widen
     * recognition, not the full unbounded history of every document ever
     * trained on for a category, which would otherwise grow (and get
     * slower to re-tokenize on every call) for the lifetime of the app.
     * Most recently trained first, so the cap always keeps the freshest
     * vocabulary rather than an arbitrary/oldest slice.
     */
    private const MAX_ROUTED_VOCABULARY_SAMPLES = 200;

    /**
     * @return array{is_valid: bool, errors: array<int,string>, readability_score: ?int, readability_note: ?string}
     *
     * readability_note carries checkContentQuality()'s explanation whenever
     * the score is low, but it's informational only — it no longer affects
     * is_valid. A low readability score usually means the document uses
     * real vocabulary the model hasn't learned yet (see
     * ClassificationService::autoTrainIfDue()), not that the document is
     * invalid; blocking on it meant exactly the documents most worth
     * learning from never reached anyone. Only the objective checks below
     * (required sections present, word count met) still gate is_valid.
     */
    public function validate(string $category, string $text): array
    {
        $errors = [];
        $template = self::TEMPLATES[$category] ?? null;

        if (! $template) {
            return ['is_valid' => false, 'errors' => ["Unrecognized document category: {$category}"], 'readability_score' => null, 'readability_note' => null];
        }

        $normalized = strtolower($text);

        foreach ($template['required_sections'] as $variants) {
            $found = false;
            foreach ($variants as $variant) {
                if (str_contains($normalized, $variant)) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                // The first variant is the canonical display name — same
                // wording every existing error message/test already
                // expects, just now backed by a list instead of one string.
                $errors[] = 'Missing required section/field: "'.ucwords($variants[0]).'"';
            }
        }

        $wordCount = str_word_count($text);
        if ($wordCount < $template['min_word_count']) {
            $errors[] = "Document content is too short ({$wordCount} words; minimum {$template['min_word_count']}). Possible incomplete submission.";
        }

        ['error' => $qualityNote, 'score' => $readabilityScore] = $this->checkContentQuality($category, $text);

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'readability_score' => $readabilityScore,
            'readability_note' => $qualityNote,
        ];
    }

    /**
     * For a document the originator flagged as not belonging to any of
     * the trained categories (DocumentRepository::desired_routing ===
     * 'unrelated' — see WorkflowService::ingest()) — none of validate()'s
     * checks apply here: required_sections and the vocabulary-based
     * readability score are both defined per category, and there's no
     * real category to check against (the classifier's guess is kept for
     * reference only, never authoritative for this document). This is a
     * bare sanity filter instead — not "does this look like a Job
     * Order," just "is there actually meaningful content here" — so an
     * obviously blank/garbage upload still doesn't reach a human
     * approver, without pretending to validate something it can't.
     *
     * @return array{is_valid: bool, errors: array<int,string>, readability_score: ?int, readability_note: ?string}
     */
    public function validateGeneric(string $text): array
    {
        $minWordCount = config('ml.generic_min_word_count', 20);
        $wordCount = str_word_count($text);

        if ($wordCount < $minWordCount) {
            return [
                'is_valid' => false,
                'errors' => ["Document content is too short ({$wordCount} words; minimum {$minWordCount}). Possible incomplete submission."],
                'readability_score' => null,
                'readability_note' => null,
            ];
        }

        return ['is_valid' => true, 'errors' => [], 'readability_score' => null, 'readability_note' => null];
    }

    /**
     * A document flagged 'unrelated' (WorkflowService::ingest()) has no
     * authoritative category — validateGeneric() above deliberately never
     * scores readability against one. But the classifier still quietly
     * produces a best-guess category for every document regardless of
     * routing mode, so this lets the UI show a real readability score
     * against that guess too, purely for display: proves classification,
     * readability and validation genuinely run on every document, not just
     * ones that end up routed through a real category.
     *
     * @return array{score: ?int, note: ?string}
     */
    public function readabilityAgainst(string $category, string $text): array
    {
        ['error' => $note, 'score' => $score] = $this->checkContentQuality($category, $text);

        return ['score' => $score, 'note' => $note];
    }

    /**
     * Same scoring as readabilityAgainst(), but takes an already-fetched
     * vocabulary instead of querying/tokenizing categoryVocabulary()
     * again — for a caller re-scoring many documents in the same category
     * back to back (see ClassificationService::autoTrainIfDue()'s batch
     * recheck, the only caller that needs this), fetching once per
     * category and reusing it across every document in that category
     * avoids redundant identical queries. categoryVocabulary() itself
     * stays uncached everywhere else — see its own docblock for why.
     *
     * @param  array<string,true>|null  $vocabulary  from vocabularyFor()
     * @return array{score: ?int, note: ?string}
     */
    public function readabilityWithVocabulary(?array $vocabulary, string $category, string $text): array
    {
        ['error' => $note, 'score' => $score] = $this->scoreContentQuality($vocabulary, $category, $text);

        return ['score' => $score, 'note' => $note];
    }

    /** Public entry point to categoryVocabulary() — see readabilityWithVocabulary()'s docblock for why a caller would need this directly. */
    public static function vocabularyFor(string $category): ?array
    {
        return self::categoryVocabulary($category);
    }

    /**
     * A real-word-ratio heuristic, not semantic understanding — true
     * "is this professional/nonsense" detection isn't reliable at this
     * project's scale. This only catches text where most tokens simply
     * aren't recognizable words for this category at all (garbled OCR,
     * keyboard-mashing, wrong-language uploads).
     *
     * The vocabulary is pulled live from that category's own ML training
     * corpus (MlStagingSample::extracted_text) rather than a hand-written
     * generic English dictionary — the same admin-curated samples
     * ClassificationService already trains the classifier on double as the
     * dataset for this heuristic too, so domain vocabulary (a Job Order's
     * "truck", "brake", "transmission", etc.) is recognized without anyone
     * maintaining a word list by hand. That vocabulary also grows
     * automatically as more documents route through and get folded into
     * training (see ClassificationService::autoTrainIfDue()) — this score
     * is informational only now, never a gate (see validate()'s docblock),
     * specifically so a low score from unfamiliar-but-real vocabulary
     * doesn't stop the very documents that would teach the model that
     * vocabulary from ever reaching training.
     *
     * @return array{error: ?string, score: ?int}
     */
    private function checkContentQuality(string $category, string $text): array
    {
        return $this->scoreContentQuality(self::categoryVocabulary($category), $category, $text);
    }

    /** The actual scoring math, factored out from checkContentQuality() so readabilityWithVocabulary() can reuse it against an already-fetched vocabulary instead of re-querying categoryVocabulary(). */
    private function scoreContentQuality(?array $vocabulary, string $category, string $text): array
    {
        $words = str_word_count(strtolower($text), 1);
        if (count($words) === 0) {
            return ['error' => null, 'score' => null]; // nothing to score — the word-count gate above already covers empty content
        }

        if ($vocabulary === null) {
            return ['error' => null, 'score' => null]; // too few staged training samples for this category yet
        }

        $realWordCount = count(array_filter($words, fn (string $w) => isset($vocabulary[$w])));
        $ratio = $realWordCount / count($words);
        $score = (int) round($ratio * 100);
        $threshold = config('ml.min_real_word_ratio', 0.7);

        if ($ratio >= $threshold) {
            return ['error' => null, 'score' => $score];
        }

        return [
            'error' => "Scored low ({$score}% recognizable words for '{$category}') — likely contains vocabulary this category's model hasn't learned yet, or a garbled scan/non-English submission.",
            'score' => $score,
        ];
    }

    /**
     * @return array<string,true>|null keyed by word for O(1) lookup, or
     *                                 null if the category doesn't have enough staged samples yet
     *
     * Deliberately not cached — the category counts here (tens, not
     * thousands, of samples) make re-querying and re-tokenizing on every
     * call cheap, and this needs a genuinely fresh read every time a
     * document is scored — a cache would need explicit invalidation to
     * support that, for no real performance win at this scale.
     *
     * Sourced from curated MlStagingSample rows PLUS real documents
     * ClassificationService::autoTrainIfDue() has already folded into
     * training for this category (used_for_training_at not null) — the
     * same real-document growth classification's own vocabulary gets,
     * still scoped per category (never mixed with the other two, unlike
     * the classifier's own shared TF-IDF vocabulary — see
     * autoTrainIfDue()'s recheck step, which re-scores readability for
     * this same batch once training completes).
     *
     * Tokenized the same way as ClassificationService::preprocess()
     * (lowercase, strip non-letters) but WITHOUT its stopword removal —
     * that stripping is right for classification's TF-IDF signal, but
     * this heuristic needs common connector words ("the", "a", "of") to
     * stay countable, since real prose naturally contains them.
     */
    private static function categoryVocabulary(string $category): ?array
    {
        $curatedSamples = MlStagingSample::curatedTextsFor($category);
        if ($curatedSamples->count() < self::MIN_VOCABULARY_SAMPLES) {
            return null; // the cold-start bar is about curated data specifically — same bar the ML Training page uses
        }

        $routedSamples = DocumentRepository::where('ml_category', $category)
            ->whereNotNull('used_for_training_at')
            ->orderByDesc('used_for_training_at')
            ->limit(self::MAX_ROUTED_VOCABULARY_SAMPLES)
            ->pluck('ocr_text');

        $vocabulary = [];
        foreach ($curatedSamples->merge($routedSamples) as $text) {
            $normalized = preg_replace('/[^a-z\s]/', ' ', strtolower((string) $text));
            foreach (preg_split('/\s+/', trim($normalized)) ?: [] as $token) {
                if (strlen($token) >= 2) {
                    $vocabulary[$token] = true;
                }
            }
        }

        return $vocabulary;
    }

    public static function knownCategories(): array
    {
        return array_keys(self::TEMPLATES);
    }
}
