<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\Export;

use DateTimeInterface;
use JohnWink\GobdInvoice\Contracts\GobdDataExporter;
use JohnWink\GobdInvoice\Models\DocumentLine;
use JohnWink\GobdInvoice\ValueObjects\Party;

/**
 * GDPdU "Datenträgerüberlassung" (Z3) exporter: writes the finalized documents
 * and their lines as two CSV tables plus an `index.xml` descriptor (the GDPdU
 * "Beschreibungsstandard"). The CSVs carry no header row — the columns are
 * defined positionally in the descriptor, the canonical GDPdU form.
 */
final class GdpduExporter implements GobdDataExporter
{
    private const string DELIMITER = ';';

    private const string ENCAPSULATOR = '"';

    private const string RECORD = "\r\n";

    /**
     * Document table columns: [header name, kind]. Kinds: alpha, num (2 decimals),
     * date. The row builder must emit values in exactly this order.
     *
     * @var list<array{string, string}>
     */
    private const array DOCUMENT_COLUMNS = [
        ['Rechnungsnummer', 'alpha'], ['Typ', 'alpha'], ['Rechnungsdatum', 'date'],
        ['Leistungsdatum', 'date'], ['Waehrung', 'alpha'], ['Verkaeufer', 'alpha'],
        ['VerkaeuferUStId', 'alpha'], ['Kaeufer', 'alpha'], ['Nettobetrag', 'num'],
        ['Umsatzsteuer', 'num'], ['Bruttobetrag', 'num'], ['FaelligerBetrag', 'num'],
        ['Status', 'alpha'], ['Festgeschrieben', 'alpha'],
    ];

    /**
     * @var list<array{string, string}>
     */
    private const array LINE_COLUMNS = [
        ['Rechnungsnummer', 'alpha'], ['Position', 'alpha'], ['Beschreibung', 'alpha'],
        ['Menge', 'alpha'], ['Einheit', 'alpha'], ['Nettobetrag', 'num'],
        ['Steuersatz', 'alpha'], ['Steuerkategorie', 'alpha'],
    ];

    public function export(iterable $documents): array
    {
        $documentRows = [];
        $lineRows = [];
        $dates = [];

        foreach ($documents as $document) {
            $seller = Party::fromArray($document->seller ?? []);
            $buyer = Party::fromArray($document->buyer ?? []);

            $documentRows[] = $this->row([
                (string) $document->number,
                $document->type->value,
                $this->date($document->issue_date),
                $this->date($document->service_date ?? $document->service_period_end),
                (string) $document->currency,
                $seller->name,
                (string) $seller->vatId,
                $buyer->name,
                $this->amount($document->net_total),
                $this->amount($document->vat_total),
                $this->amount($document->gross_total),
                $this->amount($document->amount_due),
                $document->status->value,
                $document->finalized_at?->format('d.m.Y H:i:s') ?? '',
            ]);

            if ($document->issue_date !== null) {
                $dates[] = $document->issue_date->format('d.m.Y');
            }

            $document->loadMissing('lines');
            foreach ($document->lines as $line) {
                /** @var DocumentLine $line */
                $lineRows[] = $this->row([
                    (string) $document->number,
                    (string) $line->position,
                    $line->description,
                    $line->quantity,
                    (string) $line->unit,
                    $this->amount($line->line_net_minor),
                    $line->tax_rate,
                    $line->tax_category,
                ]);
            }
        }

        return [
            'rechnungen.csv' => implode('', $documentRows),
            'positionen.csv' => implode('', $lineRows),
            'index.xml' => $this->indexXml($dates),
        ];
    }

    /**
     * @param  list<string>  $values
     */
    private function row(array $values): string
    {
        $escaped = array_map(
            fn (string $value): string => self::ENCAPSULATOR.str_replace(self::ENCAPSULATOR, self::ENCAPSULATOR.self::ENCAPSULATOR, $value).self::ENCAPSULATOR,
            $values,
        );

        return implode(self::DELIMITER, $escaped).self::RECORD;
    }

    private function amount(?int $minor): string
    {
        return bcdiv((string) ($minor ?? 0), '100', 2);
    }

    private function date(?DateTimeInterface $date): string
    {
        return $date?->format('d.m.Y') ?? '';
    }

    /**
     * @param  list<string>  $dates  d.m.Y issue dates, for the validity range
     */
    private function indexXml(array $dates): string
    {
        sort($dates);
        $from = $dates[0] ?? '';
        $to = $dates === [] ? '' : $dates[count($dates) - 1];

        $tables =
            $this->tableXml('rechnungen.csv', 'Rechnungen', 'Festgeschriebene Belege', self::DOCUMENT_COLUMNS, $from, $to).
            $this->tableXml('positionen.csv', 'Positionen', 'Belegpositionen', self::LINE_COLUMNS, $from, $to);

        return '<?xml version="1.0" encoding="UTF-8"?>'.self::RECORD.
            '<DataSet>'.
            '<Version>1.0</Version>'.
            '<DataSupplier><Name>gobd-invoice</Name><Location>Deutschland</Location><Comment>GoBD GDPdU export (Z3)</Comment></DataSupplier>'.
            '<Media><Name>gobd-invoice</Name>'.$tables.'</Media>'.
            '</DataSet>'.self::RECORD;
    }

    /**
     * @param  list<array{string, string}>  $columns
     */
    private function tableXml(string $file, string $name, string $description, array $columns, string $from, string $to): string
    {
        $columnsXml = '';
        foreach ($columns as [$columnName, $kind]) {
            $type = match ($kind) {
                'num' => '<Numeric><Accuracy>2</Accuracy></Numeric>',
                'date' => '<Date><Format>DD.MM.YYYY</Format></Date>',
                default => '<AlphaNumeric/>',
            };
            $columnsXml .= "<VariableColumn><Name>{$columnName}</Name>{$type}</VariableColumn>";
        }

        return '<Table>'.
            "<URL><File>{$file}</File></URL>".
            "<Name>{$name}</Name>".
            "<Description>{$description}</Description>".
            "<Validity><Range><From>{$from}</From><To>{$to}</To></Range><Format>DD.MM.YYYY</Format></Validity>".
            '<DecimalSymbol>.</DecimalSymbol>'.
            '<DigitGroupingSymbol>,</DigitGroupingSymbol>'.
            '<VariableLength>'.
            '<ColumnDelimiter>;</ColumnDelimiter>'.
            '<RecordDelimiter>&#13;&#10;</RecordDelimiter>'.
            '<TextEncapsulator>"</TextEncapsulator>'.
            $columnsXml.
            '</VariableLength>'.
            '</Table>';
    }
}
