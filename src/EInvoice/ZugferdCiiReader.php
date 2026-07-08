<?php

declare(strict_types=1);

namespace JohnWink\GobdInvoice\EInvoice;

use DateTime;
use DOMDocument;
use horstoeko\zugferd\ZugferdDocumentReader;
use horstoeko\zugferdublbridge\XmlConverterUblToCii;
use JohnWink\GobdInvoice\Contracts\EInvoiceReader;
use JohnWink\GobdInvoice\Exceptions\GobdInvoiceException;
use JohnWink\GobdInvoice\ValueObjects\Money;
use JohnWink\GobdInvoice\ValueObjects\ParsedEInvoice;
use JohnWink\GobdInvoice\ValueObjects\ParsedEInvoiceLine;
use JohnWink\GobdInvoice\ValueObjects\ParsedEInvoiceTax;
use JohnWink\GobdInvoice\ValueObjects\Party;
use Throwable;

/**
 * Parses an incoming EN 16931 e-invoice into a {@see ParsedEInvoice}. UBL input
 * is first converted to CII via horstoeko/zugferdublbridge, so both syntaxes are
 * accepted; the CII is then read with horstoeko/zugferd. The library is wrapped
 * behind {@see EInvoiceReader} so the domain never sees a horstoeko type.
 *
 * The extracted values are the sender's declarations, surfaced as-is; the
 * package does not re-compute or trust them (validation is a separate concern).
 */
final class ZugferdCiiReader implements EInvoiceReader
{
    /**
     * @var list<string>
     */
    private const array UBL_ROOT_NAMESPACES = [
        'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
        'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2',
    ];

    /**
     * ISO 4217 currencies whose minor unit is NOT two decimals (0- or 3-digit).
     * The engine's Money is fixed at two decimals, so rather than silently
     * mis-scale such an incoming amount the reader rejects it (fail loud).
     *
     * @var list<string>
     */
    private const array NON_TWO_DECIMAL_CURRENCIES = [
        // 0 decimals
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF',
        'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        // 3 decimals
        'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND',
    ];

    public function read(string $xml): ParsedEInvoice
    {
        // Anything the wrapped libraries throw (UBL conversion, profile guess,
        // getter failures) is surfaced as one GobdInvoiceException; our own
        // exceptions (e.g. the well-formedness check) pass through unchanged.
        try {
            $cii = $this->toCii($xml);
            $reader = ZugferdDocumentReader::readAndGuessFromContent($cii);

            $number = $typeCode = $currency = $taxCurrency = $documentName = $documentLanguage = null;
            $issueDate = $effectiveSpecifiedPeriod = null;
            $reader->getDocumentInformation($number, $typeCode, $issueDate, $currency, $taxCurrency, $documentName, $documentLanguage, $effectiveSpecifiedPeriod);

            $buyerReference = null;
            $reader->getDocumentBuyerReference($buyerReference);

            $currency = $this->str($currency) ?? 'EUR';
            throw_if(in_array(strtoupper($currency), self::NON_TWO_DECIMAL_CURRENCIES, true), GobdInvoiceException::class, "The e-invoice currency [{$currency}] is not a two-decimal currency; this engine represents money in two-decimal minor units and will not silently mis-scale it (BT-5).");

            $totals = $this->summationTotals($reader, $currency);

            return new ParsedEInvoice(
                number: $this->str($number) ?? '',
                typeCode: $this->str($typeCode) ?? '',
                issueDate: $issueDate instanceof DateTime ? $issueDate->format('Y-m-d') : null,
                currency: $currency,
                seller: $this->sellerParty($reader),
                buyer: $this->buyerParty($reader),
                grandTotal: $totals['grand'],
                payableAmount: $totals['due'],
                taxBasisTotal: $totals['basis'],
                taxTotal: $totals['tax'],
                lines: $this->lines($reader, $currency),
                taxBreakdown: $this->taxBreakdown($reader, $currency),
                notes: $this->notes($reader),
                buyerReference: $this->str($buyerReference),
            );
        } catch (GobdInvoiceException $gobdInvoiceException) {
            throw $gobdInvoiceException;
        } catch (Throwable $throwable) {
            throw new GobdInvoiceException('The payload is not a readable EN 16931 e-invoice: '.$throwable->getMessage(), $throwable->getCode(), previous: $throwable);
        }
    }

