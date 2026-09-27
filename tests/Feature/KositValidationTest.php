<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use JohnWink\GobdInvoice\Enums\DocumentType;
use JohnWink\GobdInvoice\Facades\GobdInvoice;
use JohnWink\GobdInvoice\Models\Document;

/*
 * The official KoSIT validator (itplr-kosit/validator with the configuration
 * of itplr-kosit/validator-configuration-xrechnung) checks the e-invoices of
 * the package against the EN 16931 CII scenario: XML schema plus the CEN
 * Schematron. It needs Java; .github/kosit/fetch.sh downloads the pinned
 * release, checks it against its SHA-256 and prints the directory to put into
 * GOBD_KOSIT_DIR. Without it the test is skipped.
 */

const KOSIT_REPORT_NAMESPACE = 'http://www.xoev.de/de/validator/varl/1';

const KOSIT_SCENARIO = 'EN16931 (CII)';

function kositDirectory(): ?string
{
    $directory = getenv('GOBD_KOSIT_DIR');

    return is_string($directory) && $directory !== '' ? $directory : null;
}

/**
 * @return array{accepted: bool, scenario: string, errors: list<string>, warnings: list<string>}
 */
function kositAssessment(string $example, string $xml): array
{
    $directory = (string) kositDirectory();
    $reports = $directory.DIRECTORY_SEPARATOR.'reports';

    if (! is_dir($reports)) {
        mkdir($reports, 0o755, true);
    }

    $input = $reports.DIRECTORY_SEPARATOR.$example.'.xml';
    file_put_contents($input, $xml);

    $processResult = Process::timeout(120)->run([
        'java', '-jar', $directory.DIRECTORY_SEPARATOR.'validator.jar',
        '-s', $directory.DIRECTORY_SEPARATOR.'configuration'.DIRECTORY_SEPARATOR.'scenarios.xml',
        '-r', $directory.DIRECTORY_SEPARATOR.'configuration',
        '-o', $reports,
        $input,
    ]);

    $report = $reports.DIRECTORY_SEPARATOR.$example.'-report.xml';

    throw_unless(is_file($report), RuntimeException::class, "The KoSIT validator wrote no report for [{$example}]: ".$processResult->output().$processResult->errorOutput());

    $dom = new DOMDocument;
    $dom->load($report);

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('rep', KOSIT_REPORT_NAMESPACE);
    $xpath->registerNamespace('s', 'http://www.xoev.de/de/validator/framework/1/scenarios');

    $messages = static function (string $level) use ($xpath): array {
        $found = [];

        foreach ($xpath->query("//rep:message[@level = '{$level}']") ?: [] as $message) {
            $found[] = trim(($message instanceof DOMElement ? $message->getAttribute('code') : '').' '.$message->textContent);
        }

        return $found;
    };

    $assessment = [
        'accepted' => ($xpath->query('/rep:report/rep:assessment/rep:accept')?->length ?? 0) === 1,
        'scenario' => trim((string) $xpath->query('/rep:report/rep:scenarioMatched/s:scenario/s:name')?->item(0)?->textContent),
        'errors' => $messages('error'),
        'warnings' => $messages('warning'),
    ];

    file_put_contents($reports.DIRECTORY_SEPARATOR.'summary.txt', sprintf(
        "%s: %s, scenario %s, %d errors, %d warnings%s\n",
        $example,
        $assessment['accepted'] ? 'accepted' : 'rejected',
        $assessment['scenario'] === '' ? 'none' : $assessment['scenario'],
        count($assessment['errors']),
        count($assessment['warnings']),
        implode('', array_map(static fn (string $message): string => "\n  - {$message}", [...$assessment['errors'], ...$assessment['warnings']])),
    ), FILE_APPEND);

    return $assessment;
}

/**
 * @param  array<int, array<string, mixed>>  $lines
 * @param  array<string, mixed>  $attributes
 */
function kositDocument(DocumentType $documentType, array $lines, array $attributes = []): Document
{
    return GobdInvoice::finalize(draftWithParties($documentType, $lines, [
        'issue_date' => '2026-09-01',
        'service_date' => '2026-08-28',
        ...$attributes,
    ]));
}