    private function toCii(string $xml): string
    {
        if (! $this->isUbl($xml)) {
            return $xml;
        }

        return XmlConverterUblToCii::fromString($xml)->convert()->saveXmlString();
    }

    private function isUbl(string $xml): bool
    {
        $domDocument = new DOMDocument;

        throw_unless(@$domDocument->loadXML($xml), GobdInvoiceException::class, 'The e-invoice payload is not well-formed XML.');

        return in_array($domDocument->documentElement?->namespaceURI, self::UBL_ROOT_NAMESPACES, true);
    }

    /**
     * @return array{grand: Money, due: Money, basis: Money, tax: Money}
     */
    private function summationTotals(ZugferdDocumentReader $zugferdDocumentReader, string $currency): array
    {
        $grand = $due = $line = $charge = $allowance = $basis = $tax = $rounding = $prepaid = null;
        $zugferdDocumentReader->getDocumentSummation($grand, $due, $line, $charge, $allowance, $basis, $tax, $rounding, $prepaid);

        return [
            'grand' => $this->money($grand, $currency),
            'due' => $this->money($due, $currency),
            'basis' => $this->money($basis, $currency),
            'tax' => $this->money($tax, $currency),
        ];
    }

    private function sellerParty(ZugferdDocumentReader $zugferdDocumentReader): Party
    {
        $name = $description = null;
        $id = null;
        $zugferdDocumentReader->getDocumentSeller($name, $id, $description);

        $lineOne = $lineTwo = $lineThree = $postCode = $city = $country = null;
        $subDivision = null;
        $zugferdDocumentReader->getDocumentSellerAddress($lineOne, $lineTwo, $lineThree, $postCode, $city, $country, $subDivision);

        $taxReg = null;
        $zugferdDocumentReader->getDocumentSellerTaxRegistration($taxReg);

        return $this->buildParty($name, $lineOne, $postCode, $city, $country, $taxReg);
    }

    private function buyerParty(ZugferdDocumentReader $zugferdDocumentReader): Party
    {
        $name = $description = null;
        $id = null;
        $zugferdDocumentReader->getDocumentBuyer($name, $id, $description);

        $lineOne = $lineTwo = $lineThree = $postCode = $city = $country = null;
        $subDivision = null;
        $zugferdDocumentReader->getDocumentBuyerAddress($lineOne, $lineTwo, $lineThree, $postCode, $city, $country, $subDivision);

        $taxReg = null;
        $zugferdDocumentReader->getDocumentBuyerTaxRegistration($taxReg);

        return $this->buildParty($name, $lineOne, $postCode, $city, $country, $taxReg);
    }

    /**
     * @param  array<array-key, mixed>|null  $taxReg
     */
    private function buildParty(mixed $name, mixed $line, mixed $postCode, mixed $city, mixed $country, ?array $taxReg): Party
    {
        $taxReg ??= [];

        return new Party(
            $this->str($name) ?? '',
            $this->str($line),
            $this->str($postCode),
            $this->str($city),
            $this->str($country),
            $this->str($taxReg['FC'] ?? null),
            $this->str($taxReg['VA'] ?? null),
        );
    }

    /**
     * @return list<ParsedEInvoiceLine>
     */
    private function lines(ZugferdDocumentReader $zugferdDocumentReader, string $currency): array
    {
        $lines = [];

        if (! $zugferdDocumentReader->firstDocumentPosition()) {
            return $lines;
        }

        do {
            $lineId = $lineStatusCode = $lineStatusReasonCode = null;
            $zugferdDocumentReader->getDocumentPositionGenerals($lineId, $lineStatusCode, $lineStatusReasonCode);

            $name = $description = $sellerAssignedID = $buyerAssignedID = $globalIDType = $globalID = null;
            $zugferdDocumentReader->getDocumentPositionProductDetails($name, $description, $sellerAssignedID, $buyerAssignedID, $globalIDType, $globalID);

            $billedQuantity = $chargeFreeQuantity = $packageQuantity = null;
            $billedQuantityUnitCode = $chargeFreeQuantityUnitCode = $packageQuantityUnitCode = null;
            $zugferdDocumentReader->getDocumentPositionQuantity($billedQuantity, $billedQuantityUnitCode, $chargeFreeQuantity, $chargeFreeQuantityUnitCode, $packageQuantity, $packageQuantityUnitCode);

            $lineTotal = null;
            $zugferdDocumentReader->getDocumentPositionLineSummationSimple($lineTotal);

            $category = $taxType = $rate = $calculated = $exemptionReason = $exemptionReasonCode = null;
            if ($zugferdDocumentReader->firstDocumentPositionTax()) {
                $zugferdDocumentReader->getDocumentPositionTax($category, $taxType, $rate, $calculated, $exemptionReason, $exemptionReasonCode);
            }

            $lines[] = new ParsedEInvoiceLine(
                id: $this->str($lineId) ?? '',
                name: $this->str($name) ?? '',
                quantity: $this->decimalString($billedQuantity),
                unitCode: $this->str($billedQuantityUnitCode),
                lineNet: $this->money($lineTotal, $currency),
                taxCategory: $this->str($category),
                taxRate: is_float($rate) ? $this->decimalString($rate) : null,
            );
        } while ($zugferdDocumentReader->nextDocumentPosition());

        return $lines;
    }

    /**
     * @return list<ParsedEInvoiceTax>
     */
    private function taxBreakdown(ZugferdDocumentReader $zugferdDocumentReader, string $currency): array
    {
        $groups = [];

        if (! $zugferdDocumentReader->firstDocumentTax()) {
            return $groups;
        }

        do {
            $category = $taxType = $exemptionReason = $exemptionReasonCode = $dueDateTypeCode = null;
            $basis = $calculated = $rate = $lineTotalBasis = $allowanceChargeBasis = null;
            $taxPointDate = null;
            $zugferdDocumentReader->getDocumentTax($category, $taxType, $basis, $calculated, $rate, $exemptionReason, $exemptionReasonCode, $lineTotalBasis, $allowanceChargeBasis, $taxPointDate, $dueDateTypeCode);

            $groups[] = new ParsedEInvoiceTax(
                category: $this->str($category) ?? '',
                rate: is_float($rate) ? $this->decimalString($rate) : null,
                basis: $this->money($basis, $currency),
                tax: $this->money($calculated, $currency),
                exemptionReason: $this->str($exemptionReason),
            );
        } while ($zugferdDocumentReader->nextDocumentTax());

        return $groups;
    }

    /**
     * @return list<string>
     */
    private function notes(ZugferdDocumentReader $zugferdDocumentReader): array
    {
        $notes = null;
        $zugferdDocumentReader->getDocumentNotes($notes);

        if (! is_array($notes)) {
            return [];
        }

        $collected = [];
        foreach ($notes as $note) {
            $content = is_array($note) ? ($note['content'] ?? null) : null;
            $text = $this->str($content);
            if ($text !== null) {
                $collected[] = $text;
            }
        }

        return $collected;
    }

    private function money(?float $amount, string $currency): Money
    {
        // number_format takes an explicit decimal point, so it is locale-safe;
        // sprintf('%f') would emit a comma under a de_DE LC_NUMERIC and break
        // Money::fromDecimal. (The package assumes 2-decimal currencies.)
        return Money::fromDecimal(number_format($amount ?? 0.0, 2, '.', ''), $currency);
    }

    /**
     * A non-scientific, locale-independent decimal string with trailing zeros
     * trimmed ("2.00000000" → "2"). Quantities (BT-129) may carry more than two
     * decimals, so keep up to eight before trimming.
     */
    private function decimalString(?float $value): string
    {
        if ($value === null) {
            return '0';
        }

        $formatted = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    private function str(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