function kositInvoice(): Document
{
    return kositDocument(DocumentType::Rechnung, [
        ['description' => 'Fliesenarbeiten Bad', 'quantity' => '12.5', 'unit' => 'Std', 'unit_price' => '58.00', 'tax_rate' => '19.0'],
        ['description' => 'Fachbuch Verlegetechnik', 'quantity' => '1', 'unit' => 'Stk', 'unit_price' => '39.90', 'tax_rate' => '7.0'],
    ]);
}

beforeEach(function (): void {
    config()->set('gobd-invoice.content_validation', true);
});

it('is accepted by the official KoSIT validator (EN 16931 CII)', function (string $example, Closure $build, string $typeCode, bool $referencesInvoice, string $prepaid): void {
    $document = $build();

    expect($document)->toBeInstanceOf(Document::class);

    $xml = GobdInvoice::eInvoiceXml($document);
    $xpath = ciiXpath($xml);
    $assessment = kositAssessment($example, $xml);

    expect(ciiValue($xpath, '//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID'))->toBe('urn:cen.eu:en16931:2017')
        ->and(ciiValue($xpath, '//rsm:ExchangedDocument/ram:TypeCode'))->toBe($typeCode)
        ->and(ciiValue($xpath, '//ram:InvoiceReferencedDocument/ram:IssuerAssignedID') !== null)->toBe($referencesInvoice)
        ->and(ciiValue($xpath, '//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TotalPrepaidAmount'))->toBe($prepaid)
        ->and($assessment['scenario'])->toBe(KOSIT_SCENARIO)
        ->and($assessment['errors'])->toBe([])
        ->and($assessment['accepted'])->toBeTrue();
})->with([
    'Rechnung' => ['rechnung', kositInvoice(...), '380', false, '0.00'],
    'Rechnungskorrektur (381 with BT-25)' => ['rechnungskorrektur', static fn (): Document => kositDocument(DocumentType::Rechnungskorrektur, [
        ['description' => 'Preisminderung wegen Mängeln', 'quantity' => '1', 'unit_price' => '-100.00', 'tax_rate' => '19.0'],
    ], ['source_document_id' => kositInvoice()->id]), '381', true, '0.00'],
    'Storno (381 with BT-25)' => ['storno', static fn (): Document => GobdInvoice::cancel(kositInvoice(), 'Auftrag storniert', '2026-09-03'), '381', true, '0.00'],
    'Schlussrechnung with two Abschlagsrechnungen' => ['schlussrechnung', static function (): Document {
        $advances = [
            kositDocument(DocumentType::Abschlagsrechnung, [
                ['description' => '1. Abschlag Rohbau', 'quantity' => '1', 'unit_price' => '5000.00', 'tax_rate' => '19.0'],
            ], ['issue_date' => '2026-06-01', 'service_date' => '2026-05-29']),
            kositDocument(DocumentType::Abschlagsrechnung, [
                ['description' => '2. Abschlag Innenausbau', 'quantity' => '1', 'unit_price' => '3000.00', 'tax_rate' => '19.0'],
            ], ['issue_date' => '2026-07-01', 'service_date' => '2026-06-30']),
        ];

        return kositDocument(DocumentType::Schlussrechnung, [
            ['description' => 'Rohbau', 'quantity' => '1', 'unit_price' => '9000.00', 'tax_rate' => '19.0'],
            ['description' => 'Innenausbau', 'quantity' => '1', 'unit_price' => '6000.00', 'tax_rate' => '19.0'],
        ], ['deducts' => array_map(static fn (Document $document): int|string => $document->id, $advances)]);
    }, '380', false, '9520.00'],
])->skip(kositDirectory() === null, 'Set GOBD_KOSIT_DIR to the directory of .github/kosit/fetch.sh (needs Java).');

it('reports a rejection through the same harness (control: tampered grand total)', function (): void {
    $xml = preg_replace(
        '#<ram:GrandTotalAmount>[0-9.]+</ram:GrandTotalAmount>#',
        '<ram:GrandTotalAmount>1.00</ram:GrandTotalAmount>',
        GobdInvoice::eInvoiceXml(kositInvoice()),
    );

    $assessment = kositAssessment('control-tampered-grand-total', (string) $xml);

    expect($assessment['scenario'])->toBe(KOSIT_SCENARIO)
        ->and($assessment['accepted'])->toBeFalse()
        ->and(implode("\n", $assessment['errors']))->toContain('BR-CO-15');
})->skip(kositDirectory() === null, 'Set GOBD_KOSIT_DIR to the directory of .github/kosit/fetch.sh (needs Java).');
